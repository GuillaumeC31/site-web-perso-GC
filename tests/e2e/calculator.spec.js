/*
 * Test de bout en bout de la calculatrice dans un vrai navigateur : chargement à la demande des
 * contrôleurs, calculs affichés (valeurs de l'ancien site, en français), favoris, outils récents
 * et recherche du tableau de bord.
 *
 * Les valeurs attendues sont celles de assets/calculators/golden/*.json (ancien site), sauf
 * corrections signalées dans docs/CALCULATRICE_ANOMALIES.md.
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import { expect, test } from "@playwright/test";

/** Retire les espaces (fines insécables du format français) pour comparer les nombres. */
const compact = (text) => (text ?? "").replace(/\s/g, "");

test("loi d'Ohm : deux valeurs saisies donnent les deux autres", async ({ page }) => {
    await page.goto("/calculatrice/electricite/loi-ohm");
    await page.fill("#ohm-u", "12");
    await page.fill("#ohm-i", "2");

    await expect(page.locator("#ohm-r")).toHaveValue("6,00");
    await expect(page.locator("#ohm-p")).toHaveValue("24,00");
    // Le champ en cours de saisie n'est pas réécrit.
    await expect(page.locator("#ohm-i")).toHaveValue("2");
});

test("puissances : virgule décimale acceptée, résultat au format français", async ({ page }) => {
    await page.goto("/calculatrice/electricite/puissances-ac");
    await page.fill("#p-u", "230,5");
    await page.fill("#p-i", "7,3");
    await page.fill("#p-cos", "0,92");

    await expect.poll(async () => compact(await page.locator("#p-result").textContent())).toBe("1548,04");
});

test("câbles : les quatre blocs sont calculés, cuivre et aluminium", async ({ page }) => {
    await page.goto("/calculatrice/electricite/calculs-cables");
    await page.fill("#sec-l", "50");
    await page.fill("#sec-i", "10");
    await page.fill("#sec-du", "0,6");
    // A1 : ρ NF C 15-100 (cuivre 0,0225 ; aluminium 0,036), rappelé sous le résultat
    await expect(page.locator("#sec-result")).toHaveText("37,50");
    await page.selectOption("#sec-mat", "al");
    await expect(page.locator("#sec-result")).toHaveText("60,00");
    await expect(page.locator("[data-calculator--calculs-cables-target='sectionNote']")).toHaveText("ρ = 0,036 Ω·mm²/m (aluminium) — NF C 15-100 / UTE C 15-105");

    // A3 : valeur refusée avec un message
    await page.fill("#sec-l", "-50");
    await expect(page.locator("#sec-result")).toHaveText("—");
    await expect(page.locator("[data-calculator--calculs-cables-target='sectionMessage']")).toHaveText("Longueur (L) : la valeur doit être strictement positive.");

    // A4 : nombre de conducteurs entier
    await page.fill("#w-n", "2,7");
    await expect(page.locator("[data-calculator--calculs-cables-target='weightMessage']")).toHaveText("Nombre de conducteurs : la valeur doit être un nombre entier, au moins 1.");
    await page.fill("#w-n", "2");

    // Valeurs par défaut de l'ancien site (2 conducteurs, gaine 20 %)
    await page.fill("#w-s", "2,5");
    await page.fill("#w-l", "50");
    await expect(page.locator("#w-result")).toHaveText("2,69");
});

test("relèvement du cos φ : capacité par phase en étoile et en triangle", async ({ page }) => {
    await page.goto("/calculatrice/electricite/relevement-cos-phi");
    await page.fill("#pf-p", "150");
    await page.fill("#pf-ini", "0,75");

    await expect.poll(async () => compact(await page.locator("#pf-cap-star").textContent())).toBe("1452,4");
    await expect(page.locator("#pf-cap-delta")).toHaveText("484,1");
});

test("Steinmetz : conversion kW → ch et condensateur", async ({ page }) => {
    await page.goto("/calculatrice/electricite/steinmetz");
    await page.fill("#stein-kw", "1,5");

    await expect(page.locator("#stein-hp")).toHaveValue("2,04");
    // A2 : règle pratique de 70 µF/kW (ancien site : 326 µF)
    await expect(page.locator("#stein-c")).toHaveText("105");
});

