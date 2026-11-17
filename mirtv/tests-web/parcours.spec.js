// Parcours de l'appli web MirTV avec le faux Jellyfin (faux-jellyfin.js). Tout se fait au clavier,
// comme avec une télécommande : flèches, Entrée (OK), Échap (Retour).
const { test, expect } = require('@playwright/test');

const MOTS_INTERDITS = /serveur|flux|stream|m3u|iptv|transcodage|session|biblioth[eè]que|m[ée]tadonn[ée]es|codec|tuner|jellyfin|erreur *\d/i;

async function neuf(page) {
  await page.request.get('/__reset');
  await page.addInitScript(() => { window.MIRTV_URL_COMPTE = location.origin + '/compte'; }); // faux portail des comptes
  await page.goto('/tv/');
  await page.evaluate(() => localStorage.clear());
  await page.goto('/tv/');
  await expect(page.getByRole('heading', { name: 'Qui regarde ?' })).toBeVisible();
}
async function connexion(page, nom, mdp = 'test') {
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await page.getByRole('button', { name: 'Utiliser le nom et le mot de passe' }).click();
  await page.getByLabel('Nom du compte').fill(nom);
  await page.getByLabel('Mot de passe', { exact: true }).fill(mdp);
  await page.getByLabel('Mot de passe', { exact: true }).press('Enter');
}
async function sansMotInterdit(page) {
  const texte = await page.locator('#app').innerText();
  expect(texte).not.toMatch(MOTS_INTERDITS);
}
const appels = async page => (await page.request.get('/__appels')).json();

test('premier lancement : « Qui regarde ? » propose « Se connecter »', async ({ page }) => {
  await neuf(page);
  await expect(page.getByText('Aucun profil sur cet écran pour le moment.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Se connecter' })).toBeFocused();
  await sansMotInterdit(page);
});

test('connexion rapide par code, puis accueil à 5 tuiles dans le bon ordre', async ({ page }) => {
  await neuf(page);
  await page.keyboard.press('Enter');
  await expect(page.locator('[data-code]')).toHaveText('482913');
  await expect(page.getByText('Télé en direct')).toBeVisible({ timeout: 12000 });
  const tuiles = await page.locator('.accueil-grille .tuile').allInnerTexts();
  expect(tuiles.map(x => x.split('\n')[0].trim())).toEqual(['Télé en direct', 'Reprendre', 'Films et vidéos', 'Mes favoris', 'Aide']);
  await expect(page.locator('[data-cle="tele"]')).toBeFocused();
  await sansMotInterdit(page);
});

test('une chaîne en 2 appuis, lecture directe, zapping haut/bas, bandeau Maintenant / Ensuite', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await expect(page.locator('[data-cle="tele"]')).toBeFocused();
  await page.keyboard.press('Enter');                       // appui 1 : Télé en direct
  await expect(page.locator('.carte-chaine').first()).toBeFocused();
  await page.keyboard.press('Enter');                       // appui 2 : la chaîne
  await expect(page.locator('.lecteur')).toBeVisible();
  await expect(page.locator('.nom-chaine')).toContainText('Info Une');
  const info = (await appels(page)).find(a => a.chemin === '/Items/c1/PlaybackInfo');
  expect(JSON.parse(info.corps)).toMatchObject({ EnableTranscoding: false, EnableDirectStream: false, EnableDirectPlay: true });
  await page.keyboard.press('ArrowUp');                     // chaîne suivante
  await expect(page.locator('.nom-chaine')).toContainText('Info Deux');
  await page.keyboard.press('ArrowDown');                   // chaîne précédente
  await expect(page.locator('.nom-chaine')).toContainText('Info Une');
  await page.keyboard.press('Enter');                       // bandeau
  await expect(page.locator('.bandeau')).toContainText('Maintenant');
  await expect(page.locator('.bandeau')).toContainText('Ensuite');
  await expect(page.getByRole('button', { name: 'Ajouter à mes favoris' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('button', { name: 'Retirer de mes favoris' })).toBeVisible();
  await page.keyboard.press('Escape');                      // ferme le bandeau
  await expect(page.locator('.bandeau')).toBeHidden();
  await page.keyboard.press('Escape');                      // retour à la grille
  await expect(page.getByRole('heading', { name: 'Télé en direct' })).toBeVisible();
  await sansMotInterdit(page);
  // Mes favoris montre la chaîne ajoutée
  await page.keyboard.press('Escape');
  await page.locator('[data-cle="favoris"]').click();
  await expect(page.locator('.grille-favoris')).toContainText('Info Une');
});

test('chaîne en panne : message calme et « Choisir une autre chaîne »', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await page.locator('[data-cle="tele"]').click();
  await page.locator('[data-cle="c-c7"]').click();
  await expect(page.getByText('Cette chaîne ne marche pas en ce moment.')).toBeVisible({ timeout: 15000 });
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Télé en direct' })).toBeVisible();
  await sansMotInterdit(page);
});

