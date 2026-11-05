/*
 * Bilan frigorifique d'une chambre froide, version 2 (lot 5e, logique pure, sans DOM) : chambres
 * positive et négative, mode simple (hypothèses fixes et affichées) et mode pro (tout saisi).
 *
 * Postes calculés un par un, chacun avec sa formule, ses hypothèses, sa charge (W), son besoin
 * journalier (kWh/j) et sa part : parois et sol, vitrages, portes (transmission, cordons chauffants,
 * infiltrations ASHRAE avec l'humidité), renouvellement d'air, dispositif antigel, évacuation des
 * condensats, soupape, éclairage, personnes, ventilateurs, autres équipements, produits (avec la
 * congélation sur place, C12), respiration, emballages, dégivrage. Le fluide (base de l'admin) donne
 * le COP, la charge estimée, les contrôles F-Gas et les limites NF EN 378-1 (fgas.js).
 *
 * Valeurs par défaut : docs/CHAMBRE_FROIDE_V2.md (section 2), avec les valeurs fixées par
 * l'utilisateur le 2026-10-23 ; toutes indicatives, à vérifier (VERIFY).
 *
 * Testé par : chambre_froide.test.js (3 cas calculés à la main, non-régression des postes inchangés)
 * Utilisé par : assets/controllers/calculator/chambre_froide_controller.js
 */
import { checkInputs } from "./inputs.js";
import { estimatedCop, refrigerantCharge } from "./fgas.js";
import { latentDehumidPowerW, moistAir, sensibleAirPowerW } from "./psychrometrics.js";
import { formatNumber } from "./format.js";

/** Résistances superficielles intérieure et extérieure cumulées (m²·K/W) : U = 1 / (0,17 + e/λ). */
export const SURFACE_RESISTANCE = 0.17;

/** Isolants des panneaux génériques : conductivité λ (W/(m·K)). Panneaux des marques : admin (PanelModel). */
export const INSULATIONS = {
    PIR: { label: "Polyisocyanurate (PIR)", lambda: 0.022 },
    PUR: { label: "Polyuréthane (PUR)", lambda: 0.023 },
    XPS: { label: "Polystyrène extrudé (XPS)", lambda: 0.034 },
    EPS: { label: "Polystyrène expansé (EPS)", lambda: 0.036 },
    rockwool: { label: "Laine de roche", lambda: 0.04 },
};

/** Épaisseurs proposées pour les panneaux génériques (mm). */
export const THICKNESSES = [60, 80, 100, 120, 150, 180, 200];

/** Environnement des parois et du plafond (°C). */
export const SURROUNDINGS = {
    heated: { label: "Local chauffé", t: 20 },
    unheated: { label: "Local non chauffé", t: 25 },
    outdoor: { label: "Extérieur ensoleillé", t: 32 },
};

/** Dessous du sol (°C). */
export const FLOOR_GROUNDS = {
    slab: { label: "Terre-plein", t: 12 },
    room: { label: "Local", t: 20 },
    crawl: { label: "Vide sanitaire", t: 10 },
};

/** Sol non isolé : coefficient U (W/(m²·K)), repris de l'ancien site. */
export const BARE_FLOOR_U = 1.5;

/** Câble antigel sous dalle (chambre négative sur sol isolé) : W/m², fraction réintroduite, taux de fonctionnement (%). */
export const ANTI_FREEZE = { wPerM2: 15, fraction: 0.1, dutyPct: 30 };

/** Portes : coefficient U (W/(m²·K)), repris de l'ancien site. */
export const DOOR_TYPES = {
    swing: { label: "Battante isotherme", u: 0.55 },
    sliding: { label: "Coulissante", u: 0.5 },
    sectional: { label: "Sectionnelle", u: 0.45 },
    rapid: { label: "Rideau rapide", u: 2.5 },
    pivot: { label: "Pivot isotherme", u: 0.4 },
};

/** Cadre de porte : U (W/(m²·K)) sur une bande de 6 cm du périmètre, repris de l'ancien site. */
export const DOOR_FRAME = { u: 1.2, band: 0.06 };

/** Protections de porte : efficacité E contre l'infiltration (ASHRAE). */
export const DOOR_PROTECTIONS = {
    none: { label: "Aucune", efficiency: 0 },
    strips: { label: "Rideau à lanières", efficiency: 0.7, note: "0,85 à 0,95 si neuf et bien entretenu" },
    air_curtain: { label: "Rideau d'air", efficiency: 0.65 },
    airlock: { label: "Sas", efficiency: 0.9 },
};

/** Infiltration (ASHRAE, Gosney et Olama) : facteur d'écoulement D_f, pesanteur (m/s²). */
export const INFILTRATION = { flowFactor: 0.8, g: 9.81 };

/** Cordon chauffant d'encadrement et de seuil : W/m (négatif ; 0 en positif), fraction réintroduite. */
export const DOOR_HEATER = { wPerM: 40, fraction: 0.7 };

/** Vitrages : coefficient U (W/(m²·K)) ; vitrage chauffé : fraction réintroduite. */
export const GLAZING_TYPES = { double: { label: "Double vitrage", u: 2.8 }, triple: { label: "Triple vitrage", u: 1.6 } };
export const GLAZING_HEATER_FRACTION = 0.7;

/** Ventilateurs d'évaporateur : 2,5 W par m³ de chambre (mode simple), 24 h/24. */
export const FAN_W_PER_M3 = 2.5;

/** Personnes (ASHRAE) : chaleur dégagée = 270 − 6 × T (W par personne, T en °C). */
export function personHeat(tSet) {
    return 270 - 6 * tSet;
}

/** Câble d'évacuation des condensats : W/m, fraction réintroduite ; sous tension pendant le dégivrage et l'égouttage. */
export const DRAIN_CABLE = { wPerM: 40, fraction: 0.1 };

/** Soupape d'équilibrage chauffée : W, fraction réintroduite. */
export const RELIEF_VALVE = { watts: 20, fraction: 1 };

/** Emballages : capacité thermique (kJ/(kg·K)). */
export const PACKAGING = {
    none: { label: "Aucun", cp: 0 },
    cardboard: { label: "Carton", cp: 1.4 },
    wood: { label: "Bois (palettes)", cp: 1.7 },
    plastic: { label: "Plastique", cp: 1.9 },
};

/** Dégivrage : fraction de la chaleur réintroduite dans la chambre. */
export const DEFROST_TYPES = {
    electric: { label: "Électrique", fraction: 0.5 },
    hot_gas: { label: "Gaz chauds", fraction: 0.3 },
    air: { label: "Naturel (à l'arrêt du froid)", fraction: 0 },
};

/** Temps de fonctionnement suggéré (h/j). */
export const SUGGESTED_RUN_HOURS = { positive: 16, negative: 18 };

/** Marge par défaut (%), pour les deux modes. */
export const DEFAULT_MARGIN = 10;

/** Chaleur latente de fusion de l'eau (kJ/kg) : L produit = teneur en eau × 334 (C12) ; aussi la solidification du givre. */
export const WATER_FREEZING_HEAT = 334;

