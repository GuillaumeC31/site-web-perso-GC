/*
 * Psychrométrie de l'air humide (logique pure) : pression de vapeur saturante, point de rosée,
 * humidité absolue, teneur en eau, puissances sensible et latente, humidification et
 * déshumidification. Pression atmosphérique fixe : 101 325 Pa.
 *
 * Reprise de l'ancien site (guillaume_c : assets/lib/psychrometrics.js), avec les corrections décidées
 * le 2026-10-23 (docs/CALCULATRICE_ANOMALIES.md) : C3 (puissance latente de l'air = ṁ × Δw × Lv),
 * C4 (un seul jeu de constantes de Magnus, Bolton 1980), C8 (déshumidification : unités et durée).
 *
 * Testé par : psychrometrics.test.js (valeurs de référence golden/thermodynamique.json).
 * Utilisé par : chambre_froide.js, enceinte_climatique.js
 */

const PATM = 101325;

/** Constantes de Magnus (Bolton, 1980), communes à tous les calculs (C4) : Psat = 611,2 × e^(b·T / (c + T)) Pa. */
export const MAGNUS = { b: 17.67, c: 243.5 };

/** Chaleur latente de vaporisation de l'eau (J/kg) à 0 °C : Lv = 2 501 kJ/kg (C3). */
export const LATENT_HEAT = 2_501_000;

/** Masse volumique de l'air retenue (kg/m³). */
export const AIR_DENSITY = 1.2;

/** Pression de vapeur saturante (Pa) — formule Magnus. */
export function saturationPressurePa(Tc) {
    return 611.2 * Math.exp((MAGNUS.b * Tc) / (Tc + MAGNUS.c));
}

/** Point de rosée (°C) depuis T (°C) et HR (%), mêmes constantes que saturationPressurePa (C4). */
export function dewPoint(Tc, rh) {
    if (rh <= 0) return -999;
    const alpha = (MAGNUS.b * Tc) / (MAGNUS.c + Tc) + Math.log(rh / 100);
    return (MAGNUS.c * alpha) / (MAGNUS.b - alpha);
}

/** Humidité absolue (g/m³). */
export function absoluteHumidityGPerM3(Tc, rh) {
    const Pws = saturationPressurePa(Tc);
    const Pw = (Pws * rh) / 100;
    const Tk = Tc + 273.15;
    return (Pw / (461.5 * Tk)) * 1000;
}

/** Teneur en humidité x (kg eau / kg air sec). */
export function humidityRatio(Tc, rh) {
    const Pws = saturationPressurePa(Tc);
    const Pw = (Pws * rh) / 100;
    return (0.62198 * Pw) / (PATM - Pw);
}

/** HR (%) depuis T et point de rosée. */
export function relativeHumidityFromDewPoint(Tc, dewTc) {
    const Pws = saturationPressurePa(Tc);
    const Pw = saturationPressurePa(dewTc);
    return Math.min(100, Math.max(0, (Pw / Pws) * 100));
}

/**
 * Puissance sensible air (W) — débit m³/h, ΔT (K).
 * Q ≈ 0,335 × Qv × ΔT
 */
export function sensibleAirPowerW(flowM3h, deltaT) {
    return 0.335 * flowM3h * Math.abs(deltaT);
}

/**
 * Puissance latente de l'air entrant (W), eau condensée jusqu'à la consigne (C3) :
 * Q = ṁ_air × Δw × Lv, ṁ_air = ρ × qv / 3600, Δw en kg d'eau par kg d'air sec, Lv = 2 501 kJ/kg ;
 * soit Q ≈ 0,83 × qv (m³/h) × Δw (g/kg) avec ρ = 1,2 kg/m³.
 */
export function latentDehumidPowerW(flowM3h, tIn, rhIn, tSet, rhSet) {
    const deltaW = Math.max(0, humidityRatio(tIn, rhIn) - humidityRatio(tSet, rhSet ?? rhIn));
    return ((AIR_DENSITY * flowM3h) / 3600) * deltaW * LATENT_HEAT;
}

/** Constantes de Magnus sur la glace (Sonntag, 1990), pour l'air sous 0 °C : Psat = 611,2 × e^(b·T / (c + T)) Pa. */
export const MAGNUS_ICE = { b: 22.46, c: 272.62 };

