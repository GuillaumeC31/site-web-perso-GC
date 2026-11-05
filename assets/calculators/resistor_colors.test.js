/*
 * Non-régression du code couleur des résistances : chaque appel de la librairie de l'ancien site
 * capturé dans golden/electronique.json (outil « code-couleur-resistance ») doit donner exactement
 * le même résultat avec la librairie reprise. Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import golden from "./golden/electronique.json";
import * as colors from "./resistor_colors.js";

/** Appels dont le résultat change par une correction décidée (docs/CALCULATRICE_ANOMALIES.md). */
const CORRECTIONS = [
    // B3 (2026-10-22) : recherche dans trois décades, distance logarithmique ; 99 Ω est plus près de 100 que de 91 (ancien : 91).
    { fn: "nearestE24", args: [99], result: 100 },
    // B1 (2026-10-22) : valeur normalisée ≥ 2 × 3 W = 6 W → 7 W (ancien : « 5 W ou plus (vérifier refroidissement) »).
    { fn: "suggestPowerRating", args: [3], result: "7 W (résistance de puissance, vérifier le refroidissement)" },
];
const sameCall = (a, b) => a.fn === b.fn && JSON.stringify(a.args) === JSON.stringify(b.args);

describe("Code couleur des résistances (non-régression)", () => {
    const rows = golden.results.filter((row) => row.tool === "code-couleur-resistance");

    it("couvre décodage, codage, affichage, série E24 et conseil de puissance", () => {
        expect(new Set(rows.map((row) => row.fn))).toEqual(new Set(["decodeBands", "encodeValue", "formatOhms", "nearestE24", "suggestPowerRating"]));
    });

    for (const row of rows) {
        it(`${row.fn}(${JSON.stringify(row.args)})`, () => {
            const result = colors[row.fn](...row.args);
            const expected = CORRECTIONS.find((correction) => sameCall(correction, row))?.result ?? row.result;
            // JSON : même comparaison que la capture (undefined disparaît, comme dans le fichier de référence)
            expect(JSON.parse(JSON.stringify(result ?? null))).toEqual(expected);
        });
    }

    it("cherche la valeur E24 la plus proche dans trois décades (B3)", () => {
        expect(colors.nearestE24(99)).toBe(100);
        expect(colors.nearestE24(1.02)).toBe(1);
        expect(colors.nearestE24(0.96)).toBe(1);
        expect(colors.nearestE24(0.93)).toBe(0.91);
        expect(colors.nearestE24(9.6)).toBe(10);
        expect(colors.nearestE24(1100)).toBe(1100);
    });

    it("donne les valeurs E24 juste en dessous et juste au-dessus (B3)", () => {
        expect(colors.e24Neighbors(99)).toEqual({ below: 91, above: 100 });
        expect(colors.e24Neighbors(1000)).toEqual({ below: 910, above: 1100 });
        expect(colors.e24Neighbors(1234)).toEqual({ below: 1200, above: 1300 });
        expect(colors.e24Neighbors(4.7)).toEqual({ below: 4.3, above: 5.1 });
        expect(colors.e24Neighbors(0)).toBeNull();
    });

    it("chaque correction correspond à un appel capturé", () => {
        for (const correction of CORRECTIONS) {
            expect(rows.some((row) => sameCall(correction, row)), JSON.stringify(correction)).toBe(true);
        }
    });
});