/** Chaleur latente de congélation d'un produit (kJ/kg), selon sa teneur en eau (%). */
export function freezingHeat(waterPct) {
    return (waterPct / 100) * WATER_FREEZING_HEAT;
}

/**
 * Profils produits — cp (kJ/(kg·K)), teneur en eau (%), température d'entrée (°C), durée de mise en
 * température (h), point de congélation (°C) et cp congelé : valeurs de l'ancien site et de C12.
 * Respiration (chambre positive) : W par tonne stockée selon la température (°C), interpolée ;
 * ordres de grandeur pour une moyenne de produits, à remplacer par la valeur du produit (tables ASHRAE).
 */
export const PRODUCT_PROFILES = {
    fresh_generic: { label: "Produits frais divers", group: "alimentaire", cp: 3.5, waterPct: 75, entryTemp: 10, pullDown: 12, freezePoint: -1.5, cpFrozen: 1.9, respiration: null, hint: "Denrées fraîches emballées." },
    meat: { label: "Viande / volaille", group: "alimentaire", cp: 3.2, waterPct: 70, entryTemp: 8, pullDown: 14, freezePoint: -1.7, cpFrozen: 1.8, respiration: null, hint: "Abattage, désossage, maturation." },
    fish: { label: "Poisson / produits mer", group: "alimentaire", cp: 3.7, waterPct: 78, entryTemp: 6, pullDown: 10, freezePoint: -2.2, cpFrozen: 1.9, respiration: null, hint: "Frais ou refroidi en eutectique." },
    dairy: { label: "Produits laitiers", group: "alimentaire", cp: 3.6, waterPct: 85, entryTemp: 6, pullDown: 12, freezePoint: -0.6, cpFrozen: 1.9, respiration: null, hint: "Lait, fromages, crèmes." },
    vegetables: { label: "Légumes / fruits", group: "alimentaire", cp: 4.0, waterPct: 90, entryTemp: 12, pullDown: 8, freezePoint: -0.8, cpFrozen: 2.0, respiration: { 0: 20, 5: 35, 10: 60, 15: 100 }, hint: "Respiration végétale : saisissez la valeur du produit (tables ASHRAE)." },
    bakery: { label: "Pain / pâtisserie", group: "alimentaire", cp: 2.5, waterPct: 35, entryTemp: 25, pullDown: 6, freezePoint: null, cpFrozen: null, respiration: null, hint: "Maintien qualité organoleptique." },
    beverages: { label: "Boissons / bouteilles", group: "alimentaire", cp: 3.8, waterPct: 95, entryTemp: 15, pullDown: 10, freezePoint: -1.0, cpFrozen: 2.0, respiration: null, hint: "Eau, jus, sodas." },
    frozen: { label: "Déjà surgelé (maintien)", group: "alimentaire", cp: 1.8, waterPct: 0, entryTemp: -18, pullDown: 24, freezePoint: null, cpFrozen: null, respiration: null, hint: "Pas de congélation in situ." },
    pharma_cold: { label: "Médicaments (+2 à +8 °C)", group: "pharma", cp: 2.0, waterPct: 30, entryTemp: 20, pullDown: 24, freezePoint: null, cpFrozen: null, respiration: null, hint: "GDP — chaîne du froid pharmaceutique." },
    vaccines: { label: "Vaccins / biologiques", group: "pharma", cp: 3.8, waterPct: 90, entryTemp: 5, pullDown: 6, freezePoint: null, cpFrozen: null, respiration: null, hint: "Tirage rapide, pas de congélation." },
    blood_plasma: { label: "Sang / plasma / échantillons", group: "pharma", cp: 3.7, waterPct: 85, entryTemp: 4, pullDown: 8, freezePoint: null, cpFrozen: null, respiration: null, hint: "Laboratoires, banques de sang." },
    pharma_ambient: { label: "Produits stables (+15 à +25 °C)", group: "pharma", cp: 1.5, waterPct: 10, entryTemp: 25, pullDown: 24, freezePoint: null, cpFrozen: null, respiration: null, hint: "Comprimés, dispositifs secs." },
    cosmetics: { label: "Cosmétiques / parfums", group: "pharma", cp: 2.2, waterPct: 40, entryTemp: 18, pullDown: 24, freezePoint: null, cpFrozen: null, respiration: null, hint: "Crèmes, laits, huiles." },
    chemicals: { label: "Produits chimiques", group: "industrie", cp: 1.8, waterPct: 0, entryTemp: 20, pullDown: 24, freezePoint: null, cpFrozen: null, respiration: null, hint: "Fûts, IBC, référentiel FDS." },
    solvents: { label: "Solvants / inflammables", group: "industrie", cp: 1.4, waterPct: 0, entryTemp: 18, pullDown: 24, freezePoint: null, cpFrozen: null, respiration: null, hint: "Atex — ventilation spécifique." },
    lab_reagents: { label: "Réactifs de laboratoire", group: "industrie", cp: 2.2, waterPct: 20, entryTemp: 18, pullDown: 12, freezePoint: null, cpFrozen: null, respiration: null, hint: "Flacons, cryotubes." },
    plastics: { label: "Plastiques / polymères", group: "inerte", cp: 1.5, waterPct: 0, entryTemp: 20, pullDown: 24, freezePoint: null, cpFrozen: null, respiration: null, hint: "Granulés, pièces moulées." },
    metals: { label: "Métaux / pièces usinées", group: "inerte", cp: 0.45, waterPct: 0, entryTemp: 20, pullDown: 24, freezePoint: null, cpFrozen: null, respiration: null, hint: "Faible cp — refroidissement rapide." },
    cardboard: { label: "Papier / carton", group: "inerte", cp: 1.7, waterPct: 8, entryTemp: 20, pullDown: 24, freezePoint: null, cpFrozen: null, respiration: null, hint: "Archives, emballages." },
    textile: { label: "Textile / cuir", group: "inerte", cp: 1.5, waterPct: 15, entryTemp: 20, pullDown: 24, freezePoint: null, cpFrozen: null, respiration: null, hint: "Stockage matières premières." },
    electronics: { label: "Composants électroniques", group: "inerte", cp: 0.9, waterPct: 0, entryTemp: 22, pullDown: 24, freezePoint: null, cpFrozen: null, respiration: null, hint: "Humidité contrôlée souvent requise." },
    flowers: { label: "Fleurs coupées", group: "autre", cp: 3.9, waterPct: 85, entryTemp: 12, pullDown: 8, freezePoint: null, cpFrozen: null, respiration: { 0: 40, 5: 70, 10: 120, 15: 180 }, hint: "Haute humidité, respiration : saisissez la valeur du produit." },
    ice: { label: "Glace / eau (congélation)", group: "autre", cp: 4.18, waterPct: 100, entryTemp: 10, pullDown: 24, freezePoint: 0, cpFrozen: 2.05, respiration: null, hint: "Congélation in situ — forte puissance." },
    wine: { label: "Vins / spiritueux", group: "autre", cp: 3.5, waterPct: 88, entryTemp: 15, pullDown: 24, freezePoint: null, cpFrozen: null, respiration: null, hint: "Cave positive 12–14 °C typique." },
    custom: { label: "Personnalisé (saisie manuelle)", group: "autre", cp: 2.0, waterPct: 0, entryTemp: 15, pullDown: 12, freezePoint: -1.5, cpFrozen: 1.8, respiration: null, hint: "Renseignez les propriétés thermiques." },
};

