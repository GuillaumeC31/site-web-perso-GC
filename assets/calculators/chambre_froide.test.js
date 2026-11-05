/*
 * Chambre froide v2 (lot 5e) : cas par défaut et 3 cas du cahier, rejoués poste par poste contre les cas
 * de référence golden/chambre_froide_v2.json, produits par le contre-calcul indépendant
 * scripts/calculator/cold_room_hand_check.py (vérifié en CI ; détail : docs/CHAMBRE_FROIDE_V2.md, section 4) ; non-régression des postes dont la méthode n'a pas changé
 * (scénarios de l'ancien site, golden/thermodynamique.json) ; règles F-Gas et limites NF EN 378-1.
 * Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import initialFluids from "../../config/calculator/refrigerants.json";
import initialRules from "../../config/calculator/fgas_rules.json";
import reference from "./golden/chambre_froide_v2.json";
import { coldRoom, coldRoomPro, coldRoomSimple, doorInfiltration, explain, freezesInRoom, freezingHeat, panelU, PRODUCT_PROFILES, productEnergyPerKg, respirationRate, simpleToFull } from "./chambre_froide.js";
import { applicableRules, defaultChargeRatio, estimatedCop, flammabilityLimits, isBanned, leakChecks, productStandardLimit, proposedRefrigerants, ruleText } from "./fgas.js";
import { refrigerantsFromRows } from "./refrigerants.js";

const FLUIDS = refrigerantsFromRows(initialFluids.refrigerants);
/** Règles initiales, comme si elles étaient vérifiées dans l'admin. */
const RULES = initialRules.rules.map((rule) => ({ ...rule, verified: true }));
const TODAY = "2026-10-23";

/** Puissance d'un poste (W). */
const w = (result, key) => result.lines.find((l) => l.key === key)?.watts ?? 0;

/** Saisies du mode simple, cas par défaut de la page. */
function simpleDefault(overrides = {}) {
    return {
        length: 4, width: 3, height: 2.5, regime: "negative", usage: "storage", panel: { source: "generic", insulation: "PIR", thickness: 120 },
        floorInsulated: "yes", doorProtection: "none", glazing: "no", glazingArea: 0, loadMode: "loaded",
        product: { profile: "fresh_generic", dailyKg: 500, entryTemp: 10, cp: 3.5, waterPct: 75, pullDown: 12 },
        airRenewal: 0, airRhExt: 50, runHours: 18, margin: 10,
        refrigerant: { fluid: FLUIDS.R290, installation: "split", hermetic: false, rules: [], today: TODAY },
        ...overrides,
    };
}

/** Porte du mode pro. */
const door = (props) => ({ type: "swing", leaves: 1, u: 0.55, protection: "none", efficiency: 0, heaterWPerM: 0, heaterFraction: 0.7, ...props });

/** Produit du mode pro. */
const product = (props) => ({ storedKg: 0, freezing: false, respiration: 0, packaging: "none", packagingKg: 0, freezePoint: NaN, cpFrozen: NaN, ...props });

/** Saisies communes du mode pro (chaque cas en remplace une partie). */
function proBase(overrides) {
    return {
        mode: "pro", bridgePct: 0, floorInsulated: true, rhSet: 85, tExtFloor: 12,
        antiFreeze: { enabled: false, wPerM2: 15, fraction: 0.1, dutyPct: 30 },
        glazings: [], people: { count: 0, hours: 0 }, otherW: 0,
        drain: { enabled: false, lengthM: 2, wPerM: 40, fraction: 0.1, dripMinutes: 10 },
        reliefValve: { enabled: false, watts: 20, fraction: 1 },
        airRenewal: 0, airTExt: 25, airRhExt: 50,
        defrost: { type: "air", powerMode: "kw", powerKw: 0, cycles: 4, minutes: 20, fraction: 0 },
        runHours: 18, margin: 10,
        refrigerant: { fluid: FLUIDS.R290, installation: "self_contained", hermetic: true, lineLength: 0, condenserAir: 35, detection: false, copOverride: NaN, rules: RULES, today: TODAY },
        ...overrides,
    };
}

