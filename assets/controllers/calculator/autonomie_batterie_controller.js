/*
 * Contrôleur Stimulus « calculator--autonomie-batterie » : autonomie d'une batterie.
 *
 * Utilisé par : templates/calculator/tools/electronique/autonomie-batterie.html.twig
 * Utilise : assets/calculators/electronique.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { batteryAutonomy, DECIMALS, explain } from "../../calculators/electronique.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["capacity", "current", "hours", "message"];

    /** Recalcule l'autonomie. */
    calculate() {
        const args = [parseNumber(this.capacityTarget.value), parseNumber(this.currentTarget.value)];
        showResult(this.hoursTarget, batteryAutonomy(...args), DECIMALS.batteryHours, this.messageTarget, explain("batteryAutonomy", ...args));
    }
}
