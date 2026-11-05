/*
 * Contrôleur Stimulus « calculator--diviseur-tension » : tension de sortie d'un pont diviseur,
 * et résistance manquante pour obtenir une tension donnée.
 *
 * Utilisé par : templates/calculator/tools/electronique/diviseur-tension.html.twig
 * Utilise : assets/calculators/electronique.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, dividerMissingResistor, explain, voltageDivider } from "../../calculators/electronique.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["vIn", "r1", "r2", "vOut", "current", "outputMessage", "resVIn", "resVOut", "known", "knownValue", "missing", "resCurrent", "resistorMessage"];

    /** Bloc 1 : tension de sortie. */
    output() {
        const args = [this.vInTarget, this.r1Target, this.r2Target].map((target) => parseNumber(target.value));
        const result = voltageDivider(...args);
        showResult(this.vOutTarget, result?.vOut ?? null, DECIMALS.dividerVoltage, this.outputMessageTarget, explain("voltageDivider", ...args));
        showResult(this.currentTarget, result?.currentMilliAmps ?? null, DECIMALS.dividerCurrent);
    }

    /** Bloc 2 : résistance manquante. */
    resistor() {
        const args = [parseNumber(this.resVInTarget.value), parseNumber(this.resVOutTarget.value), this.knownTarget.value, parseNumber(this.knownValueTarget.value)];
        const result = dividerMissingResistor(...args);
        showResult(this.missingTarget, result?.resistance ?? null, DECIMALS.dividerResistance, this.resistorMessageTarget, explain("dividerMissingResistor", ...args));
        showResult(this.resCurrentTarget, result?.currentMilliAmps ?? null, DECIMALS.dividerCurrent);
    }
}
