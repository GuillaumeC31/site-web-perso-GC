/*
 * Contrôleur Stimulus « webauthn » : vérification et enregistrement de la clé YubiKey.
 *
 * Repris de l'ancien projet (admin_webauthn_controller.js et admin_webauthn_2fa_controller.js),
 * réunis en un seul contrôleur. Le navigateur demande le défi au serveur, la clé le signe,
 * puis le serveur vérifie (App\Controller\Admin\SecondFactorController).
 *
 * Utilisé par : templates/admin/second_factor/verify.html.twig (action authenticate),
 *               templates/admin/second_factor/enroll.html.twig (action register)
 *   <div data-controller="webauthn"
 *        data-webauthn-options-url-value="…" data-webauthn-verify-url-value="…"
 *        data-webauthn-csrf-token-value="…" data-webauthn-auto-start-value="true">
 *     <input data-webauthn-target="label">      (enregistrement uniquement)
 *     <button data-action="webauthn#authenticate" data-webauthn-target="button">
 *     <p data-webauthn-target="status" role="status">
 */
import { Controller } from "@hotwired/stimulus";
import { startAuthentication, startRegistration } from "@simplewebauthn/browser";

export default class extends Controller {
    static targets = ["button", "status", "label"];

    static values = {
        optionsUrl: String,
        verifyUrl: String,
        csrfToken: String,
        autoStart: Boolean,
        messages: Object,
    };

    /** Bloque l'usage depuis 127.0.0.1 (refusé par WebAuthn) et lance la vérification si demandé. */
    connect() {
        if (window.location.hostname === "127.0.0.1") {
            this.setStatus(this.message("ip_host"));
            this.toggleButton(false);

            return;
        }

        if (this.autoStartValue) {
            this.authenticate();
        }
    }

    /** Vérifie la clé : défi, signature par la clé, contrôle par le serveur. */
    async authenticate() {
        await this.run(async () => {
            const options = await this.post(this.optionsUrlValue, {});
            this.setStatus(this.message("touch_key"));
            const assertion = await startAuthentication({ optionsJSON: options });

            return this.post(this.verifyUrlValue, assertion);
        });
    }

    /** Enregistre une nouvelle clé, avec le nom saisi par l'admin. */
    async register() {
        await this.run(async () => {
            const options = await this.post(this.optionsUrlValue, {});
            this.setStatus(this.message("touch_key"));
            const attestation = await startRegistration({ optionsJSON: options });
            const label = this.hasLabelTarget ? this.labelTarget.value.trim() : "";

            return this.post(this.verifyUrlValue, { credential: attestation, label });
        });
    }

    /** Déroule une cérémonie en gérant le bouton, les messages et la redirection finale. */
    async run(ceremony) {
        this.toggleButton(false);
        this.setStatus(this.message("preparing"));

        try {
            const result = await ceremony();
            this.setStatus(this.message("success"));
            window.location.assign(result.redirect);
        } catch (error) {
            // Annulation par l'utilisateur ou délai dépassé : message simple, on peut réessayer.
            const isAbort = error instanceof Error && (error.name === "NotAllowedError" || error.name === "AbortError");
            this.setStatus(isAbort ? this.message("cancelled") : (error instanceof Error ? error.message : this.message("error")));
            this.toggleButton(true);
        }
    }

    /** Envoie une requête JSON au serveur avec le jeton CSRF, et renvoie la réponse décodée. */
    async post(url, body) {
        const response = await fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-Token": this.csrfTokenValue,
            },
            body: JSON.stringify(body),
        });

        let data = {};
        try {
            data = await response.json();
        } catch {
            data = {};
        }

        if (!response.ok || data.status !== "ok") {
            throw new Error(data.errorMessage || this.message("error"));
        }

        return data;
    }

    /** Affiche un message d'état (zone annoncée aux lecteurs d'écran). */
    setStatus(text) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = text;
        }
    }

    /** Active ou désactive le bouton d'action. */
    toggleButton(enabled) {
        if (this.hasButtonTarget) {
            this.buttonTarget.disabled = !enabled;
        }
    }

    /** Renvoie un texte traduit transmis par le template (data-webauthn-messages-value). */
    message(key) {
        return this.messagesValue[key] || key;
    }
}
