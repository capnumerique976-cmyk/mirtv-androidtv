// Tests de bout en bout du site MirTV (Playwright). Adresse modifiable : MIRTV_URL=https://... npx playwright test
module.exports = {
  testDir: '.',
  timeout: 60000,
  retries: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.MIRTV_URL || 'https://mirtv.rahiss.com',
    screenshot: 'on',
    viewport: { width: 1920, height: 1080 },
    locale: 'fr-FR',
  },
};
