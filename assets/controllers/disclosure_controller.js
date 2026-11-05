/*
 * Contrôleur Stimulus « disclosure » : affiche ou masque un panneau (menu mobile,
 * barre latérale de l'admin) en gérant aria-expanded.
 *
 * Utilisé par : templates/_partials/_header.html.twig, templates/admin/base.html.twig
 *   <div data-controller="disclosure" data-disclosure-open-class="translate-x-0" data-disclosure-closed-class="-translate-x-full">
 *     <button data-action="disclosure#toggle" data-disclosure-target="button" aria-expanded="false">
 *     <nav data-disclosure-target="panel">
 */
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["panel", "button"];
    static classes = ["open", "closed"];

    /** Ferme le panneau à la navigation Turbo et sur la touche Échap. */
    connect() {
        this.onKeydown = (event) => {
            if (event.key === "Escape") {
                this.close();
            }
        };
        document.addEventListener("keydown", this.onKeydown);
    }

    disconnect() {
        document.removeEventListener("keydown", this.onKeydown);
    }

    /** Inverse l'état du panneau. */
    toggle() {
        this.setOpen(!this.isOpen());
    }

    /** Ferme le panneau. */
    close() {
        this.setOpen(false);
    }

    /** Indique si le panneau est ouvert (d'après le premier bouton). */
    isOpen() {
        return this.buttonTargets[0]?.getAttribute("aria-expanded") === "true";
    }

    /** Applique l'état ouvert / fermé au panneau et aux boutons. */
    setOpen(isOpen) {
        this.panelTargets.forEach((panel) => {
            if (this.hasOpenClass || this.hasClosedClass) {
                panel.classList.remove(...(isOpen ? this.closedClasses : this.openClasses));
                panel.classList.add(...(isOpen ? this.openClasses : this.closedClasses));
            } else {
                panel.hidden = !isOpen;
            }
        });
        this.buttonTargets.forEach((button) => button.setAttribute("aria-expanded", isOpen ? "true" : "false"));
    }
}
