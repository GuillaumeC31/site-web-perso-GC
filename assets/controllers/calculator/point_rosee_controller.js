/*
 * Contrôleur Stimulus « calculator--point-rosee » : psychrométrie selon trois méthodes.
 *
 * Utilisé par : templates/calculator/tools/thermodynamique/point-rosee.html.twig
 * Utilise : assets/calculators/thermodynamique.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, explain, fromDewPoint, fromRelativeHumidity, fromWetBulb } from "../../calculators/thermodynamique.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = [
        "rhTemperature", "rhHumidity", "rhDewPoint", "rhAbsolute", "rhVapour", "rhMessage",
        "dewTemperature", "dewDewPoint", "dewHumidity", "dewAbsolute", "dewMessage",
        "wetDry", "wetWet", "wetPressure", "wetHumidity", "wetDewPoint", "wetAbsolute", "wetMessage",
    ];

    /** Méthode 1 : température et humidité relative. */
    fromRelativeHumidity() {
        const args = [this.rhTemperatureTarget, this.rhHumidityTarget].map((target) => parseNumber(target.value));
        const result = fromRelativeHumidity(...args);
        showResult(this.rhDewPointTarget, result?.dewPoint ?? null, DECIMALS.temperature, this.rhMessageTarget, explain("fromRelativeHumidity", ...args));
        showResult(this.rhAbsoluteTarget, result?.absoluteHumidity ?? null, DECIMALS.humidity);
        showResult(this.rhVapourTarget, result?.vapourPressure ?? null, DECIMALS.vapourPressure);
    }

    /** Méthode 2 : température et point de rosée. */
    fromDewPoint() {
        const args = [this.dewTemperatureTarget, this.dewDewPointTarget].map((target) => parseNumber(target.value));
        const result = fromDewPoint(...args);
        showResult(this.dewHumidityTarget, result?.relativeHumidity ?? null, DECIMALS.relativeHumidity, this.dewMessageTarget, explain("fromDewPoint", ...args));
        showResult(this.dewAbsoluteTarget, result?.absoluteHumidity ?? null, DECIMALS.humidity);
    }

    /** Méthode 3 : psychromètre (bulbe sec, bulbe humide, pression). */
    fromWetBulb() {
        const args = [this.wetDryTarget, this.wetWetTarget, this.wetPressureTarget].map((target) => parseNumber(target.value));
        const result = fromWetBulb(...args);
        showResult(this.wetHumidityTarget, result?.relativeHumidity ?? null, DECIMALS.relativeHumidity, this.wetMessageTarget, explain("fromWetBulb", ...args));
        showResult(this.wetDewPointTarget, result?.dewPoint ?? null, DECIMALS.temperature);
        showResult(this.wetAbsoluteTarget, result?.absoluteHumidity ?? null, DECIMALS.humidity);
    }
}
