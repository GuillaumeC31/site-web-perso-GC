/*
 * Calculs de la catégorie Électronique (logique pure, sans DOM) : résistance pour LED, pont
 * diviseur de tension, autonomie d'une batterie. Le code couleur des résistances est dans
 * resistor_colors.js.
 *
 * Portage de l'ancien site (assets/controllers/calculator_controller.js de guillaume_c), avec la
 * règle A3 (saisies sans signification refusées, avec un message) et les corrections B1 (puissance
 * conseillée avec une marge de 2) et B2 (valeur E24 affichée avec ses décimales) :
 * docs/CALCULATRICE_ANOMALIES.md.
 *
 * Testé par : electronique.test.js (valeurs de référence golden/electronique.json + corrections).
 * Utilisé par : assets/controllers/calculator/*_controller.js (outils Électronique)
 */
import { inputChecker } from "./inputs.js";

/** Série normalisée E24 (une décade). */
export const E24 = [1.0, 1.1, 1.2, 1.3, 1.5, 1.6, 1.8, 2.0, 2.2, 2.4, 2.7, 3.0, 3.3, 3.6, 3.9, 4.3, 4.7, 5.1, 5.6, 6.2, 6.8, 7.5, 8.2, 9.1];

/**
 * Puissances nominales normalisées des résistances (W) : couches 1/8 à 2 W, puis résistances de
 * puissance (bobinées, boîtier céramique ou aluminium). Au-delà, valeur entière supérieure.
 */
export const POWER_RATINGS = [0.125, 0.25, 0.5, 1, 2, 3, 5, 7, 10, 15, 20, 25, 50, 100];

/** Marge appliquée à la puissance dissipée avant de choisir la puissance nominale (B1). */
export const POWER_MARGIN = 2;

/** Au-delà de cette puissance nominale (W), il faut une résistance de puissance. */
export const POWER_RESISTOR_ABOVE = 2;

/** Rendement retenu pour l'autonomie d'une batterie (marge de 30 %, affichée sur la page). */
export const BATTERY_EFFICIENCY = 0.7;

/** Tensions directes typiques proposées pour les LED (V), reprises de l'ancien site. */
export const LED_VOLTAGES = { red: 1.8, yellow: 2.0, green: 2.1, blueWhite: 3.2 };

/** Décimales d'affichage de l'ancien site. */
export const DECIMALS = { ledResistance: 1, ledPower: 3, dividerVoltage: 2, dividerCurrent: 3, dividerResistance: 1, batteryHours: 1 };

/** Règles de saisie de chaque calcul. */
export const INPUTS = {
    ledResistor: (supply, led, currentMilliAmps) => [
        { value: supply, label: "Alimentation", rule: "positive" },
        { value: led, label: "Tension de la LED", rule: "positive" },
        { value: currentMilliAmps, label: "Courant de la LED", rule: "positive" },
    ],
    voltageDivider: (vIn, r1, r2) => [
        { value: vIn, label: "Tension d'entrée", rule: "positive" },
        { value: r1, label: "Résistance R1", rule: "nonNegative" },
        { value: r2, label: "Résistance R2", rule: "nonNegative" },
    ],
    dividerMissingResistor: (vIn, vOut, _known, knownValue) => [
        { value: vIn, label: "Tension d'entrée", rule: "positive" },
        { value: vOut, label: "Tension de sortie ciblée", rule: "positive" },
        { value: knownValue, label: "Valeur de la résistance connue", rule: "positive" },
    ],
    batteryAutonomy: (capacity, current) => [
        { value: capacity, label: "Capacité", rule: "positive" },
        { value: current, label: "Consommation", rule: "positive" },
    ],
};

/** Contrôles entre champs. */
const RELATIONS = {
    ledResistor: (supply, led) => (supply > led ? null : "L'alimentation doit être supérieure à la tension de la LED."),
    voltageDivider: (_vIn, r1, r2) => (r1 + r2 > 0 ? null : "R1 et R2 ne peuvent pas être nulles toutes les deux."),
    dividerMissingResistor: (vIn, vOut) => (vIn > vOut ? null : "La tension de sortie doit être inférieure à la tension d'entrée."),
};

const checker = inputChecker(INPUTS, RELATIONS);

/**
 * Renvoie le message d'erreur d'un calcul (null si les saisies sont valides ou incomplètes).
 *
 * @param {keyof INPUTS} name nom du calcul
 * @param {...*}         args mêmes arguments que la fonction de calcul
 * @returns {string|null}
 */
export const explain = checker.explain;

/**
 * Valeur E24 immédiatement supérieure ou égale (méthode de l'ancien site).
 *
 * @param {number} ohms valeur théorique (> 0)
 * @returns {number} valeur normalisée
 */
