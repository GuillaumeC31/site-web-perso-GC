/*
 * Contrôleur Stimulus « notify » : fenêtres et messages du site (SweetAlert2, thème commun).
 *
 * Trois usages, toujours avec un équivalent sans JavaScript :
 * 1. Messages flash (templates/_partials/_flash_messages.html.twig) : chaque élément
 *    data-notify-target="toast" (succès, information) devient un toast ; sans JavaScript (ou si SweetAlert2
 *    ne se charge pas), le message reste affiché dans la page.
 *      <div data-controller="notify"> <div data-notify-target="toast" data-notify-type="success">…</div> </div>
 * 2. Confirmation d'une action importante (formulaire POST) : fenêtre « Confirmer / Annuler » ; sans
 *    JavaScript, le serveur affiche une page de confirmation (champ caché _confirmed, voir
 *    src/Service/ActionConfirmation.php).
 *      <form data-controller="notify" data-action="submit->notify#confirm" data-notify-title-value="…"
 *            data-notify-text-value="…" data-notify-confirm-label-value="Supprimer" data-notify-danger-value="true">
 *    data-notify-if-checked-value="#id" : ne demande confirmation que si cette case vient d'être cochée.
 *    data-notify-if-submitter-value="delete" : ne demande confirmation que pour le bouton d'envoi de cette valeur
 *    (actions groupées des messages : seule la suppression est confirmée).
 * 3. Fenêtre d'information (bienvenue du recruteur, récapitulatif du tableau de bord) : le panneau affiché
 *    dans la page devient une fenêtre ; sans JavaScript, il reste dans la page.
 *      <section data-controller="notify" data-notify-dialog-value="true" data-notify-title-value="…">…</section>
 *
 *    Les éléments data-notify-skip (ex. titre du panneau) ne sont pas repris dans la fenêtre.
 *
 * Avec JavaScript (classe « js » sur <html>), les toasts et panneaux sont masqués d'emblée (pas de décalage
 * de la page) ; ils réapparaissent (classe notify-fallback) si SweetAlert2 ne se charge pas.
 *
 * Turbo : toute fenêtre ouverte est fermée avant la mise en cache de la page, et les messages déjà montrés
 * sont retirés de la page (le retour arrière ne les rejoue pas, aucune fenêtre fantôme).
 *
 * Chargé à la demande (assets/stimulus_bootstrap.js, dossier controllers/lazy/) : seulement sur les pages qui
 * ont un élément data-controller="notify" (rien dans le fichier principal, Lighthouse inchangé).
 *
 * Utilise : assets/lib/notify.js (options, chargement de SweetAlert2), thème dans assets/styles/notify.css.
 */
import { Controller } from "@hotwired/stimulus";
import { confirmOptions, dialogOptions, loadSwal, needsConfirmation, prefersReducedMotion, toastOptions } from "../../lib/notify.js";

/** Classes posées sur <html> et <body> par SweetAlert2 pendant l'affichage. */
const SWAL_CLASSES = ["swal2-shown", "swal2-height-auto", "swal2-toast-shown", "swal2-no-backdrop", "swal2-iosfix"];

/**
 * Ferme toute fenêtre avant la mise en cache Turbo : la copie gardée pour le retour arrière n'en contient
 * aucune. Écouteur unique pour toute la page.
 */
document.addEventListener("turbo:before-cache", () => {
    if (window.gcSwal) {
        window.gcSwal.close();
    }
    document.querySelectorAll(".swal2-container").forEach((container) => container.remove());
    document.documentElement.classList.remove(...SWAL_CLASSES);
    document.body.classList.remove(...SWAL_CLASSES);
});

export default class extends Controller {
    static targets = ["toast"];

    static values = {
        title: String,
        text: String,
        confirmLabel: String,
        cancelLabel: String,
        danger: Boolean,
        ifChecked: String,
        ifSubmitter: String,
        dialog: Boolean,
    };

    /** Affiche les toasts ou la fenêtre d'information dès l'arrivée de l'élément dans la page. */
    connect() {
        if (this.dialogValue) {
            this.showDialog();
        } else if (this.toastTargets.length > 0) {
            this.showToasts();
        }
    }

    /**
     * Montre les messages de succès et d'information en toasts, l'un après l'autre. Chaque message est
     * retiré de la page dès qu'il est confié à SweetAlert2 (pas de double affichage, pas de rejeu).
     */
    async showToasts() {
        const messages = this.toastTargets.map((element) => ({
            element,
            text: element.textContent.trim(),
            type: element.dataset.notifyType === "success" ? "success" : "info",
        }));
        let Swal;
        try {
            Swal = await this.swal();
        } catch {
            this.element.classList.add("notify-fallback");

            return;
        }
        for (const message of messages) {
            message.element.remove();
        }
        if (this.element.children.length === 0) {
            this.element.remove();
        }
        for (const { text, type } of messages) {
            await Swal.fire({
                ...toastOptions(text, type, prefersReducedMotion()),
                didOpen: (popup) => {
                    // Annonce polie, sans interrompre ni prendre le focus
                    popup.setAttribute("role", "status");
                },
            });
        }
    }

    /**
     * Demande confirmation avant l'envoi du formulaire. Confirmé : le formulaire repart avec _confirmed=1
     * (le serveur n'affiche alors pas sa page de confirmation) ; annulé : rien n'est envoyé.
     *
     * @param {SubmitEvent} event envoi du formulaire
     */
    async confirm(event) {
        const form = this.element;
        const flag = form.querySelector('input[name="_confirmed"]');
        if ((flag && flag.value === "1") || !needsConfirmation(form, this.ifCheckedValue, event.submitter ?? null, this.ifSubmitterValue)) {
            return;
        }
        event.preventDefault();
        const submitter = event.submitter ?? null;
        let Swal;
        try {
            Swal = await this.swal();
        } catch {
            // SweetAlert2 indisponible : envoi sans _confirmed, le serveur demande confirmation
            form.submit();

            return;
        }
        const options = {
            title: this.titleValue || "Confirmer cette action ?",
            text: this.textValue,
            confirmLabel: this.confirmLabelValue || "Confirmer",
            cancelLabel: this.cancelLabelValue || "Annuler",
            danger: this.dangerValue,
        };
        const result = await Swal.fire({
            ...confirmOptions(options),
            didOpen: (popup) => popup.setAttribute("role", "alertdialog"),
        });
        if (!result.isConfirmed) {
            return;
        }
        const confirmed = flag ?? Object.assign(document.createElement("input"), { type: "hidden", name: "_confirmed" });
        confirmed.value = "1";
        if (!flag) {
            form.append(confirmed);
        }
        form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
    }

    /** Ouvre le panneau d'information dans une fenêtre (il reste dans la page si SweetAlert2 manque). */
    async showDialog() {
        let Swal;
        try {
            Swal = await this.swal();
        } catch {
            this.element.classList.add("notify-fallback");

            return;
        }
        // Le titre de la page (data-notify-skip) devient celui de la fenêtre
        const content = this.element.cloneNode(true);
        content.querySelectorAll("[data-notify-skip]").forEach((element) => element.remove());
        const html = content.innerHTML;
        const title = this.titleValue;
        // Retiré avant l'ouverture : ni double contenu (identifiants), ni rejeu au retour arrière
        this.element.remove();
        await Swal.fire(dialogOptions(title, html, this.confirmLabelValue || "J'ai compris"));
    }

    /** Charge SweetAlert2 et le garde pour la fermeture avant mise en cache Turbo. */
    async swal() {
        const Swal = await loadSwal();
        window.gcSwal = Swal;

        return Swal;
    }
}
