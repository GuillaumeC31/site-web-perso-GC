/*
 * Test de bout en bout : aucune page publique ne déborde horizontalement à 375 px.
 *
 * Règle : document.documentElement.scrollWidth <= clientWidth (pas de défilement horizontal).
 * En cas d'échec, le message liste les éléments qui dépassent du bord droit, pour corriger.
 *
 * Pages : /, /cv, /projets, /contact, l'espace recruteur (demande, lien invalide), la calculatrice (tableau de bord, catégorie, outils les plus chargés) et la page du
 * premier projet public trouvé sur /projets
 * (test ignoré, avec un message, s'il n'existe encore aucun projet public).
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import { expect, test } from "@playwright/test";

/**
 * Mesure le débordement de la page et liste les éléments qui dépassent du bord droit.
 *
 * @param {import('@playwright/test').Page} page page chargée
 * @returns {Promise<{scrollWidth: number, clientWidth: number, offenders: string[]}>}
 */
async function measureOverflow(page) {
    return page.evaluate(() => {
        const root = document.documentElement;
        const limit = root.clientWidth;
        const offenders = [];

        for (const element of document.body.querySelectorAll("*")) {
            const box = element.getBoundingClientRect();
            if (box.width > 0 && box.right > limit + 1) {
                const id = element.id ? `#${element.id}` : "";
                const classes = typeof element.className === "string" ? `.${element.className.trim().split(/\s+/).slice(0, 4).join(".")}` : "";
                offenders.push(`${element.tagName.toLowerCase()}${id}${classes} (droite à ${Math.round(box.right)} px)`);
            }
        }

        return { scrollWidth: root.scrollWidth, clientWidth: limit, offenders: offenders.slice(0, 10) };
    });
}

/**
 * Charge une page et vérifie qu'elle ne déborde pas.
 *
 * @param {import('@playwright/test').Page} page page du navigateur
 * @param {string} url adresse relative
 */
async function expectNoOverflow(page, url) {
    const response = await page.goto(url, { waitUntil: "load" });
    expect(response?.status(), `${url} doit répondre 200`).toBe(200);

    const result = await measureOverflow(page);
    expect(result.scrollWidth, `${url} déborde : ${result.offenders.join(" ; ") || "élément non identifié"}`).toBeLessThanOrEqual(result.clientWidth);
}

for (const url of ["/", "/cv", "/projets", "/contact", "/espace-recruteur", "/espace-recruteur/lien-invalide", "/calculatrice", "/calculatrice/electricite", "/calculatrice/electricite/calculs-cables", "/calculatrice/electricite/puissances-ac", "/calculatrice/electronique/code-couleur-resistance", "/calculatrice/mesures-capteurs/pt100", "/calculatrice/mesures-capteurs/signal-4-20ma", "/calculatrice/mesures-capteurs/transmetteur-humidite", "/calculatrice/mecanique/engrenages", "/calculatrice/impression-3d/cout-impression", "/calculatrice/convertisseur/unites", "/calculatrice/thermodynamique/point-rosee", "/calculatrice/thermodynamique/fluides-frigorifiques", "/calculatrice/thermodynamique/chambre-froide", "/calculatrice/thermodynamique/enceinte-climatique", "/calculatrice/eclairage/adresse-dmx", "/calculatrice/eclairage/eclairage-phyto", "/calculatrice/eclairage/vieillissement-uv", "/calculatrice/terrariophilie/eclairage-reptiles", "/calculatrice/terrariophilie/volume-substrat"]) {
    test(`aucun débordement horizontal sur ${url}`, async ({ page }) => {
        await expectNoOverflow(page, url);
    });
}

test("aucun débordement horizontal sur une page projet", async ({ page }) => {
    await page.goto("/projets");
    const link = page.locator('main a[href^="/projets/"]').first();
    test.skip((await link.count()) === 0, "Aucun projet public pour l'instant : page projet non vérifiée.");

    await expectNoOverflow(page, (await link.getAttribute("href")) ?? "/projets");
});
