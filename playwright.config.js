/*
 * Configuration Playwright : tests de bout en bout dans un vrai navigateur (tests/e2e/).
 *
 * Lancement : npm run test:e2e (serveur local démarré : PHP_FCGI_MAX_REQUESTS=10000 symfony serve -d --port=8000 ;
 * sans cette variable, php-cgi se recycle toutes les quelques centaines de requêtes et la requête en
 * cours reçoit une erreur 502 : voir README, « Lancer le site en développement »)
 * - Adresse testée : E2E_BASE_URL (par défaut https://localhost:8000, certificat local accepté).
 * - Navigateur : Microsoft Edge installé sur le poste (aucun téléchargement) ; en CI,
 *   E2E_BROWSER_CHANNEL=chromium utilise le Chromium de Playwright.
 * Vitest (npm test) ne lit que assets/**\/*.test.js : les deux suites restent séparées.
 */
import { defineConfig } from "@playwright/test";

// Toute la suite travaille sur la base dédiée « <base>_e2e » (décision du 2026-10-29) : les commandes lancées par les
// tests héritent de E2E_DATABASE=1 ; les navigateurs de test y sont envoyés d'après leur agent (Service\Dev\E2eMode).
// La base de développement n'est jamais modifiée ; seul le contrôle final des traces la lit (global-teardown.js).
process.env.E2E_DATABASE = "1";

export default defineConfig({
    testDir: "tests/e2e",
    // Comptes de test des pages privées (audit d'accessibilité, lot 8) et du parcours recruteur (IA simulée,
    // coordonnées fictives), créés puis supprimés
    globalSetup: "./tests/e2e/support/global-setup.js",
    globalTeardown: "./tests/e2e/support/global-teardown.js",
    // Serveur local en mode debug (un seul php-cgi, une requête PHP à la fois) : 2 navigateurs au plus en local
    timeout: 60_000,
    // Attente des assertions (v2.0.2) : 15 s au lieu de 5. Avec un seul php-cgi pour deux navigateurs, un aller-retour
    // au serveur (envoi d'un formulaire, réponse de l'assistant) dépassait parfois 5 s : tests qui passaient seuls et
    // échouaient dans la suite complète. Les assertions restent explicites (attente d'un élément ou d'un texte précis).
    expect: { timeout: 15_000 },
    workers: 2,
    fullyParallel: true,
    reporter: [["list"]],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? "https://localhost:8000",
        ignoreHTTPSErrors: true,
        channel: process.env.E2E_BROWSER_CHANNEL === "chromium" ? undefined : (process.env.E2E_BROWSER_CHANNEL ?? "msedge"),
    },
    projects: [
        // Petit écran de référence (iPhone SE / mini) : 375 px de large
        { name: "mobile-375", testIgnore: /recruiter-journey/, use: { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 } },
        // Page de maintenance : paramètres partagés, un seul projet (clair et sombre testés dans le fichier)
        { name: "mobile-375-sombre", testIgnore: /recruiter-journey|maintenance/, use: { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2, colorScheme: "dark" } },
        // Parcours recruteur de bout en bout (Mailpit), une fois sur ordinateur et une fois sur mobile
        { name: "parcours-ordinateur", testMatch: /recruiter-journey/, use: { viewport: { width: 1280, height: 800 } } },
        { name: "parcours-mobile", testMatch: /recruiter-journey/, use: { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 } },
    ],
});
