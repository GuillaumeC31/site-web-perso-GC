/*
 * Conversion d'unités physiques (logique pure, sans DOM) : pression, température, puissance,
 * énergie, vitesse. Unités et facteurs repris de l'ancien site (assets/controllers/calculator_controller.js
 * de guillaume_c) ; les valeurs négatives restent permises (température, pression relative).
 *
 * Testé par : convertisseur.test.js (valeurs de référence golden/convertisseur.json).
 * Utilisé par : assets/controllers/calculator/unites_controller.js
 */

/** Grandeurs et unités proposées, dans l'ordre de l'ancien site (la 2e unité est la cible par défaut). */
export const QUANTITIES = {
    pressure: {
        label: "Pression",
        units: [
            { value: "bar", label: "Bar (bar)" },
            { value: "psi", label: "PSI" },
            { value: "pa", label: "Pascal (Pa)" },
            { value: "hpa", label: "Hectopascal (hPa)" },
            { value: "atm", label: "Atmosphère (atm)" },
        ],
    },
    temperature: {
        label: "Température",
        units: [
            { value: "c", label: "Celsius (°C)" },
            { value: "f", label: "Fahrenheit (°F)" },
            { value: "k", label: "Kelvin (K)" },
        ],
    },
    power: {
        label: "Puissance",
        units: [
            { value: "w", label: "Watt (W)" },
            { value: "kw", label: "Kilowatt (kW)" },
            { value: "ch", label: "Cheval-vapeur (ch)" },
            { value: "hp", label: "Horsepower (hp)" },
        ],
    },
    energy: {
        label: "Énergie",
        units: [
            { value: "j", label: "Joule (J)" },
            { value: "kwh", label: "Kilowattheure (kWh)" },
            { value: "cal", label: "Calorie (cal)" },
            { value: "btu", label: "BTU" },
        ],
    },
    speed: {
        label: "Vitesse",
        units: [
            { value: "ms", label: "Mètre par seconde (m/s)" },
            { value: "kmh", label: "Kilomètre par heure (km/h)" },
            { value: "rpm", label: "Tour par minute (tr/min)" },
            { value: "rads", label: "Radian par seconde (rad/s)" },
        ],
    },
};

/** Facteurs vers l'unité de base (Pa, W, J). */
export const FACTORS = {
    pressure: { bar: 100000, psi: 6894.76, pa: 1, hpa: 100, atm: 101325 },
    power: { w: 1, kw: 1000, ch: 735.49875, hp: 745.699872 },
    energy: { j: 1, kwh: 3600000, cal: 4.184, btu: 1055.06 },
};

/** Message quand on convertit une vitesse linéaire en vitesse de rotation (l'ancien site affichait « N/A »). */
export const INCOMPATIBLE = "Conversion impossible entre une vitesse linéaire et une vitesse de rotation.";

/** Décimales : 2 pour les températures, jusqu'à 6 pour le reste (ancien site). */
export const DECIMALS = { temperature: 2, other: 6 };

/**
 * Convertit une valeur.
 *
 * @param {keyof QUANTITIES} quantity grandeur
 * @param {number}           value    valeur à convertir
 * @param {string}           from     unité d'origine
 * @param {string}           to       unité cible
 * @returns {{value: number}|{error: string}|null} résultat, impossibilité, ou null si la valeur manque
 */
export function convert(quantity, value, from, to) {
    if (Number.isNaN(value)) {
        return null;
    }
    if (from === to) {
        return { value };
    }

    if (quantity === "temperature") {
        const celsius = from === "f" ? ((value - 32) * 5) / 9 : from === "k" ? value - 273.15 : value;

        return { value: to === "f" ? (celsius * 9) / 5 + 32 : to === "k" ? celsius + 273.15 : celsius };
    }

    if (quantity === "speed") {
        const linear = ["ms", "kmh"];
        const angular = ["rpm", "rads"];
        if (linear.includes(from) && linear.includes(to)) {
            const metersPerSecond = from === "kmh" ? value / 3.6 : value;

            return { value: to === "kmh" ? metersPerSecond * 3.6 : metersPerSecond };
        }
        if (angular.includes(from) && angular.includes(to)) {
            const radians = from === "rpm" ? value * (Math.PI / 30) : value;

            return { value: to === "rpm" ? radians * (30 / Math.PI) : radians };
        }

        return { error: INCOMPATIBLE };
    }

    return { value: (value * FACTORS[quantity][from]) / FACTORS[quantity][to] };
}

/**
 * Arrondi d'affichage de l'ancien site : 2 décimales fixes pour une température, sinon jusqu'à
 * 6 décimales sans zéros inutiles ; la valeur saisie telle quelle si l'unité ne change pas.
 *
 * @returns {{value: number, fixedDigits: number|null}} valeur arrondie et décimales fixes (null : variables)
 */
export function displayRounding(quantity, from, to, value) {
    if (from === to) {
        return { value, fixedDigits: null };
    }
    if (quantity === "temperature") {
        return { value: Number(value.toFixed(DECIMALS.temperature)), fixedDigits: DECIMALS.temperature };
    }

    return { value: Number(value.toFixed(DECIMALS.other)), fixedDigits: null };
}
