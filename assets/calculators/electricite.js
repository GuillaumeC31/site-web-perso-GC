/*
 * Calculs de la catégorie Électricité (logique pure, sans DOM).
 *
 * Portage de l'ancien site (assets/controllers/calculator_controller.js et cables_controller.js
 * de guillaume_c), puis corrections décidées le 2026-10-22 (docs/CALCULATRICE_ANOMALIES.md) :
 * - A2 : condensateur Steinmetz, méthode « puissance » C = 70 µF/kW ;
 * - A1 : une seule résistivité de calcul pour la chute de tension et les câbles (NF C 15-100 /
 *   UTE C 15-105) : cuivre 0,0225, aluminium 0,036 Ω·mm²/m ;
 * - A3 : valeurs négatives ou nulles sans signification refusées, avec un message ;
 * - A4 : nombre de conducteurs entier obligatoire ;
 * - A5 : relèvement du cos φ, capacité par phase en étoile et en triangle.
 *
 * Chaque calcul déclare ses règles de saisie (INPUTS) : la fonction de calcul renvoie null si une
 * valeur manque ou est invalide, et explain() donne le message à afficher. DECIMALS indique
 * l'arrondi d'affichage (celui de l'ancien site).
 *
 * Testé par : electricite.test.js (valeurs de référence golden/electricite.json + corrections).
 * Utilisé par : assets/controllers/calculator/*_controller.js (outils Électricité)
 */
import { checkInputs } from "./inputs.js";

/**
 * Résistivités de calcul (Ω·mm²/m) retenues par la NF C 15-100 et le guide UTE C 15-105 pour
 * les chutes de tension (conducteurs en service), anomalie A1.
 */
export const RHO = { cu: 0.0225, al: 0.036 };

/** Libellés des matériaux, pour les messages et la note sous les résultats. */
export const MATERIAL_LABELS = { cu: "cuivre", al: "aluminium" };

/** Norme citée sous les résultats de résistivité. */
export const RHO_REFERENCE = "NF C 15-100 / UTE C 15-105";

/** Masses volumiques des conducteurs (g/cm³). */
export const DENSITY = { cu: 8.96, al: 2.7 };

/**
 * Condensateur Steinmetz, règle pratique : environ 70 µF par kW pour un moteur 230/400 V couplé
 * en triangle, sur un réseau monophasé 230 V 50 Hz (anomalie A2, décision du 2026-10-22).
 */
export const STEINMETZ_MICROFARAD_PER_KW = 70;

/** Chevaux-vapeur (ch) par kilowatt. */
export const HP_PER_KW = 1.35962;

/** Décimales d'affichage de l'ancien site, par résultat. */
export const DECIMALS = {
    power: 2,
    ohm: { u: 2, i: 3, r: 2, p: 2 },
    horsepower: 2,
    kilowatt: 2,
    capacitorPermanent: 0,
    reactivePowerKvar: 2,
    capacitanceMicrofarad: 1,
    voltageDrop: 2,
    cable: 2,
};

/** Matériau de l'ancien site : tout ce qui n'est pas « cu » est de l'aluminium. */
const material = (value) => (value === "cu" ? "cu" : "al");

/**
 * Règles de saisie de chaque calcul (valeur, libellé affiché, règle de inputs.js).
 */
