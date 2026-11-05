/*
 * Non-régression des outils Capteurs et mesures : chaque cas de golden/mesures-capteurs.json
 * (résultats de l'ancien site) est rejoué ; un cas modifié par une correction décidée
 * (docs/CALCULATRICE_ANOMALIES.md) reçoit sa nouvelle valeur dans CORRECTIONS, avec la source en
 * commentaire. Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import golden from "./golden/mesures-capteurs.json";
import { DECIMALS, explainRange, isOutsidePtRange, OUT_OF_RANGE, physicalToSignal, ptResistance, ptTable, ptTemperature, scalingTable, shuntVoltage, signalAlert, signalToPhysical } from "./capteurs.js";
import { parseNumber, roundTo } from "./format.js";

const CORRECTIONS = [
    // B5 (2026-10-22) : bornes de la CEI 60751 incluses ; 18,52 Ω (valeur affichée pour -200 °C) donne -200 °C (ancien : « Hors plage »).
    { tool: "pt100", method: "calculateTempFromResistance", inputs: { resistance: 18.52 }, outputs: { temperature: "-200.00", "!alertRange": "masqué" } },
];

const sameCase = (a, b) => a.tool === b.tool && a.method === b.method && JSON.stringify(a.inputs) === JSON.stringify(b.inputs);
const expectedOutputs = (row) => CORRECTIONS.find((correction) => sameCase(correction, row))?.outputs ?? row.outputs;
const n = (value) => parseNumber(value);
const cases = (tool) => golden.results.filter((row) => row.tool === tool);
const near = (value, expected, digits) => expect(roundTo(value, digits)).toBe(Number(expected));

describe("Sondes PT100 et PT1000", () => {
    for (const [tool, r0] of [["pt100", 100], ["pt1000", 1000]]) {
        for (const row of cases(tool)) {
            it(`${tool} ${row.method} ${JSON.stringify(row.inputs)}`, () => {
                if (row.method === "calculateResistanceFromTemp") {
                    const t = n(row.inputs.temperature);
                    if (Number.isNaN(t)) {
                        expect(row.outputs.resistance).toBe("");
                        return;
                    }
                    near(ptResistance(t, r0), row.outputs.resistance, DECIMALS.resistance);
                    expect(isOutsidePtRange(t) ? "visible" : "masqué").toBe(row.outputs["!alertRange"]);
                    return;
                }

                const r = n(row.inputs.resistance);
                const expected = expectedOutputs(row);
                if (Number.isNaN(r)) {
                    expect(expected.temperature).toBe("");
                    return;
                }
                const t = ptTemperature(r, r0);
                if (expected.temperature === OUT_OF_RANGE) {
                    expect(t).toBeNull();
                    expect(expected["!alertRange"]).toBe("visible");
                } else {
                    near(t, expected.temperature, DECIMALS.temperature);
                    expect(expected["!alertRange"]).toBe("masqué");
                }
            });
        }
    }

    it("inclut les bornes -200 et 850 °C (B5)", () => {
        expect(ptTemperature(18.52, 100)).toBe(-200);
        expect(ptTemperature(185.2, 1000)).toBe(-200);
        expect(roundTo(ptTemperature(390.48, 100), 2)).toBe(850);
        expect(ptTemperature(18.51, 100)).toBeNull();
        expect(ptTemperature(390.485, 100)).toBe(850);
        expect(ptTemperature(390.49, 100)).toBeNull();
        expect(isOutsidePtRange(-200)).toBe(false);
        expect(isOutsidePtRange(850)).toBe(false);
    });

    it("chaque correction correspond à un cas capturé", () => {
        for (const correction of CORRECTIONS) {
            expect(golden.results.some((row) => sameCase(correction, row)), JSON.stringify(correction.inputs)).toBe(true);
        }
    });

    it("construit le tableau de référence (pas, plage, écart)", () => {
        const rows = ptTable(100, 10, "common");
        expect(rows).toHaveLength(26);
        expect(rows[0].t).toBe(-50);
        expect(rows[0].delta).toBeNull();
        expect(roundTo(rows[5].r, 2)).toBe(100);
        expect(ptTable(1000, 50, "full").at(-1).t).toBe(850);
    });
});

describe("Mise à l'échelle des signaux et transmetteurs", () => {
    const tools = ["signal-4-20ma", "signal-0-20ma", "signal-0-10v", "transmetteur-humidite", "transmetteur-temperature"];
    const bounds = {
        "signal-4-20ma": { minS: 4, maxS: 20, minP: 0, maxP: 100, unit: "mA" },
        "signal-0-20ma": { minS: 0, maxS: 20, minP: 0, maxP: 100, unit: "mA" },
        "signal-0-10v": { minS: 0, maxS: 10, minP: 0, maxP: 100, unit: "V" },
        "transmetteur-humidite": { minS: 4, maxS: 20, minP: 0, maxP: 100, unit: "mA" },
        "transmetteur-temperature": { minS: 4, maxS: 20, minP: -20, maxP: 80, unit: "mA" },
    };
    const outputTypes = { "4-20": { minS: 4, maxS: 20, unit: "mA" }, "0-10": { minS: 0, maxS: 10, unit: "V" }, "0-20": { minS: 0, maxS: 20, unit: "mA" } };

    for (const tool of tools) {
        for (const row of cases(tool)) {
            it(`${tool} ${JSON.stringify(row.inputs ?? row.steps)}`, () => {
                let { minS, maxS, minP, maxP, unit } = bounds[tool];
                let inputs = row.inputs;
                let method = row.method;
                if (row.steps) {
                    // Scénario : changement de type de sortie, puis saisie du signal
                    const type = outputTypes[row.steps[0].set.outputType];
                    ({ minS, maxS, unit } = type);
                    inputs = row.steps[2].set;
                    method = "calculatePhysical";
                    expect(row.outputs["!shuntCard"]).toBe(unit === "mA" ? "visible" : "masqué");
                    if ("minSignal" in row.outputs) {
                        expect(Number(row.outputs.minSignal)).toBe(minS);
                        expect(Number(row.outputs.maxSignal)).toBe(maxS);
                    }
                }
                if ("minPhysical" in inputs) {
                    minP = n(inputs.minPhysical);
                    maxP = n(inputs.maxPhysical);
                }

                const signal = method === "calculatePhysical" ? n(inputs.signal) : physicalToSignal(n(inputs.physical), minS, maxS, minP, maxP);
                if (method === "calculatePhysical") {
                    const physical = signalToPhysical(signal, minS, maxS, minP, maxP);
                    if (row.outputs.physical === "") {
                        expect(physical).toBeNull();
                        return;
                    }
                    near(physical, row.outputs.physical, DECIMALS.physical);
                } else {
                    near(signal, row.outputs.signal, DECIMALS.signal);
                }

                if ("shuntVoltage" in row.outputs) {
                    expect(unit === "mA" ? roundTo(shuntVoltage(signal), DECIMALS.shunt) : "").toBe(unit === "mA" ? Number(row.outputs.shuntVoltage) : row.outputs.shuntVoltage);
                }
                if ("alertSignal" in row.outputs) {
                    expect(signalAlert(signal, minS, maxS) ?? "").toBe(row.outputs.alertSignal);
                }
            });
        }
    }

    it("construit le tableau de référence et signale une plage inutilisable (A3)", () => {
        const rows = scalingTable(4, 20, 0, 100, 4);
        expect(rows.map((row) => row.signal)).toEqual([4, 8, 12, 16, 20]);
        expect(rows.map((row) => roundTo(row.percent, 1))).toEqual([0, 25, 50, 75, 100]);
        expect(scalingTable(4, 20, 50, 50, 1)).toEqual([]);
        expect(explainRange(4, 20, 50, 50)).toBe("Les valeurs min et max de la plage doivent être différentes.");
        expect(explainRange(4, 20, 0, 100)).toBeNull();
    });
});
