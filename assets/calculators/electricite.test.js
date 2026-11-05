/*
 * Non-régression des outils Électricité.
 *
 * Les 59 cas de golden/electricite.json (résultats de l'ancien site, capturés par
 * scripts/calculator/capture-golden.mjs) sont tous rejoués. Un cas modifié par une correction
 * décidée (docs/CALCULATRICE_ANOMALIES.md) reçoit sa nouvelle valeur attendue dans CORRECTIONS,
 * avec la source en commentaire ; tous les autres doivent donner exactement le résultat de l'ancien site.
 * Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import golden from "./golden/electricite.json";
import {
    activePower,
    apparentPower,
    cables,
    currentFromActivePower,
    currentFromApparentPower,
    DECIMALS,
    explain,
    ohmLaw,
    powerFactorCorrection,
    reactivePower,
    resistivityNote,
    steinmetzCapacitor,
    voltageDropSinglePhase,
    voltageFromActivePower,
    voltageFromApparentPower,
} from "./electricite.js";
import { parseNumber, roundTo } from "./format.js";

/**
 * Nouvelles valeurs attendues des cas modifiés par une correction.
 * Clé : outil, méthode, entrées (comme dans golden/electricite.json). "" : aucun résultat.
 */
const CORRECTIONS = [
    // A3 (2026-10-22) : une tension négative est refusée (l'ancien site affichait -1840.00).
    { tool: "puissances-ac", method: "calculateP", inputs: { u1: -230, i1: 10, cos1: 0.8 }, outputs: { resultP: "" } },
    // A3 : cos φ hors de [0 ; 1] refusé (l'ancien site le ramenait à 1 : 0.00).
    { tool: "puissances-ac", method: "calculateQ", inputs: { uReact: 230, iReact: 10, cosReact: 1.2 }, outputs: { resultQ: "" } },
    // A3 : cos φ négatif refusé (l'ancien site calculait sin φ sur sa valeur absolue : 1991.86).
    { tool: "puissances-ac", method: "calculateQ", inputs: { uReact: 230, iReact: 10, cosReact: -0.5 }, outputs: { resultQ: "" } },

    // A2 (2026-10-22) : règle pratique C ≈ 70 µF/kW (moteur 230/400 V en triangle, réseau 230 V 50 Hz).
    // 70 × 1,5 = 105 µF (ancien : 50 × 1,5 / 230 × 1000 = 326)
    { tool: "steinmetz", method: "calculateSteinmetz", inputs: { steinP: 1.5, steinHp: "", steinI: "" }, outputs: { steinP: "1.5", steinHp: "2.04", steinI: "", steinC: "105" } },
    // A2 : 70 × 0,37 = 25,9 → 26 µF (ancien : 80)
    { tool: "steinmetz", method: "calculateSteinmetz", inputs: { steinP: 0.37, steinHp: "", steinI: "" }, outputs: { steinP: "0.37", steinHp: "0.50", steinI: "", steinC: "26" } },
    // A2 : 2 ch = 2 / 1,35962 = 1,471 kW ; 70 × 1,471 = 102,97 → 103 µF (ancien : 320)
    { tool: "steinmetz", method: "calculateSteinmetz", inputs: { steinP: "", steinHp: 2, steinI: "" }, outputs: { steinP: "1.47", steinHp: "2", steinI: "", steinC: "103" } },

    // A1 (2026-10-22) : ρ cuivre 0,0225 Ω·mm²/m (NF C 15-100 / UTE C 15-105) au lieu de 0,0171.
    // S = 2 × 0,0225 × 50 × 10 / 0,6 = 37,50 mm² (ancien : 28.50)
    { tool: "calculs-cables", method: "calculateSection", inputs: { sectionMat: "cu", sectionLength: 50, sectionCurrent: 10, sectionVoltageDrop: 0.6 }, outputs: { sectionResult: "37.50" } },
    // A1 : ρ aluminium 0,036 Ω·mm²/m (NF C 15-100 / UTE C 15-105) au lieu de 0,0282.
    // S = 2 × 0,036 × 50 × 10 / 0,6 = 60,00 mm² (ancien : 47.00)
    { tool: "calculs-cables", method: "calculateSection", inputs: { sectionMat: "al", sectionLength: 50, sectionCurrent: 10, sectionVoltageDrop: 0.6 }, outputs: { sectionResult: "60.00" } },
    // A3 : longueur nulle refusée (ancien : 0.00).
    { tool: "calculs-cables", method: "calculateSection", inputs: { sectionMat: "cu", sectionLength: 0, sectionCurrent: 10, sectionVoltageDrop: 0.6 }, outputs: { sectionResult: "" } },
    // A1 : L = 0,6 × 2,5 / (2 × 0,0225 × 10) = 3,33 m (ancien : 4.39)
    { tool: "calculs-cables", method: "calculateLength", inputs: { lengthMat: "cu", lengthSection: 2.5, lengthCurrent: 10, lengthVoltageDrop: 0.6 }, outputs: { lengthResult: "3.33" } },
    // A1 : L = 6,9 × 16 / (2 × 0,036 × 63) = 24,34 m (ancien : 31.07)
    { tool: "calculs-cables", method: "calculateLength", inputs: { lengthMat: "al", lengthSection: 16, lengthCurrent: 63, lengthVoltageDrop: 6.9 }, outputs: { lengthResult: "24.34" } },
    // A3 : champ vide = pas encore de résultat (ancien : 0.00).
    { tool: "calculs-cables", method: "calculateLength", inputs: { lengthMat: "cu", lengthSection: 2.5, lengthCurrent: "", lengthVoltageDrop: 0.6 }, outputs: { lengthResult: "" } },
    // A1 : ΔU = 2 × 0,0225 × 50 × 10 / 2,5 = 9,00 V (ancien : 6.84), désormais identique à l'outil « Chute de tension ».
    { tool: "calculs-cables", method: "calculateDrop", inputs: { dropMat: "cu", dropSection: 2.5, dropLength: 50, dropCurrent: 10 }, outputs: { dropResult: "9.00" } },
    // A1 : ΔU = 2 × 0,036 × 100 × 63 / 16 = 28,35 V (ancien : 22.21)
    { tool: "calculs-cables", method: "calculateDrop", inputs: { dropMat: "al", dropSection: 16, dropLength: 100, dropCurrent: 63 }, outputs: { dropResult: "28.35" } },
    // A3 : section négative refusée (ancien : 0.00).
    { tool: "calculs-cables", method: "calculateDrop", inputs: { dropMat: "cu", dropSection: -2.5, dropLength: 50, dropCurrent: 10 }, outputs: { dropResult: "" } },
    // A4 (2026-10-22) : nombre de conducteurs obligatoire (l'ancien site comptait 1 conducteur : 0.13).
    { tool: "calculs-cables", method: "calculateWeight", inputs: { weightMat: "cu", weightLength: 10, weightSection: 1.5, weightCores: "", weightInsulation: "" }, outputs: { weightResult: "" } },
    // A4 : nombre de conducteurs entier obligatoire (l'ancien site tronquait 2,7 en 2 : 0.30).
    { tool: "calculs-cables", method: "calculateWeight", inputs: { weightMat: "cu", weightLength: 10, weightSection: 1.5, weightCores: 2.7, weightInsulation: 10 }, outputs: { weightResult: "" } },
];

