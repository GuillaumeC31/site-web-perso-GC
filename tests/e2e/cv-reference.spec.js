/*
 * Test de bout en bout : la page /cv affiche les expériences du CV de référence.
 *
 * Vérifie, en clair et en sombre à 375 px : Beta Climat Industrie présent avec ses dates
 * (05/2021 – 02/2023), DELTA en une seule entrée de 3 postes, aucune mention privée (fin de contrat).
 * Le contenu vient de la base de développement, alignée par app:content:sync-textes.
 *
 * Lancement : npm run test:e2e (voir playwright.config.js).
 */
import { expect, test } from "@playwright/test";

test("le CV affiche les expériences du CV de référence, sans mention privée", async ({ page }) => {
    const response = await page.goto("/cv", { waitUntil: "load" });
    expect(response?.status()).toBe(200);

    const professional = page.locator("#cv-professional + ol");
    await expect(professional).toContainText("BETA CLIMAT INDUSTRIE");
    await expect(professional).toContainText("05/2021 – 02/2023");
    await expect(professional).toContainText("02/2023 – 12/2025");
    await expect(professional.locator("> li")).toHaveCount(4);

    // Expérience fondatrice : une seule entrée, trois postes
    const foundation = page.locator("#cv-foundation + ol > li");
    await expect(foundation).toHaveCount(1);
    await expect(foundation).toContainText("03/2005 – 01/2017");
    await expect(foundation.locator("h4")).toHaveText(["2017 – Chargé de conception", "2014 – Responsable de production", "2007 – Technicien de production"]);

    // Jamais de donnée privée ni d'ancienne mention
    const body = await page.locator("body").innerText();
    expect(body).not.toContain("Fin de contrat");
    expect(body).not.toContain("Ex-Beta Climat Industrie");
});
