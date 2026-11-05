/*
 * Page Messages de l'admin dans un vrai navigateur (clair et sombre selon le projet) : filtres avec leur nombre,
 * type, recherche, actions groupées (suppression confirmée par une fenêtre ; sans JavaScript, par la
 * page de confirmation du serveur), « Marquer comme répondu », accessibilité (axe-core).
 *
 * Données : une série de messages par projet (app:e2e:messages prepare --set=<projet>, adresses en
 * @e2e-messages.example.test, entreprise « Essai E2E <projet> fin »), retirée en fin de suite (repère précis).
 * La mémorisation des filtres est testée par PHPUnit (ContactMessageFiltersTest) : le compte admin de test est
 * commun aux projets lancés en parallèle, sa préférence ne peut pas être vérifiée ici sans conflit.
 * Captures (ordinateur et mobile, clair et sombre) dans captures-messages/ (hors dépôt).
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const AUTH_DIR = path.resolve("tests/e2e/.auth");
const CAPTURE_DIR = path.resolve("captures-messages");

/** Ouvre une session admin dédiée et renvoie le cookie et le chemin de l'admin. */
function openAdminSession(baseURL) {
    const sessions = JSON.parse(fs.readFileSync(path.join(AUTH_DIR, "sessions.json"), "utf8"));
    const id = execFileSync("php", ["bin/console", "app:e2e:private-sessions", "admin-session"], { encoding: "utf8" }).trim().split("\n").pop();

    return { cookie: { name: sessions.cookie, value: id, url: baseURL }, adminPath: sessions.adminPath };
}

/** Crée la série de messages du projet. */
function prepareMessages(set) {
    execFileSync("php", ["bin/console", "app:e2e:messages", "prepare", `--set=${set}`], { encoding: "utf8" });
}

/** Audit axe-core WCAG A et AA une fois les animations finies (fenêtre, toast), zéro violation. */
async function expectNoViolation(page) {
    await page.waitForFunction(() =>
        document.getAnimations().every((animation) => animation.playState !== "running" || animation.effect?.target?.classList?.contains("swal2-timer-progress-bar")),
    );
    const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).exclude(".sf-toolbar").analyze();
    const summary = results.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target.join(" ")).slice(0, 5) }));
    expect(summary, JSON.stringify(summary, null, 2)).toEqual([]);
}

/** Nombre affiché sur un filtre. */
async function countOf(page, filter) {
    return Number(await page.locator(`[data-testid="message-filters"] a[data-filter="${filter}"] [data-testid="filter-count"]`).innerText());
}

test.describe.configure({ mode: "serial" });

