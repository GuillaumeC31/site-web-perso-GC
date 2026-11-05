// @vitest-environment jsdom
/*
 * Tests du pont de nonce CSP pour Turbo (assets/lib/csp_nonce.js).
 */
import { describe, expect, it } from "vitest";
import { installCspNonceBridge, nonceHeaders, readPageNonce, readProfilerNonces } from "./csp_nonce.js";

/** Crée un document avec la balise meta du nonce et, au besoin, la barre de débogage. */
function makeDocument({ nonce = "Abc123+/Abc123+/Abc12w==", profiler = false } = {}) {
    const doc = document.implementation.createHTMLDocument("test");
    doc.head.innerHTML = nonce ? `<meta name="csp-nonce" content="${nonce}">` : "";
    if (profiler) {
        const script = doc.createElement("script");
        script.nonce = "scriptNonce";
        script.textContent = "if (typeof Sfjs === 'undefined') {}";
        doc.body.append(script);
    }

    return doc;
}

describe("nonce CSP stable sous Turbo", () => {
    it("lit le nonce de la page", () => {
        expect(readPageNonce(makeDocument())).toBe("Abc123+/Abc123+/Abc12w==");
        expect(readPageNonce(makeDocument({ nonce: "" }))).toBeNull();
    });

    it("lit les nonces de la barre de débogage, seulement si elle est là", () => {
        expect(readProfilerNonces(makeDocument())).toBeNull();
        expect(readProfilerNonces(makeDocument({ profiler: true }))).toEqual({ script: "scriptNonce", style: "scriptNonce" });
    });

    it("construit les en-têtes", () => {
        expect(nonceHeaders("n", null)).toEqual({ "X-Csp-Nonce": "n" });
        expect(nonceHeaders(null, null)).toEqual({});
        expect(nonceHeaders("n", { script: "s", style: "t" })).toEqual({
            "X-Csp-Nonce": "n",
            "X-SymfonyProfiler-Script-Nonce": "s",
            "X-SymfonyProfiler-Style-Nonce": "t",
        });
    });

    it("joint les nonces de la page d'ORIGINE à chaque requête Turbo, même après un changement de la balise meta", () => {
        const doc = makeDocument({ profiler: true });
        installCspNonceBridge(doc);
        doc.querySelector('meta[name="csp-nonce"]').setAttribute("content", "autre");

        const event = new Event("turbo:before-fetch-request");
        event.detail = { fetchOptions: { headers: { Accept: "text/html" } } };
        doc.dispatchEvent(event);

        expect(event.detail.fetchOptions.headers).toEqual({
            Accept: "text/html",
            "X-Csp-Nonce": "Abc123+/Abc123+/Abc12w==",
            "X-SymfonyProfiler-Script-Nonce": "scriptNonce",
            "X-SymfonyProfiler-Style-Nonce": "scriptNonce",
        });
    });
});