const sameInputs = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/** Renvoie les sorties attendues d'un cas : correction décidée, sinon résultat de l'ancien site. */
function expectedOutputs(row) {
    return CORRECTIONS.find((c) => c.tool === row.tool && c.method === row.method && sameInputs(c.inputs, row.inputs))?.outputs ?? row.outputs;
}

/**
 * Compare une valeur calculée à la valeur attendue (affichage de l'ancien site, arrondi compris).
 * Une chaîne vide signifie « aucun résultat » (null, Infinity ou NaN).
 */
function expectSame(value, expected, digits, label) {
    if (expected === "") {
        expect(value === null || value === undefined || !Number.isFinite(value), `${label} : aucun résultat attendu, obtenu ${value}`).toBe(true);
        return;
    }
    expect(value, `${label} : résultat attendu ${expected}`).not.toBeNull();
    expect(roundTo(value, digits), label).toBe(Number(expected));
}

const n = (value) => parseNumber(value);
const cases = (tool) => golden.results.filter((row) => row.tool === tool);

describe("Puissances en alternatif", () => {
    const formulas = {
        calculateP: (x) => ({ resultP: activePower(n(x.u1), n(x.i1), n(x.cos1)) }),
        calculateI: (x) => ({ resultI: currentFromActivePower(n(x.p2), n(x.u2), n(x.cos2)) }),
        calculateU: (x) => ({ resultU: voltageFromActivePower(n(x.p3), n(x.i3), n(x.cos3)) }),
        calculateS: (x) => ({ resultS: apparentPower(n(x.u4), n(x.i4)) }),
        calculateIApp: (x) => ({ resultIApp: currentFromApparentPower(n(x.s5), n(x.u5)) }),
        calculateUApp: (x) => ({ resultUApp: voltageFromApparentPower(n(x.s6), n(x.i6)) }),
        calculateQ: (x) => ({ resultQ: reactivePower(n(x.uReact), n(x.iReact), n(x.cosReact)) }),
    };
    for (const row of cases("puissances-ac")) {
        it(`${row.method} ${JSON.stringify(row.inputs)}`, () => {
            const computed = formulas[row.method](row.inputs);
            for (const [name, expected] of Object.entries(expectedOutputs(row))) {
                expectSame(computed[name], expected, DECIMALS.power, name);
            }
        });
    }

    it("explique les saisies refusées (A3)", () => {
        expect(explain("activePower", -230, 10, 0.8)).toBe("Tension (U) : la valeur doit être strictement positive.");
        expect(explain("currentFromActivePower", 2000, 0, 0.8)).toBe("Tension (U) : la valeur doit être strictement positive.");
        expect(explain("reactivePower", 230, 10, 1.2)).toBe("cos φ : la valeur doit être comprise entre 0 et 1.");
        expect(explain("activePower", 230, Number.NaN, 0.8)).toBeNull();
    });
});

