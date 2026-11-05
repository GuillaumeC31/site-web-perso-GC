/*
 * Contrôleur Stimulus « calculator--chauffe-fluide » : chauffe d'un fluide. Le choix du fluide
 * remplit Cp et ρ (modifiables en « Personnalisé ») ; la puissance donne le temps de chauffe, ou le
 * temps la puissance. Si les deux sont remplis, la puissance l'emporte (ancien site).
 *
 * Utilisé par : templates/calculator/tools/thermodynamique/chauffe-fluide.html.twig
 * Utilise : assets/calculators/thermodynamique.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, explain, fluidEnergyKwh, heatingHours, heatingWatts } from "../../calculators/thermodynamique.js";
import { formatNumber, parseNumber, showResult } from "../../calculators/format.js";

/** Affiche une propriété du fluide sans zéros inutiles (« 4 185 », « 1,204 »). */
const plain = (value) => new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 4 }).format(value);

export default class extends Controller {
    static targets = ["fluid", "cp", "density", "volume", "tStart", "tEnd", "energy", "message", "power", "hours", "sizingMessage"];

    /** Calcule l'énergie au chargement (valeurs par défaut). */
    connect() {
        this.calculate();
    }

    /** Remplit Cp et ρ du fluide choisi ; « Personnalisé » rend les champs modifiables. */
    chooseFluid() {
        const option = this.fluidTarget.selectedOptions[0];
        const custom = this.fluidTarget.value === "custom";
        for (const target of [this.cpTarget, this.densityTarget]) {
            target.readOnly = !custom;
        }
        if (custom) {
            this.cpTarget.focus();
        } else {
            this.cpTarget.value = plain(Number(option.dataset.cp));
            this.densityTarget.value = plain(Number(option.dataset.density));
        }
        this.calculate();
    }

    /** Énergie exacte (kWh), ou null ; elle n'est arrondie qu'à l'affichage (C1). */
    energyKwh() {
        const args = [this.volumeTarget, this.tStartTarget, this.tEndTarget, this.cpTarget, this.densityTarget].map((target) => parseNumber(target.value));
        this.energyError = explain("fluidEnergy", ...args);

        return fluidEnergyKwh(...args);
    }

    /** Recalcule l'énergie, puis le temps (si la puissance est saisie) ou la puissance (si le temps l'est). */
    calculate() {
        const energy = this.energyKwh();
        showResult(this.energyTarget, energy, DECIMALS.energy, this.messageTarget, this.energyError);
        if (this.powerTarget.value.trim() !== "") {
            this.fromPower();
        } else if (this.hoursTarget.value.trim() !== "") {
            this.fromHours();
        }
    }

    /** Temps de chauffe depuis la puissance saisie. */
    fromPower() {
        const power = parseNumber(this.powerTarget.value);
        const hours = heatingHours(this.energyKwh(), power);
        this.hoursTarget.value = hours === null ? "" : formatNumber(hours, DECIMALS.hours);
        this.showSizingError(explain("heatingPower", power));
    }

    /** Puissance depuis le temps de chauffe saisi. */
    fromHours() {
        const hours = parseNumber(this.hoursTarget.value);
        const watts = heatingWatts(this.energyKwh(), hours);
        this.powerTarget.value = watts === null ? "" : formatNumber(watts, DECIMALS.power);
        this.showSizingError(explain("heatingTime", hours));
    }

    /** Affiche ou masque le message du dimensionnement. */
    showSizingError(text) {
        this.sizingMessageTarget.textContent = text ?? "";
        this.sizingMessageTarget.hidden = text === null;
    }
}
