/*
 * Liens « Accepter » et « Refuser » des notifications Discord (validation à distance), dans un vrai navigateur,
 * en clair et en sombre à 375 px :
 * - ouvrir le lien (GET, comme un aperçu automatique) ne décide rien ; page en noindex, sans e-mail complet ;
 * - code PIN faux : message clair, essais restants ; bon code : décision appliquée, lien consommé ;
 * - aucune violation axe sur la page de confirmation, l'erreur et le résultat.
 * Jetons émis directement par app:e2e:remote-decision prepare (aucune notification réelle) ; demandes et
 * réglages remis en l'état par global-teardown.js (app:e2e:remote-decision cleanup).
 */
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { execFileSync } from "node:child_process";

/** Crée une demande en attente et renvoie ses jetons Discord, le code PIN fictif et le nom. */
function prepare() {
    return JSON.parse(execFileSync("php", ["bin/console", "app:e2e:remote-decision", "prepare"], { encoding: "utf8" }).trim().split("\n").pop());
}

/** Lance l'audit axe (WCAG A et AA) et exige zéro violation. */
async function expectNoAxeViolation(page) {
    const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).exclude(".sf-toolbar").analyze();
    const summary = results.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target.join(" ")).slice(0, 5) }));
    expect(summary, JSON.stringify(summary, null, 2)).toEqual([]);
}

test("lien Discord : GET sans effet, code PIN faux puis bon, lien consommé, axe 0 violation", async ({ page, request }) => {
    const { tokens, pin, name } = prepare();
    const url = `/validation-distance/${tokens.approve}`;

    // Aperçu automatique (GET) : aucun effet, la page reste celle de la confirmation
    const preview = await request.get(url);
    expect(preview.status()).toBe(200);
    expect(preview.headers()["x-robots-tag"]).toBe("noindex, nofollow");

    await page.goto(url, { waitUntil: "load" });
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(`Accepter la demande de ${name} ?`);
    await expect(page.getByTestId("remote-decision-summary")).toContainText("@e2e-distance.example.test");
    await expect(page.getByTestId("remote-decision-summary")).not.toContainText("demande-");
    await expectNoAxeViolation(page);

    await page.getByLabel("Votre code PIN").fill("000000");
    await page.getByRole("button", { name: "Confirmer l'acceptation" }).click();
    await expect(page.getByTestId("remote-decision-error")).toContainText("encore 4 essais");
    await expectNoAxeViolation(page);

    await page.getByLabel("Votre code PIN").fill(pin);
    await page.getByRole("button", { name: "Confirmer l'acceptation" }).click();
    await expect(page.getByTestId("remote-decision-result")).toContainText(`Demande de ${name} acceptée`);
    await expectNoAxeViolation(page);

    // Rejeu : lien déjà utilisé ; l'autre lien de la même demande : demande déjà traitée
    expect((await request.get(url)).status()).toBe(410);
    await page.goto(`/validation-distance/${tokens.refuse}`, { waitUntil: "load" });
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Demande déjà traitée");
    await expectNoAxeViolation(page);
});
