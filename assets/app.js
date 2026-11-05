/*
 * Point d'entrée JavaScript unique du site (public et admin).
 * Chargé par vite_entry_script_tags('app') dans templates/base.html.twig,
 * templates/admin/base.html.twig et templates/admin/security/login.html.twig.
 */
import "@fontsource-variable/inter";
import "./styles/app.css";
import "@hotwired/turbo";
import "./csrf_protection.js";
import "./stimulus_bootstrap.js";
import { installCspNonceBridge } from "./lib/csp_nonce.js";
import { installFrameMissingFallback } from "./lib/turbo_frame_missing.js";

// Nonce CSP de la page d'origine renvoyé à chaque navigation Turbo (scripts en ligne acceptés)
installCspNonceBridge(document);
// Réponse sans la Turbo Frame attendue (session expirée, blocage, erreur) : page complète, jamais « Content missing »
installFrameMissingFallback(document);
