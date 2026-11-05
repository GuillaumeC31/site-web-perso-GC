/*
 * RGPD dans un vrai navigateur (clair et sombre selon le projet) : politique de confidentialité (résumé de cinq
 * lignes au plus, sections repliables fermées par défaut, qui s'ouvrent même sans JavaScript ; axe-core sur la
 * page entièrement dépliée), mention courte sous le formulaire de contact, « Télécharger mes données » (JSON
 * lisible) dans l'espace recruteur de test. Base dédiée aux tests (E2eMode).
 *
 * Captures (ordinateur et mobile, clair et sombre) dans captures-rgpd/ (hors dépôt).
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";

const AUTH_DIR = path.resolve("tests/e2e/.auth");
const CAPTURE_DIR = path.resolve("captures-rgpd");

/** Audit axe-core WCAG A et AA, zéro violation. */
async function expectNoViolation(page) {
    const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).exclude(".sf-toolbar").analyze();
    const summary = results.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target.join(" ")).slice(0, 5) }));
    expect(summary, JSON.stringify(summary, null, 2)).toEqual([]);
}

/** Ouvre toutes les sections repliables de la page. */
async function openAll(page) {
    // La liste des sections fermées raccourcit à chaque clic : toujours ouvrir la première restante
    const closed = page.locator("details:not([open]) > summary");
    while ((await closed.count()) > 0) {
        await closed.first().click();
    }
}

test("politique de confidentialité : résumé court, sections repliables, accessible dépliée", async ({ page }) => {
    await page.goto("/confidentialite", { waitUntil: "load" });
    const items = page.getByTestId("privacy-summary").locator("li");
    expect(await items.count()).toBeLessThanOrEqual(5);
    await expect(page.locator("details[open]")).toHaveCount(0);
    await expect(page.getByTestId("cookies-table")).toBeHidden();

    await page.locator("#cookies > summary").click();
    await expect(page.getByTestId("no-tracking")).toBeVisible();
    await openAll(page);
    await expect(page.locator("details:not([open])")).toHaveCount(0);
    await expect(page.getByTestId("cnil-link")).toHaveAttribute("href", /^https:\/\/www\.cnil\.fr\//);
    await expectNoViolation(page);
});

test("sections repliables utilisables sans JavaScript", async ({ browser, baseURL }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, ignoreHTTPSErrors: true, baseURL });
    const page = await context.newPage();
    await page.goto("/confidentialite", { waitUntil: "load" });
    await page.locator("#bases-legales > summary").click();
    await expect(page.getByTestId("legal-basis-table")).toBeVisible();
    await page.locator("#bases-legales > summary").press("Enter");
    await expect(page.getByTestId("legal-basis-table")).toBeHidden();
    await context.close();
});

test.describe("espace recruteur connecté", () => {
    test.use({ storageState: path.join(AUTH_DIR, "recruiter.json") });

    test("Télécharger mes données : fichier JSON lisible", async ({ page }) => {
        await page.goto("/espace-recruteur/mes-donnees", { waitUntil: "load" });
        const downloadPromise = page.waitForEvent("download");
        await page.getByTestId("data-export").click();
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toBe("mes-donnees-guillaume-couget.json");
        const data = JSON.parse(fs.readFileSync(await download.path(), "utf8"));
        expect(data.demande.adresse_e_mail).toMatch(/@example\.test$/);
        expect(Array.isArray(data.echanges_avec_l_assistant)).toBe(true);
    });
});

test("captures RGPD", async ({ browser, baseURL }, testInfo) => {
    const scheme = testInfo.project.use.colorScheme === "dark" ? "sombre" : "clair";
    for (const [device, options] of [
        ["mobile", { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }],
        ["ordinateur", { viewport: { width: 1280, height: 800 } }],
    ]) {
        const common = { ...options, colorScheme: testInfo.project.use.colorScheme ?? "light", ignoreHTTPSErrors: true, baseURL };
        const context = await browser.newContext(common);
        const page = await context.newPage();
        await page.goto("/confidentialite", { waitUntil: "load" });
        await page.screenshot({ path: path.join(CAPTURE_DIR, `confidentialite-${device}-${scheme}.png`), fullPage: true });
        await openAll(page);
        await page.screenshot({ path: path.join(CAPTURE_DIR, `confidentialite-depliee-${device}-${scheme}.png`), fullPage: true });
        await page.goto("/contact", { waitUntil: "load" });
        await page.locator("form").first().screenshot({ path: path.join(CAPTURE_DIR, `contact-mention-${device}-${scheme}.png`) });
        await context.close();

        const recruiter = await browser.newContext({ ...common, storageState: path.join(AUTH_DIR, "recruiter.json") });
        const recruiterPage = await recruiter.newPage();
        await recruiterPage.goto("/espace-recruteur/mes-donnees", { waitUntil: "load" });
        await recruiterPage.screenshot({ path: path.join(CAPTURE_DIR, `mes-donnees-${device}-${scheme}.png`), fullPage: true });
        await recruiter.close();
    }
});
