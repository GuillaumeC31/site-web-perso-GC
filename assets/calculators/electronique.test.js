/*
 * Non-régression des outils Électronique (résistance pour LED, pont diviseur, autonomie).
 *
 * Les cas de golden/electronique.json (résultats de l'ancien site) sont tous rejoués ; un cas
 * modifié par une correction décidée (docs/CALCULATRICE_ANOMALIES.md) reçoit sa nouvelle valeur
 * dans CORRECTIONS, avec la source en commentaire. Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import golden from "./golden/electronique.json";
import { batteryAutonomy, DECIMALS, dividerMissingResistor, explain, formatResistance, ledResistor, powerLabel, recommendedPowerRating, voltageDivider } from "./electronique.js";
import { parseNumber, roundTo } from "./format.js";

const CORRECTIONS = [
    // B1 (2026-10-22) : puissance conseillée = valeur normalisée ≥ 2 × P (POWER_RATINGS). 0,176 W × 2 = 0,352 → 1/2 W (ancien : 1/4 W).
    { tool: "resistance-led", method: "calculateLed", inputs: { vAlimLed: 12, vLed: 3.2, iLed: 20 }, outputs: { resultLed: "440.0", resultE24: "470 ", resultPowerLed: "0.176", powerAdvice: "1/2 W (0.5W)" } },
    // B1 : 0,438 W × 2 = 0,876 → 1 W (ancien : 1/2 W).
    { tool: "resistance-led", method: "calculateLed", inputs: { vAlimLed: 24, vLed: 2.1, iLed: 20 }, outputs: { resultLed: "1095.0", resultE24: "1.1 k", resultPowerLed: "0.438", powerAdvice: "1 W" } },
    // B1 : 7,28 W × 2 = 14,56 → 15 W, résistance de puissance (ancien : 8 W).
    { tool: "resistance-led", method: "calculateLed", inputs: { vAlimLed: 24, vLed: 3.2, iLed: 350 }, outputs: { resultLed: "59.4", resultE24: "62 ", resultPowerLed: "7.280", powerAdvice: "15 W (Résistance de puissance)" } },
    // B1 : 4,536 W × 2 = 9,072 → 10 W, résistance de puissance (ancien : 5 W).
    { tool: "resistance-led", method: "calculateLed", inputs: { vAlimLed: 230, vLed: 3.2, iLed: 20 }, outputs: { resultLed: "11340.0", resultE24: "12.0 k", resultPowerLed: "4.536", powerAdvice: "10 W (Résistance de puissance)" } },
    // B2 (2026-10-22, affichage) : R = 4,0 Ω → valeur E24 supérieure 4,3 Ω, que l'ancien site affichait « 4 » (toFixed(0)).
    // Conseil inchangé par B1 : 0,04 W × 2 = 0,08 → 1/8 W.
    { tool: "resistance-led", method: "calculateLed", inputs: { vAlimLed: 3.6, vLed: 3.2, iLed: 100 }, outputs: { resultLed: "4.0", resultE24: "4.3 ", resultPowerLed: "0.040", powerAdvice: "1/8 W (0.125W)" } },
    // A3 (règle étendue à toutes les catégories le 2026-10-22) : résistance négative refusée (ancien : 5.56 V).
    { tool: "diviseur-tension", method: "calculateDiv", inputs: { vInDiv: 5, r1Div: -1000, r2Div: 10000 }, outputs: { resultDiv: "", resultDivCurrent: "" } },
    // A3 : consommation négative refusée (ancien : -28.0 h).
    { tool: "autonomie-batterie", method: "calculateBat", inputs: { capBat: 2000, iBat: -50 }, outputs: { resultBat: "" } },
];

const sameInputs = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const expectedOutputs = (row) => CORRECTIONS.find((c) => c.tool === row.tool && c.method === row.method && sameInputs(c.inputs, row.inputs))?.outputs ?? row.outputs;
const n = (value) => parseNumber(value);
const cases = (tool) => golden.results.filter((row) => row.tool === tool);

/** Compare une valeur calculée à l'affichage de l'ancien site ("" : aucun résultat). */
function expectSame(value, expected, digits, label) {
    if (expected === "") {
        expect(value === null || value === undefined || !Number.isFinite(value), `${label} : aucun résultat attendu, obtenu ${value}`).toBe(true);
        return;
    }
    expect(roundTo(value, digits), label).toBe(Number(expected));
}

/** Lit la valeur E24 affichée par l'ancien site (« 150 », « 1.1 k »). */
const parseE24 = (text) => (text.trim().endsWith("k") ? Number.parseFloat(text) * 1000 : Number.parseFloat(text));