test("favoris et recherche du tableau de bord ; plus d'outils récents", async ({ page }) => {
    // Clé laissée par l'ancienne version : effacée au chargement
    await page.goto("/calculatrice");
    await page.evaluate(() => localStorage.setItem("calculator.recents", '["electricite/loi-ohm"]'));
    await expect(page.locator("[data-calculator--dashboard-target='favorites']")).toBeHidden();

    await page.goto("/calculatrice/electricite/chute-tension");
    const favorite = page.locator("[data-calculator--tool-target='favorite']");
    await expect(favorite).toBeVisible();
    await favorite.click();
    await expect(favorite).toHaveAttribute("aria-pressed", "true");

    await page.goto("/calculatrice");
    await expect(page.locator("[data-calculator--dashboard-target='favoritesList'] a")).toHaveText(/Chute de tension/);
    await expect(page.getByText("Consultés récemment")).toHaveCount(0);
    expect(await page.evaluate(() => localStorage.getItem("calculator.recents"))).toBeNull();

    await page.fill("#calculator-search", "STEINMETZ");
    await expect(page.locator("[data-calculator--dashboard-target='tool']:visible")).toHaveCount(1);
    await expect(page.locator("[data-calculator--dashboard-target='status']")).toHaveText("1 outil(s) trouvé(s)");

    // Navigation : seules les catégories avec un résultat restent, avec le nombre d'outils trouvés
    const pills = page.locator("[data-calculator--dashboard-target='pills']");
    await expect(pills.locator("li:visible")).toHaveCount(1);
    await expect(pills.locator("a[href='#electricite'] [data-count]")).toHaveText("1");

    await page.fill("#calculator-search", "aucun outil ne s'appelle ainsi");
    await expect(page.locator("[data-calculator--dashboard-target='empty']")).toBeVisible();

    await page.fill("#calculator-search", "");
    await expect(pills.locator("a[href='#electricite'] [data-count]")).toHaveText("6");
});

test("tableau de bord à 375 px : pastilles collantes, ancre et surbrillance", async ({ page }) => {
    await page.goto("/calculatrice");
    const pills = page.locator("[data-calculator--dashboard-target='pills']");
    await expect(pills).toBeVisible();
    // Seule la rangée défile horizontalement
    expect(await pills.evaluate((row) => row.scrollWidth > row.clientWidth)).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);

    await pills.locator("a[href='#thermodynamique']").click();
    await expect(page).toHaveURL(/#thermodynamique$/);
    await expect(pills.locator("a[href='#thermodynamique']")).toHaveAttribute("aria-current", "true");
    // Le titre arrive sous la rangée de pastilles collante, sans être caché par elle
    await expect.poll(async () => {
        const [title, bar] = await Promise.all([page.locator("#category-thermodynamique").boundingBox(), pills.boundingBox()]);
        return title.y >= bar.y + bar.height - 1 && title.y < 260;
    }).toBe(true);
});

test.describe("tableau de bord sur grand écran (1440 px)", () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test("colonne collante : clic sur une catégorie, ancre, surbrillance qui suit le défilement", async ({ page }) => {
        await page.goto("/calculatrice");
        const nav = page.locator("nav[aria-label=\"Catégories d'outils\"]").first();
        await expect(nav).toBeVisible();
        await expect(page.locator("[data-calculator--dashboard-target='pills']")).toBeHidden();
        await expect(nav.locator("a[href='#electricite']")).toHaveAttribute("aria-current", "true");

        await nav.locator("a[href='#mecanique']").click();
        await expect(page).toHaveURL(/#mecanique$/);
        await expect(nav.locator("a[href='#mecanique']")).toHaveAttribute("aria-current", "true");
        await expect.poll(async () => (await page.locator("#category-mecanique").boundingBox()).y).toBeLessThan(120);
        // La colonne reste visible pendant le défilement
        expect((await nav.locator("ul").boundingBox()).y).toBeGreaterThanOrEqual(0);

        // Défilement à la main : la surbrillance suit
        await page.waitForTimeout(1300);
        await page.evaluate(() => document.getElementById("electronique").scrollIntoView({ block: "start", behavior: "instant" }));
        await expect(nav.locator("a[href='#electronique']")).toHaveAttribute("aria-current", "true");
        await expect(nav.locator("a[href='#mecanique']")).not.toHaveAttribute("aria-current", "true");

        // Lien direct vers une catégorie
        await page.goto("/calculatrice#thermodynamique");
        await expect(nav.locator("a[href='#thermodynamique']")).toHaveAttribute("aria-current", "true");

        // Navigation au clavier : focus visible
        await nav.locator("a[href='#mecanique']").focus();
        await page.keyboard.press("Tab");
        await expect(nav.locator("a[href='#impression-3d']")).toBeFocused();
        expect(await nav.locator("a[href='#impression-3d']").evaluate((link) => getComputedStyle(link).outlineStyle)).not.toBe("none");
    });
});

