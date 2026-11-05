/*
 * Encadrés d'alerte et de conseil des outils de la calculatrice : même style en clair et en sombre,
 * « info » (conseil, rappel), « result » (résultat propre à une section) ou « warning » (attention : limite
 * dépassée, givrage, consigne non atteinte).
 * Les encadrés écrits dans les gabarits utilisent la macro ui.callout (templates/calculator/_layout_macros.html.twig),
 * avec les mêmes classes.
 *
 * Testé par : callout.test.js
 * Utilisé par : assets/controllers/calculator/enceinte_climatique_controller.js, chambre_froide_controller.js
 */

/** Classes de chaque type d'encadré (« info » et « warning » identiques à la macro ui.callout). */
export const CALLOUT_CLASSES = {
    info: "border-primary-200 bg-primary-50 text-primary-900 dark:border-primary-800 dark:bg-primary-950/50 dark:text-primary-100",
    result: "border-secondary-200 bg-secondary-50 text-secondary-800 dark:border-secondary-700 dark:bg-secondary-800/60 dark:text-secondary-100",
    warning: "border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950/50 dark:text-amber-100",
};

/** Titre lu avant le texte (pour les lecteurs d'écran et les daltoniens : la couleur n'est pas le seul repère). */
export const CALLOUT_TITLES = { info: "Conseil", result: "Résultat", warning: "Attention" };

/**
 * Crée un encadré : paragraphe avec un titre en gras (« Conseil », « Attention »), puis le texte.
 * Un avertissement porte role="alert".
 *
 * @param {"info"|"result"|"warning"} kind type d'encadré   @param {string|Node} text texte (ou nœud, lien compris)
 * @param {Object<string, string>} [data] attributs data-* à poser (ex. { chargeWarning: "" })
 * @param {boolean} [withTitle] faux : sans titre (alertes courtes du résumé, qui commencent par « ⚠ »)
 * @returns {HTMLParagraphElement}
 */
export function createCallout(kind, text, data = {}, withTitle = true) {
    const p = document.createElement("p");
    p.className = `rounded-lg border px-3 py-2 text-sm ${CALLOUT_CLASSES[kind] ?? CALLOUT_CLASSES.info}`;
    if (kind === "warning") {
        p.setAttribute("role", "alert");
    }
    Object.assign(p.dataset, { callout: kind, ...data });
    const title = document.createElement("strong");
    title.className = "font-semibold";
    title.textContent = `${CALLOUT_TITLES[kind] ?? CALLOUT_TITLES.info} : `;
    p.append(...(withTitle ? [title] : []), text);

    return p;
}
