/*
 * Fluide frigorigène d'une chambre froide (logique pure) : liste des fluides proposés, interdictions
 * du règlement (UE) 2024/573, charge estimée, contrôles d'étanchéité, détection des fuites, limites
 * de charge des fluides inflammables (NF EN 378-1) et rendement (COP).
 *
 * - Interdictions (mise sur le marché, annexe IV ; entretien, article 13) : règles de l'admin
 *   (FGasRule), transmises au site seulement une fois cochées « Vérifiée » ; aucune n'est codée ici.
 * - Contrôles d'étanchéité (article 5) et détection (article 6) : seuils confirmés par l'utilisateur
 *   le 2026-10-23 sur le texte officiel (docs/CHAMBRE_FROIDE_V2.md, section 1.3).
 * - Charge estimée, COP, limites NF EN 378-1 (installations montées sur site) et limite par circuit de
 *   la norme produit IEC 60335-2-89 (groupes autonomes hermétiquement scellés) : estimations
 *   indicatives, à vérifier.
 *
 * Testé par : chambre_froide.test.js
 * Utilisé par : chambre_froide.js, enceinte_climatique.js, fluid_report.js, contrôleurs de la chambre froide et de l'enceinte
 */

/** Types d'installation : libellé et charge de fluide estimée (kg par kW frigorifique, kg par mètre de liaison). À vérifier. */
export const INSTALLATION_TYPES = {
    self_contained: { label: "Groupe autonome (monobloc)", kgPerKw: 0.3, kgPerMetre: 0 },
    split: { label: "Groupe de condensation à distance (split)", kgPerKw: 0.6, kgPerMetre: 0.05 },
    centralised: { label: "Centrale multipostes", kgPerKw: 2, kgPerMetre: 0 },
};

/**
 * Charge par fluide (kg par kW frigorifique), quand elle diffère de celle du type d'installation :
 * groupes autonomes au R-290, 0,15 kg/kW (masse volumique liquide plus faible que celle des HFC ;
 * décision du 2026-10-23). À vérifier avec les fiches constructeurs ; modifiable en mode pro.
 */
export const FLUID_CHARGE_RATIOS = { R290: { self_contained: 0.15 } };

/** Contrôles d'étanchéité (article 5) : charge minimale (t éq. CO₂) → périodicité (mois), sans et avec détection. */
export const LEAK_CHECKS = [
    { minTonnes: 500, months: 3, monthsWithDetection: 6 },
    { minTonnes: 50, months: 6, monthsWithDetection: 12 },
    { minTonnes: 5, months: 12, monthsWithDetection: 24 },
];

/** Exemption des équipements hermétiquement scellés en dessous de cette charge (t éq. CO₂), article 5. */
export const HERMETIC_EXEMPTION_TONNES = 10;

/** Détection des fuites obligatoire à partir de cette charge (t éq. CO₂), article 6, vérifiée tous les 12 mois. */
export const DETECTION_THRESHOLD_TONNES = 500;

/** Fluides inflammables : classes de sécurité (ISO 817) soumises aux limites de charge de NF EN 378-1. */
export const FLAMMABLE_CLASSES = ["A2L", "A2", "A3", "B2L"];

/**
 * Limites de charge des fluides inflammables (NF EN 378-1, annexe C) : limite pratique = 20 % de la
 * LFL ; seuils m1, m2, m3 = 4, 26 et 130 m³ × LFL. À vérifier selon l'édition en vigueur (facteurs
 * des A2L) et la catégorie d'accès du local.
 */
export const EN378 = { practicalLimitShare: 0.2, m1: 4, m2: 26, m3: 130 };

/**
 * Norme produit IEC 60335-2-89 (appareils de réfrigération commerciale à groupe incorporé) : charge
 * maximale par circuit frigorifique d'un fluide A2 ou A3 = 13 × LFL (≈ 0,49 kg de R-290), appliquée aux
 * groupes autonomes hermétiquement scellés à la place du contrôle NF EN 378-1 du local (décision du
 * 2026-10-23). Facteur et conditions associées (au-delà de 150 g : taille du local, circulation d'air)
 * à vérifier sur le texte de la norme.
 */
export const IEC_60335_2_89 = { lflFactor: 13, classes: ["A2", "A3"], conditionsAboveKg: 0.15 };

/**
 * Rendement : COP = fraction de Carnot × T_év / (T_cond − T_év), en kelvins, avec T_év = consigne −
 * 8 K et T_cond = air au condenseur + 10 K (docs/CHAMBRE_FROIDE_V2.md, section 2.5). Aucune
 * correction par fluide n'est appliquée tant qu'elle n'est pas validée. À vérifier.
 */
export const COP_MODEL = { carnotFraction: 0.45, evaporatorApproach: 8, condenserApproach: 10 };

/** Familles jamais proposées (fluides appauvrissant la couche d'ozone). */
const EXCLUDED_FAMILIES = ["CFC", "HCFC"];

