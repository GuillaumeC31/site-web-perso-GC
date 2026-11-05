/*
 * Non-régression des outils Mécanique (engrenages, vérin, poutre).
 *
 * Les cas de golden/mecanique.json (résultats de l'ancien site) sont tous rejoués ; un cas modifié
 * par une correction décidée (docs/CALCULATRICE_ANOMALIES.md) reçoit sa nouvelle valeur dans
 * CORRECTIONS, avec la source en commentaire. Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import golden from "./golden/mecanique.json";
import { cantileverBeam, cylinder, DECIMALS, explain, explainRod, gearTrain, undercutCheck, undercutLimits } from "./mecanique.js";
import { parseNumber, roundTo } from "./format.js";

const CORRECTIONS = [
    // B4 (2026-10-22) : seuils depuis l'angle de pression (20°) : théorique 2 / sin²α = 17,1 → 17, pratique 5/6 → 14.
    // 13 dents < 14 → alerte forte (ancien : aucune alerte, seuil à 13).
    { tool: "engrenages", inputs: { gearZ3: "", gearZ4: "", gearEff: 95, gearZ1: 13, gearZ2: 47, gearVin: 1500, gearCin: 10 }, outputs: { gearRatio: "1 : 3.62", gearVout: "414.9", gearCout: "34.35", gearAlerts: "Interférence de taillage. Dent de chasse optimale" } },
    // B4 : 15 dents < 17 → avertissement (ancien : aucune alerte).
    { tool: "engrenages", inputs: { gearZ3: 15, gearZ4: 45, gearEff: 90, gearZ1: 20, gearZ2: 60, gearVin: 3000, gearCin: 2 }, outputs: { gearRatio: "1 : 9.00", gearVout: "333.3", gearCout: "16.20", gearAlerts: "Risque d'interférence. Usure localisée : Paires non premières entre elles (PGCD = 20 et 15)." } },
    // A3 (règle étendue le 2026-10-22) : nombre de dents entier obligatoire (ancien : 12,5 dents acceptées, rapport 1 : 3.84).
    { tool: "engrenages", inputs: { gearZ3: "", gearZ4: "", gearEff: 95, gearZ1: 12.5, gearZ2: 48, gearVin: 3000, gearCin: 5 }, outputs: { gearRatio: "", gearVout: "", gearCout: "", gearAlerts: "" } },
    // A3 : vitesse négative et rendement de 120 % refusés (ancien : -750.0 tr/min et 24.00 N·m).
    { tool: "engrenages", inputs: { gearZ3: "", gearZ4: "", gearEff: 120, gearZ1: 12, gearZ2: 48, gearVin: -3000, gearCin: 5 }, outputs: { gearRatio: "1 : 4.00", gearVout: "", gearCout: "", gearAlerts: "Attention à l'interférence" } },
    // A3 : force négative refusée (ancien : flèche -0.477 mm, moment -500.00 N·m).
    { tool: "flexion-poutre", inputs: { beamF: -1000, beamL: 500, beamE: 210000, beamI: 416000 }, outputs: { beamDeflect: "", beamMoment: "" } },
    // A3 : longueur négative refusée (ancien : -0.477 mm, -500.00 N·m).
    { tool: "flexion-poutre", inputs: { beamF: 1000, beamL: -500, beamE: 210000, beamI: 416000 }, outputs: { beamDeflect: "", beamMoment: "" } },
];

const sameInputs = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const expectedOutputs = (row) => CORRECTIONS.find((c) => c.tool === row.tool && sameInputs(c.inputs, row.inputs))?.outputs ?? row.outputs;
const n = (value) => parseNumber(value);
const cases = (tool) => golden.results.filter((row) => row.tool === tool);

function expectSame(value, expected, digits, label) {
    if (expected === "") {
        expect(value === null || value === undefined || !Number.isFinite(value), `${label} : aucun résultat attendu, obtenu ${value}`).toBe(true);
        return;
    }
    expect(roundTo(value, digits), label).toBe(Number(expected));
}

describe("Train d'engrenages", () => {
    for (const row of cases("engrenages")) {
        it(JSON.stringify(row.inputs), () => {
            const x = row.inputs;
            const expected = expectedOutputs(row);
            const result = gearTrain(n(x.gearZ1), n(x.gearZ2), n(x.gearZ3), n(x.gearZ4), n(x.gearVin), n(x.gearCin), n(x.gearEff));
            if (expected.gearRatio === "") {
                expect(result).toBeNull();
                return;
            }
            // « 1 : 4.00 » : réduction affichée par l'ancien site
            expect(roundTo(result.reduction, DECIMALS.ratio)).toBe(Number(expected.gearRatio.split(":")[1]));
            expectSame(result.speedOut, expected.gearVout, DECIMALS.speed, "vitesse");
            expectSame(result.torqueOut, expected.gearCout, DECIMALS.torque, "couple");

            // Alertes : interférence (« Attention à l'interférence » de l'ancien site = alerte forte ; B4) et dent de chasse (PGCD)
            const level = /Attention à l'interférence|Interférence de taillage/.test(expected.gearAlerts) ? "strong" : expected.gearAlerts.includes("Risque d'interférence") ? "warning" : null;
            expect(result.undercut?.level ?? null, "interférence").toBe(level);
            expect(result.huntingTooth, "dent de chasse").toBe(expected.gearAlerts.includes("Dent de chasse optimale"));
            const gcdText = /PGCD = ([\d ]+(?:et \d+)?)/.exec(expected.gearAlerts);
            if (gcdText) {
                expect(result.gcds.join(" et ")).toBe(gcdText[1].trim());
            }
        });
    }

    it("calcule les seuils d'interférence depuis l'angle de pression (B4)", () => {
        expect(undercutLimits(20)).toMatchObject({ theoretical: 17, practical: 14 });
        expect(roundTo(undercutLimits(20).exact, 1)).toBe(17.1);
        expect(undercutLimits(14.5)).toMatchObject({ theoretical: 32, practical: 27 });
        expect(undercutLimits(25)).toMatchObject({ theoretical: 11, practical: 9 });
        expect(undercutCheck(13, 20).level).toBe("strong");
        expect(undercutCheck(14, 20).level).toBe("warning");
        expect(undercutCheck(16, 20).level).toBe("warning");
        expect(undercutCheck(17, 20).level).toBeNull();
        expect(undercutCheck(13, 25).level).toBeNull();
        expect(undercutCheck(17, Number.NaN)).toBeNull();
        expect(explain("gearAngle", 50)).toBe("Angle de pression : la valeur doit être comprise entre 10 et 35°.");
    });

    it("explique les saisies refusées (A3)", () => {
        expect(explain("gearTrain", 12.5, 48, Number.NaN, Number.NaN)).toBe("Menante 1 (Z1) : la valeur doit être un nombre entier, au moins 1.");
        expect(explain("gearTorque", 5, 120)).toBe("Rendement : la valeur doit être comprise entre 0 (exclu) et 100 %.");
        expect(explain("gearTrain", 12, 48, 15, Number.NaN)).toBe("Renseignez Z3 et Z4 ensemble pour un train à deux étages.");
    });
});

describe("Vérin", () => {
    for (const row of cases("verin")) {
        it(JSON.stringify(row.inputs), () => {
            const x = row.inputs;
            const expected = expectedOutputs(row);
            const result = cylinder(n(x.cylP), n(x.cylD), n(x.cylDrod));
            expectSame(result?.pushNewtons ?? null, expected.cylFpushN, DECIMALS.force, "poussée N");
            expectSame(result?.pushKgf ?? null, expected.cylFpushKg, DECIMALS.force, "poussée kgf");
            expectSame(result?.pullNewtons ?? null, expected.cylFpullN, DECIMALS.force, "traction N");
            expectSame(result?.pullKgf ?? null, expected.cylFpullKg, DECIMALS.force, "traction kgf");
        });
    }

    it("explique une tige trop grosse (A3)", () => {
        expect(explainRod(40, 32)).toBe("Le diamètre de la tige doit être inférieur à celui du piston.");
        expect(explainRod(12, 32)).toBeNull();
        expect(explainRod(Number.NaN, 32)).toBeNull();
    });
});

describe("Flexion d'une poutre encastrée", () => {
    for (const row of cases("flexion-poutre")) {
        it(JSON.stringify(row.inputs), () => {
            const x = row.inputs;
            const expected = expectedOutputs(row);
            const result = cantileverBeam(n(x.beamF), n(x.beamL), n(x.beamE), n(x.beamI));
            expectSame(result?.deflection ?? null, expected.beamDeflect, DECIMALS.deflection, "flèche");
            expectSame(result?.moment ?? null, expected.beamMoment, DECIMALS.moment, "moment");
        });
    }
});

describe("Valeurs de référence", () => {
    it("chaque correction correspond à un cas capturé", () => {
        for (const correction of CORRECTIONS) {
            expect(golden.results.some((r) => r.tool === correction.tool && sameInputs(r.inputs, correction.inputs)), JSON.stringify(correction.inputs)).toBe(true);
        }
    });
});
