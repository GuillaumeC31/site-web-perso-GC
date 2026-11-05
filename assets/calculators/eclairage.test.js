/*
 * Non-régression de l'Éclairage (adresse DMX, vieillissement UV, éclairage horticole) : chaque scénario
 * de golden/eclairage.json (ancien formulaire avec ses valeurs par défaut) est rejoué ; un cas modifié par
 * une correction décidée (docs/CALCULATRICE_ANOMALIES.md) reçoit sa nouvelle valeur dans CORRECTIONS,
 * avec la source en commentaire. Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import golden from "./golden/eclairage.json";
import { cases } from "../../scripts/calculator/cases/eclairage.mjs";
import {
    architect, balanceShares, BANDS, DEFAULT_EXTRAS, DEFAULT_PAR_SHARES, dliFromPpfd, PAR_BANDS, DMX, dmxAddressFromSwitches, dmxDetails, dmxSwitches, explain, hoursForDli, machineHours, requirement,
    simulate, spectrum, uvDose, uvHoursForDose,
} from "./eclairage.js";
import { parseNumber } from "./format.js";

/** Scénarios dont le résultat change (null : saisie refusée, aucun résultat). */
const CORRECTIONS = [
    // A3 (règle étendue à toutes les catégories le 2026-10-22) : adresse DMX de 1 à 512, entière (ancien : 513 ramené à 512 ; 0, -5 et vide → « 0 »).
    { tool: "adresse-dmx", set: { addressInput: 513 }, outputs: null },
    { tool: "adresse-dmx", set: { addressInput: 0 }, outputs: null },
    { tool: "adresse-dmx", set: { addressInput: -5 }, outputs: null },
    { tool: "adresse-dmx", set: { addressInput: "" }, outputs: null },
    // A3 : interrupteurs donnant 0, 513 (512 + 1) ou 1023 → hors plage, signalé (ancien : « 0 » ; 512 affiché, interrupteurs incohérents).
    { tool: "adresse-dmx", call: "toggleSwitch", set: { addressInput: 1 }, outputs: null },
    { tool: "adresse-dmx", call: "toggleSwitch", set: { addressInput: 512 }, outputs: null },
    { tool: "adresse-dmx", call: "toggleSwitch", set: { addressInput: 511 }, outputs: null },
    // D1 (2026-10-23, nécessaire) : boutons + et − aux bornes : l'adresse et ses interrupteurs restent affichés (ancien : détail vide, interrupteurs non synchronisés au chargement).
    { tool: "adresse-dmx", call: "increment", set: { addressInput: 512 }, outputs: { address: 512 } },
    { tool: "adresse-dmx", call: "decrement", set: { addressInput: 1 }, outputs: { address: 1 } },
    // A3 : irradiance négative ou nulle, phase UV nulle, condensation négative refusées (ancien : -3204 kJ/m² ; 0 h ; 0 h ; 500 h).
    { tool: "vieillissement-uv", set: { irradianceToDose: -0.89 }, outputs: null },
    { tool: "vieillissement-uv", set: { irradianceToTime: 0 }, outputs: null },
    { tool: "vieillissement-uv", set: { cycleUvHours: 0 }, outputs: null },
    { tool: "vieillissement-uv", set: { cycleDarkHours: -4 }, outputs: null },
    // A3 : photopériode de 30 h, surface négative, PPE nul refusés (ancien : 30 h ramenées à 24 ; surface ramenée à 0 ; PPE remplacé par 2,5).
    { tool: "eclairage-phyto", set: { reqHours: 30, reqLoss: "0" }, outputs: null },
    { tool: "eclairage-phyto", set: { reqArea: -1 }, outputs: null },
    { tool: "eclairage-phyto", set: { reqPpe: 0 }, outputs: null },
    // D4 (2026-10-23) : parts PAR (bleu à rouge) de somme 100 % → répartition valide ; far-red 10 % en plus du PPF (80 µmol/s), ePPF 880 µmol/s
    // (ancien : somme des 7 parts = 110 %, message d'erreur et résultats du calcul précédent).
    { tool: "eclairage-phyto", set: { archSUV: 0, archSBlue: 20, archSCold: 20, archSGreen: 10, archSWarm: 15, archSRed: 35, archSIR: 10 }, outputs: { leds: [0, 21, 26, 15, 20, 33, 17], totalLeds: 132, watts: "289 W", farRedFlux: 80, eppf: 880 } },
    // D4 (2026-10-23) : le PPF ne compte que les LED PAR (110 × 3 W × 2,8 = 924 µmol/s) ; UV 24 et far-red 39,6 µmol/s à part (ancien : 1008, toutes les LED).
    { tool: "eclairage-phyto", set: { simUV: 4, simBlue: 30, simRed: 80, simIR: 6, simPPE: 2.8, simLedWatts: 3 }, outputs: { ppf: "924", watts: "360 W", uvFlux: 24, farRedFlux: 39.6 } },
    // A3 : nombre de LED négatif refusé (ancien : -55 µmol/s, -22 W) ; 30 h par jour refusées (ancien : ramenées à 24 h).
    { tool: "eclairage-phyto", set: { simRed: -10 }, outputs: null },
    { tool: "eclairage-phyto", set: { dliHoursInput: 30 }, outputs: null },
];

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const n = (value) => parseNumber(String(value));
const form = (row) => Object.assign({ ...cases.find((c) => c.tool === row.tool).initial }, ...row.steps.map((step) => step.set ?? {}));
const lastCall = (row) => row.steps.at(-1).call;
const correction = (row) => CORRECTIONS.find((c) => c.tool === row.tool && (c.call === undefined || c.call === lastCall(row)) && row.steps.some((step) => same(step.set, c.set)));
const rows = (tool, ...calls) => golden.results.filter((row) => row.tool === tool && (calls.length === 0 || calls.includes(lastCall(row))));
/** Arrondi de l'ancien affichage du vieillissement UV : entier tel quel, sinon parseFloat(toFixed). */
const oldFormat = (value, digits = 2) => (Number.isInteger(value) ? String(value) : String(Number.parseFloat(value.toFixed(digits))));
const SHARE_KEYS = ["archSUV", "archSBlue", "archSCold", "archSGreen", "archSWarm", "archSRed", "archSIR"];
const RESULT_KEYS = ["resArchUV", "resArchBlue", "resArchCold", "resArchGreen", "resArchWarm", "resArchRed", "resArchIR"];
const SIM_KEYS = ["simUV", "simBlue", "simCold", "simGreen", "simWarm", "simRed", "simIR"];