/** Cas 1 : chambre positive de légumes avec respiration (5 × 4 × 3 m, +4 °C, 90 %). */
const CASE_1 = proBase({
    length: 5, width: 4, height: 3, tSet: 4, rhSet: 90, tExtWall: 25, tExtCeiling: 25, floorInsulated: false,
    panel: { source: "generic", insulation: "PUR", thickness: 100 },
    doors: [door({ width: 1.2, height: 2.1, protection: "strips", efficiency: 0.7, openingsPerDay: 30, openSeconds: 20, localTemp: 25, localRh: 50 })],
    lighting: { watts: 200, hours: 10 }, people: { count: 1, hours: 2 }, fans: { mode: "power", value: 120 },
    products: [product({ profile: "vegetables", dailyKg: 1000, storedKg: 4000, entryTemp: 15, cp: 4, waterPct: 90, freezePoint: -0.8, cpFrozen: 2, pullDown: 8, respiration: respirationRate("vegetables", 4), packaging: "wood", packagingKg: 80 })],
    runHours: 16,
});

/** Cas 2 : chambre négative, câble sous dalle et 2 portes (6 × 5 × 3 m, −20 °C). */
const CASE_2 = proBase({
    length: 6, width: 5, height: 3, tSet: -20, tExtWall: 20, tExtCeiling: 25,
    panel: { source: "generic", insulation: "PIR", thickness: 150 },
    antiFreeze: { enabled: true, wPerM2: 15, fraction: 0.1, dutyPct: 30 },
    doors: [
        door({ width: 1, height: 2, protection: "strips", efficiency: 0.7, openingsPerDay: 20, openSeconds: 30, localTemp: 20, localRh: 60, heaterWPerM: 40 }),
        door({ type: "sliding", leaves: 2, u: 0.5, width: 1.8, height: 2.2, protection: "air_curtain", efficiency: 0.65, openingsPerDay: 10, openSeconds: 60, localTemp: 15, localRh: 70, heaterWPerM: 40 }),
    ],
    lighting: { watts: 240, hours: 8 }, people: { count: 2, hours: 2 }, fans: { mode: "ratio", value: 40 },
    drain: { enabled: true, lengthM: 3, wPerM: 40, fraction: 0.1, dripMinutes: 10 }, reliefValve: { enabled: true, watts: 20, fraction: 1 },
    products: [product({ profile: "fresh_generic", dailyKg: 1000, entryTemp: -15, cp: 3.5, waterPct: 75, freezePoint: -1.5, cpFrozen: 1.9, pullDown: 12 })],
    defrost: { type: "electric", powerMode: "kw", powerKw: 3, cycles: 4, minutes: 25, fraction: 0.5 },
    refrigerant: { fluid: FLUIDS.R455A, installation: "split", lineLength: 15, condenserAir: 35, detection: false, copOverride: NaN, rules: RULES, today: TODAY },
});

/** Cas 3 : chambre négative, congélation sur place et vitrage chauffé (4 × 4 × 2,8 m, −25 °C). */
const CASE_3 = proBase({
    length: 4, width: 4, height: 2.8, tSet: -25, tExtWall: 25, tExtCeiling: 25,
    panel: { source: "custom_lambda", lambda: 0.022, thickness: 180 },
    antiFreeze: { enabled: true, wPerM2: 15, fraction: 0.1, dutyPct: 30 },
    doors: [door({ width: 0.9, height: 2, openingsPerDay: 12, openSeconds: 20, localTemp: 25, localRh: 50, heaterWPerM: 40 })],
    glazings: [{ area: 0.5, u: 1.6, heated: true, heaterW: 60, heaterFraction: 0.7, localTemp: 25 }],
    lighting: { watts: 100, hours: 6 }, fans: { mode: "power", value: 150 },
    drain: { enabled: true, lengthM: 2, wPerM: 40, fraction: 0.1, dripMinutes: 10 }, reliefValve: { enabled: true, watts: 20, fraction: 1 },
    products: [product({ profile: "meat", dailyKg: 300, entryTemp: 8, cp: 3.2, waterPct: 70, freezePoint: -1.7, cpFrozen: 1.8, pullDown: 14, freezing: true, packaging: "cardboard", packagingKg: 15 })],
    defrost: { type: "electric", powerMode: "kw", powerKw: 2, cycles: 4, minutes: 20, fraction: 0.5 },
    refrigerant: { fluid: FLUIDS.R744, installation: "centralised", lineLength: 0, condenserAir: 35, detection: false, copOverride: NaN, rules: RULES, today: TODAY },
});

