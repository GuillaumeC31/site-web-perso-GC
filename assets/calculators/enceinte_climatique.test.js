/*
 * Enceinte climatique v2 : 3 cas validés le 2026-10-23, rejoués poste par poste contre les cas de référence
 * golden/enceinte_climatique_v2.json, produits par le contre-calcul indépendant
 * scripts/calculator/climatic_chamber_hand_check.py (vérifié en CI ; détail : docs/ENCEINTE_CLIMATIQUE_V2.md,
 * section 4) ; non-régression des méthodes inchangées depuis l'ancien site (C4, C6, C9) ; mode simple,
 * saisies, régulation, séchage et fluide.
 * Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import initialFluids from "../../config/calculator/refrigerants.json";
import reference from "./golden/enceinte_climatique_v2.json";
import { carnotCop, chamberPro, chamberSimple, climaticChamber, DEFAULTS, explain, PROFILES, simpleToFull, volumeToDimensions } from "./enceinte_climatique.js";
import { fluidReport } from "./fluid_report.js";
import { refrigerantsFromRows } from "./refrigerants.js";
import { dewPoint } from "./psychrometrics.js";

const FLUIDS = refrigerantsFromRows(initialFluids.refrigerants);
const R290 = { fluid: FLUIDS.R290, fluidCode: "R290", installation: "self_contained", hermetic: true, lineLength: 10, detection: false, chargeRatio: NaN, rules: [], today: "2026-10-23" };

/** Saisies du mode simple, cas par défaut de la page (profil ICH long terme). */
function simpleDefault(overrides = {}) {
    return {
        kind: "modular", sizeMode: "dims", volumeLiters: NaN, sun: false, length: 2, width: 1.5, height: 2, tSet: 25, rhSet: 60, stabilityT: "2", stabilityRh: "5", doorProtection: "none", airRenewal: 0,
        heatingType: "electric", coolingType: "dx", humidifierType: "steam_electric", dryingType: "dx_coil", refrigerant: R290, ...overrides,
    };
}

/** Saisies du mode pro : valeurs par défaut (comme la page), puis le cas. */
function pro(overrides) {
    const base = simpleToFull(simpleDefault());

    return { ...base, mode: "pro", ...overrides, doors: (overrides.doors ?? base.doors).map((d) => ({ ...d, heaterWPerM: overrides.tSet < 5 ? 40 : 0 })) };
}

/** Les 3 cas du document (section 4). */
const CASES = {
    case1: pro({ tSet: 40, rhSet: 75, heating: { type: "electric", efficiency: 1, supplyT: 70, returnT: 50, installedW: 2000 } }),
    case2: pro({ tSet: 25, rhSet: 60, airRenewal: 30 }),
    case3: pro({ tSet: 5, rhSet: NaN, stabilityT: "0.5", samples: { ...DEFAULTS.samples, kg: 50, cp: 0.9, dissipatedW: 500, hours: 12 } }),
};

const close = (actual, expected, label) => (expected === null ? expect(actual, label).toBeNull() : expect(actual, label).toBeCloseTo(expected, 5));

