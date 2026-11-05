/*
 * Calculs de la catégorie Mécanique (logique pure, sans DOM) : train d'engrenages, vérin, flexion
 * d'une poutre encastrée.
 *
 * Portage de l'ancien site (assets/controllers/calculator_controller.js de guillaume_c), avec la
 * règle A3 (nombres de dents entiers, rendement entre 0 et 100 %, tige plus fine que le piston,
 * longueurs et efforts positifs) et la correction B4 (seuils d'interférence calculés depuis l'angle
 * de pression) : docs/CALCULATRICE_ANOMALIES.md.
 *
 * Testé par : mecanique.test.js (valeurs de référence golden/mecanique.json + corrections).
 * Utilisé par : assets/controllers/calculator/engrenages_controller.js, verin_controller.js,
 * flexion_poutre_controller.js
 */
import { inputChecker } from "./inputs.js";

/** Angle de pression par défaut (°), denture droite normalisée. */
export const DEFAULT_PRESSURE_ANGLE = 20;

/** Limite pratique d'interférence : 5/6 de la limite théorique (taillage par crémaillère, B4). */
export const PRACTICAL_UNDERCUT_FACTOR = 5 / 6;

/** Accélération normale de la pesanteur (m/s²), pour les kilogrammes-force. */
export const STANDARD_GRAVITY = 9.80665;

/** Décimales d'affichage de l'ancien site. */
export const DECIMALS = { ratio: 2, speed: 1, torque: 2, force: 1, deflection: 3, moment: 2 };

const optional = (value) => (Number.isNaN(value) ? [] : [value]);

/** Règles de saisie de chaque calcul. */
export const INPUTS = {
    gearTrain: (z1, z2, z3, z4) => [
        { value: z1, label: "Menante 1 (Z1)", rule: "integerMin1" },
        { value: z2, label: "Menée 1 (Z2)", rule: "integerMin1" },
        ...optional(z3).map((value) => ({ value, label: "Menante 2 (Z3)", rule: "integerMin1" })),
        ...optional(z4).map((value) => ({ value, label: "Menée 2 (Z4)", rule: "integerMin1" })),
    ],
    gearAngle: (angle) => [{ value: angle, label: "Angle de pression", rule: "pressureAngle" }],
    gearSpeed: (speed) => [{ value: speed, label: "Vitesse d'entrée", rule: "nonNegative" }],
    gearTorque: (torque, efficiencyPercent) => [
        { value: torque, label: "Couple d'entrée", rule: "nonNegative" },
        { value: efficiencyPercent, label: "Rendement", rule: "percent" },
    ],
    cylinder: (pressure, bore) => [
        { value: pressure, label: "Pression", rule: "positive" },
        { value: bore, label: "Diamètre du piston", rule: "positive" },
    ],
    cylinderRod: (rod) => [{ value: rod, label: "Diamètre de la tige", rule: "positive" }],
    beam: (force, length, modulus, inertia) => [
        { value: force, label: "Force appliquée", rule: "positive" },
        { value: length, label: "Longueur", rule: "positive" },
        { value: modulus, label: "Module de Young", rule: "positive" },
        { value: inertia, label: "Moment quadratique", rule: "positive" },
    ],
};

/** Contrôles entre champs. */
const RELATIONS = {
    gearTrain: (_z1, _z2, z3, z4) => (Number.isNaN(z3) === Number.isNaN(z4) ? null : "Renseignez Z3 et Z4 ensemble pour un train à deux étages."),
};

const checker = inputChecker(INPUTS, RELATIONS);

/**
 * Renvoie le message d'erreur d'un calcul (null si les saisies sont valides ou incomplètes).
 */
export const explain = checker.explain;

/** Plus grand commun diviseur (dent de chasse). */
export function gcd(a, b) {
    return b === 0 ? a : gcd(b, a % b);
}

/**
 * Nombres de dents minimaux sans interférence de taillage (B4, décision du 2026-10-22) :
 * théorique z = 2 / sin²α, pratique ≈ 5/6 de la théorique, arrondis à l'entier
 * (α = 20° : 17,1 → 17 et 14,2 → 14).
 *
 * @param {number} pressureAngle angle de pression α (°)
 * @returns {{exact: number, theoretical: number, practical: number}}
 */
export function undercutLimits(pressureAngle) {
    const exact = 2 / Math.sin((pressureAngle * Math.PI) / 180) ** 2;

    return { exact, theoretical: Math.round(exact), practical: Math.round(exact * PRACTICAL_UNDERCUT_FACTOR) };
}

/**
 * Niveau d'alerte d'interférence pour la plus petite roue : « strong » sous la limite pratique,
 * « warning » sous la limite théorique, null sinon (et si l'angle n'est pas valide).
 *
 * @param {number} minTeeth      nombre de dents de la plus petite roue
 * @param {number} pressureAngle angle de pression (°)
 */
