/*
 * Parcours recruteur de bout en bout, sur ordinateur et sur mobile (projets « parcours-ordinateur » et
 * « parcours-mobile » de playwright.config.js), avec les vrais e-mails capturés par Mailpit :
 *
 *   a) première visite anonyme : aucune donnée privée, CV PDF public, assistant inaccessible ;
 *   b) demande d'accès → accusé au recruteur et notification à l'admin → validation dans l'admin → e-mail
 *      avec lien → espace recruteur (coordonnées, prétentions, CV complet, assistant guidé, question libre
 *      avec consentement : IA simulée, aucun appel à Mistral) ;
 *   c) lien déjà utilisé refusé ; lien de plus de 30 minutes refusé (lien « vieilli » : horloge simulée) ;
 *   d) déconnexion, nouveau lien, nouvelle session dans un autre contexte de navigateur ;
 *   e) cas limites : adresse inconnue (même message qu'une adresse connue), demande refusée, limite de
 *      débit, accès échu après 90 jours (horloge simulée) puis renouvelé, suppression de ses données ;
 *   f) aucune erreur dans le journal du site pendant le parcours.
 * Chaque formulaire public passe par le VRAI widget Turnstile (clés de test de Cloudflare : connexion à
 * Internet nécessaire) ; chaque e-mail est contrôlé : objet, expéditeur, lien en http(s) vers le site testé.
 *
 * Données de test : adresses @e2e-parcours.example.test, coordonnées privées fictives si le profil n'en a
 * pas (app:e2e:recruiter-journey start, dans global-setup.js) ; tout est supprimé par global-teardown.js.
 */
import { expect, test } from "@playwright/test";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { countEmails, recruiterLink, waitForEmail } from "./support/mailpit.js";

/** Expéditeur de tous les e-mails du site (MAILER_FROM). */
const SENDER = "noreply@example.test";

/** Journal du site en développement (erreurs recherchées après le parcours). */
const LOG_FILE = path.resolve("var/log/dev.log");

const AUTH_DIR = path.resolve("tests/e2e/.auth");
const DOMAIN = "e2e-parcours.example.test";
/** Réponse de l'IA simulée (App\Service\Ai\E2eFakeAiProvider::ANSWER). */
const FAKE_ANSWER = "Réponse simulée pour les tests de bout en bout";
const GENERIC_LINK_MESSAGE = "Si un accès est associé à cette adresse, un lien de connexion vient de vous être envoyé.";

test.describe.configure({ mode: "serial" });

/** Données partagées entre les étapes (une série par projet). */
const state = {};

/** Valeurs privées attendues (écrites par global-setup.js). */
function privateValues() {
    return JSON.parse(fs.readFileSync(path.join(AUTH_DIR, "journey.json"), "utf8"));
}

/** Session admin de test (global-setup.js). */
function adminSession() {
    return JSON.parse(fs.readFileSync(path.join(AUTH_DIR, "sessions.json"), "utf8"));
}

/**
 * Ouvre un contexte de navigateur avec les réglages du projet (taille d'écran, mobile, adresse du site) :
 * browser.newContext() ne les reprend pas de lui-même.
 */
async function newContext(browser, extra = {}) {
    const { baseURL, viewport, isMobile, hasTouch, deviceScaleFactor, colorScheme } = test.info().project.use;

    return browser.newContext({ baseURL, viewport, isMobile, hasTouch, deviceScaleFactor, colorScheme, ignoreHTTPSErrors: true, ...extra });
}

/** Lance la commande d'aide du parcours (développement seulement) et renvoie sa sortie. */
function journeyCommand(...args) {
    return execFileSync("php", ["bin/console", "app:e2e:recruiter-journey", ...args], { encoding: "utf8" }).trim();
}

/**
 * Attend la validation du VRAI widget Turnstile du formulaire (lancée par la saisie : execution « execute »),
 * avec son jeton : aucun jeton de remplacement (un widget bloqué fait échouer le test).
 */