/** Régimes du mode simple : température de consigne (°C). */
export const REGIME_PRESETS = {
    positive: { tSet: 2, label: "Positif (+2 °C)" },
    positive0: { tSet: 0, label: "Positif (0 °C)" },
    negative: { tSet: -18, label: "Négatif (−18 °C)" },
    negative25: { tSet: -25, label: "Négatif (−25 °C)" },
};

/**
 * Hypothèses fixes du mode simple, affichées sur la page : parois à 25 °C, plafond à 32 °C, sol sur
 * terre-plein à 12 °C (câble antigel en négatif sur sol isolé) ; porte battante isotherme de 0,9 × 2 m
 * (atelier : 2 portes de 1 × 2 m), 15 ouvertures de 25 s par jour (atelier : 40), local devant la porte
 * à 25 °C et 50 % ; cordon chauffant, câble d'évacuation (2 m) et soupape chauffée en négatif ; vitrage
 * double non chauffé ; éclairage 8 W/m² pendant 12 h ; ventilateurs 2,5 W/m³ ; atelier : 1 personne
 * pendant 4 h ; dégivrage électrique en négatif (résistances de 1 kW par kW frigorifique, 4 de 20 min
 * par jour), naturel (à l'arrêt du froid) en positif ; produits déjà congelés à −15 °C en négatif ;
 * humidité de la chambre 85 % ; liaisons de 10 m (split) ; air au condenseur 35 °C.
 */
export const SIMPLE_ASSUMPTIONS = {
    tExtWall: 25, tExtCeiling: 32, ground: "slab",
    door: { width: 0.9, height: 2, prepWidth: 1, openingsPerDay: 15, prepOpeningsPerDay: 40, openSeconds: 25, localTemp: 25, localRh: 50 },
    lightingWPerM2: 8, lightingHours: 12, prepPeople: 1, prepPeopleHours: 4,
    drainLengthM: 2, dripMinutes: 10,
    defrost: { heaterKwPerKw: 1, cycles: 4, minutes: 20 },
    frozenEntryTemp: -15, rhSet: 85, lineLength: 10, condenserAir: 35,
};

/** Décimales d'affichage. */
export const DECIMALS = { kw: 2, kwh: 1, volume: 1, surface: 1, cop: 2, percent: 0, kg: 1 };

/** Champ vide : NaN, ou absent. */
const empty = (value) => value === undefined || value === null || Number.isNaN(value);

/** Valeur saisie, ou valeur par défaut si le champ est vide ; un 0 saisi reste 0 (C6). */
const or = (value, fallback) => (empty(value) ? fallback : value);

/** Nombre affiché à la française. */
const n = (value, digits = 2) => formatNumber(value, digits);

/** Surfaces et volume. */
export function geometry(length, width, height) {
    const floorArea = length * width;
    const wallArea = 2 * (length + width) * height;

    return { floorArea, ceilingArea: floorArea, wallArea, volume: floorArea * height, surface: wallArea + 2 * floorArea };
}

/**
 * Coefficient U d'un panneau (W/(m²·K)) :
 * - générique : U = 1 / (0,17 + e/λ), λ de l'isolant ;
 * - modèle d'une marque (admin) ou U saisi : U donné ;
 * - personnalisé : U = 1 / (0,17 + e/λ), λ et épaisseur saisis.
 *
 * @param {{source: string, insulation?: string, thickness?: number, lambda?: number, u?: number}} panel épaisseur en mm
 */
export function panelU(panel) {
    if (panel.source === "model" || panel.source === "custom_u") {
        return panel.u;
    }
    const lambda = panel.source === "custom_lambda" ? panel.lambda : (INSULATIONS[panel.insulation] ?? INSULATIONS.PIR).lambda;

    return 1 / (SURFACE_RESISTANCE + panel.thickness / 1000 / lambda);
}

/** Respiration (W/t) d'un profil à une température, interpolée entre les points du tableau (bornée). */
export function respirationRate(profile, tSet) {
    const table = PRODUCT_PROFILES[profile]?.respiration;
    if (!table) {
        return 0;
    }
    const points = Object.entries(table).map(([t, w]) => [Number(t), w]).sort((a, b) => a[0] - b[0]);
    if (tSet <= points[0][0]) {
        return points[0][1];
    }
    for (let i = 1; i < points.length; i++) {
        if (tSet <= points[i][0]) {
            const [[t0, w0], [t1, w1]] = [points[i - 1], points[i]];
            return w0 + ((w1 - w0) * (tSet - t0)) / (t1 - t0);
        }
    }

    return points[points.length - 1][1];
}

/** Indique si le produit est congelé sur place : entrée au-dessus et consigne au-dessous du point de congélation, produit contenant de l'eau. */
export function freezesInRoom(thermal, entryTemp, tSet, requested) {
    return requested && thermal.freezePoint !== null && entryTemp > thermal.freezePoint && tSet < thermal.freezePoint && thermal.waterPct > 0;
}

/**
 * Chaleur à retirer par kg de produit (kJ/kg), C12 :
 * - congélation sur place : cp frais × (T entrée − T congélation) + L + cp congelé × (T congélation − T consigne) ;
 * - produit déjà congelé à l'entrée : cp congelé × (T entrée − T consigne) ;
 * - sinon : cp frais × (T entrée − T consigne).
 */
export function productEnergyPerKg(thermal, entryTemp, tSet, freezing) {
    if (freezing) {
        return thermal.cp * (entryTemp - thermal.freezePoint) + freezingHeat(thermal.waterPct) + thermal.cpFrozen * (thermal.freezePoint - tSet);
    }
    const frozenAtEntry = thermal.freezePoint !== null && entryTemp <= thermal.freezePoint;

    return (frozenAtEntry ? thermal.cpFrozen : thermal.cp) * Math.max(0, entryTemp - tSet);
}

/**
 * Infiltration par une porte (ASHRAE Refrigeration Handbook, « Refrigerated-facility loads », Gosney et Olama) :
 * q = 0,221 × A × (h_ext − h_int) × ρ_int × (1 − ρ_ext/ρ_int)^0,5 × (g × H)^0,5 × F_m (kW, porte grande ouverte),
 * F_m = [2 / (1 + (ρ_int/ρ_ext)^(1/3))]^1,5 ; charge moyenne = q × D_t × D_f × (1 − E), D_t = ouvertures × durée / 86 400.
 * L'humidité de la chambre est rapportée à la glace sous 0 °C. Part latente : débit d'air sec × (w_ext − w_int) ;
 * en négatif, l'eau se dépose en givre et libère en plus sa chaleur de solidification (334 kJ/kg).
 *
 * @returns {{qOpenKw: number, dt: number, sensibleW: number, latentW: number, fusionW: number, totalW: number, waterKgDay: number}}
 */
