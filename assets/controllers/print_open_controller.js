/*
 * Contrôleur Stimulus « print-open » : ouvre un bloc <details> avant l'impression (sinon son contenu
 * n'est pas imprimé), puis lui rend son état.
 *
 * Utilisé par : templates/cv/show.html.twig (« Parcours terrain », bloc dépliable)
 *   <details data-controller="print-open">…</details>
 */
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    /** Écoute les événements d'impression du navigateur. */
    connect() {
        this.wasOpen = this.element.open;
        this.onBeforePrint = () => {
            this.wasOpen = this.element.open;
            this.element.open = true;
        };
        this.onAfterPrint = () => {
            this.element.open = this.wasOpen;
        };
        window.addEventListener("beforeprint", this.onBeforePrint);
        window.addEventListener("afterprint", this.onAfterPrint);
    }

    disconnect() {
        window.removeEventListener("beforeprint", this.onBeforePrint);
        window.removeEventListener("afterprint", this.onAfterPrint);
    }
}