describe("Chambre froide v2 : cas par défaut (mode simple)", () => {
    const r = coldRoomSimple(simpleDefault());

    it("donne les postes du contre-calcul", () => {
        expect(r.u).toBeCloseTo(1 / (0.17 + 0.12 / 0.022), 10);
        expect(w(r, "walls")).toBeCloseTo(438.26, 2);
        expect(w(r, "doorTransmission")).toBeCloseTo(60.53, 2);
        expect(w(r, "doorHeaters")).toBeCloseTo(162.4, 6);
        expect(w(r, "infiltration")).toBeCloseTo(219.07, 2);
        expect(w(r, "antiFreeze")).toBeCloseTo(5.4, 6);
        expect(w(r, "defrost")).toBeCloseTo(46.52, 2);
        expect(w(r, "drain")).toBeCloseTo(0.67, 2);
        expect(w(r, "reliefValve")).toBe(20);
        expect(w(r, "lighting")).toBe(48);
        expect(w(r, "fans")).toBe(75);
        expect(w(r, "products")).toBeCloseTo(65.97, 2);
    });

    it("donne les totaux du contre-calcul", () => {
        expect(r.totalW).toBeCloseTo(1141.81, 2);
        expect(r.kwhDay).toBeCloseTo(26.612, 3);
        expect(r.frigoKw).toBeCloseTo(1.6747, 4);
        expect(r.cop).toBeCloseTo(1.5664, 4);
        expect(r.frostKgDay).toBeCloseTo(2.498, 3);
        expect(r.lines.reduce((sum, l) => sum + l.share, 0)).toBeCloseTo(1, 10);
    });

    it("n'a ni étagères ni champ « équipements » en mode simple", () => {
        expect(r.lines.map((l) => l.key)).not.toContain("other");
        expect(JSON.stringify(simpleToFull(simpleDefault()))).not.toMatch(/shel|etag/i);
    });
});

describe("Chambre froide v2 : 3 cas calculés à la main", () => {
    it("cas 1 : chambre positive de légumes, respiration et emballages", () => {
        const r = coldRoomPro(CASE_1);
        expect(respirationRate("vegetables", 4)).toBeCloseTo(32, 10);
        expect(w(r, "walls")).toBeCloseTo(583.97, 2);
        expect(w(r, "doorTransmission")).toBeCloseTo(39.09, 2);
        expect(w(r, "infiltration")).toBeCloseTo(49.68, 2);
        expect(w(r, "lighting")).toBeCloseTo(83.33, 2);
        expect(w(r, "people")).toBeCloseTo(20.5, 6);
        expect(w(r, "fans")).toBe(120);
        expect(w(r, "respiration")).toBeCloseTo(128, 6);
        expect(w(r, "defrost")).toBe(0);
        expect(w(r, "products")).toBeCloseTo(1527.78, 2);
        expect(w(r, "packaging")).toBeCloseTo(51.94, 2);
        expect(r.totalW).toBeCloseTo(2604.29, 2);
        expect(r.kwhDay).toBeCloseTo(37.227, 3);
        expect(r.frigoKw).toBeCloseTo(4.2971, 4);
        expect(r.cop).toBeCloseTo(2.4718, 4);
        expect(r.frostKgDay).toBe(0);
        expect(r.waterKgDay).toBeCloseTo(0.657, 3);
    });

    it("cas 2 : chambre négative, câble sous dalle, 2 portes et ventilateurs par kW", () => {
        const r = coldRoomPro(CASE_2);
        expect(w(r, "walls")).toBeCloseTo(708.34, 2);
        expect(w(r, "doorTransmission")).toBeCloseTo(150.74, 2);
        expect(w(r, "doorHeaters")).toBeCloseTo(453.6, 6);
        expect(w(r, "infiltration")).toBeCloseTo(311.66, 2);
        expect(w(r, "antiFreeze")).toBeCloseTo(13.5, 6);
        expect(w(r, "drain")).toBeCloseTo(1.17, 2);
        expect(w(r, "people")).toBeCloseTo(65, 6);
        expect(w(r, "fans")).toBeCloseTo(132.63, 2);
        expect(w(r, "defrost")).toBeCloseTo(104.17, 2);
        expect(w(r, "products")).toBeCloseTo(219.91, 2);
        expect(r.totalW).toBeCloseTo(2260.7, 2);
        expect(r.kwhDay).toBeCloseTo(51.618, 3);
        expect(r.frigoKw).toBeCloseTo(3.3157, 4);
    });

    it("cas 3 : chambre négative, congélation sur place et vitrage chauffé", () => {
        const r = coldRoomPro(CASE_3);
        expect(w(r, "walls")).toBeCloseTo(434.88, 2);
        expect(w(r, "glazing")).toBeCloseTo(40, 6);
        expect(w(r, "glazingHeaters")).toBeCloseTo(42, 6);
        expect(w(r, "doorTransmission")).toBeCloseTo(70.38, 2);
        expect(w(r, "infiltration")).toBeCloseTo(171.55, 2);
        expect(w(r, "products")).toBeCloseTo(1826.07, 2);
        expect(w(r, "packaging")).toBeCloseTo(13.75, 2);
        expect(r.products[0]).toMatchObject({ freezing: true, shouldFreeze: false });
        expect(r.products[0].energyPerKg).toBeCloseTo(3.2 * 9.7 + 233.8 + 1.8 * 23.3, 6);
        expect(r.totalW).toBeCloseTo(3019.45, 2);
        expect(r.kwhDay).toBeCloseTo(54.069, 3);
        expect(r.frigoKw).toBeCloseTo(4.4285, 4);
    });
});

