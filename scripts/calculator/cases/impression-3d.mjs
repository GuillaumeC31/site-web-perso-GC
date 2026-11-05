/*
 * Jeux d'entrées de la catégorie Impression 3D, pour le banc capture-golden.mjs.
 * Calibrage des steps/mm et coût d'une impression (matière + électricité).
 */
const calculator = "assets/controllers/calculator_controller.js";
const omit = ["convType"];

export const cases = [
    {
        tool: "steps-mm",
        controller: calculator,
        identifier: "calculator",
        omit,
        cases: [
            { method: "calculateSteps", inputs: { oldSteps: 93, expectedDist: 100, actualDist: 98.5 }, outputs: ["resultSteps"] },
            { method: "calculateSteps", inputs: { oldSteps: 80, expectedDist: 100, actualDist: 100.8 }, outputs: ["resultSteps"] },
            { method: "calculateSteps", inputs: { oldSteps: 415, expectedDist: 50, actualDist: 47.2 }, outputs: ["resultSteps"] },
            { method: "calculateSteps", inputs: { oldSteps: 93, expectedDist: 100, actualDist: 0 }, outputs: ["resultSteps"] },
            { method: "calculateSteps", inputs: { oldSteps: 93, expectedDist: 100, actualDist: -98.5 }, outputs: ["resultSteps"] },
            { method: "calculateSteps", inputs: { oldSteps: "", expectedDist: 100, actualDist: 98.5 }, outputs: ["resultSteps"] },
        ],
    },
    {
        tool: "cout-impression",
        controller: calculator,
        identifier: "calculator",
        omit,
        cases: [
            { method: "calculateCost", inputs: { weightPart: 150, priceSpool: 20, timePrintHours: 4, timePrintMinutes: 30, powerPrint: 150, priceKwh: 0.25 }, outputs: ["resultCost"] },
            { method: "calculateCost", inputs: { weightPart: 42, priceSpool: 24.9, timePrintHours: 1, timePrintMinutes: 45, powerPrint: 350, priceKwh: 0.194 }, outputs: ["resultCost"] },
            { method: "calculateCost", inputs: { weightPart: 1000, priceSpool: 18, timePrintHours: 36, timePrintMinutes: "", powerPrint: 1000, priceKwh: 0.1568 }, outputs: ["resultCost"] },
            { method: "calculateCost", inputs: { weightPart: 20, priceSpool: 25, timePrintHours: "", timePrintMinutes: 40, powerPrint: 200, priceKwh: 0.25 }, outputs: ["resultCost"] },
            { method: "calculateCost", inputs: { weightPart: 150, priceSpool: 20, timePrintHours: "", timePrintMinutes: "", powerPrint: 150, priceKwh: 0.25 }, outputs: ["resultCost"] },
            { method: "calculateCost", inputs: { weightPart: -150, priceSpool: 20, timePrintHours: 4, timePrintMinutes: 0, powerPrint: 150, priceKwh: 0.25 }, outputs: ["resultCost"] },
            { method: "calculateCost", inputs: { weightPart: 150, priceSpool: 20, timePrintHours: 4, timePrintMinutes: 75, powerPrint: 150, priceKwh: 0.25 }, outputs: ["resultCost"] },
            { method: "calculateCost", inputs: { weightPart: 150, priceSpool: "", timePrintHours: 4, timePrintMinutes: 0, powerPrint: 150, priceKwh: 0.25 }, outputs: ["resultCost"] },
        ],
    },
];
