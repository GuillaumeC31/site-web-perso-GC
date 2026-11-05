/*
 * Page « Communication » de l'admin dans un vrai navigateur (clair et sombre selon le projet) :
 * - six onglets (lien actif marqué), encadré explicatif, aide « ? » au clavier, axe-core sans violation ;
 * - aperçu d'un e-mail avant d'enregistrer (HTML dans un cadre isolé, version texte), sans rien enregistrer ;
 * - e-mails de test dans Mailpit, un par catégorie (Recrutement, Général du site, Calculatrice) ;
 * - ancienne adresse des notifications (Paramètres) redirigée vers l'onglet Notifications ;
 * - sans JavaScript : onglets, aide « ? » (élément <details> natif) et page de confirmation du serveur.
 * Aucun bouton « notification de test » n'est cliqué : aucun envoi réel vers Discord ou Telegram.
 *
 * Captures (ordinateur et mobile, clair et sombre) dans captures-communication/ (hors dépôt).
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { deleteEmails, waitForEmail } from "./support/mailpit.js";

const AUTH_DIR = path.resolve("tests/e2e/.auth");
const CAPTURE_DIR = path.resolve("captures-communication");
const TABS = [
    ["", "Vue d'ensemble"],
    ["/e-mails", "E-mails"],
    ["/notifications", "Notifications"],
    ["/discord", "Discord"],
    ["/telegram", "Telegram"],
    ["/validation-a-distance", "Validation à distance"],
];

/** Ouvre une session admin dédiée (app:e2e:private-sessions admin-session) et renvoie le cookie et le chemin. */
function openAdminSession(baseURL) {
    const sessions = JSON.parse(fs.readFileSync(path.join(AUTH_DIR, "sessions.json"), "utf8"));
    const id = execFileSync("php", ["bin/console", "app:e2e:private-sessions", "admin-session"], { encoding: "utf8" }).trim().split("\n").pop();

    return { cookie: { name: sessions.cookie, value: id, url: baseURL }, adminPath: sessions.adminPath };
}

/** Audit axe-core WCAG A et AA, zéro violation. */
async function expectNoViolation(page) {
    const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).exclude(".sf-toolbar").analyze();
    const summary = results.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target.join(" ")).slice(0, 5) }));
    expect(summary, JSON.stringify(summary, null, 2)).toEqual([]);
}