test.describe("messages", () => {
    let admin;
    let set;
    let list;

    // Données créées UNE fois par projet (tests en série) : le tableau de bord change peu pendant les autres suites
    test.beforeAll(async ({}, testInfo) => {
        set = testInfo.project.name;
        prepareMessages(set);
    });

    test.beforeEach(async ({ context, baseURL }) => {
        admin = openAdminSession(baseURL);
        await context.addCookies([admin.cookie]);
        list = (query = "") => `/${admin.adminPath}/messages?filtre=tous&type=tous&tri=recents&q=${encodeURIComponent(`Essai E2E ${set} fin`)}${query}`;
    });

    test("filtres, nombres, type et recherche", async ({ page }) => {
        await page.goto(list(), { waitUntil: "load" });
        expect(await countOf(page, "tous")).toBe(6);
        expect(await countOf(page, "non-lus")).toBe(3);
        expect(await countOf(page, "lus")).toBe(3);
        expect(await countOf(page, "repondus")).toBe(1);
        expect(await countOf(page, "archives")).toBe(1);
        await expect(page.locator("[data-message]")).toHaveCount(6);

        await page.getByLabel("Type").selectOption("question");
        await page.getByRole("button", { name: "Appliquer" }).click();
        await expect(page.locator("[data-message]")).toHaveCount(2);
        await expect(page.locator("[data-message]").first()).toContainText("Question d'un recruteur");

        await page.locator('[data-testid="message-filters"] a[data-filter="archives"]').click();
        await expect(page.locator("[data-message]")).toHaveCount(0);
        await page.getByLabel("Type").selectOption("tous");
        await page.getByRole("button", { name: "Appliquer" }).click();
        await expect(page.locator("[data-message]")).toHaveCount(1);
        await expect(page.locator("[data-message]")).toContainText("Gaspard Essai");
        await expectNoViolation(page);
    });

    test("marquer comme répondu depuis la fiche", async ({ page }) => {
        await page.goto(list(), { waitUntil: "load" });
        await page.getByRole("link", { name: "Cyril Essai" }).click();
        await expect(page.getByTestId("replied-at")).toHaveText("Pas encore répondu");
        await page.getByRole("button", { name: "Marquer comme répondu" }).click();
        await expect(page.getByTestId("replied-at")).not.toHaveText("Pas encore répondu");
        await expect(page.getByRole("button", { name: "Marquer comme non répondu" })).toBeVisible();
        await expectNoViolation(page);
    });

    test("captures de la page Messages", async ({ browser, baseURL }, testInfo) => {
        const scheme = testInfo.project.use.colorScheme === "dark" ? "sombre" : "clair";
        for (const [device, options] of [
            ["mobile", { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }],
            ["ordinateur", { viewport: { width: 1280, height: 800 } }],
        ]) {
            const context = await browser.newContext({ ...options, colorScheme: testInfo.project.use.colorScheme ?? "light", ignoreHTTPSErrors: true, baseURL });
            await context.addCookies([admin.cookie]);
            const page = await context.newPage();
            await page.goto(list(), { waitUntil: "load" });
            await page.screenshot({ path: path.join(CAPTURE_DIR, `liste-${device}-${scheme}.png`), fullPage: true });
            await page.getByRole("link", { name: "Daphné Essai" }).click();
            await page.waitForLoadState("load");
            await page.screenshot({ path: path.join(CAPTURE_DIR, `fiche-${device}-${scheme}.png`), fullPage: true });
            await context.close();
        }
    });

    test("actions groupées : marquer lus, puis supprimer avec confirmation", async ({ page }) => {
        await page.goto(list(), { waitUntil: "load" });
        await page.getByLabel("Sélectionner le message de Ana Essai").check();
        await page.getByLabel("Sélectionner le message de Basile Essai").check();
        await page.getByRole("button", { name: "Marquer lus" }).click();
        await expect(page.locator(".swal2-toast")).toContainText("2 messages marqués comme lus.");
        // Cyril est déjà lu (marqué répondu depuis sa fiche, test précédent)
        expect(await countOf(page, "non-lus")).toBe(0);

        // « Tout sélectionner » (avec JavaScript), puis suppression : fenêtre de confirmation, Annuler ne supprime rien
        await page.getByLabel("Tout sélectionner").check();
        await page.getByRole("button", { name: "Supprimer" }).click();
        const dialog = page.getByRole("alertdialog");
        await expect(dialog).toContainText("Supprimer les messages sélectionnés ?");
        await expectNoViolation(page);
        await dialog.getByRole("button", { name: "Annuler" }).click();
        await expect(page.locator("[data-message]")).toHaveCount(6);

        await page.getByRole("button", { name: "Supprimer" }).click();
        await page.getByRole("alertdialog").getByRole("button", { name: "Supprimer" }).click();
        await expect(page.locator(".swal2-toast")).toContainText("6 messages supprimés.");
        await expect(page.locator("[data-message]")).toHaveCount(0);
        expect(await countOf(page, "archives")).toBe(1);
    });
});

test.describe("messages sans JavaScript", () => {
    test.use({ javaScriptEnabled: false });

    test("filtres et suppression groupée par la page de confirmation", async ({ page, context, baseURL }, testInfo) => {
        const set = `${testInfo.project.name}-sans-js`;
        prepareMessages(set);
        const admin = openAdminSession(baseURL);
        await context.addCookies([admin.cookie]);
        await page.goto(`/${admin.adminPath}/messages?filtre=non-lus&type=tous&tri=recents&q=${encodeURIComponent(`Essai E2E ${set} fin`)}`, { waitUntil: "load" });
        await expect(page.locator("[data-message]")).toHaveCount(3);
        // Sans JavaScript, pas de case « Tout sélectionner » : chaque message se coche
        await expect(page.getByLabel("Tout sélectionner")).toBeHidden();
        await page.getByLabel("Sélectionner le message de Ana Essai").check();
        await page.getByLabel("Sélectionner le message de Cyril Essai").check();
        await page.getByRole("button", { name: "Supprimer" }).click();
        await expect(page.getByTestId("confirmation-page")).toContainText("Supprimer les 2 messages sélectionnés ?");
        await page.getByTestId("confirmation-page").getByRole("button", { name: "Supprimer" }).click();
        await expect(page.locator("main")).toContainText("2 messages supprimés.");
        await expect(page.locator("[data-message]")).toHaveCount(1);
        await expect(page.locator("[data-message]")).toContainText("Basile Essai");
    });
});
