/*
 * Calculs de la catégorie Thermodynamique (logique pure, sans DOM) : chauffe d'un volume d'eau,
 * chauffe d'un fluide, loi des gaz parfaits, point de rosée (trois méthodes).
 *
 * Portage de l'ancien site (calculator_controller.js et fluid_heating_controller.js de guillaume_c),
 * avec la règle A3 (saisies sans signification refusées, avec un message) et les corrections C1
 * (durée et puissance calculées sur l'énergie exacte, arrondi à l'affichage seulement), C2
 * (psychromètre, inopérant sur l'ancien site) et C4 (constantes de Magnus de Bolton) :
 * docs/CALCULATRICE_ANOMALIES.md.
 * La chambre froide et l'enceinte climatique sont dans chambre_froide.js et enceinte_climatique.js.
 *
 * Testé par : thermodynamique.test.js (valeurs de référence golden/thermodynamique.json + corrections).
 * Utilisé par : assets/controllers/calculator/{chauffe_eau,chauffe_fluide,gaz_parfaits,point_rosee}_controller.js
 */
import { inputChecker } from "./inputs.js";

/** Capacité thermique massique de l'eau (J/(kg·K)), 1 L d'eau = 1 kg (ancien site). */
export const WATER_SPECIFIC_HEAT = 4185;

/** Constante des gaz parfaits (J/(mol·K)), valeur de l'ancien site. */
export const GAS_CONSTANT = 8.314;

/** Coefficients de Magnus (Bolton, 1980 ; pression en hPa), les mêmes que psychrometrics.js (C4). */
export const MAGNUS = { a: 6.112, b: 17.67, c: 243.5 };

/** Coefficient psychrométrique d'un psychromètre ventilé (1/°C). */
export const PSYCHROMETER_COEFFICIENT = 0.000662;

/** Décimales d'affichage de l'ancien site. */
export const DECIMALS = { energy: 2, hours: 2, power: 0, pressure: 2, temperature: 2, humidity: 2, relativeHumidity: 1, vapourPressure: 2 };

/** Règles de saisie de chaque calcul. */
export const INPUTS = {
    waterEnergy: (volume, tStart, tEnd) => [
        { value: volume, label: "Volume d'eau", rule: "positive" },
        { value: tStart, label: "Température de départ", rule: "celsius" },
        { value: tEnd, label: "Température finale", rule: "celsius" },
    ],
    heatingPower: (power) => [{ value: power, label: "Puissance", rule: "positive" }],
    heatingTime: (hours) => [{ value: hours, label: "Temps de chauffe", rule: "positive" }],
    fluidEnergy: (volume, tStart, tEnd, cp, density) => [
        { value: volume, label: "Volume du fluide", rule: "positive" },
        { value: tStart, label: "Température initiale", rule: "celsius" },
        { value: tEnd, label: "Température finale", rule: "celsius" },
        { value: cp, label: "Capacité thermique (Cp)", rule: "positive" },
        { value: density, label: "Masse volumique (ρ)", rule: "positive" },
    ],
    idealGas: (moles, temperature, litres) => [
        { value: moles, label: "Quantité de matière n", rule: "positive" },
        { value: temperature, label: "Température T", rule: "celsius" },
        { value: litres, label: "Volume V", rule: "positive" },
    ],
    fromRelativeHumidity: (t, rh) => [
        { value: t, label: "Température", rule: "celsius" },
        { value: rh, label: "Humidité relative", rule: "relativeHumidity" },
    ],
    fromDewPoint: (t, dewPoint) => [
        { value: t, label: "Température", rule: "celsius" },
        { value: dewPoint, label: "Point de rosée", rule: "celsius" },
    ],
    fromWetBulb: (dry, wet, pressure) => [
        { value: dry, label: "Bulbe sec", rule: "celsius" },
        { value: wet, label: "Bulbe humide", rule: "celsius" },
        { value: pressure, label: "Pression atmosphérique", rule: "positive" },
    ],
};

/** Contrôles entre champs. */
const RELATIONS = {
    fromDewPoint: (t, dewPoint) => (dewPoint <= t ? null : "Le point de rosée ne peut pas dépasser la température de l'air."),
    fromWetBulb: (dry, wet) => (wet <= dry ? null : "Le bulbe humide ne peut pas dépasser le bulbe sec."),
};

const checker = inputChecker(INPUTS, RELATIONS);

/**
 * Renvoie le message d'erreur d'un calcul (null si les saisies sont valides ou incomplètes).
 */
export const explain = checker.explain;

/**
 * Énergie pour chauffer un volume d'eau : E = V × 4185 × |ΔT| (1 L = 1 kg), en kWh.
 *
 * @param {number} volume litres   @param {number} tStart °C   @param {number} tEnd °C
 * @returns {number|null} énergie (kWh)
 */
export function waterEnergyKwh(volume, tStart, tEnd) {
    if (!checker.valid("waterEnergy", volume, tStart, tEnd)) {
        return null;
    }

    return (volume * WATER_SPECIFIC_HEAT * Math.abs(tEnd - tStart)) / 3600000;
}