describe("Chambre froide v2 : cas de référence (contre-calcul indépendant)", () => {
    const results = { default: coldRoomSimple(simpleDefault()), case1: coldRoomPro(CASE_1), case2: coldRoomPro(CASE_2), case3: coldRoomPro(CASE_3) };

    for (const [key, expected] of Object.entries(reference.cases)) {
        it(`${key} : chaque poste, les totaux et le COP`, () => {
            const r = results[key];
            for (const [line, values] of Object.entries(expected.lines)) {
                expect(w(r, line), line).toBeCloseTo(values.watts, 4);
                expect(r.lines.find((l) => l.key === line)?.kwhDay ?? 0, line).toBeCloseTo(values.kwhDay, 4);
            }
            // Aucun poste de la calculatrice absent du contre-calcul (sauf un poste nul)
            for (const l of r.lines) {
                expect(l.key in expected.lines || l.watts === 0, l.key).toBe(true);
            }
            expect(r.totalW).toBeCloseTo(expected.totalW, 4);
            expect(r.kwhDay).toBeCloseTo(expected.kwhDay, 4);
            expect(r.frigoKw).toBeCloseTo(expected.frigoKw, 6);
            expect(r.cop).toBeCloseTo(expected.cop, 6);
        });
    }
});

describe("Chambre froide v2 : saisies", () => {
    it("refuse les valeurs hors plage, avec le numéro de la porte ou du vitrage", () => {
        expect(explain("simple", simpleDefault({ length: -4 }))).toBe("Longueur : la valeur doit être strictement positive.");
        const badDoor = { ...CASE_2, doors: [CASE_2.doors[0], { ...CASE_2.doors[1], efficiency: 1.2 }] };
        expect(explain("pro", badDoor)).toBe("Porte 2 : efficacité de la protection : la valeur doit être comprise entre 0 et 100 %.");
        expect(coldRoomPro(badDoor)).toBeNull();
        expect(explain("pro", { ...CASE_3, glazings: [{ ...CASE_3.glazings[0], area: 0 }] })).toBe("Vitrage 1 : surface : la valeur doit être strictement positive.");
    });

    it("donne un résultat sans aucune porte ni aucun vitrage (listes vides)", () => {
        const r = coldRoomPro({ ...CASE_2, doors: [], glazings: [] });
        expect(r).not.toBeNull();
        expect(r.lines.map((l) => l.key)).not.toContain("infiltration");
    });

    it("retire la charge d'une porte supprimée", () => {
        const two = coldRoomPro(CASE_2);
        const one = coldRoomPro({ ...CASE_2, doors: [CASE_2.doors[0]] });
        expect(w(one, "infiltration")).toBeLessThan(w(two, "infiltration"));
        expect(w(one, "infiltration")).toBeCloseTo(doorInfiltration(CASE_2.doors[0], -20, 85).totalW, 6);
    });

    it("calcule U des panneaux par λ, ou prend le U d'un modèle", () => {
        expect(panelU({ source: "generic", insulation: "PUR", thickness: 100 })).toBeCloseTo(1 / (0.17 + 0.1 / 0.023), 10);
        expect(panelU({ source: "model", u: 0.19 })).toBe(0.19);
        expect(panelU({ source: "custom_u", u: 0.25 })).toBe(0.25);
    });

    it("applique les valeurs par défaut fixées par l'utilisateur", () => {
        const full = simpleToFull(simpleDefault());
        expect(full.margin).toBe(10);
        expect(full.antiFreeze).toMatchObject({ enabled: true, fraction: 0.1, dutyPct: 30 });
        expect(full.defrost).toMatchObject({ type: "electric", fraction: 0.5 });
        expect(full.drain).toMatchObject({ fraction: 0.1 });
        expect(full.fans).toEqual({ mode: "volume", value: 2.5 });
        expect(full.refrigerant.condenserAir).toBe(35);
        expect(simpleToFull(simpleDefault({ doorProtection: "strips" })).doors[0].efficiency).toBe(0.7);
        expect(simpleToFull(simpleDefault({ regime: "positive" })).defrost.type).toBe("air");
    });
});

