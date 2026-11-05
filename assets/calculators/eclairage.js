/*
 * Calculs de la catégorie Éclairage (logique pure, sans DOM) : adresse DMX et interrupteurs DIP,
 * vieillissement UV (dose, durée, cycles), éclairage horticole (besoins PPFD / DLI / PPF, architecte de
 * spectre, simulateur de panneau, DLI et photopériode, spectre indicatif).
 *
 * Portage de l'ancien site (dmx_controller.js, uv_aging_controller.js, phyto_controller.js de
 * guillaume_c) avec la règle A3 (saisies sans signification ou hors plage refusées, avec un message).
 * Corrections décidées le 2026-10-23 : D3 (unités spectrales du vieillissement UV), D4 (PPF et PPFD sur
 * 400–700 nm, UV et far-red à part, ePPF en option) : docs/CALCULATRICE_ANOMALIES.md.
 *
 * Testé par : eclairage.test.js (valeurs de référence golden/eclairage.json + corrections).
 * Utilisé par : assets/controllers/calculator/{adresse_dmx,vieillissement_uv,eclairage_phyto}_controller.js
 */
import { inputChecker } from "./inputs.js";

/* ------------------------------------------------------------------ DMX */

/** Plage des adresses DMX 512 et nombre d'interrupteurs DIP. */
export const DMX = { min: 1, max: 512, switches: 10 };

/* ------------------------------------------------------------------ Vieillissement UV */

/**
 * Lampes de référence (ASTM G154) : irradiance spectrale contrôlée à une longueur d'onde (D3) ; la dose
 * est alors en kJ/(m²·nm) à cette longueur d'onde. Mode large bande prévu plus tard.
 */
export const UV_LAMPS = {
    uva340: { label: "UVA-340 (contrôle à 340 nm)", wavelength: 340, irradiance: 0.89 },
    uvb313: { label: "UVB-313 (contrôle à 310 nm)", wavelength: 310, irradiance: 0.71 },
};

/* ------------------------------------------------------------------ Éclairage horticole */

/** PPFD indicatifs (µmol/m²/s) par type de culture et stade — recommandations agronomiques, pas normes. */
export const PPFD_PROFILES = {
    low: { min: 100, max: 250, seedling: 100, vegetative: 175, production: 225, label: "100–250" },
    medium: { min: 250, max: 500, seedling: 250, vegetative: 400, production: 450, label: "250–500" },
    high: { min: 500, max: 900, seedling: 350, vegetative: 650, production: 800, label: "500–900" },
    ultra: { min: 900, max: 1500, seedling: 600, vegetative: 1000, production: 1200, label: "900–1500" },
};

/**
 * Bandes du spectre, avec leur domaine (D4 : seules les bandes PAR, 400–700 nm, comptent dans le PPF ;
 * UV et far-red, 700–750 nm, sont comptés à part) et leur efficacité indicative (µmol/J, à vérifier).
 */
export const BANDS = [
    { key: "uv", label: "UV", region: "uv", ppe: 2.0, color: "#8b5cf6" },
    { key: "blue", label: "Bleu", region: "par", ppe: 3.5, color: "#3b82f6" },
    { key: "cold", label: "Blanc froid", region: "par", ppe: 2.8, color: "#94a3b8" },
    { key: "green", label: "Vert", region: "par", ppe: 2.5, color: "#10b981" },
    { key: "warm", label: "Blanc chaud", region: "par", ppe: 2.7, color: "#f59e0b" },
    { key: "red", label: "Rouge", region: "par", ppe: 3.8, color: "#ef4444" },
    { key: "ir", label: "Far-red", region: "farRed", ppe: 2.2, color: "#7f1d1d" },
];

/** Bandes PAR (400–700 nm), dans l'ordre des curseurs de l'architecte. */
export const PAR_BANDS = BANDS.filter((band) => band.region === "par");

/**
 * Répartition par défaut (D4) : parts PAR de l'ancien site ramenées à 100 % (15/15/10/15/35 sur 90),
 * far-red en plus du PPF dans la même proportion (10/90 ≈ 11 %), sans UV.
 */
export const DEFAULT_PAR_SHARES = [17, 17, 11, 16, 39];
export const DEFAULT_EXTRAS = { uv: 0, farRed: 11 };

/** Puissance réelle par défaut d'une LED « 3 W » (W). */
export const DEFAULT_LED_WATTS = 2.2;

/* ------------------------------------------------------------------ Règles de saisie */