describe("Enceinte climatique v2 : cas de référence (contre-calcul indépendant)", () => {
    for (const [key, expected] of Object.entries(reference.cases)) {
        it(`${key} : postes, bilans d'eau, puissances et consommations des deux saisons`, () => {
            const r = chamberPro(CASES[key]);
            expect(r, explain("pro", CASES[key])).not.toBeNull();
            for (const season of ["summer", "winter"]) {
                const s = r.seasons[season];
                const e = expected.seasons[season];
                for (const [lineKey, watts] of Object.entries(e.lines)) {
                    close(s.lines.find((l) => l.key === lineKey)?.watts ?? 0, watts, `${season} ${lineKey}`);
                }
                for (const field of ["envelopeW", "internalW", "internalAvgW", "waterKgH", "condensationKgH", "humidificationKgH", "dryingKgH", "humidifierSensibleW", "coldSensibleW", "condensationLatentW",
                    "coldW", "heatW", "humidificationW", "dryingW", "reheatW", "cop", "consumptionKwhDay", "waterLDay", "condensateLDay"]) {
                    close(s[field], e[field], `${season} ${field}`);
                }
                const ramp = r.rampTemperature[season];
                for (const [field, value] of Object.entries(expected.rampTemperature[season])) {
                    if (typeof value === "string") {
                        expect(ramp[field]).toBe(value);
                    } else {
                        close(ramp[field], value, `rampe ${season} ${field}`);
                    }
                }
                if (expected.rampHumidity) {
                    for (const [field, value] of Object.entries(expected.rampHumidity[season])) {
                        close(r.rampHumidity[season][field], value, `humidité ${season} ${field}`);
                    }
                }
                close(r.regulation.modulatedKwhDay[season], expected.regulation.modulatedKwhDay[season], `modulation ${season}`);
                close(r.regulation.permanentKwhDay[season], expected.regulation.permanentKwhDay[season], `permanent ${season}`);
            }
            for (const [key, values] of Object.entries(expected.installed)) {
                for (const [field, value] of Object.entries(values)) {
                    close(r.installed[key][field], value, `à installer ${key} ${field}`);
                }
            }
            for (const [block, values] of [["temperature", expected.pid.temperature], ["humidity", expected.pid.humidity]]) {
                for (const [field, value] of Object.entries(values ?? {})) {
                    close(r.pid[block][field], value, `PID ${block} ${field}`);
                }
            }
            expect(Object.keys(r.pid.loops).sort()).toEqual(Object.keys(expected.pid.loops).sort());
            for (const [loop, values] of Object.entries(expected.pid.loops)) {
                for (const [field, value] of Object.entries(values)) {
                    if (typeof value === "boolean") {
                        expect(r.pid.loops[loop][field], `PID ${loop} ${field}`).toBe(value);
                    } else {
                        close(r.pid.loops[loop][field], value, `PID ${loop} ${field}`);
                    }
                }
            }
            close(r.regulation.installedColdW, expected.regulation.installedColdW, "froid installé");
            close(r.regulation.reheatW, expected.regulation.reheatW, "réchauffage");
            if (!expected.rampHumidity) {
                expect(r.rampHumidity).toBeNull();
            }
        });
    }

    it("retrouve les valeurs validées du document (section 4)", () => {
        const [c1, c2, c3] = ["case1", "case2", "case3"].map((key) => chamberPro(CASES[key]));
        expect(c1.seasons.summer.coldW).toBeCloseTo(347.23, 2);
        expect(c1.seasons.summer.condensationKgH * 1000).toBeCloseTo(174.70, 2);
        expect(c1.rampTemperature.summer.minutes).toBeCloseTo(10.57, 2);
        expect(c2.seasons.winter.heatW).toBeCloseTo(381.0, 2);
        expect(c2.seasons.summer.dryingW).toBeCloseTo(12.82, 2);
        expect(c3.regulation.installedColdW).toBeCloseTo(2232.73, 2);
        expect(c3.regulation.reheatW).toBeCloseTo(2139.55, 2);
        expect(c3.regulation.permanentKwhDay.summer).toBeCloseTo(64.34, 2);
        expect(c3.sizing.coldW).toBeCloseTo(2232.73, 2);
    });

    it("cartes : puissance à installer = max(maintien, mise en régime), ou puissance installée saisie", () => {
        const [c1, c2] = ["case1", "case2"].map((key) => chamberPro(CASES[key]));
        // Cas 1 : 2 000 W de chauffe saisis (durée calculée), humidification fixée par la mise en régime
        expect(c1.installed.heatW).toMatchObject({ installedW: 2000, entered: true });
        expect(c1.installed.heatW.maintenanceW).toBeCloseTo(176.37, 2);
        expect(c1.installed.humidificationW.installedW).toBeCloseTo(340.48, 2);
        expect(c1.installed.humidificationW.maintenanceW).toBeCloseTo(159.67, 2);
        // Cas 2 : mise en régime en 60 min (température) et 30 min (humidité), plus de durée calculée avec le maintien
        expect(c2.rampTemperature.winter.requiredW).toBeCloseTo(580.92, 2);
        expect(c2.rampTemperature.winter.minutes).toBeUndefined();
        expect(c2.installed.coldW.installedW).toBeCloseTo(544.53, 2);
        expect(c2.installed.dryingW.installedW).toBeCloseTo(172.26, 2);
    });
});

