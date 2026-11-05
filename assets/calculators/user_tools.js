/*
 * Favoris et recherche du tableau de bord de la calculatrice (logique pure).
 *
 * Les favoris sont gardés dans le navigateur (localStorage, CDC § 8) sous forme de clés
 * « categorie/outil » ; rien n'est envoyé au serveur. La liste « Consultés récemment » a été retirée
 * (2026-10-23) : sa clé est effacée par forgetRecents(). Un stockage indisponible
 * (navigation privée, quota) ne bloque jamais la page : les listes sont alors vides.
 * Testé par user_tools.test.js.
 *
 * Utilisé par : assets/controllers/calculator/dashboard_controller.js, tool_controller.js
 */

/** Clé de stockage des favoris. */
export const FAVORITES_KEY = "calculator.favorites";

/** Ancienne clé des outils récents (fonction retirée), effacée du navigateur. */
export const LEGACY_RECENTS_KEY = "calculator.recents";

/** Forme d'une clé d'outil : « categorie/outil ». */
const TOOL_KEY = /^[a-z0-9-]+\/[a-z0-9-]+$/;

/**
 * Lit une liste de clés d'outils (valeurs invalides ignorées).
 *
 * @param {Storage|null} storage stockage du navigateur
 * @param {string}       key     clé de stockage
 * @returns {string[]}
 */
export function readList(storage, key) {
    try {
        const value = JSON.parse(storage?.getItem(key) ?? "[]");

        return Array.isArray(value) ? value.filter((item) => typeof item === "string" && TOOL_KEY.test(item)) : [];
    } catch {
        return [];
    }
}

/**
 * Enregistre une liste (silencieux si le stockage est indisponible).
 */
function writeList(storage, key, list) {
    try {
        storage?.setItem(key, JSON.stringify(list));
    } catch {
        // stockage plein ou interdit : la page fonctionne sans
    }
}

/**
 * Ajoute ou retire un outil des favoris.
 *
 * @param {Storage|null} storage stockage du navigateur
 * @param {string}       toolKey clé « categorie/outil »
 * @returns {boolean} vrai si l'outil est maintenant en favori
 */
export function toggleFavorite(storage, toolKey) {
    const favorites = readList(storage, FAVORITES_KEY);
    const isFavorite = favorites.includes(toolKey);
    writeList(storage, FAVORITES_KEY, isFavorite ? favorites.filter((item) => item !== toolKey) : [...favorites, toolKey]);

    return !isFavorite;
}

/**
 * Efface la liste des outils récents laissée par l'ancienne version (silencieux si le stockage est
 * indisponible).
 *
 * Liens : appelée par dashboard_controller.js et tool_controller.js au chargement.
 *
 * @param {Storage|null} storage stockage du navigateur
 */
export function forgetRecents(storage) {
    try {
        storage?.removeItem(LEGACY_RECENTS_KEY);
    } catch {
        // stockage interdit : rien à effacer
    }
}

/**
 * Met un texte sous forme comparable : minuscules, sans accents, espaces simplifiés.
 *
 * @param {string} text texte d'origine
 * @returns {string}
 */
export function normalizeSearch(text) {
    return String(text ?? "")
        .normalize("NFD")
        .replace(/[̀-ͯ]/g, "")
        .toLowerCase()
        .replace(/\s+/g, " ")
        .trim();
}

/**
 * Indique si un outil correspond à la recherche : chaque mot saisi doit apparaître dans
 * son texte (titre, description, catégorie), sans tenir compte des accents ni de la casse.
 *
 * @param {string} query      texte saisi
 * @param {string} searchText texte de l'outil
 * @returns {boolean} vrai si la recherche est vide ou si tous les mots sont trouvés
 */
export function matchesSearch(query, searchText) {
    const words = normalizeSearch(query).split(" ").filter((word) => word !== "");
    const haystack = normalizeSearch(searchText);

    return words.every((word) => haystack.includes(word));
}
