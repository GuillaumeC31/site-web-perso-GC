/*
 * Calculs de la catégorie Capteurs et mesures (logique pure, sans DOM) : sondes PT100/PT1000
 * (Callendar-Van Dusen, CEI 60751) et mise à l'échelle des signaux 4-20 mA, 0-20 mA, 0-10 V.
 *
 * Portage de l'ancien site (pt_sensor_controller.js et scaling_controller.js de guillaume_c), avec
 * la règle A3 (une plage dont le minimum égale le maximum est signalée au lieu d'être ignorée en
 * silence) et la correction B5 (bornes -200 et 850 °C incluses) : docs/CALCULATRICE_ANOMALIES.md.
 *
 * Testé par : capteurs.test.js (valeurs de référence golden/mesures-capteurs.json).
 * Utilisé par : assets/controllers/calculator/pt_sensor_controller.js, scaling_controller.js
 */

/** Coefficients de Callendar-Van Dusen de la CEI 60751 (alpha = 0,00385). */
export const CVD = { A: 3.9083e-3, B: -5.775e-7, C: -4.183e-12 };

/** Plage de validité de la CEI 60751 (°C). */
export const PT_RANGE = { min: -200, max: 850 };

/** Résistance de lecture des signaux en mA (Ω). */
export const SHUNT_OHMS = 250;

/** Dépassement toléré au-dessus de la pleine échelle avant alerte (fraction de la plage). */
export const OVER_RANGE_TOLERANCE = 0.05;

/** Décimales d'affichage de l'ancien site. */
export const DECIMALS = { resistance: 2, temperature: 2, physical: 2, signal: 2, shunt: 2, percent: 1 };

/**
 * Tolérance sur les bornes en résistance (Ω) : une demi-unité du dernier chiffre affiché (2 décimales).
 * 18,52 Ω, valeur affichée pour -200 °C (18,5201 Ω), reste ainsi dans la plage (B5).
 */
export const RESISTANCE_TOLERANCE = 0.005;

/** Message de l'ancien site quand une résistance sort de la plage. */
export const OUT_OF_RANGE = "Hors plage";

/**
 * Résistance d'une sonde platine à la température t (Callendar-Van Dusen).
 *
 * @param {number} t  température (°C)
 * @param {number} r0 résistance à 0 °C (100 ou 1000 Ω)
 * @returns {number} résistance (Ω)
 */
export function ptResistance(t, r0) {
    if (t >= 0) {
        return r0 * (1 + CVD.A * t + CVD.B * t * t);
    }

    return r0 * (1 + CVD.A * t + CVD.B * t * t + CVD.C * (t - 100) * t * t * t);
}

/**
 * Indique si une température sort de la plage de la CEI 60751 (alerte affichée).
 */
export function isOutsidePtRange(t) {
    return t < PT_RANGE.min || t > PT_RANGE.max;
}

/**
 * Température d'une sonde platine à partir de sa résistance (dichotomie, 50 itérations).
 *
 * @param {number} r  résistance mesurée (Ω)
 * @param {number} r0 résistance à 0 °C
 * @returns {number|null} température (°C), ou null si la résistance est hors plage (-200 à 850 °C,
 *          bornes incluses à la précision d'affichage près : B5)
 */
export function ptTemperature(r, r0) {
    const rMin = ptResistance(PT_RANGE.min, r0);
    const rMax = ptResistance(PT_RANGE.max, r0);
    if (r < rMin - RESISTANCE_TOLERANCE || r > rMax + RESISTANCE_TOLERANCE) {
        return null;
    }
    // Une valeur arrondie de la borne vaut la borne elle-même.
    if (r <= rMin) {
        return PT_RANGE.min;
    }
    if (r >= rMax) {
        return PT_RANGE.max;
    }

    let low = PT_RANGE.min;
    let high = PT_RANGE.max;
    let t = 0;
    for (let iteration = 0; iteration < 50; iteration++) {
        t = (low + high) / 2;
        if (ptResistance(t, r0) > r) {
            high = t;
        } else {
            low = t;
        }
    }

    return t;
}