export function doorInfiltration(door, tSet, rhSet) {
    const inside = moistAir(tSet, rhSet, tSet < 0);
    const outside = moistAir(door.localTemp, door.localRh);
    const dh = outside.h - inside.h;
    const zero = { qOpenKw: 0, dt: 0, sensibleW: 0, latentW: 0, fusionW: 0, totalW: 0, waterKgDay: 0 };
    if (dh <= 0 || inside.rho <= outside.rho) {
        return zero;
    }
    const area = door.width * door.height;
    const fm = (2 / (1 + (inside.rho / outside.rho) ** (1 / 3))) ** 1.5;
    const qOpenKw = 0.221 * area * dh * inside.rho * Math.sqrt(1 - outside.rho / inside.rho) * Math.sqrt(INFILTRATION.g * door.height) * fm;
    const dt = (door.openingsPerDay * door.openSeconds) / 86400;
    const averageKw = qOpenKw * dt * INFILTRATION.flowFactor * (1 - door.efficiency);
    // Débit moyen d'air sec (kg/s) : charge / Δh
    const dryAir = averageKw / dh;
    const dw = Math.max(0, outside.w - inside.w);
    const latentW = dryAir * dw * (2501 + 1.86 * door.localTemp) * 1000;
    const waterKgDay = dryAir * dw * 86400;
    const fusionW = tSet < 0 ? (waterKgDay * WATER_FREEZING_HEAT * 1000) / 86400 : 0;
    const totalW = averageKw * 1000 + fusionW;

    return { qOpenKw, dt, sensibleW: averageKw * 1000 - latentW, latentW, fusionW, totalW, waterKgDay };
}

/** Champ contrôlé seulement s'il est rempli. */
const optional = (value, label, rule) => (empty(value) ? [] : [{ value, label, rule }]);

/** Contrôles du mode simple. */
function simpleFields(s) {
    return [
        { value: s.length, label: "Longueur", rule: "positive" },
        { value: s.width, label: "Largeur", rule: "positive" },
        { value: s.height, label: "Hauteur", rule: "positive" },
        ...panelFields(s.panel),
        ...(s.glazing === "yes" ? [{ value: s.glazingArea, label: "Surface vitrée", rule: "nonNegative" }] : []),
        ...optional(s.airRenewal, "Renouvellement d'air", "nonNegative"),
        ...optional(s.airRhExt, "Humidité de l'air entrant", "relativeHumidity"),
        ...(s.loadMode === "loaded" ? productFields(s.product, "Produit", false) : []),
        { value: s.runHours, label: "Durée de fonctionnement", rule: "hoursPerDay" },
        { value: s.margin, label: "Marge de sécurité", rule: "nonNegative" },
    ];
}

/** Contrôles du panneau. */
function panelFields(panel) {
    if (panel.source === "custom_lambda") {
        return [{ value: panel.lambda, label: "Conductivité λ du panneau", rule: "positive" }, { value: panel.thickness, label: "Épaisseur du panneau", rule: "positive" }];
    }
    if (panel.source === "custom_u" || panel.source === "model") {
        return [{ value: panel.u, label: "Coefficient U du panneau", rule: "positive" }];
    }

    return [{ value: panel.thickness, label: "Épaisseur du panneau", rule: "positive" }];
}

/** Contrôles d'un produit. */
function productFields(p, label, pro) {
    return [
        { value: p.dailyKg, label: `${label} : entrées`, rule: "nonNegative" },
        { value: p.cp, label: `${label} : capacité thermique`, rule: "positive" },
        { value: p.waterPct, label: `${label} : teneur en eau`, rule: "percentOrZero" },
        { value: p.entryTemp, label: `${label} : température d'entrée`, rule: "celsius" },
        { value: p.pullDown, label: `${label} : durée de refroidissement`, rule: "positive" },
        ...(pro ? [
            { value: p.storedKg, label: `${label} : stock`, rule: "nonNegative" },
            ...optional(p.freezePoint, `${label} : point de congélation`, "celsius"),
            ...optional(p.cpFrozen, `${label} : capacité thermique congelé`, "positive"),
            ...optional(p.respiration, `${label} : respiration`, "nonNegative"),
            ...(p.packaging !== "none" ? [{ value: p.packagingKg, label: `${label} : emballages`, rule: "nonNegative" }] : []),
        ] : []),
    ];
}