test('onglets : seuls les onglets non vides, et la grille suit l’onglet', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await page.locator('[data-cle="tele"]').click();
  await expect(page.locator('.onglet')).toHaveText(['Infos', 'Cinéma', 'Sport', 'Culture', 'Enfants']);
  await page.locator('[data-cle="o-onglet-sport"]').click();
  await expect(page.locator('.carte-chaine')).toHaveCount(1);
  await expect(page.locator('.carte-chaine')).toContainText('Sport Max');
});

test('Programme : maintenant, ensuite, ce soir pour chaque chaîne', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await page.locator('[data-cle="tele"]').click();
  await page.locator('[data-cle="c-c1"]').click();
  await page.keyboard.press('Enter');
  await page.getByRole('button', { name: 'Voir le programme' }).click();
  await expect(page.getByRole('heading', { name: 'Programme' })).toBeVisible();
  const ligne = page.locator('[data-cle="pg-c1"]');
  await expect(ligne).toContainText('Programme actuel c1');
  await expect(ligne).toContainText('Programme suivant c1');
  await expect(ligne).toContainText('Ce soir');
});

test('Reprendre : « Il reste N min » et reprise en 2 appuis ; fiche vidéo', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await expect(page.locator('[data-cle="tele"]')).toBeFocused();
  await page.keyboard.press('ArrowRight');                  // Reprendre
  await expect(page.locator('[data-cle="reprendre"]')).toBeFocused();
  await page.keyboard.press('Enter');                       // appui 1
  await expect(page.locator('.carte-reprendre').first()).toContainText('Il reste 60 min');
  await expect(page.getByRole('button', { name: 'Recommencer depuis le début' })).toBeVisible();
  await page.keyboard.press('Enter');                       // appui 2 : lecture
  await expect(page.locator('.lecteur video')).toHaveAttribute('src', /\/Videos\/v1\/stream\?static=true/);
  const arrets = (await appels(page)).filter(a => /Transcod|master\.m3u8/i.test(a.chemin + a.requete));
  expect(arrets).toHaveLength(0);
  await expect(page.getByText('Cette vidéo ne s\'ouvre pas sur cet écran.')).toBeVisible({ timeout: 25000 }); // le faux serveur n'a pas de fichier
  await page.keyboard.press('Enter');
  // La reprise tombe à la bonne seconde (court métrage commencé à 10 s)
  await page.locator('.carte-reprendre').nth(1).click();
  await page.waitForFunction(() => { const v = document.querySelector('.lecteur video'); return v && v.currentTime > 9; }, null, { timeout: 15000 });
  const t = await page.evaluate(() => document.querySelector('.lecteur video').currentTime);
  expect(t).toBeGreaterThanOrEqual(9); expect(t).toBeLessThan(15);
  // Films et vidéos → fiche
  await page.goto('/tv/');
  await page.locator('[data-cle="films"]').click();
  await expect(page.locator('.rangee h2')).toContainText(['Cuisine', 'Nature']);
  await page.getByRole('button', { name: 'Le Baobab Géant' }).click();
  await expect(page.getByRole('button', { name: 'Reprendre' })).toBeFocused();
  await expect(page.getByRole('button', { name: 'Recommencer depuis le début' })).toBeVisible();
  const resume = await page.locator('.resume').innerText();
  expect(resume.length).toBeLessThanOrEqual(121);
  await expect(page.locator('.duree')).toHaveText('1 h 20');
});

