/*
 * Contrôleur Stimulus « calculator--chauffage-terrarium » : puissance de chauffage d'un terrarium par les
 * parois (D5), avec un matériau et un U modifiable par paroi, et l'estimation rapide empirique de l'ancien site.
 *
 * Utilisé par : templates/calculator/tools/terrariophilie/chauffage-terrarium.html.twig
 * Utilise : assets/calculators/terrariophilie.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { explain, heatingByWalls, heatingPower, wallU } from "../../calculators/terrariophilie.js";
import { formatNumber, parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["length", "width", "height", "tRoom", "tTarget", "airChanges", "safety", "material", "u", "area", "faceLoss", "conduction", "ventilation", "total", "power", "rounding", "message", "quickMaterial", "quick"];

    /** Remplit les U des matériaux par défaut, puis calcule. */
    connect() {
        this.materialTargets.forEach((select, index) => this.fillU(index));
        this.calculate();
    }

    /** Matériau choisi : son U remplace celui de la paroi. */
    chooseMaterial(event) {
        this.fillU(Number(event.target.dataset.index));
        this.calculate();
    }

    /** Écrit l'U du matériau d'une paroi (2 décimales). */
    fillU(index) {
        this.uTargets[index].value = formatNumber(wallU(this.materialTargets[index].value), 2);
    }

    /** Recalcule la méthode par les parois et l'estimation rapide. */
    calculate() {
        const dims = [this.lengthTarget, this.widthTarget, this.heightTarget].map((target) => parseNumber(target.value));
        const [tTarget, tRoom, airChanges, safety] = [this.tTargetTarget, this.tRoomTarget, this.airChangesTarget, this.safetyTarget].map((target) => parseNumber(target.value));
        const uValues = this.uTargets.map((target) => parseNumber(target.value));
        const result = heatingByWalls(...dims, tTarget, tRoom, uValues, airChanges, safety);

        this.areaTargets.forEach((cell, index) => {
            cell.textContent = result ? formatNumber(result.faces[index].area, 3) : "—";
        });
        this.faceLossTargets.forEach((cell, index) => {
            cell.textContent = result ? formatNumber(result.faces[index].watts, 1) : "—";
        });
        showResult(this.conductionTarget, result?.conduction ?? null, 1, this.messageTarget, explain("walls", ...dims, tTarget, tRoom, uValues, airChanges, safety));
        showResult(this.ventilationTarget, result?.ventilation ?? null, 1);
        showResult(this.totalTarget, result?.watts ?? null, 1);
        showResult(this.powerTarget, result?.recommended ?? null, 0);
        this.roundingTarget.textContent = result && result.recommended > 0
            ? `${formatNumber(result.watts, 0)} W calculés → ${formatNumber(result.recommended, 0)} W recommandés (arrondi à la puissance du commerce supérieure, par pas de 25 W).`
            : result ? "La pièce est déjà à la température visée : aucun chauffage nécessaire." : "";

        const quick = heatingPower(...dims, this.quickMaterialTarget.value, tTarget, tRoom);
        showResult(this.quickTarget, quick?.recommended ?? null, 0);
    }
}
