const crypto = require('crypto');
const path = require('path');

const dashboardUser = process.env.NODE_RED_HTTP_USER || process.env.MQTT_BROKER_USERNAME || '';
const dashboardPassword = process.env.NODE_RED_HTTP_PASSWORD || process.env.MQTT_BROKER_PASSWORD || '';
const dashboardRealm = 'MQTT Dashboard';
const isEditorEnabled = process.env.NODE_RED_ENABLE_EDITOR === 'true';

// ─── EDITOR PROTECTION (Basic Auth) ──────────────────────────────────────────
// Used only for the Node-RED editor (httpAdminMiddleware). The browser shows its
// native Basic Auth dialog — acceptable for an internal maintenance screen.
const basicAuthMiddleware = dashboardUser && dashboardPassword
    ? (req, res, next) => {
        const header = req.headers.authorization || '';

        if (!header.startsWith('Basic ')) {
            res.set('WWW-Authenticate', `Basic realm="${dashboardRealm}"`);
            res.status(401).send('Authentication required.');
            return;
        }

        const decoded = Buffer.from(header.slice(6), 'base64').toString('utf8');
        const separator = decoded.indexOf(':');
        const providedUser = separator === -1 ? decoded : decoded.slice(0, separator);
        const providedPassword = separator === -1 ? '' : decoded.slice(separator + 1);

        if (providedUser !== dashboardUser || providedPassword !== dashboardPassword) {
            res.set('WWW-Authenticate', `Basic realm="${dashboardRealm}"`);
            res.status(401).send('Invalid credentials.');
            return;
        }

        next();
    }
    : undefined;

// ─── SESSION STORE ────────────────────────────────────────────────────────────
// Lightweight in-memory map: token → { createdAt }
// Tokens expire after 8 hours and are pruned on each request to /ui.
const sessions = new Map();
const SESSION_TTL_MS = 8 * 60 * 60 * 1000; // 8 hours

/** Generate a cryptographically random hex token. */
function generateToken() {
    return crypto.randomBytes(32).toString('hex');
}

/** Return true only if the token exists and has not expired. */
function isValidToken(token) {
    if (!token) return false;
    const session = sessions.get(token);
    if (!session) return false;
    if (Date.now() - session.createdAt > SESSION_TTL_MS) {
        sessions.delete(token); // prune expired session
        return false;
    }
    return true;
}

/** Parse the Cookie header into a plain key→value object. */
function parseCookies(cookieHeader) {
    const out = {};
    (cookieHeader || '').split(';').forEach(pair => {
        const idx = pair.indexOf('=');
        if (idx === -1) return;
        const key = pair.slice(0, idx).trim();
        const val = pair.slice(idx + 1).trim();
        if (key) out[key] = decodeURIComponent(val);
    });
    return out;
}

/**
 * Read a URL-encoded form body in the safest way for Node-RED/Express.
 *
 * Why this helper is needed:
 * - Some Node-RED / Express stacks reach this middleware with `req.body`
 *   already populated by an upstream parser.
 * - Other requests arrive as an unread stream that still needs `data`/`end`.
 * - Waiting only on `req.on('end')` can leave the browser spinning forever if
 *   the stream has already been consumed before this middleware runs.
 *
 * The helper therefore:
 * 1. Uses `req.body` immediately when it already exists.
 * 2. Falls back to reading the raw stream when it is still available.
 * 3. Returns an empty object instead of hanging when the stream has already
 *    ended and no parsed body is available any more.
 */
function readUrlEncodedForm(req) {
    if (req.body && typeof req.body === 'object' && !Buffer.isBuffer(req.body)) {
        return Promise.resolve(req.body);
    }

    if (typeof req.body === 'string') {
        return Promise.resolve(Object.fromEntries(new URLSearchParams(req.body)));
    }

    if (Buffer.isBuffer(req.body)) {
        return Promise.resolve(Object.fromEntries(new URLSearchParams(req.body.toString('utf8'))));
    }

    if (req.readableEnded || req.complete) {
        return Promise.resolve({});
    }

    return new Promise((resolve, reject) => {
        let body = '';

        const onData = chunk => {
            body += chunk.toString();
        };

        const onEnd = () => {
            cleanup();
            resolve(Object.fromEntries(new URLSearchParams(body)));
        };

        const onError = error => {
            cleanup();
            reject(error);
        };

        const cleanup = () => {
            req.off('data', onData);
            req.off('end', onEnd);
            req.off('error', onError);
        };

        req.on('data', onData);
        req.on('end', onEnd);
        req.on('error', onError);
    });
}

