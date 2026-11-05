/*
 * Lecture des champs et affichage des résultats de la calculatrice, en français.
 *
 * Les modules de calcul travaillent sur des nombres ; ce module fait le lien avec les champs :
 * - parseNumber() accepte la virgule ou le point décimal (« 2,5 » comme « 2.5 ») ;
 * - formatNumber() affiche avec le nombre de décimales de l'ancien site (« 1 840,00 »).
 * Testé par format.test.js.
 *
 * Utilisé par : assets/controllers/calculator/*_controller.js
 */

/**
 * Lit un nombre saisi (virgule ou point décimal, espaces ignorés).
 *
 * @param {string|number|null|undefined} value valeur du champ
 * @returns {number} le nombre, ou NaN si le champ est vide ou invalide (même règle que parseFloat)
 */
export function parseNumber(value) {
    if (typeof value === "number") {
        return value;
    }
    const text = String(value ?? "").trim().replace(/\s/g, "").replace(",", ".");

    return text === "" ? Number.NaN : Number.parseFloat(text);
}

/**
 * Arrondit comme l'ancien affichage (toFixed), pour comparer et afficher.
 *
 * @param {number} value   valeur calculée
 * @param {number} digits  nombre de décimales
 * @returns {number} valeur arrondie
 */
export function roundTo(value, digits) {
    return Number(value.toFixed(digits));
}

/**
 * Affiche un résultat en français avec un nombre fixe de décimales.
 *
 * @param {number|null|undefined} value  valeur calculée (null : pas de résultat)
 * @param {number}                digits nombre de décimales
 * @returns {string} « 1 840,00 », ou chaîne vide s'il n'y a pas de résultat
 */
export function formatNumber(value, digits) {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return "";
    }

    return new Intl.NumberFormat("fr-FR", { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value);
}

/**
 * Affiche un résultat et son message : un message d'erreur remplace le résultat par un tiret.
 *
 * @param {HTMLElement}      output   élément du résultat
 * @param {number|null}      value    valeur calculée (null : pas de résultat)
 * @param {number}           digits   nombre de décimales
 * @param {HTMLElement|null} message  élément du message (facultatif)
 * @param {string|null}      text     message d'erreur ou d'information (null : rien à dire)
 * @param {boolean}          isError  vrai : le message est une erreur (pas de résultat affiché)
 */
export function showResult(output, value, digits, message = null, text = null, isError = true) {
    output.textContent = text !== null && isError ? "—" : formatNumber(value, digits) || "—";
    if (message !== null) {
        message.textContent = text ?? "";
        message.hidden = text === null;
        message.classList.toggle("text-red-700", isError);
        message.classList.toggle("dark:text-red-400", isError);
        message.classList.toggle("text-secondary-700", !isError);
        message.classList.toggle("dark:text-secondary-300", !isError);
    }
}