/** PRG au-delà duquel aucun fluide n'est proposé (PRG ≥ 2 500). */
export const MAX_GWP = 2500;

/** Seuil des fluides à faible PRG, proposés en premier (PRG < 150). */
export const LOW_GWP = 150;

/**
 * Fluides proposés, par groupe : PRG < 150, puis 150 à 2 500 ; aucun CFC, HCFC ni fluide de PRG ≥ 2 500.
 *
 * @param {Object<string, object>} refrigerants base des fluides (clé : code)
 * @returns {{low: Array<[string, object]>, medium: Array<[string, object]>}}
 */
export function proposedRefrigerants(refrigerants) {
    const usable = Object.entries(refrigerants).filter(([, fluid]) => !EXCLUDED_FAMILIES.includes(fluid.family) && fluid.gwp < MAX_GWP);

    return { low: usable.filter(([, fluid]) => fluid.gwp < LOW_GWP), medium: usable.filter(([, fluid]) => fluid.gwp >= LOW_GWP) };
}

/**
 * Règles d'interdiction qui visent ce fluide dans cette installation (règles vérifiées de l'admin).
 *
 * @param {object} fluid fluide (gwp)
 * @param {Array<object>} rules règles (FGasRule::toCalculatorArray())
 * @param {{installation: string, capacityKw: number, chargeTonnes: number, today: string}} context
 *        today : date du jour, AAAA-MM-JJ
 * @returns {Array<{rule: object, inForce: boolean}>} règles applicables ; inForce : déjà en vigueur
 */
export function applicableRules(fluid, rules, { installation, capacityKw, chargeTonnes, today }) {
    return rules
        .filter((rule) => fluid.gwp >= rule.gwp && rule.types.includes(installation))
        .filter((rule) => rule.minCapacityKw === null || capacityKw >= rule.minCapacityKw)
        .filter((rule) => rule.kind !== "servicing" || rule.minChargeTonnes === null || chargeTonnes >= rule.minChargeTonnes)
        .map((rule) => ({ rule, inForce: rule.since <= today }));
}

/**
 * Texte d'une règle : « Interdit pour [équipement] depuis le [date] — règlement (UE) 2024/573, [article], [point] ».
 *
 * @param {{rule: object, inForce: boolean}} item
 */
export function ruleText({ rule, inForce }) {
    const [year, month, day] = rule.since.split("-");
    const what = rule.kind === "servicing" ? "Entretien interdit" : "Interdit";
    const when = inForce ? `depuis le ${day}/${month}/${year}` : `à partir du ${day}/${month}/${year}`;
    const reference = ["règlement (UE) 2024/573", rule.article, rule.annexPoint].filter(Boolean).join(", ");
    const exception = rule.exception ? ` Exception : ${rule.exception}.` : "";

    return `${what} (${rule.equipment}) ${when} pour un PRG ≥ ${rule.gwp} — ${reference}.${exception}`;
}

/** Fluide grisé dans la liste : une règle de mise sur le marché est déjà en vigueur. */
export function isBanned(fluid, rules, context) {
    return applicableRules(fluid, rules, context).some(({ rule, inForce }) => inForce && rule.kind === "placing");
}

/**
 * Charge par kW frigorifique (kg/kW) par défaut : celle du fluide pour ce type d'installation
 * (FLUID_CHARGE_RATIOS), sinon celle du type d'installation.
 *
 * @param {string} installation type d'installation   @param {string|null} fluidCode code du fluide (« R290 »)
 */
export function defaultChargeRatio(installation, fluidCode) {
    const type = INSTALLATION_TYPES[installation] ?? INSTALLATION_TYPES.split;

    return FLUID_CHARGE_RATIOS[fluidCode]?.[installation] ?? type.kgPerKw;
}

/**
 * Charge de fluide estimée (kg) : kg/kW × puissance frigorifique + kg/m × longueur des liaisons (split).
 *
 * @param {string} installation type d'installation   @param {number} capacityKw puissance frigorifique (kW)
 * @param {number} lineLength longueur des liaisons frigorifiques (m)
 * @param {number} [kgPerKw] charge par kW (par défaut : celle du type d'installation)
 */
export function estimatedCharge(installation, capacityKw, lineLength, kgPerKw) {
    const type = INSTALLATION_TYPES[installation] ?? INSTALLATION_TYPES.split;

    return (kgPerKw ?? type.kgPerKw) * capacityKw + type.kgPerMetre * lineLength;
}

/**
 * Contrôles d'étanchéité et détection (articles 5 et 6) pour une charge en t éq. CO₂.
 *
 * @param {number} tonnes charge (t éq. CO₂)
 * @param {{hermetic: boolean, detection: boolean}} options équipement hermétiquement scellé ; détection installée
 * @returns {{months: number|null, exempt: boolean, detectionRequired: boolean}} months : périodicité du contrôle (null : aucun contrôle obligatoire)
 */
