/*
 * Contrôleur Stimulus « site-header » : en-tête fixe du site public.
 * - Ordinateur (≥ lg) : collant, réduit au défilement (attribut data-compact).
 * - Mobile : masqué en descendant, réaffiché en remontant (attribut data-hidden) ; jamais masqué
 *   menu ouvert ou focus dans l'en-tête. Sans animation si l'utilisateur réduit les animations
 *   (classes motion-safe: du gabarit).
 * - Hauteur visible publiée dans la variable CSS --header-offset (0 quand il est masqué) et hauteur
 *   totale dans --header-height : utilisées par les éléments collants (pastilles et colonne de
 *   /calculatrice) et par scroll-padding-top (ancres).
 *
 * Utilisé par : templates/_partials/_header.html.twig
 * Utilise : assets/lib/header_scroll.js
 */
import { Controller } from "@hotwired/stimulus";
import { nextHeaderState } from "../lib/header_scroll.js";

/** Largeur à partir de laquelle l'en-tête n'est plus masqué (Tailwind lg). */
const DESKTOP = "(min-width: 64rem)";

export default class extends Controller {
    /** Écoute le défilement, la taille de l'en-tête et le changement de largeur. */
    connect() {
        this.state = { hidden: false, compact: false, anchorY: window.scrollY };
        this.desktop = window.matchMedia(DESKTOP);
        this.onScroll = () => {
            if (!this.frame) {
                this.frame = requestAnimationFrame(() => {
                    this.frame = null;
                    this.update();
                });
            }
        };
        this.onChange = () => this.update();
        window.addEventListener("scroll", this.onScroll, { passive: true });
        this.desktop.addEventListener("change", this.onChange);
        this.element.addEventListener("focusin", this.onChange);
        this.resizeObserver = new ResizeObserver(() => this.publish());
        this.resizeObserver.observe(this.element);
        this.update();
    }

    disconnect() {
        window.removeEventListener("scroll", this.onScroll);
        this.desktop.removeEventListener("change", this.onChange);
        this.element.removeEventListener("focusin", this.onChange);
        this.resizeObserver.disconnect();
        cancelAnimationFrame(this.frame);
    }

    /** Calcule l'état selon le défilement et l'applique. */
    update() {
        const pinned = this.element.contains(document.activeElement) || this.element.querySelector('[aria-expanded="true"]') !== null;
        this.state = nextHeaderState({ y: window.scrollY, anchorY: this.state.anchorY, hidden: this.state.hidden, mobile: !this.desktop.matches, pinned });
        this.element.toggleAttribute("data-hidden", this.state.hidden);
        this.element.toggleAttribute("data-compact", this.state.compact);
        this.publish();
    }

    /** Publie la hauteur visible (--header-offset) et totale (--header-height) de l'en-tête. */
    publish() {
        const height = this.element.offsetHeight;
        const root = document.documentElement.style;
        root.setProperty("--header-height", `${height}px`);
        root.setProperty("--header-offset", `${this.state.hidden ? 0 : height}px`);
    }
}
