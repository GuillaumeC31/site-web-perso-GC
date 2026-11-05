/*
 * Calculs de la catégorie Terrariophilie (logique pure, sans DOM) : puissance de chauffage, brumisation,
 * volume de substrat et recette, renouvellement d'air, bassin de paludarium, zones de Ferguson et DLI.
 *
 * Portage de l'ancien site (terra_*_controller.js et terrarium_lighting_controller.js de guillaume_c),
 * avec la règle A3 (saisies sans signification refusées, avec un message). Dimensions en cm.
 * Correction D5 (2026-10-23) : chauffage par les parois, l'ancienne règle au volume restant une
 * estimation rapide ; anomalies D6, D7 : docs/CALCULATRICE_ANOMALIES.md.
 *
 * Testé par : terrariophilie.test.js (valeurs de référence golden/terrariophilie.json + corrections).
 * Utilisé par : assets/controllers/calculator/{chauffage_terrarium,brumisation,volume_substrat,
 * ventilation_air,bassin_paludarium,eclairage_reptiles}_controller.js
 */
import { inputChecker } from "./inputs.js";
import { dli } from "./eclairage.js";

/**
 * Chauffage, estimation rapide (empirique, ancien site) : W par litre et par degré d'écart selon le
 * matériau (le verre dissipe plus que le bois). Puissances arrondies aux 25 W supérieurs (lampes du commerce).
 */
export const HEATING_FACTORS = { glass: 0.045, wood: 0.025 };
export const HEATING_STEP = 25;

/** Résistances superficielles intérieure et extérieure cumulées (m²·K/W) : U = 1 / (0,17 + e / λ) (D5). */
export const SURFACE_RESISTANCE = 0.17;

/** Matériaux des parois (D5) : épaisseur (mm) et conductivité λ (W/(m·K)), valeurs usuelles à vérifier. */
export const WALL_MATERIALS = {
    glass: { label: "Verre 4 mm", thickness: 4, lambda: 1.0 },
    osb: { label: "OSB 18 mm", thickness: 18, lambda: 0.13 },
    pvc: { label: "PVC expansé 10 mm", thickness: 10, lambda: 0.06 },
    xps: { label: "XPS 20 mm", thickness: 20, lambda: 0.034 },
};

/** Parois d'un terrarium (D5) et leur surface selon longueur, profondeur, hauteur (m). */
export const FACES = [
    { key: "front", label: "Façade", area: (l, w, h) => l * h },
    { key: "back", label: "Fond", area: (l, w, h) => l * h },
    { key: "left", label: "Côté gauche", area: (l, w, h) => w * h },
    { key: "right", label: "Côté droit", area: (l, w, h) => w * h },
    { key: "top", label: "Dessus", area: (l, w) => l * w },
    { key: "bottom", label: "Dessous", area: (l, w) => l * w },
];

/** Coefficient de sécurité par défaut de la méthode par les parois (à vérifier). */
export const HEATING_SAFETY = 1.2;

/** 0,34 Wh/(m³·K) : capacité thermique volumique de l'air (ρ × cp / 3600). */
export const AIR_HEAT = 0.34;

/** Coefficient U (W/(m²·K)) d'une paroi : U = 1 / (0,17 + e/λ), e en m. */
export function wallU(material) {
    const m = WALL_MATERIALS[material] ?? WALL_MATERIALS.glass;

    return 1 / (SURFACE_RESISTANCE + m.thickness / 1000 / m.lambda);
}

/** Réserve de brumisation : une semaine d'autonomie, plus 20 % pour ne pas désamorcer la pompe. */
export const MISTING_DAYS = 7;
export const MISTING_MARGIN = 1.2;

/** 1 m³/h = 0,5885 CFM (pieds cubes par minute). */
export const CFM_PER_M3H = 0.5885;

/** Brassage du bassin : 4 fois le volume par heure (règle de l'ancien site, « 4 à 5 fois »). */
export const FILTER_TURNOVER = 4;

/** Recettes de substrat (parts du volume) et conseils, reprises de l'ancien site. */
export const SUBSTRATE_MIXES = {
    tropical: {
        label: "Tropical / forestier (bioactif)",
        parts: [
            { share: 0.5, label: "Terreau sans engrais" },
            { share: 0.2, label: "Fibre de coco" },
            { share: 0.2, label: "Éclats d'écorce (orchidée)" },
            { share: 0.1, label: "Sable de jeu" },
        ],
        note: "Mélange de type « ABG mix », idéal pour les milieux humides et la microfaune (collemboles, cloportes).",
    },
    arid: {
        label: "Désertique / aride (excavation)",
        parts: [
            { share: 0.6, label: "Sable lavé" },
            { share: 0.4, label: "Terre argileuse / terre de bruyère" },
        ],
        note: "Ce mélange permet de creuser des galeries solides qui ne s'effondrent pas.",
    },
};

