/*
 * Accessibilité (lot 8) : audit axe-core (règles WCAG 2.0 / 2.1, niveaux A et AA) de toutes les pages
 * publiques, en clair et en sombre à 375 px (projets de playwright.config.js). Aucune violation tolérée.
 *
 * Exclus de l'audit : le widget Cloudflare Turnstile (iframe d'un tiers) et la barre de débogage de
 * Symfony (présente seulement en développement).
 *
 * Chaque page écrit un résumé dans captures-lot8/axe/ (rapport du lot, hors dépôt).
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";

const PAGES = [
    "/",
    "/cv",
    "/projets",
    "/calculatrice",
    "/calculatrice/electricite",
    "/calculatrice/electricite/loi-ohm",
    "/calculatrice/thermodynamique/chambre-froide",
    "/contact",
    "/espace-recruteur",
    "/espace-recruteur/lien-invalide",
    "/mentions-legales",
    "/confidentialite",
    "/nouveautes",
];

const REPORT_DIR = path.resolve("captures-lot8", "axe");

for (const url of PAGES) {
    test(`accessibilité WCAG AA : ${url}`, async ({ page }, testInfo) => {
        const response = await page.goto(url, { waitUntil: "load" });
        expect(response?.status()).toBe(200);

        const results = await new AxeBuilder({ page })
            .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
            .exclude('[data-controller="turnstile"]')
            .exclude(".sf-toolbar")
            .analyze();

        const summary = results.violations.map((v) => ({
            id: v.id,
            impact: v.impact,
            help: v.help,
            nodes: v.nodes.map((n) => n.target.join(" ")).slice(0, 10),
        }));
        fs.mkdirSync(REPORT_DIR, { recursive: true });
        const name = `${testInfo.project.name}${url.replace(/[^a-z0-9]+/gi, "-").replace(/-$/, "") || "-accueil"}.json`;
        fs.writeFileSync(path.join(REPORT_DIR, name), JSON.stringify({ url, project: testInfo.project.name, passes: results.passes.length, violations: summary }, null, 2));

        expect(summary, JSON.stringify(summary, null, 2)).toEqual([]);
    });
}