/** Contrôles du mode pro. */
function proFields(x) {
    const doors = x.doors.flatMap((d, i) => [
        { value: d.width, label: `Porte ${i + 1} : largeur`, rule: "positive" },
        { value: d.height, label: `Porte ${i + 1} : hauteur`, rule: "positive" },
        { value: d.u, label: `Porte ${i + 1} : coefficient U`, rule: "positive" },
        // Efficacité saisie en % (0 à 100) sur la page, fraction dans le calcul
        { value: d.efficiency * 100, label: `Porte ${i + 1} : efficacité de la protection`, rule: "percentOrZero" },
        { value: d.openingsPerDay, label: `Porte ${i + 1} : ouvertures par jour`, rule: "nonNegative" },
        { value: d.openSeconds, label: `Porte ${i + 1} : durée d'ouverture`, rule: "nonNegative" },
        { value: d.localTemp, label: `Porte ${i + 1} : température du local`, rule: "celsius" },
        { value: d.localRh, label: `Porte ${i + 1} : humidité du local`, rule: "relativeHumidity" },
        { value: d.heaterWPerM, label: `Porte ${i + 1} : cordon chauffant`, rule: "nonNegative" },
    ]);
    const glazings = x.glazings.flatMap((g, i) => [
        { value: g.area, label: `Vitrage ${i + 1} : surface`, rule: "positive" },
        { value: g.u, label: `Vitrage ${i + 1} : coefficient U`, rule: "positive" },
        ...(g.heated ? [{ value: g.heaterW, label: `Vitrage ${i + 1} : puissance de chauffage`, rule: "nonNegative" }] : []),
    ]);

    return [
        { value: x.length, label: "Longueur", rule: "positive" },
        { value: x.width, label: "Largeur", rule: "positive" },
        { value: x.height, label: "Hauteur", rule: "positive" },
        { value: x.tSet, label: "Température de consigne", rule: "celsius" },
        { value: x.rhSet, label: "Humidité de la chambre", rule: "relativeHumidity" },
        { value: x.tExtWall, label: "Température autour des parois", rule: "celsius" },
        { value: x.tExtCeiling, label: "Température au-dessus du plafond", rule: "celsius" },
        { value: x.tExtFloor, label: "Température sous le sol", rule: "celsius" },
        { value: x.bridgePct, label: "Ponts thermiques", rule: "nonNegative" },
        ...panelFields(x.panel),
        ...(x.antiFreeze.enabled ? [
            { value: x.antiFreeze.wPerM2, label: "Câble antigel : puissance", rule: "nonNegative" },
            { value: x.antiFreeze.fraction, label: "Câble antigel : fraction réintroduite", rule: "fraction" },
            { value: x.antiFreeze.dutyPct, label: "Câble antigel : taux de fonctionnement", rule: "percentOrZero" },
        ] : []),
        ...doors,
        ...glazings,
        { value: x.lighting.watts, label: "Éclairage", rule: "nonNegative" },
        { value: x.lighting.hours, label: "Heures d'éclairage", rule: "hoursOfDay" },
        { value: x.people.count, label: "Nombre de personnes", rule: "integerMin0" },
        { value: x.people.hours, label: "Présence des personnes", rule: "hoursOfDay" },
        { value: x.fans.value, label: x.fans.mode === "ratio" ? "Ventilateurs (W par kW frigorifique)" : "Puissance des ventilateurs", rule: "nonNegative" },
        { value: x.otherW, label: "Autres équipements", rule: "nonNegative" },
        ...(x.drain.enabled ? [
            { value: x.drain.lengthM, label: "Câble d'évacuation : longueur", rule: "nonNegative" },
            { value: x.drain.wPerM, label: "Câble d'évacuation : puissance", rule: "nonNegative" },
            { value: x.drain.dripMinutes, label: "Câble d'évacuation : égouttage", rule: "nonNegative" },
        ] : []),
        ...(x.reliefValve.enabled ? [{ value: x.reliefValve.watts, label: "Soupape chauffée", rule: "nonNegative" }] : []),
        ...x.products.flatMap((p, i) => productFields(p, `Produit ${i + 1}`, true)),
        { value: x.airRenewal, label: "Renouvellement d'air", rule: "nonNegative" },
        ...(x.airRenewal > 0 ? [{ value: x.airTExt, label: "Température de l'air neuf", rule: "celsius" }, { value: x.airRhExt, label: "Humidité de l'air neuf", rule: "relativeHumidity" }] : []),
        ...(x.defrost.type !== "air" ? [
            { value: x.defrost.powerKw, label: "Puissance de dégivrage", rule: "nonNegative" },
            { value: x.defrost.fraction, label: "Dégivrage : fraction réintroduite", rule: "fraction" },
        ] : []),
        { value: x.defrost.cycles, label: "Dégivrages par jour", rule: "nonNegative" },
        { value: x.defrost.minutes, label: "Durée d'un dégivrage", rule: "nonNegative" },
        { value: x.runHours, label: "Durée de fonctionnement", rule: "hoursPerDay" },
        { value: x.margin, label: "Marge de sécurité", rule: "nonNegative" },
        { value: x.refrigerant.lineLength, label: "Longueur des liaisons", rule: "nonNegative" },
        { value: x.refrigerant.condenserAir, label: "Air au condenseur", rule: "celsius" },
        ...optional(x.refrigerant.copOverride, "COP imposé", "nonNegative"),
        ...optional(x.refrigerant.chargeRatio, "Charge de fluide par kW", "nonNegative"),
    ];
}

/** Contrôle une liste de champs. */
const check = checkInputs;

/**
 * Message d'erreur des saisies (null si elles sont valides ou incomplètes).
 *
 * @param {"simple"|"pro"} mode   @param {object} x saisies du mode
 */
export function explain(mode, x) {
    return check(mode === "simple" ? simpleFields(x) : proFields(x)).error;
}

/**
 * Saisies complètes du bilan à partir du mode simple : hypothèses fixes (SIMPLE_ASSUMPTIONS).
 *
 * @param {object} s saisies du mode simple (dimensions, régime, usage, panneau, sol, protection de la porte, vitrage, produit, air, marche, marge, fluide)
 * @returns {object} saisies au format du mode pro
 */
export function simpleToFull(s) {
    const a = SIMPLE_ASSUMPTIONS;
    const tSet = REGIME_PRESETS[s.regime]?.tSet ?? 2;
    const negative = tSet < 0;
    const prep = s.usage === "prep";
    const floorInsulated = s.floorInsulated === "yes";
    const protection = DOOR_PROTECTIONS[s.doorProtection] ?? DOOR_PROTECTIONS.none;
    const door = {
        type: "swing", width: prep ? a.door.prepWidth : a.door.width, height: a.door.height, leaves: 1, u: DOOR_TYPES.swing.u,
        protection: s.doorProtection ?? "none", efficiency: protection.efficiency,
        openingsPerDay: prep ? a.door.prepOpeningsPerDay : a.door.openingsPerDay, openSeconds: a.door.openSeconds,
        localTemp: a.door.localTemp, localRh: a.door.localRh, heaterWPerM: negative ? DOOR_HEATER.wPerM : 0, heaterFraction: DOOR_HEATER.fraction,
    };
    const profile = PRODUCT_PROFILES[s.product.profile] ?? PRODUCT_PROFILES.fresh_generic;
    const product = {
        profile: s.product.profile, dailyKg: s.loadMode === "loaded" ? s.product.dailyKg : 0, storedKg: 0,
        entryTemp: negative ? a.frozenEntryTemp : s.product.entryTemp, cp: s.product.cp, waterPct: s.product.waterPct,
        freezePoint: profile.freezePoint, cpFrozen: profile.cpFrozen ?? s.product.cp, pullDown: s.product.pullDown,
        freezing: false, respiration: 0, packaging: "none", packagingKg: 0,
    };

    return {
        mode: "simple", length: s.length, width: s.width, height: s.height, tSet, rhSet: a.rhSet,
        tExtWall: a.tExtWall, tExtCeiling: a.tExtCeiling, tExtFloor: FLOOR_GROUNDS[a.ground].t, floorInsulated, bridgePct: 0,
        panel: s.panel,
        antiFreeze: { enabled: negative && floorInsulated, ...ANTI_FREEZE },
        doors: Array.from({ length: prep ? 2 : 1 }, () => ({ ...door })),
        glazings: s.glazing === "yes" && s.glazingArea > 0 ? [{ area: s.glazingArea, u: GLAZING_TYPES.double.u, heated: false, heaterW: 0, heaterFraction: GLAZING_HEATER_FRACTION, localTemp: a.tExtWall }] : [],
        lighting: { watts: a.lightingWPerM2 * s.length * s.width, hours: a.lightingHours, perM2: a.lightingWPerM2 },
        people: { count: prep ? a.prepPeople : 0, hours: prep ? a.prepPeopleHours : 0 },
        fans: { mode: "volume", value: FAN_W_PER_M3 },
        otherW: 0,
        drain: { enabled: negative, lengthM: a.drainLengthM, wPerM: DRAIN_CABLE.wPerM, fraction: DRAIN_CABLE.fraction, dripMinutes: a.dripMinutes },
        reliefValve: { enabled: negative, ...RELIEF_VALVE },
        products: [product],
        airRenewal: or(s.airRenewal, 0), airTExt: a.tExtWall, airRhExt: or(s.airRhExt, 50),
        defrost: negative
            ? { type: "electric", powerMode: "ratio", powerKw: a.defrost.heaterKwPerKw, cycles: a.defrost.cycles, minutes: a.defrost.minutes, fraction: DEFROST_TYPES.electric.fraction }
            : { type: "air", powerMode: "kw", powerKw: 0, cycles: a.defrost.cycles, minutes: a.defrost.minutes, fraction: 0 },
        runHours: s.runHours, margin: s.margin,
        refrigerant: { ...s.refrigerant, lineLength: a.lineLength, condenserAir: a.condenserAir, detection: false, copOverride: NaN },
    };
}

