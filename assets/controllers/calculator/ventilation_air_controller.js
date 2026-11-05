/*
 * Contrôleur Stimulus « calculator--ventilation-air » : débit d'extraction d'un terrarium.
 *
 * Utilisé par : templates/calculator/tools/terrariophilie/ventilation-air.html.twig
 * Utilise : assets/calculators/terrariophilie.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { explain, ventilation } from "../../calculators/terrariophilie.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["volume", "turnover", "m3h", "cfm", "message"];

    /** Calcule avec les valeurs par défaut. */
    connect() {
        this.calculate();
    }

    /** Recalcule le débit. */
    calculate() {
        const volume = parseNumber(this.volumeTarget.value);
        const result = ventilation(volume, Number(this.turnoverTarget.value));
        showResult(this.m3hTarget, result?.m3h ?? null, 1, this.messageTarget, explain("ventilation", volume));
        showResult(this.cfmTarget, result?.cfm ?? null, 1);
    }
}
