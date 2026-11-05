/*
 * Contrôleur Stimulus « calculator--resistance-led » : résistance série d'une LED.
 * Le choix d'une couleur remplit la tension de la LED (« Personnalisée » vide le champ).
 *
 * Utilisé par : templates/calculator/tools/electronique/resistance-led.html.twig
 * Utilise : assets/calculators/electronique.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, explain, formatResistance, ledResistor, powerLabel } from "../../calculators/electronique.js";
import { formatNumber, parseNumber, showResult } from "../../calculators/format.js";

/** Conseil affiché sans résultat (règle B1). */
const DEFAULT_ADVICE = "Puissance conseillée : valeur normalisée ≥ 2 × la puissance dissipée.";

export default class extends Controller {
    static targets = ["supply", "color", "led", "current", "message", "resistance", "e24", "power", "advice"];

    /** Remplit la tension selon la couleur choisie. */
    chooseColor() {
        if (this.colorTarget.value === "custom") {
            this.ledTarget.value = "";
            this.ledTarget.focus();
        } else if (this.colorTarget.value !== "") {
            this.ledTarget.value = formatNumber(Number(this.colorTarget.value), 1);
        }
        this.calculate();
    }

    /** Recalcule la résistance, la valeur E24 et la puissance. */
    calculate() {
        const args = [parseNumber(this.supplyTarget.value), parseNumber(this.ledTarget.value), parseNumber(this.currentTarget.value)];
        const result = ledResistor(...args);
        const error = explain("ledResistor", ...args);

        showResult(this.resistanceTarget, result?.resistance ?? null, DECIMALS.ledResistance, this.messageTarget, error);
        showResult(this.powerTarget, result?.power ?? null, DECIMALS.ledPower);
        this.e24Target.textContent = result ? formatResistance(result.e24) : "—";
        this.adviceTarget.textContent = result ? adviceText(result.rating) : DEFAULT_ADVICE;
    }
}

/** Texte du conseil de puissance (B1 : marge de 2 sur la puissance dissipée). */
function adviceText(rating) {
    return `Choisissez une résistance d'au moins ${powerLabel(rating.watts)}${rating.powerResistor ? " (résistance de puissance)" : ""} : valeur normalisée ≥ 2 × la puissance dissipée.`;
}
