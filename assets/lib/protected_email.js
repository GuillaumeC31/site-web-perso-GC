/*
 * Adresse e-mail protégée contre les robots (lot 8) : l'adresse n'est jamais écrite en clair dans le
 * HTML ; ses deux parties y sont stockées à l'envers, et le navigateur la reconstitue.
 *
 * Utilisé par : assets/controllers/protected_email_controller.js
 * Testé par : assets/lib/protected_email.test.js
 */

/**
 * Remet une chaîne à l'endroit.
 *
 * @param {string} value chaîne stockée à l'envers dans le HTML
 * @returns {string}
 */
export function unreverse(value) {
    return Array.from(value).reverse().join("");
}

/**
 * Reconstitue l'adresse à partir de ses deux parties stockées à l'envers.
 *
 * Exemple : buildAddress("dpgr", "rf.elpmexe") renvoie « rgpd@exemple.test ».
 *
 * @param {string} reversedUser   partie avant l'arobase, à l'envers
 * @param {string} reversedDomain domaine, à l'envers
 * @returns {string|null} l'adresse, ou null si une partie manque ou n'est pas valable
 */
export function buildAddress(reversedUser, reversedDomain) {
    const user = unreverse(reversedUser ?? "").trim();
    const domain = unreverse(reversedDomain ?? "").trim();
    if (!/^[a-z0-9._+-]+$/i.test(user) || !/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i.test(domain)) {
        return null;
    }

    return `${user}@${domain}`;
}
