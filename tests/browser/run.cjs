const path = require('node:path');
const fs = require('node:fs');
const { spawn, spawnSync } = require('node:child_process');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '../..');
process.chdir(root);
const local = path.join(root, '.local-tools');
for (const dir of ['tmp', 'cypress-cache', 'browser-data']) fs.mkdirSync(path.join(local, dir), { recursive: true });
Object.assign(process.env, { CYPRESS_CACHE_FOLDER: path.join(local, 'cypress-cache'), TEMP: path.join(local, 'tmp'), TMP: path.join(local, 'tmp'), CYPRESS_APP_DATA_PATH: path.join(local, 'browser-data') });
const php = process.env.PHP_BINARY || 'php';
const database = path.join(root, 'database/browser-testing.sqlite');
const env = {...process.env, APP_ENV: 'testing', APP_KEY: 'base64:' + crypto.randomBytes(32).toString('base64'), APP_URL: 'http://127.0.0.1:8010', DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '', DB_AUTH_TOKEN: '', TURSO_DATABASE_URL: '', TURSO_AUTH_TOKEN: '', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'database', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', APP_DEBUG: 'true'};
if (fs.existsSync(path.join(root, 'bootstrap/cache/config.php'))) throw new Error('Clear cached Laravel configuration before browser tests to ensure database isolation.');
fs.closeSync(fs.openSync(database, 'a'));
const reset = spawnSync(php, ['artisan', 'migrate:fresh', '--seed', '--seeder=BrowserTestSeeder', '--force', '--no-interaction'], {env, stdio:'inherit', windowsHide:true});
if (reset.status !== 0) process.exit(1);
const server = spawn(php, ['artisan', 'serve', '--host=127.0.0.1', '--port=8010', '--no-reload', '--no-interaction'], {env, stdio: ['ignore','pipe','pipe'], windowsHide:true});
let serverLog = '';
server.stdout.on('data', data => serverLog += data.toString());
server.stderr.on('data', data => serverLog += data.toString());
server.on('error', error => { console.error(error.message); process.exitCode = 1; });
async function run() {
    try {
        let ready = false;
        for (let attempt=0; attempt<60; attempt++) {
            try { const result = await fetch('http://127.0.0.1:8010/up'); if (result.ok) { ready=true; break; } } catch {}
            await new Promise(resolve => setTimeout(resolve, 500));
        }
        if (!ready) throw new Error('Test server did not start: ' + serverLog);
        const results = await require('cypress').run({configFile:path.join(root,'cypress.config.cjs'), browser:'electron'});
        process.exitCode = results.totalFailed || results.failures || 0;
    } catch(error) { console.error(error); process.exitCode=1; }
    finally { if (process.platform === 'win32') spawnSync('taskkill', ['/pid', String(server.pid), '/T', '/F'], {stdio:'ignore',windowsHide:true}); else server.kill(); }
}
run();