async function passTurnstile(form) {
    const widget = form.locator('[data-controller="turnstile"]');
    await expect(widget).toHaveAttribute("data-turnstile-state", "verified", { timeout: 20_000 });
    await expect(widget.locator('input[name="cf-turnstile-response"]')).not.toHaveValue("");
}

/** Vérifie l'expéditeur et, s'il y en a un, que le lien de l'e-mail mène au site testé en http(s). */
function expectSiteEmail(email, { link = false } = {}) {
    expect(email.from).toBe(SENDER);
    if (link) {
        const url = new URL(recruiterLink(email.text));
        expect(["http:", "https:"]).toContain(url.protocol);
        expect(url.origin).toBe(new URL(test.info().project.use.baseURL).origin);
    }
}

/** Envoie le formulaire « Demander un accès ». */
async function requestAccess(page, { email, fullName, company }) {
    await page.goto("/espace-recruteur");
    const form = page.locator('form[name="recruiter_request"]');
    await form.getByLabel("E-mail professionnel").fill(email);
    await form.getByLabel("Nom et prénom").fill(fullName);
    await form.getByLabel("Entreprise ou cabinet").fill(company);
    await form.getByLabel("Message (facultatif)").fill("Parcours de test automatique.");
    await form.getByLabel("J'accepte que ces informations soient utilisées pour traiter ma demande d'accès.").check();
    await passTurnstile(form);
    await form.getByRole("button", { name: "Envoyer ma demande" }).click();
    await page.waitForURL(/\/espace-recruteur$/);
}

/** Envoie le formulaire « Recevoir un lien » et renvoie le texte affiché (confirmation ou erreur). */
async function requestLink(page, email) {
    await page.goto("/espace-recruteur");
    const form = page.locator('form[name="login_link"]');
    await form.getByLabel("E-mail de votre accès").fill(email);
    await passTurnstile(form);
    // Défilement doux du site (scroll-smooth) : amener le bouton à l'écran et attendre la fin du défilement avant de
    // cliquer, sinon le clic tombe à l'ancienne position du bouton sur mobile
    const button = form.getByRole("button", { name: "Recevoir un lien de connexion" });
    await button.scrollIntoViewIfNeeded();
    await page.waitForFunction(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve(true)))));
    await expect(async () => {
        const first = await button.boundingBox();
        await page.waitForTimeout(100);
        expect(await button.boundingBox()).toEqual(first);
    }).toPass();
    await button.click();
    // Envoi traité par Turbo (pas de rechargement complet) : attendre la réponse affichée (confirmation en
    // toast, erreurs sous le formulaire)
    await expect(page.locator("body")).toContainText(/Si un accès est associé|Trop d'envois|La vérification anti-robot/, { timeout: 20_000 });

    return (await page.locator("body").innerText()).replace(/\s+/g, " ");
}

/** Confirme une action dans la fenêtre de confirmation (role alertdialog) ouverte par le bouton cliqué. */
async function confirmInDialog(page, buttonName) {
    const dialog = page.getByRole("alertdialog");
    await expect(dialog).toBeVisible();
    await dialog.getByRole("button", { name: buttonName }).click();
}

/** Ouvre un lien de connexion et clique sur « Me connecter ». */
async function openLink(page, link) {
    await page.goto(link);
    await page.getByRole("button", { name: "Me connecter" }).click();
    await page.waitForLoadState("load");
}

/** Ouvre la fiche d'un recruteur dans l'admin (contexte connecté avec la session admin de test). */
async function openAdminRecruiter(browser, baseURL, fullName) {
    const sessions = adminSession();
    const context = await newContext(browser);
    await context.addCookies([{ name: sessions.cookie, value: sessions.adminSession, url: baseURL }]);
    const page = await context.newPage();
    page.on("dialog", (dialog) => dialog.accept());
    await page.goto(`/${sessions.adminPath}/recruteurs`);
    await page.getByRole("link", { name: fullName }).click();
    await expect(page.locator("h1")).toContainText(fullName);

    return { context, page };
}