describe("Chambre froide v2 : fluide et règlement F-Gas", () => {
    it("propose les fluides de PRG < 150, puis de 150 à 2 500, sans CFC, HCFC ni PRG ≥ 2 500", () => {
        const { low, medium } = proposedRefrigerants(FLUIDS);
        expect(low.every(([, f]) => f.gwp < 150)).toBe(true);
        expect(medium.every(([, f]) => f.gwp >= 150 && f.gwp < 2500)).toBe(true);
        const codes = [...low, ...medium].map(([code]) => code);
        expect(codes).not.toContain("R404A");
        expect(codes).not.toContain("R22");
        expect(codes).toContain("R449A");
    });

    it("grise un fluide interdit en vigueur, avec la raison et l'article ; signale une interdiction à venir", () => {
        const context = { installation: "self_contained", capacityKw: 3, chargeTonnes: 1, today: TODAY };
        expect(isBanned(FLUIDS.R449A, RULES, context)).toBe(true);
        expect(isBanned(FLUIDS.R290, RULES, context)).toBe(false);
        const [r3] = applicableRules(FLUIDS.R449A, RULES, context).filter(({ rule }) => rule.kind === "placing");
        expect(ruleText(r3)).toBe("Interdit (Réfrigération autonome (groupe autonome, monobloc), hors refroidisseurs de liquide) depuis le 01/01/2025 pour un PRG ≥ 150 — règlement (UE) 2024/573, art. 11, paragraphe 1 (à confirmer), annexe IV, point à confirmer. Exception : Sauf si nécessaire pour les exigences de sécurité du site.");
        const split = applicableRules(FLUIDS.R449A, RULES, { ...context, installation: "split" });
        expect(split.find(({ rule }) => rule.code === "R4")).toMatchObject({ inForce: false });
        expect(isBanned(FLUIDS.R449A, RULES, { ...context, installation: "split" })).toBe(false);
        // Centrale ≥ 40 kW (R2)
        expect(isBanned(FLUIDS.R449A, RULES, { ...context, installation: "centralised", capacityKw: 50 })).toBe(true);
    });

    it("n'applique aucune règle tant qu'aucune n'est vérifiée (liste vide transmise par le site)", () => {
        const r = coldRoomSimple(simpleDefault());
        expect(r.refrigerant.rules).toEqual([]);
        expect(initialRules.rules.every((rule) => rule.verified === undefined || rule.verified === false)).toBe(true);
    });

    it("donne la périodicité des contrôles d'étanchéité (art. 5) et la détection (art. 6)", () => {
        expect(leakChecks(2, { hermetic: false, detection: false }).months).toBeNull();
        expect(leakChecks(20, { hermetic: false, detection: false }).months).toBe(12);
        expect(leakChecks(20, { hermetic: false, detection: true }).months).toBe(24);
        expect(leakChecks(8, { hermetic: true, detection: false })).toMatchObject({ months: null, exempt: true });
        // Groupe autonome non déclaré hermétiquement scellé : pas d'exemption
        expect(leakChecks(8, { hermetic: false, detection: false })).toMatchObject({ months: 12, exempt: false });
        expect(leakChecks(100, { hermetic: false, detection: false }).months).toBe(6);
        expect(leakChecks(600, { hermetic: false, detection: false })).toMatchObject({ months: 6, detectionRequired: true });
    });

    it("n'applique l'exemption des équipements hermétiquement scellés que si la case est cochée", () => {
        const heavy = { ...CASE_1, refrigerant: { ...CASE_1.refrigerant, fluid: FLUIDS.R449A, installation: "self_contained" } };
        // 0,3 kg/kW × 4,30 kW × 1 397 / 1 000 = 1,8 t : sous 5 t, aucun contrôle de toute façon ; on force la charge
        const sealed = coldRoomPro({ ...heavy, length: 20, width: 15, refrigerant: { ...heavy.refrigerant, hermetic: true } });
        const notSealed = coldRoomPro({ ...heavy, length: 20, width: 15, refrigerant: { ...heavy.refrigerant, hermetic: false } });
        expect(sealed.refrigerant.tonnes).toBeGreaterThan(5);
        expect(sealed.refrigerant.tonnes).toBeLessThan(10);
        expect(sealed.refrigerant.checks.exempt).toBe(true);
        expect(notSealed.refrigerant.checks).toMatchObject({ exempt: false, months: 12 });
    });

    it("estime la charge et les t éq. CO₂ du cas 2 (split R-455A, 15 m de liaisons)", () => {
        const r = coldRoomPro(CASE_2);
        expect(r.refrigerant.chargeKg).toBeCloseTo(0.6 * r.frigoKw + 0.05 * 15, 10);
        expect(r.refrigerant.tonnes).toBeCloseTo((r.refrigerant.chargeKg * 148) / 1000, 10);
    });

    it("donne les limites NF EN 378-1 des fluides inflammables (A3, A2L), rien pour un A1", () => {
        const r290 = flammabilityLimits(FLUIDS.R290, 60);
        expect(r290.practicalLimit).toBeCloseTo(0.0076, 10);
        expect(r290.roomLimit).toBeCloseTo(0.456, 10);
        expect(r290.m1).toBeCloseTo(0.152, 10);
        expect(flammabilityLimits(FLUIDS.R455A, 90).lfl).toBe(0.431);
        expect(flammabilityLimits(FLUIDS.R744, 45)).toBeNull();
        expect(flammabilityLimits(FLUIDS.R454A, 45)).toEqual({ missing: true });
    });

    it("R-290 en groupe autonome : 0,15 kg/kW et limite par circuit IEC 60335-2-89 (13 × LFL), sans avertissement dans le cas par défaut", () => {
        expect(defaultChargeRatio("self_contained", "R290")).toBe(0.15);
        expect(defaultChargeRatio("self_contained", "R600a")).toBe(0.3);
        expect(defaultChargeRatio("split", "R290")).toBe(0.6);
        const sealed = { fluid: FLUIDS.R290, fluidCode: "R290", installation: "self_contained", hermetic: true, rules: [], today: TODAY };
        const r = coldRoomSimple(simpleDefault({ refrigerant: sealed })).refrigerant;
        expect(r.kgPerKw).toBe(0.15);
        expect(r.chargeKg).toBeCloseTo(0.25, 2);
        expect(r.productStandard.circuitLimit).toBeCloseTo(13 * 0.038, 10);
        expect(r.chargeKg).toBeLessThan(r.productStandard.circuitLimit);
        // Le même groupe, non déclaré hermétiquement scellé : contrôle NF EN 378-1 du local
        expect(productStandardLimit(FLUIDS.R290, { installation: "self_contained", hermetic: false })).toBeNull();
        // Ratio saisi en mode pro : prioritaire
        const entered = coldRoomPro({ ...CASE_1, refrigerant: { ...CASE_1.refrigerant, fluidCode: "R290", chargeRatio: 0.2 } }).refrigerant;
        expect(entered.chargeKg).toBeCloseTo(0.2 * coldRoomPro(CASE_1).frigoKw, 10);
    });

    it("R-290 en split : contrôle NF EN 378-1 du local (20 % de la LFL × volume), dépassé dans le cas par défaut", () => {
        const r = coldRoomSimple(simpleDefault({ refrigerant: { fluid: FLUIDS.R290, fluidCode: "R290", installation: "split", hermetic: false, rules: [], today: TODAY } })).refrigerant;
        expect(r.productStandard).toBeNull();
        expect(r.chargeKg).toBeGreaterThan(r.flammability.roomLimit);
        // Un A2L (R-32) en groupe autonome scellé reste au contrôle NF EN 378-1
        expect(productStandardLimit(FLUIDS.R32, { installation: "self_contained", hermetic: true })).toBeNull();
    });

    it("estime le COP par la fraction de Carnot", () => {
        expect(estimatedCop(-18, 32).cop).toBeCloseTo((0.45 * 247.15) / 68, 10);
        expect(coldRoomPro({ ...CASE_1, refrigerant: { ...CASE_1.refrigerant, copOverride: 3 } }).cop).toBe(3);
    });
});

