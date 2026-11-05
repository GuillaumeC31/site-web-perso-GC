/*
 * Mise en forme Markdown d'une sélection de texte (barre d'outils de l'éditeur des projets).
 *
 * Fonction pure, sans accès au DOM : elle reçoit le texte et la sélection, et renvoie
 * le nouveau texte et la nouvelle sélection. Testée par markdown_format.test.js.
 *
 * Utilisée par : assets/controllers/markdown_editor_controller.js
 */

/** Préfixes ajoutés au début de chaque ligne sélectionnée. */
const LINE_PREFIXES = {
    heading2: "## ",
    heading3: "### ",
    list: "- ",
    quote: "> ",
};

/** Marqueurs placés autour de la sélection. */
const WRAPPERS = {
    bold: "**",
    italic: "*",
};

/** Texte proposé quand rien n'est sélectionné. */
const PLACEHOLDERS = {
    bold: "texte en gras",
    italic: "texte en italique",
    link: "texte du lien",
};

/**
 * Applique une mise en forme à la sélection.
 *
 * @param {string} text   texte complet de la zone de saisie
 * @param {number} start  début de la sélection
 * @param {number} end    fin de la sélection
 * @param {string} action heading2, heading3, bold, italic, list, orderedList, link ou quote
 * @returns {{text: string, start: number, end: number}} nouveau texte et sélection à rétablir
 */
export function applyMarkdownFormat(text, start, end, action) {
    const selected = text.slice(start, end);

    if (action in WRAPPERS) {
        const marker = WRAPPERS[action];
        const inner = selected || PLACEHOLDERS[action];
        const result = text.slice(0, start) + marker + inner + marker + text.slice(end);

        return { text: result, start: start + marker.length, end: start + marker.length + inner.length };
    }

    if (action === "link") {
        const label = selected || PLACEHOLDERS.link;
        const url = "https://";
        const result = `${text.slice(0, start)}[${label}](${url})${text.slice(end)}`;
        // L'adresse est sélectionnée : il suffit de la remplacer.
        const urlStart = start + label.length + 3;

        return { text: result, start: urlStart, end: urlStart + url.length };
    }

    if (action in LINE_PREFIXES || action === "orderedList") {
        // On travaille sur les lignes entières touchées par la sélection.
        const lineStart = text.lastIndexOf("\n", start - 1) + 1;
        const nextBreak = text.indexOf("\n", end);
        const lineEnd = nextBreak === -1 ? text.length : nextBreak;
        const lines = text.slice(lineStart, lineEnd).split("\n");
        const prefixed = lines
            .map((line, index) => {
                const prefix = action === "orderedList" ? `${index + 1}. ` : LINE_PREFIXES[action];
                // Un titre remplace un titre existant au lieu de s'y ajouter.
                const cleaned = action.startsWith("heading") ? line.replace(/^#{1,6}\s+/, "") : line;

                return prefix + cleaned;
            })
            .join("\n");
        const result = text.slice(0, lineStart) + prefixed + text.slice(lineEnd);

        return { text: result, start: lineStart, end: lineStart + prefixed.length };
    }

    return { text, start, end };
}