test.beforeAll(async ({ browser }, testInfo) => {
    const device = testInfo.project.name.replace("parcours-", "");
    const run = Date.now().toString(36);
    state.device = device;
    state.email = `recruteur-${device}-${run}@${DOMAIN}`;
    state.fullName = `Camille Parcours ${device} ${run}`;
    state.company = `Entreprise test ${device}`;
    state.refusedEmail = `refus-${device}-${run}@${DOMAIN}`;
    state.refusedName = `Dominique Refus ${device} ${run}`;
    state.start = new Date(Date.now() - 1000);
    state.logOffset = fs.existsSync(LOG_FILE) ? fs.statSync(LOG_FILE).size : 0;
    // Un seul navigateur « du recruteur » pour toute la série (sa session passe d'une étape à l'autre)
    state.context = await newContext(browser);
    state.page = await state.context.newPage();
});

test.afterAll(async () => {
    await state.context?.close();
});

test("a) première visite anonyme : rien de privé, CV PDF public, assistant inaccessible", async () => {
    const { page } = state;
    const secret = privateValues();
    for (const url of ["/", "/cv", "/projets", "/contact", "/espace-recruteur"]) {
        const response = await page.goto(url);
        expect(response?.status(), url).toBe(200);
        const html = await page.content();
        for (const value of [secret.phone, secret.email, secret.salary].filter(Boolean)) {
            expect(html, `${url} : donnée privée visible`).not.toContain(value);
        }
        await expect(page.locator("#assistant-section")).toHaveCount(0);
    }

    for (const variant of ["design", "ats"]) {
        const pdf = await page.request.get(`/cv/pdf/${variant}`);
        expect(pdf.status()).toBe(200);
        expect(pdf.headers()["content-type"]).toContain("application/pdf");
    }

    // Espace privé, CV complet et assistant : renvoi vers la page publique de l'espace recruteur
    for (const url of ["/espace-recruteur/accueil", "/espace-recruteur/cv/design"]) {
        await page.goto(url);
        await expect(page).toHaveURL(/\/espace-recruteur$/);
    }
    const ask = await page.request.post("/espace-recruteur/assistant/question", { form: { question: "Test", consent: "1" }, maxRedirects: 0 });
    expect(ask.status()).toBe(302);
    expect(ask.headers().location).toMatch(/\/espace-recruteur$/);
});