test("résistance pour LED : valeur calculée, série E24 et puissance conseillée", async ({ page }) => {
    await page.goto("/calculatrice/electronique/resistance-led");
    await page.fill("#led-supply", "12");
    await page.fill("#led-voltage", "2");
    await page.fill("#led-current", "20");

    await expect(page.locator("#led-r")).toHaveText("500,0");
    await expect(page.locator("#led-e24")).toContainText("510");
});

test("PT100 : température ↔ résistance (CEI 60751)", async ({ page }) => {
    await page.goto("/calculatrice/mesures-capteurs/pt100");
    await page.fill("#pt-t", "100");
    await expect(page.locator("#pt-r")).toHaveValue("138,51");
});

test("4-20 mA : 12 mA donnent le milieu de la plage", async ({ page }) => {
    await page.goto("/calculatrice/mesures-capteurs/signal-4-20ma");
    await page.fill("#sc-min", "0");
    await page.fill("#sc-max", "10");
    await page.fill("#sc-signal", "12");
    await expect(page.locator("#sc-physical")).toHaveValue("5,00");
    await expect(page.locator("#sc-shunt")).toHaveText("3,00");
});

test("coût d'impression : matière et électricité", async ({ page }) => {
    await page.goto("/calculatrice/impression-3d/cout-impression");
    await page.fill("#cost-weight", "150");
    await page.fill("#cost-spool", "20");
    await page.fill("#cost-hours", "4");
    await page.fill("#cost-minutes", "30");
    await page.fill("#cost-power", "150");

    await expect(page.locator("#cost-total")).toHaveText("3,17");
    await expect(page.locator("#cost-material")).toHaveText("3,00");
    await expect(page.locator("#cost-electricity")).toHaveText("0,17");
});

test("convertisseur : bar → psi, puis changement de grandeur", async ({ page }) => {
    await page.goto("/calculatrice/convertisseur/unites");
    await page.fill("#conv-value", "1");
    await page.selectOption("#conv-from", "bar");
    await page.selectOption("#conv-to", "psi");
    await expect.poll(async () => compact(await page.locator("#conv-result").textContent())).toMatch(/^14,50/);

    await page.selectOption("#conv-type", "temperature");
    await expect(page.locator("#conv-to option")).toHaveCount(3);
});

test("corrections B1, B4 et B5 visibles dans les pages", async ({ page }) => {
    // B1 : 12 V, LED 3,2 V, 20 mA → 0,176 W, conseil 1/2 W (≥ 2 × P)
    await page.goto("/calculatrice/electronique/resistance-led");
    await page.fill("#led-supply", "12");
    await page.fill("#led-voltage", "3,2");
    await page.fill("#led-current", "20");
    await expect(page.locator("[data-calculator--resistance-led-target='advice']")).toContainText("1/2 W (0,5 W)");

    // B4 : 15 dents à 20° → avertissement ; 13 dents → alerte forte ; limites affichées
    await page.goto("/calculatrice/mecanique/engrenages");
    await page.fill("#gear-z1", "15");
    await page.fill("#gear-z2", "47");
    await expect(page.locator("[data-calculator--engrenages-target='alerts']")).toContainText("Risque d'interférence");
    await page.fill("#gear-z1", "13");
    await expect(page.locator("[data-calculator--engrenages-target='alerts']")).toContainText("Interférence de taillage");
    await expect(page.locator("[data-calculator--engrenages-target='limits']")).toContainText("→ 17 dents");

    // B5 : 18,52 Ω → -200 °C
    await page.goto("/calculatrice/mesures-capteurs/pt100");
    await page.fill("#pt-r", "18,52");
    await expect(page.locator("#pt-t")).toHaveValue("-200,00");
});

