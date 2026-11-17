// Tests de l'appli web MirTV (Playwright).
//  - « parcours » : l'appli web avec un faux Jellyfin (faux-jellyfin.js), lancé automatiquement.
//  - « site »     : le vrai site (MIRTV_URL, par défaut https://mirtv.rahiss.com).
// Lancer : npx playwright test  (ou --project=parcours / --project=site)
const chrome = process.env.CHROME_PATH ? { launchOptions: { executablePath: process.env.CHROME_PATH } } : {};
module.exports = {
  timeout: 60000,
  retries: 1,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: { screenshot: 'only-on-failure', viewport: { width: 1920, height: 1080 }, locale: 'fr-FR', ...chrome },
  webServer: { command: 'node faux-jellyfin.js 8099', url: 'http://127.0.0.1:8099/tv/', reuseExistingServer: true, timeout: 20000 },
  projects: [
    { name: 'outils', testMatch: /(explorer|captures)\.spec\.js/, use: { baseURL: 'http://127.0.0.1:8099' } },
    { name: 'parcours', testMatch: /parcours\.spec\.js/, use: { baseURL: 'http://127.0.0.1:8099' } },
    { name: 'site', testMatch: /site\.spec\.js/, use: { baseURL: process.env.MIRTV_URL || 'https://mirtv.rahiss.com' } },
  ],
};
