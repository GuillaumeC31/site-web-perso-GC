/*
 * Contrôleur Stimulus « calculator--layout » : mise en page des grands outils (chambre froide, enceinte
 * climatique), commune aux deux.
 * - Navigation interne : liens vers les sections numérotées (colonne collante sur ordinateur, pastilles
 *   collantes sur mobile) ; chaque lien vise la section du mode affiché (simple ou pro) ; la section visible
 *   est mise en surbrillance (aria-current) ; défilement doux, sauf si l'utilisateur réduit les animations ;
 *   l'en-tête fixe est pris en compte par scroll-padding-top (--header-offset) et scroll-mt.
 * - Résumé collant : sur mobile, barre en bas de l'écran qui s'ouvre sur le détail (aria-expanded, Échap).
 *
 * Utilisé par : templates/calculator/tools/thermodynamique/enceinte-climatique.html.twig, chambre-froide.html.twig
 *   <div data-controller="calculator--xxx calculator--layout">
 *     <a data-calculator--layout-target="link" data-section-link="3" href="#…">…</a>
 *     <section data-section="3" id="…">…</section>   (une par mode, dans [data-mode])
 *     <button data-calculator--layout-target="barToggle" data-action="calculator--layout#toggleBar" aria-expanded="false">
 *     <div data-calculator--layout-target="barPanel">…</div>
 *   Les boutons de mode appellent aussi calculator--layout#refresh.
 */
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["link", "barToggle", "barPanel"];

    /** Observe les sections et prépare les liens du mode affiché. */
    connect() {
        this.observer = new IntersectionObserver((entries) => this.onIntersect(entries), { rootMargin: "-20% 0px -70% 0px" });
        for (const section of this.element.querySelectorAll("[data-section]")) {
            this.observer.observe(section);
        }
        this.onKeydown = (event) => {
            if (event.key === "Escape" && this.hasBarToggleTarget && this.barToggleTarget.getAttribute("aria-expanded") === "true") {
                this.toggleBar();
                this.barToggleTarget.focus();
            }
        };
        document.addEventListener("keydown", this.onKeydown);
        this.refresh();
    }

    disconnect() {
        this.observer.disconnect();
        document.removeEventListener("keydown", this.onKeydown);
    }

    /** Mode changé : chaque lien vise la section visible portant son numéro. */
    refresh() {
        for (const link of this.linkTargets) {
            const section = this.visibleSection(link.dataset.sectionLink);
            if (section) {
                link.href = `#${section.id}`;
            }
        }
    }

    /** Clic sur un lien : défilement vers la section, focus sur son titre (clavier et lecteurs d'écran). */
    go(event) {
        const section = this.visibleSection(event.currentTarget.dataset.sectionLink);
        if (!section) {
            return;
        }
        event.preventDefault();
        const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        section.scrollIntoView({ behavior: reduceMotion ? "instant" : "smooth", block: "start" });
        section.querySelector("h2")?.focus({ preventScroll: true });
        history.replaceState(null, "", `#${section.id}`);
        this.highlight(section.dataset.section);
    }

    /** Ouvre ou ferme le détail de la barre de résumé (mobile). */
    toggleBar() {
        const open = this.barToggleTarget.getAttribute("aria-expanded") !== "true";
        this.barToggleTarget.setAttribute("aria-expanded", String(open));
        this.barPanelTarget.toggleAttribute("data-open", open);
    }

    /** Section visible du mode affiché pour un numéro (les sections d'un mode masqué sont ignorées). */
    visibleSection(number) {
        return [...this.element.querySelectorAll(`[data-section="${number}"]`)].find((section) => !section.closest("[data-mode][hidden]")) ?? null;
    }

    /** Met en surbrillance le lien de la section qui entre dans la zone de lecture. */
    onIntersect(entries) {
        const visible = entries.find((entry) => entry.isIntersecting && !entry.target.closest("[data-mode][hidden]"));
        if (visible) {
            this.highlight(visible.target.dataset.section);
        }
    }

    /** aria-current sur les liens d'un numéro de section (colonne et pastilles). */
    highlight(number) {
        for (const link of this.linkTargets) {
            if (link.dataset.sectionLink === number) {
                link.setAttribute("aria-current", "true");
            } else {
                link.removeAttribute("aria-current");
            }
        }
    }
}
