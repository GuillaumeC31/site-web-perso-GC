/*
 * Non-régression de la Terrariophilie : chaque scénario de golden/terrariophilie.json (ancien formulaire
 * avec ses valeurs par défaut) est rejoué ; un cas modifié par une correction décidée
 * (docs/CALCULATRICE_ANOMALIES.md) reçoit sa nouvelle valeur dans CORRECTIONS, avec la source en
 * commentaire. Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import golden from "./golden/terrariophilie.json";
import { cases } from "../../scripts/calculator/cases/terrariophilie.mjs";
import { explain, FACES, FERGUSON_ZONES, heatingByWalls, heatingPower, misting, pond, substrate, terrariumDli, ventilation, wallU } from "./terrariophilie.js";
import { parseNumber } from "./format.js";

/** Nouvelles valeurs attendues (null : aucun résultat, saisie refusée). */
const CORRECTIONS = [
    // A3 (règle étendue à toutes les catégories le 2026-10-22) : longueur négative ou vide → aucun résultat (ancien : « 0 W »).
    { tool: "chauffage-terrarium", set: { length: -90 }, outputs: null },
    { tool: "chauffage-terrarium", set: { length: "" }, outputs: null },
    // A3 : nombre de buses négatif ou décimal refusé, débit vide → aucun résultat (ancien : -0.07 L ; 0.08 L ; 0.00 L).
    { tool: "brumisation", set: { nozzles: -2 }, outputs: null },
    { tool: "brumisation", set: { nozzles: 2.5 }, outputs: null },
    { tool: "brumisation", set: { flow: "" }, outputs: null },
    // A3 : épaisseur nulle ou largeur négative refusée (ancien : 0.0 L et -72.0 L).
    { tool: "volume-substrat", set: { depth: 0 }, outputs: null },
    { tool: "volume-substrat", set: { width: -60 }, outputs: null },
    // A3 : volume nul ou négatif refusé (ancien : 0.0 et -1.0 m³/h).
    { tool: "ventilation-air", set: { volume: 0 }, outputs: null },
    { tool: "ventilation-air", set: { volume: -250 }, outputs: null },
    // A3 : hauteur d'eau nulle ou longueur négative refusée (ancien : 0.0 L et -180.0 L).
    { tool: "bassin-paludarium", set: { depth: 0 }, outputs: null },
    { tool: "bassin-paludarium", set: { length: -120 }, outputs: null },
    // A3 : plus de 24 h par jour, PPFD négatif ou vide refusés (ancien : DLI 10.8, -4.3 et 0.0).
    { tool: "eclairage-reptiles", set: { ppfdInput: 100, hoursInput: 30 }, outputs: null },
    { tool: "eclairage-reptiles", set: { ppfdInput: -100 }, outputs: null },
    { tool: "eclairage-reptiles", set: { ppfdInput: "" }, outputs: null },
];

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const n = (value) => parseNumber(String(value));
/** Valeurs finales du formulaire : défaut de l'ancien formulaire, puis saisies. */
const form = (row) => Object.assign({ ...cases.find((c) => c.tool === row.tool).initial }, ...row.steps.map((step) => step.set ?? {}));
const correction = (row) => CORRECTIONS.find((c) => c.tool === row.tool && row.steps.some((step) => same(step.set, c.set)));
const rows = (tool) => golden.results.filter((row) => row.tool === tool);
/** Formats de l'ancien affichage. */
const fixed = (value, digits) => value.toFixed(digits);

/** Rejoue un outil : calcul, puis comparaison avec l'ancien affichage (ou la correction). */
function replay(tool, compute) {
    for (const row of rows(tool)) {
        it(JSON.stringify(row.steps), () => {
            const fix = correction(row);
            const result = compute(form(row));
            if (fix) {
                expect(result, fix.tool).toBeNull();
                return;
            }
            expect(result).not.toBeNull();
            for (const [name, value] of Object.entries(result).filter(([key]) => key in row.outputs)) {
                expect(value, name).toBe(row.outputs[name]);
            }
        });
    }
}

describe("Puissance de chauffage, estimation rapide (empirique, ancien site)", () => {
    replay("chauffage-terrarium", (x) => {
        const result = heatingPower(n(x.length), n(x.width), n(x.height), x.material, n(x.tempTarget), n(x.tempRoom));
        return result && { resultPower: `${result.recommended} W` };
    });
});

