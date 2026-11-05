/*
 * Nonce CSP stable pour toute la durée d'une page, malgré les navigations Turbo.
 *
 * Problème : le navigateur applique la CSP de la PREMIÈRE page chargée tant que Turbo remplace le contenu.
 * Les scripts en ligne d'une page obtenue par Turbo portent le nonce de leur propre réponse, refusé par
 * cette CSP (erreur « Executing inline script violates… » dans la console).
 * Solution (comme Rails avec Turbo) : chaque requête Turbo renvoie au serveur le nonce de la page
 * d'origine (en-tête X-Csp-Nonce), que le serveur réutilise (App\Security\CspNonceGenerator). En
 * développement, les nonces de la barre de débogage de Symfony sont renvoyés de la même façon
 * (en-têtes X-SymfonyProfiler-*-Nonce, lus par le WebProfilerBundle).
 * Le nonce reste secret (tiré au hasard par le serveur au premier chargement) ; un autre site ne peut pas
 * envoyer ces en-têtes (pré-vérification CORS).
 *
 * Utilisé par : assets/app.js (installCspNonceBridge(document))
 * Testé par : assets/lib/csp_nonce.test.js
 */

/** Nonce de la page d'origine (balise <meta name="csp-nonce">), ou null. */
export function readPageNonce(doc) {
    return doc.querySelector('meta[name="csp-nonce"]')?.getAttribute("content") || null;
}

/**
 * Nonces de la barre de débogage de Symfony (développement seulement) : celui de son script « Sfjs ».
 * Le profileur n'accepte les nonces transmis que par paire (script et style) ; la barre n'a pas de style
 * avec nonce (style-src du site : 'unsafe-inline'), le nonce du script sert donc aux deux.
 * Renvoie null si la barre est absente (production).
 */
export function readProfilerNonces(doc) {
    const script = [...doc.querySelectorAll("body script")].find((element) => element.nonce && element.textContent.includes("Sfjs"));
    if (!script) {
        return null;
    }

    return { script: script.nonce, style: script.nonce };
}

/** En-têtes à joindre aux requêtes Turbo pour garder les nonces de la page d'origine. */
export function nonceHeaders(pageNonce, profilerNonces) {
    const headers = {};
    if (pageNonce) {
        headers["X-Csp-Nonce"] = pageNonce;
    }
    if (profilerNonces) {
        headers["X-SymfonyProfiler-Script-Nonce"] = profilerNonces.script;
        headers["X-SymfonyProfiler-Style-Nonce"] = profilerNonces.style;
    }

    return headers;
}

/**
 * Lit les nonces une fois, au chargement complet de la page, puis les joint à chaque requête Turbo
 * (évènement turbo:before-fetch-request). Les nonces ne sont jamais relus après une navigation Turbo.
 */
export function installCspNonceBridge(doc) {
    const pageNonce = readPageNonce(doc);
    let profilerNonces = null;
    const readProfiler = () => {
        profilerNonces ??= readProfilerNonces(doc);
    };
    if (doc.readyState === "loading") {
        doc.addEventListener("DOMContentLoaded", readProfiler, { once: true });
    } else {
        readProfiler();
    }
    doc.addEventListener("turbo:before-fetch-request", (event) => {
        readProfiler();
        Object.assign(event.detail.fetchOptions.headers, nonceHeaders(pageNonce, profilerNonces));
    });
}
