/*
 * Fluides frigorigènes et règlement F-Gas (logique pure) : base de données des fluides et calcul de
 * l'équivalent tonnes CO2 d'une charge.
 *
 * Données initiales : config/calculator/refrigerants.json (aussi chargées dans l'admin par
 * app:calculator:load-reference-data), reprises de l'ancien site (guillaume_c : fluides_frigo_controller.js) : PRG (GWP), statut
 * F-Gas, classe de sécurité, huile, substituts, applications. Chaque PRG indique le rapport du GIEC
 * dont il vient (gwpReport : « AR4 » pour toutes les valeurs reprises, colonne « GWP (AR4) » de
 * l'ancien site). Valeurs et statuts à vérifier au regard de l'annexe du règlement (UE) 2024/573 ;
 * la source n'est affichée qu'après validation dans l'admin : docs/CALCULATRICE_ANOMALIES.md (C5).
 *
 * Testé par : thermodynamique.test.js (valeurs de référence golden/thermodynamique.json).
 * Utilisé par : assets/controllers/calculator/fluides_frigorifiques_controller.js
 */
import initialData from "../../config/calculator/refrigerants.json";
import { inputChecker } from "./inputs.js";

/**
 * Construit la base des fluides (clé : code) à partir de lignes au format de
 * config/calculator/refrigerants.json ou de Refrigerant::toCalculatorArray() (base de l'admin).
 *
 * @param {Array<{code: string}>} rows lignes, dans l'ordre d'affichage
 * @returns {Object<string, object>} fluides par code, sans le champ code
 */
export function refrigerantsFromRows(rows) {
    return Object.fromEntries(rows.map(({ code, ...fluid }) => [code, fluid]));
}

/**
 * Fluides par défaut (données initiales de l'admin, dans l'ordre du tableau de l'ancien site, puis les
 * fluides ajoutés au lot 5e). Sur le site, la base de l'admin les remplace (valeur « fluids » du contrôleur).
 */
export const REFRIGERANTS = refrigerantsFromRows(initialData.refrigerants);

/** Statut F-Gas → niveau d'affichage (couleur) : interdit, en transition, avenir. */
export function statusLevel(status) {
    if (status.includes("Interdit")) {
        return "banned";
    }

    return status === "En transition" ? "transition" : "future";
}

/** Libellés des filtres du tableau (ancien site). */
export const FAMILIES = { HFC: "HFC", "HFO Blend": "HFO Blend", HFO: "HFO (pur)", Naturel: "Naturel", HCFC: "HCFC", CFC: "CFC" };
export const STATUSES = { Avenir: "Avenir", "En transition": "En transition", Interdit: "Interdit" };
export const SAFETY_CLASSES = { A1: "A1 (non inflammable)", A2L: "A2L (légèrement inflammable)", A2: "A2 (inflammable)", A3: "A3 (très inflammable)", B2L: "B2L (toxique, légèrement inflammable)" };

/** Règles de saisie. */
export const INPUTS = {
    charge: (kilograms) => [{ value: kilograms, label: "Charge de l'installation", rule: "nonNegative" }],
};

const checker = inputChecker(INPUTS);

/** Message d'erreur de la saisie (null si valide ou vide). */
export const explain = checker.explain;

/**
 * Équivalent tonnes CO2 : TéqCO2 = charge (kg) × PRG / 1000.
 *
 * @param {number} kilograms charge (kg)   @param {number} gwp PRG du fluide
 * @returns {number|null} tonnes équivalent CO2
 */
export function co2Equivalent(kilograms, gwp) {
    if (!checker.valid("charge", kilograms)) {
        return null;
    }

    return (kilograms * gwp) / 1000;
}

/**
 * Indique si un fluide passe les filtres du tableau (filtre vide : tout passe ; « Interdit »
 * regroupe « Interdit » et « Interdit (Neuf) », comme sur l'ancien site).
 *
 * @param {{family: string, status: string, class: string}} fluid
 * @param {{family?: string, status?: string, safety?: string}} filters
 */
export function matchesFilters(fluid, { family = "", status = "", safety = "" }) {
    return (family === "" || fluid.family === family) && (status === "" || fluid.status.includes(status)) && (safety === "" || fluid.class === safety);
}
