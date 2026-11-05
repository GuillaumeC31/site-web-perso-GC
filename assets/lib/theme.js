/*
 * Logique pure du mode clair / sombre (sans accès au DOM), testée par theme.test.js.
 *
 * Utilisée par : assets/controllers/theme_controller.js.
 * La même règle est reprise en ligne dans templates/_partials/_theme_script.html.twig
 * pour appliquer le thème avant l'affichage (évite le flash blanc).
 */

/** Clé de stockage du choix de l'utilisateur dans localStorage. */
export const THEME_STORAGE_KEY = "theme";

/** Valeurs de thème possibles. */
export const THEMES = Object.freeze({ LIGHT: "light", DARK: "dark" });

/**
 * Détermine le thème à appliquer.
 *
 * @param {string|null} storedTheme  choix enregistré (« light », « dark » ou null)
 * @param {boolean}     prefersDark  préférence du système (prefers-color-scheme: dark)
 * @returns {"light"|"dark"} le thème à appliquer ; une valeur enregistrée inconnue est ignorée
 */
export function resolveTheme(storedTheme, prefersDark) {
    if (storedTheme === THEMES.LIGHT || storedTheme === THEMES.DARK) {
        return storedTheme;
    }

    return prefersDark ? THEMES.DARK : THEMES.LIGHT;
}

/**
 * Renvoie le thème opposé, pour le bouton de bascule.
 *
 * @param {"light"|"dark"} currentTheme thème actuellement affiché
 * @returns {"light"|"dark"} l'autre thème
 */
export function toggleTheme(currentTheme) {
    return currentTheme === THEMES.DARK ? THEMES.LIGHT : THEMES.DARK;
}
