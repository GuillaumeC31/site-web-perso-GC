/*
 * Contrôleur Stimulus « calculator--unites » : conversion d'unités physiques. Le choix de la
 * grandeur remplit les listes d'unités (la deuxième unité est la cible par défaut, comme sur
 * l'ancien site).
 *
 * Utilisé par : templates/calculator/tools/convertisseur/unites.html.twig
 * Utilise : assets/calculators/convertisseur.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { convert, displayRounding, QUANTITIES } from "../../calculators/convertisseur.js";
import { parseNumber } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["quantity", "value", "from", "to", "result", "message"];

    /** Remplit les listes d'unités au chargement. */
    connect() {
        this.changeQuantity();
    }

    /** Remplit les listes d'unités de la grandeur choisie. */
    changeQuantity() {
        const units = QUANTITIES[this.quantityTarget.value]?.units ?? [];
        for (const select of [this.fromTarget, this.toTarget]) {
            select.replaceChildren(...units.map((unit) => new Option(unit.label, unit.value)));
        }
        if (units.length > 1) {
            this.toTarget.selectedIndex = 1;
        }
        this.convert();
    }

    /** Convertit la valeur saisie. */
    convert() {
        const quantity = this.quantityTarget.value;
        const from = this.fromTarget.value;
        const to = this.toTarget.value;
        const result = convert(quantity, parseNumber(this.valueTarget.value), from, to);

        this.messageTarget.hidden = !result?.error;
        this.messageTarget.textContent = result?.error ?? "";
        if (result === null || result.error) {
            this.resultTarget.textContent = "—";
            return;
        }

        const { value, fixedDigits } = displayRounding(quantity, from, to, result.value);
        this.resultTarget.textContent = new Intl.NumberFormat("fr-FR", fixedDigits === null ? { maximumFractionDigits: 6 } : { minimumFractionDigits: fixedDigits, maximumFractionDigits: fixedDigits }).format(value);
    }
}
