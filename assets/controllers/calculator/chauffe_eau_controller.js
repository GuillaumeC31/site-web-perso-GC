/*
 * Contrôleur Stimulus « calculator--chauffe-eau » : chauffe d'un volume d'eau, durée ou puissance.
 *
 * Utilisé par : templates/calculator/tools/thermodynamique/chauffe-eau.html.twig
 * Utilise : assets/calculators/thermodynamique.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, explain, heatingHours, heatingWatts, waterEnergyKwh } from "../../calculators/thermodynamique.js";
import { parseNumber, showResult } from "../../calculators/format.js";

export default class extends Controller {
    static targets = ["timeVolume", "timeStart", "timeEnd", "timePower", "timeEnergy", "timeHours", "timeMessage", "powerVolume", "powerStart", "powerEnd", "powerHours", "powerEnergy", "powerWatts", "powerMessage"];

    /** Temps de chauffe selon la puissance. */
    calculateTime() {
        const water = [this.timeVolumeTarget, this.timeStartTarget, this.timeEndTarget].map((target) => parseNumber(target.value));
        const power = parseNumber(this.timePowerTarget.value);
        const energy = waterEnergyKwh(...water);
        showResult(this.timeEnergyTarget, energy, DECIMALS.energy);
        showResult(this.timeHoursTarget, heatingHours(energy, power), DECIMALS.hours, this.timeMessageTarget, explain("waterEnergy", ...water) ?? explain("heatingPower", power));
    }

    /** Puissance selon le temps de chauffe souhaité. */
    calculatePower() {
        const water = [this.powerVolumeTarget, this.powerStartTarget, this.powerEndTarget].map((target) => parseNumber(target.value));
        const hours = parseNumber(this.powerHoursTarget.value);
        const energy = waterEnergyKwh(...water);
        showResult(this.powerEnergyTarget, energy, DECIMALS.energy);
        showResult(this.powerWattsTarget, heatingWatts(energy, hours), DECIMALS.power, this.powerMessageTarget, explain("waterEnergy", ...water) ?? explain("heatingTime", hours));
    }
}
