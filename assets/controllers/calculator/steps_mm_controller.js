/*
 * Contrôleur Stimulus « calculator--steps-mm » : calibrage des steps/mm.
 *
 * Utilisé par : templates/calculator/tools/impression-3d/steps-mm.html.twig
 * Utilise : assets/calculators/impression3d.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, explain, stepsPerMm } from "../../calculators/impression3d.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["current", "expected", "measured", "result", "message"];

    /** Recalcule le nouveau réglage. */
    calculate() {
        const args = [this.currentTarget, this.expectedTarget, this.measuredTarget].map((target) => parseNumber(target.value));
        showResult(this.resultTarget, stepsPerMm(...args), DECIMALS.steps, this.messageTarget, explain("stepsPerMm", ...args));
    }
}