describe("Enceinte climatique v2 : méthode", () => {
    it("régulation PID : réglages validés (section 8.3), étages et plage du variateur signalés, options", () => {
        const [c1, c3] = ["case1", "case3"].map((key) => chamberPro(CASES[key]));
        expect(c1.pid.loops.heat.xp).toBeCloseTo(2.567, 3);
        expect(c1.pid.loops.cold.xp).toBeCloseTo(0.446, 3);
        expect(c1.pid.loops.heat.ti).toBeCloseTo(640, 6);
        expect(c1.pid.warnings.map((w) => w.text).join(" ")).toContain("découpage en étages");
        expect(c3.pid.loops.cold).toMatchObject({ belowRange: true, actuatorMinPct: 30 });
        expect(c3.pid.warnings.map((w) => w.text).join(" ")).toContain("by-pass de gaz chaud");
        expect(c3.alerts.find((a) => a.code === "range")).toMatchObject({ kind: "warning", section: 9 });
        expect(c1.alerts.find((a) => a.code === "condensation")).toMatchObject({ kind: "info", section: 5 });
        // By-pass de gaz chaud : plus de plage minimale ; PID : Td = θ / 2 ; robuste (τc = 2 θ) : Xp plus large
        const options = chamberPro({ ...CASES.case3, pid: { actuators: { cold: "hot_gas" }, derivative: true, tauFactor: 2 } });
        expect(options.pid.loops.cold.belowRange).toBe(false);
        expect(options.pid.loops.cold.td).toBeCloseTo(40, 6);
        expect(options.pid.loops.cold.xp).toBeCloseTo(c3.pid.loops.cold.xp * 1.5, 6);
        // Tout ou rien en ± 0,5 K : signalé
        expect(chamberPro({ ...CASES.case3, pid: { actuators: { cold: "on_off" } } }).pid.warnings.map((w) => w.code)).toContain("onOff");
        // Matériaux hygroscopiques : constante de temps d'humidité plus longue
        const hygro = chamberPro({ ...CASES.case1, pid: { hygroMassKg: 100, hygroCapacity: 0.1 } });
        expect(hygro.pid.humidity.tauS).toBeGreaterThan(c1.pid.humidity.tauS);
    });

    it("module extérieur non chauffé : hiver à la température extérieure minimale (−5 °C par défaut)", () => {
        const heated = chamberSimple(simpleDefault({ kind: "modular_outdoor" }));
        const unheated = chamberSimple(simpleDefault({ kind: "modular_outdoor", frostProtected: false, outdoorMinT: NaN }));
        expect(heated.seasons.winter.local.t).toBe(5);
        expect(unheated.seasons.winter.local.t).toBe(-5);
        expect(chamberSimple(simpleDefault({ kind: "modular_outdoor", frostProtected: false, outdoorMinT: -12 })).seasons.winter.local.t).toBe(-12);
    });

    it("plafonne le COP à 5 (0,45 × Carnot)", () => {
        expect(carnotCop(32, 35)).toBe(5);
        expect(carnotCop(-3, 35)).toBeCloseTo((0.45 * 270.15) / 48, 10);
    });

    it("conseille l'évaporation au-dessus du point de rosée quand la batterie condense (cas 1), et plus à + 3 K", () => {
        const r = chamberPro(CASES.case1);
        expect(r.advice.join(" ")).toContain("au-dessus du point de rosée");
        const plus3 = chamberPro({ ...CASES.case1, cooling: { ...CASES.case1.cooling, surfaceOffset: 3 } });
        expect(plus3.seasons.summer.condensationKgH).toBe(0);
        expect(plus3.seasons.summer.humidificationKgH * 1000).toBeCloseTo(25.29, 2);
    });

    it("ne déduit les apports internes du chauffage que sur demande (C9)", () => {
        const credited = chamberPro({ ...CASES.case2, internalGainsInHeating: true });
        expect(credited.seasons.winter.heatW).toBeLessThan(chamberPro(CASES.case2).seasons.winter.heatW);
    });

    it("garde une consigne de 0 °C (C6) et le point de rosée de Bolton (C4)", () => {
        const r = chamberSimple(simpleDefault({ tSet: 0, rhSet: 80 }));
        expect(r.tSet).toBe(0);
        expect(r.td).toBeCloseTo(dewPoint(0, 80), 10);
    });

    it("signale le givrage de la batterie de séchage sous +2 °C de point de rosée, pas avec un dessiccateur", () => {
        expect(chamberSimple(simpleDefault({ tSet: 5, rhSet: 40 })).warnings.join(" ")).toContain("dessiccateur");
        expect(chamberSimple(simpleDefault({ tSet: 5, rhSet: 40, dryingType: "desiccant" })).alerts.filter((a) => a.code === "frost")).toHaveLength(0);
    });

    it("dessiccateur : 1 kWh par kg d'eau retirée", () => {
        const r = chamberPro({ ...CASES.case2, drying: { type: "desiccant", cop: NaN } });
        expect(r.seasons.summer.dryingW).toBeCloseTo(r.seasons.summer.dryingKgH * 1000 * 1.1, 6);
    });

    it("humidificateur adiabatique : refroidit l'air au lieu de le réchauffer", () => {
        const r = chamberPro({ ...CASES.case2, humidification: { type: "adiabatic", waterT: 15, efficiency: 1 } });
        expect(r.seasons.winter.humidifierSensibleW).toBeLessThan(0);
        expect(r.seasons.winter.humidificationW).toBe(0);
        expect(r.seasons.winter.heatW).toBeGreaterThan(chamberPro(CASES.case2).seasons.winter.heatW);
    });

    it("chauffage à eau chaude : hors de la consommation électrique", () => {
        const water = chamberPro({ ...CASES.case2, heating: { ...CASES.case2.heating, type: "hot_water" } });
        const electric = chamberPro(CASES.case2);
        expect(water.seasons.winter.consumptionKwhDay).toBeLessThan(electric.seasons.winter.consumptionKwhDay);
        expect(water.seasons.winter.thermalKwhDay).toBeCloseTo((electric.seasons.winter.consumptionKwhDay - water.seasons.winter.consumptionKwhDay), 6);
    });

    it("« froid permanent + réchauffage » : consommation et réchauffage retenus", () => {
        const r = chamberPro({ ...CASES.case3, cooling: { ...CASES.case3.cooling, regulation: "permanent_reheat" } });
        expect(r.consumptionKwhDay.summer).toBeCloseTo(r.regulation.permanentKwhDay.summer, 10);
        expect(r.sizing.heatW).toBeCloseTo(r.regulation.reheatW, 10);
    });

    it("sans contrainte ni puissance suffisante : consigne signalée non atteinte", () => {
        const r = chamberPro({ ...CASES.case1, heating: { ...CASES.case1.heating, installedW: 50 } });
        expect(r.warnings.join(" ")).toContain("consigne non atteinte");
        expect(r.warnings.join(" ")).toContain("inférieure à la puissance de maintien");
    });
});