export const INPUTS = {
    dmxAddress: (address) => [{ value: address, label: "Adresse DMX", rule: "dmxAddress" }],
    uvDose: (irradiance, hours) => [
        { value: irradiance, label: "Irradiance", rule: "positive" },
        { value: hours, label: "Temps d'exposition UV", rule: "nonNegative" },
    ],
    uvTime: (dose, irradiance) => [
        { value: dose, label: "Dose cible", rule: "nonNegative" },
        { value: irradiance, label: "Irradiance", rule: "positive" },
    ],
    uvCycles: (uvHours, darkHours, targetUv) => [
        { value: uvHours, label: "Durée de la phase UV", rule: "positive" },
        { value: darkHours, label: "Durée de la condensation", rule: "nonNegative" },
        { value: targetUv, label: "Temps UV visé", rule: "nonNegative" },
    ],
    requirement: (area, hours, ppe) => [
        { value: area, label: "Surface", rule: "nonNegative" },
        { value: hours, label: "Photopériode", rule: "hoursOfDay" },
        { value: ppe, label: "Efficacité du luminaire (PPE)", rule: "positive" },
    ],
    architect: (ppf, ledWatts, uvPct, farRedPct) => [
        { value: ppf, label: "PPF cible", rule: "nonNegative" },
        { value: ledWatts, label: "Puissance réelle par LED", rule: "positive" },
        { value: uvPct, label: "UV en plus du PPF", rule: "nonNegative" },
        { value: farRedPct, label: "Far-red en plus du PPF", rule: "nonNegative" },
    ],
    simulate: (counts, ppe, ledWatts) => [
        ...counts.map((value, index) => ({ value, label: `Nombre de LED ${BANDS[index].label.toLowerCase()}`, rule: "integerMin0" })),
        { value: ppe, label: "PPE moyen", rule: "positive" },
        { value: ledWatts, label: "Puissance réelle par LED", rule: "positive" },
    ],
    dli: (ppfd, hours) => [
        { value: ppfd, label: "PPFD", rule: "nonNegative" },
        { value: hours, label: "Heures par jour", rule: "hoursOfDay" },
    ],
    dliHours: (dli, ppfd) => [
        { value: dli, label: "DLI cible", rule: "nonNegative" },
        { value: ppfd, label: "PPFD", rule: "positive" },
    ],
};

const RELATIONS = {
    dliHours: (dli, ppfd) => ((dli * 1_000_000) / (ppfd * 3600) <= 24 ? null : "Ce DLI demande plus de 24 h d'éclairage par jour avec ce PPFD."),
};

const checker = inputChecker(INPUTS, RELATIONS);

/** Message d'erreur d'un calcul (null si les saisies sont valides ou incomplètes). */
export const explain = checker.explain;

/* ------------------------------------------------------------------ DMX */

/**
 * Position des 10 interrupteurs DIP pour une adresse : interrupteur n = bit n−1 (valeur 2^(n−1)).
 *
 * @param {number} address adresse DMX
 * @returns {boolean[]|null} vrai = ON, du n° 1 au n° 10 ; null si l'adresse est invalide
 */
export function dmxSwitches(address) {
    if (!checker.valid("dmxAddress", address)) {
        return null;
    }

    return Array.from({ length: DMX.switches }, (_, bit) => (address & (1 << bit)) !== 0);
}

/**
 * Adresse correspondant à des interrupteurs : somme des valeurs des interrupteurs sur ON.
 *
 * @param {boolean[]} switches du n° 1 au n° 10
 * @returns {number} somme (peut sortir de 1 à 512 : à contrôler avec explain("dmxAddress", …))
 */
export function dmxAddressFromSwitches(switches) {
    return switches.reduce((sum, on, bit) => sum + (on ? 1 << bit : 0), 0);
}

/**
 * Détail du calcul : somme des valeurs des interrupteurs sur ON et écriture binaire (10 bits).
 *
 * @param {number} address adresse DMX
 * @returns {{sum: string, binary: string}} ex. « 2 + 8 = 10 » et « 0b0000001010 »
 */
export function dmxDetails(address) {
    const values = [];
    for (let bit = 0; bit < DMX.switches; bit++) {
        if ((address & (1 << bit)) !== 0) {
            values.push(1 << bit);
        }
    }

    return {
        sum: values.length === 0 ? "0 (toutes positions OFF)" : `${values.join(" + ")} = ${address}`,
        binary: `0b${address.toString(2).padStart(DMX.switches, "0")}`,
    };
}

/* ------------------------------------------------------------------ Vieillissement UV */

/**
 * Dose d'irradiation : Dose (kJ/(m²·nm)) = irradiance spectrale (W/(m²·nm)) × heures × 3,6 (D3).
 *
 * @returns {number|null} dose (kJ/(m²·nm) à la longueur d'onde de contrôle)
 */
