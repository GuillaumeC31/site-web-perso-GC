/*
 * Contrôleur Stimulus « theme » : bouton de bascule clair / sombre.
 *
 * Utilisé par : templates/_partials/_theme_toggle.html.twig
 *   <button data-controller="theme" data-action="theme#toggle" aria-pressed="false">
 * Utilise : assets/lib/theme.js (logique pure).
 */
import { Controller } from "@hotwired/stimulus";
import { THEME_STORAGE_KEY, THEMES, toggleTheme } from "../lib/theme.js";

export default class extends Controller {
    /** Met à jour l'état du bouton à l'affichage (le thème est déjà appliqué en ligne). */
    connect() {
        this.updateButtonState();
    }

    /** Inverse le thème, l'applique et mémorise le choix. */
    toggle() {
        const root = document.documentElement;
        const current = root.classList.contains("dark") ? THEMES.DARK : THEMES.LIGHT;
        const next = toggleTheme(current);

        root.classList.toggle("dark", next === THEMES.DARK);
        try {
            localStorage.setItem(THEME_STORAGE_KEY, next);
        } catch {
            // Stockage indisponible (navigation privée) : le choix vaut pour la page seulement.
        }
        this.updateButtonState();
    }

    /** Indique aux lecteurs d'écran si le mode sombre est actif. */
    updateButtonState() {
        const isDark = document.documentElement.classList.contains("dark");
        this.element.setAttribute("aria-pressed", isDark ? "true" : "false");
    }
}
