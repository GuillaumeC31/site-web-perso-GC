/*
 * Non-régression des petits outils Thermodynamique (chauffe d'un volume d'eau, chauffe d'un fluide,
 * gaz parfaits, point de rosée) et des fluides frigorigènes.
 *
 * Les cas de golden/thermodynamique.json (résultats de l'ancien site) sont tous rejoués ; un cas
 * modifié par une correction décidée (docs/CALCULATRICE_ANOMALIES.md) reçoit sa nouvelle valeur
 * dans CORRECTIONS, avec la source en commentaire. Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import golden from "./golden/thermodynamique.json";
import { DECIMALS, explain, fluidEnergyKwh, fromDewPoint, fromRelativeHumidity, fromWetBulb, heatingHours, heatingWatts, idealGasPressureBar, waterEnergyKwh } from "./thermodynamique.js";
import { co2Equivalent, REFRIGERANTS, refrigerantsFromRows } from "./refrigerants.js";
import { parseNumber, roundTo } from "./format.js";

const CORRECTIONS = [
    // A3 (règle étendue à toutes les catégories le 2026-10-22) : puissance négative refusée (ancien : -3.10 h).
    { tool: "chauffe-eau", inputs: { vWater: 200, tStartWater: 20, tEndWater: 60, pWater: -3000 }, outputs: { resultEWaterTime: "9.30", resultTimeWater: "" } },
    // A3 : volume négatif refusé (ancien : -9.30 kWh et -3.10 h).
    { tool: "chauffe-eau", inputs: { vWater: -200, tStartWater: 20, tEndWater: 60, pWater: 3000 }, outputs: { resultEWaterTime: "", resultTimeWater: "" } },
    // A3 : durée négative refusée (ancien : -4650 W).
    { tool: "chauffe-eau", inputs: { vWaterPow: 200, tStartWaterPow: 20, tEndWaterPow: 60, timeWaterPow: -2 }, outputs: { resultEWaterPow: "9.30", resultPowWater: "" } },
    // A3 : quantité de matière, température sous le zéro absolu et volume négatifs refusés (ancien : -1.09, -0.10 et -4.87 bar).
    { tool: "gaz-parfaits", inputs: { nGas: -1, tGas: 20, vGas: 22.4 }, outputs: { resultGas: "" } },
    { tool: "gaz-parfaits", inputs: { nGas: 1, tGas: -300, vGas: 22.4 }, outputs: { resultGas: "" } },
    { tool: "gaz-parfaits", inputs: { nGas: 1, tGas: 20, vGas: -5 }, outputs: { resultGas: "" } },
    // A3 : humidité relative nulle ou supérieure à 100 % refusée (ancien : rosée -80.45 °C ; 22.98 °C pour 120 %).
    { tool: "point-rosee", inputs: { psyT1: 20, psyHr1: 0 }, outputs: { psyTd1: "", psyHa1: "", psyPv1: "" } },
    { tool: "point-rosee", inputs: { psyT1: 20, psyHr1: 120 }, outputs: { psyTd1: "", psyHa1: "", psyPv1: "" } },
    // A3 : la charge vide ne donne plus « 0.000 » mais aucun résultat ; charge négative refusée (ancien : -10.440 t).
    { tool: "fluides-frigorifiques", inputs: { fluidSelect: "R410A", chargeInput: "" }, outputs: { gwpDisplay: "2088", teqDisplay: "", statusDisplay: "En transition" } },
    { tool: "fluides-frigorifiques", inputs: { fluidSelect: "R410A", chargeInput: -5 }, outputs: { gwpDisplay: "2088", teqDisplay: "", statusDisplay: "En transition" } },
];

/** Corrections des scénarios « Chauffe d'un fluide » (repérés par leur suite d'étapes). */
const FLUID_CORRECTIONS = [
    // C1 (2026-10-23) : durée et puissance calculées sur l'énergie exacte, plus sur l'énergie arrondie à 0,01 kWh.
    // 1 L, 20 → 21 °C, 100 W : 0,001163 kWh → 0,01 h (ancien : 0,00 h, énergie affichée 0,00).
    { steps: [{ set: { volume: 1, tempStart: 20, tempEnd: 21, power: 100 } }, { call: "calculate" }], outputs: { energyResult: "0.00", power: "100", time: "0.01" } },
    // 100 L de glycol, 1,5 h : 2,968 kWh → 1979 W (ancien : 2,97 kWh → 1980 W).
    { steps: [{ set: { fluidType: "5" } }, { call: "changeFluid" }, { set: { volume: 100, time: 1.5 } }, { call: "calculate" }], outputs: { energyResult: "2.97", power: "1979", time: "1.5" } },
    // 80 L personnalisé, 1500 W : 3,5467 kWh → 2,36 h (ancien : 3,55 kWh → 2,37 h).
    { steps: [{ set: { fluidType: "custom" } }, { call: "changeFluid" }, { set: { cp: 3800, rho: 1050, volume: 80, power: 1500 } }, { call: "calculate" }], outputs: { energyResult: "3.55", power: "1500", time: "2.36" } },
    // A3 : puissance nulle refusée ; l'énergie (9,30 kWh) est désormais toujours recalculée (ancien : « 0.00 », valeur affichée au chargement).
    { steps: [{ set: { volume: 200, power: 0 } }, { call: "calculateTime" }], outputs: { energyResult: "9.30", time: "" } },
    // A3 : volume négatif refusé (ancien : -9.30 kWh, -3.10 h).
    { steps: [{ set: { volume: -200, power: 3000 } }, { call: "calculate" }], outputs: { energyResult: "", time: "" } },
    // A3 : volume vide → aucun résultat (ancien : « 0.00 »).
    { steps: [{ set: { volume: "" } }, { call: "calculate" }], outputs: { energyResult: "", power: "", time: "" } },
];

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const rows = (tool) => golden.results.filter((row) => row.tool === tool);
const expectedOutputs = (row) => CORRECTIONS.find((c) => c.tool === row.tool && same(c.inputs, row.inputs))?.outputs ?? row.outputs;
const n = (value) => parseNumber(value);