export function e24Above(ohms) {
    let multiplier = 1;
    let mantissa = ohms;
    while (mantissa >= 10) {
        mantissa /= 10;
        multiplier *= 10;
    }
    while (mantissa < 1 && mantissa > 0) {
        mantissa *= 10;
        multiplier /= 10;
    }

    for (const value of E24) {
        if (value * multiplier >= ohms) {
            return value * multiplier;
        }
    }

    return E24[E24.length - 1] * multiplier;
}

/**
 * Puissance nominale conseillée pour une résistance (B1, décision du 2026-10-22) : première valeur
 * normalisée supérieure ou égale à 2 × la puissance dissipée.
 *
 * Liens : utilisée par ledResistor() et resistor_colors.js (suggestPowerRating).
 *
 * @param {number} watts puissance dissipée
 * @returns {{watts: number, powerResistor: boolean}} puissance nominale, et vrai s'il faut une résistance de puissance
 */
export function recommendedPowerRating(watts) {
    const needed = watts * POWER_MARGIN;
    const rating = POWER_RATINGS.find((value) => value >= needed) ?? Math.ceil(needed);

    return { watts: rating, powerResistor: rating > POWER_RESISTOR_ABOVE };
}

/**
 * Libellé d'une puissance nominale : « 1/8 W (0,125 W) », « 1/4 W (0,25 W) », « 1/2 W (0,5 W) », « 7 W ».
 *
 * @param {number} watts puissance nominale
 * @returns {string}
 */
export function powerLabel(watts) {
    const fractions = { 0.125: "1/8 W (0,125 W)", 0.25: "1/4 W (0,25 W)", 0.5: "1/2 W (0,5 W)" };

    return fractions[watts] ?? `${new Intl.NumberFormat("fr-FR").format(watts)} W`;
}

/**
 * Résistance série d'une LED : R = (V alim − V LED) / I, valeur E24 supérieure, puissance dissipée.
 *
 * @param {number} supply           tension d'alimentation (V)
 * @param {number} led              tension directe de la LED (V)
 * @param {number} currentMilliAmps courant de la LED (mA)
 * @returns {{resistance: number, e24: number, power: number, rating: {watts: number, powerResistor: boolean}}|null}
 */
export function ledResistor(supply, led, currentMilliAmps) {
    if (!checker.valid("ledResistor", supply, led, currentMilliAmps)) {
        return null;
    }
    const current = currentMilliAmps / 1000;
    const resistance = (supply - led) / current;
    const power = (supply - led) * current;

    return { resistance, e24: e24Above(resistance), power, rating: recommendedPowerRating(power) };
}

/**
 * Tension de sortie d'un pont diviseur : Vout = Vin × R2 / (R1 + R2), courant dans le pont.
 *
 * @returns {{vOut: number, currentMilliAmps: number}|null}
 */
export function voltageDivider(vIn, r1, r2) {
    if (!checker.valid("voltageDivider", vIn, r1, r2)) {
        return null;
    }

    return { vOut: vIn * (r2 / (r1 + r2)), currentMilliAmps: (vIn / (r1 + r2)) * 1000 };
}

/**
 * Résistance manquante d'un pont diviseur pour obtenir Vout.
 *
 * @param {number}       vIn        tension d'entrée (V)
 * @param {number}       vOut       tension de sortie ciblée (V)
 * @param {"R1"|"R2"}    known      résistance connue
 * @param {number}       knownValue valeur de la résistance connue (Ω)
 * @returns {{resistance: number, currentMilliAmps: number}|null} résistance cherchée et courant dans le pont
 */
export function dividerMissingResistor(vIn, vOut, known, knownValue) {
    if (!checker.valid("dividerMissingResistor", vIn, vOut, known, knownValue)) {
        return null;
    }
    const resistance = known === "R1" ? (vOut * knownValue) / (vIn - vOut) : (vIn * knownValue) / vOut - knownValue;

    return { resistance, currentMilliAmps: (vIn / (knownValue + resistance)) * 1000 };
}

/**
 * Autonomie d'une batterie : T = capacité / consommation × 0,7 (marge de 30 %).
 *
 * @param {number} capacity capacité (mAh)
 * @param {number} current  consommation (mA)
 * @returns {number|null} durée en heures
 */
export function batteryAutonomy(capacity, current) {
    return checker.valid("batteryAutonomy", capacity, current) ? (capacity / current) * BATTERY_EFFICIENCY : null;
}

/**
 * Affiche une valeur de résistance normalisée (anomalie B2 : l'ancien site tronquait les décimales
 * sous 1 kΩ, « 4 » pour 4,3 Ω).
 *
 * @param {number} ohms valeur en Ω
 * @returns {string} ex. « 4,3 Ω », « 470 Ω », « 1,1 kΩ », « 12 kΩ »
 */
export function formatResistance(ohms) {
    const format = (value) => new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 1 }).format(value);

    if (ohms >= 1_000_000) {
        return `${format(ohms / 1_000_000)} MΩ`;
    }
    if (ohms >= 1000) {
        return `${format(ohms / 1000)} kΩ`;
    }

    return `${format(ohms)} Ω`;
}
