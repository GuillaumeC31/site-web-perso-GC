/*
 * Fenêtres et messages (SweetAlert2, contrôleur « notify ») dans un vrai navigateur, en clair et en sombre
 * à 375 px :
 * - toast : en haut à droite, annoncé (role status), ne prend jamais le focus, se ferme (bouton ou 4 s),
 *   n'est pas rejoué au retour arrière ; sans barre de temps si l'utilisateur demande moins d'animations ;
 * - confirmation : role alertdialog, focus sur « Annuler » (action importante), focus piégé (Tab), Échap
 *   annule et rend le focus au bouton d'origine ; aucune violation axe pendant l'affichage ;
 * - Turbo : aucune fenêtre fantôme après une navigation et un retour arrière ;
 * - sans JavaScript : message affiché dans la page, page de confirmation du serveur.
 * La confirmation menée jusqu'au bout et la bienvenue du recruteur (une seule fois) sont dans
 * recruiter-journey.spec.js (actions réelles sur des comptes de test).
 *
 * Session admin propre à chaque projet (app:e2e:private-sessions admin-session) : les messages flash ne sont
 * pas lus par les tests lancés en parallèle.
 */
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const AUTH_DIR = path.resolve("tests/e2e/.auth");

/** Ouvre une session admin dédiée et renvoie le cookie à poser. */
function openAdminSession(baseURL) {
    const sessions = JSON.parse(fs.readFileSync(path.join(AUTH_DIR, "sessions.json"), "utf8"));
    const id = execFileSync("php", ["bin/console", "app:e2e:private-sessions", "admin-session"], { encoding: "utf8" }).trim().split("\n").pop();

    return { cookie: { name: sessions.cookie, value: id, url: baseURL }, adminPath: sessions.adminPath };
}

/** Lance l'audit axe (WCAG A et AA) une fois les animations finies, et exige zéro violation. */
async function expectNoAxeViolation(page) {
    // La barre de temps d'un toast s'anime pendant 4 s : elle n'est pas attendue
    await page.waitForFunction(() =>
        document.getAnimations().every((animation) => animation.playState !== "running" || animation.effect?.target?.classList?.contains("swal2-timer-progress-bar")),
    );
    const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).exclude(".sf-toolbar").analyze();
    const summary = results.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target.join(" ")).slice(0, 5) }));
    expect(summary, JSON.stringify(summary, null, 2)).toEqual([]);
}

/**
 * Lance « Actualiser » (action donnée) jusqu'à obtenir le toast : si un indicateur a été changé entre-temps
 * par un autre test lancé en parallèle (demande, refus de sécurité…), le récapitulatif « Depuis votre
 * dernière visite » s'ouvre à juste titre à la place du toast ; il est fermé et l'action relancée (3 essais).
 */
async function refreshUntilToast(page, refresh) {
    const toast = page.locator(".swal2-toast");
    const recap = page.getByRole("button", { name: "Voir le tableau de bord" });
    for (let attempt = 1; attempt <= 3; attempt++) {
        await refresh();
        await expect(toast.or(recap)).toBeVisible();
        if (await toast.isVisible()) {
            return toast;
        }
        await recap.click();
    }
    throw new Error("Toast jamais affiché : récapitulatif ouvert à chaque essai.");
}

test.describe.configure({ mode: "serial" });

let admin;
test.beforeAll(({ baseURL }) => {
    admin = openAdminSession(baseURL);
});

test.beforeEach(async ({ context }) => {
    await context.addCookies([admin.cookie]);
});

test("toast : en haut à droite, annoncé sans prendre le focus, fermable, sans rejeu au retour", async ({ page }) => {
    const toast = await refreshUntilToast(page, async () => {
        await page.goto(`/${admin.adminPath}`, { waitUntil: "load" });
        const recap = page.getByRole("button", { name: "Voir le tableau de bord" });
        if (await recap.isVisible().catch(() => false)) {
            await recap.click();
        }
        const refresh = page.getByRole("link", { name: "Actualiser" });
        await refresh.focus();
        await refresh.press("Enter");
        await page.waitForURL(new RegExp(`/${admin.adminPath}$`));
    });
    // Minuterie arrêtée dès l'apparition (v2.0.2) : le toast se ferme seul après 4 s, et les contrôles qui suivent
    // pouvaient durer plus longtemps sur un poste chargé (toast disparu avant sa vérification). La fermeture
    // automatique est vérifiée par le test suivant.
    await page.evaluate(() => window.gcSwal.stopTimer());
    await expect(toast).toHaveAttribute("role", "status");
    await expect(toast).toContainText("Indicateurs actualisés.");
    // Coin en haut à droite
    const box = await toast.boundingBox();
    const viewport = page.viewportSize();
    expect(box.y).toBeLessThan(80);
    expect(box.x + box.width).toBeGreaterThan(viewport.width - 40);
    // Jamais de focus dans le toast ; le message n'est plus dans la page (pas de double affichage)
    expect(await page.evaluate(() => Boolean(document.activeElement?.closest(".swal2-container")))).toBe(false);
    await expect(page.locator('[data-notify-target="toast"]')).toHaveCount(0);
    // Minuterie suspendue le temps de l'audit (le toast resterait sinon 4 s seulement)
    await page.evaluate(() => window.gcSwal.stopTimer());
    await expectNoAxeViolation(page);

    // Fermeture par le bouton
    await toast.getByRole("button", { name: "Fermer le message" }).click();
    await expect(toast).toHaveCount(0);

    // Navigation Turbo puis retour arrière : ni toast rejoué, ni fenêtre fantôme
    await page.getByRole("link", { name: "Voir les messages" }).click();
    await page.waitForURL(/\/messages$/);
    await page.goBack();
    await page.waitForURL(new RegExp(`/${admin.adminPath}$`));
    await page.waitForTimeout(500);
    await expect(page.locator(".swal2-container")).toHaveCount(0);
    await expect(page.getByText("Indicateurs actualisés.")).toHaveCount(0);
});

