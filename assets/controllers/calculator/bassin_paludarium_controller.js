/*
 * Contrôleur Stimulus « calculator--bassin-paludarium » : volume, poids et débit de pompe d'un bassin.
 *
 * Utilisé par : templates/calculator/tools/terrariophilie/bassin-paludarium.html.twig
 * Utilise : assets/calculators/terrariophilie.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { explain, pond } from "../../calculators/terrariophilie.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["length", "width", "depth", "litres", "weight", "filter", "message"];

    /** Calcule avec les valeurs par défaut. */
    connect() {
        this.calculate();
    }

    /** Recalcule le volume, le poids et le débit. */
    calculate() {
        const dims = [this.lengthTarget, this.widthTarget, this.depthTarget].map((target) => parseNumber(target.value));
        const result = pond(...dims);
        showResult(this.litresTarget, result?.litres ?? null, 1, this.messageTarget, explain("water", ...dims));
        showResult(this.weightTarget, result?.weight ?? null, 1);
        showResult(this.filterTarget, result?.filter ?? null, 0);
    }
}