describe("Chute de tension", () => {
    for (const row of cases("chute-tension")) {
        it(JSON.stringify(row.inputs), () => {
            // L'ancien outil était en cuivre seulement : matériau « cu » (ρ inchangé, 0,0225).
            const drop = voltageDropSinglePhase("cu", n(row.inputs.lDrop), n(row.inputs.iDrop), n(row.inputs.sDrop));
            expectSame(drop, expectedOutputs(row).resultDrop, DECIMALS.voltageDrop, "ΔU");
        });
    }

    it("calcule l'aluminium avec ρ = 0,036 (A1, NF C 15-100 / UTE C 15-105)", () => {
        // ΔU = 2 × 0,036 × 50 / 2,5 × 32 = 46,08 V
        expect(roundTo(voltageDropSinglePhase("al", 50, 32, 2.5), 2)).toBe(46.08);
        expect(resistivityNote("al")).toBe("ρ = 0,036 Ω·mm²/m (aluminium) — NF C 15-100 / UTE C 15-105");
    });

    it("donne le même résultat que le dimensionnement des câbles (A1)", () => {
        expect(voltageDropSinglePhase("cu", 50, 10, 2.5)).toBe(cables.drop("cu", 2.5, 50, 10));
    });
});

describe("Loi d'Ohm", () => {
    const fields = { ohmU: "u", ohmI: "i", ohmR: "r", ohmP: "p" };
    for (const row of cases("loi-ohm")) {
        it(`${JSON.stringify(row.inputs)} (saisie : ${row.event})`, () => {
            const values = Object.fromEntries(Object.entries(fields).map(([target, key]) => [key, n(row.inputs[target])]));
            const result = ohmLaw(values);
            const expected = expectedOutputs(row);
            for (const [target, key] of Object.entries(fields)) {
                // Le champ en cours de saisie n'est jamais réécrit.
                if (target === row.event) {
                    continue;
                }
                if (result === null) {
                    expect(expected[target], target).toBe(String(row.inputs[target]));
                } else {
                    expectSame(result[key], expected[target], DECIMALS.ohm[key], target);
                }
            }
        });
    }

    it("refuse une valeur négative (A3)", () => {
        expect(ohmLaw({ u: -12, i: 2, r: Number.NaN, p: Number.NaN })).toBeNull();
        expect(explain("ohmLaw", { u: -12, i: 2, r: Number.NaN, p: Number.NaN })).toBe("Tension (U) : la valeur ne peut pas être négative.");
    });
});

describe("Condensateur Steinmetz", () => {
    const sources = { steinP: ["kw", "steinP"], steinHp: ["hp", "steinHp"], steinI: ["amps", "steinI"] };
    for (const row of cases("steinmetz")) {
        it(`${JSON.stringify(row.inputs)} (saisie : ${row.event})`, () => {
            const [source, field] = sources[row.event];
            const expected = expectedOutputs(row);
            const result = steinmetzCapacitor(source, n(row.inputs[field]));
            expectSame(result.capacitance, expected.steinC, DECIMALS.capacitorPermanent, "C");
            if (source === "kw" && result.hp !== null) {
                expectSame(result.hp, expected.steinHp, DECIMALS.horsepower, "ch");
            }
            if (source === "hp" && result.kw !== null) {
                expectSame(result.kw, expected.steinP, DECIMALS.kilowatt, "kW");
            }
        });
    }

    it("refuse une puissance négative (A3)", () => {
        expect(steinmetzCapacitor("kw", -1.5).capacitance).toBeNull();
        expect(explain("steinmetzCapacitor", "kw", -1.5)).toBe("Puissance (kW) : la valeur doit être strictement positive.");
    });
});

