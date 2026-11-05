/*
 * Test de bout en bout des formulaires publics (lot 6) : Contact et Espace recruteur.
 *
 * Vérifie, en clair et en sombre à 375 px : chaque champ visible a son libellé, le champ piège
 * est hors écran et hors du parcours au clavier, le widget Turnstile est présent (clés de test de
 * Cloudflare en local : le widget a besoin d'Internet, sa zone suffit ici).
 *
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import { expect, test } from "@playwright/test";

for (const { url, forms } of [
    { url: "/contact", forms: 1 },
    { url: "/espace-recruteur", forms: 2 },
]) {
    test(`formulaires accessibles sur ${url}`, async ({ page }) => {
        const response = await page.goto(url, { waitUntil: "load" });
        expect(response?.status()).toBe(200);

        await expect(page.locator('[data-controller="turnstile"]')).toHaveCount(forms);

        // Chaque champ visible (hors champ piège et jetons) a un libellé associé
        const fields = page.locator("main form :is(input, select, textarea):not([type=hidden]):not([tabindex='-1'])");
        const count = await fields.count();
        expect(count).toBeGreaterThan(0);
        for (let i = 0; i < count; i++) {
            const field = fields.nth(i);
            const id = await field.getAttribute("id");
            expect(id, "champ sans identifiant").toBeTruthy();
            await expect(page.locator(`label[for="${id}"]`), `libellé manquant pour #${id}`).toHaveCount(1);
        }

        // Champ piège : hors écran, ignoré par le clavier et les lecteurs d'écran
        const traps = page.locator(".hp-field");
        await expect(traps).toHaveCount(forms);
        for (let i = 0; i < forms; i++) {
            const box = await traps.nth(i).boundingBox();
            expect(box === null || box.x + box.width < 0).toBe(true);
            await expect(traps.nth(i)).toHaveAttribute("aria-hidden", "true");
            await expect(traps.nth(i).locator("input")).toHaveAttribute("tabindex", "-1");
        }
    });
}
