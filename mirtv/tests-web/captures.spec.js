// Captures d'écran pour les fiches d'aide de Web Agence (dossier aide/ du kit).
// Lancer : CAPTURES=/chemin npx playwright test captures.spec.js --project=outils
const { test, expect } = require('@playwright/test');
const DOSSIER = process.env.CAPTURES || 'test-results/captures';
test.use({ viewport: { width: 1280, height: 720 } });

async function neuf(page) {
  await page.request.get('/__reset');
  await page.addInitScript(() => { window.MIRTV_URL_COMPTE = location.origin + '/compte'; });
  await page.goto('/tv/'); await page.evaluate(() => localStorage.clear()); await page.goto('/tv/');
  await expect(page.getByRole('heading', { name: 'Qui regarde ?' })).toBeVisible();
}
async function connexion(page, nom, mdp = 'test') {
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await page.getByRole('button', { name: 'Utiliser le nom et le mot de passe' }).click();
  await page.getByLabel('Nom du compte').fill(nom);
  await page.getByLabel('Mot de passe', { exact: true }).fill(mdp);
  await page.getByLabel('Mot de passe', { exact: true }).press('Enter');
}
const photo = (page, nom) => page.waitForTimeout(600).then(() => page.screenshot({ path: `${DOSSIER}/${nom}.png` }));

test('captures', async ({ page }) => {
  test.setTimeout(180000);
  await neuf(page);
  await photo(page, '01-qui-regarde');
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await page.getByRole('button', { name: "J'ai un code d'activation" }).click();
  await photo(page, '02-code-activation');
  await neuf(page);
  await page.request.get('/__reglages?whatsapp=261340000000');
  await connexion(page, 'f1-parent');
  await expect(page.locator('.accueil-grille')).toBeVisible();
  await photo(page, '03-accueil');
  await page.locator('[data-cle="aide"]').click();
  await photo(page, '04-aide');
  await page.locator('[data-cle="image"]').click();
  await page.waitForTimeout(2500);
  await photo(page, '05-aide-image-ne-marche-pas');
  await page.locator('.barre [data-accueil]').click();
  await page.locator('[data-cle="aide"]').click();
  await page.locator('[data-cle="contact"]').click();
  await photo(page, '06-aide-contacter');
  await page.locator('.barre [data-accueil]').click();
  await page.locator('[data-cle="aide"]').click();
  await page.locator('[data-cle="numero"]').click();
  await photo(page, '07-aide-numero-de-box');
  await page.locator('.barre [data-accueil]').click();
  await page.locator('[data-cle="aide"]').click();
  await page.getByRole('button', { name: 'Connecter un autre écran' }).click();
  await photo(page, '08-connecter-un-autre-ecran');
  await page.locator('.barre [data-accueil]').click();
  await page.locator('[data-cle="tele"]').click();
  await photo(page, '09-tele-en-direct');
  await page.locator('[data-cle="c-c7"]').click();
  await expect(page.getByText('Cette chaîne ne marche pas en ce moment.')).toBeVisible({ timeout: 15000 });
  await photo(page, '10-chaine-en-panne');
  // trop d'écrans
  await neuf(page);
  await connexion(page, 'f1-parent');
  await expect(page.locator('[data-cle="tele"]')).toBeFocused();
  await page.keyboard.press('Escape');
  await connexion(page, 'plein', 'x');
  await expect(page.getByText('Votre compte est déjà ouvert sur 2 écrans. Fermez-en un.')).toBeVisible();
  await photo(page, '11-trop-d-ecrans');
  await page.getByRole('button', { name: 'Voir mes écrans' }).click();
  await expect(page.getByText('Télé du salon')).toBeVisible();
  await photo(page, '12-voir-mes-ecrans');
  // fin d'abonnement proche puis pause
  await neuf(page);
  await page.request.get('/__abo?statut=actif&jours=2');
  await connexion(page, 'f1-parent');
  await expect(page.locator('.accueil-grille')).toBeVisible();
  await expect.poll(() => page.evaluate(() => !!JSON.parse(localStorage.getItem('mirtv.abonnements') || '{}').f1)).toBe(true);
  await page.reload();
  await expect(page.locator('.bandeau-abo')).toBeVisible();
  await photo(page, '13-fin-abonnement-proche');
  await page.request.get('/__abo?statut=en_pause');
  await page.reload();
  await expect(page.locator('.abo-pause')).toBeVisible();
  await photo(page, '14-abonnement-en-pause');
  // espace enfants
  await neuf(page);
  await connexion(page, 'f1-lea');
  await expect(page.getByRole('heading', { name: 'Que veux-tu regarder ?' })).toBeVisible();
  await photo(page, '15-espace-enfants');
});