describe("Chauffage par les parois (D5)", () => {
    it("calcule U = 1 / (0,17 + e/λ) pour chaque matériau", () => {
        expect(wallU("glass")).toBeCloseTo(5.747, 3);
        expect(wallU("osb")).toBeCloseTo(3.242, 3);
        expect(wallU("pvc")).toBeCloseTo(2.970, 2);
        expect(wallU("xps")).toBeCloseTo(1.319, 3);
    });

    it("retrouve le cas calculé à la main (90 × 45 × 60 cm tout verre, 20 → 35 °C, 1 vol/h, sécurité 1,2)", () => {
        // Surfaces : 2 × 0,54 + 2 × 0,27 + 2 × 0,405 = 2,43 m² ; Σ(U × S) = 5,747 × 2,43 = 13,966 W/K ; × 15 K = 209,5 W
        // Air : 0,243 m³ × 1 /h = 0,243 m³/h ; 0,34 × 0,243 × 15 = 1,24 W ; total × 1,2 = 252,9 W → 275 W
        const result = heatingByWalls(90, 45, 60, 35, 20, FACES.map(() => wallU("glass")), 1, 1.2);
        expect(result.conduction).toBeCloseTo(209.49, 1);
        expect(result.ventilation).toBeCloseTo(1.239, 3);
        expect(result.watts).toBeCloseTo(252.88, 1);
        expect(result.recommended).toBe(275);
    });

    it("ne chauffe pas si la pièce est assez chaude, et refuse un U nul (A3)", () => {
        expect(heatingByWalls(90, 45, 60, 20, 22, FACES.map(() => 5.7), 1, 1.2).recommended).toBe(0);
        expect(explain("walls", 90, 45, 60, 35, 20, [0, 5.7, 5.7, 5.7, 5.7, 5.7], 1, 1.2)).toBe("Coefficient U (façade) : la valeur doit être strictement positive.");
    });
});

describe("Brumisation", () => {
    replay("brumisation", (x) => {
        const result = misting(n(x.nozzles), n(x.flow), n(x.duration), n(x.frequency));
        return result && { resDay: fixed(result.day, 2), resWeek: fixed(result.week, 1), resTank: String(result.tank) };
    });
});

describe("Volume de substrat", () => {
    replay("volume-substrat", (x) => {
        const result = substrate(n(x.length), n(x.width), n(x.depth), x.mixType);
        if (!result) {
            return null;
        }
        // Recette de l'ancien site, texte sans les pictogrammes ni la mise en forme
        const text = result.parts.map((part) => `${Math.round(part.share * 100)}% ${part.label} : ${fixed(part.litres, 1)} L`).join(" ");
        return { resultLiters: fixed(result.litres, 1), mixRecipe: text };
    });

    it("garde les parts et les litres de l'ancienne recette", () => {
        for (const row of rows("volume-substrat").filter((r) => !correction(r))) {
            const x = form(row);
            const result = substrate(n(x.length), n(x.width), n(x.depth), x.mixType);
            for (const part of result.parts) {
                expect(row.outputs.mixResult, part.label).toContain(`${Math.round(part.share * 100)}%`);
                expect(row.outputs.mixResult, part.label).toContain(`${fixed(part.litres, 1)} L`);
            }
        }
    });
});

describe("Renouvellement d'air", () => {
    replay("ventilation-air", (x) => {
        const result = ventilation(n(x.volume), n(x.turnover));
        return result && { resM3h: fixed(result.m3h, 1), resCfm: fixed(result.cfm, 1) };
    });
});

describe("Bassin de paludarium", () => {
    replay("bassin-paludarium", (x) => {
        const result = pond(n(x.length), n(x.width), n(x.depth));
        return result && { resLiters: fixed(result.litres, 1), resWeight: fixed(result.weight, 1), resFilter: fixed(result.filter, 0) };
    });
});

describe("Éclairage et zones de Ferguson", () => {
    // Zones : mêmes valeurs, écrites à la française (virgule décimale, tiret demi-cadratin)
    const french = (text) => text.replace(/(\d)\.(\d)/g, "$1,$2").replace(" - ", " – ").replace("Non reco.", "Non recommandé");
    for (const row of rows("eclairage-reptiles").filter((r) => r.steps.some((s) => s.call === "updateZone"))) {
        it(`zone ${form(row).animalSelect}`, () => {
            const zone = FERGUSON_ZONES[form(row).animalSelect];
            expect(zone.name).toBe(row.outputs.zoneDisplay);
            expect(zone.avg).toBe(french(row.outputs.uviAvgDisplay));
            expect(zone.max).toBe(french(row.outputs.uviMaxDisplay));
            expect([zone.t56, zone.t512, zone.t514]).toEqual([row.outputs.distT56, row.outputs.distT512, row.outputs.distT514].map(french));
        });
    }
    const dliRows = rows("eclairage-reptiles").filter((r) => r.steps.some((s) => s.call === "calcDli"));
    for (const row of dliRows) {
        it(JSON.stringify(row.steps), () => {
            const x = form(row);
            const result = terrariumDli(n(x.ppfdInput), n(x.hoursInput));
            if (correction(row)) {
                expect(result).toBeNull();
                return;
            }
            expect(fixed(result, 1)).toBe(row.outputs.dliResult);
        });
    }

    it("explique les saisies refusées (A3)", () => {
        expect(explain("dli", 100, 30)).toBe("Heures par jour : la valeur doit être comprise entre 0 et 24 h.");
        expect(explain("misting", 2.5, 2, 15, 4)).toBe("Nombre de buses : la valeur doit être un nombre entier, au moins 1.");
    });
});

describe("Valeurs de référence", () => {
    it("chaque correction correspond à un scénario capturé", () => {
        for (const fix of CORRECTIONS) {
            expect(golden.results.some((row) => row.tool === fix.tool && correction(row) === fix), JSON.stringify(fix.set)).toBe(true);
        }
    });
});