/**
 * Lignes du tableau de référence d'une sonde : température, résistance, écart avec la ligne précédente.
 *
 * @param {number}             r0    résistance à 0 °C
 * @param {number}             step  pas (°C)
 * @param {"common"|"full"}    range plage courante (-50 à 200 °C) ou complète (-200 à 850 °C)
 * @returns {Array<{t: number, r: number, delta: number|null}>}
 */
export function ptTable(r0, step, range) {
    const min = range === "full" ? PT_RANGE.min : -50;
    const max = range === "full" ? PT_RANGE.max : 200;
    const rows = [];
    let previous = null;
    for (let t = min; t <= max; t += step) {
        const r = ptResistance(t, r0);
        rows.push({ t, r, delta: previous === null ? null : r - previous });
        previous = r;
    }

    return rows;
}

/**
 * Message si la plage physique est inutilisable (A3), sinon null.
 */
export function explainRange(minSignal, maxSignal, minPhysical, maxPhysical) {
    if ([minSignal, maxSignal, minPhysical, maxPhysical].some(Number.isNaN)) {
        return null;
    }
    if (minSignal === maxSignal) {
        return "Les bornes du signal doivent être différentes.";
    }

    return minPhysical === maxPhysical ? "Les valeurs min et max de la plage doivent être différentes." : null;
}

/**
 * Valeur physique correspondant à un signal (interpolation linéaire).
 *
 * @returns {number|null} valeur physique, ou null si une valeur manque ou si la plage est inutilisable
 */
export function signalToPhysical(signal, minSignal, maxSignal, minPhysical, maxPhysical) {
    if ([signal, minSignal, maxSignal, minPhysical, maxPhysical].some(Number.isNaN) || maxSignal === minSignal || maxPhysical === minPhysical) {
        return null;
    }

    return ((signal - minSignal) * (maxPhysical - minPhysical)) / (maxSignal - minSignal) + minPhysical;
}

/**
 * Signal correspondant à une valeur physique (interpolation linéaire inverse).
 *
 * @returns {number|null} signal, ou null si une valeur manque ou si la plage est inutilisable
 */
export function physicalToSignal(physical, minSignal, maxSignal, minPhysical, maxPhysical) {
    if ([physical, minSignal, maxSignal, minPhysical, maxPhysical].some(Number.isNaN) || maxPhysical === minPhysical) {
        return null;
    }

    return ((physical - minPhysical) * (maxSignal - minSignal)) / (maxPhysical - minPhysical) + minSignal;
}

/**
 * Tension aux bornes du shunt de 250 Ω pour un signal en mA.
 *
 * @param {number} milliAmps signal (mA)
 * @returns {number} tension (V)
 */
export function shuntVoltage(milliAmps) {
    return (milliAmps * SHUNT_OHMS) / 1000;
}

/**
 * Alerte sur le signal (textes de l'ancien site) : sous le minimum, ou au-delà de la pleine échelle
 * plus 5 % de la plage.
 *
 * @returns {string|null}
 */
export function signalAlert(signal, minSignal, maxSignal) {
    if (signal < minSignal) {
        return minSignal === 4 ? "Signal < 4 mA : défaut probable (fil coupé, capteur HS)" : "Signal inférieur au minimum de plage";
    }
    if (signal > maxSignal + (maxSignal - minSignal) * OVER_RANGE_TOLERANCE) {
        return "Signal supérieur au maximum (dépassement de plage)";
    }

    return null;
}

/**
 * Lignes du tableau de référence d'un signal : signal, valeur physique, pourcentage de la plage.
 *
 * @returns {Array<{signal: number, physical: number, percent: number}>} vide si la plage est inutilisable
 */
export function scalingTable(minSignal, maxSignal, minPhysical, maxPhysical, step) {
    if ([minSignal, maxSignal, minPhysical, maxPhysical].some(Number.isNaN) || maxSignal === minSignal || maxPhysical === minPhysical || !(step > 0)) {
        return [];
    }
    const rows = [];
    for (let signal = minSignal; signal <= maxSignal + 0.0001; signal += step) {
        rows.push({
            signal,
            physical: signalToPhysical(signal, minSignal, maxSignal, minPhysical, maxPhysical),
            percent: ((signal - minSignal) / (maxSignal - minSignal)) * 100,
        });
    }

    return rows;
}
