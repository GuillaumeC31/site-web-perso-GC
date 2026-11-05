/*
 * Tests unitaires (Vitest) de la logique du mode sombre : assets/lib/theme.js.
 * Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import { resolveTheme, toggleTheme } from "./theme.js";

describe("resolveTheme", () => {
    it("respecte le choix enregistré, quelle que soit la préférence système", () => {
        expect(resolveTheme("light", true)).toBe("light");
        expect(resolveTheme("dark", false)).toBe("dark");
    });

    it("suit la préférence système sans choix enregistré", () => {
        expect(resolveTheme(null, true)).toBe("dark");
        expect(resolveTheme(null, false)).toBe("light");
    });

    it("ignore une valeur enregistrée inconnue", () => {
        expect(resolveTheme("violet", true)).toBe("dark");
    });
});

describe("toggleTheme", () => {
    it("bascule entre clair et sombre", () => {
        expect(toggleTheme("light")).toBe("dark");
        expect(toggleTheme("dark")).toBe("light");
    });
});
