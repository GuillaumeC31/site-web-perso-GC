/*
 * Code couleur des résistances (CEI 60062), 4, 5 et 6 anneaux : décodage, codage, série E24,
 * conseil de puissance.
 *
 * Repris de l'ancien site (guillaume_c : assets/lib/resistor_colors.js), qui était déjà une
 * librairie pure ; décodage, codage et affichage sont inchangés.
 * Corrections décidées le 2026-10-22 (docs/CALCULATRICE_ANOMALIES.md) : B3 (valeur E24 la plus
 * proche cherchée dans trois décades, en distance logarithmique, et valeurs encadrantes) et B1
 * (conseil de puissance commun avec la résistance pour LED : valeur normalisée ≥ 2 × P).
 *
 * Testé par : resistor_colors.test.js (valeurs de référence golden/electronique.json).
 * Utilisé par : assets/controllers/calculator/code_couleur_resistance_controller.js
 */

import { powerLabel, recommendedPowerRating } from './electronique.js';

export const BAND_DIGITS = [
    { id: '0', name: 'Noir', hex: '#1a1a1a', textClass: 'text-white' },
    { id: '1', name: 'Marron', hex: '#92400e', textClass: 'text-white' },
    { id: '2', name: 'Rouge', hex: '#dc2626', textClass: 'text-white' },
    { id: '3', name: 'Orange', hex: '#ea580c', textClass: 'text-black' },
    { id: '4', name: 'Jaune', hex: '#eab308', textClass: 'text-black' },
    { id: '5', name: 'Vert', hex: '#16a34a', textClass: 'text-white' },
    { id: '6', name: 'Bleu', hex: '#2563eb', textClass: 'text-white' },
    { id: '7', name: 'Violet', hex: '#7c3aed', textClass: 'text-white' },
    { id: '8', name: 'Gris', hex: '#6b7280', textClass: 'text-white' },
    { id: '9', name: 'Blanc', hex: '#f3f4f6', textClass: 'text-black' },
];

export const MULTIPLIERS = [
    { id: 'silver', name: 'Argent', factor: 0.01, hex: '#d1d5db', textClass: 'text-black' },
    { id: 'gold', name: 'Or', factor: 0.1, hex: '#fbbf24', textClass: 'text-black' },
    { id: '0', name: 'Noir', factor: 1, hex: '#1a1a1a', textClass: 'text-white' },
    { id: '1', name: 'Marron', factor: 10, hex: '#92400e', textClass: 'text-white' },
    { id: '2', name: 'Rouge', factor: 100, hex: '#dc2626', textClass: 'text-white' },
    { id: '3', name: 'Orange', factor: 1_000, hex: '#ea580c', textClass: 'text-black' },
    { id: '4', name: 'Jaune', factor: 10_000, hex: '#eab308', textClass: 'text-black' },
    { id: '5', name: 'Vert', factor: 100_000, hex: '#16a34a', textClass: 'text-white' },
    { id: '6', name: 'Bleu', factor: 1_000_000, hex: '#2563eb', textClass: 'text-white' },
    { id: '7', name: 'Violet', factor: 10_000_000, hex: '#7c3aed', textClass: 'text-white' },
    { id: '8', name: 'Gris', factor: 100_000_000, hex: '#6b7280', textClass: 'text-white' },
    { id: '9', name: 'Blanc', factor: 1_000_000_000, hex: '#f3f4f6', textClass: 'text-black' },
];

export const TOLERANCES = [
    { id: 'brown', name: 'Marron', pct: 1, hex: '#92400e', textClass: 'text-white' },
    { id: 'red', name: 'Rouge', pct: 2, hex: '#dc2626', textClass: 'text-white' },
    { id: 'green', name: 'Vert', pct: 0.5, hex: '#16a34a', textClass: 'text-white' },
    { id: 'blue', name: 'Bleu', pct: 0.25, hex: '#2563eb', textClass: 'text-white' },
    { id: 'violet', name: 'Violet', pct: 0.1, hex: '#7c3aed', textClass: 'text-white' },
    { id: 'grey', name: 'Gris', pct: 0.05, hex: '#6b7280', textClass: 'text-white' },
    { id: 'gold', name: 'Or', pct: 5, hex: '#fbbf24', textClass: 'text-black' },
    { id: 'silver', name: 'Argent', pct: 10, hex: '#d1d5db', textClass: 'text-black' },
];

