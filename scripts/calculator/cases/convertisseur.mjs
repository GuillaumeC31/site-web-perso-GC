/*
 * Jeux d'entrées de la catégorie Convertisseur, pour le banc capture-golden.mjs.
 * Chaque cas reproduit l'usage de l'ancienne page : choix de la grandeur (qui remplit les listes
 * d'unités), saisie de la valeur, choix des unités, conversion.
 */
const calculator = "assets/controllers/calculator_controller.js";

/** Scénario : grandeur, valeur, unité d'origine, unité cible. */
const conversion = (type, value, from, to) => ({
    steps: [
        { set: { convType: type } },
        { call: "updateConvUnits" },
        { set: { convValIn: value, convUnitIn: from, convUnitOut: to } },
        { call: "calculateConv" },
    ],
    outputs: ["convValOut"],
});

export const cases = [
    {
        tool: "unites",
        controller: calculator,
        identifier: "calculator",
        selects: ["convType", "convUnitIn", "convUnitOut"],
        selectOptions: { convType: ["pressure", "temperature", "power", "energy", "speed"] },
        cases: [
            conversion("pressure", 1, "bar", "psi"),
            conversion("pressure", 100, "psi", "bar"),
            conversion("pressure", 1013.25, "hpa", "atm"),
            conversion("pressure", 2.5, "bar", "pa"),
            conversion("pressure", 7, "bar", "bar"),
            conversion("temperature", 20, "c", "f"),
            conversion("temperature", 98.6, "f", "c"),
            conversion("temperature", 0, "k", "c"),
            conversion("temperature", -40, "c", "k"),
            conversion("temperature", 25, "c", "c"),
            conversion("power", 1, "kw", "ch"),
            conversion("power", 100, "hp", "kw"),
            conversion("power", 1500, "w", "hp"),
            conversion("energy", 1, "kwh", "j"),
            conversion("energy", 1000, "cal", "btu"),
            conversion("energy", 3600, "j", "kwh"),
            conversion("speed", 90, "kmh", "ms"),
            conversion("speed", 1500, "rpm", "rads"),
            conversion("speed", 10, "ms", "rpm"),
            conversion("pressure", "", "bar", "psi"),
            conversion("pressure", -1, "bar", "psi"),
        ],
    },
];