/** Pression de vapeur saturante sur la glace (Pa), T < 0 °C. */
export function saturationPressureIcePa(Tc) {
    return 611.2 * Math.exp((MAGNUS_ICE.b * Tc) / (Tc + MAGNUS_ICE.c));
}

/**
 * État de l'air humide (chambre froide v2, infiltrations ASHRAE) : teneur en eau, enthalpie et masse
 * volumique, à la pression atmosphérique.
 * - pression de vapeur : HR × Psat, sur l'eau, ou sur la glace sous 0 °C si overIce (air d'une chambre négative) ;
 * - w = 0,622 × pv / (P − pv) ; h = 1,006 × T + w × (2 501 + 1,86 × T) (kJ/kg d'air sec, ASHRAE Fundamentals, chap. 1) ;
 * - ρ = (P − pv) / (287,05 × T_K) + pv / (461,5 × T_K) (kg/m³ d'air humide).
 *
 * @param {number} Tc température (°C)   @param {number} rh humidité relative (%)
 * @param {boolean} overIce HR rapportée à la saturation sur la glace (si T < 0 °C)
 * @returns {{w: number, h: number, rho: number, pv: number}}
 */
export function moistAir(Tc, rh, overIce = false) {
    const psat = overIce && Tc < 0 ? saturationPressureIcePa(Tc) : saturationPressurePa(Tc);
    const pv = (psat * rh) / 100;
    const w = (0.62198 * pv) / (PATM - pv);
    const Tk = Tc + 273.15;

    return { w, h: 1.006 * Tc + w * (2501 + 1.86 * Tc), rho: (PATM - pv) / (287.05 * Tk) + pv / (461.5 * Tk), pv };
}

/**
 * Débit eau à vaporiser (g/h) pour passer de HR1 à HR2 dans un volume V (m³) à T (°C).
 */
export function humidificationRateGPerH(volumeM3, Tc, rhFrom, rhTo, timeMinutes = 60) {
    if (rhTo <= rhFrom) return 0;
    const rhoAir = 1.2;
    const massAir = volumeM3 * rhoAir;
    const x1 = humidityRatio(Tc, rhFrom);
    const x2 = humidityRatio(Tc, rhTo);
    const waterKg = massAir * (x2 - x1);
    return (waterKg * 1000) / (timeMinutes / 60);
}

/**
 * Puissance thermique humidification (W).
 * type: boiler|steam (chauffe), ultrasonic|atomization (refroidit).
 */
export function humidificationThermalPowerW(rateGPerH, type, waterTempC = 20) {
    const mdot = rateGPerH / 1000 / 3600;
    const Lv = 2_500_000;
    const cpWater = 4180;
    switch (type) {
        case 'boiler':
        case 'steam':
        case 'external_steam':
            return mdot * (Lv + cpWater * Math.max(0, 100 - waterTempC));
        case 'ultrasonic':
        case 'atomization':
            return -mdot * Lv * 0.85;
        default:
            return mdot * Lv;
    }
}

/**
 * Déshumidification frigorifique d'un volume d'air (C8) : eau à retirer et puissance moyenne (W) sur la
 * durée de la transition. Eau (g) = Δ humidité absolue (g/m³) × volume ; latent = eau × Lv / durée ;
 * sensible = refroidissement de l'air jusqu'à l'évaporateur, ρ × V × cp × ΔT / durée.
 *
 * @param {number} durationMinutes durée de la transition (60 min par défaut)
 * @returns {{sensible: number, latent: number, total: number, waterGrams: number, dewPoint: number}}
 */
export function dehumidRefrigPowerW(volumeM3, Tc, rhFrom, rhTo, evapTempC, durationMinutes = 60) {
    const td = dewPoint(Tc, rhTo);
    const seconds = durationMinutes * 60;
    const deltaT = Math.max(0, Tc - evapTempC);
    const sensible = (volumeM3 * AIR_DENSITY * 1005 * deltaT) / seconds;
    const waterGrams = Math.max(0, absoluteHumidityGPerM3(Tc, rhFrom) - absoluteHumidityGPerM3(Tc, rhTo)) * volumeM3;
    const latent = ((waterGrams / 1000) * LATENT_HEAT) / seconds;
    return { sensible, latent, total: sensible + latent, waterGrams, dewPoint: td };
}