export const TEMP_COEFFS = [
    { id: 'brown', name: 'Marron', ppm: 100, hex: '#92400e', textClass: 'text-white' },
    { id: 'red', name: 'Rouge', ppm: 50, hex: '#dc2626', textClass: 'text-white' },
    { id: 'orange', name: 'Orange', ppm: 15, hex: '#ea580c', textClass: 'text-black' },
    { id: 'yellow', name: 'Jaune', ppm: 25, hex: '#eab308', textClass: 'text-black' },
    { id: 'blue', name: 'Bleu', ppm: 10, hex: '#2563eb', textClass: 'text-white' },
    { id: 'violet', name: 'Violet', ppm: 5, hex: '#7c3aed', textClass: 'text-white' },
];

const digitById = (id) => BAND_DIGITS.find((c) => c.id === id);
const multById = (id) => MULTIPLIERS.find((c) => c.id === id);
const tolById = (id) => TOLERANCES.find((c) => c.id === id);
const tempById = (id) => TEMP_COEFFS.find((c) => c.id === id);

/**
 * Décode les anneaux en valeur de résistance.
 *
 * @param {string[]} bandIds   identifiants des couleurs, dans l'ordre des anneaux
 * @param {4|5|6}    bandCount nombre d'anneaux
 * @returns {{ohms: number, tolerancePct: number, toleranceId: string, tempPpm: number|null, tempId?: string|null}|null}
 */
export function decodeBands(bandIds, bandCount) {
    if (bandCount === 4) {
        const d1 = digitById(bandIds[0]);
        const d2 = digitById(bandIds[1]);
        const mult = multById(bandIds[2]);
        const tol = tolById(bandIds[3]);
        if (!d1 || !d2 || !mult || !tol) return null;

        const ohms = (parseInt(d1.id, 10) * 10 + parseInt(d2.id, 10)) * mult.factor;
        return { ohms, tolerancePct: tol.pct, toleranceId: tol.id, tempPpm: null };
    }

    const d1 = digitById(bandIds[0]);
    const d2 = digitById(bandIds[1]);
    const d3 = digitById(bandIds[2]);
    const mult = multById(bandIds[3]);
    const tol = tolById(bandIds[4]);
    if (!d1 || !d2 || !d3 || !mult || !tol) return null;

    const ohms = (parseInt(d1.id, 10) * 100 + parseInt(d2.id, 10) * 10 + parseInt(d3.id, 10)) * mult.factor;
    const temp = bandCount === 6 ? tempById(bandIds[5]) : null;

    return {
        ohms,
        tolerancePct: tol.pct,
        toleranceId: tol.id,
        tempPpm: temp?.ppm ?? null,
        tempId: temp?.id ?? null,
    };
}

/**
 * Cherche les anneaux d'une valeur (à 2,1 % près, sinon null : valeur non représentable).
 *
 * @param {number} ohms        valeur en Ω
 * @param {4|5|6}  bandCount   nombre d'anneaux
 * @param {string} toleranceId tolérance souhaitée (or par défaut)
 * @returns {{bandIds: string[], ohms: number}|null}
 */
export function encodeValue(ohms, bandCount, toleranceId = 'gold') {
    if (!Number.isFinite(ohms) || ohms <= 0) return null;

    const tol = tolById(toleranceId) ? toleranceId : 'gold';

    const findMultiplier = (factor) => MULTIPLIERS.find((m) => m.factor === factor);

    if (bandCount === 4) {
        for (let exp = -2; exp <= 9; exp += 1) {
            const factor = 10 ** exp;
            const mantissa = ohms / factor;
            if (mantissa < 1 || mantissa >= 100) continue;

            let d1;
            let d2;
            if (mantissa >= 10) {
                d1 = Math.floor(mantissa / 10);
                d2 = Math.round(mantissa % 10);
            } else {
                d1 = Math.floor(mantissa);
                d2 = Math.round((mantissa - d1) * 10);
            }

            if (d1 > 9 || d2 > 9) continue;

            const rebuilt = (d1 * 10 + d2) * factor;
            if (Math.abs(rebuilt - ohms) / ohms > 0.021) continue;

            const multEntry = findMultiplier(factor);
            if (!multEntry) continue;

            return { bandIds: [String(d1), String(d2), multEntry.id, tol], ohms: rebuilt };
        }

        return null;
    }

    for (let exp = -1; exp <= 8; exp += 1) {
        const factor = 10 ** exp;
        const mantissa = ohms / factor;
        if (mantissa < 100 || mantissa >= 1000) continue;

        const rounded = Math.round(mantissa);
        const d1 = Math.floor(rounded / 100);
        const d2 = Math.floor((rounded % 100) / 10);
        const d3 = rounded % 10;
        const rebuilt = rounded * factor;
        if (Math.abs(rebuilt - ohms) / ohms > 0.021) continue;

        const multEntry = findMultiplier(factor);
        if (!multEntry) continue;

        const bands = [String(d1), String(d2), String(d3), multEntry.id, tol];
        if (bandCount === 6) {
            bands.push('brown');
        }

        return { bandIds: bands, ohms: rebuilt };
    }

    return null;
}

