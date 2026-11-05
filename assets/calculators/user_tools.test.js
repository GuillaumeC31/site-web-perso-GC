/*
 * Tests unitaires (Vitest) des favoris, de l'effacement des anciens outils récents et de la recherche : assets/calculators/user_tools.js.
 * Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import { FAVORITES_KEY, forgetRecents, LEGACY_RECENTS_KEY, matchesSearch, readList, toggleFavorite } from "./user_tools.js";

/** Stockage en mémoire, comme localStorage. */
function memoryStorage(initial = {}) {
    const data = { ...initial };

    return {
        getItem: (key) => (key in data ? data[key] : null),
        setItem: (key, value) => {
            data[key] = String(value);
        },
        removeItem: (key) => {
            delete data[key];
        },
    };
}

describe("favoris", () => {
    it("ajoute puis retire un outil", () => {
        const storage = memoryStorage();
        expect(toggleFavorite(storage, "electricite/loi-ohm")).toBe(true);
        expect(readList(storage, FAVORITES_KEY)).toEqual(["electricite/loi-ohm"]);
        expect(toggleFavorite(storage, "electricite/loi-ohm")).toBe(false);
        expect(readList(storage, FAVORITES_KEY)).toEqual([]);
    });

    it("ignore un contenu de stockage invalide ou malveillant", () => {
        expect(readList(memoryStorage({ [FAVORITES_KEY]: "pas du JSON" }), FAVORITES_KEY)).toEqual([]);
        expect(readList(memoryStorage({ [FAVORITES_KEY]: JSON.stringify(["electricite/loi-ohm", "<img onerror=x>", 42]) }), FAVORITES_KEY)).toEqual(["electricite/loi-ohm"]);
    });

    it("fonctionne sans stockage disponible", () => {
        const broken = {
            getItem: () => {
                throw new Error("interdit");
            },
            setItem: () => {
                throw new Error("interdit");
            },
        };
        expect(readList(broken, FAVORITES_KEY)).toEqual([]);
        expect(toggleFavorite(broken, "electricite/loi-ohm")).toBe(true);
        expect(readList(null, FAVORITES_KEY)).toEqual([]);
    });
});

describe("anciens outils récents", () => {
    it("efface la clé laissée par l'ancienne version, sans toucher aux favoris", () => {
        const storage = memoryStorage({ [LEGACY_RECENTS_KEY]: '["electricite/loi-ohm"]', [FAVORITES_KEY]: '["electricite/steinmetz"]' });
        forgetRecents(storage);
        expect(storage.getItem(LEGACY_RECENTS_KEY)).toBeNull();
        expect(readList(storage, FAVORITES_KEY)).toEqual(["electricite/steinmetz"]);
    });

    it("reste silencieux si le stockage est indisponible", () => {
        expect(() => forgetRecents(null)).not.toThrow();
        expect(() => forgetRecents({ removeItem: () => { throw new Error("interdit"); } })).not.toThrow();
    });
});

describe("recherche", () => {
    it("trouve sans tenir compte des accents ni de la casse", () => {
        expect(matchesSearch("electricite", "Électricité · Loi d'Ohm")).toBe(true);
        expect(matchesSearch("CHUTE tension", "Chute de tension d'une ligne")).toBe(true);
    });

    it("exige tous les mots saisis", () => {
        expect(matchesSearch("chute cuivre", "Chute de tension d'une ligne")).toBe(false);
    });

    it("accepte une recherche vide", () => {
        expect(matchesSearch("   ", "n'importe quoi")).toBe(true);
    });
});
