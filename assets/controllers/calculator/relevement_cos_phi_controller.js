/*
 * Contrôleur Stimulus « calculator--relevement-cos-phi » : batterie de condensateurs pour relever
 * le cos φ d'un réseau triphasé (Qc en kvar à 2 décimales, capacité par phase en étoile et en
 * triangle à 1 décimale, anomalie A5). Saisies invalides expliquées (A3) ; information si la cible
 * est déjà atteinte.
 *
 * Utilisé par : templates/calculator/tools/electricite/relevement-cos-phi.html.twig
 * Utilise : assets/calculators/electricite.js (powerFactorCorrection), assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, explain, powerFactorCorrection } from "../../calculators/electricite.js";
import { parseNumber, showResult } from "../../calculators/format.js";

/** Message quand le cos φ initial atteint déjà la cible. */
const ALREADY_REACHED = "Aucune compensation nécessaire : le cos φ initial atteint déjà la cible.";

export default class extends Controller {
    static targets = ["power", "voltage", "frequency", "initial", "target", "reactive", "capacitanceStar", "capacitanceDelta", "message"];

    /** Calcule dès l'affichage (valeurs par défaut) et à chaque saisie. */
    connect() {
        this.calculate();
    }

    /** Recalcule la batterie de condensateurs. */
    calculate() {
        const values = [this.powerTarget, this.voltageTarget, this.frequencyTarget, this.initialTarget, this.targetTarget].map((target) => parseNumber(target.value));
        const result = powerFactorCorrection(...values);
        const error = explain("powerFactorCorrection", ...values);
        const info = result?.alreadyReached ? ALREADY_REACHED : null;

        showResult(this.reactiveTarget, result?.reactivePower ?? null, DECIMALS.reactivePowerKvar, this.messageTarget, error ?? info, error !== null);
        showResult(this.capacitanceStarTarget, error ? null : (result?.capacitanceStar ?? null), DECIMALS.capacitanceMicrofarad);
        showResult(this.capacitanceDeltaTarget, error ? null : (result?.capacitanceDelta ?? null), DECIMALS.capacitanceMicrofarad);
    }
}
