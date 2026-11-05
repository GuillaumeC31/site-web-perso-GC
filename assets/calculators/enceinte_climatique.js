/*
 * Enceinte climatique, version 2 (logique pure, sans DOM) : modes simple (hypothèses fixes et affichées)
 * et pro (tout saisi), cas été et cas hiver calculés côte à côte.
 *
 * Reprend le moteur de la chambre froide v2 (panneaux par λ ou modèles de l'admin, portes et
 * infiltrations ASHRAE, cordons, vitrages, fluide et contrôles F-Gas / NF EN 378 / IEC 60335-2-89) et
 * ajoute les postes propres aux enceintes : ventilateurs, échantillons, apports manuels, humidification
 * (chaleur sensible de la vapeur), condensation sur la batterie froide, séchage, mises en régime de
 * température et d'humidité, régulation (« froid permanent + réchauffage » comparé à la modulation).
 *
 * Méthode et valeurs par défaut : docs/ENCEINTE_CLIMATIQUE_V2.md (validées le 2026-10-23), toutes
 * indicatives et à vérifier. L'outil dimensionne les puissances ; la stabilité et l'homogénéité réelles
 * se vérifient par caractérisation (FD X 15-140, IEC 60068-3-5 et 3-6).
 *
 * Testé par : enceinte_climatique.test.js (3 cas de référence golden/enceinte_climatique_v2.json, produits
 * par le contre-calcul indépendant scripts/calculator/climatic_chamber_hand_check.py)
 * Utilisé par : assets/controllers/calculator/enceinte_climatique_controller.js
 */
import initialProfiles from "../../config/calculator/climatic_profiles.json";
import { checkInputs } from "./inputs.js";
import { COP_MODEL, refrigerantCharge } from "./fgas.js";
import { dewPoint, moistAir } from "./psychrometrics.js";
import { DOOR_FRAME, DOOR_PROTECTIONS, geometry, INFILTRATION, panelU, personHeat } from "./chambre_froide.js";
import { formatNumber } from "./format.js";

/** Chaleur latente de vaporisation à 0 °C (kJ/kg). */
export const LATENT_HEAT = 2501;

/** Enthalpie de la vapeur saturée à 100 °C (kJ/kg), pour la chaleur sensible apportée par un humidificateur à vapeur. */
export const STEAM_ENTHALPY = 2676;

/** Chaleur de vaporisation de l'eau à 100 °C (kJ/kg) : générateur de vapeur, vapeur de chauffe. */
export const VAPORISATION_100 = 2257;

/** Chaleur massique de l'eau (kJ/(kg·K)). */
export const WATER_CP = 4.186;

/** COP plafonné (validé le 2026-10-23) : 0,45 × Carnot, évaporation = consigne − 8 K, condenseur à l'air + 10 K. */
export const COP_MAX = 5;

/** Surface de la batterie froide = évaporation + 2 K par défaut (prudent, validé le 2026-10-23), modifiable. */
export const SURFACE_OFFSET = 2;

/** Séchage par batterie froide : sortie à point de rosée − 2 K, air saturé ; évaporation 5 K sous la batterie. */
export const DRYING_COIL = { belowDewPoint: 2, evaporatorApproach: 5 };

/** Point de rosée de consigne sous lequel la batterie de séchage risque de givrer (°C). */
export const FROST_DEW_POINT = 2;

/** Dessiccateur (rotor) : énergie de régénération par kg d'eau retirée (kWh/kg), validée le 2026-10-23. */
export const DESICCANT_KWH_PER_KG = 1;

/** Local d'une enceinte posée dans un bâtiment : été 30 °C / 60 % HR, hiver 18 °C / 30 % HR. */
const BUILDING_LOCAL = { summer: { localT: 30, localRh: 60 }, winter: { localT: 18, localRh: 30 } };

/** Masses de la peau intérieure (inox 1 mm), des étagères et de la structure (batteries, ventilation). */
const MODULAR_MASSES = { skinKgPerM2: 7.9, skinCp: 0.5, shelvesKg: 30, shelvesCp: 0.5, structureKg: 40, structureCp: 0.6 };

/**
 * Types d'enceinte : ventilateur ou turbine (W/m³, 100 % de la puissance dans l'enceinte, 24 h/24),
 * masses, local, porte, éclairage. Le choix du type remplit ces valeurs, toutes modifiables (mode pro).
 * - monobloc : armoire ou enceinte de laboratoire de 150 à 3 000 litres ; volume utile en litres ou
 *   dimensions ; porte sur toute la face avant ; la chaleur du groupe frigorifique intégré est rejetée
 *   dans le local, pas dans l'enceinte (à proposer, à valider) ;
 * - modular : chambre accessible en panneaux sandwich montée dans un bâtiment (valeurs validées) ;
 * - modular_outdoor : même chambre dans un bâtiment modulaire extérieur (bungalow, module préfabriqué) :
 *   le local est l'intérieur du module, 35 °C / 45 % en été (+ 5 K au soleil) ; en hiver, module chauffé
 *   hors gel (5 °C / 70 %, par défaut) ou non chauffé (température extérieure minimale, −5 °C par défaut).
 * Valeurs validées le 2026-10-23.
 */
export const CHAMBER_KINDS = {
    monobloc: {
        label: "Monobloc", hint: "Armoire ou enceinte de laboratoire, 150 à 3 000 litres", fanWPerM3: 60, volumeLiters: 1000, frontDoor: true,
        masses: { skinKgPerM2: 7.9, skinCp: 0.5, shelvesKg: 15, shelvesCp: 0.5, structureKg: 25, structureCp: 0.6 }, ...BUILDING_LOCAL, lighting: { watts: 20, hours: 2 },
    },
    modular: {
        label: "Modulaire", hint: "Chambre accessible en panneaux sandwich, dans un bâtiment", fanWPerM3: 35, frontDoor: false,
        masses: MODULAR_MASSES, ...BUILDING_LOCAL, lighting: { watts: 40, hours: 2 },
    },
    modular_outdoor: {
        label: "Modulaire extérieure", hint: "Chambre en panneaux sandwich dans un module extérieur (bungalow, module préfabriqué)", fanWPerM3: 35, frontDoor: false,
        masses: MODULAR_MASSES, summer: { localT: 35, localRh: 45 }, winter: { localT: 5, localRh: 70 }, sunExtraK: 5, lighting: { watts: 40, hours: 2 },
        unheatedWinter: { localT: -5, localRh: 90 },
    },
};

/** Proportions intérieures d'un monobloc saisi par son volume : largeur L = V^(1/3), profondeur 0,8 L, hauteur 1,25 L (à valider). */
export const MONOBLOC_PROPORTIONS = { depth: 0.8, height: 1.25 };

/**
 * Dimensions intérieures d'un monobloc à partir de son volume utile (litres).
 *
 * @param {number} liters volume utile (L)
 * @returns {{length: number, width: number, height: number}} largeur (face avant), profondeur, hauteur (m)
 */
export function volumeToDimensions(liters) {
    const length = Math.cbrt(liters / 1000 / (MONOBLOC_PROPORTIONS.depth * MONOBLOC_PROPORTIONS.height));

    return { length, width: MONOBLOC_PROPORTIONS.depth * length, height: MONOBLOC_PROPORTIONS.height * length };
}

/** Porte par défaut d'un type d'enceinte : 0,8 × 2 m, ou toute la face avant d'un monobloc. */
export function defaultDoor(kind, length, height) {
    const k = CHAMBER_KINDS[kind] ?? CHAMBER_KINDS.modular;

    return k.frontDoor ? { width: length, height } : { width: DEFAULTS.door.width, height: DEFAULTS.door.height };
}

/** Classes de stabilité en température (K) : régulation conseillée (validée le 2026-10-23). */
export const STABILITY_T = {
    "0.5": { label: "± 0,5 K", advice: "modulation conseillée (variateur, by-pass gaz chaud) ; la comparaison avec « froid permanent + réchauffage » est affichée", modulate: true },
    1: { label: "± 1 K", advice: "modulation conseillée (variateur, by-pass gaz chaud) ; la comparaison avec « froid permanent + réchauffage » est affichée", modulate: true },
    2: { label: "± 2 K", advice: "régulation tout ou rien acceptable", modulate: false },
    3: { label: "± 3 K", advice: "régulation tout ou rien acceptable", modulate: false },
};

/** Classes de stabilité en humidité (% HR) : régulation conseillée. */
export const STABILITY_RH = {
    1: { label: "± 1 % HR", advice: "humidificateur modulant et séchage permanent" },
    2: { label: "± 2 % HR", advice: "humidificateur modulant et séchage permanent" },
    3: { label: "± 3 % HR", advice: "humidification et séchage modulés" },
    5: { label: "± 5 % HR", advice: "humidification et séchage modulés" },
    10: { label: "± 10 % HR", advice: "régulation tout ou rien acceptable" },
};

/** Modes de régulation du froid. */
export const REGULATION_MODES = {
    modulating: "Modulation (variateur, by-pass gaz chaud)",
    on_off: "Tout ou rien",
    permanent_reheat: "Froid permanent + réchauffage",
};

