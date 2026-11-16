NetsOn endpoint (Vercel, Node)

Put in the ROOT of the project behind netson.vercel.app:
    api/grab.js
    vercel.json         (merge the "rewrites" entry if you already have one)

Delete any grab.php you previously put in the project root or api/ folder, otherwise
Vercel serves its source code as plain text (that is what causes "not valid JSON").
Also remove the old "functions": { "api/*.php": ... } entry if it is still in vercel.json.

If your project has a package.json containing "type": "module", rename api/grab.js to
api/grab.cjs and change the rewrite destination to /api/grab.cjs.

Deploy, then test:
    https://netson.vercel.app/grab.php            -> JSON with your videos
    https://netson.vercel.app/grab.php?debug=1    -> lists top-level Firebase nodes

php/grab.php is only for a host that genuinely runs PHP; Vercel does not need it.
