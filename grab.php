<?php
/**
 * grab.php - reads videos from Firebase Realtime Database and returns clean JSON
 * for the NetsOn Roku channel.
 *
 * Configure with Vercel environment variables (or edit the defaults below):
 *   FIREBASE_DB_URL      e.g. https://my-project-default-rtdb.firebaseio.com
 *   FIREBASE_PATH        node that holds the videos           (default: videos)
 *   FIREBASE_USERS_PATH  optional node with creator profiles  (default: none)
 *   FIREBASE_AUTH        optional access token / database secret
 *   VIDEO_LIMIT          max videos returned                  (default: 200)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, s-maxage=30, stale-while-revalidate=60');

$DB_URL     = rtrim(getenv('FIREBASE_DB_URL') ?: 'https://YOUR-PROJECT-default-rtdb.firebaseio.com', '/');
$PATH       = trim(getenv('FIREBASE_PATH') ?: 'videos', '/');
$USERS_PATH = trim(getenv('FIREBASE_USERS_PATH') ?: '', '/');
$AUTH       = getenv('FIREBASE_AUTH') ?: '';
$LIMIT      = (int)(getenv('VIDEO_LIMIT') ?: 200);

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg, 'videos' => [], 'count' => 0]);
    exit;
}

function fetch_json($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx  = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        $err = ($body === false) ? 'request failed' : '';
    }
    if ($body === false || $body === null || $err) fail(502, 'Could not reach Firebase: ' . $err);
    if ($code !== 200) fail(502, 'Firebase returned HTTP ' . $code . ' (check database rules / URL)');
    return json_decode($body, true);
}

/** First non-empty string found under any of $keys (looks inside {url: ...} objects too). */
function pick($arr, $keys) {
    if (!is_array($arr)) return '';
    foreach ($keys as $k) {
        if (!isset($arr[$k])) continue;
        $v = $arr[$k];
        if (is_string($v) && trim($v) !== '') return trim($v);
        if (is_array($v)) {
            $s = pick($v, ['url', 'src', 'link', 'original', 'large', 'medium']);
            if ($s !== '') return $s;
        }
    }
    return '';
}

$AVATAR_KEYS = ['creatorImage', 'creator_image', 'creatorAvatar', 'creator_avatar', 'avatar', 'avatarUrl',
                'avatar_url', 'profileImage', 'profile_image', 'profilePic', 'profile_pic', 'pfp',
                'userImage', 'user_image', 'authorImage', 'author_image', 'creatorPhoto', 'photo',
                'photoURL', 'picture'];
$THUMB_KEYS  = ['thumbnail', 'thumbnailUrl', 'thumbnail_url', 'thumb', 'thumbUrl', 'thumb_url', 'poster',
                'cover', 'preview', 'previewImage', 'imageUrl', 'image_url', 'image'];
$VIDEO_KEYS  = ['videoUrl', 'video_url', 'videoURL', 'url', 'video', 'link', 'src', 'source', 'stream',
                'streamUrl', 'mp4', 'file', 'videoLink', 'video_link'];
$CREATOR_KEYS = ['creator', 'creatorName', 'creator_name', 'author', 'authorName', 'uploader', 'username',
                 'userName', 'user', 'channel', 'channelName', 'owner', 'artist', 'by'];

// ---- Fetch videos (newest keys last in Firebase, so we reverse afterwards) ----
$qs = 'orderBy=' . rawurlencode('"$key"') . '&limitToLast=' . $LIMIT;
if ($AUTH !== '') $qs .= '&auth=' . rawurlencode($AUTH);
$raw = fetch_json("$DB_URL/$PATH.json?$qs");

if ($raw === null) {
    echo json_encode(['videos' => [], 'count' => 0]);
    exit;
}
if (!is_array($raw)) fail(502, 'Unexpected data at /' . $PATH);

// ---- Optional creator profiles ----
$users = [];
if ($USERS_PATH !== '') {
    $uqs = $AUTH !== '' ? '?auth=' . rawurlencode($AUTH) : '';
    $u = fetch_json("$DB_URL/$USERS_PATH.json$uqs");
    if (is_array($u)) $users = $u;
}

// ---- Normalize ----
$videos = [];
foreach ($raw as $id => $v) {
    if (!is_array($v)) continue;

    $videoUrl = pick($v, $VIDEO_KEYS);
    if ($videoUrl === '') continue;

    $thumb = pick($v, $THUMB_KEYS);
    $title = pick($v, ['title', 'name', 'videoTitle', 'video_title', 'caption']);

    $creator = '';
    $avatar  = '';
    foreach ($CREATOR_KEYS as $k) {
        if ($creator !== '' || !isset($v[$k])) continue;
        $c = $v[$k];
        if (is_string($c)) {
            $creator = trim($c);
        } elseif (is_array($c)) {
            $creator = pick($c, ['name', 'displayName', 'display_name', 'username', 'handle', 'title']);
            $avatar  = pick($c, array_merge($AVATAR_KEYS, ['image']));
        }
    }
    if ($avatar === '') $avatar = pick($v, $AVATAR_KEYS);

    // Look up the creator profile by id if one is available
    $uid = pick($v, ['creatorId', 'creator_id', 'uid', 'userId', 'user_id', 'authorId', 'ownerId']);
    if ($uid !== '' && isset($users[$uid]) && is_array($users[$uid])) {
        if ($creator === '') $creator = pick($users[$uid], ['name', 'displayName', 'username', 'handle']);
        if ($avatar === '')  $avatar  = pick($users[$uid], array_merge($AVATAR_KEYS, ['image']));
    }

    // A leftover "image" field next to a separate thumbnail is most likely the creator's picture
    if ($avatar === '' && pick($v, array_slice($THUMB_KEYS, 0, 8)) !== '') {
        $avatar = pick($v, ['image', 'imageUrl', 'image_url']);
    }

    $videos[] = [
        'id'          => (string)$id,
        'title'       => $title !== '' ? $title : 'Untitled',
        'videoUrl'    => $videoUrl,
        'thumbnail'   => $thumb,
        'creator'     => $creator,
        'avatar'      => $avatar,
        'description' => pick($v, ['description', 'desc', 'about', 'details']),
    ];
}

$videos = array_reverse($videos); // newest first

echo json_encode(['count' => count($videos), 'videos' => $videos], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