export const INPUTS = {
    activePower: (u, i, cos) => [
        { value: u, label: "Tension (U)", rule: "positive" },
        { value: i, label: "Courant (I)", rule: "nonNegative" },
        { value: cos, label: "cos φ", rule: "cosPhi" },
    ],
    currentFromActivePower: (p, u, cos) => [
        { value: p, label: "Puissance (P)", rule: "nonNegative" },
        { value: u, label: "Tension (U)", rule: "positive" },
        { value: cos, label: "cos φ", rule: "cosPhiNonZero" },
    ],
    voltageFromActivePower: (p, i, cos) => [
        { value: p, label: "Puissance (P)", rule: "nonNegative" },
        { value: i, label: "Courant (I)", rule: "positive" },
        { value: cos, label: "cos φ", rule: "cosPhiNonZero" },
    ],
    apparentPower: (u, i) => [
        { value: u, label: "Tension (U)", rule: "positive" },
        { value: i, label: "Courant (I)", rule: "nonNegative" },
    ],
    currentFromApparentPower: (s, u) => [
        { value: s, label: "Puissance apparente (S)", rule: "nonNegative" },
        { value: u, label: "Tension (U)", rule: "positive" },
    ],
    voltageFromApparentPower: (s, i) => [
        { value: s, label: "Puissance apparente (S)", rule: "nonNegative" },
        { value: i, label: "Courant (I)", rule: "positive" },
    ],
    reactivePower: (u, i, cos) => [
        { value: u, label: "Tension (U)", rule: "positive" },
        { value: i, label: "Courant (I)", rule: "nonNegative" },
        { value: cos, label: "cos φ", rule: "cosPhi" },
    ],
    ohmLaw: ({ u, i, r, p }) => [
        { value: u, label: "Tension (U)", rule: "nonNegative" },
        { value: i, label: "Courant (I)", rule: "nonNegative" },
        { value: r, label: "Résistance (R)", rule: "nonNegative" },
        { value: p, label: "Puissance (P)", rule: "nonNegative" },
    ],
    steinmetzCapacitor: (source, value) => [
        { value, label: { kw: "Puissance (kW)", hp: "Puissance (ch)", amps: "Courant (I)" }[source] ?? "Valeur", rule: "positive" },
    ],
    powerFactorCorrection: (p, u, f, cosIni, cosCib) => [
        { value: p, label: "Puissance active (P)", rule: "positive" },
        { value: u, label: "Tension (U)", rule: "positive" },
        { value: f, label: "Fréquence", rule: "positive" },
        { value: cosIni, label: "cos φ initial", rule: "cosPhiNonZero" },
        { value: cosCib, label: "cos φ cible", rule: "cosPhiNonZero" },
    ],
    voltageDropSinglePhase: (_material, length, current) => [
        { value: length, label: "Longueur (L)", rule: "positive" },
        { value: current, label: "Courant (I)", rule: "nonNegative" },
    ],
    cableSection: (_material, length, current, drop) => [
        { value: length, label: "Longueur (L)", rule: "positive" },
        { value: current, label: "Courant (I)", rule: "positive" },
        { value: drop, label: "Chute de tension (ΔU)", rule: "positive" },
    ],
    cableLength: (_material, section, current, drop) => [
        { value: section, label: "Section (S)", rule: "positive" },
        { value: current, label: "Courant (I)", rule: "positive" },
        { value: drop, label: "Chute de tension maximale (ΔU)", rule: "positive" },
    ],
    cableDrop: (_material, section, length, current) => [
        { value: section, label: "Section (S)", rule: "positive" },
        { value: length, label: "Longueur (L)", rule: "positive" },
        { value: current, label: "Courant (I)", rule: "nonNegative" },
    ],
    cableWeight: (_material, length, section, cores, insulationPercent) => [
        { value: section, label: "Section d'un fil", rule: "positive" },
        { value: length, label: "Longueur (L)", rule: "positive" },
        { value: cores, label: "Nombre de conducteurs", rule: "integerMin1" },
        // Gaine facultative : un champ vide compte pour 0 % (comme sur l'ancien site)
        { value: Number.isNaN(insulationPercent) ? 0 : insulationPercent, label: "Gaine isolante", rule: "nonNegative" },
    ],
};

/**
 * Renvoie le message d'erreur d'un calcul (null si les saisies sont valides ou incomplètes).
 *
 * @param {keyof INPUTS} name nom du calcul
 * @param {...*}         args mêmes arguments que la fonction de calcul
 * @returns {string|null}
 */
export function explain(name, ...args) {
    return checkInputs(INPUTS[name](...args)).error;
}

/** Vrai si toutes les saisies du calcul sont présentes et valides. */
const valid = (name, ...args) => checkInputs(INPUTS[name](...args)).ok;

/**
 * Puissance active P = U × I × cos φ.
 *
 * @returns {number|null} P en W
 */