describe("Adresse DMX", () => {
    /** Adresse après le scénario : saisie, interrupteur basculé, ou bouton + / −. */
    function addressAfter(row) {
        const x = form(row);
        const typed = n(x.addressInput);
        const step = row.steps.at(-1);
        if (step.call === "toggleSwitch") {
            const switches = dmxSwitches(typed);
            switches[step.eventIndex] = !switches[step.eventIndex];
            return dmxAddressFromSwitches(switches);
        }
        if (step.call === "increment") {
            return Math.min(DMX.max, typed + 1);
        }
        return step.call === "decrement" ? Math.max(DMX.min, typed - 1) : typed;
    }

    for (const row of rows("adresse-dmx")) {
        it(JSON.stringify(row.steps), () => {
            const address = addressAfter(row);
            const fix = correction(row);
            if (fix?.outputs === null) {
                expect(dmxSwitches(address)).toBeNull();
                expect(explain("dmxAddress", address) ?? "vide").not.toBeNull();
                return;
            }
            const switches = dmxSwitches(address);
            const details = dmxDetails(address);
            if (fix) {
                expect(address).toBe(fix.outputs.address);
                return;
            }
            expect(String(address)).toBe(row.outputs.addressInput);
            expect(switches.map((on) => (on ? "on" : "off")).join(",")).toBe(row.outputs["@switch:state"]);
            expect(details.sum).toBe(row.outputs.calculationDetails);
            expect(details.binary).toBe(row.outputs.binaryLabel);
        });
    }

    it("explique les adresses refusées (A3)", () => {
        expect(explain("dmxAddress", 513)).toBe("Adresse DMX : la valeur doit être un nombre entier de 1 à 512.");
        expect(explain("dmxAddress", 0)).toBe("Adresse DMX : la valeur doit être un nombre entier de 1 à 512.");
    });
});

