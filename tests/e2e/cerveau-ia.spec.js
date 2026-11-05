/*
 * Page « Cerveau de l'IA » dans un vrai navigateur, en clair et en sombre à 375 px : création d'une fiche,
 * blocage d'une donnée privée (message sous le champ, rien d'enregistré), banc d'essai (IA simulée :
 * aucun appel à Mistral), audit axe avec le résultat affiché, suppression par la fenêtre de confirmation.
 *
 * Session admin propre à chaque projet (app:e2e:private-sessions admin-session). La fiche de test est
 * supprimée à la fin du test.
 */
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const AUTH_DIR = path.resolve("tests/e2e/.auth");

test("fiche, blocage d'une donnée privée, banc d'essai, suppression", async ({ page, context, baseURL }, testInfo) => {
    const sessions = JSON.parse(fs.readFileSync(path.join(AUTH_DIR, "sessions.json"), "utf8"));
    const id = execFileSync("php", ["bin/console", "app:e2e:private-sessions", "admin-session"], { encoding: "utf8" }).trim().split("\n").pop();
    await context.addCookies([{ name: sessions.cookie, value: id, url: baseURL }]);
    const title = `Fiche e2e ${testInfo.project.name} ${Date.now().toString(36)}`;

    await page.goto(`/${sessions.adminPath}/cerveau-ia/nouvelle`, { waitUntil: "load" });
    await page.getByLabel("Titre").fill(title);
    await page.getByLabel("Contenu").fill("Joignable au 06 12 34 56 78.");
    await page.getByRole("button", { name: "Enregistrer" }).click();
    await expect(page.locator("main")).toContainText("Cette fiche contient un numéro de téléphone");

    await page.getByLabel("Contenu").fill("Il apprécie le travail en équipe pluridisciplinaire.");
    await page.getByRole("button", { name: "Enregistrer" }).click();
    await page.waitForURL(new RegExp(`/${sessions.adminPath}/cerveau-ia$`));
    await expect(page.getByText(title)).toBeVisible();
    await expect(page.getByTestId("ai-knowledge-reminder")).toContainText("N'y mettez rien de confidentiel.");

    // Banc d'essai (IA simulée)
    await page.getByLabel("Question").fill("Comment travaille-t-il ?");
    await page.getByRole("button", { name: "Tester" }).click();
    await expect(page.getByTestId("ai-test-result")).toContainText("Réponse simulée");
    const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).exclude(".sf-toolbar").analyze();
    expect(results.violations.map((v) => v.id)).toEqual([]);

    // Suppression : fenêtre de confirmation
    const entry = page.getByTestId("ai-entry").filter({ hasText: title });
    await entry.getByRole("button", { name: "Supprimer" }).click();
    await page.getByRole("alertdialog").getByRole("button", { name: "Supprimer" }).click();
    // Suppression faite côté serveur : message de confirmation (toast), puis plus de fiche dans la liste
    await expect(page.locator(".swal2-toast")).toContainText("Fiche supprimée.");
    await expect(page.getByTestId("ai-entry").filter({ hasText: title })).toHaveCount(0);
});
