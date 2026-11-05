/*
 * Contrôleur Stimulus « calculator--verin » : poussée et traction d'un vérin.
 *
 * Utilisé par : templates/calculator/tools/mecanique/verin.html.twig
 * Utilise : assets/calculators/mecanique.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { cylinder, DECIMALS, explain, explainRod } from "../../calculators/mecanique.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["pressure", "bore", "rod", "message", "pushNewtons", "pushKgf", "pullNewtons", "pullKgf", "rodMessage"];

    /** Recalcule les forces. */
    calculate() {
        const pressure = parseNumber(this.pressureTarget.value);
        const bore = parseNumber(this.boreTarget.value);
        const rod = parseNumber(this.rodTarget.value);
        const result = cylinder(pressure, bore, rod);

        showResult(this.pushNewtonsTarget, result?.pushNewtons ?? null, DECIMALS.force, this.messageTarget, explain("cylinder", pressure, bore));
        showResult(this.pushKgfTarget, result?.pushKgf ?? null, DECIMALS.force);
        showResult(this.pullNewtonsTarget, result?.pullNewtons ?? null, DECIMALS.force, this.rodMessageTarget, explainRod(rod, bore));
        showResult(this.pullKgfTarget, result?.pullKgf ?? null, DECIMALS.force);
    }
}
