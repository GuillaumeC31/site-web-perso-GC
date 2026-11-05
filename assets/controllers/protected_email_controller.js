/*
 * Contrôleur Stimulus « protected-email » : remplace le texte de repli (« rgpd [arobase] … ») par un lien
 * « mailto: » reconstitué dans le navigateur ; l'adresse n'est jamais en clair dans le HTML.
 *
 * Utilisé par : templates/legal/privacy.html.twig (section « Vos droits »)
 *   <span data-controller="protected-email" data-protected-email-user-value="dpgr"
 *         data-protected-email-domain-value="tset.elpmaxe">rgpd [arobase] …</span>
 * Utilise : assets/lib/protected_email.js
 */
import { Controller } from "@hotwired/stimulus";
import { buildAddress } from "../lib/protected_email.js";

export default class extends Controller {
    static values = { user: String, domain: String };

    /** Affiche le lien ; sans adresse valable, le texte de repli reste affiché. */
    connect() {
        const address = buildAddress(this.userValue, this.domainValue);
        if (null === address) {
            return;
        }
        const link = document.createElement("a");
        link.href = `mailto:${address}`;
        link.className = "link";
        link.textContent = address;
        this.element.replaceChildren(link);
    }
}
