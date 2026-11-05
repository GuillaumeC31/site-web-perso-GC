/*
 * Tests unitaires (Vitest) de la barre d'outils Markdown : assets/lib/markdown_format.js.
 * Lancement : npm test
 */
import { describe, expect, it } from "vitest";
import { applyMarkdownFormat } from "./markdown_format.js";

describe("applyMarkdownFormat", () => {
    it("met la sélection en gras et la garde sélectionnée", () => {
        const result = applyMarkdownFormat("un mot ici", 3, 6, "bold");
        expect(result.text).toBe("un **mot** ici");
        expect(result.text.slice(result.start, result.end)).toBe("mot");
    });

    it("propose un texte quand rien n'est sélectionné", () => {
        const result = applyMarkdownFormat("", 0, 0, "italic");
        expect(result.text).toBe("*texte en italique*");
        expect(result.text.slice(result.start, result.end)).toBe("texte en italique");
    });

    it("crée un lien et sélectionne l'adresse à remplacer", () => {
        const result = applyMarkdownFormat("voir le site", 8, 12, "link");
        expect(result.text).toBe("voir le [site](https://)");
        expect(result.text.slice(result.start, result.end)).toBe("https://");
    });

    it("transforme les lignes sélectionnées en liste", () => {
        const text = "avant\nun\ndeux\naprès";
        const result = applyMarkdownFormat(text, 7, 12, "list");
        expect(result.text).toBe("avant\n- un\n- deux\naprès");
    });

    it("numérote une liste ordonnée", () => {
        const result = applyMarkdownFormat("un\ndeux", 0, 7, "orderedList");
        expect(result.text).toBe("1. un\n2. deux");
    });

    it("remplace un titre existant au lieu de l'empiler", () => {
        const result = applyMarkdownFormat("## Contexte", 3, 3, "heading3");
        expect(result.text).toBe("### Contexte");
    });

    it("ignore une action inconnue", () => {
        expect(applyMarkdownFormat("texte", 0, 5, "inconnue")).toEqual({ text: "texte", start: 0, end: 5 });
    });
});