test("thermodynamique : eau, fluide, point de rosée et psychromètre", async ({ page }) => {
    await page.goto("/calculatrice/thermodynamique/chauffe-eau");
    await page.fill("#wt-volume", "200");
    await page.fill("#wt-start", "20");
    await page.fill("#wt-end", "60");
    await page.fill("#wt-power", "3000");
    await expect(page.locator("#wt-energy")).toHaveText("9,30");
    await expect(page.locator("#wt-hours")).toHaveText("3,10");

    // Chauffe d'un fluide : eau liquide par défaut ; la puissance donne le temps
    await page.goto("/calculatrice/thermodynamique/chauffe-fluide");
    await page.fill("#fh-volume", "200");
    await expect(page.locator("#fh-energy")).toHaveText("9,30");
    await page.fill("#fh-power", "3000");
    await expect(page.locator("#fh-hours")).toHaveValue("3,10");

    await page.goto("/calculatrice/thermodynamique/point-rosee");
    await page.fill("#psy1-t", "20");
    await page.fill("#psy1-rh", "50");
    await expect(page.locator("#psy1-td")).toHaveText("9,27");
    // Psychromètre : inopérant sur l'ancien site (C2)
    await page.fill("#psy3-dry", "20");
    await page.fill("#psy3-wet", "15");
    await expect(page.locator("#psy3-rh")).toHaveText("58,6");
});

