/*
 * Rapport sur le fluide frigorigène (logique pure, textes en français) : identité du fluide, charge
 * estimée et t éq. CO₂, contrôles d'étanchéité et détection (articles 5 et 6), interdictions vérifiées,
 * limite de charge (IEC 60335-2-89 pour un groupe autonome hermétiquement scellé, NF EN 378-1 sinon),
 * avec l'avertissement éventuel. Commun à la chambre froide et à l'enceinte climatique.
 *
 * Testé par : chambre_froide.test.js, enceinte_climatique.test.js
 * Utilisé par : assets/controllers/calculator/chambre_froide_controller.js, enceinte_climatique_controller.js
 */
import { INSTALLATION_TYPES, ruleText } from "./fgas.js";
import { formatNumber } from "./format.js";

/** Fluide proposé pour les petits équipements autonomes. */
export const SMALL_SELF_CONTAINED_FLUID = "R600a";

/** Valeur sans zéros inutiles (« 0,15 »). */
const plain = (value) => new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 4, useGrouping: false }).format(value);

/** Nombre affiché. */
const n = (value, digits) => formatNumber(value, digits);

/**
 * Lignes du rapport et avertissement éventuel (charge au-delà de la limite).
 *
 * @param {object} r résultat de fgas.refrigerantCharge() (fluid, chargeKg, kgPerKw, tonnes, checks, rules, flammability, productStandard)
 * @param {{installation: string, volume: number, rulesCount: number, refrigerants: Object<string, object>, smallLabel: string, place: string}} context
 *        rulesCount : nombre de règles vérifiées transmises par le site ; smallLabel : « Petit équipement autonome »… ;
 *        place : « le local », « l'enceinte »
 * @returns {{lines: Array<string>, warning: string|null}}
 */
export function fluidReport(r, { installation, volume, rulesCount, refrigerants, smallLabel, place }) {
    const f = r.fluid;
    const type = INSTALLATION_TYPES[installation] ?? INSTALLATION_TYPES.split;
    const lines = [];
    let warning = null;
    lines.push(`${f.name} : ${f.family}, PRG ${f.gwp} (${f.gwpReport}), classe de sécurité ${f.class}${f.glide ? `, glissement ${n(f.glide, 1)} K` : ""}${f.verified ? "" : " — valeurs à vérifier"}.`);
    const ratioSource = r.chargeRatioEntered ? "valeur saisie" : r.kgPerKw !== type.kgPerKw ? `valeur propre au ${f.name}, masse volumique liquide plus faible que celle des HFC ; à vérifier avec les fiches constructeurs` : "estimation à vérifier";
    lines.push(`Charge estimée : ${n(r.chargeKg, 2)} kg (${type.label.toLowerCase()}, ${plain(r.kgPerKw)} kg/kW${type.kgPerMetre ? ` + ${plain(type.kgPerMetre)} kg/m de liaison` : ""} ; ${ratioSource}), soit ${n(r.tonnes, 3)} t éq. CO₂.`);
    const c = r.checks;
    lines.push(c.exempt
        ? "Contrôles d'étanchéité : non obligatoires (équipement hermétiquement scellé de moins de 10 t éq. CO₂, article 5)."
        : c.months === null ? "Contrôles d'étanchéité : non obligatoires en dessous de 5 t éq. CO₂ (article 5)." : `Contrôles d'étanchéité : tous les ${c.months} mois (article 5 ; intervalle doublé avec un système de détection des fuites).`);
    lines.push(c.detectionRequired ? "Détection des fuites : obligatoire à partir de 500 t éq. CO₂, vérifiée tous les 12 mois (article 6)." : "Détection des fuites : non obligatoire (en dessous de 500 t éq. CO₂, article 6).");
    const small = refrigerants[SMALL_SELF_CONTAINED_FLUID];
    if (installation === "self_contained" && small && f !== small) {
        lines.push(`${smallLabel} : le ${small.name} est aussi proposé.`);
    }
    if (rulesCount === 0) {
        lines.push("Interdictions de mise sur le marché et d'entretien (règlement (UE) 2024/573) : en cours de vérification, non affichées.");
    } else if (r.rules.length === 0) {
        lines.push("Aucune interdiction vérifiée ne vise ce fluide pour ce type d'installation.");
    } else {
        lines.push(...r.rules.map(ruleText));
    }
    const fl = r.flammability;
    const ps = r.productStandard;
    if (ps) {
        // Groupe autonome hermétiquement scellé : norme produit IEC 60335-2-89 (décision du 2026-10-23)
        lines.push(`IEC 60335-2-89 (groupe autonome hermétiquement scellé, fluide ${f.class}, LFL ${plain(ps.lfl)} kg/m³) : charge maximale par circuit frigorifique 13 × LFL = ${n(ps.circuitLimit, 2)} kg ; facteur à vérifier sur le texte de la norme.`);
        lines.push(`Conditions associées, à vérifier sur le texte de la norme : au-delà de ${plain(ps.conditionsAboveKg * 1000)} g par circuit, le fabricant indique une surface minimale du local d'installation et l'appareil doit limiter la concentration en cas de fuite (circulation d'air permanente par ventilateur, essais de fuite). Le contrôle NF EN 378-1 du local (20 % de la LFL × volume) reste celui des installations montées sur site (split, centrale).`);
        if (r.chargeKg > ps.circuitLimit) {
            warning = `Attention : la charge estimée (${n(r.chargeKg, 2)} kg de ${f.name}) dépasse la limite par circuit de la norme IEC 60335-2-89 (${n(ps.circuitLimit, 2)} kg). Plusieurs groupes ou circuits, ou un autre fluide, sont à étudier.`;
        }
    } else if (fl?.missing) {
        lines.push(`NF EN 378-1 (fluide inflammable, classe ${f.class}) : limite non calculable : LFL non renseignée.`);
    } else if (fl) {
        lines.push(`NF EN 378-1 (fluide inflammable, classe ${f.class}, LFL ${plain(fl.lfl)} kg/m³) : limite pratique 20 % de la LFL = ${n(fl.practicalLimit, 4)} kg/m³, soit ${n(fl.roomLimit, 2)} kg pour ${n(volume, 1)} m³. Seuils m1, m2, m3 = ${n(fl.m1, 2)}, ${n(fl.m2, 2)}, ${n(fl.m3, 2)} kg (annexe C ; à vérifier selon l'édition en vigueur et la catégorie d'accès).`);
        if (r.chargeKg > fl.roomLimit) {
            warning = `Attention : la charge estimée (${n(r.chargeKg, 2)} kg de ${f.name}) dépasse la limite pour ${place} (${n(fl.roomLimit, 2)} kg). Mesures complémentaires (NF EN 378) ou autre implantation à étudier : groupe hors du local, charge réduite, autre fluide.`;
        }
    }

    return { lines, warning };
}