export function undercutCheck(minTeeth, pressureAngle) {
    if (!checker.valid("gearAngle", pressureAngle)) {
        return null;
    }
    const { theoretical, practical } = undercutLimits(pressureAngle);
    const level = minTeeth < practical ? "strong" : minTeeth < theoretical ? "warning" : null;

    return { level, minTeeth, theoretical, practical };
}

/**
 * Train d'engrenages simple (Z1/Z2) ou à deux étages (Z1/Z2 puis Z3/Z4).
 *
 * @param {number} z1 menante 1   @param {number} z2 menée 1
 * @param {number} z3 menante 2 (NaN si absent)   @param {number} z4 menée 2 (NaN si absent)
 * @param {number} speed            vitesse d'entrée (tr/min, NaN si absente)
 * @param {number} torque           couple d'entrée (N·m, NaN si absent)
 * @param {number} efficiencyPercent rendement (%)
 * @param {number} pressureAngle    angle de pression (°, 20 par défaut)
 * @returns {{reduction: number, ratio: number, speedOut: number|null, torqueOut: number|null,
 *            undercut: {level: "strong"|"warning"|null, minTeeth: number, theoretical: number, practical: number}|null,
 *            huntingTooth: boolean, gcds: number[]}|null}
 *          reduction : « 1 : reduction » ; ratio = vitesse de sortie / vitesse d'entrée
 */
export function gearTrain(z1, z2, z3, z4, speed, torque, efficiencyPercent, pressureAngle = DEFAULT_PRESSURE_ANGLE) {
    if (!checker.valid("gearTrain", z1, z2, z3, z4)) {
        return null;
    }
    const twoStages = !Number.isNaN(z3);
    const ratio = twoStages ? (z1 * z3) / (z2 * z4) : z1 / z2;
    const teeth = twoStages ? [z1, z2, z3, z4] : [z1, z2];
    const gcds = twoStages ? [gcd(z1, z2), gcd(z3, z4)] : [gcd(z1, z2)];

    return {
        reduction: 1 / ratio,
        ratio,
        speedOut: checker.valid("gearSpeed", speed) ? speed * ratio : null,
        torqueOut: checker.valid("gearTorque", torque, efficiencyPercent) ? (torque / ratio) * (efficiencyPercent / 100) : null,
        undercut: undercutCheck(Math.min(...teeth), pressureAngle),
        huntingTooth: gcds.every((value) => value === 1),
        gcds,
    };
}

/**
 * Forces d'un vérin double effet : poussée sur toute la surface du piston, traction sur la
 * surface annulaire (piston moins tige).
 *
 * @param {number} pressure pression (bar)
 * @param {number} bore     diamètre du piston (mm)
 * @param {number} rod      diamètre de la tige (mm, NaN si absent)
 * @returns {{pushNewtons: number, pushKgf: number, pullNewtons: number|null, pullKgf: number|null}|null}
 */
export function cylinder(pressure, bore, rod) {
    if (!checker.valid("cylinder", pressure, bore)) {
        return null;
    }
    const pascals = pressure * 100_000;
    const area = Math.PI * (bore / 2 / 1000) ** 2;
    const push = pascals * area;
    const rodValid = checker.valid("cylinderRod", rod) && rod < bore;
    const pull = rodValid ? pascals * (area - Math.PI * (rod / 2 / 1000) ** 2) : null;

    return { pushNewtons: push, pushKgf: push / STANDARD_GRAVITY, pullNewtons: pull, pullKgf: pull === null ? null : pull / STANDARD_GRAVITY };
}

/**
 * Message sur la tige du vérin (A3) : tige trop grosse ou valeur négative.
 */
export function explainRod(rod, bore) {
    return explain("cylinderRod", rod) ?? (!Number.isNaN(rod) && !Number.isNaN(bore) && rod >= bore ? "Le diamètre de la tige doit être inférieur à celui du piston." : null);
}

/**
 * Poutre encastrée chargée à son extrémité libre : flèche F·L³ / (3·E·I), moment maximal F·L.
 *
 * @param {number} force   force (N)
 * @param {number} length  longueur (mm)
 * @param {number} modulus module de Young (MPa = N/mm²)
 * @param {number} inertia moment quadratique (mm⁴)
 * @returns {{deflection: number, moment: number}|null} flèche (mm), moment à l'encastrement (N·m)
 */
export function cantileverBeam(force, length, modulus, inertia) {
    if (!checker.valid("beam", force, length, modulus, inertia)) {
        return null;
    }

    return { deflection: (force * length ** 3) / (3 * modulus * inertia), moment: (force * length) / 1000 };
}