/**
 * Durée de chauffe : t = E / P (rendement 100 %, sans pertes).
 *
 * @param {number|null} energyKwh énergie (kWh)   @param {number} powerWatts puissance (W)
 * @returns {number|null} durée (h)
 */
export function heatingHours(energyKwh, powerWatts) {
    if (energyKwh === null || !checker.valid("heatingPower", powerWatts)) {
        return null;
    }

    return energyKwh / (powerWatts / 1000);
}

/**
 * Puissance nécessaire : P = E / t (rendement 100 %, sans pertes).
 *
 * @param {number|null} energyKwh énergie (kWh)   @param {number} hours durée (h)
 * @returns {number|null} puissance (W)
 */
export function heatingWatts(energyKwh, hours) {
    if (energyKwh === null || !checker.valid("heatingTime", hours)) {
        return null;
    }

    return (energyKwh / hours) * 1000;
}

/**
 * Énergie pour chauffer un fluide : m = V / 1000 × ρ, E = m × Cp × |ΔT|, en kWh.
 *
 * @param {number} volume litres   @param {number} tStart °C   @param {number} tEnd °C
 * @param {number} cp     J/(kg·K) @param {number} density kg/m³
 * @returns {number|null} énergie (kWh)
 */
export function fluidEnergyKwh(volume, tStart, tEnd, cp, density) {
    if (!checker.valid("fluidEnergy", volume, tStart, tEnd, cp, density)) {
        return null;
    }

    return (volume * 0.001 * density * cp * Math.abs(tEnd - tStart)) / 3600000;
}

/**
 * Pression d'un gaz parfait : P = n × R × T / V, T en kelvins et V en m³, résultat en bar.
 *
 * @param {number} moles mol   @param {number} celsius °C   @param {number} litres L
 * @returns {number|null} pression (bar)
 */
export function idealGasPressureBar(moles, celsius, litres) {
    if (!checker.valid("idealGas", moles, celsius, litres)) {
        return null;
    }

    return (moles * GAS_CONSTANT * (celsius + 273.15)) / (litres / 1000) / 100000;
}

/** Pression de vapeur saturante sur l'eau (hPa), formule de Magnus. */
export function saturationPressureHpa(t) {
    return MAGNUS.a * Math.exp((MAGNUS.b * t) / (t + MAGNUS.c));
}

/** Point de rosée (°C) d'une pression de vapeur (hPa) ; 0,001 hPa au minimum (ancien site). */
export function dewPointFromVapour(pv) {
    const p = pv > 0.001 ? pv : 0.001;
    const ln = Math.log(p / MAGNUS.a);

    return (MAGNUS.c * ln) / (MAGNUS.b - ln);
}

/** Humidité absolue (g/m³) : 2,16679 × Pv (Pa) / T (K). */
export function absoluteHumidity(pv, t) {
    return (2.16679 * (pv * 100)) / (t + 273.15);
}

/**
 * Point de rosée, humidité absolue et pression de vapeur depuis la température et l'humidité relative.
 *
 * @returns {{dewPoint: number, absoluteHumidity: number, vapourPressure: number}|null}
 */
export function fromRelativeHumidity(t, rh) {
    if (!checker.valid("fromRelativeHumidity", t, rh)) {
        return null;
    }
    const pv = saturationPressureHpa(t) * (rh / 100);

    return { dewPoint: dewPointFromVapour(pv), absoluteHumidity: absoluteHumidity(pv, t), vapourPressure: pv };
}

/**
 * Humidité relative et absolue depuis la température et le point de rosée.
 *
 * @returns {{relativeHumidity: number, absoluteHumidity: number}|null}
 */
export function fromDewPoint(t, dewPoint) {
    if (!checker.valid("fromDewPoint", t, dewPoint)) {
        return null;
    }
    const pv = saturationPressureHpa(dewPoint);

    return { relativeHumidity: (pv / saturationPressureHpa(t)) * 100, absoluteHumidity: absoluteHumidity(pv, t) };
}

/**
 * Psychromètre à bulbe sec et bulbe humide : Pv = Psat(Th) − A × Patm × (Ts − Th), A = 0,000662 /°C.
 * Code de l'ancien site, qui ne s'exécutait pas (anomalie C2) : Pv au minimum 0,001 hPa, HR au plus 100 %.
 *
 * @param {number} dry bulbe sec (°C)   @param {number} wet bulbe humide (°C)   @param {number} pressure hPa
 * @returns {{relativeHumidity: number, dewPoint: number, absoluteHumidity: number}|null}
 */
export function fromWetBulb(dry, wet, pressure) {
    if (!checker.valid("fromWetBulb", dry, wet, pressure)) {
        return null;
    }
    let pv = saturationPressureHpa(wet) - PSYCHROMETER_COEFFICIENT * pressure * (dry - wet);
    if (pv < 0) {
        pv = 0.001;
    }

    return {
        relativeHumidity: Math.min(100, (pv / saturationPressureHpa(dry)) * 100),
        dewPoint: dewPointFromVapour(pv),
        absoluteHumidity: absoluteHumidity(pv, dry),
    };
}
