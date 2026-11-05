/*
 * Fenêtres et messages (SweetAlert2) : options communes, règles de confirmation et contrôleur « notify »
 * (toasts, confirmation, fenêtre d'information, fermeture avant la mise en cache Turbo), avec un faux
 * SweetAlert2 (aucun chargement réel).
 * Lancement : npm test
 *
 * @vitest-environment jsdom
 */
import { Application } from "@hotwired/stimulus";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { TOAST_DURATION, confirmOptions, dialogOptions, needsConfirmation, prefersReducedMotion, toastOptions } from "./notify.js";

/** Faux SweetAlert2 : garde les options reçues, répond selon `answer`. */
const fakeSwal = {
    calls: [],
    answer: { isConfirmed: true },
    fire: vi.fn(async (options) => {
        fakeSwal.calls.push(options);
        options.didOpen?.(document.createElement("div"));

        return fakeSwal.answer;
    }),
    close: vi.fn(),
};
let swalFails = false;

vi.mock("./notify.js", async (importOriginal) => {
    const actual = await importOriginal();

    return { ...actual, loadSwal: vi.fn(async () => (swalFails ? Promise.reject(new Error("hors ligne")) : fakeSwal)) };
});

describe("Options des fenêtres et messages", () => {
    it("toast : en haut à droite, 4 s, fermable, en français, sans prise de focus", () => {
        const options = toastOptions("Enregistré.", "success");
        expect(options).toMatchObject({ toast: true, position: "top-end", icon: "success", text: "Enregistré.", timer: TOAST_DURATION, showCloseButton: true, showConfirmButton: false });
        expect(TOAST_DURATION).toBe(4000);
        expect(options.closeButtonAriaLabel).toBe("Fermer le message");
        // Options interdites aux toasts (avertissement de SweetAlert2 dans la console)
        for (const forbidden of ["focusConfirm", "returnFocus", "focusCancel", "allowEscapeKey", "backdrop"]) {
            expect(options).not.toHaveProperty(forbidden);
        }
        expect(toastOptions("Info", "info").icon).toBe("info");
        expect(toastOptions("Autre", "warning").icon).toBe("info");
    });

    it("confirmation : boutons en français, focus sur « Annuler » pour une action dangereuse, focus rendu", () => {
        const danger = confirmOptions({ title: "Supprimer ?", confirmLabel: "Supprimer", danger: true });
        expect(danger).toMatchObject({ title: "Supprimer ?", confirmButtonText: "Supprimer", cancelButtonText: "Annuler", focusCancel: true, focusConfirm: false, returnFocus: true, allowEscapeKey: true, icon: "warning", buttonsStyling: false });
        expect(danger.customClass.confirmButton).toBe("btn btn-danger");
        const simple = confirmOptions({ title: "Renvoyer ?" });
        expect(simple).toMatchObject({ confirmButtonText: "Confirmer", focusConfirm: true, focusCancel: false, icon: "question" });
        expect(simple.customClass.confirmButton).toBe("btn btn-primary");
    });

    it("fenêtre d'information : contenu HTML, bouton « J'ai compris », Échap ferme", () => {
        expect(dialogOptions("Bienvenue", "<p>Texte</p>")).toMatchObject({ title: "Bienvenue", html: "<p>Texte</p>", confirmButtonText: "J'ai compris", allowEscapeKey: true, returnFocus: true });
    });

    it("moins d'animations : sans barre de temps, animations gardées (rendues instantanées par le CSS du site)", () => {
        expect(toastOptions("Ok", "success", true).timerProgressBar).toBe(false);
        expect(toastOptions("Ok", "success", false).timerProgressBar).toBe(true);
        // Retirer les classes d'animation bloquerait la fermeture (SweetAlert2 attend la fin d'une animation)
        expect(toastOptions("Ok", "success", true)).not.toHaveProperty("hideClass");
        expect(confirmOptions({ title: "?" })).not.toHaveProperty("showClass");
        expect(prefersReducedMotion({ matchMedia: () => ({ matches: true }) })).toBe(true);
        expect(prefersReducedMotion({ matchMedia: () => ({ matches: false }) })).toBe(false);
        expect(prefersReducedMotion({})).toBe(false);
    });

    it("confirmation selon une case : seulement quand elle vient d'être cochée", () => {
        document.body.innerHTML = '<form><input type="checkbox" id="m"></form>';
        const form = document.querySelector("form");
        const box = form.querySelector("#m");
        expect(needsConfirmation(form)).toBe(true);
        expect(needsConfirmation(form, "#m")).toBe(false);
        box.checked = true;
        expect(needsConfirmation(form, "#m")).toBe(true);
        // Déjà cochée au chargement : la laisser cochée ne redemande rien
        box.defaultChecked = true;
        expect(needsConfirmation(form, "#m")).toBe(false);
        expect(needsConfirmation(form, "#absent")).toBe(false);
    });

    it("confirmation selon le bouton d'envoi : seulement pour la valeur demandée (actions groupées)", () => {
        document.body.innerHTML = '<form><button name="action" value="read">Lus</button><button name="action" value="delete">Supprimer</button></form>';
        const form = document.querySelector("form");
        const [read, remove] = form.querySelectorAll("button");
        expect(needsConfirmation(form, "", remove, "delete")).toBe(true);
        expect(needsConfirmation(form, "", read, "delete")).toBe(false);
        // Envoi sans bouton (touche Entrée) : aucune suppression, pas de confirmation
        expect(needsConfirmation(form, "", null, "delete")).toBe(false);
        expect(needsConfirmation(form, "", read)).toBe(true);
    });
});

