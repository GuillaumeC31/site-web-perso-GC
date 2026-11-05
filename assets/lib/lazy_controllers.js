/*
 * Choix des contrôleurs Stimulus à charger à la demande (logique pure, testée par
 * lazy_controllers.test.js).
 *
 * Les contrôleurs de la calculatrice (assets/controllers/calculator/) ne sont pas dans le
 * fichier JavaScript principal : chacun est un petit fichier séparé, téléchargé seulement
 * quand une page en a besoin (data-controller="calculator--loi-ohm"…).
 *
 * Utilisé par : assets/stimulus_bootstrap.js
 */

/**
 * Renvoie les identifiants à charger : présents dans la page, disponibles en chargement
 * différé, et ni déjà enregistrés ni déjà en cours de chargement.
 *
 * @param {string[]}    controllerAttributes valeurs des attributs data-controller de la page
 * @param {Set<string>} available            identifiants chargeables à la demande
 * @param {Set<string>} alreadyHandled       identifiants enregistrés ou en cours de chargement
 * @returns {string[]} identifiants à charger, sans doublon
 */
export function lazyIdentifiersToLoad(controllerAttributes, available, alreadyHandled) {
    const wanted = new Set();
    for (const attribute of controllerAttributes) {
        for (const identifier of String(attribute ?? "").split(/\s+/)) {
            if (identifier !== "" && available.has(identifier) && !alreadyHandled.has(identifier)) {
                wanted.add(identifier);
            }
        }
    }

    return [...wanted];
}