test("toast : disparaît seul après 4 s ; moins d'animations : sans barre de temps", async ({ browser, baseURL }) => {
    const context = await browser.newContext({ ignoreHTTPSErrors: true, reducedMotion: "reduce", viewport: { width: 375, height: 812 } });
    await context.addCookies([admin.cookie]);
    const page = await context.newPage();
    const toast = await refreshUntilToast(page, () => page.goto(`${baseURL}/${admin.adminPath}?actualiser=1`, { waitUntil: "load" }));
    await expect(page.locator(".swal2-timer-progress-bar")).toBeHidden();
    await expect(toast).toHaveCount(0, { timeout: 6000 });
    await context.close();
});

test("confirmation : alertdialog, focus sur « Annuler », piégé au clavier, Échap annule et rend le focus", async ({ page }) => {
    await page.goto(`/${admin.adminPath}/parametres`, { waitUntil: "load" });
    const maintenance = page.getByLabel("Site en maintenance");
    await expect(maintenance).not.toBeChecked();
    await maintenance.check();
    const save = page.getByRole("button", { name: "Enregistrer" });
    await save.focus();
    await save.press("Enter");

    const dialog = page.getByRole("alertdialog");
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute("aria-modal", "true");
    await expect(dialog).toContainText("Mettre le site en maintenance ?");
    await expect(dialog.getByRole("button", { name: "Annuler" })).toBeFocused();
    // Tab reste dans la fenêtre
    for (let i = 0; i < 4; i++) {
        await page.keyboard.press("Tab");
        expect(await page.evaluate(() => Boolean(document.activeElement?.closest(".swal2-popup")))).toBe(true);
    }
    await expectNoAxeViolation(page);

    await page.keyboard.press("Escape");
    await expect(dialog).toHaveCount(0);
    await expect(save).toBeFocused();
    // Rien n'a été enregistré
    await page.reload({ waitUntil: "load" });
    await expect(page.getByLabel("Site en maintenance")).not.toBeChecked();

    // Page de confirmation du serveur (repli sans JavaScript), auditée ici : axe a besoin de scripts
    await page.locator('form[name="site_settings"]').evaluate((form) => form.removeAttribute("data-controller"));
    await page.getByLabel("Site en maintenance").check();
    await page.getByRole("button", { name: "Enregistrer" }).click();
    await expect(page.getByTestId("confirmation-page")).toBeVisible();
    await expectNoAxeViolation(page);
    await page.getByTestId("confirmation-page").getByRole("link", { name: "Annuler" }).click();
    await page.waitForURL(/\/parametres$/);
    await expect(page.getByLabel("Site en maintenance")).not.toBeChecked();
});

test("sans JavaScript : message dans la page, page de confirmation du serveur", async ({ browser, baseURL }) => {
    const context = await browser.newContext({ ignoreHTTPSErrors: true, javaScriptEnabled: false, viewport: { width: 375, height: 812 } });
    // Session neuve : Symfony refuse le contrôle CSRF sans JavaScript dans une session qui a déjà utilisé
    // celui avec JavaScript (double soumission), ce qui n'arrive pas à un vrai visiteur sans JavaScript
    await context.addCookies([openAdminSession(baseURL).cookie]);
    const page = await context.newPage();

    await page.goto(`${baseURL}/${admin.adminPath}?actualiser=1`, { waitUntil: "load" });
    await expect(page.locator('[data-notify-target="toast"]')).toBeVisible();
    await expect(page.locator('[data-notify-target="toast"]')).toContainText("Indicateurs actualisés.");

    await page.goto(`${baseURL}/${admin.adminPath}/parametres`, { waitUntil: "load" });
    await page.getByLabel("Site en maintenance").check();
    await page.getByRole("button", { name: "Enregistrer" }).click();
    const confirmation = page.getByTestId("confirmation-page");
    await expect(confirmation).toBeVisible();
    await expect(confirmation).toHaveAttribute("role", "alertdialog");
    await expect(confirmation.getByRole("heading", { level: 1 })).toHaveText("Mettre le site en maintenance ?");
    await confirmation.getByRole("link", { name: "Annuler" }).click();
    await page.waitForURL(/\/parametres$/);
    await expect(page.getByLabel("Site en maintenance")).not.toBeChecked();
    await context.close();
});