/** Ligne de résultat d'un poste. */
const line = (key, label, watts, kwhDay, formula, detail, extra = {}) => ({ key, label, watts, kwhDay, formula, detail, ...extra });

/**
 * Postes du bilan pour une puissance frigorifique supposée (ventilateurs et dégivrage « par kW » en dépendent).
 *
 * @param {object} x saisies complètes   @param {number} frigoKw puissance frigorifique supposée (kW)
 * @returns {{lines: Array<object>, products: Array<object>, frostKgDay: number, electricKwhDay: number, geo: object, u: number}}
 */
function loads(x, frigoKw) {
    const geo = geometry(x.length, x.width, x.height);
    const u = panelU(x.panel);
    const lines = [];
    let electricKwhDay = 0;
    const dT = (t) => Math.abs(t - x.tSet);
    const day = (watts) => (watts * 24) / 1000;

    // Parois, plafond et sol
    const uFloor = x.floorInsulated ? u : BARE_FLOOR_U;
    const bridge = 1 + x.bridgePct / 100;
    const walls = u * geo.wallArea * dT(x.tExtWall) * bridge;
    const ceiling = u * geo.ceilingArea * dT(x.tExtCeiling) * bridge;
    const floor = uFloor * geo.floorArea * dT(x.tExtFloor) * bridge;
    const envelope = walls + ceiling + floor;
    lines.push(line("walls", "Parois, plafond et sol", envelope, day(envelope), "Q = U × S × ΔT (+ ponts thermiques)",
        `parois ${n(u, 3)} × ${n(geo.wallArea, 1)} m² × ${n(dT(x.tExtWall), 1)} K + plafond ${n(u, 3)} × ${n(geo.ceilingArea, 1)} m² × ${n(dT(x.tExtCeiling), 1)} K + sol ${n(uFloor, 3)} × ${n(geo.floorArea, 1)} m² × ${n(dT(x.tExtFloor), 1)} K${x.bridgePct > 0 ? `, ponts thermiques × ${n(bridge, 2)}` : ""}`,
        { parts: { walls, ceiling, floor } }));

    // Vitrages
    if (x.glazings.length > 0) {
        const trans = x.glazings.reduce((sum, g) => sum + g.u * g.area * dT(or(g.localTemp, x.tExtWall)), 0);
        lines.push(line("glazing", "Vitrages (transmission)", trans, day(trans), "Q = U × S × ΔT", x.glazings.map((g, i) => `vitrage ${i + 1} : ${n(g.u, 2)} × ${n(g.area, 2)} m² × ${n(dT(or(g.localTemp, x.tExtWall)), 1)} K`).join(" ; ")));
        const heated = x.glazings.filter((g) => g.heated && g.heaterW > 0);
        if (heated.length > 0) {
            const heaters = heated.reduce((sum, g) => sum + g.heaterW * or(g.heaterFraction, GLAZING_HEATER_FRACTION), 0);
            electricKwhDay += day(heated.reduce((sum, g) => sum + g.heaterW, 0));
            lines.push(line("glazingHeaters", "Vitrages chauffés", heaters, day(heaters), "Q = P chauffage × fraction réintroduite, 24 h/24", heated.map((g) => `${n(g.heaterW, 0)} W × ${n(or(g.heaterFraction, GLAZING_HEATER_FRACTION), 2)}`).join(" + ")));
        }
    }

    // Portes : transmission, cordons chauffants, infiltrations
    const doorTrans = x.doors.reduce((sum, d) => sum + (d.u * d.width * d.height + DOOR_FRAME.u * 2 * (d.width + d.height) * DOOR_FRAME.band) * dT(d.localTemp), 0);
    if (x.doors.length > 0) {
        lines.push(line("doorTransmission", "Portes (transmission)", doorTrans, day(doorTrans), "Q = (U porte × S + U cadre × périmètre × 0,06 m) × ΔT",
            x.doors.map((d, i) => `porte ${i + 1} : (${n(d.u, 2)} × ${n(d.width * d.height, 2)} m² + ${n(DOOR_FRAME.u, 1)} × ${n(2 * (d.width + d.height), 2)} m × 0,06) × ${n(dT(d.localTemp), 1)} K`).join(" ; ")));
        const heaterPerimeter = (d) => 2 * (d.width + d.height) + (d.leaves === 2 ? d.height : 0);
        const heaterElectric = x.doors.reduce((sum, d) => sum + d.heaterWPerM * heaterPerimeter(d), 0);
        if (heaterElectric > 0) {
            const heaterIn = x.doors.reduce((sum, d) => sum + d.heaterWPerM * heaterPerimeter(d) * or(d.heaterFraction, DOOR_HEATER.fraction), 0);
            electricKwhDay += day(heaterElectric);
            lines.push(line("doorHeaters", "Cordons chauffants des portes", heaterIn, day(heaterIn), "Q = W/m × (périmètre + montant central si 2 vantaux) × fraction réintroduite, 24 h/24",
                x.doors.filter((d) => d.heaterWPerM > 0).map((d) => `${n(d.heaterWPerM, 0)} W/m × ${n(heaterPerimeter(d), 2)} m × ${n(or(d.heaterFraction, DOOR_HEATER.fraction), 2)}`).join(" + ")));
        }
        const infil = x.doors.map((d) => doorInfiltration(d, x.tSet, x.rhSet));
        const total = infil.reduce((sum, i) => sum + i.totalW, 0);
        const water = infil.reduce((sum, i) => sum + i.waterKgDay, 0);
        lines.push(line("infiltration", "Infiltrations par les portes", total, day(total),
            "ASHRAE : q = 0,221 × A × Δh × ρ_int × (1 − ρ_ext/ρ_int)^0,5 × (g·H)^0,5 × F_m ; charge = q × D_t × D_f × (1 − E)",
            x.doors.map((d, i) => `porte ${i + 1} : ${n(infil[i].qOpenKw, 2)} kW porte ouverte × D_t ${n(infil[i].dt, 4)} × D_f ${n(INFILTRATION.flowFactor, 1)} × (1 − ${n(d.efficiency, 2)})`).join(" ; "),
            {
                sensibleW: infil.reduce((sum, i) => sum + i.sensibleW, 0), latentW: infil.reduce((sum, i) => sum + i.latentW, 0),
                fusionW: infil.reduce((sum, i) => sum + i.fusionW, 0), waterKgDay: water,
            }));
    }

    // Renouvellement d'air (air neuf)
    let airWater = 0;
    if (x.airRenewal > 0) {
        const sensible = sensibleAirPowerW(x.airRenewal, x.airTExt - x.tSet);
        const latent = latentDehumidPowerW(x.airRenewal, x.airTExt, x.airRhExt, x.tSet, x.rhSet);
        airWater = Math.max(0, moistAir(x.airTExt, x.airRhExt).w - moistAir(x.tSet, x.rhSet, x.tSet < 0).w) * 1.2 * x.airRenewal * 24;
        const fusion = x.tSet < 0 ? (airWater * WATER_FREEZING_HEAT * 1000) / 86400 : 0;
        const total = sensible + latent + fusion;
        lines.push(line("airRenewal", "Renouvellement d'air", total, day(total), "sensible Q = 0,335 × qv × ΔT ; latent Q = ṁ × Δw × Lv (+ givre)",
            `${n(x.airRenewal, 0)} m³/h : sensible ${n(sensible, 0)} W, latent ${n(latent + fusion, 0)} W`, { sensibleW: sensible, latentW: latent, fusionW: fusion, waterKgDay: airWater }));
    }

    // Câble antigel sous dalle
    if (x.antiFreeze.enabled) {
        const electric = x.antiFreeze.wPerM2 * geo.floorArea * (x.antiFreeze.dutyPct / 100);
        const into = electric * x.antiFreeze.fraction;
        electricKwhDay += day(electric);
        lines.push(line("antiFreeze", "Câble antigel sous dalle", into, day(into), "Q = W/m² × S sol × taux de fonctionnement × fraction réintroduite",
            `${n(x.antiFreeze.wPerM2, 0)} W/m² × ${n(geo.floorArea, 1)} m² × ${n(x.antiFreeze.dutyPct, 0)} % × ${n(x.antiFreeze.fraction, 2)}`));
    }

    // Dégivrage (puissance saisie, ou par kW frigorifique)
    const defrostType = DEFROST_TYPES[x.defrost.type] ?? DEFROST_TYPES.electric;
    const defrostKw = x.defrost.type === "air" ? 0 : x.defrost.powerMode === "ratio" ? x.defrost.powerKw * frigoKw : x.defrost.powerKw;
    const defrostHours = (x.defrost.cycles * x.defrost.minutes) / 60;
    const defrostFraction = x.defrost.type === "air" ? 0 : x.defrost.fraction;
    const defrostKwh = defrostKw * defrostHours * defrostFraction;
    if (x.defrost.type === "electric") {
        electricKwhDay += defrostKw * defrostHours;
    }
    lines.push(line("defrost", `Dégivrage (${defrostType.label.toLowerCase()})`, (defrostKwh * 1000) / 24, defrostKwh,
        "E = P dégivrage × nombre × durée × fraction réintroduite, lissée sur 24 h",
        x.defrost.type === "air" ? "dégivrage par l'air : aucune chaleur apportée" : `${n(defrostKw, 2)} kW${x.defrost.powerMode === "ratio" ? ` (${n(x.defrost.powerKw, 2)} kW par kW frigorifique)` : ""} × ${n(x.defrost.cycles, 0)} × ${n(x.defrost.minutes, 0)} min × ${n(defrostFraction, 2)}`));

    // Évacuation des condensats : sous tension pendant le dégivrage et l'égouttage
    if (x.drain.enabled) {
        const duty = Math.min(1, (x.defrost.cycles * (x.defrost.minutes + x.drain.dripMinutes)) / 1440);
        const electric = x.drain.wPerM * x.drain.lengthM * duty;
        const into = electric * x.drain.fraction;
        electricKwhDay += day(electric);
        lines.push(line("drain", "Câble d'évacuation des condensats", into, day(into), "Q = W/m × longueur × taux de fonctionnement × fraction réintroduite",
            `${n(x.drain.wPerM, 0)} W/m × ${n(x.drain.lengthM, 1)} m × ${n(duty * 100, 1)} % (${n(x.defrost.cycles, 0)} × (${n(x.defrost.minutes, 0)} + ${n(x.drain.dripMinutes, 0)} min) / 24 h) × ${n(x.drain.fraction, 2)}`));
    }

    // Soupape d'équilibrage chauffée
    if (x.reliefValve.enabled) {
        const into = x.reliefValve.watts * x.reliefValve.fraction;
        electricKwhDay += day(x.reliefValve.watts);
        lines.push(line("reliefValve", "Soupape d'équilibrage chauffée", into, day(into), "Q = P × fraction réintroduite, 24 h/24", `${n(x.reliefValve.watts, 0)} W × ${n(x.reliefValve.fraction, 2)}`));
    }

    // Éclairage
    const lighting = (x.lighting.watts * x.lighting.hours) / 24;
    electricKwhDay += (x.lighting.watts * x.lighting.hours) / 1000;
    lines.push(line("lighting", "Éclairage", lighting, day(lighting), "Q = P éclairage × heures / 24",
        `${x.lighting.perM2 ? `${n(x.lighting.perM2, 0)} W/m² × ${n(geo.floorArea, 1)} m² = ` : ""}${n(x.lighting.watts, 0)} W × ${n(x.lighting.hours, 1)} h / 24`));

    // Personnes
    if (x.people.count > 0 && x.people.hours > 0) {
        const each = personHeat(x.tSet);
        const people = (x.people.count * each * x.people.hours) / 24;
        lines.push(line("people", "Personnes", people, day(people), "Q = nombre × (270 − 6 × T) × heures / 24 (ASHRAE)", `${n(x.people.count, 0)} × ${n(each, 0)} W × ${n(x.people.hours, 1)} h / 24`));
    }

    // Ventilateurs d'évaporateur
    const fansW = x.fans.mode === "volume" ? x.fans.value * geo.volume : x.fans.mode === "ratio" ? x.fans.value * frigoKw : x.fans.value;
    electricKwhDay += day(fansW);
    lines.push(line("fans", "Ventilateurs d'évaporateur", fansW, day(fansW), "Q = P ventilateurs, 24 h/24 (toute la puissance devient chaleur)",
        x.fans.mode === "volume" ? `${n(x.fans.value, 1)} W/m³ × ${n(geo.volume, 1)} m³` : x.fans.mode === "ratio" ? `${n(x.fans.value, 0)} W/kW × ${n(frigoKw, 2)} kW` : `${n(fansW, 0)} W saisis`));

    if (x.otherW > 0) {
        electricKwhDay += day(x.otherW);
        lines.push(line("other", "Autres équipements", x.otherW, day(x.otherW), "Q = P, 24 h/24", `${n(x.otherW, 0)} W`));
    }

    // Produits, respiration, emballages
    const products = x.products.map((p) => productLoad(p, x.tSet));
    const productW = products.reduce((sum, p) => sum + p.watts, 0);
    if (x.products.some((p) => p.dailyKg > 0)) {
        lines.push(line("products", "Produits", productW, products.reduce((sum, p) => sum + p.kwhDay, 0),
            "P = masse × chaleur par kg / durée de refroidissement ; E = masse × chaleur par kg",
            products.map((p, i) => `produit ${i + 1} : ${n(p.dailyKg, 0)} kg/j × ${n(p.energyPerKg, 1)} kJ/kg sur ${n(p.pullDown, 1)} h`).join(" ; ")));
    }
    const respiration = products.reduce((sum, p) => sum + p.respirationW, 0);
    if (respiration > 0) {
        lines.push(line("respiration", "Respiration des produits", respiration, day(respiration), "Q = W/t × masse stockée, 24 h/24",
            products.filter((p) => p.respirationW > 0).map((p) => `${n(p.respiration, 0)} W/t × ${n(p.storedKg / 1000, 2)} t`).join(" + ")));
    }
    const packaging = products.reduce((sum, p) => sum + p.packagingW, 0);
    if (packaging > 0) {
        lines.push(line("packaging", "Emballages", packaging, products.reduce((sum, p) => sum + p.packagingKwhDay, 0), "P = masse × cp × ΔT / durée de refroidissement",
            products.filter((p) => p.packagingW > 0).map((p) => `${n(p.packagingKg, 0)} kg/j × ${n(PACKAGING[p.packaging].cp, 1)} kJ/(kg·K) × ${n(Math.max(0, p.entryTemp - x.tSet), 1)} K`).join(" + ")));
    }

    const frostKgDay = x.tSet < 0 ? lines.reduce((sum, l) => sum + (l.waterKgDay ?? 0), 0) : 0;

    return { lines, products, frostKgDay, waterKgDay: lines.reduce((sum, l) => sum + (l.waterKgDay ?? 0), 0), electricKwhDay, geo, u, defrostKw };
}

