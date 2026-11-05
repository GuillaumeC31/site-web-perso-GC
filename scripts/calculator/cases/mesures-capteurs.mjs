/*
 * Jeux d'entrées de la catégorie Capteurs et mesures, pour le banc capture-golden.mjs.
 * Sondes PT100/PT1000 (contrôleur pt-sensor) et mise à l'échelle des signaux et transmetteurs
 * (contrôleur scaling), avec les valeurs Stimulus de chaque page de l'ancien site.
 */
const pt = "assets/controllers/pt_sensor_controller.js";
const scaling = "assets/controllers/scaling_controller.js";
// Tableaux de référence : construits en HTML par l'ancien contrôleur, non capturés ici (mêmes formules).
const ptOmit = ["tableBody", "tableSearch", "tableStep", "tableRange", "tableCount"];
const scalingOmit = ["tableBody", "tableStep", "tableCount", "tableSignalHeader", "tableShuntHeader"];
const scalingSpans = ["shuntVoltage", "alertSignal", "unitLabel", "minLabel", "maxLabel", "formulaSignal", "formulaPhysical", "physicalUnitLabel", "shuntCard"];
const outputTypes = [
    { value: "4-20", data: { min: 4, max: 20, unit: "mA" } },
    { value: "0-10", data: { min: 0, max: 10, unit: "V" } },
    { value: "0-20", data: { min: 0, max: 20, unit: "mA" } },
];

const ptCases = (temperatures, resistances) => [
    ...temperatures.map((t) => ({ method: "calculateResistanceFromTemp", inputs: { temperature: t }, outputs: ["resistance", "!alertRange"] })),
    ...resistances.map((r) => ({ method: "calculateTempFromResistance", inputs: { resistance: r }, outputs: ["temperature", "!alertRange"] })),
];