/** Chauffage : électrique (rendement 1), eau chaude, vapeur, autre (rendement saisi). Seul l'électrique compte dans la consommation électrique. */
export const HEATING_TYPES = {
    electric: { label: "Électrique", electric: true },
    hot_water: { label: "Eau chaude", electric: false },
    steam: { label: "Vapeur", electric: false },
    other: { label: "Autre (rendement saisi)", electric: false },
};

/** Refroidissement : détente directe (fluide), eau glacée, autre (COP saisi). */
export const COOLING_TYPES = {
    dx: { label: "Détente directe" },
    chilled_water: { label: "Eau glacée" },
    other: { label: "Autre (COP saisi)" },
};

/** Humidification : vapeur électrique, vapeur réseau, pulvérisation ou ultrasons (adiabatique), autre (rendement saisi). */
export const HUMIDIFIER_TYPES = {
    steam_electric: { label: "Vapeur électrique (électrodes ou résistances)", steam: true, electric: true },
    steam_network: { label: "Vapeur réseau", steam: true, electric: false },
    adiabatic: { label: "Pulvérisation ou ultrasons (adiabatique)", steam: false, electric: false },
    other: { label: "Autre (rendement saisi)", steam: true, electric: true },
};

/** Séchage : batterie froide (détente directe ou eau glacée), dessiccateur (rotor), autre (COP saisi). */
export const DRYING_TYPES = {
    dx_coil: { label: "Batterie froide en détente directe", coil: true },
    chilled_water_coil: { label: "Batterie à eau glacée", coil: true },
    desiccant: { label: "Dessiccateur (rotor)", coil: false },
    other: { label: "Autre (COP saisi)", coil: true },
};

/**
 * Valeurs par défaut (docs/ENCEINTE_CLIMATIQUE_V2.md, section 2), aussi hypothèses fixes du mode simple :
 * chambre de stabilité accessible 2 × 1,5 × 2 m, PUR 80 mm, fuites 0,5 %/h, local été 30 °C / 60 %,
 * hiver 18 °C / 30 %, air neuf été 32 °C / 40 %, hiver −5 °C / 90 %, peau inox 1 mm (7,9 kg/m², cp 0,5),
 * étagères 30 kg (cp 0,5), structure 40 kg (cp 0,6), porte 0,8 × 2 m (5 ouvertures de 20 s par jour),
 * éclairage 40 W 2 h/j, instrumentation 10 W 24 h/24, cordons 40 W/m sous +5 °C, échantillons cp 1,5,
 * eau à 15 °C, mise en régime de température en 60 min et d'humidité en 30 min, eau chaude 70 / 50 °C, eau glacée 7 / 12 °C, air au
 * condenseur 35 °C, marge 10 %, 24 h/24.
 */
export const DEFAULTS = {
    kind: "modular", length: 2, width: 1.5, height: 2, insulation: "PUR", thickness: 80, leakPct: 0.5,
    summer: { localT: 30, localRh: 60, freshT: 32, freshRh: 40 },
    winter: { localT: 18, localRh: 30, freshT: -5, freshRh: 90 },
    masses: { skinKgPerM2: 7.9, skinCp: 0.5, shelvesKg: 30, shelvesCp: 0.5, structureKg: 40, structureCp: 0.6 },
    stabilityT: "2", stabilityRh: "5", rampMinutes: 60,
    door: { width: 0.8, height: 2, u: 0.55, openingsPerDay: 5, openSeconds: 20, heaterWPerM: 40, heaterFraction: 0.7, heaterBelowT: 5 },
    lighting: { watts: 40, hours: 2 }, instrumentation: { watts: 10, hours: 24 },
    samples: { kg: 0, cp: 1.5, deltaT: 0, dissipatedW: 0, hours: 24, waterGPerH: 0, pullDownHours: 12 },
    waterT: 15, humidMinutes: 30, hotWater: { supplyT: 70, returnT: 50 }, chilledWater: { supplyT: 7, returnT: 12, evaporatorApproach: 5 },
    condenserAir: 35, margin: 10, runHours: 24, lineLength: 10,
};

/**
 * Régulation PID (section 8 de docs/ENCEINTE_CLIMATIQUE_V2.md, validée le 2026-10-23) : actionneurs de chaque
 * fonction et leur plage de modulation minimale (%), null pour un tout ou rien. Valeurs à vérifier.
 */
export const ACTUATORS = {
    heat: {
        ssr: { label: "Relais statique (cycle court ≤ 1 s)", minPct: 0 },
        phase: { label: "Gradateur à angle de phase", minPct: 0 },
        contactor: { label: "Contacteur (cycle long)", minPct: null },
    },
    cold: {
        vsd: { label: "Compresseur à variateur", minPct: 30 },
        hot_gas: { label: "By-pass de gaz chaud", minPct: 0 },
        eev: { label: "Détendeur électronique", minPct: 10 },
        valve: { label: "Vanne d'eau glacée 3 voies", minPct: 0 },
        on_off: { label: "Tout ou rien", minPct: null },
    },
    humid: {
        modulating_steam: { label: "Vapeur modulante", minPct: 10 },
        on_off: { label: "Tout ou rien", minPct: null },
    },
    dry: {
        valve: { label: "Vanne de batterie", minPct: 0 },
        desiccant: { label: "Dessiccateur modulant", minPct: 20 },
    },
};

/** Solutions proposées quand la puissance minimale est sous la plage de l'actionneur. */
export const RANGE_SOLUTIONS = {
    heat: "relais statique à cycle court ou gradateur, ou étage de base plus petit",
    cold: "by-pass de gaz chaud, « froid permanent + réchauffage » régulé en PID, ou compresseur à plus large plage",
    humid: "humidificateur à plus large plage ou étage de base plus petit",
    dry: "vanne de batterie modulante",
};

/**
 * Réglages par défaut de la régulation : actionneurs, brassage (vol/h selon le type d'enceinte), temps de
 * réponse des sondes (s), étendue de mesure (K), τc = facteur × θ (1 : standard ; 0,5 nerveux à 3 robuste),
 * PI (dérivée en option), seuil « puissance trop forte » (× maintien), matériaux hygroscopiques (aucun).
 */
export const PID_DEFAULTS = {
    actuators: { heat: "ssr", cold: "vsd", humid: "modulating_steam", dry: "valve" }, coldMinPct: 30,
    airChanges: { monobloc: 150, modular: 60, modular_outdoor: 60 }, sensorT: 20, sensorRh: 30, spanT: 150,
    tauFactor: 1, derivative: false, stagingRatio: 3, hygroMassKg: 0, hygroCapacity: 0,
};

/** Décimales d'affichage. */
export const DECIMALS = { w: 0, kw: 2, kwh: 1, volume: 1, surface: 1, cop: 2, gh: 0, l: 1, minutes: 0, t: 1 };

/** Profils de consigne par défaut (données initiales de l'admin) ; sur le site, la base de l'admin les remplace. */
export const PROFILES = initialProfiles.profiles;

/** Champ vide : NaN, ou absent. */
const empty = (value) => value === undefined || value === null || Number.isNaN(value);

/** Valeur saisie, ou valeur par défaut si le champ est vide ; un 0 saisi reste 0 (C6). */
const or = (value, fallback) => (empty(value) ? fallback : value);

/** Nombre affiché à la française. */
const n = (value, digits = 2) => formatNumber(value, digits);

/** Humidité régulée : consigne d'humidité renseignée. */
export const humidityRegulated = (x) => !empty(x.rhSet);

/**
 * COP estimé : 0,45 × T_év / (T_cond − T_év) en kelvins, T_cond = air au condenseur + 10 K, plafonné à 5.
 *
 * @param {number} tEvap température d'évaporation (°C)   @param {number} condenserAir air au condenseur (°C)
 */
export function carnotCop(tEvap, condenserAir) {
    const tCond = condenserAir + COP_MODEL.condenserApproach;

    return Math.min(COP_MAX, (COP_MODEL.carnotFraction * (tEvap + 273.15)) / Math.max(1, tCond - tEvap));
}

/** Air humide (rapporté à la glace sous 0 °C). */
const air = (t, rh) => ({ t, rh, ...moistAir(t, rh, t < 0) });

/** Teneur en eau à saturation (kg/kg). */
const wSat = (t) => moistAir(t, 100, t < 0).w;

/**
 * Débit moyen d'air sec échangé par une porte (kg/s), ASHRAE (Gosney et Olama) : côté dense = air le
 * plus froid ; q = 0,221 × A × ρ_dense × (1 − ρ_léger/ρ_dense)^0,5 × (g·H)^0,5 × F_m, multiplié par
 * D_t (ouvertures × durée / 86 400) × D_f × (1 − E).
 */