/**
 * Charge d'un produit : chaleur par kg (C12), puissance de refroidissement (W, sur la durée de
 * refroidissement), besoin journalier (kWh/j), respiration (positif) et emballages.
 */
function productLoad(p, tSet) {
    const thermal = { cp: p.cp, waterPct: p.waterPct, freezePoint: empty(p.freezePoint) ? null : p.freezePoint, cpFrozen: or(p.cpFrozen, p.cp) };
    const maintenance = p.profile === "frozen";
    const freezing = freezesInRoom(thermal, p.entryTemp, tSet, p.freezing);
    const energyPerKg = p.dailyKg > 0 && !maintenance ? productEnergyPerKg(thermal, p.entryTemp, tSet, freezing) : 0;
    const pullDown = Math.max(0.5, p.pullDown);
    const watts = (p.dailyKg * energyPerKg * 1000) / (pullDown * 3600);
    const frozenAtEntry = !freezing && thermal.freezePoint !== null && p.entryTemp <= thermal.freezePoint;
    const shouldFreeze = !freezing && !maintenance && p.dailyKg > 0 && thermal.freezePoint !== null && thermal.waterPct > 0 && p.entryTemp > thermal.freezePoint && tSet < thermal.freezePoint;
    const respiration = tSet >= 0 ? or(p.respiration, 0) : 0;
    const packagingCp = (PACKAGING[p.packaging] ?? PACKAGING.none).cp;
    const packagingKj = or(p.packagingKg, 0) * packagingCp * Math.max(0, p.entryTemp - tSet);
    const profile = PRODUCT_PROFILES[p.profile] ?? PRODUCT_PROFILES.fresh_generic;

    return {
        ...p, pullDown, energyPerKg, freezing, frozenAtEntry, shouldFreeze, latentHeat: freezing ? freezingHeat(thermal.waterPct) : 0,
        watts, kwhDay: (p.dailyKg * energyPerKg) / 3600, profileLabel: profile.label, profilePullDown: profile.pullDown,
        respiration, respirationW: (respiration * or(p.storedKg, 0)) / 1000,
        packagingW: (packagingKj * 1000) / (pullDown * 3600), packagingKwhDay: packagingKj / 3600,
    };
}

