/*
 * Pages légales (lot 8) : l'adresse dédiée aux droits RGPD est reconstituée par le navigateur dans la
 * politique de confidentialité (lien mailto), alors qu'elle n'est jamais écrite en clair dans le HTML.
 *
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import { expect, test } from "@playwright/test";

test("adresse RGPD reconstituée dans la politique de confidentialité", async ({ page, request }) => {
    const raw = await (await request.get("/confidentialite")).text();
    expect(raw).not.toContain("rgpd@");

    await page.goto("/confidentialite", { waitUntil: "load" });
    const link = page.locator('#droits [data-testid="rgpd-email"] a');
    await expect(link).toHaveAttribute("href", "mailto:rgpd@example.test");
    await expect(link).toHaveText("rgpd@example.test");
});
