const { test, expect } = require('@playwright/test');

test('la page web s\'appelle MirTV dès le chargement (jamais Jellyfin)', async ({ page }) => {
  const reponse = await page.goto('/web/');
  expect(reponse.status()).toBe(200);
  const html = await (await page.request.get('/web/index.html')).text();
  expect(html).toContain('<title>MirTV</title>');
  expect(html).not.toMatch(/Jellyfin<\/title>/);
  await expect(page).toHaveTitle('MirTV');
});

test('le manifeste de l\'application web porte le nom MirTV', async ({ request }) => {
  const m = await (await request.get('/web/manifest.json')).json();
  expect(m.name).toBe('MirTV');
  expect(m.short_name).toBe('MirTV');
});

test('l\'écran de connexion n\'affiche pas le mot Jellyfin', async ({ page }) => {
  await page.goto('/web/#/login');
  await page.waitForTimeout(6000);
  await expect(page).toHaveTitle('MirTV');
  // La seule mention permise est l'avis légal « MirTV n'est pas le projet Jellyfin ».
  const visible = (await page.locator('body').innerText()).replace(/MirTV n'est pas le projet Jellyfin\./g, '');
  expect(visible).not.toMatch(/jellyfin/i);
  await page.screenshot({ path: 'connexion.png' });
});

test('le fichier de mise à jour de l\'appli existe et pointe vers un APK téléchargeable', async ({ request }) => {
  const r = await request.get('/app/test/version.json');
  expect(r.status()).toBe(200);
  const v = await r.json();
  expect(Object.keys(v).join(',')).toMatch(/version/i);
  const url = JSON.stringify(v).match(/https:\/\/[^"]+\.apk/);
  expect(url).not.toBeNull();
  const apk = await request.head(url[0]);
  expect(apk.status()).toBe(200);
  expect(Number(apk.headers()['content-length'])).toBeGreaterThan(5_000_000);
});

test('le back office est protégé par mot de passe', async ({ request }) => {
  const r = await request.get('https://admin.mirtv.rahiss.com/', { maxRedirects: 0 });
  expect([401, 403, 302, 303]).toContain(r.status());
});

test('le site est servi en HTTPS avec les en-têtes de sécurité', async ({ request }) => {
  const r = await request.get('/web/');
  const h = r.headers();
  expect(h['strict-transport-security']).toContain('max-age');
  expect(h['x-content-type-options']).toBe('nosniff');
  expect(h['server']).toBeUndefined();
});

test('l’appli web MirTV est en ligne à /tv/ et s’installe', async ({ page, request }) => {
  const r = await page.goto('/tv/');
  expect(r.status()).toBe(200);
  await expect(page).toHaveTitle('MirTV');
  await expect(page.getByRole('heading', { name: 'Qui regarde ?' })).toBeVisible();
  const m = await (await request.get('/tv/manifest.webmanifest')).json();
  expect(m.name).toBe('MirTV');
  expect((await request.get('/tv/sw.js')).status()).toBe(200);
  const texte = await page.locator('body').innerText();
  expect(texte).not.toMatch(/jellyfin|serveur|iptv|stream/i);
});

test('l’adresse principale ouvre l’appli web', async ({ request }) => {
  const r = await request.get('/', { maxRedirects: 0 });
  expect([301, 302, 307, 308]).toContain(r.status());
  expect(r.headers()['location']).toMatch(/\/tv\/$/);
});