test('connecter un autre écran avec le code affiché sur cet écran', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await page.locator('[data-cle="aide"]').click();
  await page.getByRole('button', { name: 'Connecter un autre écran' }).click();
  for (const c of '111111') await page.keyboard.press(c);
  await expect(page.getByText('Ce code ne marche pas. Réessayez.')).toBeVisible();
  await page.waitForTimeout(700);
  for (const c of '482913') await page.keyboard.press(c);
  await expect(page.getByText('C\'est fait ! L\'autre écran est connecté.')).toBeVisible();
  const texte = await page.locator('#app').innerText();
  expect(texte).not.toMatch(/jellyfin/i);
});

test('bouton « Accueil » partout : grille, bandeau du lecteur, programme, espace enfants', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await page.locator('[data-cle="tele"]').click();
  await expect(page.locator('.barre [data-accueil]')).toBeVisible();
  await page.locator('[data-cle="c-c1"]').click();
  await page.locator('.bouton-accueil-video').click();                       // lecteur → accueil
  await expect(page.locator('.accueil-grille')).toBeVisible();
  await expect(page.locator('[data-accueil]')).toHaveCount(0);                // pas de bouton sur l'accueil lui-même
  await page.locator('[data-cle="tele"]').click();
  await page.locator('[data-cle="c-c1"]').click();
  await page.keyboard.press('Enter');                                          // bandeau
  await page.locator('.bandeau [data-accueil]').click();
  await expect(page.locator('.accueil-grille')).toBeVisible();
  await page.locator('[data-cle="films"]').click();
  await page.getByRole('button', { name: 'Le Baobab Géant' }).click();
  await page.locator('.barre [data-accueil]').click();                         // fiche vidéo → accueil
  await expect(page.locator('.accueil-grille')).toBeVisible();
  await page.keyboard.press('Escape');                                         // la pile est vidée : Retour → Qui regarde ?
  await expect(page.getByRole('heading', { name: 'Qui regarde ?' })).toBeVisible();
  // Espace enfants : « Accueil » ramène à l'accueil enfants, jamais à l'adulte
  await connexion(page, 'f1-lea');
  await page.locator('[data-cle="dessins"]').click();
  await page.locator('.barre [data-accueil]').click();
  await expect(page.getByRole('heading', { name: 'Que veux-tu regarder ?' })).toBeVisible();
  await expect(page.locator('.accueil-grille')).toHaveCount(0);
});

test('Mes favoris vide : message et « Voir la télé en direct »', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await page.locator('[data-cle="favoris"]').click();
  await expect(page.getByText('Pas encore de favori.')).toBeVisible();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Télé en direct' })).toBeVisible();
});

test('connexion : espaces copiés par erreur autour du mot de passe acceptés, case « Afficher »', async ({ page }) => {
  await neuf(page);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await page.getByRole('button', { name: 'Utiliser le nom et le mot de passe' }).click();
  await page.getByLabel('Nom du compte').fill('Utilisateur : F1\u2011parent\u200b ');
  await page.getByLabel('Mot de passe', { exact: true }).fill('  test \n');
  await page.getByLabel('Afficher le mot de passe').check();
  await expect(page.getByLabel('Mot de passe', { exact: true })).toHaveAttribute('type', 'text');
  await page.getByLabel('Mot de passe', { exact: true }).press('Enter');
  await expect(page.locator('[data-cle="tele"]')).toBeFocused();
});

test('trop d’écrans : jamais « mot de passe incorrect »', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');                       // un profil déjà mémorisé sur cet écran
  await expect(page.locator('[data-cle="tele"]')).toBeFocused();
  await page.keyboard.press('Escape');                      // retour à « Qui regarde ? »
  await connexion(page, 'plein', 'x');
  await expect(page.getByText('Votre compte est déjà ouvert sur 2 écrans. Fermez-en un.')).toBeVisible();
  await expect(page.getByText('Nom ou mot de passe incorrect.')).toHaveCount(0);
  await page.getByRole('button', { name: 'Voir mes écrans' }).click();
  await expect(page.getByText('Télé du salon')).toBeVisible();
});