describe("Vieillissement UV", () => {
    for (const row of rows("vieillissement-uv")) {
        it(JSON.stringify(row.steps), () => {
            const x = form(row);
            const fix = correction(row);
            const call = lastCall(row);
            const value = call === "calcDose" ? uvDose(n(x.irradianceToDose), n(x.hoursToDose))
                : call === "calcTime" ? uvHoursForDose(n(x.targetDose), n(x.irradianceToTime))
                    : machineHours(n(x.cycleUvHours), n(x.cycleDarkHours), n(x.cycleTargetUv));
            if (fix) {
                expect(value).toBeNull();
                return;
            }
            if (call === "calcDose") {
                expect(oldFormat(value)).toBe(row.outputs.resDoseKj);
                expect(`Soit ${oldFormat(value / 1000)} MJ/m²`).toBe(row.outputs.resDoseMj);
            } else if (call === "calcTime") {
                expect(oldFormat(value, 1)).toBe(row.outputs.resTimeHours);
                expect(`Soit ${oldFormat(value / 24, 1)} jours (sans cycle)`).toBe(row.outputs.resTimeDays);
            } else {
                expect(`${oldFormat(value, 1)} Heures`).toBe(row.outputs.resMachineHours);
                expect(`Soit ${oldFormat(value / 24, 1)} jours`).toBe(row.outputs.resMachineDays);
            }
        });
    }
});

