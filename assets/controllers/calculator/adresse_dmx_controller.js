/*
 * Contrôleur Stimulus « calculator--adresse-dmx » : adresse DMX ↔ interrupteurs DIP. Les interrupteurs
 * sont synchronisés dès le chargement (D1 : l'ancien site ne le faisait pas) ; une adresse ou une
 * combinaison hors de 1 à 512 est signalée (A3).
 *
 * Utilisé par : templates/calculator/tools/eclairage/adresse-dmx.html.twig
 * Utilise : assets/calculators/eclairage.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DMX, dmxAddressFromSwitches, dmxDetails, dmxSwitches, explain } from "../../calculators/eclairage.js";
import { parseNumber } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["address", "switch", "sum", "binary", "message"];

    /** Synchronise les interrupteurs avec l'adresse affichée. */
    connect() {
        this.fromAddress();
    }

    /** Adresse saisie → interrupteurs. */
    fromAddress() {
        const address = parseNumber(this.addressTarget.value);
        const switches = dmxSwitches(address);
        if (switches) {
            this.switchTargets.forEach((button, index) => button.setAttribute("aria-pressed", String(switches[index])));
        }
        this.render(address);
    }

    /** Interrupteur basculé → adresse (signalée si elle sort de 1 à 512). */
    toggle(event) {
        const button = event.currentTarget;
        button.setAttribute("aria-pressed", String(button.getAttribute("aria-pressed") !== "true"));
        const address = dmxAddressFromSwitches(this.switchTargets.map((target) => target.getAttribute("aria-pressed") === "true"));
        this.addressTarget.value = String(address);
        this.render(address);
    }

    /** Adresse suivante (512 au plus). */
    increment() {
        this.step(1);
    }

    /** Adresse précédente (1 au moins). */
    decrement() {
        this.step(-1);
    }

    /** Ajoute un pas à l'adresse, dans la plage DMX. */
    step(delta) {
        const current = parseNumber(this.addressTarget.value);
        const next = Number.isInteger(current) ? Math.min(DMX.max, Math.max(DMX.min, current + delta)) : DMX.min;
        this.addressTarget.value = String(next);
        this.fromAddress();
    }

    /** Affiche le détail du calcul, ou le message d'erreur. */
    render(address) {
        const error = explain("dmxAddress", address);
        this.messageTarget.hidden = error === null;
        this.messageTarget.textContent = error ?? "";
        const details = dmxSwitches(address) === null ? null : dmxDetails(address);
        this.sumTarget.textContent = details?.sum ?? "—";
        this.binaryTarget.textContent = details?.binary ?? "—";
    }
}