test("b) demande, e-mails, validation par l'admin, lien, espace recruteur complet", async ({ browser, baseURL }) => {
    const { page } = state;
    const secret = privateValues();
    await requestAccess(page, state);
    // Toast (en haut à droite) au texte identique quelle que soit l'adresse
    await expect(page.locator(".swal2-toast")).toContainText("Demande reçue. Vous recevrez un e-mail dès sa validation (sous 24 h ouvrées).");

    // E-mails : accusé au recruteur (sans lien), notification à l'admin (adresse complète, v2.0.3 : e-mail seulement)
    const acknowledgement = await waitForEmail({ query: `to:"${state.email}"`, after: state.start });
    expect(acknowledgement.subject).toBe("Votre demande d'accès à l'espace recruteur est bien reçue");
    expectSiteEmail(acknowledgement);
    expect(acknowledgement.text).toContain("24 h ouvrées");
    expect(acknowledgement.text).not.toContain("/espace-recruteur/");
    const notification = await waitForEmail({ query: 'subject:"Nouvelle demande d\'accès recruteur"', after: state.start, contains: state.fullName });
    expect(notification.text).toContain(`E-mail : ${state.email}`);
    expect(notification.subject).toBe("[Site] Nouvelle demande d'accès recruteur");
    expectSiteEmail(notification);

    // Validation dans l'admin
    const admin = await openAdminRecruiter(browser, baseURL, state.fullName);
    await admin.page.getByRole("button", { name: "Valider (90 jours)" }).click();
    await confirmInDialog(admin.page, "Valider (90 jours)");
    // Statut de la fiche (le message flash peut être lu par l'autre parcours : session admin de test partagée)
    await expect(admin.page.getByRole("button", { name: "Valider (90 jours)" })).toHaveCount(0);
    await expect(admin.page.locator("main")).toContainText("Validé");
    await admin.context.close();

    // E-mail de validation avec le lien de connexion
    const approval = await waitForEmail({ query: `to:"${state.email}" subject:"validé"`, after: state.start });
    expect(approval.subject).toBe("Votre accès à l'espace recruteur est validé");
    expectSiteEmail(approval, { link: true });
    state.firstLink = recruiterLink(approval.text);
    await openLink(page, state.firstLink);
    await expect(page).toHaveURL(/\/espace-recruteur\/accueil/);

    // Première connexion : fenêtre de bienvenue (contenu, fin de l'accès, « Mes données »), fermée au clavier
    const welcome = page.getByRole("dialog", { name: "Bienvenue dans l'espace recruteur" });
    await expect(welcome).toBeVisible();
    await expect(welcome).toContainText("Votre accès est ouvert jusqu'au");
    await expect(welcome.getByRole("link", { name: "Mes données et leur suppression" })).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(welcome).toHaveCount(0);

    // Coordonnées, prétentions, vrais noms d'entreprises
    const main = page.locator("main");
    for (const value of [secret.phone, secret.email, secret.salary].filter(Boolean)) {
        await expect(main).toContainText(value);
    }
    await expect(main).toContainText("ALPHA ESSAIS GROUP");

    // CV complet en PDF (session du recruteur)
    for (const variant of ["design", "ats"]) {
        const pdf = await page.request.get(`/espace-recruteur/cv/${variant}`);
        expect(pdf.status()).toBe(200);
        expect(pdf.headers()["content-type"]).toContain("application/pdf");
    }

    // Assistant guidé (sans IA)
    await page.getByRole("button", { name: "Qui est Guillaume Couget, en quelques mots ?" }).click();
    await expect(page.getByTestId("assistant-history")).toContainText("Réponse tirée du CV, sans IA.");

    // Question libre : consentement obligatoire, puis réponse (IA simulée)
    const consent = page.getByLabel("J'accepte que mes questions libres soient envoyées à Mistral AI dans ces conditions.");
    await expect(consent).toHaveAttribute("required", "");
    await page.getByLabel("Votre question").fill("Quel poste recherche-t-il ?");
    await consent.check();
    await page.getByRole("button", { name: "Poser ma question" }).click();
    await expect(page.getByTestId("assistant-history")).toContainText(FAKE_ANSWER);
    await expect(page.getByTestId("assistant-rgpd-notice")).toHaveCount(0);
});

test("c) lien déjà utilisé refusé, lien de plus de 30 minutes refusé", async ({ browser }) => {
    const context = await newContext(browser);
    const page = await context.newPage();
    await openLink(page, state.firstLink);
    await expect(page).toHaveURL(/\/espace-recruteur\/lien-invalide/);
    await expect(page.locator("h1")).toContainText("Lien invalide ou expiré");

    // Lien émis « il y a 31 minutes » (horloge simulée) : expiré
    const aged = journeyCommand("aged-link", `--email=${state.email}`, "--minutes=31", `--base-url=${test.info().project.use.baseURL}`);
    await openLink(page, aged);
    await expect(page).toHaveURL(/\/espace-recruteur\/lien-invalide/);

    // Témoin : le même lien émis « il y a 29 minutes » est encore valable
    const fresh = journeyCommand("aged-link", `--email=${state.email}`, "--minutes=29", `--base-url=${test.info().project.use.baseURL}`);
    await openLink(page, fresh);
    await expect(page).toHaveURL(/\/espace-recruteur\/accueil/);
    await context.close();
});

