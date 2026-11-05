/*
 * Contrôleur Stimulus « calculator--puissances-ac » : sept calculs indépendants de puissances
 * en monophasé (P, I, U ; S, I, U ; Q), résultats à 2 décimales comme sur l'ancien site.
 * Une saisie refusée (négative, nulle sans signification, cos φ hors de [0 ; 1]) est expliquée
 * sous le bloc (anomalie A3).
 *
 * Utilisé par : templates/calculator/tools/electricite/puissances-ac.html.twig
 * Utilise : assets/calculators/electricite.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import * as electricity from "../../calculators/electricite.js";
import { parseNumber, showResult } from "../../calculators/format.js";

/** Bloc : calcul, champs lus (dans l'ordre des arguments), résultat et message. */
const BLOCKS = {
    activePower: { fields: ["activeU", "activeI", "activeCos"], result: "activeResult", message: "activeMessage" },
    currentFromActivePower: { fields: ["currentP", "currentU", "currentCos"], result: "currentResult", message: "currentMessage" },
    voltageFromActivePower: { fields: ["voltageP", "voltageI", "voltageCos"], result: "voltageResult", message: "voltageMessage" },
    apparentPower: { fields: ["apparentU", "apparentI"], result: "apparentResult", message: "apparentMessage" },
    currentFromApparentPower: { fields: ["apparentCurrentS", "apparentCurrentU"], result: "apparentCurrentResult", message: "apparentCurrentMessage" },
    voltageFromApparentPower: { fields: ["apparentVoltageS", "apparentVoltageI"], result: "apparentVoltageResult", message: "apparentVoltageMessage" },
    reactivePower: { fields: ["reactiveU", "reactiveI", "reactiveCos"], result: "reactiveResult", message: "reactiveMessage" },
};

export default class extends Controller {
    static targets = Object.values(BLOCKS).flatMap((block) => [...block.fields, block.result, block.message]);

    /** P = U × I × cos φ */
    activePower() {
        this.run("activePower");
    }

    /** I = P / (U × cos φ) */
    currentFromActive() {
        this.run("currentFromActivePower");
    }

    /** U = P / (I × cos φ) */
    voltageFromActive() {
        this.run("voltageFromActivePower");
    }

    /** S = U × I */
    apparentPower() {
        this.run("apparentPower");
    }

    /** I = S / U */
    currentFromApparent() {
        this.run("currentFromApparentPower");
    }

    /** U = S / I */
    voltageFromApparent() {
        this.run("voltageFromApparentPower");
    }

    /** Q = U × I × sin φ */
    reactivePower() {
        this.run("reactivePower");
    }

    /** Lit les champs d'un bloc, calcule et affiche le résultat ou le message. */
    run(name) {
        const block = BLOCKS[name];
        const values = block.fields.map((field) => parseNumber(this[`${field}Target`].value));
        showResult(this[`${block.result}Target`], electricity[name](...values), electricity.DECIMALS.power, this[`${block.message}Target`], electricity.explain(name, ...values));
    }
}
