/*
 * Jeux d'entrées de la catégorie Éclairage, pour le banc capture-golden.mjs : adresse DMX (10 interrupteurs
 * DIP), vieillissement UV (dose, durée, cycles), éclairage horticole (besoins, architecte de spectre,
 * simulateur de panneau, DLI). Valeurs par défaut des anciens formulaires (« initial »).
 * Les graphiques ApexCharts de l'éclairage horticole sont remplacés par un objet vide (« stub ») :
 * seuls les calculs sont capturés.
 */
const dmx = "assets/controllers/dmx_controller.js";
const dmxOutputs = ["addressInput", "@switch:state", "calculationDetails", "binaryLabel"];

/** Graphique factice : l'ancien contrôleur crée et met à jour un graphique ApexCharts. */
class FakeChart {
    render() {}
    updateSeries() {}
}

const bands = ["UV", "Blue", "Cold", "Green", "Warm", "Red", "IR"];
const archInitial = { archSUV: 0, archSBlue: 15, archSCold: 15, archSGreen: 10, archSWarm: 15, archSRed: 35, archSIR: 10 };
const archOutputs = ["resArchUV", "resArchBlue", "resArchCold", "resArchGreen", "resArchWarm", "resArchRed", "resArchIR", "resArchTotal", "resArchWatts", "archSum", "!archError"];
const sliders = (values) => Object.fromEntries(bands.map((band, index) => [`archS${band}`, values[index]]));

