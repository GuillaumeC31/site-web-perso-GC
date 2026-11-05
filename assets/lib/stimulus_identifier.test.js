/*
 * Tests unitaires (Vitest) du calcul des identifiants Stimulus : assets/lib/stimulus_identifier.js.
 * Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import { controllerIdentifier } from "./stimulus_identifier.js";

describe("controllerIdentifier", () => {
    it("retire le suffixe _controller", () => {
        expect(controllerIdentifier("./controllers/theme_controller.js")).toBe("theme");
        expect(controllerIdentifier("./controllers/webauthn_controller.js")).toBe("webauthn");
    });

    it("remplace les soulignés par des tirets", () => {
        expect(controllerIdentifier("./controllers/admin_menu_controller.js")).toBe("admin-menu");
    });

    it("gère les sous-dossiers comme Stimulus (deux tirets)", () => {
        expect(controllerIdentifier("./controllers/admin/menu_controller.js")).toBe("admin--menu");
    });

    it("refuse un fichier qui n'est pas un contrôleur", () => {
        expect(controllerIdentifier("./controllers/helpers.js")).toBeNull();
        expect(controllerIdentifier("C:\\projet\\assets\\controllers\\theme_controller.js")).toBeNull();
    });
});
