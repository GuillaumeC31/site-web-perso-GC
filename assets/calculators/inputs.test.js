/*
 * Tests unitaires (Vitest) du contrôle des saisies : assets/calculators/inputs.js.
 * Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import { checkInputs } from "./inputs.js";

describe("checkInputs", () => {
    it("accepte des valeurs valides", () => {
        expect(checkInputs([{ value: 230, label: "Tension (U)", rule: "positive" }, { value: 0.8, label: "cos φ", rule: "cosPhi" }])).toEqual({ ok: true, missing: false, error: null });
    });

    it("signale un champ vide sans message d'erreur", () => {
        expect(checkInputs([{ value: Number.NaN, label: "Tension (U)", rule: "positive" }])).toEqual({ ok: false, missing: true, error: null });
    });

    it("refuse une valeur négative ou nulle avec un message clair", () => {
        expect(checkInputs([{ value: -230, label: "Tension (U)", rule: "positive" }]).error).toBe("Tension (U) : la valeur doit être strictement positive.");
        expect(checkInputs([{ value: 0, label: "Longueur (L)", rule: "positive" }]).ok).toBe(false);
        expect(checkInputs([{ value: 0, label: "Courant (I)", rule: "nonNegative" }]).ok).toBe(true);
    });

    it("contrôle le cos φ et les nombres entiers", () => {
        expect(checkInputs([{ value: 1.2, label: "cos φ", rule: "cosPhi" }]).error).toBe("cos φ : la valeur doit être comprise entre 0 et 1.");
        expect(checkInputs([{ value: 0, label: "cos φ", rule: "cosPhiNonZero" }]).ok).toBe(false);
        expect(checkInputs([{ value: 2.7, label: "Nombre de conducteurs", rule: "integerMin1" }]).error).toBe("Nombre de conducteurs : la valeur doit être un nombre entier, au moins 1.");
        expect(checkInputs([{ value: 3, label: "Nombre de conducteurs", rule: "integerMin1" }]).ok).toBe(true);
    });

    it("donne la priorité à une valeur invalide sur un champ vide", () => {
        const result = checkInputs([{ value: Number.NaN, label: "A", rule: "positive" }, { value: -1, label: "B", rule: "positive" }]);
        expect(result.error).toBe("B : la valeur doit être strictement positive.");
        expect(result.missing).toBe(true);
    });
});
