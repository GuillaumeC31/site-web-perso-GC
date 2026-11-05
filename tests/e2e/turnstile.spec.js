/*
 * Widgets Cloudflare Turnstile de l'Espace recruteur (deux formulaires sur la même page), avec le VRAI
 * widget et les clés de test « toujours valide » de Cloudflare (connexion à Internet nécessaire) :
 * - chaque widget se valide quand on commence à remplir SON formulaire (un jeton par formulaire) ;
 *   le second ne reste plus bloqué sur « Vérification… » (anomalie du 2026-10-26) ;
 * - retour arrière et navigation Turbo : un seul widget par formulaire, qui se valide encore ;
 * - un envoi immédiat attend le jeton puis part tout seul ;
 * - aucune erreur dans la console (CSP comprise, y compris après les navigations Turbo).
 *
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import { expect, test } from "@playwright/test";

/** Widget d'un formulaire de la page. */
const widget = (page, form) => page.locator(`form[name="${form}"] [data-controller="turnstile"]`);

/** Erreurs de la console et de la page, relevées pendant le test. */
function watchConsole(page) {
    const errors = [];
    page.on("console", (message) => {
        if (message.type() === "error") {
            errors.push(message.text());
        }
    });
    page.on("pageerror", (error) => errors.push(error.message));

    return errors;
}

test("les deux widgets se valident, chacun pour son formulaire", async ({ page }) => {
    const errors = watchConsole(page);
    await page.goto("/espace-recruteur", { waitUntil: "load" });
    await expect(widget(page, "recruiter_request")).toHaveAttribute("data-turnstile-state", "idle");
    await expect(widget(page, "login_link")).toHaveAttribute("data-turnstile-state", "idle");

    // Second formulaire d'abord (celui qui restait bloqué)
    await page.locator('form[name="login_link"]').getByLabel("E-mail de votre accès").fill("widget@example.test");
    await expect(widget(page, "login_link")).toHaveAttribute("data-turnstile-state", "verified", { timeout: 20_000 });
    await page.locator('form[name="recruiter_request"]').getByLabel("E-mail professionnel").fill("widget@example.test");
    await expect(widget(page, "recruiter_request")).toHaveAttribute("data-turnstile-state", "verified", { timeout: 20_000 });

    // Un jeton par formulaire, dans son formulaire
    for (const form of ["recruiter_request", "login_link"]) {
        const tokens = page.locator(`form[name="${form}"] input[name="cf-turnstile-response"]`);
        await expect(tokens).toHaveCount(1);
        await expect(tokens).not.toHaveValue("");
    }
    expect(errors).toEqual([]);
});

test("navigation Turbo et retour arrière : un seul widget par formulaire, toujours valide", async ({ page }) => {
    const errors = watchConsole(page);
    await page.goto("/", { waitUntil: "load" });
    await page.locator('a[href="/espace-recruteur"]:visible').first().click();
    await page.waitForURL(/\/espace-recruteur$/);
    // Lien du pied de page (visible aussi à 375 px, où le menu est replié)
    await page.locator('a[href="/mentions-legales"]:visible').first().click();
    await page.waitForURL(/\/mentions-legales$/);
    await page.goBack();
    await page.waitForURL(/\/espace-recruteur$/);

    for (const form of ["recruiter_request", "login_link"]) {
        await expect(page.locator(`form[name="${form}"] [data-controller="turnstile"]`)).toHaveCount(1);
    }
    await page.locator('form[name="login_link"]').getByLabel("E-mail de votre accès").fill("retour@example.test");
    await expect(widget(page, "login_link")).toHaveAttribute("data-turnstile-state", "verified", { timeout: 20_000 });
    await expect(page.locator('form[name="login_link"] input[name="cf-turnstile-response"]')).toHaveCount(1);
    expect(errors).toEqual([]);
});

test("un envoi immédiat attend le jeton puis part", async ({ page }) => {
    await page.goto("/espace-recruteur", { waitUntil: "load" });
    const form = page.locator('form[name="login_link"]');
    await form.getByLabel("E-mail de votre accès").fill(`immediat-${Date.now()}@example.test`);
    // Clic tout de suite, sans attendre la vérification
    await form.getByRole("button", { name: "Recevoir un lien de connexion" }).click();
    // Confirmation en toast (en haut à droite)
    await expect(page.locator(".swal2-toast")).toContainText("Si un accès est associé à cette adresse", { timeout: 25_000 });
});