export function uvDose(irradiance, hours) {
    return checker.valid("uvDose", irradiance, hours) ? irradiance * hours * 3.6 : null;
}

/**
 * Durée d'exposition UV pour atteindre une dose : heures = Dose / (Irradiance × 3,6).
 *
 * @returns {number|null} heures d'UV
 */
export function uvHoursForDose(dose, irradiance) {
    return checker.valid("uvTime", dose, irradiance) ? dose / (irradiance * 3.6) : null;
}

/**
 * Durée réelle de l'essai (machine) : temps UV / part des heures UV dans le cycle.
 *
 * @returns {number|null} heures machine
 */
export function machineHours(uvHours, darkHours, targetUv) {
    return checker.valid("uvCycles", uvHours, darkHours, targetUv) ? targetUv / (uvHours / (uvHours + darkHours)) : null;
}

/* ------------------------------------------------------------------ Éclairage horticole */

/**
 * Besoins d'une culture : PPFD du stade, DLI, PPF du panneau (avec marge de pertes), puissance estimée.
 *
 * @param {string} plant low|medium|high|ultra   @param {string} stage seedling|vegetative|production
 * @param {number} area m²   @param {number} hours h/j   @param {number} lossPct %   @param {number} ppe µmol/J
 * @returns {{ppfd: number, range: string, dli: number, ppf: number, watts: number}|null}
 */
export function requirement(plant, stage, area, hours, lossPct, ppe) {
    if (!checker.valid("requirement", area, hours, ppe)) {
        return null;
    }
    const profile = PPFD_PROFILES[plant] ?? PPFD_PROFILES.high;
    const ppfd = profile[stage] ?? profile.production;
    const ppf = ppfd * area * (1 + lossPct / 100);

    return { ppfd, range: profile.label, dli: dli(ppfd, hours), ppf, watts: ppf / ppe };
}

/**
 * Architecte de spectre (D4) : LED par bande pour atteindre le PPF cible (400–700 nm) réparti sur les
 * bandes PAR, plus un flux UV et un flux far-red exprimés en % du PPF, comptés à part.
 *
 * @param {number}   ppf       PPF cible, 400–700 nm (µmol/s)
 * @param {number}   ledWatts  puissance réelle d'une LED (W)
 * @param {number[]} parShares parts du PPF par bande PAR (%), dans l'ordre de PAR_BANDS, somme 100
 * @param {number}   uvPct     flux UV en plus, en % du PPF
 * @param {number}   farRedPct flux far-red en plus, en % du PPF
 * @returns {{sum: number, bands: Array<{leds: number, watts: number, flux: number}>, ppf: number, uvFlux: number,
 *            farRedFlux: number, eppf: number, totalLeds: number, totalWatts: number}|null}
 *          null si une saisie est invalide ; bands vide si la somme des parts PAR n'est pas 100 %
 */
export function architect(ppf, ledWatts, parShares, uvPct, farRedPct) {
    if (!checker.valid("architect", ppf, ledWatts, uvPct, farRedPct)) {
        return null;
    }
    const sum = parShares.reduce((total, share) => total + share, 0);
    const uvFlux = (ppf * uvPct) / 100;
    const farRedFlux = (ppf * farRedPct) / 100;
    if (Math.abs(sum - 100) > 1e-9) {
        return { sum, bands: [], ppf, uvFlux, farRedFlux, eppf: ppf + farRedFlux, totalLeds: 0, totalWatts: 0 };
    }
    let par = 0;
    const bands = BANDS.map((band) => {
        const flux = band.region === "uv" ? uvFlux : band.region === "farRed" ? farRedFlux : (ppf * parShares[par++]) / 100;
        const watts = flux / band.ppe;

        return { flux, watts, leds: Math.round(watts / ledWatts) };
    });

    return {
        sum, bands, ppf, uvFlux, farRedFlux, eppf: ppf + farRedFlux,
        totalLeds: bands.reduce((total, b) => total + b.leds, 0), totalWatts: bands.reduce((total, b) => total + b.watts, 0),
    };
}

/**
 * Rééquilibre les curseurs quand l'un change : les autres se partagent le reste au prorata (ou à
 * parts égales s'ils étaient tous à 0), arrondis à l'entier ; l'écart d'arrondi va au plus grand.
 *
 * @param {number[]} shares       parts actuelles (%)
 * @param {number}   changedIndex curseur modifié
 * @returns {number[]} nouvelles parts, de somme 100
 */
