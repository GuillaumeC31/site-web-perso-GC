/*
 * Tests de l'adresse e-mail protégée (assets/lib/protected_email.js).
 */
import { describe, expect, it } from "vitest";
import { buildAddress, unreverse } from "./protected_email.js";

describe("adresse e-mail protégée", () => {
    it("remet une chaîne à l'endroit", () => {
        expect(unreverse("tset.elpmaxe")).toBe("guillaume-couget.fr");
    });

    it("reconstitue l'adresse à partir des deux parties inversées", () => {
        expect(buildAddress("dpgr", "tset.elpmaxe")).toBe("rgpd@example.test");
    });

    it("refuse une partie vide ou invalide", () => {
        expect(buildAddress("", "rf.elpmexe")).toBeNull();
        expect(buildAddress("dpgr", "")).toBeNull();
        expect(buildAddress("dpgr", "elpmexe")).toBeNull();
        expect(buildAddress("<tpircs>", "rf.elpmexe")).toBeNull();
    });
});
