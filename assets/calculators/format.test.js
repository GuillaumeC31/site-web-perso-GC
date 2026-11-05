/*
 * Tests unitaires (Vitest) de la lecture et de l'affichage des nombres : assets/calculators/format.js.
 * Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import { formatNumber, parseNumber, roundTo } from "./format.js";

describe("parseNumber", () => {
    it("accepte le point et la virgule décimale", () => {
        expect(parseNumber("2.5")).toBe(2.5);
        expect(parseNumber("2,5")).toBe(2.5);
        expect(parseNumber(" 1 250,75 ")).toBe(1250.75);
    });

    it("renvoie NaN pour un champ vide ou invalide", () => {
        expect(parseNumber("")).toBeNaN();
        expect(parseNumber(null)).toBeNaN();
        expect(parseNumber("abc")).toBeNaN();
    });
});

describe("formatNumber", () => {
    it("affiche en français avec un nombre fixe de décimales", () => {
        // Espace insécable fine entre les milliers (norme française d'Intl)
        expect(formatNumber(1840, 2).replace(/\s/g, " ")).toBe("1 840,00");
        expect(formatNumber(0.4375, 3)).toBe("0,438");
    });

    it("n'affiche rien sans résultat", () => {
        expect(formatNumber(null, 2)).toBe("");
        expect(formatNumber(Number.POSITIVE_INFINITY, 2)).toBe("");
        expect(formatNumber(Number.NaN, 2)).toBe("");
    });
});

describe("roundTo", () => {
    it("arrondit comme toFixed", () => {
        expect(roundTo(1548.042, 2)).toBe(1548.04);
        expect(roundTo(326.08, 0)).toBe(326);
    });
});
