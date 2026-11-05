/*
 * Contrôleur Stimulus « calculator--vieillissement-uv » : dose UV, durée d'exposition, durée de l'essai.
 * Affichage sans zéros inutiles (2 décimales au plus pour la dose, 1 pour les durées), comme l'ancien site.
 *
 * Utilisé par : templates/calculator/tools/eclairage/vieillissement-uv.html.twig
 * Utilise : assets/calculators/eclairage.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { explain, machineHours, UV_LAMPS, uvDose, uvHoursForDose } from "../../calculators/eclairage.js";
import { parseNumber } from "../../calculators/format.js";

/** Nombre en français, sans zéros inutiles. */
const plain = (value, digits) => new Intl.NumberFormat("fr-FR", { maximumFractionDigits: digits }).format(value);

export default class extends Controller {
    static targets = [
        "lamp", "wavelength", "doseIrradiance", "doseHours", "dose", "doseMj", "doseMessage",
        "timeDose", "timeIrradiance", "timeHours", "timeDays", "timeMessage",
        "cycleUv", "cycleDark", "cycleTarget", "machineHours", "machineDays", "cycleMessage",
    ];

    /** Calcule les trois blocs avec les valeurs par défaut. */
    connect() {
        this.calcDose();
        this.calcTime();
        this.calcCycles();
    }

    /** Lampe choisie : longueur d'onde de contrôle et irradiance courante dans les deux blocs (D3). */
    chooseLamp() {
        const lamp = UV_LAMPS[this.lampTarget.value];
        this.wavelengthTarget.textContent = String(lamp.wavelength);
        for (const target of [this.doseIrradianceTarget, this.timeIrradianceTarget]) {
            target.value = plain(lamp.irradiance, 2);
        }
        this.calcDose();
        this.calcTime();
    }

    /** Dose atteinte. */
    calcDose() {
        const args = [this.doseIrradianceTarget, this.doseHoursTarget].map((target) => parseNumber(target.value));
        const dose = uvDose(...args);
        this.show([[this.doseTarget, dose, 2], [this.doseMjTarget, dose === null ? null : dose / 1000, 2]], this.doseMessageTarget, explain("uvDose", ...args));
    }

    /** Durée d'exposition pour la dose visée. */
    calcTime() {
        const args = [this.timeDoseTarget, this.timeIrradianceTarget].map((target) => parseNumber(target.value));
        const hours = uvHoursForDose(...args);
        this.show([[this.timeHoursTarget, hours, 1], [this.timeDaysTarget, hours === null ? null : hours / 24, 1]], this.timeMessageTarget, explain("uvTime", ...args));
    }

    /** Durée réelle de l'essai avec les cycles. */
    calcCycles() {
        const args = [this.cycleUvTarget, this.cycleDarkTarget, this.cycleTargetTarget].map((target) => parseNumber(target.value));
        const hours = machineHours(...args);
        this.show([[this.machineHoursTarget, hours, 1], [this.machineDaysTarget, hours === null ? null : hours / 24, 1]], this.cycleMessageTarget, explain("uvCycles", ...args));
    }

    /** Affiche des résultats (ou « — ») et le message d'erreur. */
    show(results, message, error) {
        for (const [output, value, digits] of results) {
            output.textContent = value === null || error !== null ? "—" : plain(value, digits);
        }
        message.hidden = error === null;
        message.textContent = error ?? "";
    }
}
