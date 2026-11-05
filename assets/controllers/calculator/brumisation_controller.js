/*
 * Contrôleur Stimulus « calculator--brumisation » : consommation d'eau et réserve de brumisation.
 *
 * Utilisé par : templates/calculator/tools/terrariophilie/brumisation.html.twig
 * Utilise : assets/calculators/terrariophilie.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { explain, misting } from "../../calculators/terrariophilie.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["nozzles", "flow", "duration", "frequency", "day", "week", "tank", "message"];

    /** Calcule avec les valeurs par défaut. */
    connect() {
        this.calculate();
    }

    /** Recalcule la consommation et la réserve. */
    calculate() {
        const args = [this.nozzlesTarget, this.flowTarget, this.durationTarget, this.frequencyTarget].map((target) => parseNumber(target.value));
        const result = misting(...args);
        showResult(this.dayTarget, result?.day ?? null, 2, this.messageTarget, explain("misting", ...args));
        showResult(this.weekTarget, result?.week ?? null, 1);
        showResult(this.tankTarget, result?.tank ?? null, 0);
    }
}