describe("Relèvement du cos φ", () => {
    for (const row of cases("relevement-cos-phi")) {
        it(JSON.stringify(row.inputs), () => {
            const x = row.inputs;
            const expected = expectedOutputs(row);
            const result = powerFactorCorrection(n(x.cosPFp), n(x.cosPFu), n(x.cosPFhz), n(x.cosPFini), n(x.cosPFcib));
            expectSame(result?.reactivePower ?? null, expected.cosPFqc, DECIMALS.reactivePowerKvar, "Qc");
            // A5 : la « capacité totale équivalente » de l'ancien site est la capacité par phase en étoile.
            expectSame(result?.capacitanceStar ?? null, expected.cosPFcap, DECIMALS.capacitanceMicrofarad, "C étoile");
        });
    }

    it("donne la capacité par phase en triangle, trois fois plus petite (A5)", () => {
        // Qc = 150 × (tan(acos 0,75) − tan(acos 0,93)) = 73,00 kvar ; triangle : C = Qc / (3 × 400² × 2π × 50) = 484,1 µF
        const result = powerFactorCorrection(150, 400, 50, 0.75, 0.93);
        expect(roundTo(result.capacitanceStar, 1)).toBe(1452.4);
        expect(roundTo(result.capacitanceDelta, 1)).toBe(484.1);
        expect(result.alreadyReached).toBe(false);
    });

    it("signale qu'aucune compensation n'est nécessaire si la cible est déjà atteinte", () => {
        expect(powerFactorCorrection(50, 400, 60, 0.95, 0.9).alreadyReached).toBe(true);
    });
});

describe("Dimensionnement des câbles", () => {
    const formulas = {
        calculateSection: (x) => ["sectionResult", cables.section(x.sectionMat, n(x.sectionLength), n(x.sectionCurrent), n(x.sectionVoltageDrop))],
        calculateLength: (x) => ["lengthResult", cables.length(x.lengthMat, n(x.lengthSection), n(x.lengthCurrent), n(x.lengthVoltageDrop))],
        calculateDrop: (x) => ["dropResult", cables.drop(x.dropMat, n(x.dropSection), n(x.dropLength), n(x.dropCurrent))],
        calculateWeight: (x) => ["weightResult", cables.weight(x.weightMat, n(x.weightLength), n(x.weightSection), n(x.weightCores), n(x.weightInsulation))],
    };
    for (const row of cases("calculs-cables")) {
        it(`${row.method} ${JSON.stringify(row.inputs)}`, () => {
            const [name, value] = formulas[row.method](row.inputs);
            expectSame(value, expectedOutputs(row)[name], DECIMALS.cable, name);
        });
    }

    it("explique les saisies refusées (A3, A4)", () => {
        expect(explain("cableSection", "cu", 0, 10, 0.6)).toBe("Longueur (L) : la valeur doit être strictement positive.");
        expect(explain("cableWeight", "cu", 10, 1.5, 2.7, 10)).toBe("Nombre de conducteurs : la valeur doit être un nombre entier, au moins 1.");
    });
});

describe("Valeurs de référence", () => {
    it("rejoue les 59 cas capturés sur les 6 outils de l'ancien site", () => {
        expect(golden.results).toHaveLength(59);
        expect(new Set(golden.results.map((row) => row.tool))).toEqual(
            new Set(["puissances-ac", "chute-tension", "loi-ohm", "steinmetz", "relevement-cos-phi", "calculs-cables"]),
        );
    });

    it("chaque correction correspond à un cas capturé (aucune correction orpheline)", () => {
        for (const correction of CORRECTIONS) {
            const row = golden.results.find((r) => r.tool === correction.tool && r.method === correction.method && sameInputs(r.inputs, correction.inputs));
            expect(row, `${correction.tool} ${correction.method} ${JSON.stringify(correction.inputs)}`).toBeDefined();
        }
    });
});
