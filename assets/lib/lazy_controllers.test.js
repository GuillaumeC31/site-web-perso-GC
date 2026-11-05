/*
 * Tests unitaires (Vitest) du chargement à la demande des contrôleurs : assets/lib/lazy_controllers.js.
 * Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import { lazyIdentifiersToLoad } from "./lazy_controllers.js";

describe("lazyIdentifiersToLoad", () => {
    const available = new Set(["calculator--loi-ohm", "calculator--tool", "calculator--dashboard"]);

    it("retient les contrôleurs différés présents dans la page", () => {
        expect(lazyIdentifiersToLoad(["theme", "calculator--tool calculator--loi-ohm"], available, new Set())).toEqual(["calculator--tool", "calculator--loi-ohm"]);
    });

    it("ignore ceux déjà enregistrés ou en cours de chargement, et les doublons", () => {
        expect(lazyIdentifiersToLoad(["calculator--tool", "calculator--tool", "calculator--loi-ohm"], available, new Set(["calculator--loi-ohm"]))).toEqual(["calculator--tool"]);
    });

    it("ignore les contrôleurs inconnus et les attributs vides", () => {
        expect(lazyIdentifiersToLoad(["", null, "disclosure", "calculator--inconnu"], available, new Set())).toEqual([]);
    });
});
