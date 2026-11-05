/*
 * Contrôleur Stimulus « calculator--steinmetz » : condensateur d'un montage Steinmetz.
 *
 * Méthode « puissance » : C ≈ 70 µF/kW (anomalie A2) ; méthode « courant » : C = 14 × I.
 * Comportement de l'ancien site : saisir la puissance en kW remplit les ch (et inversement)
 * et vide le courant ; saisir le courant vide les deux puissances. Une valeur négative ou nulle
 * est refusée avec un message (anomalie A3) et ne modifie pas les autres champs.
 *
 * Utilisé par : templates/calculator/tools/electricite/steinmetz.html.twig
 * Utilise : assets/calculators/electricite.js (steinmetzCapacitor), assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, explain, steinmetzCapacitor } from "../../calculators/electricite.js";
import { formatNumber, parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["kw", "hp", "amps", "capacitance", "message"];

    /** Recalcule à partir du champ saisi. */
    calculate(event) {
        const source = event.target === this.hpTarget ? "hp" : event.target === this.ampsTarget ? "amps" : "kw";
        const value = parseNumber(event.target.value);
        const result = steinmetzCapacitor(source, value);

        // Champ vide ou refusé : les autres champs ne changent pas.
        if (result.capacitance !== null) {
            if (source === "kw") {
                this.hpTarget.value = formatNumber(result.hp, DECIMALS.horsepower);
                this.ampsTarget.value = "";
            } else if (source === "hp") {
                this.kwTarget.value = formatNumber(result.kw, DECIMALS.kilowatt);
                this.ampsTarget.value = "";
            } else {
                this.kwTarget.value = "";
                this.hpTarget.value = "";
            }
        }

        showResult(this.capacitanceTarget, result.capacitance, DECIMALS.capacitorPermanent, this.messageTarget, explain("steinmetzCapacitor", source, value));
    }
}
