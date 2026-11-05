/*
 * Contrôleur Stimulus « calculator--scaling » : mise à l'échelle d'un signal analogique
 * (4-20 mA, 0-20 mA, 0-10 V) et transmetteurs : conversion signal ↔ valeur physique, shunt 250 Ω,
 * alertes de plage, formules, tableau de référence.
 *
 * Portage de l'ancien contrôleur (scaling_controller.js de guillaume_c) ; une plage dont le minimum
 * égale le maximum est signalée (règle A3) ; le tableau est construit avec le DOM.
 *
 * Utilisé par : templates/calculator/tools/mesures-capteurs/_scaling.html.twig
 * Utilise : assets/calculators/capteurs.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, explainRange, physicalToSignal, scalingTable, shuntVoltage, signalAlert, signalToPhysical } from "../../calculators/capteurs.js";
import { formatNumber, parseNumber } from "../../calculators/format.js";

/** Classes de la ligne mise en évidence dans le tableau. */
const ACTIVE_ROW = ["bg-primary-50", "dark:bg-primary-950/50"];

export default class extends Controller {
    static targets = ["outputType", "physicalUnit", "physicalUnitLabel", "minPhysical", "maxPhysical", "rangeMessage", "signal", "physical", "unitLabel", "shuntCard", "shuntVoltage", "alert", "formulaPhysical", "formulaSignal", "tableStep", "tableCount", "tableBody", "tableSignalHeader", "tableShuntHeader"];
    static values = { signalMin: Number, signalMax: Number, signalUnit: String, physicalUnitFixed: String };

    /** Prépare l'affichage et calcule la valeur de départ. */
    connect() {
        this.activeRow = null;
        if (this.hasOutputTypeTarget) {
            this.changeOutputType();
            return;
        }
        this.refreshAll();
    }

    /** Transmetteur : change le type de signal (bornes, unité, shunt). */
    changeOutputType() {
        const option = this.outputTypeTarget.selectedOptions[0];
        this.signalMinValue = Number(option.dataset.min);
        this.signalMaxValue = Number(option.dataset.max);
        this.signalUnitValue = option.dataset.unit;
        this.refreshAll();
    }

    /** Applique une plage proposée (bouton). */
    applyPreset(event) {
        const { min, max, unit } = event.params;
        this.minPhysicalTarget.value = formatNumber(Number(min), 0);
        this.maxPhysicalTarget.value = formatNumber(Number(max), 0);
        if (unit !== undefined && this.hasPhysicalUnitTarget) {
            this.physicalUnitTarget.value = unit;
        }
        this.refreshAll();
    }

    /** Plage physique modifiée. */
    onRangeChange() {
        this.refreshAll();
    }

    /** Unité physique libre modifiée. */
    updatePhysicalUnit() {
        this.physicalUnitLabelTarget.textContent = this.unit();
        this.rebuildTable();
    }

    /** Met à jour libellés, formules, tableau et conversion. */
    refreshAll() {
        const unit = this.signalUnitValue;
        this.unitLabelTarget.textContent = unit;
        this.shuntCardTarget.hidden = unit !== "mA";
        this.tableShuntHeaderTarget.hidden = unit !== "mA";
        this.tableSignalHeaderTarget.textContent = `Signal (${unit})`;
        this.physicalUnitLabelTarget.textContent = this.unit();

        const span = this.signalMaxValue - this.signalMinValue;
        const min = formatNumber(this.signalMinValue, 0);
        this.formulaPhysicalTarget.textContent = `P = Pmin + ((S − ${min}) / ${formatNumber(span, 0)}) × (Pmax − Pmin)`;
        this.formulaSignalTarget.textContent = `S = ${min} + ((P − Pmin) / (Pmax − Pmin)) × ${formatNumber(span, 0)}`;

        this.rebuildTable();
        this.calculatePhysical();
    }

