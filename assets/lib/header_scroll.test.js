/*
 * Tests de l'état de l'en-tête fixe (assets/lib/header_scroll.js). Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import { nextHeaderState } from "./header_scroll.js";

/** Rejoue une suite de positions de défilement. */
function replay(positions, { mobile = true, pinned = false } = {}) {
    let state = { hidden: false, compact: false, anchorY: 0 };
    return positions.map((y) => {
        state = nextHeaderState({ y, anchorY: state.anchorY, hidden: state.hidden, mobile, pinned });
        return state.hidden;
    });
}

describe("En-tête fixe", () => {
    it("ordinateur : jamais masqué, réduit dès que la page défile", () => {
        expect(replay([0, 300, 600, 200], { mobile: false })).toEqual([false, false, false, false]);
        expect(nextHeaderState({ y: 10, anchorY: 0, hidden: false, mobile: false, pinned: false }).compact).toBe(false);
        expect(nextHeaderState({ y: 200, anchorY: 0, hidden: false, mobile: false, pinned: false }).compact).toBe(true);
    });

    it("mobile : masqué en descendant, réaffiché en remontant", () => {
        expect(replay([0, 100, 200, 400, 390, 370, 500])).toEqual([false, true, true, true, false, false, true]);
    });

    it("mobile : visible en haut de page, menu ouvert ou focus dans l'en-tête", () => {
        expect(replay([0, 50, 70])).toEqual([false, false, false]);
        expect(replay([0, 200, 400], { pinned: true })).toEqual([false, false, false]);
    });

    it("ignore les petits mouvements", () => {
        expect(replay([0, 200, 205, 400, 395])).toEqual([false, true, true, true, true]);
    });
});
