/*
 * Contrôle des valeurs saisies dans la calculatrice (logique pure, testée par inputs.test.js).
 *
 * Décision du 2026-10-22 (anomalies A3 et A4, docs/CALCULATRICE_ANOMALIES.md) : une valeur
 * négative ou nulle qui n'a pas de sens est refusée avec un message clair, au lieu de donner
 * un résultat faux ou « 0,00 ». Un champ vide n'est pas une erreur : il n'y a simplement pas
 * encore de résultat.
 *
 * Utilisé par : assets/calculators/electricite.js (et les autres catégories)
 */

/** Règles disponibles : test de la valeur et fin du message d'erreur. */
export const RULES = {
    positive: { test: (value) => value > 0, message: "la valeur doit être strictement positive." },
    nonNegative: { test: (value) => value >= 0, message: "la valeur ne peut pas être négative." },
    cosPhi: { test: (value) => value >= 0 && value <= 1, message: "la valeur doit être comprise entre 0 et 1." },
    cosPhiNonZero: { test: (value) => value > 0 && value <= 1, message: "la valeur doit être comprise entre 0 (exclu) et 1." },
    integerMin1: { test: (value) => Number.isInteger(value) && value >= 1, message: "la valeur doit être un nombre entier, au moins 1." },
    pressureAngle: { test: (value) => value >= 10 && value <= 35, message: "la valeur doit être comprise entre 10 et 35°." },
    integerMin0: { test: (value) => Number.isInteger(value) && value >= 0, message: "la valeur doit être un nombre entier positif ou nul." },
    relativeHumidity: { test: (value) => value > 0 && value <= 100, message: "la valeur doit être comprise entre 0 (exclu) et 100 %." },
    percentOrZero: { test: (value) => value >= 0 && value <= 100, message: "la valeur doit être comprise entre 0 et 100 %." },
    hoursPerDay: { test: (value) => value > 0 && value <= 24, message: "la valeur doit être comprise entre 0 (exclu) et 24 h." },
    hoursOfDay: { test: (value) => value >= 0 && value <= 24, message: "la valeur doit être comprise entre 0 et 24 h." },
    celsius: { test: (value) => value > -273.15, message: "la température doit être supérieure au zéro absolu (−273,15 °C)." },
    dmxAddress: { test: (value) => Number.isInteger(value) && value >= 1 && value <= 512, message: "la valeur doit être un nombre entier de 1 à 512." },
    percent: { test: (value) => value > 0 && value <= 100, message: "la valeur doit être comprise entre 0 (exclu) et 100 %." },
    fraction: { test: (value) => value >= 0 && value <= 1, message: "la valeur doit être comprise entre 0 et 1." },
};

/**
 * Contrôle une série de valeurs.
 *
 * @param {Array<{value: number, label: string, rule: keyof RULES}>} fields valeurs (NaN si champ vide)
 * @returns {{ok: boolean, missing: boolean, error: string|null}} ok : toutes les valeurs sont
 *          présentes et valides ; error : premier message d'erreur (une valeur invalide l'emporte
 *          sur un champ vide)
 */
export function checkInputs(fields) {
    let missing = false;
    for (const { value, label, rule } of fields) {
        if (Number.isNaN(value)) {
            missing = true;
            continue;
        }
        if (!RULES[rule].test(value)) {
            return { ok: false, missing, error: `${label} : ${RULES[rule].message}` };
        }
    }

    return { ok: !missing, missing, error: null };
}

/**
 * Prépare les contrôles d'une catégorie à partir de ses règles.
 *
 * @param {Object<string, function(...*): Array>}        specs     règles par calcul : (args) → champs
 * @param {Object<string, function(...*): string|null>}  relations contrôles entre champs (facultatif) :
 *        (args) → message si les valeurs, valides une à une, sont incohérentes entre elles
 * @returns {{explain: function(string, ...*): string|null, valid: function(string, ...*): boolean}}
 *          explain : message à afficher (null si tout va bien ou s'il manque une valeur) ;
 *          valid : vrai si toutes les valeurs sont présentes, valides et cohérentes
 */
export function inputChecker(specs, relations = {}) {
    const check = (name, args) => {
        const result = checkInputs(specs[name](...args));
        if (result.error !== null || result.missing) {
            return result;
        }
        const relationError = relations[name]?.(...args) ?? null;

        return relationError === null ? result : { ok: false, missing: false, error: relationError };
    };

    return {
        explain: (name, ...args) => check(name, args).error,
        valid: (name, ...args) => check(name, args).ok,
    };
}
