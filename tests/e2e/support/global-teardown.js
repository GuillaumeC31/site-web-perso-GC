/*
 * Fin des tests de bout en bout (lot 8) : supprime les comptes de test des pages privées et leurs sessions
 * (voir global-setup.js et la commande app:e2e:private-sessions), les recruteurs du parcours, l'IA simulée,
 * les coordonnées fictives (app:e2e:recruiter-journey cleanup), les demandes et réglages de la validation à
 * distance (app:e2e:remote-decision cleanup) et les e-mails des tests dans Mailpit ; puis retire toute autre trace
 * des tests dans la base de développement (journal de sécurité, échanges et consommation de l'assistant, questions
 * sans réponse, e-mails en échec, historique des envois et des textes : app:e2e:traces purge) et VÉRIFIE qu'il n'en
 * reste aucune (app:e2e:traces check) : sinon la suite échoue.
 *
 * Règle (décision du 2026-10-29) : seules les données de test repérées précisément sont supprimées (adresse en
 * example.test, navigateur ou compte de test, étiquette Mailpit « e2e-trace »), jamais selon une date : les actions
 * réelles faites pendant la suite restent intactes, dans la base comme dans Mailpit.
 */
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { deleteEmails } from "./mailpit.js";

export default async function globalTeardown() {
    execFileSync("php", ["bin/console", "app:e2e:private-sessions", "cleanup"], { encoding: "utf8" });
    execFileSync("php", ["bin/console", "app:e2e:recruiter-journey", "cleanup"], { encoding: "utf8" });
    execFileSync("php", ["bin/console", "app:e2e:remote-decision", "cleanup"], { encoding: "utf8" });
    execFileSync("php", ["bin/console", "app:e2e:messages", "cleanup"], { encoding: "utf8" });
    execFileSync("php", ["bin/console", "app:e2e:traces", "purge"], { encoding: "utf8" });
    try {
        // E-mails envoyés par les tests : étiquette posée par ResilientMailer (en-tête X-Tags), ou destinataire de test
        await deleteEmails("tag:e2e-trace");
        await deleteEmails('to:"e2e-parcours.example.test"');
        await deleteEmails('to:"e2e-distance.example.test"');
    } catch {
        // Mailpit arrêté : rien à nettoyer
    }
    // Vérification : échoue (exception) s'il reste une trace de test dans la base de développement
    try {
        // Base dédiée aux tests : aucune trace restante
        execFileSync("php", ["bin/console", "app:e2e:traces", "check"], { encoding: "utf8" });
        // Base de DÉVELOPPEMENT (lecture seule) : aucune trace ni aucun réglage fictif n'y est arrivé
        execFileSync("php", ["bin/console", "app:e2e:traces", "check"], { encoding: "utf8", env: { ...process.env, E2E_DATABASE: "0" } });
    } finally {
        fs.rmSync(path.resolve("tests/e2e/.auth"), { recursive: true, force: true });
    }
}