test('espace enfants : monde fermé, sortie seulement avec le code parent', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-lea');
  await expect(page.getByRole('heading', { name: 'Que veux-tu regarder ?' })).toBeVisible();
  await expect(page.locator('.ecran.enfants')).toBeVisible();
  await expect(page.locator('.enfants-grille .tuile')).toHaveCount(3);
  // Retour et appui long sur Retour restent dans l'espace enfants
  await page.keyboard.press('Escape');
  await page.keyboard.down('Escape'); await page.waitForTimeout(1100); await page.keyboard.up('Escape');
  await expect(page.getByRole('heading', { name: 'Que veux-tu regarder ?' })).toBeVisible();
  // Un rechargement de la page ramène dans l'espace enfants, pas sur « Qui regarde ? »
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Que veux-tu regarder ?' })).toBeVisible();
  // Dessins animés : seulement les chaînes enfants
  await page.locator('[data-cle="dessins"]').click();
  await expect(page.locator('.carte-chaine')).toHaveCount(2);
  await page.keyboard.press('Escape');
  // Mes vidéos : pas de vidéo « pas-enfants »
  await page.locator('[data-cle="videos"]').click();
  await expect(page.locator('#app')).not.toContainText('Cuisine Facile');
  await page.keyboard.press('Escape');
  // Sortir : mauvais code puis bon code
  await page.locator('[data-cle="sortir"]').click();
  await expect(page.getByText('Entrez le code à 4 chiffres.')).toBeVisible();
  for (const c of '0000') await page.keyboard.press(c);
  await expect(page.getByText('Ce n\'est pas le bon code.', { exact: false })).toBeVisible();
  await page.getByRole('button', { name: 'Rester' }).click();
  await expect(page.getByRole('heading', { name: 'Que veux-tu regarder ?' })).toBeVisible();
  await page.locator('[data-cle="sortir"]').click();
  for (const c of '1234') await page.keyboard.press(c);
  await expect(page.getByRole('heading', { name: 'Qui regarde ?' })).toBeVisible();
  // Rien de payant, aucun mot interdit
  await sansMotInterdit(page);
});

test('enfant qui appuie partout : 400 touches au hasard, on ne sort jamais', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-lea');
  await expect(page.getByRole('heading', { name: 'Que veux-tu regarder ?' })).toBeVisible();
  const touches = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Enter', 'Escape', 'Backspace'];
  let graine = 7;
  for (let i = 0; i < 400; i++) {
    graine = (graine * 1103515245 + 12345) % 2147483648;
    await page.keyboard.press(touches[graine % touches.length]);
    if (i % 40 === 0) {
      const adulte = await page.locator('.accueil-grille, [data-cle="se-connecter"], [data-cle="reprendre"].tuile:not(.enfants-grille *)').count();
      expect(adulte).toBe(0);
    }
  }
  expect(await page.locator('.ecran.enfants, .ecran.lecteur').count()).toBeGreaterThan(0);
  expect(await page.locator('.accueil-grille').count()).toBe(0);
});

test('écran de pause en dehors de la plage horaire', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-dort');
  await expect(page.getByText('C\'est l\'heure de la pause !')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByText('C\'est l\'heure de la pause !')).toBeVisible();
});

test('réglages parent : appui long sur Retour, code, langue et nouveau code', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await expect(page.locator('[data-cle="tele"]')).toBeFocused();
  await page.keyboard.down('Escape'); await page.waitForTimeout(1000); await page.keyboard.up('Escape');
  await expect(page.getByText('Entrez le code à 4 chiffres.')).toBeVisible();
  for (const c of '1234') await page.keyboard.press(c);
  await expect(page.getByRole('heading', { name: 'Réglages' })).toBeVisible();
  await page.getByRole('button', { name: 'Changer le code parent' }).click();
  for (const c of '5678') await page.keyboard.press(c);
  await expect(page.getByText('Code enregistré.')).toBeVisible();
  // l'ancien code ne marche plus, le nouveau oui
  await page.keyboard.press('Escape');
  await page.keyboard.down('Escape'); await page.waitForTimeout(1000); await page.keyboard.up('Escape');
  for (const c of '1234') await page.keyboard.press(c);
  await expect(page.getByText('Ce n\'est pas le bon code.', { exact: false })).toBeVisible();
  for (const c of '5678') await page.keyboard.press(c);
  await expect(page.getByRole('heading', { name: 'Réglages' })).toBeVisible();
});

