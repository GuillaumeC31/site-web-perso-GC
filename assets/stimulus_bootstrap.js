/*
 * Démarre Stimulus et enregistre les contrôleurs de assets/controllers/.
 * Un fichier « theme_controller.js » devient le contrôleur data-controller="theme".
 *
 * - Contrôleurs de assets/controllers/*_controller.js : inclus dans le fichier principal.
 * - Contrôleurs de assets/controllers/calculator/ : chargés à la demande (un petit fichier
 *   par outil), seulement sur les pages qui les utilisent ; « calculator/loi_ohm_controller.js »
 *   devient « calculator--loi-ohm ». Ils sont recherchés à chaque affichage de page Turbo.
 * - Contrôleurs de assets/controllers/lazy/ : chargés à la demande aussi, identifiant sans préfixe
 *   (« lazy/notify_controller.js » devient « notify ») ; ex. fenêtres et messages, absents de la plupart des pages.
 *
 * L'enregistrement est fait ici, sans la transformation « ?stimulus » de
 * vite-plugin-symfony, qui calcule de faux identifiants sous Windows
 * (voir assets/lib/stimulus_identifier.js).
 */
import { Application } from "@hotwired/stimulus";
import { controllerIdentifier } from "./lib/stimulus_identifier.js";
import { lazyIdentifiersToLoad } from "./lib/lazy_controllers.js";

const app = Application.start();

const modules = import.meta.glob("./controllers/*_controller.js", { eager: true });

for (const [path, module] of Object.entries(modules)) {
    const identifier = controllerIdentifier(path);

    if (identifier && module.default) {
        app.register(identifier, module.default);
    }
}

// Chargement à la demande (fichiers séparés produits par Vite)
const lazyLoaders = new Map(
    [
        ...Object.entries(import.meta.glob("./controllers/calculator/*_controller.js")).map(([path, load]) => [controllerIdentifier(path), load]),
        // Dossier lazy/ : identifiant sans préfixe (« lazy/notify_controller.js » devient « notify »)
        ...Object.entries(import.meta.glob("./controllers/lazy/*_controller.js")).map(([path, load]) => [controllerIdentifier(path)?.replace(/^lazy--/, "") ?? null, load]),
    ].filter(([identifier]) => identifier !== null),
);
const handled = new Set();

/** Charge et enregistre les contrôleurs différés utilisés par la page affichée. */
function loadLazyControllers() {
    const attributes = [...document.querySelectorAll("[data-controller]")].map((element) => element.getAttribute("data-controller"));

    for (const identifier of lazyIdentifiersToLoad(attributes, new Set(lazyLoaders.keys()), handled)) {
        handled.add(identifier);
        lazyLoaders
            .get(identifier)()
            .then((module) => app.register(identifier, module.default))
            .catch(() => handled.delete(identifier));
    }
}

document.addEventListener("turbo:load", loadLazyControllers);
document.addEventListener("turbo:frame-load", loadLazyControllers);
if (document.readyState !== "loading") {
    loadLazyControllers();
}
