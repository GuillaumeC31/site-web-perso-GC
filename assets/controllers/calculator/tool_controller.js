/*
 * Contrôleur Stimulus « calculator--tool » : page d'un outil de la calculatrice.
 *
 * - bouton « Ajouter aux favoris » (aria-pressed, libellé mis à jour) ;
 * - efface l'ancienne liste des outils récents (fonction retirée le 2026-10-23).
 *
 * Utilisé par : templates/calculator/tool.html.twig
 *   <div data-controller="calculator--tool" data-calculator--tool-key-value="electricite/loi-ohm"
 *        data-calculator--tool-labels-value='{"add": "…", "remove": "…"}'>
 *     <button data-calculator--tool-target="favorite" data-action="calculator--tool#toggleFavorite" aria-pressed="false">
 * Utilise : assets/calculators/user_tools.js
 */
import { Controller } from "@hotwired/stimulus";
import { FAVORITES_KEY, forgetRecents, readList, toggleFavorite } from "../../calculators/user_tools.js";

export default class extends Controller {
    static targets = ["favorite", "favoriteLabel"];
    static values = { key: String, labels: Object };

    /** Affiche l'état du favori. */
    connect() {
        this.storage = safeStorage();
        forgetRecents(this.storage);
        this.render(readList(this.storage, FAVORITES_KEY).includes(this.keyValue));
        this.favoriteTarget.hidden = false;
    }

    /** Ajoute ou retire l'outil des favoris. */
    toggleFavorite() {
        this.render(toggleFavorite(this.storage, this.keyValue));
    }

    /** Met à jour le bouton. */
    render(isFavorite) {
        this.favoriteTarget.setAttribute("aria-pressed", String(isFavorite));
        this.favoriteLabelTarget.textContent = isFavorite ? this.labelsValue.remove : this.labelsValue.add;
    }
}

/** Renvoie localStorage, ou null s'il est interdit (navigation privée, réglages). */
function safeStorage() {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}
