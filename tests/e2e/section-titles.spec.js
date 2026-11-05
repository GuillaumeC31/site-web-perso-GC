/*
 * Titres de section et pied de page dans un vrai navigateur (v2.0.2).
 *
 * - Titre « Expertise » de l'accueil (« Quatre domaines, une même exigence : que ça marche sur le terrain ») : une
 *   seule ligne à partir de l'écran d'ordinateur (lg, 1024 px et plus), plusieurs lignes permises sur tablette et
 *   téléphone, jamais de débordement horizontal ; captures à 375, 768, 1366 et 1920 px dans captures-titres-v2.0.2/
 *   (hors dépôt).
 * - Même règle pour tous les titres de section (<twig:SectionHeading>) : un titre qui tient sur une ligne dans la
 *   largeur de sa section n'est jamais coupé en deux (cause corrigée : max-w-3xl sur le titre).
 * - Pied de page à 375 px avec plusieurs réseaux (dont Bluesky et Mastodon) : passage à la ligne propre, sans
 *   débordement ; liens créés puis supprimés dans la base dédiée aux tests (E2eMode).
 *
 * Lancé une seule fois (projet mobile-375) : la largeur de chaque contrôle est fixée par le test lui-même.
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import { expect, test } from "@playwright/test";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const AUTH_DIR = path.resolve("tests/e2e/.auth");
const CAPTURE_DIR = path.resolve("captures-titres-v2.0.2");
const EXPERTISE_TITLE = "Quatre domaines, une même exigence : que ça marche sur le terrain";

/** Ouvre une session admin dédiée et renvoie le cookie et le chemin de l'admin. */
function openAdminSession(baseURL) {
    const sessions = JSON.parse(fs.readFileSync(path.join(AUTH_DIR, "sessions.json"), "utf8"));
    const id = execFileSync("php", ["bin/console", "app:e2e:private-sessions", "admin-session"], { encoding: "utf8" }).trim().split("\n").pop();

    return { cookie: { name: sessions.cookie, value: id, url: baseURL }, adminPath: sessions.adminPath };
}

/** Nombre de lignes d'un élément de texte (hauteur divisée par la hauteur de ligne). */
async function lineCount(locator) {
    return locator.evaluate((element) => {
        const style = getComputedStyle(element);
        const lineHeight = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.25;

        return Math.round(element.getBoundingClientRect().height / lineHeight);
    });
}

/** Vrai si la page déborde horizontalement. */
async function overflowsHorizontally(page) {
    return page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
}

test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== "mobile-375", "Contrôle de mise en page lancé une seule fois (largeurs fixées par le test).");
});

test("titre « Expertise » : une ligne sur ordinateur, plusieurs permises sur tablette et téléphone, sans débordement", async ({ page }) => {
    fs.mkdirSync(CAPTURE_DIR, { recursive: true });
    for (const width of [375, 768, 1024, 1366, 1920]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto("/", { waitUntil: "load" });
        const title = page.getByRole("heading", { level: 2, name: EXPERTISE_TITLE });
        await expect(title).toBeVisible();
        const lines = await lineCount(title);
        if (width >= 1024) {
            expect(lines, `${width} px : une seule ligne`).toBe(1);
        } else {
            expect(lines, `${width} px`).toBeGreaterThanOrEqual(1);
        }
        expect(await overflowsHorizontally(page), `${width} px : aucun débordement horizontal`).toBe(false);
        // Taille de police jamais inférieure à celle des autres titres de section de l'accueil
        const own = await title.evaluate((t) => parseFloat(getComputedStyle(t).fontSize));
        const largest = await page.locator("main section h2").evaluateAll((titles) => Math.max(...titles.map((t) => parseFloat(getComputedStyle(t).fontSize))));
        expect(own, `${width} px : titre « Expertise » pas plus petit que les autres titres de section`).toBeGreaterThanOrEqual(largest);
        if (width !== 1024) {
            await title.locator("xpath=ancestor::section[1]").screenshot({ path: path.join(CAPTURE_DIR, `expertise-${width}.png`) });
        }
    }
});