export function activePower(u, i, cos) {
    return valid("activePower", u, i, cos) ? u * i * cos : null;
}

/**
 * Courant I = P / (U × cos φ).
 *
 * @returns {number|null} I en A
 */
export function currentFromActivePower(p, u, cos) {
    return valid("currentFromActivePower", p, u, cos) ? p / (u * cos) : null;
}

/**
 * Tension U = P / (I × cos φ).
 *
 * @returns {number|null} U en V
 */
export function voltageFromActivePower(p, i, cos) {
    return valid("voltageFromActivePower", p, i, cos) ? p / (i * cos) : null;
}

/**
 * Puissance apparente S = U × I.
 *
 * @returns {number|null} S en VA
 */
export function apparentPower(u, i) {
    return valid("apparentPower", u, i) ? u * i : null;
}

/**
 * Courant I = S / U.
 *
 * @returns {number|null} I en A
 */
export function currentFromApparentPower(s, u) {
    return valid("currentFromApparentPower", s, u) ? s / u : null;
}

/**
 * Tension U = S / I.
 *
 * @returns {number|null} U en V
 */
export function voltageFromApparentPower(s, i) {
    return valid("voltageFromApparentPower", s, i) ? s / i : null;
}

/**
 * Puissance réactive Q = U × I × sin φ, avec sin φ = √(1 − cos² φ), cos φ entre 0 et 1 (A3).
 *
 * @returns {number|null} Q en var
 */
export function reactivePower(u, i, cos) {
    return valid("reactivePower", u, i, cos) ? u * i * Math.sqrt(1 - cos ** 2) : null;
}

/**
 * Loi d'Ohm « deux valeurs connues → les deux autres » (U = R × I, P = U × I).
 *
 * Règle de l'ancien site : il faut au moins deux valeurs ; les paires sont essayées dans l'ordre
 * (U,I), (U,R), (U,P), (I,R), (I,P), (R,P). Une valeur négative est refusée (A3).
 *
 * @param {{u: number, i: number, r: number, p: number}} values valeurs saisies (NaN si vide)
 * @returns {{u: number, i: number, r: number, p: number}|null} les quatre grandeurs
 *          (une division par zéro donne Infinity ou NaN : rien à afficher), ou null
 */
export function ohmLaw({ u, i, r, p }) {
    if (explain("ohmLaw", { u, i, r, p }) !== null) {
        return null;
    }
    const known = (value) => !Number.isNaN(value);
    if ([u, i, r, p].filter(known).length < 2) {
        return null;
    }

    if (known(u) && known(i)) {
        return { u, i, r: u / i, p: u * i };
    }
    if (known(u) && known(r)) {
        return { u, i: u / r, r, p: (u * u) / r };
    }
    if (known(u) && known(p)) {
        return { u, i: p / u, r: (u * u) / p, p };
    }
    if (known(i) && known(r)) {
        return { u: r * i, i, r, p: r * i * i };
    }
    if (known(i) && known(p)) {
        return { u: p / i, i, r: p / (i * i), p };
    }

    return { u: Math.sqrt(p * r), i: Math.sqrt(p / r), r, p };
}

/**
 * Condensateur permanent d'un montage Steinmetz (moteur triphasé sur réseau monophasé 230 V).
 *
 * Méthode « puissance » : C = 70 × P(kW) (règle pratique, moteur 230/400 V couplé en triangle,
 * réseau 230 V 50 Hz ; anomalie A2 : l'ancien site calculait 50 × P / 230 × 1000) ;
 * méthode « courant » : C = 14 × I (ancien site) ; 1 kW = 1,35962 ch.
 *
 * @param {"kw"|"hp"|"amps"} source champ saisi en dernier
 * @param {number}           value  valeur de ce champ
 * @returns {{kw: number|null, hp: number|null, capacitance: number|null}} conversions et capacité en µF
 */
