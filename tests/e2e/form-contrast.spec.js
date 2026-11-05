/*
 * Test de bout en bout : le texte des champs de formulaire reste lisible (contraste ≥ 4,5:1,
 * WCAG AA), en mode clair comme en mode sombre, avec ou sans remplissage automatique.
 *
 * Trois contrôles :
 * 1. champs réels de la connexion admin, remplis (adresse lue dans E2E_ADMIN_PATH, jamais
 *    écrite ici ; contrôle ignoré si la variable manque) ;
 * 2. champs témoins input, select et textarea de classe .form-input (classe donnée à tous les
 *    champs par templates/form/tailwind_theme.html.twig : profil admin, futurs formulaires publics) ;
 * 3. règles :-webkit-autofill de la feuille de style : le navigateur ne laisse pas déclencher le
 *    vrai remplissage automatique, on applique donc les déclarations de ces règles à un champ
 *    témoin et on mesure le contraste obtenu (fond imposé recouvert, texte forcé).
 *
 * Lancement : npm run test:e2e (voir playwright.config.js : projets clair et sombre à 375 px).
 */
import { expect, test } from "@playwright/test";

/** Contraste minimal du texte (WCAG AA, texte normal). */
const MIN_CONTRAST = 4.5;

/**
 * Fonctions de mesure exécutées dans la page : conversion de toute couleur CSS (oklch, lab…)
 * en sRGB par un canevas, puis calcul du contraste WCAG.
 */
const MEASURE_HELPERS = `
    window.__toRgba = (color) => {
        const canvas = document.createElement("canvas");
        canvas.width = canvas.height = 1;
        const context = canvas.getContext("2d", { willReadFrequently: true });
        context.clearRect(0, 0, 1, 1);
        context.fillStyle = "#000";
        context.fillStyle = color;
        context.fillRect(0, 0, 1, 1);
        return Array.from(context.getImageData(0, 0, 1, 1).data);
    };
    window.__contrast = (foreground, background) => {
        const luminance = ([r, g, b]) => {
            const [R, G, B] = [r, g, b].map((v) => {
                const c = v / 255;
                return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
            });
            return 0.2126 * R + 0.7152 * G + 0.0722 * B;
        };
        const [l1, l2] = [luminance(window.__toRgba(foreground)), luminance(window.__toRgba(background))].sort((a, b) => b - a);
        return (l1 + 0.05) / (l2 + 0.05);
    };
    window.__shadowColor = (boxShadow) => {
        const match = /^(?:[a-z-]+\\([^)]*\\)|#[0-9a-f]+|[a-z]+)/i.exec(boxShadow.trim());
        return match ? match[0] : "transparent";
    };
`;

/**
 * Mesure le contraste texte / fond d'un champ (fond opaque exigé).
 *
 * @param {import('@playwright/test').Locator} field champ à mesurer
 * @returns {Promise<{contrast: number, backgroundAlpha: number, color: string, background: string}>}
 */
async function measureField(field) {
    return field.evaluate((element) => {
        const style = getComputedStyle(element);
        const color = style.webkitTextFillColor || style.color;
        const background = style.backgroundColor;

        return { contrast: window.__contrast(color, background), backgroundAlpha: window.__toRgba(background)[3], color, background };
    });
}

test.beforeEach(async ({ page }) => {
    await page.addInitScript(MEASURE_HELPERS);
});

test("champs de la connexion admin lisibles une fois remplis", async ({ page }) => {
    const adminPath = process.env.E2E_ADMIN_PATH;
    test.skip(!adminPath, "E2E_ADMIN_PATH absent : connexion admin non vérifiée.");

    await page.goto(`/${adminPath}/connexion`, { waitUntil: "load" });
    await page.fill("#username", "utilisateur.test");
    await page.fill("#password", "mot-de-passe-de-test");

    for (const selector of ["#username", "#password"]) {
        const result = await measureField(page.locator(selector));
        expect(result.backgroundAlpha, `${selector} : fond transparent (${result.background})`).toBe(255);
        expect(result.contrast, `${selector} : texte ${result.color} sur ${result.background}`).toBeGreaterThanOrEqual(MIN_CONTRAST);
    }
});

test("champs input, select et textarea (.form-input) lisibles", async ({ page }) => {
    await page.goto("/", { waitUntil: "load" });
    await page.evaluate(() => {
        const form = document.createElement("form");
        form.id = "e2e-form";
        form.innerHTML = `
            <input class="form-input" id="e2e-input" value="Texte saisi">
            <select class="form-input" id="e2e-select"><option selected>Option choisie</option></select>
            <textarea class="form-input" id="e2e-textarea">Texte long</textarea>`;
        document.querySelector("main").prepend(form);
    });

    for (const selector of ["#e2e-input", "#e2e-select", "#e2e-textarea"]) {
        const result = await measureField(page.locator(selector));
        expect(result.backgroundAlpha, `${selector} : fond transparent (${result.background})`).toBe(255);
        expect(result.contrast, `${selector} : texte ${result.color} sur ${result.background}`).toBeGreaterThanOrEqual(MIN_CONTRAST);
    }
});

test("remplissage automatique : texte forcé lisible sur le fond recouvert", async ({ page }) => {
    await page.goto("/", { waitUntil: "load" });

    const results = await page.evaluate(() => {
        const isDark = document.documentElement.classList.contains("dark");
        const rules = [];
        for (const sheet of document.styleSheets) {
            let cssRules;
            try {
                cssRules = sheet.cssRules;
            } catch {
                continue;
            }
            const walk = (list) => {
                for (const rule of list) {
                    if (rule.cssRules && !rule.selectorText) {
                        walk(rule.cssRules);
                    } else if (rule.selectorText?.includes("-webkit-autofill") && rule.style.getPropertyValue("-webkit-text-fill-color")) {
                        rules.push(rule);
                    }
                }
            };
            walk(cssRules);
        }

        // Règles du thème affiché : celles qui commencent par .dark en mode sombre, les autres en clair.
        const themed = rules.filter((rule) => rule.selectorText.trim().startsWith(".dark") === isDark);

        return themed.map((rule) => {
            const probe = document.createElement("input");
            probe.className = "form-input";
            probe.style.setProperty("-webkit-text-fill-color", rule.style.getPropertyValue("-webkit-text-fill-color"));
            probe.style.setProperty("caret-color", rule.style.getPropertyValue("caret-color"));
            probe.style.setProperty("box-shadow", rule.style.getPropertyValue("box-shadow"));
            document.body.append(probe);
            const style = getComputedStyle(probe);
            // Fond imposé par le navigateur (clair) recouvert par l'ombre intérieure : c'est elle qu'on voit.
            const background = window.__shadowColor(style.boxShadow);
            const result = {
                selector: rule.selectorText,
                text: window.__contrast(style.webkitTextFillColor, background),
                caret: window.__contrast(style.caretColor, background),
                backgroundAlpha: window.__toRgba(background)[3],
                details: `${style.webkitTextFillColor} sur ${background}`,
            };
            probe.remove();

            return result;
        });
    });

    expect(results.length, "règles :-webkit-autofill du thème introuvables").toBeGreaterThanOrEqual(2);
    for (const result of results) {
        expect(result.backgroundAlpha, `${result.selector} : fond de recouvrement transparent`).toBe(255);
        expect(result.text, `${result.selector} : ${result.details}`).toBeGreaterThanOrEqual(MIN_CONTRAST);
        expect(result.caret, `${result.selector} : curseur peu visible`).toBeGreaterThanOrEqual(MIN_CONTRAST);
    }
});