export const cases = [
    {
        tool: "pt100",
        controller: pt,
        identifier: "pt-sensor",
        values: { r0: 100 },
        omit: ptOmit,
        spans: ["alertRange"],
        cases: ptCases([0, 25, 37.5, 100, -50, -200, 850, 900, -210, ""], [100, 109.73, 138.51, 80.31, 18.52, 390.48, 400, 10, ""]),
    },
    {
        tool: "pt1000",
        controller: pt,
        identifier: "pt-sensor",
        values: { r0: 1000 },
        omit: ptOmit,
        spans: ["alertRange"],
        cases: ptCases([0, 25, -40, 150], [1000, 1097.35, 842.71, 1573.25]),
    },
    {
        tool: "signal-4-20ma",
        controller: scaling,
        identifier: "scaling",
        values: { signalMin: 4, signalMax: 20, signalUnit: "mA" },
        omit: [...scalingOmit, "outputType", "signalType", "shuntCard"],
        spans: scalingSpans,
        initial: { minSignal: 4, maxSignal: 20, minPhysical: 0, maxPhysical: 100, physicalUnit: "bar" },
        cases: [
            { method: "calculatePhysical", inputs: { signal: 12 }, outputs: ["physical", "shuntVoltage", "alertSignal"] },
            { method: "calculatePhysical", inputs: { signal: 4 }, outputs: ["physical", "shuntVoltage", "alertSignal"] },
            { method: "calculatePhysical", inputs: { signal: 20 }, outputs: ["physical", "shuntVoltage", "alertSignal"] },
            { method: "calculatePhysical", inputs: { signal: 3.5 }, outputs: ["physical", "shuntVoltage", "alertSignal"] },
            { method: "calculatePhysical", inputs: { signal: 20.5 }, outputs: ["physical", "shuntVoltage", "alertSignal"] },
            { method: "calculatePhysical", inputs: { signal: 21 }, outputs: ["physical", "shuntVoltage", "alertSignal"] },
            { method: "calculatePhysical", inputs: { signal: 8, minPhysical: -50, maxPhysical: 150 }, outputs: ["physical", "shuntVoltage", "alertSignal"] },
            { method: "calculateSignal", inputs: { physical: 75 }, outputs: ["signal", "shuntVoltage", "alertSignal"] },
            { method: "calculateSignal", inputs: { physical: 7.5, minPhysical: 0, maxPhysical: 10 }, outputs: ["signal", "shuntVoltage", "alertSignal"] },
            { method: "calculateSignal", inputs: { physical: 120 }, outputs: ["signal", "shuntVoltage", "alertSignal"] },
            { method: "calculatePhysical", inputs: { signal: 12, minPhysical: 50, maxPhysical: 50 }, outputs: ["physical"] },
            { method: "calculatePhysical", inputs: { signal: "" }, outputs: ["physical"] },
        ],
    },
    {
        tool: "signal-0-20ma",
        controller: scaling,
        identifier: "scaling",
        values: { signalMin: 0, signalMax: 20, signalUnit: "mA" },
        omit: [...scalingOmit, "outputType", "signalType", "shuntCard"],
        spans: scalingSpans,
        initial: { minSignal: 0, maxSignal: 20, minPhysical: 0, maxPhysical: 100, physicalUnit: "bar" },
        cases: [
            { method: "calculatePhysical", inputs: { signal: 10 }, outputs: ["physical", "shuntVoltage", "alertSignal"] },
            { method: "calculatePhysical", inputs: { signal: 0 }, outputs: ["physical", "shuntVoltage", "alertSignal"] },
            { method: "calculatePhysical", inputs: { signal: -1 }, outputs: ["physical", "shuntVoltage", "alertSignal"] },
            { method: "calculateSignal", inputs: { physical: 25 }, outputs: ["signal", "shuntVoltage"] },
        ],
    },
    {
        tool: "signal-0-10v",
        controller: scaling,
        identifier: "scaling",
        values: { signalMin: 0, signalMax: 10, signalUnit: "V" },
        omit: [...scalingOmit, "outputType", "signalType", "shuntCard"],
        spans: scalingSpans,
        initial: { minSignal: 0, maxSignal: 10, minPhysical: 0, maxPhysical: 100, physicalUnit: "bar" },
        cases: [
            { method: "calculatePhysical", inputs: { signal: 5 }, outputs: ["physical", "alertSignal"] },
            { method: "calculatePhysical", inputs: { signal: 2.5, minPhysical: -20, maxPhysical: 80 }, outputs: ["physical", "alertSignal"] },
            { method: "calculatePhysical", inputs: { signal: 11 }, outputs: ["physical", "alertSignal"] },
            { method: "calculateSignal", inputs: { physical: 33.3 }, outputs: ["signal"] },
        ],
    },
    {
        tool: "transmetteur-humidite",
        controller: scaling,
        identifier: "scaling",
        values: { signalMin: 4, signalMax: 20, signalUnit: "mA", fixedMinPhysical: 0, fixedMaxPhysical: 100, physicalUnitFixed: "% RH" },
        omit: [...scalingOmit, "signalType", "physicalUnit"],
        spans: scalingSpans,
        selects: ["outputType"],
        selectOptions: { outputType: outputTypes },
        initial: { minSignal: 4, maxSignal: 20, minPhysical: 0, maxPhysical: 100 },
        cases: [
            { method: "calculatePhysical", inputs: { signal: 12 }, outputs: ["physical", "shuntVoltage", "alertSignal"] },
            { method: "calculateSignal", inputs: { physical: 65 }, outputs: ["signal", "shuntVoltage"] },
            {
                steps: [{ set: { outputType: "0-10" } }, { call: "changeOutputType" }, { set: { signal: 7.5 } }, { call: "calculatePhysical" }],
                outputs: ["physical", "minSignal", "maxSignal", "alertSignal", "!shuntCard"],
            },
            {
                steps: [{ set: { outputType: "0-20" } }, { call: "changeOutputType" }, { set: { signal: 5 } }, { call: "calculatePhysical" }],
                outputs: ["physical", "shuntVoltage", "!shuntCard"],
            },
        ],
    },
    {
        tool: "transmetteur-temperature",
        controller: scaling,
        identifier: "scaling",
        values: { signalMin: 4, signalMax: 20, signalUnit: "mA", physicalUnitFixed: "°C" },
        omit: [...scalingOmit, "signalType", "physicalUnit"],
        spans: scalingSpans,
        selects: ["outputType"],
        selectOptions: { outputType: outputTypes },
        initial: { minSignal: 4, maxSignal: 20, minPhysical: -20, maxPhysical: 80 },
        cases: [
            { method: "calculatePhysical", inputs: { signal: 12 }, outputs: ["physical", "shuntVoltage", "alertSignal"] },
            { method: "calculateSignal", inputs: { physical: 0 }, outputs: ["signal", "shuntVoltage"] },
            { method: "calculatePhysical", inputs: { signal: 16, minPhysical: -40, maxPhysical: 120 }, outputs: ["physical"] },
            { method: "calculatePhysical", inputs: { signal: 2 }, outputs: ["physical", "alertSignal"] },
        ],
    },
];