test("d) déconnexion, nouveau lien, nouvelle session dans un autre navigateur", async ({ browser }) => {
    const { page } = state;
    await page.goto("/espace-recruteur/accueil");
    await page.getByRole("link", { name: "Se déconnecter" }).click();
    await expect(page).toHaveURL(/\/espace-recruteur$/);
    await page.goto("/espace-recruteur/accueil");
    await expect(page).toHaveURL(/\/espace-recruteur$/);

    const before = new Date(Date.now() - 1000);
    state.knownMessage = await requestLink(page, state.email);
    expect(state.knownMessage).toContain(GENERIC_LINK_MESSAGE);
    const email = await waitForEmail({ query: `to:"${state.email}" subject:"lien de connexion"`, after: before });
    expect(email.subject).toBe("Votre lien de connexion à l'espace recruteur");
    expectSiteEmail(email, { link: true });

    const other = await newContext(browser);
    const otherPage = await other.newPage();
    await openLink(otherPage, recruiterLink(email.text));
    await expect(otherPage).toHaveURL(/\/espace-recruteur\/accueil/);
    await expect(otherPage.locator("main")).toContainText(state.fullName.split(" ")[0]);
    // Bienvenue montrée une seule fois : pas de nouvelle fenêtre aux connexions suivantes
    await otherPage.waitForTimeout(500);
    await expect(otherPage.locator(".swal2-popup")).toHaveCount(0);
    await expect(otherPage.getByTestId("recruiter-welcome")).toHaveCount(0);
    await other.storageState({ path: path.join(AUTH_DIR, `journey-${state.device}.json`) });
    await other.close();
});

test("e1) adresse inconnue : même message qu'une adresse connue, aucun e-mail", async () => {
    const { page } = state;
    const unknown = `inconnu-${state.device}-${Date.now()}@${DOMAIN}`;
    const before = new Date(Date.now() - 1000);
    const message = await requestLink(page, unknown);
    expect(message).toContain(GENERIC_LINK_MESSAGE);
    expect(message.match(/Si un accès est associé[^.]*\./)?.[0]).toBe(state.knownMessage.match(/Si un accès est associé[^.]*\./)?.[0]);
    await page.waitForTimeout(2000);
    expect(await countEmails(`to:"${unknown}"`, before)).toBe(0);
});

test("e2) demande refusée : e-mail de refus sans adresse de réponse, puis aucun lien", async ({ browser, baseURL }) => {
    const { page } = state;
    await requestAccess(page, { email: state.refusedEmail, fullName: state.refusedName, company: "Refus test" });
    await waitForEmail({ query: `to:"${state.refusedEmail}"`, after: state.start, contains: "24 h ouvrées" });

    const admin = await openAdminRecruiter(browser, baseURL, state.refusedName);
    const decided = new Date(Date.now() - 1000);
    await admin.page.getByRole("button", { name: "Refuser" }).click();
    await confirmInDialog(admin.page, "Refuser");
    await expect(admin.page.getByRole("button", { name: "Refuser" })).toHaveCount(0);
    await expect(admin.page.locator("main")).toContainText("Refusé");
    await admin.context.close();

    // E-mail de refus (décision du 2026-10-29) : sans motif, sans lien de connexion, formulaire de contact seulement
    const refusal = await waitForEmail({ query: `to:"${state.refusedEmail}"`, after: decided, contains: "formulaire de contact" });
    expect(refusal.text).not.toMatch(/\/espace-recruteur\/\S+/);
    expect(refusal.text.toLowerCase()).not.toContain("gmail");

    const before = new Date(Date.now() - 1000);
    expect(await requestLink(page, state.refusedEmail)).toContain(GENERIC_LINK_MESSAGE);
    await page.waitForTimeout(2000);
    // Aucun lien de connexion (l'e-mail de refus, arrivé juste avant, n'entre pas dans ce compte)
    expect(await countEmails(`to:"${state.refusedEmail}" subject:"lien de connexion"`, before)).toBe(0);
});

