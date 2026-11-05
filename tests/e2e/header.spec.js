/*
 * En-tête fixe (lot 5e) : collant et réduit au défilement sur ordinateur ; masqué en descendant et
 * réaffiché en remontant sur mobile ; jamais masqué menu ouvert ; sans animation si l'utilisateur
 * réduit les animations ; éléments collants de /calculatrice placés sous l'en-tête.
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import { expect, test } from "@playwright/test";

/** Fait défiler la page jusqu'à y (px) et laisse l'en-tête réagir. */
async function scrollTo(page, y) {
    await page.evaluate((top) => window.scrollTo({ top, behavior: "instant" }), y);
    await page.waitForTimeout(150);
}

test("mobile : masqué en descendant, réaffiché en remontant, visible menu ouvert", async ({ page }) => {
    await page.goto("/calculatrice");
    const header = page.locator("header[role='banner']");
    await scrollTo(page, 600);
    await expect(header).toHaveAttribute("data-hidden", "");
    // Les pastilles collantes remontent en haut de l'écran
    const pills = page.locator("nav.sticky").first();
    await expect.poll(async () => Math.round((await pills.boundingBox()).y)).toBe(0);
    await scrollTo(page, 500);
    await expect(header).not.toHaveAttribute("data-hidden");
    await expect.poll(async () => Math.round((await header.boundingBox()).y)).toBe(0);
    // Les pastilles se placent sous l'en-tête visible
    const headerBox = await header.boundingBox();
    await expect.poll(async () => Math.round((await pills.boundingBox()).y)).toBe(Math.round(headerBox.height));

    await page.getByRole("button", { name: "Ouvrir le menu" }).click();
    await scrollTo(page, 900);
    await expect(header).not.toHaveAttribute("data-hidden");
    await expect(page.locator("#mobile-navigation")).toBeVisible();
});

test("mobile : sans animation si l'utilisateur réduit les animations", async ({ page }) => {
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.goto("/calculatrice");
    // Règle globale de app.css (prefers-reduced-motion) : durées ramenées à 0,01 ms
    const duration = await page.locator("header[role='banner']").evaluate((element) => parseFloat(getComputedStyle(element).transitionDuration));
    expect(duration).toBeLessThan(0.001);
});

test.describe("ordinateur (1440 px)", () => {
    test.use({ viewport: { width: 1440, height: 900 }, isMobile: false, hasTouch: false });

    test("collant, réduit au défilement, jamais masqué ; colonne de /calculatrice sous l'en-tête", async ({ page }) => {
        await page.goto("/calculatrice");
        const header = page.locator("header[role='banner']");
        const full = (await header.boundingBox()).height;
        await scrollTo(page, 1500);
        await expect(header).toHaveAttribute("data-compact", "");
        await expect(header).not.toHaveAttribute("data-hidden");
        const box = await header.boundingBox();
        expect(Math.round(box.y)).toBe(0);
        await expect.poll(async () => (await header.boundingBox()).height).toBeLessThan(full);
        // La colonne collante reste sous l'en-tête (offset + 1,5 rem)
        const column = page.locator("nav[aria-label] ul.sticky").first();
        await expect.poll(async () => Math.round((await column.boundingBox()).y)).toBe(Math.round((await header.boundingBox()).height + 24));
    });

    test("une ancre de catégorie s'arrête sous l'en-tête", async ({ page }) => {
        await page.goto("/calculatrice#thermodynamique");
        await page.waitForTimeout(300);
        const header = await page.locator("header[role='banner']").boundingBox();
        const title = await page.locator("#category-thermodynamique").boundingBox();
        expect(title.y).toBeGreaterThanOrEqual(header.y + header.height);
    });
});