describe("Enceinte climatique v2 : mode simple, profils et fluide", () => {
    it("mode simple : valeurs par défaut du document, consigne ICH long terme (= cas 2 sans air neuf)", () => {
        const r = chamberSimple(simpleDefault());
        expect(r.geo.volume).toBe(6);
        expect(r.u).toBeCloseTo(1 / (0.17 + 0.08 / 0.023), 10);
        expect(r.seasons.summer.internalW).toBe(260);
        expect(r.stabilityT.modulate).toBe(false);
        expect(simpleToFull(simpleDefault({ stabilityT: "1" })).cooling.regulation).toBe("modulating");
    });

    it("monobloc : volume en litres, ventilateur 60 W/m³, porte sur la face avant", () => {
        const r = chamberSimple(simpleDefault({ kind: "monobloc", sizeMode: "volume", volumeLiters: 1000 }));
        expect(r.geo.volume).toBeCloseTo(1, 10);
        expect(r.seasons.summer.lines.find((l) => l.key === "fans").watts).toBeCloseTo(60, 10);
        expect(volumeToDimensions(1000).height / volumeToDimensions(1000).length).toBeCloseTo(1.25, 10);
        expect(explain("simple", simpleDefault({ kind: "monobloc", sizeMode: "volume", volumeLiters: -5 }))).toBe("Volume utile : la valeur doit être strictement positive.");
    });

    it("modulaire extérieure : module plus chaud en été (+ 5 K au soleil), plus froid en hiver", () => {
        const shade = chamberSimple(simpleDefault({ kind: "modular_outdoor" }));
        const sun = chamberSimple(simpleDefault({ kind: "modular_outdoor", sun: true }));
        expect(shade.seasons.summer.local.t).toBe(35);
        expect(sun.seasons.summer.local.t).toBe(40);
        expect(shade.seasons.winter.local.t).toBe(5);
    });

    it("profils : ICH long terme par défaut, humidité non régulée pour le réfrigéré", () => {
        expect(PROFILES.find((p) => p.default).code).toBe("ich_long_term");
        expect(PROFILES.find((p) => p.code === "ich_refrigerated").rhSet).toBeNull();
        expect(PROFILES.some((p) => /aliment/i.test(p.sector))).toBe(false);
    });

    it("fluide R-290 en groupe autonome scellé : charge sous la limite IEC 60335-2-89", () => {
        const r = chamberPro(CASES.case3);
        expect(r.refrigerant.chargeKg).toBeCloseTo(0.15 * 2.23273, 4);
        const report = fluidReport(r.refrigerant, { installation: "self_contained", volume: 6, rulesCount: 0, refrigerants: FLUIDS, smallLabel: "Petite enceinte", place: "l'enceinte" });
        expect(report.warning).toBeNull();
        expect(report.lines.join(" ")).toContain("Petite enceinte : le R-600a (Isobutane) est aussi proposé.");
        expect(chamberPro({ ...CASES.case3, cooling: { ...CASES.case3.cooling, type: "chilled_water" } }).refrigerant).toBeNull();
    });

    it("explique les saisies refusées", () => {
        expect(explain("simple", simpleDefault({ length: -2 }))).toBe("Longueur : la valeur doit être strictement positive.");
        expect(explain("simple", simpleDefault({ rhSet: 120 }))).toBe("Humidité de consigne : la valeur doit être comprise entre 0 (exclu) et 100 %.");
        expect(chamberSimple(simpleDefault({ length: NaN }))).toBeNull();
        const badDoor = { ...CASES.case1, doors: [{ ...CASES.case1.doors[0], efficiency: 2 }] };
        expect(explain("pro", badDoor)).toBe("Porte 1 : efficacité de la protection : la valeur doit être comprise entre 0 et 100 %.");
    });

    it("calcule le bilan d'un appel direct (sans contrôle des saisies)", () => {
        expect(climaticChamber(CASES.case2).sizing.coldW).toBeCloseTo(chamberPro(CASES.case2).sizing.coldW, 10);
    });
});