test("chambre froide v2 : modes simple et pro, portes et vitrages ajoutés puis supprimés", async ({ page }) => {
    const target = (name) => page.locator(`[data-calculator--chambre-froide-target='${name}']`);
    await page.goto("/calculatrice/thermodynamique/chambre-froide");
    // Cas par défaut v2 (docs/CHAMBRE_FROIDE_V2.md, section 4 ; contre-calcul scripts/calculator/cold_room_hand_check.py)
    await expect(page.locator("#cf-frigo")).toHaveText("1,67");
    await expect(page.locator("#cf-need")).toHaveText("26,6");
    await expect(page.locator("#cf-frost")).toHaveText("2,5");
    // Résumé collant (barre en bas sur mobile) et navigation des sections, mode affiché
    await expect(target("summaryFrigo")).toHaveText("1,67");
    await expect(target("alertCount")).toHaveText("0");
    await expect(page.locator("nav [data-section-link='4']").filter({ visible: true })).toHaveAttribute("href", "#cf-s-section-4");
    await expect(target("productSummary")).toContainText("déjà congelés à l'entrée");
    await expect(page.locator("#cf-s-entry")).not.toBeEditable();
    // 11 postes et le total, chacun avec sa formule
    await expect(target("breakdown").locator("tr")).toHaveCount(12);
    await expect(target("breakdown")).toContainText("Infiltrations par les portes");
    await expect(target("breakdown")).toContainText("ASHRAE");
    await expect(target("fluidReport")).toContainText("en cours de vérification, non affichées");
    // R-290 par défaut, en groupe autonome hermétiquement scellé ; R-600a proposé
    await expect(page.locator("#cf-s-fluid")).toHaveValue("R290");
    await expect(page.locator("#cf-s-installation")).toHaveValue("self_contained");
    await expect(page.locator("#cf-s-hermetic")).toHaveValue("yes");
    await expect(target("fluidReport")).toContainText("le R-600a (Isobutane) est aussi proposé");
    // Charge de 0,25 kg (0,15 kg/kW) sous la limite par circuit IEC 60335-2-89 (13 × LFL = 0,49 kg) : aucun avertissement
    await expect(target("fluidReport")).toContainText("Charge estimée : 0,25 kg");
    await expect(target("fluidReport")).toContainText("à vérifier avec les fiches constructeurs");
    await expect(target("fluidReport")).toContainText("IEC 60335-2-89");
    await expect(page.locator("[data-charge-warning]")).toHaveCount(0);
    // Split choisi à la main : charge plus forte, avertissement NF EN 378-1, type conservé si le fluide change
    await page.selectOption("#cf-s-installation", "split");
    await expect(page.locator("#cf-s-hermetic")).toHaveValue("no");
    await expect(page.locator("[data-charge-warning]")).toContainText("dépasse la limite pour le local");
    await expect(target("alertCount")).toHaveText("1");
    await expect(target("summaryAlerts")).toContainText("⚠ Charge de fluide au-delà de la limite — voir le détail");
    await expect(target("summaryAlerts").locator("a")).toHaveAttribute("href", "#cf-section-7");
    await page.selectOption("#cf-s-fluid", "R744");
    await expect(page.locator("#cf-s-installation")).toHaveValue("split");
    await page.selectOption("#cf-s-fluid", "R290");
    // LFL non renseignée (R-454A, A2L) : contrôle signalé, pas ignoré
    await page.selectOption("#cf-s-fluid", "R454A");
    await expect(target("fluidReport")).toContainText("limite non calculable : LFL non renseignée");
    await expect(page.getByText(/étagère/i)).toHaveCount(0);
    // Positif : suggestion de 16 h/j, dégivrage par l'air
    await page.selectOption("#cf-s-regime", "positive");
    await expect(page.locator("#cf-s-run")).toHaveValue("16");
    await expect(target("breakdown")).toContainText("Dégivrage (naturel (à l'arrêt du froid))");

    await page.getByRole("button", { name: "Pro" }).click();
    const oneDoor = await page.locator("#cf-frigo").textContent();
    await page.getByRole("button", { name: "Ajouter une porte" }).click();
    await expect(page.locator("legend", { hasText: "Porte 2" })).toBeVisible();
    await expect(page.locator("#cf-frigo")).not.toHaveText(oneDoor);
    // Suppression avec confirmation : annulée, puis acceptée
    page.once("dialog", (dialog) => dialog.dismiss());
    await page.getByRole("button", { name: "Supprimer cette porte" }).nth(1).click();
    await expect(page.locator("legend", { hasText: "Porte 2" })).toBeVisible();
    page.once("dialog", (dialog) => {
        expect(dialog.message()).toBe("Supprimer cette porte ?");
        dialog.accept();
    });
    await page.getByRole("button", { name: "Supprimer cette porte" }).nth(1).click();
    await expect(page.locator("legend", { hasText: "Porte 2" })).toHaveCount(0);
    await expect(page.locator("#cf-frigo")).toHaveText(oneDoor);
    await expect(page.getByRole("button", { name: "Ajouter une porte" })).toBeFocused();

    await expect(page.getByText("Aucun vitrage.")).toBeVisible();
    await page.getByRole("button", { name: "Ajouter un vitrage" }).click();
    await expect(page.getByText("Aucun vitrage.")).toBeHidden();
    const glazing = page.locator("fieldset", { hasText: "Vitrage 1" });
    await glazing.locator("[data-item-field='heated']").selectOption("yes");
    await glazing.locator("[data-item-field='heaterW']").fill("60");
    await expect(target("breakdown")).toContainText("Vitrages chauffés");
    page.once("dialog", (dialog) => dialog.accept());
    await page.getByRole("button", { name: "Supprimer ce vitrage" }).click();
    await expect(target("breakdown")).not.toContainText("Vitrages");

    // C12 conservé : viande congelée sur place
    const product = page.locator("fieldset", { hasText: "Produit 1" });
    await product.locator("[data-item-field='profile']").selectOption("meat");
    await product.locator("[data-item-field='freezing']").selectOption("yes");
    await expect(target("productSummary")).toContainText("294,2 kJ/kg à retirer ; charge répartie sur 14 h de refroidissement (durée par défaut du profil « Viande / volaille »");
    // Protection à lanières : efficacité 70 % et note
    const door = page.locator("fieldset", { hasText: "Porte 1" });
    await door.locator("[data-item-field='protection']").selectOption("strips");
    await expect(door.locator("[data-item-field='efficiency']")).toHaveValue("70");
    await expect(door).toContainText("85 à 95 % si neuf et bien entretenu");
    const withStrips = await page.locator("#cf-frigo").textContent();
    await door.locator("[data-item-field='efficiency']").fill("150");
    await expect(page.locator("#cf-frigo")).toHaveText("—");
    await expect(target("message")).toHaveText("Porte 1 : efficacité de la protection : la valeur doit être comprise entre 0 et 100 %.");
    await door.locator("[data-item-field='efficiency']").fill("70");
    await expect(page.locator("#cf-frigo")).toHaveText(withStrips);
    await page.fill("#cf-p-length", "-4");
    await expect(page.locator("#cf-frigo")).toHaveText("—");
    await expect(target("message")).toHaveText("Longueur : la valeur doit être strictement positive.");
});

