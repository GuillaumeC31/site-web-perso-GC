/*
 * Réponse sans la Turbo Frame attendue : page affichée en entier, jamais « Content missing ».
 *
 * Problème (bug du 05/11/2026, Admin › Piliers de compétences) : un formulaire ouvert dans une Turbo Frame
 * (« crud_form ») envoie sa réponse dans cette frame. Si le serveur répond par une page qui ne la contient pas
 * (session expirée et page de connexion, étape YubiKey, blocage par un pare-feu, erreur), Turbo efface la frame,
 * affiche « Content missing » et jette la vraie réponse : l'utilisateur ne voit ni la cause ni le message.
 * Solution : à l'évènement « turbo:frame-missing », on annule l'affichage par défaut et on affiche la réponse reçue
 * comme une page complète (même comportement que turbo-visit-control « reload », sans nouvelle requête).
 *
 * Utilisé par : assets/app.js (installFrameMissingFallback(document))
 * Testé par : assets/lib/turbo_frame_missing.test.js
 * Voir aussi : templates/admin/crud/form.html.twig (le formulaire s'envoie déjà hors de la frame, data-turbo-frame="_top")
 */

/**
 * Traite un évènement « turbo:frame-missing » : affiche la réponse en page complète.
 *
 * @param {CustomEvent} event évènement de Turbo (detail : response, visit)
 * @returns {boolean} vrai si l'évènement a été pris en charge
 */
export function handleFrameMissing(event) {
    const detail = event?.detail;
    if (!detail || typeof detail.visit !== "function" || !detail.response) {
        return false;
    }

    event.preventDefault();
    detail.visit(detail.response);

    return true;
}

/**
 * Installe le repli sur le document (une seule fois).
 *
 * @param {Document} doc document de la page
 */
export function installFrameMissingFallback(doc) {
    if (doc.documentElement?.dataset.frameMissingFallback === "on") {
        return;
    }
    if (doc.documentElement) {
        doc.documentElement.dataset.frameMissingFallback = "on";
    }
    doc.addEventListener("turbo:frame-missing", handleFrameMissing);
}