/** Compare une valeur calculée à l'affichage de l'ancien site ("" : aucun résultat). */
function expectSame(value, expected, digits, label) {
    if (expected === "") {
        expect(value === null || value === undefined || !Number.isFinite(value), `${label} : aucun résultat attendu, obtenu ${value}`).toBe(true);
        return;
    }
    expect(roundTo(value, digits), label).toBe(Number(expected));
}

describe("Chauffe d'un volume d'eau", () => {
    for (const row of rows("chauffe-eau")) {
        it(`${row.method} ${JSON.stringify(row.inputs)}`, () => {
            const x = row.inputs;
            const expected = expectedOutputs(row);
            if (row.method === "calculateWaterTime") {
                const energy = waterEnergyKwh(n(x.vWater), n(x.tStartWater), n(x.tEndWater));
                expectSame(energy, expected.resultEWaterTime, DECIMALS.energy, "E");
                expectSame(heatingHours(energy, n(x.pWater)), expected.resultTimeWater, DECIMALS.hours, "t");
            } else {
                const energy = waterEnergyKwh(n(x.vWaterPow), n(x.tStartWaterPow), n(x.tEndWaterPow));
                expectSame(energy, expected.resultEWaterPow, DECIMALS.energy, "E");
                expectSame(heatingWatts(energy, n(x.timeWaterPow)), expected.resultPowWater, DECIMALS.power, "P");
            }
        });
    }

    it("explique les saisies refusées (A3)", () => {
        expect(explain("heatingPower", -3000)).toBe("Puissance : la valeur doit être strictement positive.");
        expect(explain("waterEnergy", 200, -300, 20)).toBe("Température de départ : la température doit être supérieure au zéro absolu (−273,15 °C).");
    });
});

describe("Chauffe d'un fluide", () => {
    // Options de l'ancien formulaire : Cp et ρ de chaque fluide (scripts/calculator/cases/thermodynamique.mjs)
    const options = { 1: [4185, 1000], 3: [2000, 850], 5: [2400, 1113], 13: [1005, 1.204] };
    const initial = { cp: 4185, rho: 1000, tempStart: 20, tempEnd: 60, volume: "", power: "", time: "" };

    for (const row of rows("chauffe-fluide")) {
        it(JSON.stringify(row.steps), () => {
            const expected = FLUID_CORRECTIONS.find((c) => same(c.steps, row.steps))?.outputs ?? row.outputs;
            // Saisies finales, Cp et ρ remplis par le choix du fluide
            const x = { ...initial };
            for (const step of row.steps) {
                Object.assign(x, step.set ?? {});
                if (step.call === "changeFluid" && options[x.fluidType]) {
                    [x.cp, x.rho] = options[x.fluidType];
                }
            }
            const energy = fluidEnergyKwh(n(x.volume), n(x.tempStart), n(x.tempEnd), n(x.cp), n(x.rho));
            expectSame(energy, expected.energyResult, DECIMALS.energy, "E");

            // Dernier appel : la durée depuis la puissance (prioritaire), ou la puissance depuis la durée
            const last = row.steps.at(-1).call;
            const fromPower = last === "calculateTime" || (last === "calculate" && x.power !== "");
            if (fromPower) {
                expectSame(heatingHours(energy, n(x.power)), expected.time, DECIMALS.hours, "t");
            } else if (last === "calculatePower" || x.time !== "") {
                expectSame(heatingWatts(energy, n(x.time)), expected.power, DECIMALS.power, "P");
            }
        });
    }

    it("calcule la durée sur l'énergie exacte (C1)", () => {
        // 1 L d'eau chauffé de 1 K : 0,00116 kWh (affiché 0,00) → 0,0116 h avec 100 W (ancien : 0,00 h)
        expect(heatingHours(fluidEnergyKwh(1, 20, 21, 4185, 1000), 100)).toBeCloseTo(0.011625, 6);
    });
});