test("e2b) demande renvoyée en attente : e-mail « déjà en cours d'examen » une fois par 24 h, admin non renotifié", async () => {
    const { page } = state;
    const email = `attente-${state.device}-${Date.now().toString(36)}@${DOMAIN}`;
    const fullName = `Alix Attente ${state.device}`;
    const before = new Date(Date.now() - 1000);
    await requestAccess(page, { email, fullName, company: "Attente test" });
    await waitForEmail({ query: `to:"${email}"`, after: before, contains: "24 h ouvrées" });
    await waitForEmail({ query: 'subject:"Nouvelle demande d\'accès recruteur"', after: before, contains: fullName });

    // Deuxième demande : même message affiché, e-mail « déjà en cours d'examen », aucune nouvelle notification.
    // Heure exacte, sans marge : l'accusé de la première demande est déjà arrivé (attendu ci-dessus)
    const second = new Date();
    await requestAccess(page, { email, fullName, company: "Attente test" });
    await expect(page.locator(".swal2-toast")).toContainText("Demande reçue. Vous recevrez un e-mail dès sa validation (sous 24 h ouvrées).");
    const pending = await waitForEmail({ query: `to:"${email}" subject:"déjà en cours"`, after: second });
    expect(pending.subject).toBe("Votre demande d'accès est déjà en cours d'examen");
    expect(pending.text).toContain("sous 24 h ouvrées");
    expectSiteEmail(pending);

    // Troisième demande dans les 24 h : rien de plus (depuis la deuxième demande : un seul e-mail, celui
    // « déjà en cours d'examen » ; compter depuis la deuxième évite de dépendre de l'heure d'arrivée exacte)
    await requestAccess(page, { email, fullName, company: "Attente test" });
    await expect(page.locator(".swal2-toast")).toContainText("Demande reçue.");
    await page.waitForTimeout(2000);
    expect(await countEmails(`to:"${email}"`, second)).toBe(1);

    // 25 h plus tard (horloge simulée) : un nouvel e-mail
    journeyCommand("age-pending-notice", `--email=${email}`, "--hours=25");
    const fourth = new Date();
    await requestAccess(page, { email, fullName, company: "Attente test" });
    await waitForEmail({ query: `to:"${email}" subject:"déjà en cours"`, after: fourth });

    // L'admin n'a reçu qu'une notification pour cette adresse
    expect(await countEmails(`subject:"Nouvelle demande d'accès recruteur" "${fullName}"`, before)).toBe(1);
});

test("e3) limite de débit : message clair, aucun e-mail", async () => {
    const { page } = state;
    const limited = `limite-${state.device}@${DOMAIN}`;
    journeyCommand("saturate", `--email=${limited}`);
    const message = await requestLink(page, limited);
    expect(message).toContain("Trop d'envois depuis votre connexion.");
    expect(message).not.toContain(GENERIC_LINK_MESSAGE);
});

