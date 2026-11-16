/**
 * /grab.php  ->  api/grab.js   (runs natively on Vercel, no PHP runtime needed)
 * Reads videos from Firebase Realtime Database and returns clean JSON for the NetsOn Roku channel.
 *
 * Optional Vercel environment variables:
 *   FIREBASE_DB_URL      default https://privatems-vercel-app-default-rtdb.firebaseio.com
 *   FIREBASE_PATH        default videos
 *   FIREBASE_USERS_PATH  optional node with creator profiles
 *   FIREBASE_AUTH        optional token / database secret
 *   VIDEO_LIMIT          default 200
 */
const DB_URL = (process.env.FIREBASE_DB_URL || 'https://privatems-vercel-app-default-rtdb.firebaseio.com').replace(/\/+$/, '');
const PATH = (process.env.FIREBASE_PATH || 'videos').replace(/^\/+|\/+$/g, '');
const USERS_PATH = (process.env.FIREBASE_USERS_PATH || '').replace(/^\/+|\/+$/g, '');
const AUTH = process.env.FIREBASE_AUTH || '';
const LIMIT = parseInt(process.env.VIDEO_LIMIT || '200', 10);

const AVATAR_KEYS = ['creatorImage', 'creator_image', 'creatorAvatar', 'creator_avatar', 'avatar', 'avatarUrl',
  'avatar_url', 'profileImage', 'profile_image', 'profilePic', 'profile_pic', 'pfp', 'userImage', 'user_image',
  'authorImage', 'author_image', 'creatorPhoto', 'photo', 'photoURL', 'picture'];
const THUMB_KEYS = ['thumbnail', 'thumbnailUrl', 'thumbnail_url', 'thumb', 'thumbUrl', 'thumb_url', 'poster',
  'cover', 'preview', 'previewImage', 'imageUrl', 'image_url', 'image'];
const VIDEO_KEYS = ['videoUrl', 'video_url', 'videoURL', 'url', 'video', 'link', 'src', 'source', 'stream',
  'streamUrl', 'mp4', 'file', 'videoLink', 'video_link'];
const CREATOR_KEYS = ['creator', 'creatorName', 'creator_name', 'author', 'authorName', 'uploader', 'username',
  'userName', 'user', 'channel', 'channelName', 'owner', 'artist', 'by'];

class HttpError extends Error {
  constructor(status, message) { super(message); this.status = status; }
}

function pick(obj, keys) {
  if (!obj || typeof obj !== 'object') return '';
  for (const k of keys) {
    const v = obj[k];
    if (typeof v === 'string' && v.trim() !== '') return v.trim();
    if (v && typeof v === 'object') {
      const s = pick(v, ['url', 'src', 'link', 'original', 'large', 'medium']);
      if (s) return s;
    }
  }
  return '';
}

async function fetchJson(url) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 10000);
  let r;
  try {
    r = await fetch(url, { signal: ctrl.signal, headers: { Accept: 'application/json' } });
  } catch (e) {
    throw new HttpError(502, 'Could not reach Firebase: ' + e.message);
  } finally {
    clearTimeout(timer);
  }
  if (!r.ok) throw new HttpError(502, `Firebase returned HTTP ${r.status} (check database rules / URL)`);
  return r.json();
}

function normalize(raw, users) {
  const videos = [];
  for (const [id, v] of Object.entries(raw)) {
    if (!v || typeof v !== 'object') continue;

    const videoUrl = pick(v, VIDEO_KEYS);
    if (!videoUrl) continue;

    const thumb = pick(v, THUMB_KEYS);
    const title = pick(v, ['title', 'name', 'videoTitle', 'video_title', 'caption']);

    let creator = '';
    let avatar = '';
    for (const k of CREATOR_KEYS) {
      if (creator || v[k] === undefined || v[k] === null) continue;
      const c = v[k];
      if (typeof c === 'string') {
        creator = c.trim();
      } else if (typeof c === 'object') {
        creator = pick(c, ['name', 'displayName', 'display_name', 'username', 'handle', 'title']);
        avatar = pick(c, AVATAR_KEYS.concat(['image']));
      }
    }
    if (!avatar) avatar = pick(v, AVATAR_KEYS);

    const uid = pick(v, ['creatorId', 'creator_id', 'uid', 'userId', 'user_id', 'authorId', 'ownerId']);
    if (uid && users[uid] && typeof users[uid] === 'object') {
      if (!creator) creator = pick(users[uid], ['name', 'displayName', 'username', 'handle']);
      if (!avatar) avatar = pick(users[uid], AVATAR_KEYS.concat(['image']));
    }

    // A leftover "image" next to a separate thumbnail is most likely the creator's picture
    if (!avatar && pick(v, THUMB_KEYS.slice(0, 8))) {
      avatar = pick(v, ['image', 'imageUrl', 'image_url']);
    }

    videos.push({
      id: String(id),
      title: title || 'Untitled',
      videoUrl,
      thumbnail: thumb,
      creator,
      avatar,
      description: pick(v, ['description', 'desc', 'about', 'details']),
    });
  }
  return videos.reverse(); // newest first
}

module.exports = async (req, res) => {
  const send = (status, obj) => {
    res.statusCode = status;
    res.setHeader('Content-Type', 'application/json; charset=utf-8');
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Cache-Control', status === 200 ? 'public, s-maxage=30, stale-while-revalidate=60' : 'no-store');
    res.end(JSON.stringify(obj));
  };
  const authQs = AUTH ? '&auth=' + encodeURIComponent(AUTH) : '';

  try {
    // /grab.php?debug=1 lists the top-level nodes so you can find the right FIREBASE_PATH
    if (req.query && req.query.debug !== undefined) {
      const root = await fetchJson(`${DB_URL}/.json?shallow=true${authQs}`);
      return send(200, {
        databaseUrl: DB_URL,
        configuredPath: PATH,
        topLevelNodes: root && typeof root === 'object' ? Object.keys(root) : root,
      });
    }

    const qs = 'orderBy=' + encodeURIComponent('"$key"') + '&limitToLast=' + LIMIT + authQs;
    const raw = await fetchJson(`${DB_URL}/${PATH}.json?${qs}`);
    if (raw === null) return send(200, { count: 0, videos: [] });
    if (typeof raw !== 'object') throw new HttpError(502, 'Unexpected data at /' + PATH);

    let users = {};
    if (USERS_PATH) {
      const u = await fetchJson(`${DB_URL}/${USERS_PATH}.json${AUTH ? '?auth=' + encodeURIComponent(AUTH) : ''}`);
      if (u && typeof u === 'object') users = u;
    }

    const videos = normalize(raw, users);
    return send(200, { count: videos.length, videos });
  } catch (e) {
    return send(e.status || 500, { error: e.message, count: 0, videos: [] });
  }
};
