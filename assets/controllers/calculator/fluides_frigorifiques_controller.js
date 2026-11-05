/*
 * Contrôleur Stimulus « calculator--fluides-frigorifiques » : équivalent tonnes CO2 d'une charge et
 * tableau des fluides filtrable (famille, statut F-Gas, sécurité). Listes et tableau construits en
 * DOM (textContent) depuis la base de l'admin (valeur « fluids », fournie par
 * CalculatorController::toolData()), ou à défaut la base initiale de refrigerants.js.
 *
 * Utilisé par : templates/calculator/tools/thermodynamique/fluides-frigorifiques.html.twig
 * Utilise : assets/calculators/refrigerants.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { co2Equivalent, explain, FAMILIES, matchesFilters, REFRIGERANTS, refrigerantsFromRows, SAFETY_CLASSES, STATUSES, statusLevel } from "../../calculators/refrigerants.js";
import { parseNumber, showResult } from "../../calculators/format.js";

/** Fluide choisi par défaut (ancien site). */
const DEFAULT_FLUID = "R410A";

/** Couleurs du statut F-Gas. */
const STATUS_CLASSES = {
    banned: "inline-flex rounded-md px-2 py-1 text-xs font-bold bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-200",
    transition: "inline-flex rounded-md px-2 py-1 text-xs font-bold bg-amber-100 text-amber-900 dark:bg-amber-950/60 dark:text-amber-100",
    future: "inline-flex rounded-md px-2 py-1 text-xs font-bold bg-emerald-100 text-emerald-900 dark:bg-emerald-950/60 dark:text-emerald-100",
};

export default class extends Controller {
    static values = { fluids: Array };
    static targets = ["fluid", "charge", "status", "gwp", "teq", "message", "filterFamily", "filterStatus", "filterSafety", "count", "tableBody"];

    /** Remplit les listes et le tableau, puis calcule. */
    connect() {
        this.refrigerants = this.fluidsValue.length > 0 ? refrigerantsFromRows(this.fluidsValue) : REFRIGERANTS;
        const selected = DEFAULT_FLUID in this.refrigerants ? DEFAULT_FLUID : Object.keys(this.refrigerants)[0];
        this.fluidTarget.replaceChildren(...Object.entries(this.refrigerants).map(([key, fluid]) => new Option(`${fluid.name} (${fluid.family})`, key, false, key === selected)));
        for (const [select, labels] of [[this.filterFamilyTarget, FAMILIES], [this.filterStatusTarget, STATUSES], [this.filterSafetyTarget, SAFETY_CLASSES]]) {
            select.append(...Object.entries(labels).map(([value, label]) => new Option(label, value)));
        }
        this.tableBodyTarget.replaceChildren(...Object.entries(this.refrigerants).map(([key, fluid]) => this.row(key, fluid)));
        this.filter();
        this.calculate();
    }

    /** PRG, statut et équivalent CO2 du fluide choisi. */
    calculate() {
        const fluid = this.refrigerants[this.fluidTarget.value];
        const charge = parseNumber(this.chargeTarget.value);
        this.gwpTarget.textContent = `${fluid.gwp} (${fluid.gwpReport})`;
        this.statusTarget.textContent = fluid.status;
        this.statusTarget.className = STATUS_CLASSES[statusLevel(fluid.status)];
        showResult(this.teqTarget, co2Equivalent(charge, fluid.gwp), 3, this.messageTarget, explain("charge", charge));
    }

    /** Masque les lignes qui ne passent pas les filtres. */
    filter() {
        const filters = { family: this.filterFamilyTarget.value, status: this.filterStatusTarget.value, safety: this.filterSafetyTarget.value };
        let shown = 0;
        for (const row of this.tableBodyTarget.rows) {
            row.hidden = !matchesFilters(this.refrigerants[row.dataset.key], filters);
            shown += row.hidden ? 0 : 1;
        }
        this.countTarget.textContent = `${shown} fluide(s) sur ${this.tableBodyTarget.rows.length}`;
    }

    /** Ligne du tableau d'un fluide. */
    row(key, fluid) {
        const tr = document.createElement("tr");
        tr.dataset.key = key;
        const cells = [fluid.name, fluid.family, `${fluid.gwp} (${fluid.gwpReport})`, fluid.status, fluid.class, fluid.application, fluid.replacement, fluid.oil];
        cells.forEach((text, index) => {
            const cell = document.createElement(index === 0 ? "th" : "td");
            if (index === 0) {
                cell.scope = "row";
            }
            cell.className = index === 0 ? "px-3 py-2 text-left font-bold text-secondary-900 dark:text-white" : "px-3 py-2 text-secondary-700 dark:text-secondary-300";
            if (index === 3) {
                const badge = document.createElement("span");
                badge.className = STATUS_CLASSES[statusLevel(fluid.status)];
                badge.textContent = text;
                cell.append(badge);
            } else {
                cell.textContent = text;
            }
            tr.append(cell);
        });

        return tr;
    }
}
