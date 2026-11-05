/*
 * Lecture des e-mails capturés par Mailpit (serveur SMTP local de Laragon, interface http://localhost:8025)
 * pour les tests de bout en bout : attente d'un e-mail, comptage, suppression des e-mails de test.
 *
 * Adresse de l'API : MAILPIT_URL (par défaut http://127.0.0.1:8025). Le site en développement envoie ses
 * e-mails à Mailpit (MAILER_DSN=smtp://127.0.0.1:1025 dans .env.local) : aucun e-mail ne sort du poste.
 *
 * Utilisé par : tests/e2e/recruiter-journey.spec.js, support/global-teardown.js
 */
export const MAILPIT_URL = process.env.MAILPIT_URL ?? "http://127.0.0.1:8025";

/** Appelle l'API de Mailpit et renvoie le JSON. */
async function api(path, options = {}) {
    const response = await fetch(`${MAILPIT_URL}/api/v1${path}`, options);
    if (!response.ok) {
        throw new Error(`Mailpit : ${options.method ?? "GET"} ${path} → ${response.status}`);
    }

    return response.status === 200 && options.method !== "DELETE" ? response.json() : null;
}

/** Résumés des e-mails correspondant à une recherche Mailpit (plus récents d'abord). */
export async function search(query) {
    const data = await api(`/search?query=${encodeURIComponent(query)}&limit=50`);

    return data.messages ?? [];
}

/**
 * Attend un e-mail et renvoie son contenu ({ subject, from, to, text, html, created }).
 *
 * @param {object} criteria
 * @param {string} criteria.query    recherche Mailpit (ex. 'to:"rh@exemple.test"')
 * @param {Date}   [criteria.after]  ignorer les e-mails reçus avant cette date
 * @param {string} [criteria.contains] texte que la version texte doit contenir
 * @param {number} [criteria.timeout] délai d'attente (ms)
 */
export async function waitForEmail({ query, after = new Date(0), contains = null, timeout = 20_000 }) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        for (const summary of await search(query)) {
            if (new Date(summary.Created) < after) {
                continue;
            }
            const message = await api(`/message/${summary.ID}`);
            if (contains === null || message.Text.includes(contains)) {
                return { subject: message.Subject, from: message.From?.Address ?? "", to: message.To.map((t) => t.Address), text: message.Text, html: message.HTML, created: new Date(summary.Created) };
            }
        }
        await new Promise((resolve) => setTimeout(resolve, 500));
    }
    throw new Error(`Aucun e-mail pour la recherche Mailpit : ${query}${contains ? ` (contenant « ${contains} »)` : ""}`);
}

/** Nombre d'e-mails reçus pour une recherche, après une date. */
export async function countEmails(query, after = new Date(0)) {
    return (await search(query)).filter((summary) => new Date(summary.Created) >= after).length;
}

/** Supprime les e-mails d'une recherche (nettoyage des tests). */
export async function deleteEmails(query) {
    await api(`/search?query=${encodeURIComponent(query)}`, { method: "DELETE" });
}

/** Premier lien vers l'espace recruteur d'un e-mail (version texte). */
export function recruiterLink(text) {
    const match = text.match(/https?:\/\/\S+\/espace-recruteur\/\S+/);
    if (!match) {
        throw new Error("Aucun lien vers l'espace recruteur dans l'e-mail.");
    }

    return match[0];
}
