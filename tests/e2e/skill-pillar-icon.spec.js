/*
 * Admin › Piliers de compétences dans un vrai navigateur (Turbo compris) : bug du 05/11/2026, « Contenu manquant »
 * après « Enregistrer » et icône non enregistrée.
 *
 * Parcours : ajout d'un pilier propre au projet (aucun conflit entre projets lancés en parallèle), ouverture du
 * formulaire dans la Turbo Frame de la liste, choix de l'icône « Presse-papiers (projets) », Enregistrer → page de la
 * liste en entier avec le toast « Les modifications ont été enregistrées. », jamais « Content missing » ; l'icône est
 * relue dans le formulaire ; suppression du pilier à la fin. Base dédiée aux tests (E2eMode).
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import { expect, test } from "@playwright/test";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const AUTH_DIR = path.resolve("tests/e2e/.auth");

/** Ouvre une session admin dédiée et renvoie le cookie et le chemin de l'admin. */
function openAdminSession(baseURL) {
    const sessions = JSON.parse(fs.readFileSync(path.join(AUTH_DIR, "sessions.json"), "utf8"));
    const id = execFileSync("php", ["bin/console", "app:e2e:private-sessions", "admin-session"], { encoding: "utf8" }).trim().split("\n").pop();

    return { cookie: { name: sessions.cookie, value: id, url: baseURL }, adminPath: sessions.adminPath };
}

test("icône d'un pilier enregistrée depuis la frame de la liste, retour à la liste avec le message", async ({ page, context, baseURL }, testInfo) => {
    const admin = openAdminSession(baseURL);
    await context.addCookies([admin.cookie]);
    // Nom propre au projet et à l'exécution : jamais le pilier d'un autre test
    const name = `Pilier e2e ${testInfo.project.name} ${Date.now().toString(36)}`;
    const row = page.locator("tr").filter({ hasText: name });

    // Ajout (formulaire ouvert dans la frame « crud_form » de la liste)
    await page.goto(`/${admin.adminPath}/piliers`, { waitUntil: "load" });
    await page.getByRole("link", { name: "Ajouter" }).click();
    await page.locator("turbo-frame#crud_form").getByLabel("Nom").fill(name);
    await page.locator("turbo-frame#crud_form").getByRole("button", { name: "Enregistrer" }).click();
    await expect(page.locator(".swal2-toast")).toContainText("L'élément a été ajouté.");
    await expect(row).toHaveCount(1);

    // Modification de l'icône depuis la frame de la liste
    await row.getByRole("link", { name: "Modifier" }).click();
    const frame = page.locator("turbo-frame#crud_form");
    await frame.getByLabel("Icône").selectOption("project");
    await frame.getByRole("button", { name: "Enregistrer" }).click();

    // Page de la liste affichée en entier, avec le message ; jamais « Content missing »
    await expect(page.locator(".swal2-toast")).toContainText("Les modifications ont été enregistrées.");
    await expect(page.getByText("Content missing")).toHaveCount(0);
    await expect(page).toHaveURL(new RegExp(`/${admin.adminPath}/piliers$`));

    // Icône relue
    await row.getByRole("link", { name: "Modifier" }).click();
    await expect(page.locator("turbo-frame#crud_form").getByLabel("Icône")).toHaveValue("project");

    // Nettoyage : suppression confirmée
    await page.goto(`/${admin.adminPath}/piliers`, { waitUntil: "load" });
    await row.getByRole("button", { name: "Supprimer" }).click();
    await page.getByRole("alertdialog").getByRole("button", { name: "Supprimer" }).click();
    await expect(row).toHaveCount(0);
});