describe("Éclairage horticole", () => {
    for (const row of rows("eclairage-phyto", "calcReq")) {
        it(JSON.stringify(row.steps), () => {
            const x = form(row);
            const result = requirement(x.reqPlant, x.reqStage, n(x.reqArea), n(x.reqHours), n(x.reqLoss), n(x.reqPpe));
            if (correction(row)) {
                expect(result).toBeNull();
                return;
            }
            const expected = { resReqPpfd: String(result.ppfd), resReqPpfdRange: result.range, resReqDli: result.dli.toFixed(1), resReqPpf: String(Math.round(result.ppf)), resReqWatts: `${Math.round(result.watts)} W`, resReqPpeNote: n(x.reqPpe).toFixed(1) };
            for (const [name, value] of Object.entries(row.outputs)) {
                expect(expected[name], name).toBe(value);
            }
        });
    }

    for (const row of rows("eclairage-phyto", "calcArch", "balanceSliders")) {
        it(JSON.stringify(row.steps), () => {
            const x = form(row);
            let shares = SHARE_KEYS.map((key) => n(x[key]));
            const last = row.steps.at(-1);
            if (last.call === "balanceSliders") {
                shares = balanceShares(shares, SHARE_KEYS.indexOf(last.event));
                SHARE_KEYS.forEach((key, index) => expect(String(shares[index]), key).toBe(row.outputs[key]));
            }
            // D4 : l'ancien scénario (7 parts d'un flux total) se relit en PPF PAR, parts PAR ramenées à 100 %,
            // UV et far-red en % du PPF : les nombres de LED doivent être identiques
            const parSum = shares.slice(1, 6).reduce((total, share) => total + share, 0);
            const fix = correction(row);
            if (fix) {
                const result = architect(n(x.archPpf), n(x.archLedWatts), shares.slice(1, 6), shares[0], shares[6]);
                expect(result.bands.map((band) => band.leds)).toEqual(fix.outputs.leds);
                expect(result.totalLeds).toBe(fix.outputs.totalLeds);
                expect(`${Math.round(result.totalWatts)} W`).toBe(fix.outputs.watts);
                expect(result.farRedFlux).toBeCloseTo(fix.outputs.farRedFlux, 6);
                expect(result.eppf).toBeCloseTo(fix.outputs.eppf, 6);
                return;
            }
            const result = architect((n(x.archPpf) * parSum) / 100, n(x.archLedWatts), shares.slice(1, 6).map((share) => (share / parSum) * 100), (shares[0] / parSum) * 100, (shares[6] / parSum) * 100);
            expect(String(shares.reduce((total, share) => total + share, 0))).toBe(row.outputs.archSum);
            RESULT_KEYS.forEach((key, index) => expect(String(result.bands[index].leds), key).toBe(row.outputs[key]));
            expect(String(result.totalLeds)).toBe(row.outputs.resArchTotal);
            expect(`${Math.round(result.totalWatts)} W`).toBe(row.outputs.resArchWatts);
        });
    }

    for (const row of rows("eclairage-phyto", "calcSim")) {
        it(JSON.stringify(row.steps), () => {
            const x = form(row);
            const result = simulate(SIM_KEYS.map((key) => n(x[key])), n(x.simPPE), n(x.simLedWatts));
            const fix = correction(row);
            if (fix?.outputs) {
                expect(String(Math.round(result.ppf))).toBe(fix.outputs.ppf);
                expect(`${Math.round(result.watts)} W`).toBe(fix.outputs.watts);
                expect(result.uvFlux).toBeCloseTo(fix.outputs.uvFlux, 6);
                expect(result.farRedFlux).toBeCloseTo(fix.outputs.farRedFlux, 6);
                return;
            }
            if (fix) {
                expect(result).toBeNull();
                return;
            }
            expect(String(Math.round(result.ppf))).toBe(row.outputs.resSimPpf);
            expect(`${Math.round(result.watts)} W`).toBe(row.outputs.resSimWatts);
            expect(result.photobiologicalWarning ? "visible" : "masqué").toBe(row.outputs["!simSafetyWarning"]);
        });
    }

    for (const row of rows("eclairage-phyto", "calcDli1", "calcDli2")) {
        it(JSON.stringify(row.steps), () => {
            const x = form(row);
            const result = lastCall(row) === "calcDli1" ? dliFromPpfd(n(x.dliPpfdInput), n(x.dliHoursInput)) : hoursForDli(n(x.dliTargetInput), n(x.dliPpfdInput2));
            const expected = row.outputs.resDli1 ?? row.outputs.resDli2;
            if (correction(row) || expected === "—") {
                expect(result).toBeNull();
                return;
            }
            expect(result.toFixed(1)).toBe(expected);
        });
    }

    it("compte le PPF sur 400–700 nm seulement, UV et far-red à part (D4)", () => {
        // Répartition par défaut : 800 µmol/s de PAR, far-red 11 % en plus (88 µmol/s), ePPF 888 µmol/s
        const result = architect(800, 2.2, DEFAULT_PAR_SHARES, DEFAULT_EXTRAS.uv, DEFAULT_EXTRAS.farRed);
        expect(result.ppf).toBe(800);
        expect(result.farRedFlux).toBeCloseTo(88, 6);
        expect(result.eppf).toBeCloseTo(888, 6);
        expect(result.bands.filter((_, index) => BANDS[index].region === "par").reduce((total, band) => total + band.flux, 0)).toBeCloseTo(800, 6);
        expect(PAR_BANDS.map((band) => band.key)).toEqual(["blue", "cold", "green", "warm", "red"]);
        // Simulateur sans UV ni far-red : inchangé (90 LED × 2,2 W × 2,5 = 495 µmol/s)
        expect(simulate([0, 20, 10, 0, 0, 60, 0], 2.5, 2.2).ppf).toBeCloseTo(495, 6);
    });

    it("normalise le spectre indicatif à 100 % (41 points de 380 à 780 nm)", () => {
        const data = spectrum([0, 15, 15, 10, 15, 35, 10]);
        expect(data).toHaveLength(41);
        expect(Math.max(...data.map((point) => point.y))).toBeCloseTo(100, 6);
        expect(data[0].x).toBe(380);
        expect(BANDS).toHaveLength(7);
    });
});

describe("Valeurs de référence", () => {
    it("chaque correction correspond à un scénario capturé", () => {
        for (const fix of CORRECTIONS) {
            expect(golden.results.some((row) => correction(row) === fix), JSON.stringify(fix.set)).toBe(true);
        }
    });
});
