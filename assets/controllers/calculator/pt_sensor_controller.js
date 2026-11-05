/*
 * Contrôleur Stimulus « calculator--pt-sensor » : sondes PT100/PT1000 (CEI 60751), conversion
 * température ↔ résistance, alerte hors plage, tableau de référence avec recherche.
 *
 * Portage de l'ancien contrôleur (pt_sensor_controller.js de guillaume_c) ; le tableau est construit
 * avec le DOM (aucun HTML assemblé en texte).
 *
 * Utilisé par : templates/calculator/tools/mesures-capteurs/_pt-sensor.html.twig
 *   <div data-controller="calculator--pt-sensor" data-calculator--pt-sensor-r0-value="100">
 * Utilise : assets/calculators/capteurs.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, isOutsidePtRange, OUT_OF_RANGE, ptResistance, ptTable, ptTemperature } from "../../calculators/capteurs.js";
import { formatNumber, parseNumber } from "../../calculators/format.js";

/** Classes de la ligne mise en évidence dans le tableau. */
const ACTIVE_ROW = ["bg-primary-50", "dark:bg-primary-950/50"];

export default class extends Controller {
    static targets = ["temperature", "resistance", "alert", "tableBody", "tableStep", "tableRange", "tableSearch", "tableCount"];
    static values = { r0: { type: Number, default: 100 } };

    /** Construit le tableau et calcule la résistance de départ. */
    connect() {
        this.activeRow = null;
        this.rebuildTable();
        this.resistanceFromTemperature();
    }

    /** Température → résistance. */
    resistanceFromTemperature() {
        const t = parseNumber(this.temperatureTarget.value);
        if (Number.isNaN(t)) {
            this.resistanceTarget.value = "";
            this.alertTarget.hidden = true;
            this.highlight(null);
            return;
        }
        this.alertTarget.hidden = !isOutsidePtRange(t);
        this.resistanceTarget.value = formatNumber(ptResistance(t, this.r0Value), DECIMALS.resistance);
        this.highlight(t);
    }

    /** Résistance → température (« Hors plage » au-delà de -200 à 850 °C). */
    temperatureFromResistance() {
        const r = parseNumber(this.resistanceTarget.value);
        if (Number.isNaN(r)) {
            this.temperatureTarget.value = "";
            this.alertTarget.hidden = true;
            this.highlight(null);
            return;
        }
        const t = ptTemperature(r, this.r0Value);
        this.alertTarget.hidden = t !== null;
        this.temperatureTarget.value = t === null ? OUT_OF_RANGE : formatNumber(t, DECIMALS.temperature);
        this.highlight(t);
    }

    /** Reconstruit le tableau selon le pas et la plage. */
    rebuildTable() {
        const step = Number(this.tableStepTarget.value) || 10;
        const range = this.tableRangeTarget.value;
        const rows = ptTable(this.r0Value, step, range);
        this.tableBodyTarget.replaceChildren(
            ...rows.map((row) => {
                const tr = document.createElement("tr");
                tr.dataset.temperature = String(row.t);
                tr.dataset.resistance = String(row.r);
                for (const text of [formatNumber(row.t, 0), formatNumber(row.r, 2), row.delta === null ? "—" : formatNumber(row.delta, 2)]) {
                    const cell = document.createElement("td");
                    cell.className = "px-4 py-2 text-secondary-800 dark:text-secondary-200";
                    cell.textContent = text;
                    tr.append(cell);
                }

                return tr;
            }),
        );
        this.tableCountTarget.textContent = `${rows.length} lignes · pas de ${step} °C · de ${formatNumber(rows[0].t, 0)} à ${formatNumber(rows.at(-1).t, 0)} °C`;
        this.activeRow = null;
        const t = parseNumber(this.temperatureTarget.value);
        this.highlight(Number.isNaN(t) ? null : t);
    }

    /** Met en évidence la ligne la plus proche d'une température ou d'une résistance recherchée. */
    searchTable() {
        const value = parseNumber(this.tableSearchTarget.value.replace(/[^\d,.-]/g, ""));
        if (Number.isNaN(value)) {
            return;
        }
        const row = this.closestRow((tr) => Math.min(Math.abs(Number(tr.dataset.temperature) - value), Math.abs(Number(tr.dataset.resistance) - value)));
        this.setActive(row);
        row?.scrollIntoView({ block: "nearest" });
    }

    /** Met en évidence la ligne la plus proche d'une température. */
    highlight(t) {
        this.setActive(t === null ? null : this.closestRow((tr) => Math.abs(Number(tr.dataset.temperature) - t)));
    }

    /** Ligne la plus proche selon une distance. */
    closestRow(distance) {
        let best = null;
        let bestDistance = Number.POSITIVE_INFINITY;
        for (const tr of this.tableBodyTarget.rows) {
            const d = distance(tr);
            if (d < bestDistance) {
                best = tr;
                bestDistance = d;
            }
        }

        return best;
    }

    /** Change la ligne mise en évidence. */
    setActive(row) {
        this.activeRow?.classList.remove(...ACTIVE_ROW);
        this.activeRow = row;
        row?.classList.add(...ACTIVE_ROW);
    }
}