test("titres de section : jamais coupés s'ils tiennent sur une ligne dans leur section (1366 et 1920 px)", async ({ page }) => {
    for (const width of [1366, 1920]) {
        await page.setViewportSize({ width, height: 900 });
        for (const url of ["/", "/projets", "/calculatrice", "/contact", "/mentions-legales", "/confidentialite", "/nouveautes"]) {
            await page.goto(url, { waitUntil: "load" });
            const report = await page.locator("main h1, main h2").evaluateAll((titles) => titles.map((title) => {
                // Largeur naturelle sur une ligne, comparée à la largeur disponible dans la section
                const clone = title.cloneNode(true);
                clone.style.cssText = "position:absolute;visibility:hidden;white-space:nowrap;width:auto;max-width:none";
                title.parentElement.appendChild(clone);
                const natural = clone.getBoundingClientRect().width;
                clone.remove();
                const style = getComputedStyle(title);
                const lineHeight = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.25;
                const section = title.closest("section, main");
                const available = section.getBoundingClientRect().width;

                return { text: title.textContent.trim().slice(0, 60), lines: Math.round(title.getBoundingClientRect().height / lineHeight), fits: natural <= available * 0.9 };
            }));
            const cut = report.filter((t) => t.fits && t.lines > 1);
            expect(cut, `${url} à ${width} px : titre coupé alors qu'il tient sur une ligne`).toEqual([]);
            expect(await overflowsHorizontally(page), `${url} à ${width} px`).toBe(false);
        }
    }
});

test("pied de page à 375 px avec plusieurs réseaux : passage à la ligne propre, sans débordement", async ({ page, context, baseURL }) => {
    const admin = openAdminSession(baseURL);
    await context.addCookies([admin.cookie]);
    const stamp = Date.now().toString(36);
    const links = [
        ["bluesky", `https://bsky.app/profile/e2e-${stamp}.bsky.social`],
        ["mastodon", `https://mastodon.social/@e2e${stamp}`],
        ["github", `https://github.com/e2e-${stamp}`],
    ];

    // Ajout de trois liens, puis affichage d'un clic
    for (const [network, url] of links) {
        await page.goto(`/${admin.adminPath}/communication/reseaux-sociaux`, { waitUntil: "load" });
        await page.getByRole("link", { name: "Ajouter un lien" }).click();
        await page.getByLabel("Réseau").selectOption(network);
        await page.getByLabel("Adresse").fill(url);
        await page.getByRole("button", { name: "Enregistrer" }).click();
        const item = page.locator("[data-testid=social-links] li", { hasText: url });
        await expect(item).toContainText("Masqué");
        await item.getByRole("button", { name: /^Afficher/ }).click();
        await expect(item).toContainText("Visible");
    }

    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto("/mentions-legales", { waitUntil: "load" });
    for (const [, url] of links) {
        await expect(page.locator(`footer a[href="${url}"] svg`)).toBeVisible();
    }
    expect(await overflowsHorizontally(page)).toBe(false);
    // Chaque lien du pied de page tient dans la largeur de l'écran, sans être coupé
    const boxes = await page.locator("footer nav a").evaluateAll((anchors) => anchors.map((a) => {
        const box = a.getBoundingClientRect();

        return { left: box.left, right: box.right, lines: Math.round(box.height / (parseFloat(getComputedStyle(a).lineHeight) || 20)) };
    }));
    for (const box of boxes) {
        expect(box.left).toBeGreaterThanOrEqual(0);
        expect(box.right).toBeLessThanOrEqual(375);
        expect(box.lines, "un lien n'est jamais coupé sur deux lignes").toBe(1);
    }
    fs.mkdirSync(CAPTURE_DIR, { recursive: true });
    await page.locator("footer").screenshot({ path: path.join(CAPTURE_DIR, "pied-de-page-375-reseaux.png") });

    // Nettoyage
    for (const [, url] of links) {
        await page.goto(`/${admin.adminPath}/communication/reseaux-sociaux`, { waitUntil: "load" });
        const item = page.locator("[data-testid=social-links] li", { hasText: url });
        await item.getByRole("button", { name: /^Supprimer/ }).click();
        await page.getByRole("alertdialog").getByRole("button", { name: "Supprimer" }).click();
        await expect(page.locator("[data-testid=social-links] li", { hasText: url })).toHaveCount(0);
    }
});