export function doorAirFlow(door, local, inside) {
    const [dense, light] = inside.rho >= local.rho ? [inside, local] : [local, inside];
    if (Math.abs(dense.rho - light.rho) < 1e-12) {
        return 0;
    }
    const fm = (2 / (1 + (dense.rho / light.rho) ** (1 / 3))) ** 1.5;
    const open = 0.221 * door.width * door.height * dense.rho * Math.sqrt(1 - light.rho / dense.rho) * Math.sqrt(INFILTRATION.g * door.height) * fm;

    return open * ((door.openingsPerDay * door.openSeconds) / 86400) * INFILTRATION.flowFactor * (1 - door.efficiency);
}

/** Transmission d'une porte (W/K) : U × S + cadre (U 1,2 sur une bande de 6 cm du périmètre). */
const doorUa = (d) => d.u * d.width * d.height + DOOR_FRAME.u * 2 * (d.width + d.height) * DOOR_FRAME.band;

/** Longueur du cordon chauffant d'une porte (m) : périmètre, plus le montant central si 2 vantaux. */
const heaterLength = (d) => 2 * (d.width + d.height) + (d.leaves === 2 ? d.height : 0);

/** Champ contrôlé seulement s'il est rempli. */
const optional = (value, label, rule) => (empty(value) ? [] : [{ value, label, rule }]);

/** Contrôles du panneau (comme la chambre froide). */
function panelFields(panel) {
    if (panel.source === "custom_lambda") {
        return [{ value: panel.lambda, label: "Conductivité λ du panneau", rule: "positive" }, { value: panel.thickness, label: "Épaisseur du panneau", rule: "positive" }];
    }
    if (panel.source === "custom_u" || panel.source === "model") {
        return [{ value: panel.u, label: "Coefficient U du panneau", rule: "positive" }];
    }

    return [{ value: panel.thickness, label: "Épaisseur du panneau", rule: "positive" }];
}

/** Contrôles des saisies complètes (mode pro, ou mode simple converti). */
function fullFields(x) {
    const season = (s, name) => [
        { value: s.localT, label: `Local, ${name} : température`, rule: "celsius" },
        { value: s.localRh, label: `Local, ${name} : humidité`, rule: "relativeHumidity" },
        ...(x.airRenewal > 0 ? [{ value: s.freshT, label: `Air neuf, ${name} : température`, rule: "celsius" }, { value: s.freshRh, label: `Air neuf, ${name} : humidité`, rule: "relativeHumidity" }] : []),
    ];

    return [
        { value: x.length, label: "Longueur", rule: "positive" },
        { value: x.width, label: "Largeur", rule: "positive" },
        { value: x.height, label: "Hauteur", rule: "positive" },
        ...panelFields(x.panel),
        { value: x.leakPct, label: "Fuites", rule: "nonNegative" },
        ...season(x.summer, "été"),
        ...season(x.winter, "hiver"),
        { value: x.masses.skinKgPerM2, label: "Peau intérieure : masse", rule: "nonNegative" },
        { value: x.masses.skinCp, label: "Peau intérieure : capacité thermique", rule: "nonNegative" },
        { value: x.masses.shelvesKg, label: "Étagères : masse", rule: "nonNegative" },
        { value: x.masses.shelvesCp, label: "Étagères : capacité thermique", rule: "nonNegative" },
        { value: x.masses.structureKg, label: "Structure : masse", rule: "nonNegative" },
        { value: x.masses.structureCp, label: "Structure : capacité thermique", rule: "nonNegative" },
        { value: x.tSet, label: "Température de consigne", rule: "celsius" },
        ...optional(x.rhSet, "Humidité de consigne", "relativeHumidity"),
        ...optional(x.tStart, "Température de départ", "celsius"),
        ...optional(x.rhStart, "Humidité de départ", "relativeHumidity"),
        { value: x.rampMinutes, label: "Mise en régime de température", rule: "positive" },
        { value: x.humidMinutes, label: "Durée de mise en régime d'humidité", rule: "positive" },
        ...x.doors.flatMap((d, i) => [
            { value: d.width, label: `Porte ${i + 1} : largeur`, rule: "positive" },
            { value: d.height, label: `Porte ${i + 1} : hauteur`, rule: "positive" },
            { value: d.u, label: `Porte ${i + 1} : coefficient U`, rule: "positive" },
            // Efficacité saisie en % (0 à 100) sur la page, fraction dans le calcul
        { value: d.efficiency * 100, label: `Porte ${i + 1} : efficacité de la protection`, rule: "percentOrZero" },
            { value: d.openingsPerDay, label: `Porte ${i + 1} : ouvertures par jour`, rule: "nonNegative" },
            { value: d.openSeconds, label: `Porte ${i + 1} : durée d'ouverture`, rule: "nonNegative" },
            { value: d.heaterWPerM, label: `Porte ${i + 1} : cordon chauffant`, rule: "nonNegative" },
        ]),
        ...x.glazings.flatMap((g, i) => [
            { value: g.area, label: `Vitrage ${i + 1} : surface`, rule: "positive" },
            { value: g.u, label: `Vitrage ${i + 1} : coefficient U`, rule: "positive" },
            ...(g.heated ? [{ value: g.heaterW, label: `Vitrage ${i + 1} : puissance de chauffage`, rule: "nonNegative" }] : []),
        ]),
        { value: x.fans.wPerM3, label: "Ventilateur ou turbine", rule: "nonNegative" },
        { value: x.lighting.watts, label: "Éclairage", rule: "nonNegative" },
        { value: x.lighting.hours, label: "Heures d'éclairage", rule: "hoursOfDay" },
        { value: x.people.count, label: "Nombre de personnes", rule: "integerMin0" },
        { value: x.people.hours, label: "Présence des personnes", rule: "hoursOfDay" },
        { value: x.instrumentation.watts, label: "Câbles et instrumentation", rule: "nonNegative" },
        { value: x.instrumentation.hours, label: "Câbles et instrumentation : durée", rule: "hoursOfDay" },
        { value: x.samples.kg, label: "Échantillons : masse", rule: "nonNegative" },
        { value: x.samples.cp, label: "Échantillons : capacité thermique", rule: "positive" },
        { value: x.samples.deltaT, label: "Échantillons : écart de température", rule: "celsius" },
        { value: x.samples.dissipatedW, label: "Échantillons : puissance dissipée", rule: "nonNegative" },
        { value: x.samples.hours, label: "Échantillons : durée", rule: "hoursOfDay" },
        ...x.manual.flatMap((m, i) => [
            { value: m.watts, label: `Apport ${i + 1} : puissance`, rule: "nonNegative" },
            { value: m.hours, label: `Apport ${i + 1} : durée`, rule: "hoursOfDay" },
            { value: m.fraction, label: `Apport ${i + 1} : fraction restant dans l'enceinte`, rule: "fraction" },
        ]),
        ...(x.heating.type === "other" ? [{ value: x.heating.efficiency, label: "Chauffage : rendement", rule: "positive" }] : []),
        ...(x.heating.type === "hot_water" ? [{ value: x.heating.supplyT, label: "Eau chaude : départ", rule: "celsius" }, { value: x.heating.returnT, label: "Eau chaude : retour", rule: "celsius" }] : []),
        ...optional(x.heating.installedW, "Puissance de chauffe installée", "positive"),
        ...optional(x.cooling.installedW, "Puissance frigorifique installée", "positive"),
        ...(x.cooling.type === "chilled_water" ? [{ value: x.cooling.supplyT, label: "Eau glacée : départ", rule: "celsius" }, { value: x.cooling.returnT, label: "Eau glacée : retour", rule: "celsius" }] : []),
        ...(x.cooling.type === "other" ? [{ value: x.cooling.cop, label: "Refroidissement : COP", rule: "positive" }] : optional(x.cooling.cop, "COP imposé", "positive")),
        { value: x.cooling.surfaceOffset, label: "Surface de la batterie (écart à l'évaporation)", rule: "celsius" },
        { value: x.cooling.condenserAir, label: "Air au condenseur", rule: "celsius" },
        ...optional(x.cooling.refrigerant?.chargeRatio, "Charge de fluide par kW", "nonNegative"),
        { value: x.humidification.waterT, label: "Température de l'eau d'alimentation", rule: "celsius" },
        ...(x.humidification.type === "other" ? [{ value: x.humidification.efficiency, label: "Humidification : rendement", rule: "positive" }] : []),
        ...(x.drying.type === "other" ? [{ value: x.drying.cop, label: "Séchage : COP", rule: "positive" }] : []),
        { value: x.airRenewal, label: "Renouvellement d'air", rule: "nonNegative" },
        { value: x.runHours, label: "Temps de fonctionnement", rule: "hoursPerDay" },
        { value: x.margin, label: "Marge de sécurité", rule: "nonNegative" },
        ...optional(x.pid?.airChanges, "Brassage", "positive"),
        ...optional(x.pid?.sensorT, "Sonde de température : temps de réponse", "nonNegative"),
        ...optional(x.pid?.sensorRh, "Sonde d'humidité : temps de réponse", "nonNegative"),
        ...optional(x.pid?.spanT, "Étendue de mesure", "positive"),
        ...optional(x.pid?.coldMinPct, "Plage minimale du variateur", "percentOrZero"),
        ...optional(x.pid?.stagingRatio, "Seuil des étages", "positive"),
        ...optional(x.pid?.hygroMassKg, "Matériaux hygroscopiques : masse", "nonNegative"),
        ...optional(x.pid?.hygroCapacity, "Matériaux hygroscopiques : capacité", "nonNegative"),
    ];
}

