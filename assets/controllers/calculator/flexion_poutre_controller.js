/*
 * Contrôleur Stimulus « calculator--flexion-poutre » : flèche et moment d'une poutre encastrée.
 *
 * Utilisé par : templates/calculator/tools/mecanique/flexion-poutre.html.twig
 * Utilise : assets/calculators/mecanique.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { cantileverBeam, DECIMALS, explain } from "../../calculators/mecanique.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["force", "length", "modulus", "inertia", "message", "deflection", "moment"];

    /** Recalcule la flèche et le moment. */
    calculate() {
        const args = [this.forceTarget, this.lengthTarget, this.modulusTarget, this.inertiaTarget].map((target) => parseNumber(target.value));
        const result = cantileverBeam(...args);
        showResult(this.deflectionTarget, result?.deflection ?? null, DECIMALS.deflection, this.messageTarget, explain("beam", ...args));
        showResult(this.momentTarget, result?.moment ?? null, DECIMALS.moment);
    }
}