export function balanceShares(shares, changedIndex) {
    const result = [...shares];
    const remaining = 100 - result[changedIndex];
    const others = result.map((value, index) => ({ value, index })).filter(({ index }) => index !== changedIndex);
    const sumOthers = others.reduce((total, { value }) => total + value, 0);

    if (sumOthers === 0) {
        if (remaining > 0 && others.length > 0) {
            const share = Math.floor(remaining / others.length);
            let remainder = remaining - share * others.length;
            for (const { index } of others) {
                result[index] = share + (remainder > 0 ? 1 : 0);
                remainder = Math.max(0, remainder - 1);
            }
        }

        return result;
    }
    const scaled = others.map(({ value, index }) => ({ index, value: Math.round((value / sumOthers) * remaining) }));
    const diff = remaining - scaled.reduce((total, { value }) => total + value, 0);
    if (diff !== 0 && scaled.length > 0) {
        // Tri stable décroissant, comme l'ancien site (Array.sort)
        scaled.sort((a, b) => b.value - a.value)[0].value += diff;
    }
    for (const { index, value } of scaled) {
        result[index] = value;
    }

    return result;
}

/**
 * Simulateur de panneau (D4) : puissance électrique de toutes les LED ; PPF (400–700 nm) des seules LED
 * PAR avec le PPE moyen saisi ; flux UV et far-red à part (efficacités indicatives de BANDS) ; ePPF
 * (400–750 nm) = PPF + far-red.
 *
 * @param {number[]} counts   LED par bande, dans l'ordre de BANDS
 * @returns {{ppf: number, uvFlux: number, farRedFlux: number, eppf: number, watts: number, photobiologicalWarning: boolean}|null}
 */
export function simulate(counts, ppe, ledWatts) {
    if (!checker.valid("simulate", counts, ppe, ledWatts)) {
        return null;
    }
    const watts = counts.reduce((total, count) => total + count, 0) * ledWatts;
    const parLeds = counts.filter((_, index) => BANDS[index].region === "par").reduce((total, count) => total + count, 0);
    const ppf = parLeds * ledWatts * ppe;
    const uvFlux = counts[0] * ledWatts * BANDS[0].ppe;
    const farRedFlux = counts[6] * ledWatts * BANDS[6].ppe;

    return { ppf, uvFlux, farRedFlux, eppf: ppf + farRedFlux, watts, photobiologicalWarning: counts[0] > 0 || counts[6] > 0 };
}

/** DLI (mol/m²/j) = PPFD × heures × 3600 / 1 000 000. */
export function dli(ppfd, hours) {
    return (ppfd * hours * 3600) / 1_000_000;
}

/** DLI atteint (contrôlé), ou null. */
export function dliFromPpfd(ppfd, hours) {
    return checker.valid("dli", ppfd, hours) ? dli(ppfd, hours) : null;
}

/** Heures d'éclairage pour un DLI cible avec un PPFD donné, ou null. */
export function hoursForDli(target, ppfd) {
    return checker.valid("dliHours", target, ppfd) ? (target * 1_000_000) / (ppfd * 3600) : null;
}

/**
 * Spectre relatif indicatif (illustration, non mesuré) : pics en cosinus² par bande, de 380 à 780 nm
 * tous les 10 nm, normalisé à 100. Même modèle que l'ancien site.
 *
 * @param {number[]} weights poids par bande, dans l'ordre de BANDS (LED ou parts)
 * @returns {Array<{x: number, y: number}>}
 */
export function spectrum(weights) {
    const [uv, blue, cold, green, warm, red, ir] = weights;
    const values = new Array(41).fill(0);
    const addPeak = (center, width, amplitude) => {
        const centerIndex = Math.round((center - 380) / 10);
        for (let i = 0; i <= 40; i++) {
            const distance = Math.abs(i - centerIndex);
            if (distance < width) {
                values[i] += amplitude * Math.cos((distance / width) * (Math.PI / 2)) ** 2;
            }
        }
    };
    if (uv > 0) addPeak(390, 2, uv * 0.8);
    if (blue > 0) addPeak(450, 3, blue * 1.5);
    if (green > 0) addPeak(520, 4, green * 0.9);
    if (red > 0) addPeak(660, 3, red * 2.0);
    if (ir > 0) addPeak(730, 2, ir * 1.2);
    if (cold > 0) {
        addPeak(450, 3, cold * 1.2);
        addPeak(550, 8, cold * 0.6);
    }
    if (warm > 0) {
        addPeak(450, 3, warm * 0.5);
        addPeak(600, 10, warm * 1.0);
    }
    const max = Math.max(...values, 1);

    return values.map((value, i) => ({ x: 380 + i * 10, y: (value / max) * 100 }));
}