export function steinmetzCapacitor(source, value) {
    if (!valid("steinmetzCapacitor", source, value)) {
        return { kw: null, hp: null, capacitance: null };
    }

    if (source === "kw") {
        return { kw: value, hp: value * HP_PER_KW, capacitance: Math.round(STEINMETZ_MICROFARAD_PER_KW * value) };
    }
    if (source === "hp") {
        const kw = value / HP_PER_KW;

        return { kw, hp: value, capacitance: Math.round(STEINMETZ_MICROFARAD_PER_KW * kw) };
    }

    return { kw: null, hp: null, capacitance: Math.round(value * 14) };
}

/**
 * Relèvement du facteur de puissance d'un réseau triphasé : batterie Qc = P × (tan φ1 − tan φ2),
 * puis capacité par phase (A5), U étant la tension entre phases :
 * - couplage étoile (chaque condensateur sous U/√3) : C = Qc / (U² × 2πf) ;
 * - couplage triangle (chaque condensateur sous U) : C = Qc / (3 × U² × 2πf).
 * La valeur « étoile » est celle que l'ancien site appelait « capacité totale équivalente ».
 *
 * @returns {{reactivePower: number, capacitanceStar: number, capacitanceDelta: number, alreadyReached: boolean}|null}
 *          Qc en kvar (0 si le cos φ initial atteint déjà la cible), capacités en µF par phase
 */
export function powerFactorCorrection(p, u, f, cosIni, cosCib) {
    if (!valid("powerFactorCorrection", p, u, f, cosIni, cosCib)) {
        return null;
    }

    const needed = p * (Math.tan(Math.acos(cosIni)) - Math.tan(Math.acos(cosCib)));
    const reactive = Math.max(0, needed);
    const star = ((reactive * 1000) / (u * u * 2 * Math.PI * f)) * 1_000_000;

    return { reactivePower: reactive, capacitanceStar: star, capacitanceDelta: star / 3, alreadyReached: needed <= 0 };
}

/**
 * Chute de tension monophasée, charge résistive : ΔU = 2 × ρ × (L / S) × I (A1 : ρ selon le matériau).
 *
 * @param {"cu"|"al"} conductor matériau
 * @returns {number|null} ΔU en V
 */
export function voltageDropSinglePhase(conductor, length, current, section) {
    return valid("voltageDropSinglePhase", conductor, length, current) && section > 0 ? 2 * RHO[material(conductor)] * (length / section) * current : null;
}

/**
 * Dimensionnement des câbles (A1 : ρ de la NF C 15-100 ; A3 : saisies invalides refusées).
 */
export const cables = {
    /** Section minimale S = 2ρLI / ΔU (mm²). */
    section(conductor, length, current, drop) {
        return valid("cableSection", conductor, length, current, drop) ? (2 * RHO[material(conductor)] * length * current) / drop : null;
    },

    /** Longueur maximale L = ΔU × S / (2ρI) (m). */
    length(conductor, section, current, drop) {
        return valid("cableLength", conductor, section, current, drop) ? (drop * section) / (2 * RHO[material(conductor)] * current) : null;
    },

    /** Chute de tension ΔU = 2ρLI / S (V). */
    drop(conductor, section, length, current) {
        return valid("cableDrop", conductor, section, length, current) ? (2 * RHO[material(conductor)] * length * current) / section : null;
    },

    /** Masse estimée (kg) = S × densité × L × conducteurs / 1000, majorée du pourcentage d'isolant (A4 : conducteurs entiers). */
    weight(conductor, length, section, cores, insulationPercent) {
        if (!valid("cableWeight", conductor, length, section, cores, insulationPercent)) {
            return null;
        }
        const insulation = Number.isNaN(insulationPercent) ? 0 : insulationPercent;

        return ((section * DENSITY[material(conductor)] * length * cores) / 1000) * (1 + insulation / 100);
    },
};

/**
 * Note affichée sous un résultat dépendant de la résistivité (A1).
 *
 * @param {"cu"|"al"} conductor matériau
 * @returns {string} ex. « ρ = 0,0225 Ω·mm²/m (cuivre) — NF C 15-100 / UTE C 15-105 »
 */
export function resistivityNote(conductor) {
    const key = material(conductor);

    return `ρ = ${String(RHO[key]).replace(".", ",")} Ω·mm²/m (${MATERIAL_LABELS[key]}) — ${RHO_REFERENCE}`;
}