    /** Signal → valeur physique. */
    calculatePhysical(event) {
        const signal = parseNumber(this.signalTarget.value);
        const physical = signalToPhysical(signal, ...this.bounds());
        this.showRangeMessage();
        if (physical === null) {
            return;
        }
        if (event?.target !== this.physicalTarget) {
            this.physicalTarget.value = formatNumber(physical, DECIMALS.physical);
        }
        this.showSignalState(signal);
    }

    /** Valeur physique → signal. */
    calculateSignal(event) {
        const signal = physicalToSignal(parseNumber(this.physicalTarget.value), ...this.bounds());
        this.showRangeMessage();
        if (signal === null || event?.target === this.signalTarget) {
            return;
        }
        this.signalTarget.value = formatNumber(signal, DECIMALS.signal);
        this.showSignalState(signal);
    }

    /** Reconstruit le tableau de référence. */
    rebuildTable() {
        const step = Number(this.tableStepTarget.value) || 1;
        const rows = scalingTable(...this.bounds(), step);
        const unit = this.unit();
        const withShunt = this.signalUnitValue === "mA";
        this.tableBodyTarget.replaceChildren(
            ...rows.map((row) => {
                const tr = document.createElement("tr");
                tr.dataset.signal = String(row.signal);
                const cells = [formatNumber(row.signal, 2), `${formatNumber(row.physical, 2)} ${unit}`, `${formatNumber(row.percent, 1)} %`];
                if (withShunt) {
                    cells.push(formatNumber(shuntVoltage(row.signal), 2));
                }
                for (const text of cells) {
                    const cell = document.createElement("td");
                    cell.className = "px-4 py-2 text-secondary-800 dark:text-secondary-200";
                    cell.textContent = text;
                    tr.append(cell);
                }

                return tr;
            }),
        );
        this.tableCountTarget.textContent = rows.length === 0 ? "" : `${rows.length} lignes · pas de ${formatNumber(step, 0)} ${this.signalUnitValue}`;
        this.activeRow = null;
        const signal = parseNumber(this.signalTarget.value);
        this.highlight(Number.isNaN(signal) ? null : signal);
    }

    /** Shunt, alerte de plage et ligne du tableau pour un signal. */
    showSignalState(signal) {
        if (this.signalUnitValue === "mA") {
            this.shuntVoltageTarget.textContent = formatNumber(shuntVoltage(signal), DECIMALS.shunt);
        }
        const alert = signalAlert(signal, this.signalMinValue, this.signalMaxValue);
        this.alertTarget.hidden = alert === null;
        this.alertTarget.textContent = alert ?? "";
        this.highlight(signal);
    }

    /** Message si la plage est inutilisable (A3). */
    showRangeMessage() {
        const message = explainRange(...this.bounds());
        this.rangeMessageTarget.hidden = message === null;
        this.rangeMessageTarget.textContent = message ?? "";
    }

    /** Bornes : signal min, signal max, valeur min, valeur max. */
    bounds() {
        return [this.signalMinValue, this.signalMaxValue, parseNumber(this.minPhysicalTarget.value), parseNumber(this.maxPhysicalTarget.value)];
    }

    /** Unité physique affichée. */
    unit() {
        if (this.hasPhysicalUnitFixedValue && this.physicalUnitFixedValue !== "") {
            return this.physicalUnitFixedValue;
        }

        return this.hasPhysicalUnitTarget ? this.physicalUnitTarget.value.trim() || "unités" : "";
    }

    /** Met en évidence la ligne la plus proche d'un signal. */
    highlight(signal) {
        this.activeRow?.classList.remove(...ACTIVE_ROW);
        this.activeRow = null;
        if (signal === null) {
            return;
        }
        let bestDistance = Number.POSITIVE_INFINITY;
        for (const tr of this.tableBodyTarget.rows) {
            const distance = Math.abs(Number(tr.dataset.signal) - signal);
            if (distance < bestDistance) {
                bestDistance = distance;
                this.activeRow = tr;
            }
        }
        this.activeRow?.classList.add(...ACTIVE_ROW);
    }
}
