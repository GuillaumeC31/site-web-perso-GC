/*
 * Tests de assets/lib/turbo_frame_missing.js : une réponse sans la Turbo Frame attendue s'affiche en page
 * complète (jamais « Content missing »).
 */
import { describe, expect, it, vi } from "vitest";
import { handleFrameMissing, installFrameMissingFallback } from "./turbo_frame_missing.js";

describe("handleFrameMissing", () => {
    it("annule l'affichage « Content missing » et visite la réponse reçue", () => {
        const response = { status: 302 };
        const visit = vi.fn();
        const event = { detail: { response, visit }, preventDefault: vi.fn() };

        expect(handleFrameMissing(event)).toBe(true);
        expect(event.preventDefault).toHaveBeenCalledOnce();
        expect(visit).toHaveBeenCalledWith(response);
    });

    it("laisse passer un évènement incomplet", () => {
        const event = { detail: {}, preventDefault: vi.fn() };

        expect(handleFrameMissing(event)).toBe(false);
        expect(event.preventDefault).not.toHaveBeenCalled();
        expect(handleFrameMissing(undefined)).toBe(false);
    });
});

describe("installFrameMissingFallback", () => {
    it("écoute « turbo:frame-missing » une seule fois", () => {
        const doc = { documentElement: { dataset: {} }, addEventListener: vi.fn() };

        installFrameMissingFallback(doc);
        installFrameMissingFallback(doc);

        expect(doc.addEventListener).toHaveBeenCalledOnce();
        expect(doc.addEventListener).toHaveBeenCalledWith("turbo:frame-missing", handleFrameMissing);
    });
});
