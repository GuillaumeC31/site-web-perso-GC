/*
 * Contrôleur Stimulus « markdown-editor » : éditeur Markdown du contenu des projets (admin).
 *
 * - Barre d'outils : titres, gras, italique, listes, lien, citation (sur la sélection).
 * - Aperçu : le texte est envoyé au serveur, qui renvoie le HTML nettoyé exactement
 *   comme sur le site (MarkdownRenderer) ; le HTML reçu est donc sûr à afficher.
 *
 * Utilisé par : templates/admin/project/form.html.twig
 *   <div data-controller="markdown-editor" data-markdown-editor-url-value="…" data-markdown-editor-token-value="…">
 *     <button data-action="markdown-editor#format" data-markdown-editor-format-param="bold">
 *     <button data-action="markdown-editor#togglePreview" data-markdown-editor-target="toggle">
 *     <textarea data-markdown-editor-target="input">
 *     <div data-markdown-editor-target="preview" hidden>
 * Utilise : assets/lib/markdown_format.js (logique pure, testée) ; route admin_project_markdown_preview.
 */
import { Controller } from "@hotwired/stimulus";
import { applyMarkdownFormat } from "../lib/markdown_format.js";

export default class extends Controller {
    static targets = ["input", "preview", "toggle"];
    static values = { url: String, token: String };

    /** Applique la mise en forme demandée par un bouton de la barre d'outils. */
    format(event) {
        const input = this.inputTarget;
        const result = applyMarkdownFormat(input.value, input.selectionStart, input.selectionEnd, event.params.format);

        input.value = result.text;
        input.focus();
        input.setSelectionRange(result.start, result.end);
        input.dispatchEvent(new Event("input", { bubbles: true }));

        if (!this.previewTarget.hidden) {
            this.refreshPreview();
        }
    }

    /** Affiche ou masque l'aperçu. */
    togglePreview() {
        const show = this.previewTarget.hidden;
        this.previewTarget.hidden = !show;
        this.toggleTarget.setAttribute("aria-pressed", String(show));

        if (show) {
            this.refreshPreview();
        }
    }

    /** Demande au serveur le HTML de l'aperçu. */
    async refreshPreview() {
        const body = new FormData();
        body.append("markdown", this.inputTarget.value);
        body.append("_token", this.tokenValue);

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                body,
                headers: { "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            });
            // HTML nettoyé côté serveur (sanitizer « app.project_content »).
            this.previewTarget.innerHTML = response.ok ? await response.text() : "";
        } catch {
            this.previewTarget.textContent = "";
        }
    }
}