test("enceinte climatique v2 : mode simple, puis cas 1 et cas 3 en mode pro", async ({ page }) => {
    await page.goto("/calculatrice/thermodynamique/enceinte-climatique");
    const results = (name) => page.locator(`[data-calculator--enceinte-climatique-target='${name}']`);
    // Cas par défaut (ICH long terme 25 °C / 60 %, chambre modulaire 2 × 1,5 × 2 m) : puissances à installer,
    // mise en régime en 60 min (température) et 30 min (humidité)
    await expect(page.locator("#ec-s-profile")).toHaveValue("ich_long_term");
    await expect(page.locator("#ec-cold")).toHaveText("496");
    await expect(page.locator("#ec-heat")).toHaveText("294");
    await expect(page.locator("#ec-humid")).toHaveText("91");
    await expect(results("coldDetail")).toContainText("maintien : 325 W · mise en régime : 496 W");
    await expect(results("summaryCold")).toHaveText("496");
    await expect(results("seasons")).toContainText("Consommation électrique");
    await expect(results("fluidReport")).toContainText("IEC 60335-2-89");
    // Monobloc saisi par son volume (1 000 L)
    await page.getByText("Monobloc", { exact: true }).first().click();
    await expect(page.locator("#ec-s-volume")).toBeVisible();
    await expect(page.locator("[data-mode='simple'] [data-geometry-info]")).toContainText("Volume 1,00 m³ (1 000 L)");
    // Module extérieur non chauffé : température extérieure minimale proposée (−5 °C)
    await page.getByText("Modulaire extérieure", { exact: true }).first().click();
    await page.selectOption("#ec-s-frost", "no");
    await expect(page.locator("#ec-s-outdoor-min")).toHaveValue("-5");
    await page.selectOption("#ec-s-frost", "yes");
    await page.getByText("Modulaire", { exact: true }).first().click();
    // Profil réfrigéré : humidité non régulée
    await page.selectOption("#ec-s-profile", "ich_refrigerated");
    await expect(page.locator("#ec-s-rhset")).toHaveValue("");
    await expect(page.locator("#ec-humid")).toHaveText("0");

    // Mode pro, cas 1 : 40 °C / 75 %, chauffe électrique 2 kW installés (docs/ENCEINTE_CLIMATIQUE_V2.md, 4.1)
    await page.getByRole("button", { name: "Pro", exact: true }).click();
    await page.selectOption("#ec-p-profile", "ich_accelerated");
    await page.fill("#ec-p-heater-w", "2000");
    await expect(page.locator("#ec-cold")).toHaveText("347");
    await expect(page.locator("#ec-heat")).toHaveText("2 000");
    await expect(page.locator("#ec-humid")).toHaveText("340");
    await expect(results("heatDetail")).toContainText("maintien : 176 W · puissance saisie ; mise en régime : 10,6 min / 23,7 min");
    // Conseil complet dans la section Refroidissement, version courte avec lien dans le résumé
    await expect(page.locator("#ec-p-section-5 [data-section-alerts]")).toContainText("au-dessus du point de rosée");
    await expect(results("summaryAlerts")).toContainText("Condensation sur la batterie froide — voir le conseil");
    await expect(results("summaryAlerts").locator("[data-alert-code='condensation'] a")).toHaveAttribute("href", "#ec-p-section-5");
    // Régulation : chauffe de 2 kW, 11 fois le maintien → étages ; réglages de départ (section 8.3)
    await expect(page.locator("#ec-p-section-9 [data-pid-loop='heat']")).toContainText("2,57 K");
    await expect(page.locator("#ec-p-section-9 [data-pid-loop='cold']")).toContainText("0,45 K");
    await expect(page.locator("#ec-p-section-9 [data-section-alerts]")).toContainText("découpage en étages");
    // Surface à évaporation + 3 K : plus de condensation
    await page.fill("#ec-p-surface", "3");
    await expect(page.locator("#ec-p-section-5 [data-advice]")).toHaveCount(0);
    await page.fill("#ec-p-surface", "2");

    // Cas 3 : 5 °C, électronique 500 W 12 h/j (50 kg), descente en 60 min (défaut), ± 0,5 K
    await page.fill("#ec-p-heater-w", "");
    await page.selectOption("#ec-p-profile", "ich_refrigerated");
    await page.selectOption("#ec-p-stab-t", "0.5");
    await expect(page.locator("#ec-p-regulation")).toHaveValue("modulating");
    await page.fill("#ec-p-sample-kg", "50");
    await page.fill("#ec-p-sample-cp", "0,9");
    await page.fill("#ec-p-sample-w", "500");
    await page.fill("#ec-p-sample-hours", "12");
    await expect(page.locator("#ec-cold")).toHaveText("2 233");
    await expect(results("seasons")).toContainText("64,3 kWh/j");
    // Variateur à 30 % : charge minimale de 14 % sous la plage → by-pass de gaz chaud ; plus d'alerte une fois choisi
    await expect(page.locator("#ec-p-section-9 [data-pid-loop='cold']")).toContainText("⚠ 14 % / ≥ 30 %");
    await page.selectOption("#ec-p-act-cold", "hot_gas");
    await expect(page.locator("#ec-p-section-9 [data-pid-loop='cold']")).not.toContainText("⚠");
    // Équipements en tableau compact (éclairage : puissance et durée)
    await expect(page.locator("#ec-p-section-3").getByRole("textbox", { name: "Éclairage : durée (h/j)" })).toHaveValue("2");
    // Apport manuel ajouté puis supprimé
    await page.getByRole("button", { name: "Ajouter un apport" }).click();
    await page.fill("[data-calculator--enceinte-climatique-target='manualList'] [data-item-field='watts']", "100");
    await expect(page.locator("#ec-cold")).not.toHaveText("2 233");
    page.once("dialog", (dialog) => dialog.accept());
    await page.getByRole("button", { name: "Supprimer cet apport" }).click();
    await expect(page.locator("#ec-cold")).toHaveText("2 233");
});

