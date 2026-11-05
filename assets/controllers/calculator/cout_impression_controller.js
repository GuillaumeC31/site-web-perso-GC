/*
 * Contrôleur Stimulus « calculator--cout-impression » : coût d'une impression 3D. Le choix d'une
 * imprimante ou d'une offre d'électricité (données de référence de l'admin) remplit le champ.
 *
 * Utilisé par : templates/calculator/tools/impression-3d/cout-impression.html.twig
 * Utilise : assets/calculators/impression3d.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, explain, printCost } from "../../calculators/impression3d.js";
import { formatNumber, parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["weight", "spool", "hours", "minutes", "power", "priceKwh", "message", "total", "material", "electricity"];

    /** Remplit la puissance de l'imprimante choisie. */
    choosePrinter(event) {
        if (event.target.value !== "") {
            this.powerTarget.value = event.target.value;
            this.calculate();
        }
    }

    /** Remplit le prix du kWh de l'offre choisie. */
    chooseProvider(event) {
        if (event.target.value !== "") {
            this.priceKwhTarget.value = formatNumber(Number(event.target.value), 4);
            this.calculate();
        }
    }

    /** Recalcule le coût. */
    calculate() {
        const args = [this.weightTarget, this.spoolTarget, this.hoursTarget, this.minutesTarget, this.powerTarget, this.priceKwhTarget].map((target) => parseNumber(target.value));
        const result = printCost(...args);
        showResult(this.totalTarget, result?.total ?? null, DECIMALS.cost, this.messageTarget, explain("printCost", ...args));
        showResult(this.materialTarget, result?.material ?? null, DECIMALS.cost);
        showResult(this.electricityTarget, result?.electricity ?? null, DECIMALS.cost);
    }
}