/** Zones de Ferguson : UVI moyen et maximal, distances indicatives des tubes T5 avec réflecteur. */
export const FERGUSON_ZONES = {
    1: { name: "Zone 1", label: "Zone 1 (crépusculaire) — ex. gecko à crête, gecko léopard, python royal", avg: "0,0 – 0,7", max: "0,6 – 1,4", t56: "25 – 35 cm", t512: "45 – 55 cm", t514: "55 – 65 cm" },
    2: { name: "Zone 2", label: "Zone 2 (exposition occasionnelle) — ex. boa constrictor, anolis vert", avg: "0,7 – 1,0", max: "1,1 – 3,0", t56: "20 – 25 cm", t512: "35 – 45 cm", t514: "45 – 55 cm" },
    3: { name: "Zone 3", label: "Zone 3 (exposition mi-ombre) — ex. caméléon casqué, tortue d'Hermann", avg: "1,0 – 2,6", max: "2,9 – 7,4", t56: "15 – 20 cm", t512: "25 – 35 cm", t514: "35 – 45 cm" },
    4: { name: "Zone 4", label: "Zone 4 (plein soleil) — ex. pogona (dragon barbu), uromastyx", avg: "2,6 – 3,5+", max: "7,4+", t56: "Non recommandé", t512: "20 – 25 cm", t514: "25 – 35 cm" },
};

const dimensions = (length, width, height, heightLabel) => [
    { value: length, label: "Longueur", rule: "positive" },
    { value: width, label: "Largeur", rule: "positive" },
    { value: height, label: heightLabel, rule: "positive" },
];

/** Règles de saisie de chaque calcul. */
export const INPUTS = {
    walls: (length, width, height, tTarget, tRoom, uValues, airChanges, safety) => [
        ...dimensions(length, width, height, "Hauteur"),
        { value: tTarget, label: "Point chaud visé", rule: "celsius" },
        { value: tRoom, label: "Température de la pièce", rule: "celsius" },
        ...uValues.map((value, index) => ({ value, label: `Coefficient U (${FACES[index].label.toLowerCase()})`, rule: "positive" })),
        { value: airChanges, label: "Renouvellement d'air", rule: "nonNegative" },
        { value: safety, label: "Coefficient de sécurité", rule: "positive" },
    ],
    heating: (length, width, height, tTarget, tRoom) => [
        ...dimensions(length, width, height, "Hauteur"),
        { value: tTarget, label: "Point chaud visé", rule: "celsius" },
        { value: tRoom, label: "Température de la pièce", rule: "celsius" },
    ],
    misting: (nozzles, flow, duration, frequency) => [
        { value: nozzles, label: "Nombre de buses", rule: "integerMin1" },
        { value: flow, label: "Débit par buse", rule: "positive" },
        { value: duration, label: "Durée par pluie", rule: "positive" },
        { value: frequency, label: "Pluies par jour", rule: "integerMin0" },
    ],
    substrate: (length, width, depth) => dimensions(length, width, depth, "Épaisseur"),
    ventilation: (volume) => [{ value: volume, label: "Volume du terrarium", rule: "positive" }],
    water: (length, width, depth) => dimensions(length, width, depth, "Hauteur d'eau"),
    dli: (ppfd, hours) => [
        { value: ppfd, label: "PPFD", rule: "nonNegative" },
        { value: hours, label: "Heures par jour", rule: "hoursOfDay" },
    ],
};

const checker = inputChecker(INPUTS);

/** Message d'erreur d'un calcul (null si les saisies sont valides ou incomplètes). */
export const explain = checker.explain;

/** Volume (L) d'un parallélépipède en cm. */
const litres = (length, width, height) => (length * width * height) / 1000;

/**
 * Puissance de chauffage par les parois (D5) : P = [Σ(U × S) × ΔT + 0,34 × débit (m³/h) × ΔT] × sécurité,
 * débit = volume × renouvellements par heure, arrondie aux 25 W supérieurs ; 0 W si la pièce suffit.
 *
 * @param {number[]} uValues U de chaque paroi (W/(m²·K)), dans l'ordre de FACES
 * @param {number}   airChanges renouvellements d'air par heure
 * @param {number}   safety coefficient de sécurité
 * @returns {{faces: Array<{area: number, u: number, watts: number}>, conduction: number, ventilation: number,
 *            airflow: number, watts: number, recommended: number}|null} dimensions en cm, puissances en W
 */