/** Contrôles du mode simple. */
function simpleFields(s) {
    return [
        ...(s.kind === "monobloc" && s.sizeMode === "volume"
            ? [{ value: s.volumeLiters, label: "Volume utile", rule: "positive" }]
            : [{ value: s.length, label: "Longueur", rule: "positive" }, { value: s.width, label: "Largeur", rule: "positive" }, { value: s.height, label: "Hauteur", rule: "positive" }]),
        { value: s.tSet, label: "Température de consigne", rule: "celsius" },
        ...optional(s.rhSet, "Humidité de consigne", "relativeHumidity"),
        ...optional(s.tStart, "Température de départ", "celsius"),
        ...optional(s.rhStart, "Humidité de départ", "relativeHumidity"),
        ...(s.kind === "modular_outdoor" && s.frostProtected === false ? optional(s.outdoorMinT, "Température extérieure minimale", "celsius") : []),
        { value: or(s.rampMinutes, DEFAULTS.rampMinutes), label: "Mise en régime de température", rule: "positive" },
        { value: or(s.humidMinutes, DEFAULTS.humidMinutes), label: "Mise en régime d'humidité", rule: "positive" },
        { value: s.airRenewal, label: "Renouvellement d'air", rule: "nonNegative" },
    ];
}

/**
 * Message d'erreur des saisies (null si elles sont valides ou incomplètes).
 *
 * @param {"simple"|"pro"} mode   @param {object} x saisies du mode
 */
export function explain(mode, x) {
    return checkInputs(mode === "simple" ? simpleFields(x) : fullFields(x)).error;
}

/**
 * Saisies complètes à partir du mode simple : valeurs par défaut (DEFAULTS) pour tout le reste.
 *
 * @param {object} s saisies du mode simple : kind, sizeMode (« volume » ou « dims », monobloc), volumeLiters,
 *        length, width, height, sun (module exposé au soleil), frostProtected (module chauffé hors gel),
 *        outdoorMinT (température extérieure minimale, module non chauffé), tSet, rhSet (NaN : non régulée), stabilityT,
 *        stabilityRh, tStart, rhStart, rampMinutes, humidMinutes, doorProtection, airRenewal, heatingType,
 *        coolingType, humidifierType, dryingType, refrigerant
 * @returns {object} saisies au format du mode pro
 */
export function simpleToFull(s) {
    const d = DEFAULTS;
    const kind = CHAMBER_KINDS[s.kind] ?? CHAMBER_KINDS.modular;
    const protection = DOOR_PROTECTIONS[s.doorProtection] ?? DOOR_PROTECTIONS.none;
    const dims = s.kind === "monobloc" && s.sizeMode === "volume" ? volumeToDimensions(s.volumeLiters) : { length: s.length, width: s.width, height: s.height };
    const door = defaultDoor(s.kind, dims.length, dims.height);
    const sun = s.sun && kind.sunExtraK ? kind.sunExtraK : 0;
    // Module extérieur non chauffé : l'hiver est celui de l'extérieur (température minimale saisie)
    const winter = kind.unheatedWinter && s.frostProtected === false ? { ...kind.unheatedWinter, localT: or(s.outdoorMinT, kind.unheatedWinter.localT) } : kind.winter;

    return {
        mode: "simple", kind: s.kind, ...dims,
        panel: { source: "generic", insulation: d.insulation, thickness: d.thickness }, leakPct: d.leakPct,
        summer: { ...d.summer, ...kind.summer, localT: kind.summer.localT + sun }, winter: { ...d.winter, ...winter }, masses: { ...kind.masses },
        tSet: s.tSet, rhSet: s.rhSet, stabilityT: s.stabilityT, stabilityRh: s.stabilityRh,
        tStart: or(s.tStart, NaN), rhStart: or(s.rhStart, NaN), rampMinutes: or(s.rampMinutes, d.rampMinutes), humidMinutes: or(s.humidMinutes, d.humidMinutes),
        doors: [{
            width: door.width, height: door.height, leaves: 1, u: d.door.u, protection: s.doorProtection ?? "none", efficiency: protection.efficiency,
            openingsPerDay: d.door.openingsPerDay, openSeconds: d.door.openSeconds, heaterWPerM: s.tSet < d.door.heaterBelowT ? d.door.heaterWPerM : 0, heaterFraction: d.door.heaterFraction,
        }],
        glazings: [],
        fans: { wPerM3: kind.fanWPerM3 },
        lighting: { ...kind.lighting }, people: { count: 0, hours: 0 }, instrumentation: { ...d.instrumentation },
        samples: { ...d.samples }, manual: [],
        heating: { type: s.heatingType, efficiency: 1, supplyT: d.hotWater.supplyT, returnT: d.hotWater.returnT, installedW: NaN },
        cooling: {
            type: s.coolingType, supplyT: d.chilledWater.supplyT, returnT: d.chilledWater.returnT, cop: NaN, surfaceOffset: SURFACE_OFFSET, installedW: NaN,
            condenserAir: d.condenserAir, regulation: (STABILITY_T[s.stabilityT] ?? STABILITY_T[2]).modulate ? "modulating" : "on_off",
            refrigerant: { ...s.refrigerant, lineLength: d.lineLength, detection: false, chargeRatio: NaN },
        },
        humidification: { type: s.humidifierType, waterT: d.waterT, efficiency: 1 },
        drying: { type: s.dryingType, cop: NaN },
        airRenewal: or(s.airRenewal, 0), runHours: d.runHours, margin: d.margin, internalGainsInHeating: false,
    };
}

/** Ligne d'un poste (W, + = apport). */
const line = (key, label, watts, formula, detail, extra = {}) => ({ key, label, watts, formula, detail, ...extra });

/**
 * Apports internes : puissance de dimensionnement (tous présents), moyenne sur 24 h (durées déclarées),
 * électricité des équipements de l'enceinte (kWh/j ; la charge en essai n'en fait pas partie) et eau.
 */