test.describe("communication", () => {
    let admin;

    test.beforeEach(async ({ context, baseURL }) => {
        admin = openAdminSession(baseURL);
        await context.addCookies([admin.cookie]);
    });

    test("onglets : lien actif, encadré explicatif, accessibilité", async ({ page }) => {
        for (const [suffix, label] of TABS) {
            const response = await page.goto(`/${admin.adminPath}/communication${suffix}`, { waitUntil: "load" });
            expect(response?.status()).toBe(200);
            await expect(page.getByTestId("communication-tabs").locator('[aria-current="page"]')).toHaveText(label);
            await expect(page.getByTestId("info-box")).toContainText("À quoi ça sert");
            await expectNoViolation(page);
        }
    });

    test("aide « ? » accessible au clavier", async ({ page }) => {
        await page.goto(`/${admin.adminPath}/communication/e-mails`, { waitUntil: "load" });
        const help = page.getByTestId("help").first();
        const summary = help.locator("summary");
        await summary.focus();
        await page.keyboard.press("Enter");
        await expect(help).toHaveAttribute("open", "");
        await expect(help.locator("p")).toBeVisible();
    });

    test("aperçu d'un e-mail avant d'enregistrer", async ({ page }) => {
        await page.goto(`/${admin.adminPath}/communication/e-mails/contenu/refusal`, { waitUntil: "load" });
        await page.getByLabel("Objet").fill("Votre demande, {nom}");
        await page.getByLabel("Phrase d'introduction").fill("Merci {nom} ({entreprise}) : réponse {delai}.");
        await page.getByRole("button", { name: "Aperçu" }).click();
        await expect(page.getByTestId("preview-subject")).toHaveText("Votre demande, Camille Exemple");
        await expect(page.getByTestId("preview-text")).toContainText("Merci Camille Exemple (Entreprise Exemple) : réponse");
        await expect(page.frameLocator('[data-testid="preview-html"]').locator("body")).toContainText("Merci Camille Exemple");
        await expect(page.frameLocator('[data-testid="preview-html"]').locator("body")).toContainText("formulaire de contact du site");
        // Rien n'est enregistré : l'e-mail garde son texte d'origine
        await page.goto(`/${admin.adminPath}/communication/e-mails`, { waitUntil: "load" });
        await expect(page.getByTestId("email-types").locator("li", { hasText: "Refus" })).toContainText("Texte d'origine");
        await expectNoViolation(page);
    });

    test("e-mails de test dans Mailpit, un par catégorie", async ({ page }, testInfo) => {
        // Un seul projet : les e-mails de test des deux projets se mélangeraient dans Mailpit
        test.skip(testInfo.project.name !== "mobile-375", "Envoi réel vers Mailpit : un seul projet.");
        await page.goto(`/${admin.adminPath}/communication/e-mails`, { waitUntil: "load" });
        for (const label of ["Recrutement", "Général du site", "Calculatrice"]) {
            const before = new Date(Date.now() - 1000);
            await page.getByRole("button", { name: `Tester « ${label} »` }).click();
            // Envoi SMTP réel vers Mailpit : quelques secondes
            await expect(page.locator(".swal2-toast")).toContainText(`E-mail de test « ${label} » envoyé`, { timeout: 30_000 });
            // Étiquette « e2e-trace » : l'e-mail est bien repéré comme venant des tests (nettoyé en fin de suite)
            const email = await waitForEmail({ query: `tag:e2e-trace subject:"E-mail de test du site — ${label}"`, after: before });
            expect(email.from).toBe("noreply@example.test");
            expect(email.text).toContain(`Adresse testée : ${label}`);
        }
        // Seulement les e-mails des tests (étiquette « e2e-trace »), jamais vos vrais e-mails de test
        await deleteEmails("tag:e2e-trace");
    });

    test("ancienne adresse des notifications redirigée", async ({ page }) => {
        await page.goto(`/${admin.adminPath}/parametres/notifications`, { waitUntil: "load" });
        await expect(page).toHaveURL(new RegExp(`/${admin.adminPath}/communication/notifications$`));
        await expect(page.getByTestId("notification-table")).toBeVisible();
    });

    test("captures de la page Communication", async ({ browser, baseURL }, testInfo) => {
        const scheme = testInfo.project.use.colorScheme === "dark" ? "sombre" : "clair";
        for (const [device, options] of [
            ["mobile", { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }],
            ["ordinateur", { viewport: { width: 1280, height: 800 } }],
        ]) {
            const context = await browser.newContext({ ...options, colorScheme: testInfo.project.use.colorScheme ?? "light", ignoreHTTPSErrors: true, baseURL });
            await context.addCookies([admin.cookie]);
            const page = await context.newPage();
            for (const [suffix] of [...TABS, ["/e-mails/contenu/login_link"]]) {
                await page.goto(`/${admin.adminPath}/communication${suffix}`, { waitUntil: "load" });
                const name = suffix.replace(/^\//, "").replace(/[^a-z0-9]+/gi, "-") || "vue-d-ensemble";
                await page.screenshot({ path: path.join(CAPTURE_DIR, `${name}-${device}-${scheme}.png`), fullPage: true });
            }
            await context.close();
        }
    });
});

test.describe("communication sans JavaScript", () => {
    test.use({ javaScriptEnabled: false });

    test("onglets, aide et confirmation du serveur sans JavaScript", async ({ page, context, baseURL }) => {
        const admin = openAdminSession(baseURL);
        await context.addCookies([admin.cookie]);
        await page.goto(`/${admin.adminPath}/communication`, { waitUntil: "load" });
        await page.getByTestId("communication-tabs").getByRole("link", { name: "Notifications" }).click();
        await expect(page).toHaveURL(/\/communication\/notifications$/);
        await expect(page.getByTestId("notification-table")).toBeVisible();

        await page.getByTestId("communication-tabs").getByRole("link", { name: "E-mails" }).click();
        const help = page.getByTestId("help").first();
        await help.locator("summary").click();
        await expect(help.locator("p")).toBeVisible();

        // Changer le code PIN sans JavaScript : la double saisie suffit, pas de fenêtre ; deux codes différents refusés
        await page.goto(`/${admin.adminPath}/communication/validation-a-distance`, { waitUntil: "load" });
        await page.getByLabel("Nouveau code PIN").fill("123456");
        await page.getByLabel("Confirmer le code PIN").fill("654321");
        await page.getByRole("button", { name: "Enregistrer" }).click();
        await expect(page.locator("main")).toContainText("Les deux codes PIN ne correspondent pas.");
    });
});
