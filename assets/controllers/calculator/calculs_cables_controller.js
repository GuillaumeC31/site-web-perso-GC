/*
 * Contrôleur Stimulus « calculator--calculs-cables » : section, longueur, chute de tension et
 * masse d'un câble. Comme sur l'ancien site, les quatre blocs sont calculés dès l'affichage.
 * Corrections (docs/CALCULATRICE_ANOMALIES.md) : résistivité NF C 15-100 rappelée sous les
 * résultats (A1), saisies invalides expliquées au lieu de « 0,00 » (A3), conducteurs entiers (A4).
 *
 * Utilisé par : templates/calculator/tools/electricite/calculs-cables.html.twig
 * Utilise : assets/calculators/electricite.js (cables), assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { cables, DECIMALS, explain, resistivityNote } from "../../calculators/electricite.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = [
        "sectionMaterial", "sectionLength", "sectionCurrent", "sectionDrop", "sectionResult", "sectionMessage", "sectionNote",
        "lengthMaterial", "lengthSection", "lengthCurrent", "lengthDrop", "lengthResult", "lengthMessage", "lengthNote",
        "dropMaterial", "dropSection", "dropLength", "dropCurrent", "dropResult", "dropMessage", "dropNote",
        "weightMaterial", "weightSection", "weightLength", "weightCores", "weightInsulation", "weightResult", "weightMessage",
    ];

    /** Calcule les quatre blocs à l'affichage. */
    connect() {
        this.section();
        this.length();
        this.drop();
        this.weight();
    }

    /** Section minimale. */
    section() {
        const args = [this.sectionMaterialTarget.value, this.read("sectionLength"), this.read("sectionCurrent"), this.read("sectionDrop")];
        showResult(this.sectionResultTarget, cables.section(...args), DECIMALS.cable, this.sectionMessageTarget, explain("cableSection", ...args));
        this.sectionNoteTarget.textContent = resistivityNote(args[0]);
    }

    /** Longueur maximale. */
    length() {
        const args = [this.lengthMaterialTarget.value, this.read("lengthSection"), this.read("lengthCurrent"), this.read("lengthDrop")];
        showResult(this.lengthResultTarget, cables.length(...args), DECIMALS.cable, this.lengthMessageTarget, explain("cableLength", ...args));
        this.lengthNoteTarget.textContent = resistivityNote(args[0]);
    }

    /** Chute de tension. */
    drop() {
        const args = [this.dropMaterialTarget.value, this.read("dropSection"), this.read("dropLength"), this.read("dropCurrent")];
        showResult(this.dropResultTarget, cables.drop(...args), DECIMALS.cable, this.dropMessageTarget, explain("cableDrop", ...args));
        this.dropNoteTarget.textContent = resistivityNote(args[0]);
    }

    /** Masse estimée. */
    weight() {
        const args = [this.weightMaterialTarget.value, this.read("weightLength"), this.read("weightSection"), this.read("weightCores"), this.read("weightInsulation")];
        showResult(this.weightResultTarget, cables.weight(...args), DECIMALS.cable, this.weightMessageTarget, explain("cableWeight", ...args));
    }

    /** Lit un champ (virgule ou point décimal). */
    read(name) {
        return parseNumber(this[`${name}Target`].value);
    }
}