export const cases = [
    {
        tool: "adresse-dmx",
        controller: dmx,
        identifier: "dmx",
        spans: ["calculationDetails", "binaryLabel"],
        repeat: { switch: { count: 10, data: (index) => ({ bit: index, state: "off" }) } },
        initial: { addressInput: 1 },
        cases: [
            ...[1, 2, 10, 33, 100, 255, 256, 511, 512, 513, 0, -5, ""].map((address) => ({ steps: [{ set: { addressInput: address } }, { call: "onAddressInput" }], outputs: dmxOutputs })),
            { steps: [{ set: { addressInput: 7 } }, { call: "onAddressInput" }, { call: "toggleSwitch", event: "switch", eventIndex: 3 }], outputs: dmxOutputs },
            { steps: [{ set: { addressInput: 1 } }, { call: "onAddressInput" }, { call: "toggleSwitch", event: "switch", eventIndex: 0 }], outputs: dmxOutputs },
            { steps: [{ set: { addressInput: 512 } }, { call: "onAddressInput" }, { call: "toggleSwitch", event: "switch", eventIndex: 0 }], outputs: dmxOutputs },
            { steps: [{ set: { addressInput: 511 } }, { call: "onAddressInput" }, { call: "toggleSwitch", event: "switch", eventIndex: 9 }], outputs: dmxOutputs },
            { steps: [{ set: { addressInput: 41 } }, { call: "increment" }], outputs: dmxOutputs },
            { steps: [{ set: { addressInput: 512 } }, { call: "increment" }], outputs: dmxOutputs },
            { steps: [{ set: { addressInput: 41 } }, { call: "decrement" }], outputs: dmxOutputs },
            { steps: [{ set: { addressInput: 1 } }, { call: "decrement" }], outputs: dmxOutputs },
        ],
    },
    {
        tool: "vieillissement-uv",
        controller: "assets/controllers/uv_aging_controller.js",
        identifier: "uv-aging",
        spans: ["resDoseKj", "resDoseMj", "resTimeHours", "resTimeDays", "resMachineHours", "resMachineDays"],
        initial: { irradianceToDose: 0.89, hoursToDose: 1000, targetDose: 3204, irradianceToTime: 0.89, cycleUvHours: 8, cycleDarkHours: 4, cycleTargetUv: 1000 },
        cases: [
            { steps: [{ call: "calcDose" }], outputs: ["resDoseKj", "resDoseMj"] },
            { steps: [{ set: { irradianceToDose: 0.71, hoursToDose: 500 } }, { call: "calcDose" }], outputs: ["resDoseKj", "resDoseMj"] },
            { steps: [{ set: { irradianceToDose: 1.55, hoursToDose: 2000 } }, { call: "calcDose" }], outputs: ["resDoseKj", "resDoseMj"] },
            { steps: [{ set: { irradianceToDose: -0.89 } }, { call: "calcDose" }], outputs: ["resDoseKj", "resDoseMj"] },
            { steps: [{ call: "calcTime" }], outputs: ["resTimeHours", "resTimeDays"] },
            { steps: [{ set: { targetDose: 5000, irradianceToTime: 0.71 } }, { call: "calcTime" }], outputs: ["resTimeHours", "resTimeDays"] },
            { steps: [{ set: { targetDose: 1000, irradianceToTime: 1.2 } }, { call: "calcTime" }], outputs: ["resTimeHours", "resTimeDays"] },
            { steps: [{ set: { irradianceToTime: 0 } }, { call: "calcTime" }], outputs: ["resTimeHours", "resTimeDays"] },
            { steps: [{ call: "calcCycles" }], outputs: ["resMachineHours", "resMachineDays"] },
            { steps: [{ set: { cycleUvHours: 4, cycleDarkHours: 4, cycleTargetUv: 500 } }, { call: "calcCycles" }], outputs: ["resMachineHours", "resMachineDays"] },
            { steps: [{ set: { cycleUvHours: 102, cycleDarkHours: 18, cycleTargetUv: 2000 } }, { call: "calcCycles" }], outputs: ["resMachineHours", "resMachineDays"] },
            { steps: [{ set: { cycleUvHours: 0 } }, { call: "calcCycles" }], outputs: ["resMachineHours", "resMachineDays"] },
            { steps: [{ set: { cycleDarkHours: -4 } }, { call: "calcCycles" }], outputs: ["resMachineHours", "resMachineDays"] },
        ],
    },
    {
        tool: "eclairage-phyto",
        controller: "assets/controllers/phyto_controller.js",
        identifier: "phyto",
        selects: ["reqPlant", "reqStage", "reqLoss"],
        selectOptions: { reqPlant: ["low", "medium", "high", "ultra"], reqStage: ["seedling", "vegetative", "production"], reqLoss: ["0", "10", "20", "30"] },
        spans: [
            "resReqPpfd", "resReqPpfdRange", "resReqDli", "resReqPpf", "resReqWatts", "resReqPpeNote", "archError", "archSum",
            "archLUV", "archLBlue", "archLCold", "archLGreen", "archLWarm", "archLRed", "archLIR",
            "resArchUV", "resArchBlue", "resArchCold", "resArchGreen", "resArchWarm", "resArchRed", "resArchIR", "resArchTotal", "resArchWatts", "chartArch",
            "resSimPpf", "resSimWatts", "simSafetyWarning", "chartSim", "resDli1", "resDli2",
        ],
        omit: ["btnTransferArch"],
        stub: { ApexCharts: FakeChart },
        initial: {
            reqPlant: "high", reqStage: "production", reqArea: 1, reqHours: 12, reqLoss: "20", reqPpe: 2.5,
            archPpf: 800, archLedWatts: 2.2, ...archInitial,
            simUV: 0, simBlue: 0, simCold: 0, simGreen: 0, simWarm: 0, simRed: 0, simIR: 0, simPPE: 2.5, simLedWatts: 2.2,
            dliPpfdInput: 600, dliHoursInput: 12, dliTargetInput: 30, dliPpfdInput2: 600,
        },
        cases: [
            ...[["high", "production"], ["low", "seedling"], ["medium", "vegetative"], ["ultra", "production"], ["high", "seedling"]].map(([plant, stage]) => ({
                steps: [{ set: { reqPlant: plant, reqStage: stage } }, { call: "calcReq" }], outputs: ["resReqPpfd", "resReqPpfdRange", "resReqDli", "resReqPpf", "resReqWatts", "resReqPpeNote"],
            })),
            { steps: [{ set: { reqArea: 2.4, reqHours: 16, reqLoss: "10", reqPpe: 3.1 } }, { call: "calcReq" }], outputs: ["resReqPpfd", "resReqDli", "resReqPpf", "resReqWatts", "resReqPpeNote"] },
            { steps: [{ set: { reqHours: 30, reqLoss: "0" } }, { call: "calcReq" }], outputs: ["resReqDli", "resReqPpf", "resReqWatts"] },
            { steps: [{ set: { reqArea: -1 } }, { call: "calcReq" }], outputs: ["resReqPpf", "resReqWatts"] },
            { steps: [{ set: { reqPpe: 0 } }, { call: "calcReq" }], outputs: ["resReqWatts", "resReqPpeNote"] },
            { steps: [{ call: "calcArch" }], outputs: archOutputs },
            { steps: [{ set: { archPpf: 960, archLedWatts: 3 } }, { call: "calcArch" }], outputs: archOutputs },
            { steps: [{ set: { archPpf: 1500, ...sliders([5, 20, 10, 5, 10, 40, 10]) } }, { call: "calcArch" }], outputs: archOutputs },
            { steps: [{ set: sliders([0, 50, 0, 0, 0, 50, 0]) }, { call: "calcArch" }], outputs: archOutputs },
            { steps: [{ set: sliders([0, 20, 20, 10, 15, 35, 10]) }, { call: "calcArch" }], outputs: archOutputs },
            { steps: [{ set: { archSRed: 50 } }, { call: "balanceSliders", event: "archSRed" }], outputs: [...archOutputs, "archSUV", "archSBlue", "archSCold", "archSGreen", "archSWarm", "archSRed", "archSIR"] },
            { steps: [{ set: sliders([0, 0, 0, 0, 0, 0, 0]) }, { set: { archSBlue: 30 } }, { call: "balanceSliders", event: "archSBlue" }], outputs: [...archOutputs, "archSUV", "archSBlue", "archSCold", "archSGreen", "archSWarm", "archSRed", "archSIR"] },
            { steps: [{ set: { simBlue: 20, simRed: 60, simCold: 10 } }, { call: "calcSim" }], outputs: ["resSimPpf", "resSimWatts", "!simSafetyWarning"] },
            { steps: [{ set: { simUV: 4, simBlue: 30, simRed: 80, simIR: 6, simPPE: 2.8, simLedWatts: 3 } }, { call: "calcSim" }], outputs: ["resSimPpf", "resSimWatts", "!simSafetyWarning"] },
            { steps: [{ set: { simRed: -10 } }, { call: "calcSim" }], outputs: ["resSimPpf", "resSimWatts", "!simSafetyWarning"] },
            { steps: [{ call: "calcDli1" }], outputs: ["resDli1"] },
            { steps: [{ set: { dliPpfdInput: 350, dliHoursInput: 18 } }, { call: "calcDli1" }], outputs: ["resDli1"] },
            { steps: [{ set: { dliHoursInput: 30 } }, { call: "calcDli1" }], outputs: ["resDli1"] },
            { steps: [{ call: "calcDli2" }], outputs: ["resDli2"] },
            { steps: [{ set: { dliTargetInput: 17, dliPpfdInput2: 250 } }, { call: "calcDli2" }], outputs: ["resDli2"] },
            { steps: [{ set: { dliPpfdInput2: 0 } }, { call: "calcDli2" }], outputs: ["resDli2"] },
        ],
    },
];