/** Lit le conseil de puissance de l'ancien site (« 1/8 W (0.125W) », « 8 W (Résistance de puissance) »). */
function parseAdvice(text) {
    const fraction = /^1\/(\d) W/.exec(text);

    return { watts: fraction ? 1 / Number(fraction[1]) : Number.parseFloat(text), powerResistor: text.includes("puissance") };
}

describe("Résistance pour LED", () => {
    for (const row of cases("resistance-led")) {
        it(JSON.stringify(row.inputs), () => {
            const x = row.inputs;
            const expected = expectedOutputs(row);
            const result = ledResistor(n(x.vAlimLed), n(x.vLed), n(x.iLed));
            if (expected.resultLed === "") {
                expect(result).toBeNull();
                return;
            }
            expectSame(result.resistance, expected.resultLed, DECIMALS.ledResistance, "R");
            expect(roundTo(result.e24, 6), "E24").toBe(parseE24(expected.resultE24));
            expectSame(result.power, expected.resultPowerLed, DECIMALS.ledPower, "P");
            expect(result.rating, "conseil").toEqual(parseAdvice(expected.powerAdvice));
        });
    }

    it("affiche la valeur E24 avec ses décimales (B2)", () => {
        expect(formatResistance(4.3)).toBe("4,3 Ω");
        expect(formatResistance(470)).toBe("470 Ω");
        expect(formatResistance(1100)).toBe("1,1 kΩ");
        expect(formatResistance(12000)).toBe("12 kΩ");
    });

    it("conseille une puissance avec une marge de 2 (B1)", () => {
        expect(recommendedPowerRating(0.0625)).toEqual({ watts: 0.125, powerResistor: false });
        expect(recommendedPowerRating(0.1)).toEqual({ watts: 0.25, powerResistor: false });
        expect(recommendedPowerRating(1.2)).toEqual({ watts: 3, powerResistor: true });
        expect(recommendedPowerRating(60)).toEqual({ watts: 120, powerResistor: true });
        expect(powerLabel(0.25)).toBe("1/4 W (0,25 W)");
        expect(powerLabel(15)).toBe("15 W");
    });

    it("explique une alimentation trop faible (A3)", () => {
        expect(explain("ledResistor", 2, 3.2, 20)).toBe("L'alimentation doit être supérieure à la tension de la LED.");
        expect(explain("ledResistor", 5, 2, 0)).toBe("Courant de la LED : la valeur doit être strictement positive.");
    });
});

describe("Pont diviseur de tension", () => {
    for (const row of cases("diviseur-tension")) {
        it(`${row.method} ${JSON.stringify(row.inputs)}`, () => {
            const x = row.inputs;
            const expected = expectedOutputs(row);
            if (row.method === "calculateDiv") {
                const result = voltageDivider(n(x.vInDiv), n(x.r1Div), n(x.r2Div));
                expectSame(result?.vOut ?? null, expected.resultDiv, DECIMALS.dividerVoltage, "Vout");
                expectSame(result?.currentMilliAmps ?? null, expected.resultDivCurrent, DECIMALS.dividerCurrent, "I");
            } else {
                const result = dividerMissingResistor(n(x.vInDivCalc), n(x.vOutDivCalc), x.fixedResType, n(x.fixedResVal));
                expectSame(result?.resistance ?? null, expected.resultDivRes, DECIMALS.dividerResistance, "R");
                expectSame(result?.currentMilliAmps ?? null, expected.resultDivCalcCurrent, DECIMALS.dividerCurrent, "I");
            }
        });
    }

    it("explique les saisies refusées (A3)", () => {
        expect(explain("voltageDivider", 5, -1000, 10000)).toBe("Résistance R1 : la valeur ne peut pas être négative.");
        expect(explain("voltageDivider", 5, 0, 0)).toBe("R1 et R2 ne peuvent pas être nulles toutes les deux.");
        expect(explain("dividerMissingResistor", 5, 6, "R1", 10000)).toBe("La tension de sortie doit être inférieure à la tension d'entrée.");
    });
});

describe("Autonomie d'une batterie", () => {
    for (const row of cases("autonomie-batterie")) {
        it(JSON.stringify(row.inputs), () => {
            expectSame(batteryAutonomy(n(row.inputs.capBat), n(row.inputs.iBat)), expectedOutputs(row).resultBat, DECIMALS.batteryHours, "T");
        });
    }
});

describe("Valeurs de référence", () => {
    it("chaque correction correspond à un cas capturé", () => {
        for (const correction of CORRECTIONS) {
            expect(golden.results.some((r) => r.tool === correction.tool && r.method === correction.method && sameInputs(r.inputs, correction.inputs)), JSON.stringify(correction.inputs)).toBe(true);
        }
    });
});
