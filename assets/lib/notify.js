/*
 * Fenêtres et messages du site (SweetAlert2) : options communes et règles d'usage, sans dépendance au DOM
 * de la page (testées par assets/lib/notify.test.js). Le contrôleur Stimulus « notify » les applique.
 *
 * Règles (aussi dans les consignes du projet) :
 * - FENÊTRE de confirmation : actions importantes ou irréversibles ;
 * - TOAST (en haut à droite, 4 s, fermable, ne prend jamais le focus) : messages flash de succès et
 *   d'information ;
 * - FENÊTRE d'information : bienvenue du recruteur (une fois), récapitulatif du tableau de bord (s'il a changé) ;
 * - JAMAIS en fenêtre : erreurs de formulaire, bandeaux permanents, arrivée d'un visiteur anonyme.
 * Sans JavaScript, tout reste lisible dans la page (messages, pages de confirmation).
 *
 * Utilisé par : assets/controllers/lazy/notify_controller.js
 */

/** Durée d'affichage d'un toast (ms). */
export const TOAST_DURATION = 4000;

/** Classes communes (boutons et fenêtre aux couleurs du site : assets/styles/notify.css). */
const CUSTOM_CLASS = {
    popup: "gc-swal",
    title: "gc-swal-title",
    htmlContainer: "gc-swal-text",
    actions: "gc-swal-actions",
    confirmButton: "btn btn-primary",
    denyButton: "btn btn-danger",
    cancelButton: "btn btn-secondary",
    closeButton: "gc-swal-close",
};

/*
 * Moins d'animations (prefers-reduced-motion) : les animations de SweetAlert2 sont gardées, mais la règle
 * globale de assets/styles/app.css les rend instantanées (0,01 ms). Les retirer bloquerait la fermeture :
 * SweetAlert2 attend la fin d'une animation dès que la durée calculée n'est pas nulle. Seule la barre de
 * temps des toasts est retirée.
 */

/**
 * Options d'un toast : coin en haut à droite, 4 s, bouton de fermeture, barre de temps, pas de focus pris.
 *
 * @param {string} message texte du message
 * @param {"success"|"info"} type nature du message
 * @param {boolean} reducedMotion vrai : sans barre de temps
 */
export function toastOptions(message, type, reducedMotion = false) {
    return {
        toast: true,
        position: "top-end",
        icon: type === "success" ? "success" : "info",
        text: message,
        timer: TOAST_DURATION,
        timerProgressBar: !reducedMotion,
        showConfirmButton: false,
        showCloseButton: true,
        closeButtonAriaLabel: "Fermer le message",
        // Un toast ne prend jamais le focus (comportement de SweetAlert2 pour les toasts : focusConfirm et
        // returnFocus y sont interdits et provoqueraient un avertissement dans la console)
        buttonsStyling: false,
        customClass: { ...CUSTOM_CLASS, popup: `${CUSTOM_CLASS.popup} gc-swal-toast` },
    };
}

/**
 * Options d'une fenêtre de confirmation (role="alertdialog" posé à l'ouverture) : bouton de confirmation
 * rouge pour une action irréversible, « Annuler » qui a le focus par défaut pour une action dangereuse.
 *
 * @param {{title: string, text?: string, confirmLabel?: string, cancelLabel?: string, danger?: boolean}} settings
 */
export function confirmOptions({ title, text = "", confirmLabel = "Confirmer", cancelLabel = "Annuler", danger = false }) {
    return {
        icon: danger ? "warning" : "question",
        title,
        text,
        showCancelButton: true,
        confirmButtonText: confirmLabel,
        cancelButtonText: cancelLabel,
        reverseButtons: true,
        focusCancel: danger,
        focusConfirm: !danger,
        returnFocus: true,
        allowEscapeKey: true,
        buttonsStyling: false,
        customClass: { ...CUSTOM_CLASS, confirmButton: danger ? "btn btn-danger" : "btn btn-primary" },
    };
}

/**
 * Options d'une fenêtre d'information (bienvenue, récapitulatif) : contenu HTML rendu par le serveur.
 *
 * @param {string} title titre
 * @param {string} html contenu (gabarit Twig du site, échappé côté serveur)
 * @param {string} confirmLabel libellé du bouton
 */
export function dialogOptions(title, html, confirmLabel = "J'ai compris") {
    return {
        title,
        html,
        confirmButtonText: confirmLabel,
        returnFocus: true,
        allowEscapeKey: true,
        buttonsStyling: false,
        customClass: CUSTOM_CLASS,
    };
}

/**
 * Indique si l'envoi d'un formulaire doit être confirmé : toujours, seulement quand une case donnée vient
 * d'être cochée (ex. activer le mode maintenance : la décocher ne demande rien), ou seulement pour un bouton
 * d'envoi donné (ex. « Supprimer » parmi les actions groupées des messages).
 *
 * @param {HTMLFormElement} form formulaire envoyé
 * @param {string} ifChecked sélecteur de la case (vide : toujours)
 * @param {HTMLElement|null} submitter bouton qui a envoyé le formulaire
 * @param {string} ifSubmitter valeur du bouton qui demande confirmation (vide : tous)
 */
export function needsConfirmation(form, ifChecked = "", submitter = null, ifSubmitter = "") {
    if (ifSubmitter !== "" && (submitter?.value ?? "") !== ifSubmitter) {
        return false;
    }
    if (ifChecked === "") {
        return true;
    }
    const box = form.querySelector(ifChecked);

    return box !== null && box.checked && !box.defaultChecked;
}

/** Vrai si l'utilisateur demande moins d'animations. */
export function prefersReducedMotion(win = globalThis.window) {
    return Boolean(win?.matchMedia?.("(prefers-reduced-motion: reduce)").matches);
}

/** Chargement de SweetAlert2 (fichier séparé, une seule fois). */
let swalPromise = null;
export function loadSwal() {
    swalPromise ??= import("./swal.js").then((module) => module.default);

    return swalPromise;
}
