/*
 * Contrôleur Stimulus « calculator--eclairage-reptiles » : zones de Ferguson (UVI, distances des tubes)
 * et DLI d'une lampe LED pour les plantes.
 *
 * Utilisé par : templates/calculator/tools/terrariophilie/eclairage-reptiles.html.twig
 * Utilise : assets/calculators/terrariophilie.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { explain, FERGUSON_ZONES, terrariumDli } from "../../calculators/terrariophilie.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["zone", "zoneName", "uviAvg", "uviMax", "t56", "t512", "t514", "ppfd", "hours", "dli", "message"];

    /** Affiche la zone choisie et le DLI par défaut. */
    connect() {
        this.updateZone();
        this.calcDli();
    }

    /** Affiche l'UVI et les distances de la zone choisie. */
    updateZone() {
        const zone = FERGUSON_ZONES[this.zoneTarget.value] ?? FERGUSON_ZONES[3];
        this.zoneNameTarget.textContent = zone.name;
        this.uviAvgTarget.textContent = zone.avg;
        this.uviMaxTarget.textContent = zone.max;
        this.t56Target.textContent = zone.t56;
        this.t512Target.textContent = zone.t512;
        this.t514Target.textContent = zone.t514;
    }

    /** Recalcule le DLI. */
    calcDli() {
        const args = [this.ppfdTarget, this.hoursTarget].map((target) => parseNumber(target.value));
        showResult(this.dliTarget, terrariumDli(...args), 1, this.messageTarget, explain("dli", ...args));
    }
}
