/*
 * Contrôleur Stimulus « calculator--chute-tension » : ΔU = 2 × ρ × (L / S) × I, monophasé.
 * ρ selon le matériau (NF C 15-100 / UTE C 15-105, anomalie A1), rappelé sous le résultat ;
 * saisies invalides expliquées (A3).
 *
 * Utilisé par : templates/calculator/tools/electricite/chute-tension.html.twig
 * Utilise : assets/calculators/electricite.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, explain, resistivityNote, voltageDropSinglePhase } from "../../calculators/electricite.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["material", "length", "current", "section", "result", "message", "note"];

    /** Recalcule la chute de tension et met à jour la résistivité rappelée. */
    calculate() {
        const material = this.materialTarget.value;
        const length = parseNumber(this.lengthTarget.value);
        const current = parseNumber(this.currentTarget.value);
        const drop = voltageDropSinglePhase(material, length, current, parseNumber(this.sectionTarget.value));

        showResult(this.resultTarget, drop, DECIMALS.voltageDrop, this.messageTarget, explain("voltageDropSinglePhase", material, length, current));
        this.noteTarget.textContent = resistivityNote(material);
    }
}