test("enceinte climatique v2 : navigation des sections au clavier et barre de résumé mobile", async ({ page }) => {
    await page.goto("/calculatrice/thermodynamique/enceinte-climatique");
    // Pastilles (mobile) : chaque lien vise la section du mode affiché ; le titre reçoit le focus
    const link = page.locator("nav [data-section-link='5']").filter({ visible: true });
    await expect(link).toHaveAttribute("href", "#ec-s-section-5");
    await link.focus();
    await page.keyboard.press("Enter");
    await expect(page.locator("#ec-s-section-5-title")).toBeFocused();
    await page.getByRole("button", { name: "Pro", exact: true }).click();
    await expect(link).toHaveAttribute("href", "#ec-p-section-5");
    // Barre de résumé : ouverte (Entrée) et fermée (Échap) au clavier
    const toggle = page.locator("[data-calculator--layout-target='barToggle']");
    await expect(toggle).toHaveAttribute("aria-expanded", "false");
    // Au clavier (en local, la barre de débogage de Symfony recouvre le bas de l'écran)
    await toggle.focus();
    await page.keyboard.press("Enter");
    await expect(toggle).toHaveAttribute("aria-expanded", "true");
    await expect(page.locator("#ec-summary-panel")).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(toggle).toHaveAttribute("aria-expanded", "false");
    await expect(page.locator("#ec-summary-panel")).toBeHidden();
});

test("fluides frigorigènes : équivalent CO2 et filtres", async ({ page }) => {
    await page.goto("/calculatrice/thermodynamique/fluides-frigorifiques");
    await expect(page.locator("#ff-teq")).toHaveText("5,220");
    await page.selectOption("#ff-fluid", "R404A");
    await page.fill("#ff-charge", "10");
    await expect(page.locator("#ff-teq")).toHaveText("39,220");
    await page.selectOption("#ff-family", "Naturel");
    await expect(page.locator("[data-calculator--fluides-frigorifiques-target='tableBody'] tr:visible")).toHaveCount(5);
});

test("éclairage : adresse DMX dans les deux sens, hors plage signalé", async ({ page }) => {
    await page.goto("/calculatrice/eclairage/adresse-dmx");
    const switches = page.locator("[data-calculator--adresse-dmx-target='switch']");
    // Interrupteurs synchronisés dès le chargement (D1) : adresse 1 → interrupteur 1
    await expect(switches.nth(0)).toHaveAttribute("aria-pressed", "true");
    await page.fill("#dmx-address", "100");
    await expect(page.locator("[data-calculator--adresse-dmx-target='sum']")).toHaveText("4 + 32 + 64 = 100");
    await expect(switches.nth(2)).toHaveAttribute("aria-pressed", "true");
    await switches.nth(0).click();
    await expect(page.locator("#dmx-address")).toHaveValue("101");
    await page.fill("#dmx-address", "513");
    await expect(page.locator("[data-calculator--adresse-dmx-target='message']")).toHaveText("Adresse DMX : la valeur doit être un nombre entier de 1 à 512.");
});