// ─── LOGIN PAGE HTML ──────────────────────────────────────────────────────────
// Served at GET /login. Self-contained Bootstrap 5 form — no extra files needed.
function buildLoginPage(errorMsg) {
    return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MQTT Dashboard – Sign in</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body { background: linear-gradient(135deg,#e0f0ff 0%,#f0e6ff 50%,#e8f5e9 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; }
    .login-card { width:100%; max-width:400px; border-radius:1rem; }
  </style>
</head>
<body>
  <div class="login-card card shadow border-0 p-4">
    <div class="text-center mb-4">
      <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
           style="width:64px;height:64px;background:rgba(13,110,253,.12);border:2px solid rgba(13,110,253,.25);">
        <i class="bi bi-broadcast-pin text-primary" style="font-size:1.8rem;"></i>
      </div>
      <h5 class="fw-bold mb-0">MQTT Dashboard</h5>
      <small class="text-muted">Sign in to continue</small>
    </div>
    ${errorMsg ? `<div class="alert alert-danger py-2 small">${errorMsg}</div>` : ''}
    <form method="POST" action="/login">
      <div class="mb-3">
        <label class="form-label small fw-semibold">Username</label>
        <input type="text" name="username" class="form-control" placeholder="Enter username" required autofocus>
      </div>
      <div class="mb-4">
        <label class="form-label small fw-semibold">Password</label>
        <input type="password" name="password" class="form-control" placeholder="Enter password" required>
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-semibold">
        <i class="bi bi-box-arrow-in-right me-2"></i>Sign in
      </button>
    </form>
  </div>
</body>
</html>`;
}

/**
 * Convert the different Express path fields into one stable pathname.
 *
 * Why this helper exists:
 * - `ui.middleware` and `httpStatic.middleware` are mounted on different roots.
 * - Express may expose `/login` as `req.originalUrl`, or as `req.baseUrl=/login`
 *   with `req.path=/`, depending on where the middleware is attached.
 * - Normalising once keeps the route checks simple and avoids subtle bugs where
 *   `/login` works in one middleware hook but not in another.
 */
function normaliseRequestPath(req) {
    const rawPath = req.originalUrl || req.url || req.path || req.baseUrl || '/';

    let pathname = rawPath;

    try {
        pathname = new URL(rawPath, 'http://localhost').pathname || '/';
    } catch (error) {
        pathname = rawPath || '/';
    }

    if (pathname.length > 1 && pathname.endsWith('/')) {
        return pathname.slice(0, -1);
    }

    return pathname || '/';
}

/**
 * Check whether the current request targets the standalone login endpoint.
 *
 * This is intentionally strict: only the dashboard login URL is handled here.
 * Nothing else on the Node-RED HTTP surface is modified.
 */
function isDashboardLoginRequest(req) {
    if (normaliseRequestPath(req) === '/login') {
        return true;
    }

    return req.baseUrl === '/login' && (req.path === '/' || req.path === '');
}

// ─── LOGIN ROUTE MIDDLEWARE ───────────────────────────────────────────────────
// This middleware is mounted ONLY on `/login` through `httpStatic`.
// It exists so port 8884 has a real `/login` endpoint while the rest of the
// Node-RED HTTP surface stays untouched.
const dashboardLoginRouteMiddleware = dashboardUser && dashboardPassword
    ? (req, res, next) => {
        if (!isDashboardLoginRequest(req)) {
            return next();
        }

        // ── Handle GET /login → serve the login form ──────────────────────────
        if (req.method === 'GET') {
            const cookies = parseCookies(req.headers.cookie || '');
            if (isValidToken(cookies['nr_session'])) {
                return res.redirect('/ui');
            }

            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            return res.status(200).send(buildLoginPage(null));
        }

        // ── Handle POST /login → validate credentials and issue session ───────
        if (req.method === 'POST') {
            readUrlEncodedForm(req)
                .then(form => {
                    const user = typeof form.username === 'string' ? form.username : '';
                    const pass = typeof form.password === 'string' ? form.password : '';

                    if (user === dashboardUser && pass === dashboardPassword) {
                        // Credentials match → create session token, set cookie, go to /ui
                        const token = generateToken();
                        sessions.set(token, { createdAt: Date.now() });
                        res.setHeader('Set-Cookie',
                            `nr_session=${token}; HttpOnly; Path=/; SameSite=Lax; Max-Age=${SESSION_TTL_MS / 1000}`);
                        return res.redirect('/ui');
                    }

                    // Bad credentials → re-render login form with error message
                    res.setHeader('Content-Type', 'text/html; charset=utf-8');
                    return res.status(401).send(buildLoginPage('Invalid username or password.'));
                })
                .catch(() => {
                    res.setHeader('Content-Type', 'text/html; charset=utf-8');
                    return res.status(400).send(buildLoginPage('The sign-in request could not be processed. Please try again.'));
                });
            return; // body handling is asynchronous; do not call next()
        }

        res.setHeader('Allow', 'GET, POST');
        return res.status(405).send('Method Not Allowed');
    }
    : undefined; // if no credentials are configured, no login route is needed

// ─── DASHBOARD UI MIDDLEWARE ──────────────────────────────────────────────────
// This hook is provided by node-red-dashboard itself. That matters because `/ui`
// is owned by the dashboard package, not by generic HTTP In nodes.
const dashboardUiMiddleware = dashboardUser && dashboardPassword
    ? (req, res, next) => {
        const cookies = parseCookies(req.headers.cookie || '');
        if (isValidToken(cookies['nr_session'])) {
            return next(); // authenticated — let the dashboard render normally
        }

        // First visit without a session cookie → send the browser to the
        // dedicated dashboard login page on the same port.
        res.redirect('/login');
    }
    : undefined; // if no credentials are configured, the dashboard stays public

module.exports = {
    flowFile: 'flows.json',

    // Reuse the broker password as a stable credential secret unless an
    // explicit Node-RED secret is provided by the deployment environment.
    credentialSecret: process.env.NODE_RED_CREDENTIAL_SECRET || process.env.MQTT_BROKER_PASSWORD,

    logging: {
        console: {
            level: 'info',
            metrics: false,
            audit: false,
        },
    },

    contextStorage: {
        default: {
            module: 'localfilesystem',
        },
    },

    exportGlobalContextKeys: false,
    functionExternalModules: false,

    // The dashboard is the product surface here. Disable the editor unless the
    // operator explicitly re-enables it for maintenance.
    //
    // This extra `httpAdminRoot` guard is important:
    // - Node-RED's default admin root is `/`
    // - leaving it there lets admin middleware compete with `/login` and `/ui`
    // - setting it to `false` when the editor is disabled removes that conflict
    // - when the editor is enabled on purpose, it lives under `/red` only
    disableEditor: !isEditorEnabled,
    httpAdminRoot: isEditorEnabled ? '/red' : false,
    // Editor keeps the native Basic Auth browser dialog, but only when the
    // editor itself is deliberately enabled for maintenance.
    httpAdminMiddleware: isEditorEnabled ? basicAuthMiddleware : undefined,
    // `/login` exists only for this dashboard service and is mounted narrowly so
    // unrelated Node-RED routes are not rewritten.
    httpStatic: [
        {
            path: path.join(__dirname, '.dashboard-login-static'),
            root: '/login',
            middleware: dashboardLoginRouteMiddleware,
        },
    ],
    // The dashboard package documents its own `ui.middleware` hook for `/ui`.
    // Using that hook keeps the redirect behaviour scoped to the MQTT dashboard.
    ui: {
        path: 'ui',
        middleware: dashboardUiMiddleware,
    },

    editorTheme: {
        projects: {
            enabled: false,
        },
    },
};
