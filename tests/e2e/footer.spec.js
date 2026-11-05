/*
 * Pied de page public et page « Nouveautés » (décision du 2026-10-29) : mention avec émojis accessibles, numéro du
 * site sans préversion (lien vers /nouveautes), deux lignes de texte au plus sur mobile, accessibilité (axe-core,
 * clair et sombre selon le projet).
 *
 * Captures (ordinateur et mobile, clair et sombre) dans captures-pied-de-page/ (hors dépôt).
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import path from "node:path";

const CAPTURE_DIR = path.resolve("captures-pied-de-page");

/** Audit axe-core WCAG A et AA, zéro violation. */
async function expectNoViolation(page) {
    const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).exclude(".sf-toolbar").analyze();
    const summary = results.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target.join(" ")).slice(0, 5) }));
    expect(summary, JSON.stringify(summary, null, 2)).toEqual([]);
}

test("pied de page : mention, version publique et deux lignes au plus", async ({ page }) => {
    await page.goto("/", { waitUntil: "load" });
    const credits = page.getByTestId("footer-credits");
    await expect(credits).toContainText("Site conçu et développé avec");
    await expect(credits).toContainText("sous Symfony");
    await expect(credits.getByRole("img", { name: "amour" })).toBeVisible();
    await expect(credits.getByRole("img", { name: "café" })).toBeVisible();

    const version = page.getByTestId("footer-version");
    await expect(version).toHaveText(/v\d+\.\d+\.\d+$/);
    await expect(version).not.toHaveText(/alpha|rc/);

    // Deux lignes au plus : hauteur du bloc inférieure à trois hauteurs de ligne
    const { height, lineHeight } = await credits.evaluate((el) => ({ height: el.getBoundingClientRect().height, lineHeight: parseFloat(getComputedStyle(el).lineHeight) }));
    expect(height).toBeLessThan(lineHeight * 2.5);

    // Lien suivi par son adresse : en développement, la barre de débogage de Symfony recouvre le bas de l'écran
    await expect(version).toHaveAttribute("href", "/nouveautes");
    await page.goto("/nouveautes", { waitUntil: "load" });
    await expect(page.getByRole("heading", { level: 1, name: "Nouveautés" })).toBeVisible();
    await expect(page.getByTestId("whats-new")).toContainText("Version 2.0.0");
    await expectNoViolation(page);
});

test("accessibilité WCAG AA : accueil (pied de page)", async ({ page }) => {
    await page.goto("/", { waitUntil: "load" });
    await expectNoViolation(page);
});

test("captures du pied de page et de la page Nouveautés", async ({ browser }, testInfo) => {
    const scheme = testInfo.project.use.colorScheme === "dark" ? "sombre" : "clair";
    for (const [device, options] of [
        ["mobile", { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }],
        ["ordinateur", { viewport: { width: 1280, height: 800 } }],
    ]) {
        const context = await browser.newContext({ ...options, colorScheme: testInfo.project.use.colorScheme ?? "light", ignoreHTTPSErrors: true, baseURL: testInfo.project.use.baseURL ?? process.env.E2E_BASE_URL ?? "https://localhost:8000" });
        const page = await context.newPage();
        await page.goto("/", { waitUntil: "load" });
        await page.locator("footer").screenshot({ path: path.join(CAPTURE_DIR, `pied-de-page-${device}-${scheme}.png`) });
        await page.goto("/nouveautes", { waitUntil: "load" });
        await page.screenshot({ path: path.join(CAPTURE_DIR, `nouveautes-${device}-${scheme}.png`), fullPage: true });
        await context.close();
    }
});
