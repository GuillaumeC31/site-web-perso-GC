/*
 * Contrôleur Stimulus « calculator--loi-ohm » : loi d'Ohm, deux valeurs connues → les deux autres.
 *
 * Comme sur l'ancien site, le champ en cours de saisie n'est jamais réécrit ; les trois
 * autres reçoivent la valeur calculée (U, R, P à 2 décimales, I à 3). Une valeur négative est
 * refusée avec un message (anomalie A3).
 *
 * Utilisé par : templates/calculator/tools/electricite/loi-ohm.html.twig
 * Utilise : assets/calculators/electricite.js (ohmLaw), assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, explain, ohmLaw } from "../../calculators/electricite.js";
import { formatNumber, parseNumber } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["u", "i", "r", "p", "message"];

    /** Recalcule à chaque saisie. */
    calculate(event) {
        const values = { u: parseNumber(this.uTarget.value), i: parseNumber(this.iTarget.value), r: parseNumber(this.rTarget.value), p: parseNumber(this.pTarget.value) };
        const result = ohmLaw(values);
        const error = explain("ohmLaw", values);
        this.messageTarget.textContent = error ?? "";
        this.messageTarget.hidden = error === null;

        if (result === null) {
            if (Object.values(values).every((value) => Number.isNaN(value))) {
                this.reset();
            }
            return;
        }

        for (const key of ["u", "i", "r", "p"]) {
            const target = this[`${key}Target`];
            if (target !== event?.target) {
                target.value = formatNumber(result[key], DECIMALS.ohm[key]);
            }
        }
    }

    /** Vide les quatre champs. */
    reset() {
        for (const target of [this.uTarget, this.iTarget, this.rTarget, this.pTarget]) {
            target.value = "";
        }
        this.messageTarget.hidden = true;
    }
}