test('appli installable : manifeste, icônes et service worker', async ({ page, request }) => {
  const m = await (await request.get('/tv/manifest.webmanifest')).json();
  expect(m.name).toBe('MirTV');
  expect(m.display).toBe('standalone');
  expect(m.icons.some(i => i.sizes === '512x512')).toBeTruthy();
  for (const i of m.icons) expect((await request.get('/tv/' + i.src)).status()).toBe(200);
  await page.goto('/tv/');
  const sw = await page.evaluate(async () => { const r = await navigator.serviceWorker.ready; return !!r.active; });
  expect(sw).toBeTruthy();
});

test('téléphone : connexion et accueil sur un vrai téléphone Android', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true,
    userAgent: 'Mozilla/5.0 (Linux; Android 14; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36' });
  const page = await ctx.newPage();
  await neuf(page);
  await connexion(page, 'f1-parent');
  const tele = page.locator('[data-cle="tele"]');
  await expect(tele).toBeVisible();
  const boite = await tele.boundingBox();
  expect(boite.width).toBeLessThanOrEqual(390);
  await ctx.close();
});

test('habillage : images du programme, logos des chaînes, rangée « En direct maintenant »', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await expect(page.getByText('En direct maintenant')).toBeVisible({ timeout: 12000 });
  const rangee = page.locator('.cartes-direct .carte-chaine');
  expect(await rangee.count()).toBeGreaterThan(2);
  await expect(page.locator('.cartes-direct .plaque-logo img').first()).toBeVisible();      // logo officiel
  await expect(page.locator('.cartes-direct .visuel img').first()).toBeVisible();           // image du programme
  await expect(page.locator('.tuile-image.principale .photo').first()).toBeAttached({ timeout: 8000 });
  // Une chaîne sans logo garde ses initiales, sans image cassée
  const cassees = await page.evaluate(() => [...document.querySelectorAll('#app img')].filter(i => i.complete && i.naturalWidth === 0 && getComputedStyle(i).display !== 'none').length);
  expect(cassees).toBe(0);
  // OK sur une carte de la rangée ouvre la chaîne
  await page.locator('.cartes-direct .carte-chaine').first().focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('.lecteur')).toBeVisible();
  await sansMotInterdit(page);
});

test('comptes sans préfixe de famille : « irchad » va dans l’espace enfants, « parent1 » dans l’accueil adulte', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'Irchad');   // majuscule tapée : acceptée
  await expect(page.getByRole('heading', { name: 'Que veux-tu regarder ?' })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Que veux-tu regarder ?' })).toBeVisible();
  await page.locator('[data-cle="sortir"]').click();
  for (const c of '1234') await page.keyboard.press(c);
  await expect(page.getByRole('heading', { name: 'Qui regarde ?' })).toBeVisible();
  await expect(page.locator('.pastille-enfants')).toHaveCount(1);
  await connexion(page, 'parent1');
  await expect(page.locator('.accueil-grille')).toBeVisible();
  await expect(page.locator('.ecran.enfants')).toHaveCount(0);
});

test('vignette prise sur la chaîne quand le programme n’a pas d’image', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await page.locator('[data-cle="tele"]').click();
  await page.locator('[data-cle="o-onglet-culture"]').click();
  const carte = page.locator('[data-cle="c-c3"]');
  await expect(carte).toHaveClass(/avec-visuel/);
  await expect(carte.locator('.visuel img')).toHaveAttribute('src', /apercus\/culture-plus-1234abcd\.jpg/);
});

