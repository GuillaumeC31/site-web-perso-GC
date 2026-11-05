/*
 * Contrôleur Stimulus « select-all » : case « Tout sélectionner » d'une liste à actions groupées (page Messages de
 * l'admin). La case est masquée sans JavaScript (attribut hidden retiré ici) : chaque ligne se coche alors à la main.
 *
 *   <form data-controller="select-all">
 *       <input type="checkbox" hidden data-select-all-target="all" data-action="select-all#toggle">
 *       <input type="checkbox" name="ids[]" data-select-all-target="item" data-action="select-all#sync">
 *   </form>
 *
 * Chargé à la demande (dossier controllers/lazy/). Utilisé par : templates/admin/contact_message/index.html.twig.
 */
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["all", "item"];

    /** Montre la case « Tout sélectionner » et la met d'accord avec les lignes. */
    connect() {
        this.allTargets.forEach((box) => box.removeAttribute("hidden"));
        this.sync();
    }

    /** Coche ou décoche toutes les lignes selon la case « Tout sélectionner ». */
    toggle(event) {
        this.itemTargets.forEach((box) => {
            box.checked = event.target.checked;
        });
        this.sync();
    }

    /** Met la case « Tout sélectionner » à jour (cochée, décochée ou indéterminée). */
    sync() {
        const checked = this.itemTargets.filter((box) => box.checked).length;
        this.allTargets.forEach((box) => {
            box.checked = checked > 0 && checked === this.itemTargets.length;
            box.indeterminate = checked > 0 && checked < this.itemTargets.length;
        });
    }
}
