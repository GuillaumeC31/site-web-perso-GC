/*
 * Non-régression de la librairie de psychrométrie : chaque appel capturé sur l'ancien site
 * (golden/thermodynamique.json, outil « psychrometrie ») est rejoué ; un appel modifié par une
 * correction décidée (docs/CALCULATRICE_ANOMALIES.md) reçoit sa nouvelle valeur dans CORRECTIONS,
 * avec la source en commentaire. Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import golden from "./golden/thermodynamique.json";
import * as psychrometrics from "./psychrometrics.js";

const CORRECTIONS = [
    // C4 (2026-10-23) : point de rosée avec les constantes de Bolton 17,67 / 243,5 °C (ancien : 17,27 / 237,7 → 16,684 ; 9,254 ; 3,498 °C).
    { fn: "dewPoint", args: [25, 60], result: 16.7054 },
    { fn: "dewPoint", args: [20, 50], result: 9.2701 },
    { fn: "dewPoint", args: [5, 90], result: 3.497 },
    // C3 (2026-10-23) : Q = ρ × qv / 3600 × Δw × 2 501 kJ/kg, Δw en kg/kg d'air sec (ancien : débit massique × Δ humidité par m³ × 2 500 kJ/kg → 757,3 W et 869,4 W).
    { fn: "latentDehumidPowerW", args: [100, 25, 60, 2, 85], result: 682.2842 },
    { fn: "latentDehumidPowerW", args: [100, 25, 50, -18, 85], result: 758.3649 },
    // C8 (2026-10-23) : eau à retirer = Δ humidité absolue × volume, sans × 1,2 ; Lv = 2 501 kJ/kg ; durée par défaut 60 min (ancien latent : 23,02 W et 38,38 W).
    { fn: "dehumidRefrigPowerW", args: [6, 25, 60, 40, 15], result: { sensible: 20.1, latent: 19.1908, total: 39.2908, waterGrams: 27.6237, dewPoint: 10.4776 } },
    { fn: "dehumidRefrigPowerW", args: [6, 40, 75, 60, 30], result: { sensible: 20.1, latent: 31.9935, total: 52.0935, waterGrams: 46.0522, dewPoint: 30.7686 } },
];

const sameCall = (a, b) => a.fn === b.fn && JSON.stringify(a.args) === JSON.stringify(b.args);
const rows = golden.results.filter((r) => r.tool === "psychrometrie");

/** Compare à 4 décimales (nombre ou objet de nombres). */
function expectClose(actual, expected) {
    if (typeof expected === "number") {
        expect(actual).toBeCloseTo(expected, 3);
        return;
    }
    expect(Object.keys(actual).sort()).toEqual(Object.keys(expected).sort());
    for (const [key, value] of Object.entries(expected)) {
        expect(actual[key], key).toBeCloseTo(value, 3);
    }
}

describe("Psychrométrie (non-régression)", () => {
    for (const row of rows) {
        it(`${row.fn}(${JSON.stringify(row.args)})`, () => {
            const correction = CORRECTIONS.find((c) => sameCall(c, row));
            const result = psychrometrics[row.fn](...row.args);
            if (correction) {
                expectClose(result, correction.result);
                return;
            }
            expect(JSON.parse(JSON.stringify(result))).toEqual(row.result);
        });
    }

    it("donne Q ≈ 0,83 × qv × Δw (g/kg) (C3)", () => {
        const deltaW = (psychrometrics.humidityRatio(25, 60) - psychrometrics.humidityRatio(2, 85)) * 1000;
        expect(psychrometrics.latentDehumidPowerW(100, 25, 60, 2, 85) / (100 * deltaW)).toBeCloseTo(0.8337, 3);
    });

    it("chaque correction correspond à un appel capturé", () => {
        for (const correction of CORRECTIONS) {
            expect(rows.some((row) => sameCall(correction, row)), correction.fn).toBe(true);
        }
    });
});