test('qualité des chaînes : 720p par défaut, réglable, et adresse mémorisée pour lancer plus vite', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await expect(page.locator('[data-cle="tele"]')).toBeFocused();
  await page.keyboard.down('Escape'); await page.waitForTimeout(1000); await page.keyboard.up('Escape');
  for (const c of '1234') await page.keyboard.press(c);
  await expect(page.getByRole('button', { name: 'Normale (720p)' })).toHaveClass(/principal/);
  await page.getByRole('button', { name: 'Économie (480p)' }).click();
  await expect(page.getByRole('button', { name: 'Économie (480p)' })).toHaveClass(/principal/);
  expect(await page.evaluate(() => localStorage.getItem('mirtv.qualite'))).toBe('480');
  // Une chaîne déjà ouverte : son adresse est mémorisée pour la fois suivante
  await page.goto('/tv/');
  await page.locator('[data-cle="tele"]').click();
  await page.locator('[data-cle="c-c1"]').click();
  await expect(page.locator('.lecteur video')).toBeAttached();
  await expect.poll(() => page.evaluate(() => Object.keys(JSON.parse(localStorage.getItem('mirtv.sources') || '{}')))).toContain('c1');
});

test('chaîne sans image : dessin de sa catégorie, puis image gardée après l’avoir regardée', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await page.locator('[data-cle="tele"]').click();
  await page.locator('[data-cle="o-onglet-enfants"]').click();
  const carte = page.locator('[data-cle="c-c6"]');
  await expect(carte).toHaveClass(/sans-visuel/);
  await expect(carte.locator('.motif svg')).toBeVisible();
  await carte.click();
  await expect.poll(() => page.evaluate(() => document.querySelector('.lecteur video')?.currentTime || 0), { timeout: 15000 }).toBeGreaterThan(1);
  await page.waitForTimeout(6500);
  await page.keyboard.press('Escape');
  await expect(page.locator('[data-cle="c-c6"]')).toHaveClass(/avec-visuel/, { timeout: 5000 });
  await expect(page.locator('[data-cle="c-c6"] .visuel img')).toHaveAttribute('src', /^blob:/);
});

test('écran neuf : le code d’activation connecte toute la famille', async ({ page }) => {
  await neuf(page);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await page.getByRole('button', { name: 'J’ai un code d’activation'.replace(/’/g, "'") }).click();
  await page.getByLabel('Code d’activation'.replace(/’/g, "'")).fill('abcd-0000');
  await page.keyboard.press('Enter');
  await expect(page.getByText('Ce code ne marche pas.', { exact: false })).toBeVisible();
  await page.getByLabel('Code d’activation'.replace(/’/g, "'")).fill('abcd-2345');
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Qui regarde ?' })).toBeVisible();
  await expect(page.locator('.profil .prenom')).toHaveText(['Parent', 'Lea', 'Se connecter']);
  await sansMotInterdit(page);
});

test('abonnement en pause : QR code et lien de paiement pour le parent, rien pour l’enfant', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await expect(page.locator('.accueil-grille')).toBeVisible();
  await expect.poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('mirtv.abonnements') || '{}').f1?.jeton)).toBeTruthy();
  await page.request.get('/__abo?statut=en_pause');
  await page.reload();
  await expect(page.getByText('Votre abonnement est en pause.').first()).toBeVisible();
  await expect(page.locator('.abo-qr svg')).toBeVisible();
  await expect(page.locator('[data-cle="payer"]')).toHaveAttribute('href', /\/compte\/payer\/[a-f0-9]{32}$/);
  await sansMotInterdit(page);
  // L'enfant de la même famille : un écran calme, sans QR ni paiement
  await page.evaluate(() => {
    const l = JSON.parse(localStorage.getItem('mirtv.profils'));
    l.push({ id: 'u-lea', nom: 'f1-lea', jeton: 'jeton-u-lea' });
    localStorage.setItem('mirtv.profils', JSON.stringify(l));
    localStorage.setItem('mirtv.ouvert', JSON.stringify('u-lea'));
  });
  await page.reload();
  await expect(page.getByText('On regarde plus tard !')).toBeVisible();
  await expect(page.locator('.abo-qr, [data-cle="payer"]')).toHaveCount(0);
  // Payé : la télé revient (au prochain lancement)
  await page.request.get('/__abo?statut=actif');
  await page.evaluate(() => localStorage.setItem('mirtv.ouvert', JSON.stringify('u-parent')));
  await page.reload();
  await expect(page.locator('.accueil-grille')).toBeVisible();
});