export function heatingByWalls(length, width, height, tTarget, tRoom, uValues, airChanges, safety) {
    if (!checker.valid("walls", length, width, height, tTarget, tRoom, uValues, airChanges, safety)) {
        return null;
    }
    const [l, w, h] = [length, width, height].map((cm) => cm / 100);
    const deltaT = Math.max(0, tTarget - tRoom);
    const faces = FACES.map((face, index) => {
        const area = face.area(l, w, h);

        return { area, u: uValues[index], watts: uValues[index] * area * deltaT };
    });
    const conduction = faces.reduce((total, face) => total + face.watts, 0);
    const airflow = l * w * h * airChanges;
    const ventilation = AIR_HEAT * airflow * deltaT;
    const watts = (conduction + ventilation) * safety;

    return { faces, conduction, ventilation, airflow, watts, recommended: watts > 0 ? Math.max(HEATING_STEP, Math.ceil(watts / HEATING_STEP) * HEATING_STEP) : 0 };
}

/**
 * Estimation rapide (empirique, ancien site) — puissance de chauffage recommandée : volume (L) × écart de température × facteur du matériau,
 * arrondie aux 25 W supérieurs (25 W au moins) ; 0 W si la pièce est déjà assez chaude.
 *
 * @param {string} material glass|wood
 * @returns {{watts: number, recommended: number}|null}
 */
export function heatingPower(length, width, height, material, tTarget, tRoom) {
    if (!checker.valid("heating", length, width, height, tTarget, tRoom)) {
        return null;
    }
    const deltaT = tTarget - tRoom;
    const watts = deltaT > 0 ? litres(length, width, height) * deltaT * (HEATING_FACTORS[material] ?? HEATING_FACTORS.glass) : 0;

    return { watts, recommended: watts > 0 ? Math.max(HEATING_STEP, Math.ceil(watts / HEATING_STEP) * HEATING_STEP) : 0 };
}

/**
 * Consommation d'eau de brumisation et réserve conseillée.
 *
 * @param {number} nozzles buses   @param {number} flow L/h par buse   @param {number} duration s   @param {number} frequency pluies/jour
 * @returns {{day: number, week: number, tank: number}|null} litres ; réserve arrondie au litre supérieur
 */
export function misting(nozzles, flow, duration, frequency) {
    if (!checker.valid("misting", nozzles, flow, duration, frequency)) {
        return null;
    }
    const day = (flow / 3600) * duration * nozzles * frequency;
    const tank = day * MISTING_DAYS * MISTING_MARGIN;

    return { day, week: day * MISTING_DAYS, tank: tank > 0 ? Math.ceil(tank) : 0 };
}

/**
 * Volume de substrat et recette.
 *
 * @param {string} mix tropical|arid
 * @returns {{litres: number, parts: Array<{share: number, label: string, litres: number}>, note: string}|null}
 */
export function substrate(length, width, depth, mix) {
    if (!checker.valid("substrate", length, width, depth)) {
        return null;
    }
    const total = litres(length, width, depth);
    const recipe = SUBSTRATE_MIXES[mix] ?? SUBSTRATE_MIXES.tropical;

    return { litres: total, parts: recipe.parts.map((part) => ({ ...part, litres: total * part.share })), note: recipe.note };
}

/**
 * Débit d'extraction : volume × renouvellements par heure, en m³/h et en CFM.
 *
 * @param {number} volume litres   @param {number} turnover renouvellements par heure
 * @returns {{m3h: number, cfm: number}|null}
 */
export function ventilation(volume, turnover) {
    if (!checker.valid("ventilation", volume)) {
        return null;
    }
    const m3h = (volume / 1000) * turnover;

    return { m3h, cfm: m3h * CFM_PER_M3H };
}

/**
 * Bassin : volume d'eau, poids (1 kg/L) et débit de pompe conseillé (4 volumes par heure).
 *
 * @returns {{litres: number, weight: number, filter: number}|null}
 */
export function pond(length, width, depth) {
    if (!checker.valid("water", length, width, depth)) {
        return null;
    }
    const volume = litres(length, width, depth);

    return { litres: volume, weight: volume, filter: volume * FILTER_TURNOVER };
}

/** DLI d'une lampe LED (mol/m²/j), contrôlé, ou null. */
export function terrariumDli(ppfd, hours) {
    return checker.valid("dli", ppfd, hours) ? dli(ppfd, hours) : null;
}
