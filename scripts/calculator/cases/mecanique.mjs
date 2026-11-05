/*
 * Jeux d'entrées de la catégorie Mécanique, pour le banc capture-golden.mjs.
 * Engrenages (rapport, vitesse, couple, alertes d'interférence et de dent de chasse), vérin, poutre.
 */
const calculator = "assets/controllers/calculator_controller.js";
const omit = ["convType"];
const gearOutputs = ["gearRatio", "gearVout", "gearCout", "gearAlerts"];

export const cases = [
    {
        tool: "engrenages",
        controller: calculator,
        identifier: "calculator",
        omit,
        spans: ["gearAlerts"],
        defaults: { gearZ3: "", gearZ4: "", gearEff: 95 },
        cases: [
            { method: "calculateGears", inputs: { gearZ1: 12, gearZ2: 48, gearVin: 3000, gearCin: 5 }, outputs: gearOutputs },
            { method: "calculateGears", inputs: { gearZ1: 13, gearZ2: 47, gearVin: 1500, gearCin: 10 }, outputs: gearOutputs },
            { method: "calculateGears", inputs: { gearZ1: 20, gearZ2: 60, gearZ3: 15, gearZ4: 45, gearVin: 3000, gearCin: 2, gearEff: 90 }, outputs: gearOutputs },
            { method: "calculateGears", inputs: { gearZ1: 17, gearZ2: 53, gearZ3: 19, gearZ4: 61, gearVin: 1450, gearCin: 12.5 }, outputs: gearOutputs },
            { method: "calculateGears", inputs: { gearZ1: 40, gearZ2: 20, gearVin: 1000, gearCin: 3 }, outputs: gearOutputs },
            { method: "calculateGears", inputs: { gearZ1: 12, gearZ2: 48, gearVin: "", gearCin: "" }, outputs: gearOutputs },
            { method: "calculateGears", inputs: { gearZ1: 12, gearZ2: 48, gearVin: 3000, gearCin: 5, gearEff: 0 }, outputs: gearOutputs },
            { method: "calculateGears", inputs: { gearZ1: 12.5, gearZ2: 48, gearVin: 3000, gearCin: 5 }, outputs: gearOutputs },
            { method: "calculateGears", inputs: { gearZ1: 0, gearZ2: 48, gearVin: 3000, gearCin: 5 }, outputs: gearOutputs },
            { method: "calculateGears", inputs: { gearZ1: 12, gearZ2: 48, gearVin: -3000, gearCin: 5, gearEff: 120 }, outputs: gearOutputs },
        ],
    },
    {
        tool: "verin",
        controller: calculator,
        identifier: "calculator",
        omit,
        cases: [
            { method: "calculateCylinder", inputs: { cylP: 6, cylD: 32, cylDrod: 12 }, outputs: ["cylFpushN", "cylFpushKg", "cylFpullN", "cylFpullKg"] },
            { method: "calculateCylinder", inputs: { cylP: 10, cylD: 50, cylDrod: 20 }, outputs: ["cylFpushN", "cylFpushKg", "cylFpullN", "cylFpullKg"] },
            { method: "calculateCylinder", inputs: { cylP: 160, cylD: 80, cylDrod: 45 }, outputs: ["cylFpushN", "cylFpushKg", "cylFpullN", "cylFpullKg"] },
            { method: "calculateCylinder", inputs: { cylP: 6, cylD: 32, cylDrod: "" }, outputs: ["cylFpushN", "cylFpushKg", "cylFpullN", "cylFpullKg"] },
            { method: "calculateCylinder", inputs: { cylP: 6, cylD: 32, cylDrod: 40 }, outputs: ["cylFpushN", "cylFpushKg", "cylFpullN", "cylFpullKg"] },
            { method: "calculateCylinder", inputs: { cylP: 0, cylD: 32, cylDrod: 12 }, outputs: ["cylFpushN", "cylFpushKg", "cylFpullN", "cylFpullKg"] },
            { method: "calculateCylinder", inputs: { cylP: 6, cylD: -32, cylDrod: 12 }, outputs: ["cylFpushN", "cylFpushKg", "cylFpullN", "cylFpullKg"] },
        ],
    },
    {
        tool: "flexion-poutre",
        controller: calculator,
        identifier: "calculator",
        omit,
        cases: [
            { method: "calculateBeam", inputs: { beamF: 1000, beamL: 500, beamE: 210000, beamI: 416000 }, outputs: ["beamDeflect", "beamMoment"] },
            { method: "calculateBeam", inputs: { beamF: 250, beamL: 1200, beamE: 69000, beamI: 125000 }, outputs: ["beamDeflect", "beamMoment"] },
            { method: "calculateBeam", inputs: { beamF: 50, beamL: 300, beamE: 210000, beamI: 833.3 }, outputs: ["beamDeflect", "beamMoment"] },
            { method: "calculateBeam", inputs: { beamF: -1000, beamL: 500, beamE: 210000, beamI: 416000 }, outputs: ["beamDeflect", "beamMoment"] },
            { method: "calculateBeam", inputs: { beamF: 1000, beamL: -500, beamE: 210000, beamI: 416000 }, outputs: ["beamDeflect", "beamMoment"] },
            { method: "calculateBeam", inputs: { beamF: 1000, beamL: 500, beamE: 0, beamI: 416000 }, outputs: ["beamDeflect", "beamMoment"] },
            { method: "calculateBeam", inputs: { beamF: 1000, beamL: 500, beamE: 210000, beamI: "" }, outputs: ["beamDeflect", "beamMoment"] },
        ],
    },
];
