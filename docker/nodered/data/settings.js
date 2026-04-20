const dashboardUser = process.env.NODE_RED_HTTP_USER || process.env.MQTT_BROKER_USERNAME || '';
const dashboardPassword = process.env.NODE_RED_HTTP_PASSWORD || process.env.MQTT_BROKER_PASSWORD || '';
const dashboardRealm = 'MQTT Dashboard';

// The dashboard now has operational buttons, so keep it behind basic auth by
// default. Falling back to the MQTT broker credentials means a deployment can
// become protected without adding extra env vars on day one.
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
    disableEditor: process.env.NODE_RED_ENABLE_EDITOR !== 'true',
    httpAdminMiddleware: basicAuthMiddleware,
    httpNodeMiddleware: basicAuthMiddleware,

    editorTheme: {
        projects: {
            enabled: false,
        },
    },
};
