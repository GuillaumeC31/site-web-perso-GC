/*
 * Non-régression des outils Impression 3D (steps/mm, coût d'impression) et du Convertisseur.
 *
 * Les cas de golden/impression-3d.json et golden/convertisseur.json (résultats de l'ancien site)
 * sont tous rejoués ; un cas modifié par une correction décidée reçoit sa nouvelle valeur dans
 * CORRECTIONS, avec la source en commentaire. Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import printing from "./golden/impression-3d.json";
import converter from "./golden/convertisseur.json";
import { DECIMALS, explain, printCost, stepsPerMm } from "./impression3d.js";
import { convert, displayRounding, INCOMPATIBLE } from "./convertisseur.js";
import { parseNumber, roundTo } from "./format.js";

const CORRECTIONS = [
    // A3 (règle étendue le 2026-10-22) : distance obtenue négative refusée (ancien : -94.42).
    { tool: "steps-mm", inputs: { oldSteps: 93, expectedDist: 100, actualDist: -98.5 }, outputs: { resultSteps: "" } },
    // A3 : poids négatif refusé (ancien : coût -2.85 €).
    { tool: "cout-impression", inputs: { weightPart: -150, priceSpool: 20, timePrintHours: 4, timePrintMinutes: 0, powerPrint: 150, priceKwh: 0.25 }, outputs: { resultCost: "" } },
];

const sameInputs = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const expectedOutputs = (row) => CORRECTIONS.find((c) => c.tool === row.tool && sameInputs(c.inputs, row.inputs))?.outputs ?? row.outputs;
const n = (value) => parseNumber(value);

function expectSame(value, expected, digits, label) {
    if (expected === "") {
        expect(value === null || value === undefined || !Number.isFinite(value), `${label} : aucun résultat attendu, obtenu ${value}`).toBe(true);
        return;
    }
    expect(roundTo(value, digits), label).toBe(Number(expected));
}

describe("Calibrage des steps/mm", () => {
    for (const row of printing.results.filter((r) => r.tool === "steps-mm")) {
        it(JSON.stringify(row.inputs), () => {
            const x = row.inputs;
            expectSame(stepsPerMm(n(x.oldSteps), n(x.expectedDist), n(x.actualDist)), expectedOutputs(row).resultSteps, DECIMALS.steps, "steps/mm");
        });
    }
});

describe("Coût d'une impression 3D", () => {
    for (const row of printing.results.filter((r) => r.tool === "cout-impression")) {
        it(JSON.stringify(row.inputs), () => {
            const x = row.inputs;
            const result = printCost(n(x.weightPart), n(x.priceSpool), n(x.timePrintHours), n(x.timePrintMinutes), n(x.powerPrint), n(x.priceKwh));
            expectSame(result?.total ?? null, expectedOutputs(row).resultCost, DECIMALS.cost, "coût");
        });
    }

    it("explique les saisies refusées (A3)", () => {
        expect(explain("printCost", -150, 20, 4, 0, 150, 0.25)).toBe("Poids de la pièce : la valeur doit être strictement positive.");
        expect(explain("printCost", 150, 20, Number.NaN, 0, 150, 0.25)).toBe("Indiquez la durée d'impression.");
    });
});

describe("Convertisseur d'unités", () => {
    for (const row of converter.results) {
        const [{ set: type }, , { set: values }] = row.steps;
        it(`${type.convType} ${JSON.stringify(values)}`, () => {
            const result = convert(type.convType, n(values.convValIn), values.convUnitIn, values.convUnitOut);
            const expected = row.outputs.convValOut;
            if (expected === "") {
                expect(result).toBeNull();
            } else if (expected.startsWith("N/A")) {
                expect(result).toEqual({ error: INCOMPATIBLE });
            } else {
                expect(displayRounding(type.convType, values.convUnitIn, values.convUnitOut, result.value).value).toBe(Number(expected));
            }
        });
    }
});

describe("Valeurs de référence", () => {
    it("chaque correction correspond à un cas capturé", () => {
        for (const correction of CORRECTIONS) {
            expect(printing.results.some((r) => r.tool === correction.tool && sameInputs(r.inputs, correction.inputs)), JSON.stringify(correction.inputs)).toBe(true);
        }
    });
});
