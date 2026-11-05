/*
 * Préparation des tests de bout en bout (lot 8) : sessions de test des pages privées, pour l'audit
 * d'accessibilité de l'espace recruteur et de l'admin (tests/e2e/accessibility-private.spec.js) et pour le
 * parcours recruteur (tests/e2e/recruiter-journey.spec.js).
 *
 * - Lance « php bin/console app:e2e:private-sessions prepare » (commande de développement seulement) : compte admin de test sans clé YubiKey (session « clé
 *   vérifiée » posée par le vrai service) et recruteur de test avec un lien de connexion.
 * - Connecte le recruteur avec son lien (une fois), et garde la session dans tests/e2e/.auth/ (hors dépôt).
 * - Lance « php bin/console app:e2e:recruiter-journey start » : IA simulée (aucun appel à Mistral) et, si le
 *   profil n'en a pas, coordonnées privées fictives ; garde les valeurs privées attendues dans .auth/journey.json.
 * Les comptes, l'IA simulée et les coordonnées fictives sont retirés par global-teardown.js.
 */
import { chromium } from "@playwright/test";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

export const AUTH_DIR = path.resolve("tests/e2e/.auth");

export default async function globalSetup(config) {
    const { baseURL, ignoreHTTPSErrors, channel } = config.projects[0].use;
    // Base dédiée aux tests remise à neuf : schéma courant et copie (lecture seule) des données de développement
    execFileSync("php", ["bin/console", "app:e2e:database", "prepare"], { encoding: "utf8" });
    const output = execFileSync("php", ["bin/console", "app:e2e:private-sessions", "prepare", `--base-url=${baseURL}`], { encoding: "utf8" });
    const sessions = JSON.parse(output.trim().split("\n").pop());
    fs.mkdirSync(AUTH_DIR, { recursive: true });

    // Recruteur : connexion par son lien (page de confirmation, puis bouton en POST)
    const browser = await chromium.launch({ channel });
    const context = await browser.newContext({ ignoreHTTPSErrors });
    const page = await context.newPage();
    await page.goto(sessions.recruiter_link);
    await page.getByRole("button", { name: "Me connecter" }).click();
    await page.waitForURL("**/espace-recruteur/accueil**");
    await context.storageState({ path: path.join(AUTH_DIR, "recruiter.json") });
    await browser.close();

    const journey = execFileSync("php", ["bin/console", "app:e2e:recruiter-journey", "start"], { encoding: "utf8" });
    fs.writeFileSync(path.join(AUTH_DIR, "journey.json"), journey.trim().split("\n").pop());

    fs.writeFileSync(path.join(AUTH_DIR, "sessions.json"), JSON.stringify({ cookie: sessions.cookie, adminSession: sessions.admin_session, adminPath: sessions.admin_path }));
}
