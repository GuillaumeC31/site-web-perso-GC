/*
 * CV public allégé (corrections de recette n° 1 à 4, 2026-10-30) dans un vrai navigateur, clair et sombre selon le projet :
 * - /cv et l'accueil ne contiennent (HTML entier, éléments masqués compris) aucune puce réservée, aucun détail du
 *   parcours terrain, aucun texte complet des rubriques, aucun centre d'intérêt, ni langue ni formation ; mention « + n points détaillés » ;
 * - note « CV synthétique » : lien « Demander un accès », ou « voir le CV complet » pour un recruteur connecté ;
 * - l'espace recruteur garde tout le détail (rubriques complètes, centres d'intérêt) ;
 * - pages accessibles (axe-core), sans débordement horizontal à 375 px.
 *
 * Contenu : celui de la base de développement recopiée dans la base e2e (TEXTES_SITE.md, repères 🌐).
 * Captures (ordinateur et mobile, clair et sombre) et les 4 PDF dans captures-cv-public-v2/ (hors dépôt).
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";

const AUTH_DIR = path.resolve("tests/e2e/.auth");
const CAPTURE_DIR = path.resolve("captures-cv-public-v2");

/** Textes réservés à l'espace recruteur (puces non cochées, parcours terrain, rubriques complètes, intérêts). */
const RESERVED = [
    "Rachat de BCI par ALPHA ESSAIS Group",
    "Fonction exercée principalement à distance",
    "Coordination des équipes et sous-traitants",
    "Formation de développeur web",
    "Planification de l'atelier",
    "maintenance préventive et curative",
    "travail dans le commerce saisonnier",
    "SpaceClaim 2023 R1",
    "Eaton Easy-Soft",
    "Utilisation de l'IA générative pour l'assistance documentaire",
    "Terrariophilie",
    "Escape games",
    "Fin de contrat",
    // Langues, formations et certifications réservées (correction de recette n° 2)
    "langue maternelle",
    "compréhension écrite",
    "École Exemple",
    "Bac Pro Énergétique",
    "BEP/CAP Frigoriste",
    "manipulation des fluides",
    "Habilitations électriques",
];

/** Nombre de pages d'un PDF (objets « /Type /Page »). */
function pageCount(buffer) {
    return (buffer.toString("latin1").match(/\/Type\s*\/Page[^s]/g) ?? []).length;
}

/** HTML de la page sans les césures invisibles (un mot coupé ne doit pas cacher une fuite). */
async function htmlWithoutHyphenation(page) {
    return (await page.content()).replace(/[­​]/g, "").replace(/&#039;|&apos;/g, "'");
}

/** Audit axe-core WCAG A et AA une fois les animations finies, zéro violation. */
async function expectNoViolation(page) {
    await page.waitForFunction(() => document.getAnimations().every((a) => a.playState !== "running"));
    const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).exclude(".sf-toolbar").analyze();
    const summary = results.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target.join(" ")).slice(0, 5) }));
    expect(summary, JSON.stringify(summary, null, 2)).toEqual([]);
}