describe("Chambre froide v2 : non-régression (méthodes inchangées depuis l'ancien site)", () => {
    it("garde la chaleur des produits de C12 (L = teneur en eau × 334 kJ/kg)", () => {
        const meat = { ...PRODUCT_PROFILES.meat };
        expect(freezingHeat(70)).toBeCloseTo(233.8, 6);
        expect(productEnergyPerKg(meat, 8, -18, true)).toBeCloseTo(294.18, 6);
        expect(productEnergyPerKg(meat, 8, 2, false)).toBeCloseTo(19.2, 6);
        expect(productEnergyPerKg(meat, -15, -18, false)).toBeCloseTo(5.4, 6);
        expect(freezesInRoom(meat, 8, 0, true)).toBe(false);
        expect(freezesInRoom(meat, 8, -18, true)).toBe(true);
    });

    it("garde la transmission des portes, l'éclairage et la charge des produits de l'ancien site (cas par défaut)", () => {
        // Valeurs exactes de la bibliothèque v1 (cas par défaut, corrigé par C12), affichées 0,06 / 0,05 / 0,07 kW
        // dans golden/thermodynamique.json : portes 1,4076 W/K × 43 K, éclairage 8 W/m² × 12 m² × 12 h / 24,
        // produits 500 kg × 1,9 × 3 K sur 12 h
        const V1 = { doorTransmissionW: 60.5268, lightingW: 48, productW: (500 * 1.9 * 3 * 1000) / (12 * 3600) };
        const r = coldRoomSimple(simpleDefault());
        expect(w(r, "doorTransmission")).toBeCloseTo(V1.doorTransmissionW, 6);
        expect(w(r, "lighting")).toBeCloseTo(V1.lightingW, 6);
        expect(w(r, "products")).toBeCloseTo(V1.productW, 6);
    });

    it("garde le renouvellement d'air de l'ancien site (C3) en chambre positive", () => {
        // Ancien site, cas « positif, 100 m³/h, 60 % / 85 % » : sensible 0,77 kW, latent 0,68 kW (C3)
        const r = coldRoomSimple(simpleDefault({ regime: "positive", airRenewal: 100, airRhExt: 60 }));
        const air = r.lines.find((l) => l.key === "airRenewal");
        expect(air.sensibleW / 1000).toBeCloseTo(0.77, 2);
        expect(air.latentW / 1000).toBeCloseTo(0.68, 2);
    });

    it("calcule le bilan complet d'un appel direct (sans contrôle des saisies)", () => {
        expect(coldRoom(CASE_1).totalW).toBeCloseTo(coldRoomPro(CASE_1).totalW, 10);
    });
});
