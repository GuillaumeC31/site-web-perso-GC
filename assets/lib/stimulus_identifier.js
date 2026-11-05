/*
 * Calcule l'identifiant Stimulus d'un contrôleur à partir de son chemin.
 *
 * Utilisé par : assets/stimulus_bootstrap.js. Testé par : stimulus_identifier.test.js.
 *
 * Pourquoi ce module : la transformation « ?stimulus » de vite-plugin-symfony
 * construit l'identifiant à partir du chemin système du fichier ; sous Windows,
 * les antislashs donnent des identifiants faux (« assetscontrollers\theme »), et
 * aucun contrôleur ne démarre. Ici, on part de la clé de import.meta.glob, toujours
 * écrite avec des barres obliques (« ./controllers/theme_controller.js »).
 */

/** Forme attendue : ./controllers/<nom>_controller.js (ou .ts). */
const CONTROLLER_PATH = /^\.\/controllers\/(.+)_controller\.[jt]s$/;

/**
 * Renvoie l'identifiant Stimulus d'un fichier de contrôleur.
 *
 * Exemples : « ./controllers/theme_controller.js » → « theme »,
 *            « ./controllers/admin_menu_controller.js » → « admin-menu ».
 *
 * @param {string} path clé renvoyée par import.meta.glob
 * @returns {string|null} l'identifiant, ou null si le chemin n'est pas un contrôleur
 */
export function controllerIdentifier(path) {
    const match = CONTROLLER_PATH.exec(path);

    return match ? match[1].replace(/_/g, "-").replace(/\//g, "--") : null;
}