test("éclairage horticole : besoins, architecte de spectre et spectre SVG", async ({ page }) => {
    await page.goto("/calculatrice/eclairage/eclairage-phyto");
    await expect(page.locator("#ph-ppfd")).toHaveText("800");
    await expect(page.locator("#ph-dli")).toHaveText("34,6");
    await expect(page.locator("#ph-ppf")).toHaveText("960");
    await expect(page.locator("#ph-watts")).toHaveText("384");
    // D4 : 800 µmol/s de PAR + far-red 11 % à part → 133 LED ; ePPF affiché sur demande
    await expect(page.locator("[data-calculator--eclairage-phyto-target='archTotal']")).toHaveText("133");
    await expect(page.locator("[data-calculator--eclairage-phyto-target='archFarRedOut']")).toHaveText("88");
    await expect(page.locator("[data-calculator--eclairage-phyto-target='archEppf']")).toBeHidden();
    await page.getByLabel("Afficher l'ePPF (400–750 nm)").check();
    await expect(page.locator("[data-calculator--eclairage-phyto-target='archEppf']")).toHaveText("888");
    await expect(page.locator("[data-calculator--eclairage-phyto-target='archChart'] svg path")).toHaveCount(2);
    // Curseur : les autres bandes se rééquilibrent, total toujours 100 %
    await page.locator("#ph-share-red").fill("50");
    const total = await page.locator("[data-calculator--eclairage-phyto-target='share']").evaluateAll((inputs) => inputs.reduce((sum, input) => sum + Number(input.value), 0));
    expect(total).toBe(100);
    await expect(page.locator("[data-calculator--eclairage-phyto-target='share']")).toHaveCount(5);
    await page.getByRole("button", { name: "Reporter ce PPF dans l'architecte de spectre (bloc 2)" }).click();
    await expect(page.locator("#ph-arch-ppf")).toHaveValue("960");
});

test("vieillissement UV et terrariophilie : valeurs de l'ancien site", async ({ page }) => {
    await page.goto("/calculatrice/eclairage/vieillissement-uv");
    await expect(page.locator("#uv-dose")).toHaveText("3 204".replace(" ", " "));
    // D3 : unités spectrales, lampe UVB-313 à 310 nm
    await expect(page.locator("#uv-dose-label")).toBeVisible();
    await page.selectOption("#uv-lamp", "uvb313");
    await expect(page.locator("[data-calculator--vieillissement-uv-target='wavelength']")).toHaveText("310");
    await expect(page.locator("#uv-irradiance")).toHaveValue("0,71");
    await expect(page.locator("#uv-machine")).toHaveText("1 500".replace(" ", " "));

    // D5 : méthode par les parois (tout verre) 275 W ; estimation rapide empirique 175 W (ancien site)
    await page.goto("/calculatrice/terrariophilie/chauffage-terrarium");
    await expect(page.locator("#th-power")).toHaveText("275");
    await expect(page.locator("[data-calculator--chauffage-terrarium-target='rounding']")).toHaveText("253 W calculés → 275 W recommandés (arrondi à la puissance du commerce supérieure, par pas de 25 W).");
    await expect(page.locator("#th-quick")).toHaveText("175");
    await page.locator("[data-calculator--chauffage-terrarium-target='material']").nth(1).selectOption("xps");
    await expect(page.locator("[data-calculator--chauffage-terrarium-target='u']").nth(1)).toHaveValue("1,32");
    await page.goto("/calculatrice/terrariophilie/volume-substrat");
    await expect(page.locator("#ts-litres")).toHaveText("72,0");
    await expect(page.locator("[data-calculator--volume-substrat-target='recipe'] li")).toHaveCount(4);
    await page.goto("/calculatrice/terrariophilie/eclairage-reptiles");
    await expect(page.locator("[data-calculator--eclairage-reptiles-target='uviMax']")).toHaveText("2,9 – 7,4");
    await expect(page.locator("#tl-dli")).toHaveText("4,3");
});