/**
 * Bilan complet : postes, totaux, puissance à installer, consommation, fluide.
 *
 * Puissance frigorifique à installer = Σ charges × 24 / heures de marche × (1 + marge) (méthode de
 * l'ancien site, la charge des produits étant comptée sur leur durée de refroidissement). Besoin
 * journalier = Σ énergies réelles (kWh/j) ; part de chaque poste rapportée à ce besoin.
 *
 * @param {object} x saisies complètes (mode pro, ou simpleToFull())
 * @returns {object} lines, totalW, kwhDay, frigoKw, cop, compressorKw, electricKwhDay, frostKgDay, product(s), fluid
 */
export function coldRoom(x) {
    // Ventilateurs et dégivrage « par kW frigorifique » : point fixe sur la puissance à installer
    let frigoKw = 0;
    let result;
    for (let i = 0; i < 50; i++) {
        result = loads(x, frigoKw);
        const totalW = result.lines.reduce((sum, l) => sum + l.watts, 0);
        const next = (totalW / 1000) * (24 / x.runHours) * (1 + x.margin / 100);
        if (Math.abs(next - frigoKw) < 1e-9) {
            break;
        }
        frigoKw = next;
    }
    const totalW = result.lines.reduce((sum, l) => sum + l.watts, 0);
    const kwhDay = result.lines.reduce((sum, l) => sum + l.kwhDay, 0);
    for (const l of result.lines) {
        l.share = kwhDay > 0 ? l.kwhDay / kwhDay : 0;
    }
    const refrigerant = refrigerantInfo(x, frigoKw, result.geo.volume);
    const compressorKw = frigoKw / refrigerant.cop;

    return {
        ...result, totalW, kwhDay, frigoKw, tSet: x.tSet, runHours: x.runHours, margin: x.margin,
        compressorKw, compressorKwhDay: kwhDay / refrigerant.cop, totalElectricKwhDay: kwhDay / refrigerant.cop + result.electricKwhDay,
        cop: refrigerant.cop, refrigerant,
    };
}

/** Fluide : COP, charge estimée, t éq. CO₂, contrôles, règles applicables, limites NF EN 378-1 ou IEC 60335-2-89. */
function refrigerantInfo(x, frigoKw, volume) {
    const r = x.refrigerant;
    const estimated = estimatedCop(x.tSet, r.condenserAir);
    const cop = r.copOverride > 0 ? r.copOverride : estimated.cop;
    if ((r.fluid ?? null) === null) {
        return { cop, estimated, fluid: null };
    }

    return { cop, estimated, ...refrigerantCharge(r, frigoKw, r.installation === "split" ? r.lineLength : 0, volume) };
}

/**
 * Bilan du mode simple.
 *
 * @param {object} s saisies du mode simple (voir simpleToFull())
 * @returns {object|null} résultat de coldRoom(), ou null si la saisie est invalide ou incomplète
 */
export function coldRoomSimple(s) {
    return check(simpleFields(s)).ok ? coldRoom(simpleToFull(s)) : null;
}

/**
 * Bilan du mode pro.
 *
 * @param {object} x saisies complètes
 * @returns {object|null} résultat de coldRoom(), ou null si la saisie est invalide ou incomplète
 */
export function coldRoomPro(x) {
    return check(proFields(x)).ok ? coldRoom(x) : null;
}
