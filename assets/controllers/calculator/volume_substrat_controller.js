/*
 * Contrôleur Stimulus « calculator--volume-substrat » : volume de substrat et recette, construite en DOM.
 *
 * Utilisé par : templates/calculator/tools/terrariophilie/volume-substrat.html.twig
 * Utilise : assets/calculators/terrariophilie.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { explain, substrate } from "../../calculators/terrariophilie.js";
import { formatNumber, parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["length", "width", "depth", "litres", "message", "mix", "recipe", "recipeNote"];

    /** Calcule avec les valeurs par défaut. */
    connect() {
        this.calculate();
    }

    /** Recalcule le volume et la recette. */
    calculate() {
        const dims = [this.lengthTarget, this.widthTarget, this.depthTarget].map((target) => parseNumber(target.value));
        const result = substrate(...dims, this.mixTarget.value);
        showResult(this.litresTarget, result?.litres ?? null, 1, this.messageTarget, explain("substrate", ...dims));

        if (result === null) {
            this.recipeTarget.replaceChildren();
            this.recipeNoteTarget.textContent = "Saisissez des dimensions valides pour voir la recette.";
            return;
        }
        this.recipeTarget.replaceChildren(...result.parts.map((part) => {
            const item = document.createElement("li");
            const strong = document.createElement("strong");
            strong.textContent = `${formatNumber(part.share * 100, 0)} % ${part.label} : `;
            item.append(strong, `${formatNumber(part.litres, 1)} L`);
            return item;
        }));
        this.recipeNoteTarget.textContent = result.note;
    }
}
