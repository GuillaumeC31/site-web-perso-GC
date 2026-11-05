/*
 * Contrôleur Stimulus « calculator--dashboard » : tableau de bord de la calculatrice.
 *
 * - Recherche instantanée (sans accents ni casse) : masque les outils et catégories sans résultat,
 *   met à jour le nombre d'outils de chaque catégorie dans la navigation, annonce le nombre de résultats ;
 * - navigation par catégorie (colonne sur grand écran, pastilles sinon) : défilement doux vers la
 *   section (immédiat si l'utilisateur préfère réduire les animations), ancre ajoutée à l'URL,
 *   surbrillance de la catégorie visible (IntersectionObserver, aria-current="true") ;
 * - favoris (localStorage), affichés s'il y en a ; efface l'ancienne liste des outils récents.
 * Sans JavaScript, la page reste une liste de liens complète et les ancres fonctionnent.
 *
 * Utilisé par : templates/calculator/dashboard.html.twig
 * Utilise : assets/calculators/user_tools.js
 */
import { Controller } from "@hotwired/stimulus";
import { FAVORITES_KEY, forgetRecents, matchesSearch, readList } from "../../calculators/user_tools.js";

export default class extends Controller {
    static targets = ["search", "tool", "category", "status", "empty", "favorites", "favoritesList", "cardTemplate", "navLink", "pills"];
    static values = { catalog: Object, messages: Object };

    /** Affiche les favoris et suit la catégorie visible. */
    connect() {
        const storage = safeStorage();
        forgetRecents(storage);
        this.renderFavorites(storage);

        this.visible = new Set();
        this.observer = new IntersectionObserver((entries) => this.onIntersect(entries), { rootMargin: "-15% 0px -70% 0px" });
        for (const category of this.categoryTargets) {
            this.observer.observe(category);
        }
        const fromHash = decodeURIComponent(window.location.hash.slice(1));
        this.setActive(this.categoryTargets.some((c) => c.dataset.category === fromHash) ? fromHash : this.categoryTargets[0]?.dataset.category);
    }

    /** Arrête le suivi du défilement. */
    disconnect() {
        this.observer?.disconnect();
        clearTimeout(this.unlockTimer);
    }

    /** Défile jusqu'à la catégorie choisie, met l'ancre dans l'URL et place le focus sur son titre. */
    navigate(event) {
        const slug = event.currentTarget.dataset.category;
        const section = this.categoryTargets.find((category) => category.dataset.category === slug);
        if (!section) {
            return;
        }
        event.preventDefault();
        const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        section.scrollIntoView({ behavior: reduceMotion ? "instant" : "smooth", block: "start" });
        history.pushState(null, "", `#${slug}`);
        section.querySelector("h2")?.focus({ preventScroll: true });
        this.setActive(slug);
        // Pendant le défilement, la catégorie choisie reste active ; ensuite, la surbrillance suit de nouveau
        // l'écran (sauf si la section choisie est encore visible : cas des dernières, qui n'atteignent pas le haut)
        this.lockedUntil = Date.now() + 1200;
        clearTimeout(this.unlockTimer);
        this.unlockTimer = setTimeout(() => {
            const box = section.getBoundingClientRect();
            if (box.bottom <= 0 || box.top >= window.innerHeight) {
                this.refresh();
            }
        }, 1250);
    }

    /** Retient les sections dans la bande de lecture, puis met en surbrillance la première. */
    onIntersect(entries) {
        for (const entry of entries) {
            this.visible[entry.isIntersecting ? "add" : "delete"](entry.target.dataset.category);
        }
        if (Date.now() > (this.lockedUntil ?? 0)) {
            this.refresh();
        }
    }

    /** Met en surbrillance la première catégorie présente dans la bande de lecture. */
    refresh() {
        const first = this.categoryTargets.find((category) => !category.hidden && this.visible.has(category.dataset.category));
        if (first) {
            this.setActive(first.dataset.category);
        }
    }

    /** Marque la catégorie active dans les deux navigations et garde sa pastille visible. */
    setActive(slug) {
        if (slug === undefined || slug === this.active) {
            return;
        }
        this.active = slug;
        for (const link of this.navLinkTargets) {
            if (link.dataset.category === slug) {
                link.setAttribute("aria-current", "true");
            } else {
                link.removeAttribute("aria-current");
            }
        }
        // Pastille active ramenée dans la rangée, sans faire défiler la page
        const pill = this.pillsTarget.querySelector(`[data-category="${slug}"]`);
        if (pill && this.pillsTarget.offsetParent !== null) {
            const row = this.pillsTarget;
            const left = pill.offsetLeft - row.offsetLeft - (row.clientWidth - pill.offsetWidth) / 2;
            row.scrollTo({ left: Math.max(0, left), behavior: "auto" });
        }
    }

    /** Filtre les outils selon la recherche et met à jour la navigation. */
    search() {
        const query = this.searchTarget.value;
        const searching = query.trim() !== "";
        let visible = 0;

        for (const tool of this.toolTargets) {
            const match = matchesSearch(query, tool.dataset.search);
            tool.hidden = !match;
            visible += match ? 1 : 0;
        }
        const found = {};
        for (const category of this.categoryTargets) {
            found[category.dataset.category] = category.querySelectorAll("[data-calculator--dashboard-target='tool']:not([hidden])").length;
            category.hidden = found[category.dataset.category] === 0;
        }
        for (const link of this.navLinkTargets) {
            const count = link.querySelector("[data-count]");
            link.parentElement.hidden = found[link.dataset.category] === 0;
            count.textContent = searching ? String(found[link.dataset.category]) : count.dataset.total;
        }

        this.emptyTarget.hidden = visible > 0;
        this.statusTarget.textContent = searching ? this.messagesValue.results.replace("%count%", String(visible)) : "";
    }

    /** Affiche les favoris encore publics (section masquée s'il n'y en a pas). */
    renderFavorites(storage) {
        const keys = readList(storage, FAVORITES_KEY).filter((key) => key in this.catalogValue);
        this.favoritesListTarget.replaceChildren();

        for (const key of keys) {
            const tool = this.catalogValue[key];
            const item = this.cardTemplateTarget.content.firstElementChild.cloneNode(true);
            const link = item.querySelector("a");
            link.href = tool.url;
            link.querySelector("[data-title]").textContent = tool.title;
            link.querySelector("[data-category]").textContent = tool.category;
            this.favoritesListTarget.append(item);
        }

        this.favoritesTarget.hidden = keys.length === 0;
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