function internalGains(x, geo) {
    const gains = [];
    const add = (key, label, sizingW, hours, equipmentKwhDay, formula, detail, water = 0, waterAvg = water) => gains.push({ key, label, sizingW, avgW: (sizingW * hours) / 24, hours, equipmentKwhDay, formula, detail, waterKgH: water, waterAvgKgH: waterAvg });
    const fans = x.fans.wPerM3 * geo.volume;
    add("fans", "Ventilateur ou turbine", fans, 24, (fans * 24) / 1000, "Q = W/m³ × volume, 24 h/24 (toute la puissance absorbée entre dans l'enceinte)", `${n(x.fans.wPerM3, 0)} W/m³ × ${n(geo.volume, 2)} m³`);
    add("lighting", "Éclairage", x.lighting.watts, x.lighting.hours, (x.lighting.watts * x.lighting.hours) / 1000, "Q = P éclairage ; consommation sur la durée d'allumage", `${n(x.lighting.watts, 0)} W, ${n(x.lighting.hours, 1)} h/j`);
    add("instrumentation", "Câbles et instrumentation", x.instrumentation.watts, x.instrumentation.hours, (x.instrumentation.watts * x.instrumentation.hours) / 1000, "Q = P", `${n(x.instrumentation.watts, 0)} W, ${n(x.instrumentation.hours, 1)} h/j`);
    if (x.people.count > 0 && x.people.hours > 0) {
        const each = personHeat(x.tSet);
        add("people", "Personnes", x.people.count * each, x.people.hours, 0, "Q = nombre × (270 − 6 × T) (ASHRAE)", `${n(x.people.count, 0)} × ${n(each, 0)} W, ${n(x.people.hours, 1)} h/j`);
    }
    if (x.samples.dissipatedW > 0) {
        add("samplesPower", "Échantillons : puissance dissipée", x.samples.dissipatedW, x.samples.hours, 0, "Q = puissance dissipée (charge en essai : chaleur comptée, pas sa consommation)", `${n(x.samples.dissipatedW, 0)} W, ${n(x.samples.hours, 1)} h/j`);
    }
    if (x.samples.kg > 0 && x.samples.deltaT !== 0) {
        const kj = x.samples.kg * x.samples.cp * x.samples.deltaT;
        const sizing = (kj * 1000) / (DEFAULTS.samples.pullDownHours * 3600);
        gains.push({ key: "samplesLoad", label: "Échantillons : mise à température", sizingW: sizing, avgW: (kj * 1000) / 86400, hours: DEFAULTS.samples.pullDownHours, equipmentKwhDay: 0,
            formula: "Q = masse × cp × écart / 12 h (une introduction par jour)", detail: `${n(x.samples.kg, 0)} kg × ${n(x.samples.cp, 2)} kJ/(kg·K) × ${n(x.samples.deltaT, 1)} K`, waterKgH: 0, waterAvgKgH: 0 });
    }
    const sampleWater = or(x.samples.waterGPerH, 0) / 1000;
    if (sampleWater !== 0) {
        gains.push({ key: "samplesWater", label: "Échantillons : humidité dégagée (+) ou absorbée (−)", sizingW: 0, avgW: 0, hours: 24, equipmentKwhDay: 0, formula: "eau ajoutée au bilan d'eau", detail: `${n(sampleWater * 1000, 0)} g/h`, waterKgH: sampleWater, waterAvgKgH: sampleWater });
    }
    x.manual.forEach((m, i) => {
        const water = or(m.waterGPerH, 0) / 1000;
        add(`manual${i + 1}`, m.label || `Apport ${i + 1}`, m.watts * m.fraction, m.hours, (m.watts * m.hours) / 1000, "Q = P × fraction restant dans l'enceinte", `${n(m.watts, 0)} W × ${n(m.fraction, 2)}, ${n(m.hours, 1)} h/j${water ? `, ${n(water * 1000, 0)} g/h d'eau` : ""}`, water, (water * m.hours) / 24);
    });
    const heaterElectric = x.doors.reduce((sum, d) => sum + d.heaterWPerM * heaterLength(d), 0);
    if (heaterElectric > 0) {
        const into = x.doors.reduce((sum, d) => sum + d.heaterWPerM * heaterLength(d) * or(d.heaterFraction, DEFAULTS.door.heaterFraction), 0);
        add("doorHeaters", "Cordons chauffants de cadre", into, 24, (heaterElectric * 24) / 1000, "Q = W/m × périmètre × fraction réintroduite, 24 h/24", x.doors.filter((d) => d.heaterWPerM > 0).map((d) => `${n(d.heaterWPerM, 0)} W/m × ${n(heaterLength(d), 2)} m × ${n(or(d.heaterFraction, DEFAULTS.door.heaterFraction), 2)}`).join(" + "));
    }
    const heated = x.glazings.filter((g) => g.heated && g.heaterW > 0);
    if (heated.length > 0) {
        add("glazingHeaters", "Vitrages chauffés", heated.reduce((sum, g) => sum + g.heaterW * or(g.heaterFraction, 0.7), 0), 24, (heated.reduce((sum, g) => sum + g.heaterW, 0) * 24) / 1000, "Q = P chauffage × fraction réintroduite, 24 h/24", heated.map((g) => `${n(g.heaterW, 0)} W × ${n(or(g.heaterFraction, 0.7), 2)}`).join(" + "));
    }

    return gains;
}

/**
 * Échanges avec l'extérieur pour une saison : postes sensibles (W, + = apport) et eau entrante (kg/h).
 * Parois, vitrages et portes : U × S × (T_local − T) ; fuites, infiltration des portes et air neuf :
 * ṁ × (1,006 + 1,86 × w_ext) × (T_ext − T), eau ṁ × (w_ext − w_consigne).
 */
function exchanges(x, geo, u, inside, local, fresh) {
    const t = x.tSet;
    const lines = [line("walls", "Parois, plafond et sol", u * geo.surface * (local.t - t), "Q = U × S × ΔT (6 faces dans un local)", `${n(u, 4)} × ${n(geo.surface, 2)} m² × ${n(local.t - t, 1)} K`)];
    if (x.glazings.length > 0) {
        const watts = x.glazings.reduce((sum, g) => sum + g.u * g.area * (local.t - t), 0);
        lines.push(line("glazing", "Vitrages (transmission)", watts, "Q = U × S × ΔT", x.glazings.map((g, i) => `vitrage ${i + 1} : ${n(g.u, 2)} × ${n(g.area, 2)} m² × ${n(local.t - t, 1)} K`).join(" ; ")));
    }
    if (x.doors.length > 0) {
        lines.push(line("doorTransmission", "Portes (transmission)", x.doors.reduce((sum, d) => sum + doorUa(d), 0) * (local.t - t), "Q = (U porte × S + U cadre × périmètre × 0,06 m) × ΔT",
            x.doors.map((d, i) => `porte ${i + 1} : (${n(d.u, 2)} × ${n(d.width * d.height, 2)} m² + ${n(DOOR_FRAME.u, 1)} × ${n(2 * (d.width + d.height), 2)} m × 0,06) × ${n(local.t - t, 1)} K`).join(" ; ")));
    }
    const sensible = (m, ext) => m * (1.006 + 1.86 * ext.w) * (ext.t - t) * 1000;
    const flows = [
        ["leaks", "Fuites", (x.leakPct / 100) * geo.volume * inside.rho / 3600, local, `${n(x.leakPct, 2)} %/h × ${n(geo.volume, 2)} m³`, "Q = ṁ × (1,006 + 1,86 × w_local) × ΔT"],
        ...(x.doors.length > 0 ? [["infiltration", "Infiltrations par les portes", x.doors.reduce((sum, d) => sum + doorAirFlow(d, local, inside), 0), local,
            x.doors.map((d, i) => `porte ${i + 1} : ${n(d.openingsPerDay, 0)} × ${n(d.openSeconds, 0)} s par jour, D_f ${n(INFILTRATION.flowFactor, 1)}, (1 − ${n(d.efficiency, 2)})`).join(" ; "),
            "ASHRAE : débit d'air = 0,221 × A × ρ_dense × (1 − ρ_léger/ρ_dense)^0,5 × (g·H)^0,5 × F_m × D_t × D_f × (1 − E)"]] : []),
        ...(x.airRenewal > 0 ? [["freshAir", "Renouvellement d'air", (x.airRenewal * inside.rho) / 3600, fresh, `${n(x.airRenewal, 0)} m³/h à ${n(fresh.t, 1)} °C / ${n(fresh.rh, 0)} %`, "Q = ṁ × (1,006 + 1,86 × w_ext) × ΔT"]] : []),
    ];
    let water = 0;
    for (const [key, label, m, ext, detail, formula] of flows) {
        const w = m * (ext.w - inside.w) * 3600;
        water += w;
        lines.push(line(key, label, sensible(m, ext), formula, `${detail} : ${n(m * 3600, 2)} kg/h d'air sec, eau ${n(w * 1000, 1)} g/h`, { waterKgH: w }));
    }

    return { lines, envelopeW: lines.reduce((sum, l) => sum + l.watts, 0), waterKgH: water };
}

/**
 * Point fixe du bilan : humidification → chaleur sensible de l'humidificateur → froid sensible →
 * condensation sur la batterie froide → humidification. Bilan d'eau net = eau entrante − condensation :
 * négatif → humidification, positif → séchage.
 *
 * @returns {{humid: number, cond: number, hs: number, coldSensible: number, dry: number, desiccantW: number}} débits en kg/h, puissances en W
 */
function waterBalance(x, ctx, envelopeW, waterKgH, internalW) {
    const { inside, regulated, tSurface, td, humidifier, waterT } = ctx;
    const t = x.tSet;
    const desiccant = x.drying.type === "desiccant";
    let humid = 0;
    let cond = 0;
    let hs = 0;
    let coldSensible = 0;
    let desiccantW = 0;
    for (let i = 0; i < 500; i++) {
        // Vapeur à 100 °C : apporte (2 676 − h_vapeur à T) ; adiabatique : retire (2 501 + 1,86 T − 4,186 T_eau)
        hs = humidifier.steam ? ((humid / 3600) * (STEAM_ENTHALPY - (LATENT_HEAT + 1.86 * t)) * 1000) : -((humid / 3600) * (LATENT_HEAT + 1.86 * t - WATER_CP * waterT) * 1000);
        // Dessiccateur : l'air séché sort plus chaud (chaleur d'adsorption ≈ chaleur latente), à refroidir
        const dryNow = regulated ? Math.max(0, waterKgH - cond) : 0;
        desiccantW = desiccant ? (dryNow / 3600) * LATENT_HEAT * 1000 : 0;
        coldSensible = Math.max(0, envelopeW + internalW + hs + desiccantW);
        let nextCond = 0;
        if (regulated && coldSensible > 0 && tSurface < td) {
            // Point de contact : l'air qui touche la batterie en sort à la température de surface, saturé, et porte tout le froid sensible
            const contact = coldSensible / 1000 / (1.006 * (t - tSurface));
            nextCond = contact * (inside.w - wSat(tSurface)) * 3600;
        }
        const nextHumid = regulated ? Math.max(0, -(waterKgH - nextCond)) : 0;
        const done = Math.abs(nextHumid - humid) < 1e-13 && Math.abs(nextCond - cond) < 1e-13;
        humid = nextHumid;
        cond = nextCond;
        if (done) {
            break;
        }
    }

    return { humid, cond, hs, coldSensible, dry: regulated ? Math.max(0, waterKgH - cond) : 0, desiccantW };
}

/** Puissance du générateur d'humidification (W) pour un débit (kg/h) : vapeur = débit × [4,186 × (100 − T_eau) + 2 257] / rendement ; adiabatique : 0 (pompe négligée). */
function humidifierPower(ctx, humidKgH) {
    if (!ctx.humidifier.steam) {
        return 0;
    }

    return ((humidKgH / 3600) * (WATER_CP * (100 - ctx.waterT) + VAPORISATION_100) * 1000) / ctx.humidEfficiency;
}

/**
 * Séchage d'un débit d'eau (kg/h) : batterie à point de rosée − 2 K, air saturé ; latent = eau × 2 501 kJ/kg,
 * sensible = débit d'air traversant × 1,006 × (T − T_batterie), réchauffé ensuite ; dessiccateur : 1 kWh/kg.
 */
function drying(x, ctx, dryKgH) {
    if (dryKgH <= 0) {
        return { latentW: 0, sensibleW: 0, reheatW: 0, coilT: null, airKgH: 0, powerW: 0, electricW: 0 };
    }
    if (x.drying.type === "desiccant") {
        const powerW = dryKgH * DESICCANT_KWH_PER_KG * 1000;

        return { latentW: 0, sensibleW: 0, reheatW: 0, coilT: null, airKgH: 0, powerW, electricW: powerW };
    }
    const coilT = ctx.td - DRYING_COIL.belowDewPoint;
    const airKgH = dryKgH / (ctx.inside.w - wSat(coilT));
    const latentW = (dryKgH / 3600) * LATENT_HEAT * 1000;
    const sensibleW = (airKgH / 3600) * 1.006 * (x.tSet - coilT) * 1000;
    const cop = x.drying.type === "other" ? x.drying.cop : carnotCop(coilT - DRYING_COIL.evaporatorApproach, x.cooling.condenserAir);

    return { latentW, sensibleW, reheatW: sensibleW, coilT, airKgH, powerW: latentW + sensibleW, electricW: (latentW + sensibleW) / cop + sensibleW, cop };
}

/**
 * Bilan d'une saison : postes, bilan d'eau, puissances de dimensionnement (× marge) et consommation.
 */
function season(x, ctx, key, local, fresh) {
    const m = 1 + x.margin / 100;
    const ex = exchanges(x, ctx.geo, ctx.u, ctx.inside, local, fresh);
    const internalW = ctx.gains.reduce((sum, g) => sum + g.sizingW, 0);
    const internalAvgW = ctx.gains.reduce((sum, g) => sum + g.avgW, 0);
    const water = ex.waterKgH + ctx.gains.reduce((sum, g) => sum + g.waterKgH, 0);
    const waterAvg = ex.waterKgH + ctx.gains.reduce((sum, g) => sum + g.waterAvgKgH, 0);

    // Dimensionnement : tous les apports présents en même temps
    const b = waterBalance(x, ctx, ex.envelopeW, water, internalW);
    const condensationLatentW = (b.cond / 3600) * LATENT_HEAT * 1000;
    const humidPower = humidifierPower(ctx, b.humid);
    const dry = drying(x, ctx, b.dry);
    const run = 24 / x.runHours;
    const coldW = (b.coldSensible + condensationLatentW) * run * m;
    // Chauffe : pertes, apports internes non déduits (C9) sauf demande ; l'humidificateur adiabatique refroidit
    const heatW = Math.max(0, -(ex.envelopeW + Math.min(0, b.hs) + (x.internalGainsInHeating ? internalW : 0))) * m;

    // Consommation : apports selon leur durée déclarée
    const c = waterBalance(x, ctx, ex.envelopeW, waterAvg, internalAvgW);
    const net = ex.envelopeW + internalAvgW + c.hs + c.desiccantW;
    const condLatentAvg = (c.cond / 3600) * LATENT_HEAT * 1000;
    const coldThermalW = Math.max(0, net) + condLatentAvg;
    const heatThermalW = Math.max(0, -net);
    const humidAvgW = humidifierPower(ctx, c.humid);
    const dryAvg = drying(x, ctx, c.dry);
    const equipmentKwhDay = ctx.gains.reduce((sum, g) => sum + g.equipmentKwhDay, 0);
    const heatingElectric = ctx.heating.electric ? heatThermalW / ctx.heatEfficiency : 0;
    const humidElectric = ctx.humidifier.electric ? humidAvgW : 0;
    const othersKwhDay = ((humidElectric + dryAvg.electricW) * 24) / 1000 + equipmentKwhDay;
    const consumptionKwhDay = ((coldThermalW / ctx.cop + heatingElectric) * 24) / 1000 + othersKwhDay;
    // Énergie hors électricité : chauffe à eau chaude, vapeur ou autre ; humidification par vapeur réseau
    const thermalKwhDay = ((ctx.heating.electric ? 0 : heatThermalW / ctx.heatEfficiency) + (ctx.humidifier.steam && !ctx.humidifier.electric ? humidAvgW : 0)) * 24 / 1000;

    const lines = [
        ...ex.lines,
        ...ctx.gains.filter((g) => g.sizingW !== 0).map((g) => line(g.key, g.label, g.sizingW, g.formula, g.detail, { avgW: g.avgW })),
        ...(b.hs !== 0 ? [line("humidifierSensible", ctx.humidifier.steam ? "Humidificateur : chaleur sensible de la vapeur" : "Humidificateur adiabatique : refroidissement", b.hs,
            ctx.humidifier.steam ? "Q = débit × [2 676 − (2 501 + 1,86 × T)] kJ/kg" : "Q = −débit × (2 501 + 1,86 × T − 4,186 × T_eau) kJ/kg", `${n(b.humid * 1000, 1)} g/h`)] : []),
        ...(b.desiccantW > 0 ? [line("desiccantHeat", "Dessiccateur : air séché réchauffé", b.desiccantW, "Q = eau retirée × 2 501 kJ/kg (chaleur d'adsorption, à vérifier)", `${n(b.dry * 1000, 1)} g/h`)] : []),
        ...(condensationLatentW > 0 ? [line("condensation", "Condensation sur la batterie froide (latent)", condensationLatentW, "eau = débit d'air au contact × (w_consigne − w_saturé à la surface) ; Q = eau × 2 501 kJ/kg",
            `surface ${n(ctx.tSurface, 1)} °C sous le point de rosée ${n(ctx.td, 2)} °C : ${n(b.cond * 1000, 1)} g/h, repris par l'humidificateur`)] : []),
    ];

    return {
        key, local, fresh, lines,
        envelopeW: ex.envelopeW, internalW, internalAvgW, waterKgH: water,
        condensationKgH: b.cond, humidificationKgH: b.humid, dryingKgH: b.dry, humidifierSensibleW: b.hs, coldSensibleW: b.coldSensible, condensationLatentW,
        coldW, heatW, humidificationW: humidPower * m, dryingW: dry.powerW * m, reheatW: dry.reheatW * m, dryingCoilT: dry.coilT, dryingAirKgH: dry.airKgH,
        cop: ctx.cop, consumptionKwhDay, thermalKwhDay, waterLDay: c.humid * 24, condensateLDay: (c.dry + c.cond) * 24,
        average: { netW: net, condensationLatentW: condLatentAvg, coldThermalW, heatThermalW, humidificationW: humidAvgW, othersKwhDay, equipmentKwhDay },
    };
}

/**
 * Mise en température d'une saison, depuis le départ (par défaut : le local) : énergie = Σ(m × cp) × ΔT ;
 * pertes ou apports moyens à la température moyenne de la rampe (UA du local, air neuf) ; chauffe :
 * apports internes non comptés ; refroidissement : tous les apports présents. Durée souhaitée (60 min par
 * défaut) : P = (énergie / durée + pertes) × (1 + marge) ; puissance installée saisie : durée = énergie /
 * (P − pertes), ou null si P ne couvre pas les pertes.
 */
function rampTemperature(x, ctx, local, fresh) {
    const t = x.tSet;
    const ms = x.masses;
    const parts = [
        ["air", ctx.inside.rho * ctx.geo.volume * 1.006],
        ["peau intérieure", ctx.geo.surface * ms.skinKgPerM2 * ms.skinCp],
        ["étagères", ms.shelvesKg * ms.shelvesCp],
        ["structure, batteries", ms.structureKg * ms.structureCp],
        ...(x.samples.kg > 0 ? [["échantillons", x.samples.kg * x.samples.cp]] : []),
    ];
    const capacityKjK = parts.reduce((sum, [, v]) => sum + v, 0);
    const startT = or(x.tStart, local.t);
    const mean = (startT + t) / 2;
    const uaLocal = ctx.u * ctx.geo.surface + x.doors.reduce((sum, d) => sum + doorUa(d), 0) + x.glazings.reduce((sum, g) => sum + g.u * g.area, 0) + ((x.leakPct / 100) * ctx.geo.volume * ctx.inside.rho / 3600) * 1006;
    const uaFresh = ((x.airRenewal * ctx.inside.rho) / 3600) * 1006;
    const energyKj = capacityKjK * Math.abs(t - startT);
    const out = { startT, capacityKjK, energyKj, parts, uaLocal, uaFresh, direction: null };
    if (t === startT) {
        return out;
    }
    const heating = t > startT;
    const flow = heating
        ? uaLocal * (mean - local.t) + uaFresh * (mean - fresh.t)
        : uaLocal * (local.t - mean) + uaFresh * (fresh.t - mean) + ctx.gains.reduce((sum, g) => sum + g.sizingW, 0);
    const installed = heating ? x.heating.installedW : x.cooling.installedW;
    const base = { ...out, direction: heating ? "heat" : "cool", [heating ? "meanLossW" : "meanGainW"]: flow };
    if (installed > 0) {
        return { ...base, powerW: installed, minutes: installed > flow ? (energyKj * 1000) / (installed - flow) / 60 : null };
    }

    return { ...base, requiredW: ((energyKj * 1000) / (x.rampMinutes * 60) + flow) * (1 + x.margin / 100) };
}

/**
 * Mise en régime d'humidité : eau = ρ × V × (w_consigne − w_départ) sur la durée souhaitée (30 min par
 * défaut) ; eau à apporter : humidificateur ; eau à retirer : séchage. Puissances × (1 + marge).
 */
function rampHumidity(x, ctx, local) {
    const start = air(or(x.tStart, local.t), or(x.rhStart, local.rh));
    const waterKg = ctx.inside.rho * ctx.geo.volume * (ctx.inside.w - start.w);
    const rateKgH = (waterKg * 60) / x.humidMinutes;
    const m = 1 + x.margin / 100;

    return { startT: start.t, startRh: start.rh, waterKg, rateKgH, powerW: humidifierPower(ctx, Math.max(0, rateKgH)) * m, dryingW: drying(x, ctx, Math.max(0, -rateKgH)).powerW * m };
}

/**
 * Réglage SIMC (Skogestad) d'un procédé premier ordre + temps mort, en PI : Kc = τ / [K_u × (τc + θ)]
 * (% de sortie par unité mesurée), Ti = min(τ, 4 × (τc + θ)) ; quand τ ≫ θ (enceintes), Ti = 4 × (τc + θ),
 * réglage « procédé quasi intégrateur ». Option PID : Td = θ / 2 (à vérifier). Bande proportionnelle Xp = 100 / Kc.
 */
export function simcTuning(kp, tau, theta, tauC, derivative = false) {
    const kc = tau / (kp * (tauC + theta));

    return { kp, kc, xp: 100 / kc, ti: Math.min(tau, 4 * (tauC + theta)), td: derivative ? theta / 2 : 0 };
}

/**
 * Régulation PID : modèle de l'enceinte et réglages de départ de chaque fonction.
 * - Température : premier ordre + temps mort ; UA = parois, portes, vitrages, fuites, air neuf (W/K) ;
 *   K_u = P installée / (100 × UA) (K par % de sortie) ; τ = C / UA ; θ = volume / débit de brassage + sonde.
 * - Humidité : capacité en eau M = ρ × V (+ matériaux hygroscopiques ramenés en air équivalent) ; échanges
 *   ṁ = fuites + infiltration (été) + air neuf (kg/s) ; τ = M / ṁ ; K_u = débit maximal / (100 × ṁ) × HR / w.
 * - Plage de modulation : puissance minimale à fournir (chauffe : besoin le plus faible des deux saisons ;
 *   froid : charge la plus faible, apports intermittents absents ; humidité : besoin le plus faible) comparée
 *   à la puissance installée ; avertissement sous la plage de l'actionneur, et au-delà de 3 fois le maintien.
 *
 * @returns {{temperature: object, humidity: object|null, loops: Object<string, object>, warnings: Array<{code: string, loop: string, text: string}>, settings: object}}
 */
function pidRegulation(x, ctx, seasons, rampT, rampRh, installed) {
    const p = { ...PID_DEFAULTS, ...(x.pid ?? {}), actuators: { ...PID_DEFAULTS.actuators, ...(x.pid?.actuators ?? {}) } };
    const airChanges = or(x.pid?.airChanges, PID_DEFAULTS.airChanges[x.kind] ?? PID_DEFAULTS.airChanges.modular);
    const mix = 3600 / airChanges;
    const ramp = rampT.summer;
    const ua = ramp.uaLocal + ramp.uaFresh;
    const capacity = ramp.capacityKjK * 1000;
    const temperature = { uaWK: ua, capacityJK: capacity, tauS: capacity / ua, thetaS: mix + p.sensorT, mixS: mix, airChanges };
    const tauC = (theta) => p.tauFactor * theta;
    const loops = {};
    const warnings = [];
    const stable = STABILITY_T[x.stabilityT] ?? STABILITY_T[2];
    const minPct = (loop) => (loop === "cold" && p.actuators.cold === "vsd" ? p.coldMinPct : ACTUATORS[loop][p.actuators[loop]]?.minPct ?? null);
    const check = (loop, label, ratio, extra) => {
        const min = minPct(loop);
        const actuator = ACTUATORS[loop][p.actuators[loop]];
        const belowRange = min !== null && ratio > 0 && ratio < min;
        if (belowRange) {
            warnings.push({ code: "range", loop, text: `${label} : la puissance minimale à fournir (${n(ratio, 1)} % de la puissance installée) est sous la plage de l'actionneur (${actuator.label.toLowerCase()}, ${n(min, 0)} % au minimum). Solution : ${RANGE_SOLUTIONS[loop]}.` });
        }
        if (min === null && (loop === "heat" || loop === "cold") && stable.modulate) {
            warnings.push({ code: "onOff", loop, text: `${label} : un actionneur tout ou rien ne tient pas une stabilité de ${stable.label} ; choisissez un actionneur modulant.` });
        }

        return { ...extra, minRatioPct: ratio, actuatorMinPct: min, belowRange };
    };

    const least = Math.min(seasons.summer.envelopeW, seasons.winter.envelopeW);
    const permanent = ctx.gains.filter((g) => g.hours >= 24).reduce((sum, g) => sum + g.sizingW, 0);
    const minimum = {
        heat: Math.min(...[seasons.summer.envelopeW, seasons.winter.envelopeW].filter((e) => e < 0).map((e) => -e), Infinity),
        cold: Math.max(0, least + permanent),
    };
    for (const [loop, key, label] of [["heat", "heatW", "Chauffe"], ["cold", "coldW", "Froid"]]) {
        const power = installed[key].installedW;
        if (power <= 0) {
            continue;
        }
        const tuning = simcTuning(power / (100 * ua), temperature.tauS, temperature.thetaS, tauC(temperature.thetaS), p.derivative);
        const maintenance = installed[key].maintenanceW;
        const minimumW = Number.isFinite(minimum[loop]) ? minimum[loop] : 0;
        const ratio = maintenance > 0 ? power / maintenance : null;
        const staging = ratio !== null && ratio > p.stagingRatio;
        if (staging) {
            warnings.push({ code: "staging", loop, text: `${label} : la puissance installée (${n(power, 0)} W) vaut ${n(ratio, 1)} fois le maintien (${n(maintenance, 0)} W) : découpage en étages conseillé (étage de base modulé proche du maintien, plus un étage de mise en régime).` });
        }
        loops[loop] = check(loop, label, (minimumW / power) * 100, { ...tuning, xpPct: (tuning.xp / p.spanT) * 100, powerW: power, minimumW, installedToMaintenance: ratio, staging });
    }

    let humidity = null;
    if (ctx.regulated) {
        const local = seasons.summer.local;
        const exchange = (((x.leakPct / 100) * ctx.geo.volume + x.airRenewal) * ctx.inside.rho) / 3600 + x.doors.reduce((sum, d) => sum + doorAirFlow(d, local, ctx.inside), 0);
        const dRhDw = x.rhSet / ctx.inside.w;
        // Matériaux hygroscopiques : eau absorbée par % HR, ramenée en masse d'air sec équivalente
        const mass = ctx.inside.rho * ctx.geo.volume + (p.hygroMassKg * p.hygroCapacity) / 1000 / (1 / dRhDw);
        humidity = { exchangeKgS: exchange, massKg: mass, tauS: mass / exchange, thetaS: mix + p.sensorRh };
        const ramps = rampRh ? Object.values(rampRh) : [];
        for (const [loop, field, rampSign, label] of [["humid", "humidificationKgH", 1, "Humidification"], ["dry", "dryingKgH", -1, "Séchage"]]) {
            const needs = [seasons.summer[field], seasons.winter[field]];
            const rate = Math.max(...needs, ...ramps.map((r) => Math.max(0, rampSign * r.rateKgH)));
            if (rate <= 0) {
                continue;
            }
            const tuning = simcTuning((rate / 3600 / 100 / exchange) * dRhDw, humidity.tauS, humidity.thetaS, tauC(humidity.thetaS), p.derivative);
            loops[loop] = check(loop, label, (Math.min(...needs) / rate) * 100, { ...tuning, maxKgH: rate });
        }
    }

    return { temperature, humidity, loops, warnings, settings: { ...p, airChanges } };
}

/**
 * Bilan complet : deux saisons, mises en régime, régulation, fluide.
 *
 * @param {object} x saisies complètes (mode pro, ou simpleToFull())
 * @returns {object} geo, u, td, seasons {summer, winter}, rampTemperature, rampHumidity, sizing, regulation, refrigerant, warnings, advice
 */
export function climaticChamber(x) {
    const geo = geometry(x.length, x.width, x.height);
    const u = panelU(x.panel);
    const regulated = humidityRegulated(x);
    const inside = air(x.tSet, regulated ? x.rhSet : 50);
    const td = regulated ? dewPoint(x.tSet, x.rhSet) : null;
    const coolingType = x.cooling.type;
    const tEvap = coolingType === "chilled_water" ? x.cooling.supplyT - DEFAULTS.chilledWater.evaporatorApproach : x.tSet - COP_MODEL.evaporatorApproach;
    const tSurface = (coolingType === "chilled_water" ? x.cooling.supplyT : tEvap) + x.cooling.surfaceOffset;
    const cop = x.cooling.cop > 0 ? x.cooling.cop : carnotCop(tEvap, x.cooling.condenserAir);
    const heating = HEATING_TYPES[x.heating.type] ?? HEATING_TYPES.electric;
    const humidifier = HUMIDIFIER_TYPES[x.humidification.type] ?? HUMIDIFIER_TYPES.steam_electric;
    const ctx = {
        geo, u, inside, regulated, td, tEvap, tSurface, cop, heating, humidifier,
        heatEfficiency: x.heating.type === "other" ? x.heating.efficiency : 1,
        humidEfficiency: x.humidification.type === "other" ? x.humidification.efficiency : 1,
        waterT: x.humidification.waterT, gains: internalGains(x, geo),
    };
    const local = (s) => air(s.localT, s.localRh);
    const fresh = (s) => air(s.freshT, s.freshRh);
    const seasons = { summer: season(x, ctx, "summer", local(x.summer), fresh(x.summer)), winter: season(x, ctx, "winter", local(x.winter), fresh(x.winter)) };
    const rampT = { summer: rampTemperature(x, ctx, seasons.summer.local, seasons.summer.fresh), winter: rampTemperature(x, ctx, seasons.winter.local, seasons.winter.fresh) };
    const rampRh = regulated ? { summer: rampHumidity(x, ctx, seasons.summer.local), winter: rampHumidity(x, ctx, seasons.winter.local) } : null;

    // Puissances à installer = max(maintien été, maintien hiver, mise en régime), ou puissance installée saisie
    const both = (field) => Math.max(seasons.summer[field], seasons.winter[field]);
    const ramps = (direction) => Math.max(0, ...Object.values(rampT).filter((r) => r.direction === direction && r.requiredW !== undefined).map((r) => r.requiredW));
    const humidRamps = (field) => (rampRh ? Math.max(0, ...Object.values(rampRh).map((r) => r[field])) : 0);
    const entry = (maintenanceW, rampW, enteredW) => ({ maintenanceW, rampW, installedW: enteredW > 0 ? enteredW : Math.max(maintenanceW, rampW), entered: enteredW > 0 });
    const installed = {
        coldW: entry(both("coldW"), ramps("cool"), x.cooling.installedW),
        heatW: entry(both("heatW"), ramps("heat"), x.heating.installedW),
        humidificationW: entry(both("humidificationW"), humidRamps("powerW"), NaN),
        dryingW: entry(both("dryingW"), humidRamps("dryingW"), NaN),
    };

    // Régulation : « froid permanent + réchauffage » avec le froid à installer ; le compresseur tourne à pleine
    // puissance, le réchauffage compense l'excédent ; dimensionné sans apports internes
    const installedColdW = installed.coldW.installedW;
    const leastW = Math.min(seasons.summer.envelopeW, seasons.winter.envelopeW);
    const regulation = { mode: x.cooling.regulation, installedColdW, reheatW: installedColdW - leastW, modulatedKwhDay: {}, permanentKwhDay: {} };
    for (const [key, s] of Object.entries(seasons)) {
        const reheat = installedColdW - s.average.netW - s.average.condensationLatentW;
        regulation.modulatedKwhDay[key] = s.consumptionKwhDay;
        regulation.permanentKwhDay[key] = ((installedColdW / s.cop + reheat) * 24) / 1000 + s.average.othersKwhDay;
    }
    const permanent = x.cooling.regulation === "permanent_reheat";
    const consumptionKwhDay = permanent ? regulation.permanentKwhDay : regulation.modulatedKwhDay;
    if (permanent && !installed.heatW.entered) {
        installed.heatW.installedW = Math.max(installed.heatW.installedW, regulation.reheatW);
    }
    const sizing = Object.fromEntries(Object.entries(installed).map(([key, v]) => [key, v.installedW]));

    const pid = pidRegulation(x, ctx, seasons, rampT, rampRh, installed);
    // Alertes : type (« info » conseil, « warning » attention), code, section de la page qui porte le texte complet
    const alerts = [];
    const alert = (kind, code, section, text) => alerts.push({ kind, code, section, text });
    if (Object.values(seasons).some((s) => s.condensationKgH > 0)) {
        alert("info", "condensation", 5, `La surface de la batterie froide (${n(tSurface, 1)} °C) est sous le point de rosée de la consigne (${n(td, 2)} °C) : l'humidificateur reprend l'eau condensée et le froid compte sa chaleur latente. Conseil : évaporation à haute température, au-dessus du point de rosée, pour éviter que froid et humidification ne se compensent. Avec une surface à évaporation + 3 K au lieu de + 2 K, il n'y aurait pas de condensation dans le cas 40 °C / 75 % HR.`);
    }
    const dryingCoil = (DRYING_TYPES[x.drying.type] ?? DRYING_TYPES.dx_coil).coil;
    if (regulated && td < FROST_DEW_POINT && dryingCoil) {
        alert("warning", "frost", 7, `Point de rosée de consigne ${n(td, 1)} °C, sous +${FROST_DEW_POINT} °C : la batterie de séchage risque de givrer. Un dessiccateur (rotor) est proposé.`);
    }
    for (const [key, r] of Object.entries(rampT)) {
        if (r.direction !== null && r.requiredW === undefined && r.minutes === null) {
            alert("warning", r.direction === "heat" ? "rampHeat" : "rampCold", r.direction === "heat" ? 4 : 5, `Mise en ${r.direction === "heat" ? "température" : "froid"} (${key === "summer" ? "été" : "hiver"}) : la puissance ${r.direction === "heat" ? "de chauffe" : "frigorifique"} installée (${n(r.powerW, 0)} W) ne couvre pas les ${r.direction === "heat" ? "pertes" : "apports"} moyens (${n(r.direction === "heat" ? r.meanLossW : r.meanGainW, 0)} W) : consigne non atteinte.`);
        }
    }
    for (const [key, label, section] of [["heatW", "de chauffe", 4], ["coldW", "frigorifique", 5]]) {
        const v = installed[key];
        if (v.entered && v.installedW < v.maintenanceW) {
            alert("warning", key === "heatW" ? "underHeat" : "underCold", section, `La puissance ${label} installée (${n(v.installedW, 0)} W) est inférieure à la puissance de maintien (${n(v.maintenanceW, 0)} W).`);
        }
    }
    for (const w of pid.warnings) {
        alert("warning", w.code, 9, w.text);
    }
    const warnings = alerts.filter((a) => a.kind === "warning").map((a) => a.text);
    const advice = alerts.filter((a) => a.kind === "info").map((a) => a.text);

    // Fluide (détente directe) : charge estimée et obligations, pour la puissance frigorifique installée
    const r = x.cooling.refrigerant;
    const refrigerant = coolingType === "dx" && r?.fluid
        ? refrigerantCharge(r, installedColdW / 1000, r.installation === "split" ? or(r.lineLength, DEFAULTS.lineLength) : 0, geo.volume)
        : null;

    return {
        geo, u, td, tEvap, tSurface, cop, inside, regulated, kind: x.kind, tSet: x.tSet, rhSet: x.rhSet, margin: x.margin, rampMinutes: x.rampMinutes, humidMinutes: x.humidMinutes,
        stabilityT: STABILITY_T[x.stabilityT] ?? STABILITY_T[2], stabilityRh: regulated ? (STABILITY_RH[x.stabilityRh] ?? STABILITY_RH[5]) : null,
        seasons, rampTemperature: rampT, rampHumidity: rampRh, regulation, consumptionKwhDay, installed, sizing, refrigerant, warnings, advice, alerts, pid,
        heating: x.heating, cooling: x.cooling, humidification: x.humidification, drying: x.drying,
    };
}

/**
 * Bilan du mode simple.
 *
 * @param {object} s saisies du mode simple (voir simpleToFull())
 * @returns {object|null} résultat de climaticChamber(), ou null si la saisie est invalide ou incomplète
 */
export function chamberSimple(s) {
    return checkInputs(simpleFields(s)).ok ? climaticChamber(simpleToFull(s)) : null;
}

/**
 * Bilan du mode pro.
 *
 * @param {object} x saisies complètes
 * @returns {object|null} résultat de climaticChamber(), ou null si la saisie est invalide ou incomplète
 */
export function chamberPro(x) {
    return checkInputs(fullFields(x)).ok ? climaticChamber(x) : null;
}