describe("Loi des gaz parfaits", () => {
    for (const row of rows("gaz-parfaits")) {
        it(JSON.stringify(row.inputs), () => {
            const x = row.inputs;
            expectSame(idealGasPressureBar(n(x.nGas), n(x.tGas), n(x.vGas)), expectedOutputs(row).resultGas, DECIMALS.pressure, "P");
        });
    }
});

describe("Point de rosée", () => {
    for (const row of rows("point-rosee")) {
        it(`${row.method} ${JSON.stringify(row.inputs)}`, () => {
            const x = row.inputs;
            const expected = expectedOutputs(row);
            if (row.method === "calculatePsy1") {
                const result = fromRelativeHumidity(n(x.psyT1), n(x.psyHr1));
                expectSame(result?.dewPoint, expected.psyTd1, DECIMALS.temperature, "Td");
                expectSame(result?.absoluteHumidity, expected.psyHa1, DECIMALS.humidity, "HA");
                expectSame(result?.vapourPressure, expected.psyPv1, DECIMALS.vapourPressure, "Pv");
            } else {
                const result = fromDewPoint(n(x.psyT2), n(x.psyTd2));
                expectSame(result?.relativeHumidity, expected.psyHr2, DECIMALS.relativeHumidity, "HR");
                expectSame(result?.absoluteHumidity, expected.psyHa2, DECIMALS.humidity, "HA");
            }
        });
    }

    it("calcule le psychromètre (bloc inopérant sur l'ancien site, C2)", () => {
        // 20 °C sec, 15 °C humide, 1013,25 hPa : Pv = 17,04 − 0,000662 × 1013,25 × 5 = 13,69 hPa
        const result = fromWetBulb(20, 15, 1013.25);
        expect(roundTo(result.relativeHumidity, 1)).toBe(58.6);
        expect(roundTo(result.dewPoint, 2)).toBe(11.64);
        expect(roundTo(result.absoluteHumidity, 2)).toBe(10.12);
        expect(fromWetBulb(20, 20, 1013.25).relativeHumidity).toBe(100);
        expect(explain("fromWetBulb", 15, 20, 1013.25)).toBe("Le bulbe humide ne peut pas dépasser le bulbe sec.");
    });

    it("explique les saisies refusées (A3)", () => {
        expect(explain("fromRelativeHumidity", 20, 120)).toBe("Humidité relative : la valeur doit être comprise entre 0 (exclu) et 100 %.");
        expect(explain("fromDewPoint", 20, 25)).toBe("Le point de rosée ne peut pas dépasser la température de l'air.");
    });
});

describe("Fluides frigorigènes", () => {
    for (const row of rows("fluides-frigorifiques")) {
        it(JSON.stringify(row.inputs), () => {
            const expected = expectedOutputs(row);
            const fluid = REFRIGERANTS[row.inputs.fluidSelect];
            expect(String(fluid.gwp)).toBe(expected.gwpDisplay);
            expect(["AR4", "AR5"], "rapport du GIEC du PRG (C5)").toContain(fluid.gwpReport);
            expect(fluid.status).toBe(expected.statusDisplay);
            expectSame(co2Equivalent(n(row.inputs.chargeInput), fluid.gwp), expected.teqDisplay, 3, "TéqCO2");
        });
    }

    it("garde les 22 fluides de l'ancien site en tête, dans leur ordre, puis les 4 fluides du lot 5e", () => {
        const codes = Object.keys(REFRIGERANTS);
        expect(codes.slice(0, 3)).toEqual(["R12", "R22", "R134a"]);
        expect(codes[21]).toBe("R717");
        expect(codes.slice(22)).toEqual(["R513A", "R449A", "R448A", "R452A"]);
    });

    it("construit la base depuis les lignes de l'admin (code en clé, ordre conservé)", () => {
        const base = refrigerantsFromRows([{ code: "R999X", name: "R-999X", gwp: 5 }, { code: "R12", name: "R-12", gwp: 10900 }]);
        expect(Object.keys(base)).toEqual(["R999X", "R12"]);
        expect(base.R999X).toEqual({ name: "R-999X", gwp: 5 });
    });
});

describe("Valeurs de référence", () => {
    it("chaque correction correspond à un cas capturé", () => {
        for (const correction of CORRECTIONS) {
            expect(golden.results.some((r) => r.tool === correction.tool && same(r.inputs, correction.inputs)), JSON.stringify(correction.inputs)).toBe(true);
        }
        for (const correction of FLUID_CORRECTIONS) {
            expect(golden.results.some((r) => r.tool === "chauffe-fluide" && same(r.steps, correction.steps)), JSON.stringify(correction.steps)).toBe(true);
        }
    });
});
