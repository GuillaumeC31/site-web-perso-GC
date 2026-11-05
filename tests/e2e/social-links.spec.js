/*
 * Communication › Réseaux sociaux dans un vrai navigateur (clair et sombre selon le projet) : onglet accessible
 * (axe-core), ajout d'un lien masqué par défaut, affichage d'un clic dans le pied de page (simple lien, nouvel onglet,
 * icône locale), suppression confirmée. Base dédiée aux tests (E2eMode) : vos liens réels ne sont jamais touchés.
 *
 * Captures (ordinateur et mobile, clair et sombre) dans captures-reseaux/ (hors dépôt).
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const AUTH_DIR = path.resolve("tests/e2e/.auth");
const CAPTURE_DIR = path.resolve("captures-reseaux");

/** Ouvre une session admin dédiée et renvoie le cookie et le chemin de l'admin. */
function openAdminSession(baseURL) {
    const sessions = JSON.parse(fs.readFileSync(path.join(AUTH_DIR, "sessions.json"), "utf8"));
    const id = execFileSync("php", ["bin/console", "app:e2e:private-sessions", "admin-session"], { encoding: "utf8" }).trim().split("\n").pop();

    return { cookie: { name: sessions.cookie, value: id, url: baseURL }, adminPath: sessions.adminPath };
}

/** Audit axe-core WCAG A et AA une fois les animations finies, zéro violation. */
async function expectNoViolation(page) {
    await page.waitForFunction(() => document.getAnimations().every((a) => a.playState !== "running" || a.effect?.target?.classList?.contains("swal2-timer-progress-bar")));
    const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).exclude(".sf-toolbar").analyze();
    const summary = results.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target.join(" ")).slice(0, 5) }));
    expect(summary, JSON.stringify(summary, null, 2)).toEqual([]);
}

test.describe.configure({ mode: "serial" });

test("ajout masqué, affichage dans le pied de page, suppression", async ({ page, context, baseURL }, testInfo) => {
    const admin = openAdminSession(baseURL);
    await context.addCookies([admin.cookie]);
    // Adresse propre au projet, jamais contenue dans celle de l'autre (« mobile-375 » est un préfixe de
    // « mobile-375-sombre ») : les deux projets lancés en parallèle ne se gênent pas
    const url = `https://github.com/${testInfo.project.name}-essai-e2e`;

    await page.goto(`/${admin.adminPath}/communication/reseaux-sociaux`, { waitUntil: "load" });
    await expect(page.getByTestId("social-reminder")).toContainText("lien d'invitation public");
    await expectNoViolation(page);

    await page.getByRole("link", { name: "Ajouter un lien" }).click();
    await page.getByLabel("Réseau").selectOption("github");
    await page.getByLabel("Adresse").fill(url);
    await page.getByRole("button", { name: "Enregistrer" }).click();
    const item = page.locator("[data-testid=social-links] li", { hasText: url });
    await expect(item).toContainText("Masqué");

    await page.goto("/mentions-legales", { waitUntil: "load" });
    await expect(page.locator(`footer a[href="${url}"]`)).toHaveCount(0);

    await page.goto(`/${admin.adminPath}/communication/reseaux-sociaux`, { waitUntil: "load" });
    await item.getByRole("button", { name: /^Afficher/ }).click();
    await expect(item).toContainText("Visible");
    await page.goto("/mentions-legales", { waitUntil: "load" });
    const link = page.locator(`footer a[href="${url}"]`);
    await expect(link).toHaveAttribute("target", "_blank");
    await expect(link).toHaveAttribute("rel", "me noopener noreferrer");
    await expect(link.locator("svg")).toHaveAttribute("aria-hidden", "true");
    await expectNoViolation(page);

    await page.goto(`/${admin.adminPath}/communication/reseaux-sociaux`, { waitUntil: "load" });
    await item.getByRole("button", { name: /^Supprimer/ }).click();
    await page.getByRole("alertdialog").getByRole("button", { name: "Supprimer" }).click();
    await expect(page.locator("[data-testid=social-links] li", { hasText: url })).toHaveCount(0);
});

test("captures de l'onglet Réseaux sociaux et du pied de page", async ({ browser, baseURL }, testInfo) => {
    const admin = openAdminSession(baseURL);
    const scheme = testInfo.project.use.colorScheme === "dark" ? "sombre" : "clair";
    for (const [device, options] of [
        ["mobile", { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }],
        ["ordinateur", { viewport: { width: 1280, height: 800 } }],
    ]) {
        const context = await browser.newContext({ ...options, colorScheme: testInfo.project.use.colorScheme ?? "light", ignoreHTTPSErrors: true, baseURL });
        await context.addCookies([admin.cookie]);
        const page = await context.newPage();
        await page.goto(`/${admin.adminPath}/communication/reseaux-sociaux`, { waitUntil: "load" });
        await page.screenshot({ path: path.join(CAPTURE_DIR, `onglet-${device}-${scheme}.png`), fullPage: true });
        await page.goto(`/${admin.adminPath}/communication/reseaux-sociaux/nouveau`, { waitUntil: "load" });
        await page.screenshot({ path: path.join(CAPTURE_DIR, `formulaire-${device}-${scheme}.png`), fullPage: true });
        await page.goto("/", { waitUntil: "load" });
        await page.locator("footer").screenshot({ path: path.join(CAPTURE_DIR, `pied-de-page-${device}-${scheme}.png`) });
        await context.close();
    }
});
