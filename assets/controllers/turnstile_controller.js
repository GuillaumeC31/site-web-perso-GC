/*
 * Contrôleur Stimulus « turnstile » : widget anti-robot Cloudflare Turnstile d'un formulaire public
 * (contact, espace recruteur), en rendu explicite (aussi après une navigation Turbo). Le widget ajoute
 * dans son conteneur le champ caché cf-turnstile-response (un jeton par formulaire), vérifié côté
 * serveur (App\Security\TurnstileVerifier).
 *
 * Vérification lancée à la première interaction avec le formulaire (execution: « execute ») :
 * deux widgets sur la même page (Espace recruteur : « Demander un accès » et « Déjà validé ? ») ne
 * tournent jamais en même temps. Rendus et exécutés ensemble, le second restait affiché sur
 * « Vérification… » (constaté le 2026-10-26). Le jeton est aussi plus frais à l'envoi.
 * - Envoi avant la fin de la vérification : l'envoi attend le jeton, puis repart tout seul.
 * - Formulaire renvoyé avec une erreur (refus du serveur) : vérification relancée dès l'affichage.
 * - Jeton expiré (300 s) : rafraîchi automatiquement (refresh-expired: auto).
 * - Retour arrière ou navigation Turbo : widget retiré à la déconnexion, rendu à nouveau, sans doublon.
 * - Thème « auto » (suit le système), textes en français, rappels propres à chaque widget.
 * État exposé dans data-turnstile-state : idle | running | verified | expired | error.
 * Erreur du widget (v2.0.3) : son code est posté dans le champ caché « turnstile_error », pour que le serveur repère
 * une erreur de CONFIGURATION (clé de site invalide, nom d'hôte non autorisé : alerte « Turnstile » de l'admin).
 *
 * Utilisé par : templates/_partials/_turnstile.html.twig
 *   <div data-controller="turnstile" data-turnstile-site-key-value="…"></div>
 */
import { Controller } from "@hotwired/stimulus";

/** Adresse du script de Turnstile (rendu explicite). */
const SCRIPT_URL = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";

/** Chargement du script, partagé par tous les widgets de la page. */
let scriptPromise = null;

/** Charge le script de Turnstile une seule fois et attend qu'il soit prêt. */
function loadTurnstile() {
    if (window.turnstile) {
        return Promise.resolve(window.turnstile);
    }
    scriptPromise ??= new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src = SCRIPT_URL;
        script.async = true;
        script.onload = () => resolve(window.turnstile);
        script.onerror = () => {
            scriptPromise = null;
            reject(new Error("Turnstile indisponible"));
        };
        document.head.append(script);
    });

    return scriptPromise;
}

export default class extends Controller {
    static values = { siteKey: String };

    /** Rend le widget (sans lancer la vérification) et écoute le formulaire. */
    async connect() {
        this.form = this.element.closest("form");
        this.setState("idle");
        this.onInteraction = () => this.execute();
        this.onSubmit = (event) => this.holdSubmit(event);
        this.form?.addEventListener("focusin", this.onInteraction);
        this.form?.addEventListener("submit", this.onSubmit);

        let turnstile;
        try {
            turnstile = await loadTurnstile();
        } catch {
            this.element.textContent = "La vérification anti-robot n'a pas pu se charger. Rechargez la page ou réessayez plus tard.";
            this.setState("error");

            return;
        }
        // Formulaire quitté entre-temps (navigation Turbo) ou déjà rendu : rien à faire
        if (!this.element.isConnected || this.widgetId !== undefined) {
            return;
        }
        this.widgetId = turnstile.render(this.element, {
            sitekey: this.siteKeyValue,
            language: "fr",
            theme: "auto",
            execution: "execute",
            "refresh-expired": "auto",
            callback: () => this.verified(),
            "error-callback": (code) => this.failed(code),
            "expired-callback": () => this.setState("expired"),
        });
        // Interaction déjà commencée pendant le chargement du script, ou formulaire renvoyé avec une erreur
        // (refus du serveur) : vérification relancée tout de suite, le widget reste visible
        if (this.form?.contains(document.activeElement) || this.form?.querySelector(".form-error")) {
            this.execute();
        }
    }

    /** Lance la vérification de ce formulaire, une seule fois (la suivante est automatique). */
    execute() {
        if (this.widgetId === undefined || !window.turnstile || this.state !== "idle") {
            return;
        }
        this.setState("running");
        window.turnstile.execute(this.element);
    }

    /** Jeton reçu : état mis à jour et envoi en attente repris. */
    verified() {
        this.setState("verified");
        if (this.pendingSubmitter !== undefined) {
            const submitter = this.pendingSubmitter;
            this.pendingSubmitter = undefined;
            this.form.requestSubmit(submitter ?? undefined);
        }
    }

    /** Envoi avant le jeton : on attend la fin de la vérification (le serveur refuserait sinon). */
    holdSubmit(event) {
        if (this.state === "verified" || this.state === "error" || this.widgetId === undefined) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        this.pendingSubmitter = event.submitter ?? null;
        this.execute();
    }

    /** Erreur du widget : état « error » et code noté dans le champ caché turnstile_error du formulaire. */
    failed(code) {
        this.setState("error");
        if (!this.form) {
            return;
        }
        let field = this.form.querySelector('input[name="turnstile_error"]');
        if (!field) {
            field = document.createElement("input");
            field.type = "hidden";
            field.name = "turnstile_error";
            this.form.append(field);
        }
        field.value = String(code ?? "").replace(/[^0-9]/g, "").slice(0, 6);
    }

    /** Note l'état du widget (attribut lu par les tests et le style). */
    setState(state) {
        this.state = state;
        this.element.dataset.turnstileState = state;
    }

    /** Retire le widget et les écouteurs quand le formulaire quitte la page (Turbo, retour arrière). */
    disconnect() {
        this.form?.removeEventListener("focusin", this.onInteraction);
        this.form?.removeEventListener("submit", this.onSubmit);
        if (this.widgetId !== undefined && window.turnstile) {
            window.turnstile.remove(this.widgetId);
        }
        this.widgetId = undefined;
    }
}
