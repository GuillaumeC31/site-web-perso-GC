/*
 * Page de maintenance (aperçu de l'admin : même page que les visiteurs, sans activer la maintenance), en clair
 * et en sombre :
 * - axe-core : aucune violation (WCAG 2.1 A et AA), avec et sans date, date dépassée ;
 * - sans JavaScript : la date de retour est écrite en clair, le compte à rebours reste caché ;
 * - avec JavaScript : compte à rebours affiché mais masqué aux lecteurs d'écran (aria-hidden) ;
 * - moins d'animations demandé : décor immobile ;
 * - aucun défilement en 375, 768, 1280, 1600, 1920 et 2560 px de large ;
 * - date dépassée : « Retour imminent », rechargement toutes les 60 s par l'en-tête HTTP Refresh.
 * La date de réactivation est posée le temps du fichier, puis remise à sa valeur d'avant ; fichier lancé dans
 * le seul projet « mobile-375 » (playwright.config.js), les thèmes clair et sombre étant testés à l'intérieur.
 */
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const VIEWPORTS = [{ width: 375, height: 812 }, { width: 768, height: 1024 }, { width: 1280, height: 800 }, { width: 1600, height: 900 }, { width: 1920, height: 1080 }, { width: 2560, height: 1440 }];

const sql = (query) => execFileSync("php", ["bin/console", "dbal:run-sql", query], { encoding: "utf8" });

/** Ouvre une session admin dédiée et renvoie le cookie à poser. */
function adminCookie(baseURL) {
    const sessions = JSON.parse(fs.readFileSync(path.resolve("tests/e2e/.auth/sessions.json"), "utf8"));
    const id = execFileSync("php", ["bin/console", "app:e2e:private-sessions", "admin-session"], { encoding: "utf8" }).trim().split("\n").pop();

    return { cookie: { name: sessions.cookie, value: id, url: baseURL }, adminPath: sessions.adminPath };
}

/** Lance l'audit axe et exige zéro violation. */
async function expectNoAxeViolation(page) {
    const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).exclude(".sf-toolbar").analyze();
    const summary = results.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target.join(" ")).slice(0, 5) }));
    expect(summary, JSON.stringify(summary, null, 2)).toEqual([]);
}

test.describe.configure({ mode: "serial" });

let before;
let admin;
test.beforeAll(({ baseURL }) => {
    before = sql("SELECT COALESCE(maintenance_until, 'NULL') AS v FROM site_settings").match(/(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d|NULL)/)[1];
    admin = adminCookie(baseURL);
});
test.afterAll(() => {
    sql(`UPDATE site_settings SET maintenance_until = ${before === "NULL" ? "NULL" : `'${before}'`}`);
});

test("avec date : lisible sans JavaScript, compte à rebours masqué aux lecteurs d'écran, aucun défilement, axe 0 violation", async ({ browser, baseURL }) => {
    sql("UPDATE site_settings SET maintenance_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 52 HOUR)");
    const url = `${baseURL}/${admin.adminPath}/parametres/maintenance/apercu`;
    for (const colorScheme of ["light", "dark"]) {
        // Sans JavaScript : date en clair, compte à rebours caché
        const noJs = await browser.newContext({ ignoreHTTPSErrors: true, colorScheme, javaScriptEnabled: false });
        await noJs.addCookies([admin.cookie]);
        const plain = await noJs.newPage();
        await plain.goto(url, { waitUntil: "load" });
        await expect(plain.locator(".return")).toContainText("Retour prévu le");
        await expect(plain.locator(".countdown")).toBeHidden();
        await noJs.close();

        // Avec JavaScript : compte à rebours visible, ignoré des lecteurs d'écran ; la date reste lue
        for (const viewport of VIEWPORTS) {
            const context = await browser.newContext({ ignoreHTTPSErrors: true, colorScheme, viewport, reducedMotion: "reduce" });
            await context.addCookies([admin.cookie]);
            const page = await context.newPage();
            await page.goto(url, { waitUntil: "load" });
            await expect(page.locator(".countdown")).toBeVisible();
            await expect(page.locator(".countdown")).toHaveAttribute("aria-hidden", "true");
            await expect(page.getByText(/Retour prévu le/)).toBeVisible();
            // Moins d'animations : décor immobile
            expect(await page.evaluate(() => getComputedStyle(document.querySelector(".glow")).animationName)).toBe("none");
            const size = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, sh: document.documentElement.scrollHeight, w: innerWidth, h: innerHeight }));
            expect(size.sw, `${viewport.width} px : défilement horizontal`).toBeLessThanOrEqual(size.w);
            expect(size.sh, `${viewport.width} px : défilement vertical`).toBeLessThanOrEqual(size.h);
            if ([375, 1920].includes(viewport.width)) {
                await expectNoAxeViolation(page);
            }
            await context.close();
        }
    }
});

test("sans date et date dépassée : « Retour très bientôt » ou « Retour imminent », axe 0 violation", async ({ browser, baseURL }) => {
    const url = `${baseURL}/${admin.adminPath}/parametres/maintenance/apercu`;
    const context = await browser.newContext({ ignoreHTTPSErrors: true, colorScheme: "dark" });
    await context.addCookies([admin.cookie]);
    const page = await context.newPage();
    sql("UPDATE site_settings SET maintenance_until = NULL");
    await page.goto(url, { waitUntil: "load" });
    await expect(page.locator(".countdown")).toHaveCount(0);
    await expectNoAxeViolation(page);

    sql("UPDATE site_settings SET maintenance_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)");
    await page.goto(url, { waitUntil: "load" });
    await expect(page.locator(".return")).toHaveText("Retour imminent.");
    // Rechargement toutes les 60 s sans JavaScript : en-tête HTTP Refresh (pas de balise meta)
    expect((await page.request.get(url)).headers().refresh).toBe("60");
    await expectNoAxeViolation(page);
    await context.close();
});
