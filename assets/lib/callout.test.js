/*
 * Encadrés d'alerte et de conseil : titre, rôle et attributs.
 * Lancement : npm test
 *
 * @vitest-environment jsdom
 */
import { describe, expect, it } from "vitest";
import { createCallout } from "./callout.js";

describe("Encadrés de la calculatrice", () => {
    it("crée un avertissement annoncé (role alert) avec son titre et ses attributs", () => {
        const p = createCallout("warning", "Charge dépassée.", { chargeWarning: "" });
        expect(p.getAttribute("role")).toBe("alert");
        expect(p.textContent).toBe("Attention : Charge dépassée.");
        expect(p.dataset.callout).toBe("warning");
        expect("chargeWarning" in p.dataset).toBe(true);
    });

    it("crée un conseil sans role alert", () => {
        const p = createCallout("info", "Évaporation au-dessus du point de rosée.");
        expect(p.hasAttribute("role")).toBe(false);
        expect(p.textContent).toContain("Conseil : ");
        expect(p.className).toContain("bg-primary-50");
    });

    it("crée une alerte courte sans titre", () => {
        expect(createCallout("warning", "⚠ Charge de fluide au-delà de la limite", {}, false).textContent).toBe("⚠ Charge de fluide au-delà de la limite");
    });
});