test("CV public et accueil : rien de réservé, note et mentions présentes", async ({ page }) => {
    for (const url of ["/cv", "/"]) {
        const response = await page.goto(url, { waitUntil: "load" });
        expect(response?.status()).toBe(200);
        const html = await htmlWithoutHyphenation(page);
        for (const text of RESERVED) {
            expect(html.toLowerCase(), `${url} : « ${text} »`).not.toContain(text.toLowerCase());
        }
    }

    await page.goto("/cv", { waitUntil: "load" });
    const note = page.getByTestId("cv-synthetic-note");
    // Trois lignes, la première en gras, le lien à la fin de la troisième (correction de recette n° 2 bis)
    const lines = note.locator("p");
    await expect(lines).toHaveCount(3);
    await expect(lines.nth(0)).toHaveText("Ceci est une version courte de mon CV.");
    await expect(lines.nth(0)).toHaveCSS("font-weight", "600");
    await expect(lines.nth(1)).toContainText("Missions détaillées, formations, langues et coordonnées sont dans l'espace recruteur.");
    await expect(lines.nth(2)).toContainText("Accès gratuit et sécurisé : demande en moins d'une minute, réponse sous 24 h ouvrées.");
    const link = lines.nth(2).getByRole("link", { name: /Demander votre accès/ });
    await expect(link).toHaveAttribute("href", "/espace-recruteur");
    // Lien bien cliquable sur mobile : zone d'au moins 24 px de haut (WCAG 2.2, 2.5.8)
    expect((await link.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(24);
    await expect(page.locator("#cv-languages")).toHaveCount(0);
    await expect(page.locator("#cv-educations")).toHaveCount(0);
    // ALPHA ESSAIS : 3 puces publiques sur 4 ; BCI : 3 sur 5
    await expect(page.getByTestId("cv-hidden-count").first()).toContainText("+ 1 point détaillé dans l'espace recruteur");
    await expect(page.getByTestId("cv-hidden-count").nth(1)).toContainText("+ 2 points détaillés dans l'espace recruteur");
    await expect(page.locator("#cv-interest")).toHaveCount(0);
    await expect(page.getByRole("link", { name: "CV synthétique (PDF)" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Version ATS (PDF)" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Me contacter" }).first()).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
});

test("recruteur connecté : note « voir le CV complet », espace avec tout le détail", async ({ browser, baseURL }) => {
    const context = await browser.newContext({ ...test.info().project.use, storageState: path.join(AUTH_DIR, "recruiter.json"), ignoreHTTPSErrors: true, baseURL });
    const page = await context.newPage();

    await page.goto("/cv", { waitUntil: "load" });
    const note = page.getByTestId("cv-synthetic-note");
    await expect(note).toHaveText(/Vous êtes connecté\.\s*→ Voir le CV complet/);
    await expect(note.getByRole("link", { name: /Voir le CV complet/ })).toHaveAttribute("href", "/espace-recruteur/accueil");

    await page.goto("/espace-recruteur/accueil", { waitUntil: "load" });
    const html = await htmlWithoutHyphenation(page);
    for (const text of ["Rachat de BCI par ALPHA ESSAIS Group", "maintenance préventive et curative", "SpaceClaim 2023 R1", "Terrariophilie", "langue maternelle", "École Exemple", "manipulation des fluides", "Habilitations électriques"]) {
        expect(html.toLowerCase(), text).toContain(text.toLowerCase());
    }
    await expect(page.getByTestId("cv-hidden-count")).toHaveCount(0);
    // Deux listes triées par année décroissante (correction de recette n° 3)
    await expect(page.getByTestId("cv-diplomas").locator(".cv-date")).toHaveText(["2019", "1999", "1998", "1997", "1995"]);
    await expect(page.getByTestId("cv-certifications").locator(".cv-date")).toHaveText(["2021", "2011"]);
    await expect(page.getByRole("link", { name: "CV complet (PDF)" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Version ATS complète (PDF)" })).toBeVisible();
    await context.close();
});

test("captures du CV public et de l'espace recruteur, accessibles ; les 4 PDF", async ({ browser, baseURL }, testInfo) => {
    const scheme = testInfo.project.use.colorScheme === "dark" ? "sombre" : "clair";
    fs.mkdirSync(CAPTURE_DIR, { recursive: true });
    for (const [device, options] of [
        ["mobile", { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }],
        ["ordinateur", { viewport: { width: 1280, height: 800 } }],
    ]) {
        const common = { ...options, colorScheme: testInfo.project.use.colorScheme ?? "light", reducedMotion: "reduce", ignoreHTTPSErrors: true, baseURL };

        const visitor = await browser.newContext(common);
        const page = await visitor.newPage();
        await page.goto("/cv", { waitUntil: "load" });
        await expectNoViolation(page);
        await page.screenshot({ path: path.join(CAPTURE_DIR, `cv-public-${device}-${scheme}.png`), fullPage: true });
        await visitor.close();

        const recruiter = await browser.newContext({ ...common, storageState: path.join(AUTH_DIR, "recruiter.json") });
        const area = await recruiter.newPage();
        await area.goto("/espace-recruteur/accueil", { waitUntil: "load" });
        await expectNoViolation(area);
        await area.screenshot({ path: path.join(CAPTURE_DIR, `espace-recruteur-${device}-${scheme}.png`), fullPage: true });

        // Les 4 PDF, une seule fois (contenu identique quel que soit l'écran)
        if ("ordinateur" === device && "clair" === scheme) {
            for (const [url, name] of [
                ["/cv/pdf/design", "cv-synthetique.pdf"],
                ["/cv/pdf/ats", "cv-synthetique-ats.pdf"],
                ["/espace-recruteur/cv/design", "cv-complet.pdf"],
                ["/espace-recruteur/cv/ats", "cv-complet-ats.pdf"],
            ]) {
                const response = await area.request.get(url);
                expect(response.status(), url).toBe(200);
                expect(response.headers()["content-type"]).toContain("application/pdf");
                const body = await response.body();
                fs.writeFileSync(path.join(CAPTURE_DIR, name), body);
                // CV synthétique mis en page : 1 page ; version ATS synthétique : 2 pages au plus (correction n° 4)
                if ("cv-synthetique.pdf" === name) {
                    expect(pageCount(body), name).toBe(1);
                } else if ("cv-synthetique-ats.pdf" === name) {
                    expect(pageCount(body), name).toBeLessThanOrEqual(2);
                }
            }
        }
        await recruiter.close();
    }
});
