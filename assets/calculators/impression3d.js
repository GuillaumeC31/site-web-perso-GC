/*
 * Calculs de la catégorie Impression 3D (logique pure, sans DOM) : calibrage des steps/mm et
 * coût d'une impression (matière + électricité).
 *
 * Portage de l'ancien site (assets/controllers/calculator_controller.js de guillaume_c), avec la
 * règle A3 (distances, masse, durée, puissance positives) : docs/CALCULATRICE_ANOMALIES.md.
 *
 * Testé par : impression3d.test.js (valeurs de référence golden/impression-3d.json + corrections).
 * Utilisé par : assets/controllers/calculator/steps_mm_controller.js, cout_impression_controller.js
 */
import { inputChecker } from "./inputs.js";

/** Décimales d'affichage de l'ancien site. */
export const DECIMALS = { steps: 2, cost: 2 };

/** Règles de saisie de chaque calcul. */
export const INPUTS = {
    stepsPerMm: (current, expected, measured) => [
        { value: current, label: "Steps/mm actuel", rule: "positive" },
        { value: expected, label: "Distance demandée", rule: "positive" },
        { value: measured, label: "Distance obtenue", rule: "positive" },
    ],
    printCost: (weight, spoolPrice, hours, minutes, power, priceKwh) => [
        { value: weight, label: "Poids de la pièce", rule: "positive" },
        { value: spoolPrice, label: "Prix de la bobine", rule: "nonNegative" },
        // Durée : heures et minutes facultatives séparément (vide = 0), au moins une minute au total
        { value: Number.isNaN(hours) ? 0 : hours, label: "Heures", rule: "nonNegative" },
        { value: Number.isNaN(minutes) ? 0 : minutes, label: "Minutes", rule: "nonNegative" },
        { value: power, label: "Puissance de l'imprimante", rule: "positive" },
        { value: priceKwh, label: "Prix du kWh", rule: "nonNegative" },
    ],
};

/** Contrôles entre champs. */
const RELATIONS = {
    printCost: (_weight, _spool, hours, minutes) => (printHours(hours, minutes) > 0 ? null : "Indiquez la durée d'impression."),
};

const checker = inputChecker(INPUTS, RELATIONS);

/**
 * Renvoie le message d'erreur d'un calcul (null si les saisies sont valides ou incomplètes).
 */
export const explain = checker.explain;

/** Durée en heures à partir des heures et minutes saisies (champ vide = 0). */
export function printHours(hours, minutes) {
    return (Number.isNaN(hours) ? 0 : hours) + (Number.isNaN(minutes) ? 0 : minutes) / 60;
}

/**
 * Nouveau réglage steps/mm : ancien × distance demandée / distance obtenue.
 *
 * @returns {number|null}
 */
export function stepsPerMm(current, expected, measured) {
    return checker.valid("stepsPerMm", current, expected, measured) ? (current * expected) / measured : null;
}

/**
 * Coût d'une impression : matière (poids × prix de la bobine de 1 kg) + électricité (durée × puissance × prix du kWh).
 *
 * @param {number} weight     poids de la pièce (g)
 * @param {number} spoolPrice prix de la bobine de 1 kg (€)
 * @param {number} hours      heures (NaN si vide)
 * @param {number} minutes    minutes (NaN si vide)
 * @param {number} power      puissance de l'imprimante (W)
 * @param {number} priceKwh   prix du kWh (€)
 * @returns {{total: number, material: number, electricity: number}|null}
 */
export function printCost(weight, spoolPrice, hours, minutes, power, priceKwh) {
    if (!checker.valid("printCost", weight, spoolPrice, hours, minutes, power, priceKwh)) {
        return null;
    }
    const material = (weight / 1000) * spoolPrice;
    const electricity = ((printHours(hours, minutes) * power) / 1000) * priceKwh;

    return { total: material + electricity, material, electricity };
}