/**
 * Affiche une valeur en mΩ, Ω, kΩ ou MΩ (format de l'ancien site).
 *
 * @param {number} ohms valeur en Ω
 * @returns {string}
 */
export function formatOhms(ohms) {
    if (ohms >= 1_000_000) return `${(ohms / 1_000_000).toFixed(ohms % 1_000_000 === 0 ? 0 : 2)} MΩ`;
    if (ohms >= 1_000) return `${(ohms / 1_000).toFixed(ohms % 1_000 === 0 ? 0 : 2)} kΩ`;
    if (ohms < 1) return `${(ohms * 1_000).toFixed(1)} mΩ`;
    return `${ohms.toFixed(ohms < 10 ? 2 : 1)} Ω`;
}

/**
 * Renvoie la couleur d'un anneau selon son rôle (chiffre, multiplicateur, tolérance, coefficient).
 *
 * @param {"digit"|"mult"|"tol"|"temp"} type rôle de l'anneau
 * @param {string} id identifiant de la couleur
 */
export function colorMeta(type, id) {
    if (type === 'digit') return digitById(id);
    if (type === 'mult') return multById(id);
    if (type === 'tol') return tolById(id);
    if (type === 'temp') return tempById(id);
    return null;
}

export const E24 = [
    1.0, 1.1, 1.2, 1.3, 1.5, 1.6, 1.8, 2.0, 2.2, 2.4, 2.7, 3.0,
    3.3, 3.6, 3.9, 4.3, 4.7, 5.1, 5.6, 6.2, 6.8, 7.5, 8.2, 9.1,
];

/**
 * Valeurs E24 des décades inférieure, courante et supérieure d'une résistance, en ordre croissant.
 *
 * @param {number} ohms valeur en Ω (> 0)
 * @returns {number[]}
 */
function e24Candidates(ohms) {
    const exp = Math.floor(Math.log10(ohms));
    const values = [];
    for (const decade of [exp - 1, exp, exp + 1]) {
        // toPrecision : évite les restes binaires (1,1 × 1000 = 1100,0000000000002)
        values.push(...E24.map((v) => Number((v * 10 ** decade).toPrecision(12))));
    }

    return values;
}

/**
 * Renvoie la valeur de la série E24 la plus proche (B3 : trois décades, rapport le plus proche de 1,
 * soit la plus petite distance logarithmique |log(R / E24)|) : 99 Ω → 100 Ω.
 *
 * @param {number} ohms valeur en Ω
 * @returns {number|null}
 */
export function nearestE24(ohms) {
    if (!(ohms > 0)) return null;
    let best = null;
    let bestDistance = Infinity;
    for (const value of e24Candidates(ohms)) {
        const distance = Math.abs(Math.log(ohms / value));
        if (distance < bestDistance) {
            best = value;
            bestDistance = distance;
        }
    }
    return best;
}

/**
 * Valeurs E24 juste en dessous et juste au-dessus d'une résistance (B3). Pour une valeur de la série,
 * ce sont ses deux voisines (1 kΩ → 910 Ω et 1,1 kΩ).
 *
 * @param {number} ohms valeur en Ω
 * @returns {{below: number, above: number}|null}
 */
export function e24Neighbors(ohms) {
    if (!(ohms > 0)) return null;
    const values = e24Candidates(ohms);
    // Comparaison avec une tolérance relative : 4,7 saisi est bien la valeur de la série 4,7
    const same = (value) => Math.abs(value / ohms - 1) < 1e-9;

    return {
        below: values.filter((v) => v < ohms && !same(v)).at(-1),
        above: values.find((v) => v > ohms && !same(v)),
    };
}

/**
 * Conseille une puissance nominale de résistance (B1 : valeur normalisée ≥ 2 × la puissance
 * dissipée, règle commune avec la résistance pour LED ; libellés de l'ancien site).
 *
 * @param {number} watts puissance dissipée
 * @returns {string}
 */
export function suggestPowerRating(watts) {
    if (!Number.isFinite(watts) || watts <= 0) return '1/4 W (0,25 W) minimum courant';
    const rating = recommendedPowerRating(watts);
    if (rating.powerResistor) return `${powerLabel(rating.watts)} (résistance de puissance, vérifier le refroidissement)`;
    return `${powerLabel(rating.watts)} ${rating.watts === 0.125 ? 'suffisant' : 'recommandé'}`;
}