test("e4) accès échu après 90 jours (horloge simulée), puis renouvelé par l'admin", async ({ browser, baseURL }) => {
    const { page } = state;
    journeyCommand("age-access", `--email=${state.email}`, "--days=91");

    // Accès échu : même message, aucun e-mail ; un lien encore signé est refusé
    const before = new Date(Date.now() - 1000);
    expect(await requestLink(page, state.email)).toContain(GENERIC_LINK_MESSAGE);
    await page.waitForTimeout(2000);
    expect(await countEmails(`to:"${state.email}"`, before)).toBe(0);
    const context = await newContext(browser);
    const recruiterPage = await context.newPage();
    await openLink(recruiterPage, journeyCommand("aged-link", `--email=${state.email}`, "--minutes=1", `--base-url=${baseURL}`));
    await expect(recruiterPage).toHaveURL(/\/espace-recruteur\/lien-invalide/);

    // Renouvellement dans l'admin : e-mail « prolongé » avec un lien qui ouvre de nouveau l'espace
    const admin = await openAdminRecruiter(browser, baseURL, state.fullName);
    await admin.page.getByRole("button", { name: "Renouveler (90 jours)" }).click();
    await expect(admin.page.getByRole("button", { name: "Renouveler (90 jours)" })).toBeVisible();
    await admin.context.close();
    const renewal = await waitForEmail({ query: `to:"${state.email}" subject:"prolongé"`, after: before });
    expect(renewal.subject).toBe("Votre accès à l'espace recruteur est prolongé");
    expectSiteEmail(renewal, { link: true });
    await openLink(recruiterPage, recruiterLink(renewal.text));
    await expect(recruiterPage).toHaveURL(/\/espace-recruteur\/accueil/);
    // Session gardée pour l'étape suivante (suppression des données)
    await context.storageState({ path: path.join(AUTH_DIR, `journey-${state.device}.json`) });
    await context.close();
});

test("e5) suppression de ses données par le recruteur", async ({ browser, baseURL }) => {
    const context = await newContext(browser, { storageState: path.join(AUTH_DIR, `journey-${state.device}.json`) });
    const page = await context.newPage();
    const before = new Date(Date.now() - 1000);
    await page.goto("/espace-recruteur/accueil");
    await page.getByRole("link", { name: "Mes données et leur suppression" }).click();
    await expect(page.locator("main")).toContainText(state.email);
    await page.getByRole("button", { name: "Supprimer définitivement mes données" }).click();
    // Fenêtre de confirmation : « Annuler » a le focus (action irréversible) ; Échap annule sans rien envoyer
    await expect(page.getByRole("alertdialog")).toBeVisible();
    await expect(page.getByRole("alertdialog").getByRole("button", { name: "Annuler" })).toBeFocused();
    await page.keyboard.press("Escape");
    await expect(page.getByRole("alertdialog")).toHaveCount(0);
    await expect(page).toHaveURL(/\/espace-recruteur\/mes-donnees$/);
    await expect(page.getByRole("button", { name: "Supprimer définitivement mes données" })).toBeFocused();
    await page.getByRole("button", { name: "Supprimer définitivement mes données" }).click();
    await confirmInDialog(page, "Supprimer définitivement mes données");
    await expect(page).toHaveURL(/\/espace-recruteur$/);
    await expect(page.locator("body")).toContainText("Vos données ont été supprimées");
    await page.goto("/espace-recruteur/accueil");
    await expect(page).toHaveURL(/\/espace-recruteur$/);
    await context.close();

    const notification = await waitForEmail({ query: 'subject:"Un recruteur a supprimé ses données"', after: before });
    expect(notification.text).not.toContain(state.email);

    // Plus de fiche dans l'admin
    const sessions = adminSession();
    const adminContext = await newContext(browser);
    await adminContext.addCookies([{ name: sessions.cookie, value: sessions.adminSession, url: baseURL }]);
    const adminPage = await adminContext.newPage();
    await adminPage.goto(`/${sessions.adminPath}/recruteurs`);
    await expect(adminPage.getByRole("link", { name: state.fullName })).toHaveCount(0);
    await adminContext.close();
});

test("f) aucune erreur dans le journal du site pendant le parcours", async () => {
    const log = fs.existsSync(LOG_FILE) ? fs.readFileSync(LOG_FILE, "utf8").slice(state.logOffset) : "";
    const errors = log.split("\n").filter((line) => /\.(ERROR|CRITICAL|ALERT|EMERGENCY): /.test(line));
    expect(errors, errors.join("\n")).toEqual([]);
});
