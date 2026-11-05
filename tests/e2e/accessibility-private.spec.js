/*
 * Accessibilité des pages privées (lot 8) : audit axe-core (WCAG 2.0 / 2.1, niveaux A et AA) de l'espace
 * recruteur connecté et des pages principales de l'admin, en clair et en sombre à 375 px. Comptes de test
 * créés par tests/e2e/support/global-setup.js (admin sans clé YubiKey, recruteur de test), supprimés ensuite.
 *
 * Chaque page écrit un résumé dans captures-lot8/axe/ (rapport du lot, hors dépôt).
 */
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";

const AUTH_DIR = path.resolve("tests/e2e/.auth");
const REPORT_DIR = path.resolve("captures-lot8", "axe");

const ADMIN_PAGES = ["", "/parametres", "/assistant-ia", "/recruteurs", "/messages", "/experiences", "/projets", "/profil", "/calculatrice/outils", "/securite/journal", "/journal-des-versions", "/cerveau-ia", "/cerveau-ia/nouvelle", "/cerveau-ia/questions-sans-reponse", "/communication", "/communication/e-mails", "/communication/e-mails/contenu/approval", "/communication/notifications", "/communication/discord", "/communication/telegram", "/communication/validation-a-distance", "/messages?filtre=archives", "/communication/reseaux-sociaux", "/communication/reseaux-sociaux/nouveau"];

/** Lance l'audit, écrit le résumé et exige zéro violation. */
async function audit(page, testInfo, label) {
    const results = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
        .exclude(".sf-toolbar")
        // Aperçu d'un e-mail (Communication) : cadre isolé sans script, contenu de l'e-mail et non page du site
        .exclude('[data-testid="preview-html"]')
        .analyze();
    const summary = results.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.map((n) => n.target.join(" ")).slice(0, 10) }));
    fs.mkdirSync(REPORT_DIR, { recursive: true });
    fs.writeFileSync(path.join(REPORT_DIR, `${testInfo.project.name}-prive-${label}.json`), JSON.stringify({ page: label, project: testInfo.project.name, passes: results.passes.length, violations: summary }, null, 2));
    expect(summary, JSON.stringify(summary, null, 2)).toEqual([]);
}

test.describe("espace recruteur connecté", () => {
    test.use({ storageState: path.join(AUTH_DIR, "recruiter.json") });

    test("accessibilité WCAG AA : /espace-recruteur/accueil", async ({ page }, testInfo) => {
        const response = await page.goto("/espace-recruteur/accueil", { waitUntil: "load" });
        expect(response?.status()).toBe(200);
        await expect(page.locator("#assistant-section")).toBeVisible();
        await audit(page, testInfo, "espace-recruteur-accueil");
    });

    test("accessibilité WCAG AA : /espace-recruteur/question", async ({ page }, testInfo) => {
        const response = await page.goto("/espace-recruteur/question", { waitUntil: "load" });
        expect(response?.status()).toBe(200);
        await expect(page.getByRole("button", { name: "Envoyer ma question" })).toBeVisible();
        await audit(page, testInfo, "espace-recruteur-question");
    });

    test("accessibilité WCAG AA : /espace-recruteur/mes-donnees", async ({ page }, testInfo) => {
        const response = await page.goto("/espace-recruteur/mes-donnees", { waitUntil: "load" });
        expect(response?.status()).toBe(200);
        await expect(page.getByRole("button", { name: "Supprimer définitivement mes données" })).toBeVisible();
        await audit(page, testInfo, "espace-recruteur-mes-donnees");
    });
});

test.describe("administration", () => {
    for (const suffix of ADMIN_PAGES) {
        test(`accessibilité WCAG AA : admin${suffix || " (tableau de bord)"}`, async ({ page, context, baseURL }, testInfo) => {
            const sessions = JSON.parse(fs.readFileSync(path.join(AUTH_DIR, "sessions.json"), "utf8"));
            await context.addCookies([{ name: sessions.cookie, value: sessions.adminSession, url: baseURL }]);
            const response = await page.goto(`/${sessions.adminPath}${suffix}`, { waitUntil: "load" });
            expect(response?.status()).toBe(200);
            await audit(page, testInfo, `admin${suffix.replace(/[^a-z0-9]+/gi, "-") || "-tableau-de-bord"}`);
        });
    }
});