export function leakChecks(tonnes, { hermetic, detection }) {
    const detectionRequired = tonnes >= DETECTION_THRESHOLD_TONNES;
    const exempt = hermetic && tonnes < HERMETIC_EXEMPTION_TONNES;
    const step = LEAK_CHECKS.find((row) => tonnes >= row.minTonnes);
    if (exempt || step === undefined) {
        return { months: null, exempt, detectionRequired };
    }

    return { months: detection || detectionRequired ? step.monthsWithDetection : step.months, exempt, detectionRequired };
}

/**
 * Limites de charge d'un fluide inflammable (NF EN 378-1, annexe C, à vérifier), ou null s'il ne l'est pas.
 *
 * @param {object} fluid fluide (class, lfl en kg/m³)   @param {number} volume volume de la chambre (m³)
 * @returns {null|{missing: true}|{lfl: number, practicalLimit: number, roomLimit: number, m1: number, m2: number, m3: number}}
 *          missing : LFL non renseignée dans l'admin
 */
export function flammabilityLimits(fluid, volume) {
    if (!FLAMMABLE_CLASSES.includes(fluid.class)) {
        return null;
    }
    if (fluid.lfl === null || fluid.lfl === undefined) {
        return { missing: true };
    }
    const practicalLimit = EN378.practicalLimitShare * fluid.lfl;

    return { lfl: fluid.lfl, practicalLimit, roomLimit: practicalLimit * volume, m1: EN378.m1 * fluid.lfl, m2: EN378.m2 * fluid.lfl, m3: EN378.m3 * fluid.lfl };
}

/**
 * Limite par circuit de la norme produit IEC 60335-2-89 (13 × LFL), pour un groupe autonome
 * hermétiquement scellé chargé d'un fluide A2 ou A3 ; null sinon (le contrôle NF EN 378-1 s'applique).
 *
 * @param {object} fluid fluide (class, lfl en kg/m³)
 * @param {{installation: string, hermetic: boolean}} equipment
 * @returns {null|{lfl: number, circuitLimit: number, conditionsAboveKg: number}}
 */
export function productStandardLimit(fluid, { installation, hermetic }) {
    if (installation !== "self_contained" || !hermetic || !IEC_60335_2_89.classes.includes(fluid.class) || fluid.lfl === null || fluid.lfl === undefined) {
        return null;
    }

    return { lfl: fluid.lfl, circuitLimit: IEC_60335_2_89.lflFactor * fluid.lfl, conditionsAboveKg: IEC_60335_2_89.conditionsAboveKg };
}

/**
 * Charge de fluide et obligations d'une installation : charge estimée (kg, t éq. CO₂), contrôles
 * d'étanchéité, règles d'interdiction vérifiées, limites NF EN 378-1 et IEC 60335-2-89.
 *
 * Liens :
 * - Appelée par : chambre_froide.js (refrigerantInfo), enceinte_climatique.js
 *
 * @param {{fluid: object, fluidCode?: string, installation: string, hermetic?: boolean, detection?: boolean, chargeRatio?: number, rules?: Array<object>, today?: string}} r
 *        fluide et installation ; chargeRatio : kg/kW saisi (sinon défaut du fluide et du type)
 * @param {number} frigoKw puissance frigorifique (kW)   @param {number} lineLength longueur des liaisons comptée (m)
 * @param {number} volume volume du local ou de l'enceinte (m³)
 */
export function refrigerantCharge(r, frigoKw, lineLength, volume) {
    const fluid = r.fluid;
    const kgPerKw = r.chargeRatio > 0 ? r.chargeRatio : defaultChargeRatio(r.installation, r.fluidCode ?? null);
    const chargeKg = estimatedCharge(r.installation, frigoKw, lineLength, kgPerKw);
    const tonnes = (chargeKg * fluid.gwp) / 1000;
    const context = { installation: r.installation, capacityKw: frigoKw, chargeTonnes: tonnes, today: r.today };

    return {
        fluid, chargeKg, kgPerKw, chargeRatioEntered: r.chargeRatio > 0, tonnes,
        checks: leakChecks(tonnes, { hermetic: Boolean(r.hermetic), detection: Boolean(r.detection) }),
        rules: applicableRules(fluid, r.rules ?? [], context),
        flammability: flammabilityLimits(fluid, volume),
        productStandard: productStandardLimit(fluid, { installation: r.installation, hermetic: Boolean(r.hermetic) }),
    };
}

/**
 * COP estimé (COP_MODEL).
 *
 * @param {number} tSet consigne (°C)   @param {number} condenserAir air au condenseur (°C)
 * @returns {{cop: number, tEvap: number, tCond: number}}
 */
export function estimatedCop(tSet, condenserAir) {
    const tEvap = tSet - COP_MODEL.evaporatorApproach;
    const tCond = condenserAir + COP_MODEL.condenserApproach;
    const cop = (COP_MODEL.carnotFraction * (tEvap + 273.15)) / Math.max(1, tCond - tEvap);

    return { cop, tEvap, tCond };
}