describe("Contrôleur notify", () => {
    let app;

    beforeEach(async () => {
        fakeSwal.calls = [];
        fakeSwal.answer = { isConfirmed: true };
        swalFails = false;
        window.matchMedia = () => ({ matches: false });
        const { default: NotifyController } = await import("../controllers/lazy/notify_controller.js");
        app = Application.start();
        app.register("notify", NotifyController);
    });

    afterEach(() => {
        app.stop();
        document.body.innerHTML = "";
    });

    /** Attend la fin des promesses en cours (chargement, fenêtres). */
    const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

    it("transforme les messages de succès et d'information en toasts, puis les retire de la page", async () => {
        document.body.innerHTML = `<div data-controller="notify">
            <div data-notify-target="toast" data-notify-type="success"> Enregistré. </div>
            <div data-notify-target="toast" data-notify-type="info">Lien envoyé.</div>
            <div role="alert">Erreur visible</div></div>`;
        await settle();
        expect(fakeSwal.calls.map((options) => [options.toast, options.icon, options.text])).toEqual([
            [true, "success", "Enregistré."],
            [true, "info", "Lien envoyé."],
        ]);
        expect(document.querySelectorAll('[data-notify-target="toast"]')).toHaveLength(0);
        // Les avertissements et erreurs restent dans la page
        expect(document.body.textContent).toContain("Erreur visible");
    });

    it("garde les messages dans la page si SweetAlert2 ne se charge pas", async () => {
        swalFails = true;
        document.body.innerHTML = '<div data-controller="notify"><div data-notify-target="toast" data-notify-type="success">Enregistré.</div></div>';
        await settle();
        expect(fakeSwal.calls).toHaveLength(0);
        expect(document.querySelector('[data-notify-target="toast"]')).not.toBeNull();
        expect(document.querySelector('[data-controller="notify"]').classList.contains("notify-fallback")).toBe(true);
    });

    it("demande confirmation (role alertdialog) puis envoie avec _confirmed=1", async () => {
        document.body.innerHTML = `<form method="post" action="/x" data-controller="notify" data-action="submit->notify#confirm"
            data-notify-title-value="Supprimer ce recruteur ?" data-notify-confirm-label-value="Supprimer" data-notify-danger-value="true">
            <input type="hidden" name="_confirmed" value="0"><button type="submit">Supprimer</button></form>`;
        const form = document.querySelector("form");
        const sent = [];
        form.requestSubmit = vi.fn(() => sent.push(form.querySelector('[name="_confirmed"]').value));
        await settle();
        form.querySelector("button").click();
        await settle();
        expect(fakeSwal.calls[0]).toMatchObject({ title: "Supprimer ce recruteur ?", confirmButtonText: "Supprimer", focusCancel: true });
        expect(sent).toEqual(["1"]);
    });

    it("n'envoie rien si la confirmation est annulée", async () => {
        fakeSwal.answer = { isConfirmed: false, isDismissed: true };
        document.body.innerHTML = `<form data-controller="notify" data-action="submit->notify#confirm">
            <input type="hidden" name="_confirmed" value="0"><button type="submit">Renvoyer</button></form>`;
        const form = document.querySelector("form");
        form.requestSubmit = vi.fn();
        await settle();
        form.querySelector("button").click();
        await settle();
        expect(fakeSwal.calls).toHaveLength(1);
        expect(form.requestSubmit).not.toHaveBeenCalled();
        expect(form.querySelector('[name="_confirmed"]').value).toBe("0");
    });

    it("mode maintenance : pas de fenêtre quand la case n'est pas cochée", async () => {
        document.body.innerHTML = `<form data-controller="notify" data-action="submit->notify#confirm" data-notify-if-checked-value="#maintenance">
            <input type="checkbox" id="maintenance"><button type="submit">Enregistrer</button></form>`;
        const form = document.querySelector("form");
        const submitted = vi.fn((event) => event.preventDefault());
        form.addEventListener("submit", submitted);
        await settle();
        form.querySelector("button").click();
        await settle();
        expect(fakeSwal.calls).toHaveLength(0);
        expect(submitted).toHaveBeenCalledTimes(1);
    });

    it("ouvre le panneau d'information en fenêtre, sans son titre de page, et le retire de la page", async () => {
        document.body.innerHTML = `<section data-controller="notify" data-notify-dialog-value="true" data-notify-title-value="Bienvenue">
            <h2 data-notify-skip>Bienvenue</h2><p>Votre accès est valable jusqu'au 30 novembre.</p><a href="/espace-recruteur/mes-donnees">Mes données</a></section>`;
        await settle();
        expect(fakeSwal.calls).toHaveLength(1);
        expect(fakeSwal.calls[0].title).toBe("Bienvenue");
        expect(fakeSwal.calls[0].html).toContain("Mes données");
        expect(fakeSwal.calls[0].html).not.toContain("<h2");
        expect(document.querySelector("section")).toBeNull();
    });

    it("ferme toute fenêtre avant la mise en cache Turbo (pas de fenêtre fantôme au retour)", async () => {
        document.body.innerHTML = '<div data-controller="notify"><div data-notify-target="toast" data-notify-type="info">Info</div></div>';
        await settle();
        const ghost = document.createElement("div");
        ghost.className = "swal2-container";
        document.body.append(ghost);
        document.body.classList.add("swal2-shown", "swal2-toast-shown");
        document.dispatchEvent(new Event("turbo:before-cache"));
        expect(fakeSwal.close).toHaveBeenCalled();
        expect(document.querySelector(".swal2-container")).toBeNull();
        expect(document.body.classList.contains("swal2-shown")).toBe(false);
    });
});
