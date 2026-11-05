/*
 * Contrôleur Stimulus « calculator--gaz-parfaits » : pression d'un gaz parfait.
 *
 * Utilisé par : templates/calculator/tools/thermodynamique/gaz-parfaits.html.twig
 * Utilise : assets/calculators/thermodynamique.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, explain, idealGasPressureBar } from "../../calculators/thermodynamique.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["moles", "temperature", "volume", "pressure", "message"];

    /** Recalcule la pression. */
    calculate() {
        const args = [this.molesTarget, this.temperatureTarget, this.volumeTarget].map((target) => parseNumber(target.value));
        showResult(this.pressureTarget, idealGasPressureBar(...args), DECIMALS.pressure, this.messageTarget, explain("idealGas", ...args));
    }
}