test('fin d’abonnement proche : bandeau « Renouveler » pour le parent', async ({ page }) => {
  await neuf(page);
  await page.request.get('/__abo?statut=actif&jours=2');
  await connexion(page, 'f1-parent');
  await expect(page.locator('.accueil-grille')).toBeVisible();
  await expect.poll(() => page.evaluate(() => !!JSON.parse(localStorage.getItem('mirtv.abonnements') || '{}').f1)).toBe(true);
  await page.reload();
  await expect(page.locator('.bandeau-abo')).toContainText('Votre abonnement se termine le');
  await page.locator('[data-cle="renouveler"]').click();
  await expect(page.locator('.abo-qr svg')).toBeVisible();
});

test('guichet : numéro WhatsApp de l\'aide et message à tous les écrans', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  // Rien de réglé : pas de bandeau, aide « numéro bientôt disponible »
  await expect(page.locator('.annonce')).toBeHidden();
  await page.locator('[data-cle="aide"]').click();
  await page.locator('[data-cle="contact"]').click();
  await expect(page.getByText('Numéro bientôt disponible.')).toBeVisible();
  // Ravi règle le numéro et un message dans le guichet
  await page.request.get('/__reglages?whatsapp=261341234567&annonce=' + encodeURIComponent('Travaux ce soir de 23 h à minuit') + '&type=alerte');
  await page.locator('[data-cle="contact"]').click();
  const lien = page.locator('a.numero-whatsapp');
  await expect(lien).toHaveText('+261 34 12 345 67');
  await expect(lien).toHaveAttribute('href', 'https://wa.me/261341234567?text=Bonjour%20MirTV');
  await expect(page.locator('.aide-qr svg')).toBeVisible();
  await expect(page.getByText('On vous répond en français, malgache.', { exact: false })).toBeVisible();
  await page.screenshot({ path: 'test-results/aide-whatsapp.png' });
  await page.locator('.barre [data-accueil]').click();
  await expect(page.locator('.annonce')).toBeVisible();
  await expect(page.locator('.annonce')).toHaveClass(/annonce-alerte/);
  await expect(page.locator('.annonce [data-texte]')).toHaveText('Travaux ce soir de 23 h à minuit');
  await page.screenshot({ path: 'test-results/accueil-annonce.png' });
  // Message retiré : le bandeau disparaît au prochain passage sur l'accueil
  await page.request.get('/__reglages?whatsapp=261341234567');
  await page.locator('[data-cle="aide"]').click();
  await page.locator('.barre [data-accueil]').click();
  await expect(page.locator('.annonce')).toBeHidden();
});

test('abonnement en pause : bouton d\'aide WhatsApp du guichet', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await expect(page.locator('.accueil-grille')).toBeVisible();
  await expect.poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('mirtv.abonnements') || '{}').f1?.jeton)).toBeTruthy();
  await page.request.get('/__abo?statut=en_pause');
  await page.reload();
  await expect(page.locator('.abo-pause')).toBeVisible();
  await expect(page.locator('.abo-pause [data-cle="aide"]')).toHaveAttribute('href', /wa\.me\/261340000000/);
});

test('lecteur : bouton « Plein écran » visible, entre et sort du plein écran (aussi dans le bandeau)', async ({ page }) => {
  await neuf(page);
  await connexion(page, 'f1-parent');
  await page.locator('[data-cle="tele"]').click();
  await page.locator('[data-cle="c-c1"]').click();
  const bouton = page.locator('[data-plein]');
  await expect(bouton).toBeVisible();
  await expect(bouton).toHaveAttribute('aria-label', 'Plein écran');
  await bouton.click();
  await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(true);
  await expect(bouton).toHaveAttribute('aria-label', 'Quitter le plein écran');
  await page.screenshot({ path: 'test-results/lecteur-plein-ecran.png' });
  await bouton.click();
  await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(false);
  // Télécommande : OK ouvre le bandeau, qui propose aussi « Plein écran »
  await page.keyboard.press('Enter');
  await expect(page.locator('.bandeau [data-cle="plein"]')).toBeVisible();
  await page.locator('.bandeau [data-cle="plein"]').click();
  await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(true);
  // En quittant le lecteur, on sort du plein écran
  await page.locator('.bouton-accueil-video').click();
  await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(false);
});
