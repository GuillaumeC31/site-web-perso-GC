/*
 * Sécurité des envois dans un vrai navigateur (clair et sombre selon le projet) : /.well-known/security.txt servi en
 * texte, réglage du plafond des e-mails aux personnes non validées (Communication › E-mails) et rappel de sauvegarde du
 * tableau de bord, accessibles (axe-core). Base dédiée aux tests (E2eMode) : vos réglages réels ne sont jamais touchés.
 *
 * Captures (ordinateur et mobile, clair et sombre) dans captures-securite/ (hors dépôt).
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const AUTH_DIR = path.resolve("tests/e2e/.auth");
const CAPTURE_DIR = path.resolve("captures-securite");

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

test("security.txt servi en texte (RFC 9116)", async ({ request }) => {
    const response = await request.get("/.well-known/security.txt");
    expect(response.status()).toBe(200);
    expect(response.headers()["content-type"]).toMatch(/^text\/plain/);
    const text = await response.text();
    expect(text).toContain("Contact: https://guillaume-couget.fr/contact");
    expect(text).toMatch(/^Expires: \d{4}-\d{2}-\d{2}T/m);
});

test("onglets Communication et tableau de bord sans débordement horizontal à 375 px", async ({ page, context, baseURL }) => {
    const admin = openAdminSession(baseURL);
    await context.addCookies([admin.cookie]);
    for (const suffix of ["", "/communication", "/communication/e-mails", "/communication/notifications", "/communication/discord", "/communication/telegram", "/communication/validation-a-distance", "/communication/reseaux-sociaux"]) {
        await page.goto(`/${admin.adminPath}${suffix}`, { waitUntil: "load" });
        const width = await page.evaluate(() => document.documentElement.scrollWidth);
        expect(width, suffix || "tableau de bord").toBeLessThanOrEqual(375);
    }
});

test("plafond et rappel de sauvegarde accessibles, captures", async ({ browser, baseURL }, testInfo) => {
    const admin = openAdminSession(baseURL);
    const scheme = testInfo.project.use.colorScheme === "dark" ? "sombre" : "clair";
    for (const [device, options] of [
        ["mobile", { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }],
        ["ordinateur", { viewport: { width: 1280, height: 800 } }],
    ]) {
        // Mouvements réduits : pas de défilement doux pendant les captures d'éléments (motion-safe:scroll-smooth)
        const context = await browser.newContext({ ...options, colorScheme: testInfo.project.use.colorScheme ?? "light", reducedMotion: "reduce", ignoreHTTPSErrors: true, baseURL });
        await context.addCookies([admin.cookie]);
        const page = await context.newPage();

        await page.goto(`/${admin.adminPath}/communication/e-mails`, { waitUntil: "load" });
        const cap = page.getByTestId("unvalidated-cap");
        await expect(cap.getByLabel("E-mails par jour aux personnes non validées")).toHaveValue(/^\d+$/);
        await expectNoViolation(page);
        // Élément centré : l'en-tête fixe de l'admin ne le recouvre pas sur la capture
        await cap.evaluate((el) => el.scrollIntoView({ block: "center", behavior: "instant" }));
        // Page entière : la zone d'administration défile dans son propre conteneur, la capture d'élément y est décalée
        await page.screenshot({ path: path.join(CAPTURE_DIR, `communication-e-mails-${device}-${scheme}.png`), fullPage: true });

        await page.goto(`/${admin.adminPath}`, { waitUntil: "load" });
        // Récapitulatif d'ouverture éventuel : le fermer pour voir la page
        const recap = page.locator(".swal2-confirm");
        if (await recap.isVisible().catch(() => false)) {
            await recap.click();
        }
        // Fond assombri de la fenêtre : attendre qu'il ait disparu (sinon axe mesure les contrastes à travers lui)
        await expect(page.locator(".swal2-container")).toHaveCount(0);
        const reminder = page.getByTestId("backup-reminder");
        await expect(reminder.getByRole("button", { name: "J'ai téléchargé une copie" })).toBeVisible();
        await expectNoViolation(page);
        await reminder.evaluate((el) => el.scrollIntoView({ block: "center", behavior: "instant" }));
        await reminder.screenshot({ path: path.join(CAPTURE_DIR, `rappel-sauvegarde-${device}-${scheme}.png`) });
        await page.locator('[data-testid="dashboard-tiles"]').screenshot({ path: path.join(CAPTURE_DIR, `tuiles-${device}-${scheme}.png`) });
        await context.close();
    }
});
