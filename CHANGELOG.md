# Journal des versions

Évolutions notables du site guillaume-couget.fr (v2).

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) ; versions : [SemVer](https://semver.org/lang/fr/).
Les préversions `2.0.0-alpha.N` correspondent aux lots du développement, `2.0.0-rc.1` à la candidate recettée avant
la mise en ligne ; ensuite, `2.0.x` pour les corrections et `2.x.0` pour les nouveautés. Méthode de travail :
[docs/METHODE.md](docs/METHODE.md).

## [2.0.3] — 2026-11-05

Adresse réelle des visiteurs derrière les mandataires (proxys), pages privées jamais en cache, alerte de
configuration de l'anti-robot, signal d'intérêt des recruteurs fiabilisé.

### Ajouté
- Mandataires de confiance limités à une liste d'adresses versionnée ; seuls `X-Forwarded-For` et
  `X-Forwarded-Proto` sont lus. Un en-tête forgé par un client hors de cette liste est ignoré : limites de débit,
  journal de sécurité et anti-robot reçoivent l'adresse réelle du visiteur.
- Commande de contrôle hebdomadaire de cette liste, comparée aux listes publiées par le fournisseur : alerte en cas
  d'écart, jamais de mise à jour automatique.
- Page d'admin temporaire de diagnostic des adresses, active seulement quand une variable d'environnement le permet.
- Alerte « anti-robot mal configuré » : les erreurs de configuration de Turnstile (clé refusée, nom d'hôte non
  autorisé…) sont notées au journal, à part des refus normaux des robots ; tuile du tableau de bord et notification.
- Notification « Activité d'un recruteur » (première connexion, CV complet téléchargé), au plus une fois par jour,
  réglable dans l'admin.

### Modifié
- Notifications par e-mail à Guillaume : adresse du recruteur cliquable ; Discord et Telegram n'affichent que le
  domaine.
- Après « Je suis intéressé » : message de confirmation avec le délai de réponse (page, espace recruteur,
  assistant).

### Corrigé
- **Signal d'intérêt envoyé sans clic** : la première connexion et le téléchargement du CV partaient sous le titre
  « Signal d'intérêt ». Seul le clic sur « Je suis intéressé » (POST avec jeton CSRF) l'envoie désormais, une fois
  par 7 jours ; un GET, un préchargement ou l'ouverture du lien par un robot de messagerie n'envoient rien.
- Pages privées ou personnalisées (admin, espace recruteur, session ouverte, formulaires avec jeton) en
  `no-store, private, max-age=0` avec `Vary: Cookie` : jamais gardées par un cache intermédiaire. Le script de
  contrôle des en-têtes vérifie aussi une page privée et une page de formulaire.
- En-tête HSTS absent des fichiers statiques (régression de la 2.0.2) : une seule déclaration dans
  `public/.htaccess`.

## [2.0.2] — 2026-11-05

Visibilité auprès des recruteurs, bloc « Pourquoi me recruter » géré dans l'admin, corrections après la mise en
ligne.

### Ajouté
- Données structurées schema.org `Person` et `ProfilePage` (JSON-LD, nonce CSP) sur l'accueil et `/cv` : adresse
  limitée à la ville, la région et le pays ; jamais de téléphone ni d'e-mail.
- Image de partage Open Graph et Twitter (1200 × 630) produite à partir du profil, sans service externe, régénérée
  quand le nom, le titre ou la photo changent.
- QR code sur les PDF du CV (bibliothèque locale) : le CV public renvoie à l'espace recruteur, le CV complet au CV en
  ligne.
- Page d'admin « Pourquoi me recruter » : arguments triables (titre, texte, « Afficher »), interrupteur général,
  aperçu. Le bloc n'apparaît sur l'accueil que s'il est activé et qu'un argument est visible ; l'assistant IA ne
  reçoit que les arguments affichés.
- Réseaux sociaux : Bluesky et Mastodon (toute instance en https), logos SVG locaux.
- Icônes du site (`favicon.ico`, `apple-touch-icon.png`) produites à partir du monogramme.

### Corrigé
- **Icône de pilier jamais enregistrée** : un formulaire de l'admin ouvert dans une Turbo Frame n'avait pas
  d'attribut `action` ; le navigateur l'envoyait à l'adresse de la liste (GET seulement) : erreur 405, rien
  d'enregistré. Action explicite, envoi hors de la frame, repli global sur `turbo:frame-missing`.
- Mises à jour de sécurité de Symfony (8.1.11) et de PHPUnit (13.4.6), relevées par `composer audit`.
- Page 404 aux en-têtes de sécurité en double : un cache intermédiaire gardait les réponses d'erreur malgré
  `no-cache`. Toute réponse d'erreur part désormais en `no-store, private, max-age=0`, et le script de contrôle
  des en-têtes vérifie une 404.
- Titres de section sur deux lignes sur ordinateur.
- Tests de bout en bout plus stables (attente des assertions, minuterie des messages, base de test vidée avant la
  copie).

### Retiré
- Réglages propres à l'ancien site de test, retirés du code ; les options utiles à un futur environnement de test
  sont gardées.

## [2.0.1] — 2026-11-05

Premier correctif après la mise en ligne : retours de l'audit du site en production.

### Ajouté
- Lien vers l'administration sur le cœur du pied de page, **seulement sur un appareil de confiance** : cookie signé
  (HMAC, 180 jours, HttpOnly, Secure, SameSite=Lax, expiration et numéro de génération) posé après une connexion
  complète (mot de passe et clé physique). Sans cookie valide, l'adresse de l'admin n'apparaît nulle part dans le
  HTML. Bouton « Révoquer tous les appareils de confiance ».
- Icône « projet » pour les piliers de compétences, réalignée à la synchronisation.
- Release GitHub après chaque version publiée, avec les notes du journal des versions.

### Corrigé
- Listes de l'admin en erreur 500 (« Object of class … could not be converted to string ») : `__toString()`
  manquant sur quatre entités. Nouveau test qui ouvre chaque liste et chaque formulaire du CRUD de l'admin.
- En-têtes de sécurité envoyés deux fois (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, HSTS) :
  une condition de `public/.htaccess` était vraie aussi pour `index.php`. Apache ne les pose plus que sur les
  fichiers statiques, Symfony garde les réponses de PHP (CSP à nonce comprise). Le script de contrôle des
  en-têtes vérifie la page, un fichier statique, une 404 et un PDF, et signale les doublons.
- Mise en ligne : droits des fichiers publics forcés et vérifiés après la compilation.

### Performance
- PDF publics du CV en cache disque (hors du dossier public), purgé à chaque modification du contenu ; le CV complet
  de l'espace recruteur n'est jamais mis en cache.

## [2.0.0] — 2026-11-04 (mise en ligne)

Version finale, mise en ligne après la recette complète de la candidate `2.0.0-rc.1` sur un site de test séparé.
Aucun changement de code depuis `2.0.0-rc.1`.

## [2.0.0-rc.1] — 2026-10-31

Première candidate, recettée sur un site de test séparé avant la mise en ligne. Regroupe les lots tableau de
bord, communication, notifications, validation à distance, Cerveau de l'IA, RGPD et sécurité des envois, ainsi que
les corrections de recette.

### Ajouté
- Réseaux sociaux gérés dans l'admin (Communication › Réseaux sociaux) : LinkedIn, GitHub, YouTube, Facebook,
  Instagram, X, Discord, Telegram, Malt ou « Autre », icônes locales, ordre, afficher / masquer, endroits
  d'affichage (pied de page, maintenance, espace recruteur, données structurées) ; LinkedIn repris, visible partout.
- Messages : filtres Tous / Non lus / Lus / Répondus / Archivés avec leur nombre, filtre par type (formulaire de
  contact, question d'un recruteur), recherche (nom, entreprise, message), tri par date, pagination au-delà de 25 ;
  filtre, type et tri mémorisés sur le compte admin ; « Marquer comme répondu » / « non répondu » avec la date ;
  archivage ; actions groupées (lus, répondus, archiver, supprimer avec confirmation) ; pastille du menu
  « Messages » (non lus) ; tuile du tableau de bord : messages lus en attente de réponse.
- Admin : page « Communication » en six onglets (Vue d'ensemble, E-mails, Notifications, Discord, Telegram,
  Validation à distance), chacun avec un encadré « À quoi ça sert / Qui le voit / Ce qu'il faut régler » et des
  aides « ? » ; entrée dans le menu ; la tuile « E-mails » du tableau de bord devient « Communication ».
- E-mails : objet, introduction et signature modifiables pour chaque type (variables `{nom}`, `{entreprise}`,
  `{delai}`, texte simple), aperçu HTML et texte avant d'enregistrer, « Rétablir le texte d'origine », historique.
- E-mail de refus d'une demande d'accès (sans motif, sans adresse de réponse).
- Déploiement : refus si l'adresse « Recrutement » ou « Général du site » est vide ou fictive
  (`app:communication:check`).
- Pied de page public : « Site conçu et développé avec ❤️ et ☕ sous Symfony » (émojis accessibles) et numéro du
  site sans préversion, lié à la nouvelle page publique « Nouveautés » (`/nouveautes`, résumé `NOUVEAUTES.md` sans
  détail technique).
- Telegram : code PIN demandé par le bot après « Accepter » ou « Refuser » (réponse forcée ; message du code
  supprimé aussitôt ; 5 essais puis bouton bloqué), à la place de la double confirmation « Oui / Non ».
- Discord : application (bot dans le salon configuré, vrais boutons interactifs, fenêtre « Code PIN »), point
  d'entrée `/discord/interactions` avec signature Ed25519 obligatoire ; webhook en repli.
- Paramètres : « Relève Telegram : active / arrêtée » (développement) et état de l'application Discord ;
  avertissement dans la notification Telegram si la relève est arrêtée.
- Discord : vrais boutons sous la notification (« ✅ Accepter », « ❌ Refuser », « 🔗 Voir dans l'admin » ; boutons
  de type lien envoyés avec `with_components=true`), repli automatique sur les liens dans le texte si Discord les
  refuse ; signaux d'intérêt : « Voir dans l'admin » seulement.
- Page de maintenance repensée : fond sombre animé discret (coupé si moins d'animations), logo (bannière sur
  ordinateur, carré « GC » sous 640 px), message réglable (par défaut « Quelques améliorations sont en cours.
  Merci de votre patience. »), date et heure de réactivation avec compte à rebours (date en clair sans
  JavaScript, masqué aux lecteurs d'écran), « Retour imminent » et rechargement toutes les 60 s une fois la
  date passée, lien LinkedIn ; adaptative de 375 à 2560 px sans défilement ; Retry-After calculé depuis la date ;
  adresses IP autorisées (facultatif) ; bouton « Aperçu de la page de maintenance » dans Paramètres.
- Validation à distance en local : commande `app:telegram:poll` (relève des appuis sans webhook, mêmes
  contrôles, absente en production, refus si un webhook est déclaré).
- Notification de test avec boutons et liens d'essai (parcours complet sans rien décider) ; liens Discord
  locaux signalés.
- Notifications de nouvelle demande et de signal d'intérêt : score avec son niveau en clair et 2 ou 3 critères,
  signaux reçus ; confirmation Telegram « Confirmer l'acceptation de <nom> (<entreprise>) ? », résultat daté.
- Validation à distance des demandes d'accès (désactivée par défaut) : boutons Telegram « Accepter »,
  « Refuser », « Voir dans l'admin » (deux appuis, webhook vérifié par en-tête secret, conversation et compte
  autorisés), liens Discord à usage unique (page de confirmation, code PIN, 5 essais) ; jetons valables 72 h ;
  même traitement que l'admin ; journal de sécurité et tuile Sécurité ; commande `app:telegram:webhook`.
- Paramètres : notification de test par canal (résultat en toast, erreur claire) ; interrupteur
  « Validation à distance » et code PIN.
- Demande d'accès : champs facultatifs « Fonction » et « Site web de l'entreprise » ; notification de
  nouvelle demande détaillée sur tous les canaux (domaine de l'e-mail, début du message, synthèse ; jamais
  l'IP ni l'e-mail complet). Politique de confidentialité mise à jour (Telegram et Discord, hors UE).
- Espace recruteur : nouvelle demande d'une adresse déjà en attente → e-mail « Votre demande est déjà en
  cours d'examen » (au plus une fois par 24 h), sans renotifier l'admin ; message affiché inchangé.
- Tests : base de test migrée avant chaque suite ; aucune trace des tests de bout en bout dans la base de
  développement (purge et vérification en fin de suite).
- Admin : page « Cerveau de l'IA » — ce que sait l'assistant en trois niveaux (socle de sécurité fixe,
  résumé du site, fiches), fiches de connaissances (jetons, plafond de taille, historique, export Markdown,
  blocage des données privées), banc d'essai, questions sans réponse ; import des anciennes directives
  (`app:ai:import-directives`, brouillons).
- Assistant : contrôle avant affichage (tout élément absent du site et des fiches fait remplacer la réponse
  par « Ce point n'est pas précisé… »), température 0, refus de toute question hors du parcours.
- Espace recruteur : formulaire de question privé, proposé quand l'assistant n'a pas l'information.
- Fenêtres et messages (SweetAlert2 11.26.25, chargé à la demande, thème du site, clair et sombre) :
  confirmation des actions importantes (avec une page de confirmation sans JavaScript), messages de succès et
  d'information en toasts, bienvenue du recruteur à sa première connexion seulement.
- Tableau de bord de l'admin en tuiles (recruteurs, messages, contenu à compléter, assistant IA, e-mails,
  sécurité, sauvegardes et tâches planifiées, site, calculatrice), statut en icône et texte, bouton
  « Actualiser », pastille du menu, récapitulatif à l'ouverture si quelque chose a changé.
- Résumé hebdomadaire du tableau de bord par e-mail (lundi 7 h, `app:dashboard:weekly-summary`, chiffres
  seulement) ; vérification quotidienne des modèles Mistral (`app:ai:refresh-models`, 3 h 30, sans jeton).
- Passages des tâches planifiées notés en base (`heartbeat`) ; limites de débit atteintes notées au journal
  de sécurité.
- Admin : journal des versions en lecture seule (contenu de `CHANGELOG.md`, HTML brut échappé), lié depuis
  la version du pied de page.
- Test du `rp_id` WebAuthn (`RELYING_PARTY_ID` : `localhost` en local, `guillaume-couget.fr` sur le serveur).
- Espace recruteur : accusé de réception de la demande d'accès (e-mail au recruteur, réponse sous 24 h
  ouvrées, sans lien) ; page « Mes données » avec suppression définitive par le recruteur (fiche, évènements,
  échanges avec l'assistant ; déconnexion ; notification sans donnée personnelle).
- Test du lien de connexion expiré après 30 minutes (horloge simulée de la vérification des signatures).
- E-mails résilients : une panne du serveur d'envoi ne fait plus échouer la page ; l'e-mail est gardé
  (`failed_email`), l'erreur journalisée sans donnée personnelle ; tableau de bord : « X e-mails non envoyés »
  avec **Renvoyer** (liens de connexion recréés) et **Envoyer un e-mail de test**.
- Nonce CSP stable pendant les navigations Turbo (`X-Csp-Nonce`, aussi pour la barre de débogage en
  développement) : plus d'erreur CSP dans la console.
- Tests : refus Turnstile (clés de test de Cloudflare), contrôle des clés au déploiement, panne d'envoi et
  renvoi, accès échu à 90 jours et renouvelé (horloge simulée), widgets Turnstile réels dans Playwright,
  e-mails contrôlés (objet, expéditeur, lien) et journal sans erreur dans le parcours recruteur.

### Modifié
- Tests de bout en bout : base dédiée `guillaume_c2_e2e` (copie en lecture seule de la base de développement à chaque
  lancement) et cache à part ; la suite ne modifie plus jamais les réglages réels (validation à distance, coordonnées,
  maintenance) ; contrôle final en lecture seule de la base de développement.
- Déploiement : contrôle des clés Turnstile réécrit en PHP (`scripts/security/check-turnstile-keys.php`), identique
  sous Windows et sous Linux.
- Messages : le statut « Traité » devient « Répondu » (date : réception du message), « Nouveau » devient « Non lu ».
- Adresse de réponse : seulement sur les e-mails aux recruteurs validés ; accusé de réception, demande en cours et
  refus renvoient vers le formulaire de contact. `MAILER_REPLY_TO` n'est plus qu'un repli.
- Paramètres : notifications et validation à distance déplacées dans Communication (`/parametres/notifications`
  redirige) ; code PIN saisi deux fois, avec confirmation.
- Texte après une demande d'accès recruteur : « Demande reçue. Vous recevrez un e-mail dès sa validation
  (sous 24 h ouvrées). », identique quelle que soit l'adresse.
- Les confirmations `data-turbo-confirm` (fenêtre du navigateur) sont remplacées par la fenêtre du site.
- Règle de mise en ligne : aucun déploiement sans le « TOP » écrit de Guillaume.
- Projets : un onglet de type n'est proposé que si ce type a un projet visible (études de cas en brouillon :
  ni onglet, ni titre vide, ni lien mort).
- Synchronisation : une expérience ajoutée au fichier ne remplace plus toutes les autres (rapprochement par
  rubrique, entreprise, poste et début).
- Texte justifié (classe `.text-prose-justify`) sur le texte courant du site public et de l'espace recruteur :
  profil, accroche, missions des expériences, rubriques en phrases (pas les logiciels), projets, pages
  légales, présentation des outils de calcul ; aligné à gauche sous 640 px ; titres, boutons, menus,
  tableaux, formulaires, assistant, pied de page et admin jamais justifiés.
- Césure française côté serveur (bibliothèque `vanderlee/syllable`, filtres Twig `hyphenate` et
  `hyphenate_html`) : Edge et Chrome sous Windows ne coupent pas le français ; sans césure, le texte justifié
  laissait jusqu'à 1,8 em entre les mots.
- CV PDF ATS : police 10 pt (au lieu de 9,5 ; ligne de contact comprise, au lieu de 8,5), interligne 1,25, marges 15 / 13 mm : 3 pages au lieu de 4, sans
  retirer de contenu ; toujours aligné à gauche, sans césure.
- Césure du site limitée aux mots de 9 lettres ou plus.
- Turnstile : vérification lancée à la première interaction avec le formulaire (un widget et un jeton par
  formulaire, rappels propres à chacun), relancée après un refus, jeton expiré rafraîchi, envoi immédiat mis
  en attente du jeton ; thème « auto », français. Refus distingués : « a échoué » / « a expiré ou a déjà servi ».
- Notifications par e-mail : adresse du compte admin (Paramètres › Notifications), seule source ; la variable
  `NOTIFICATION_EMAIL` disparaît.

### Corrigé
- Admin sur mobile : débordements horizontaux de la page Communication et de l'en-tête à 375 px ; contraste des
  titres du menu latéral en mode sombre.
- Validation à distance : la relève Telegram ne s'arrête plus à la première coupure réseau ; webhook Telegram,
  interactions Discord et page de confirmation utilisables pendant la maintenance.
- `check-staged-assets` : bloque toute valeur des variables Discord et Telegram dans un fichier versionné.
- Journal des versions (admin) : la fin du journal n'était plus affichée au-delà de 20 000 octets (limite du
  nettoyeur HTML) ; nettoyeur dédié, même règles.
- Maintenance : date et heure saisies à l'heure de Paris, enregistrées en UTC (compte à rebours et
  Retry-After justes).
- Validation à distance : boutons et liens absents sans explication ; interrupteur actif, chaque notification
  de nouvelle demande les porte, ou dit ce qui manque (code PIN, compte Telegram). `TELEGRAM_USER_ID`
  facultatif en conversation privée ; le secret du webhook n'est plus exigé pour proposer les boutons.
- Notifications parasites : aucun envoi vers Discord ou Telegram pour une adresse de test (`example.test`), ni
  en développement pendant les tests (coupure qui pouvait être ignorée par un processus resté sur l'ancienne
  configuration).
- Tests de bout en bout : les réglages de la validation à distance ne sont plus remis en l'état s'ils ont été
  changés pendant la suite.
- Journaux : jeton du bot Telegram, jeton du webhook Discord et jetons des liens masqués.
- Paramètres : message d'erreur de l'adresse LinkedIn traduit (la clé brute s'affichait).
- Banc d'essai du Cerveau de l'IA : « probablement utilisée » calculé sur les termes propres à la fiche
  (hors mots vides et termes déjà présents dans le site, comme le nom de Guillaume), avec un seuil.
- Journal de sécurité : un lien de connexion recruteur refusé n'est plus compté comme un échec de connexion
  à l'admin (tuile Sécurité du tableau de bord).
- Tableau de bord : contenu à compléter en deux compteurs (« Bloquant pour le TOP », « Facultatif » sans
  alerte) ; tâches planifiées « Non applicable en local » hors production ; normes de la calculatrice en
  information.
- CV PDF : `jSmaxChar => 0` ne supprimait pas l'espacement entre les lettres (dans mPDF, 0 veut dire « sans
  plafond ») : les intitulés en gras des lignes justifiées étaient espacés ; la dernière ligne d'un paragraphe
  reprenait les espaces élargis de la précédente.
- Espace recruteur : le widget Turnstile de « Déjà validé ? » restait affiché sur « Vérification… » (deux
  vérifications lancées en même temps sur la page).
- Navigation Turbo : scripts en ligne refusés par la CSP (nonce de la nouvelle réponse).
- Une panne du serveur d'envoi provoquait une erreur 500 (demande d'accès, formulaire de contact).

## [2.0.0-alpha.9] — 2026-10-24

Lot 8 : finitions (pages légales, référencement, accessibilité, performance, maintenance, migration, sécurité).

### Ajouté
- LinkedIn dans le pied de page et dans les données structurées (réglable dans Paramètres).
- Mode maintenance (Paramètres) : site public en 503 avec message, admin accessible.
- Redirections 301 des adresses publiques de l'ancien site.
- `app:legacy:migrate` : reprise des projets (en brouillon), de LinkedIn et des données de calcul de
  l'ancien site, avec inventaire, aperçu puis `--apply`, lecture seule de l'ancienne base.
- Audit d'accessibilité axe-core (WCAG 2.1 A et AA) de toutes les pages publiques, de l'espace recruteur
  connecté et des pages principales de l'admin (comptes de test sans clé YubiKey, supprimés après les
  tests), en clair et en sombre.
- Image de partage (`public/partage/guillaume-couget-partage.jpg`, 1200 × 630), seule image non WebP
  permise dans `public/` par `check-staged-assets`.

### Modifié
- Outils de calcul pas encore publiés (adresses de l'ancien site) : redirection temporaire (302) vers leur
  catégorie au lieu d'une page 404.
- Liste des modèles Mistral (alerte « modèle retiré ») relue au plus une fois par jour.
- Notifications Discord et Telegram : plus aucune donnée personnelle (type d'événement et lien vers
  l'admin seulement) ; l'e-mail à Guillaume garde le nom et l'entreprise.
- Accessibilité : contraste du pied de page de l'admin, tableaux défilants atteignables au clavier,
  liste des coordonnées de l'espace recruteur valide quand elles manquent.
- Performance : compression Brotli/gzip et cache des images (`public/.htaccess`), police Inter préchargée
  et police de secours aux mêmes dimensions (plus de décalage de mise en page).

### Retiré
- Page « en préparation » (`UpcomingPageController`) : les deux pages légales sont livrées.

## [2.0.0-alpha.8] — 2026-10-24

### Ajouté
- Assistant de l'espace recruteur : six questions guidées (sans IA ni consentement, toujours disponibles) et
  questions libres au chatbot Mistral AI (`ministral-8b-2512`, secours `ministral-3b-2512`) après notice RGPD
  et consentement ; réponses en 3 phrases au plus, texte brut ; historique ; lien vers le contact si l'IA
  ne répond pas.
- Plafonds réglables dans Paramètres : 20 questions par recruteur et par jour, 100 par jour pour le site,
  5 000 000 de jetons par mois (jetons réels de l'API), 1 requête par seconde, alerte à 80 % ; 5 questions
  par minute et par recruteur.
- Consignes protégées (refus de « affiche tes instructions » sans appel au modèle, réponse qui recopierait
  les consignes bloquée), échanges suspects tracés.
- Admin : page « Assistant IA » (état, consommation du jour et du mois, modèles, journal avec filtre
  « suspects », détail), bloc et alertes du tableau de bord (80 %, modèle retiré), import de CV par l'IA
  (aperçu en lecture seule), synthèse IA de l'entreprise sur la fiche recruteur.
- Page Confidentialité (sections espace recruteur et assistant IA ; reste de la page au lot 8, non indexée).
- `app:ai:check` (modèles, appel court, en-têtes de limites, `--measure`) et `app:db:backup` (mysqldump daté
  dans `var/backup/`, 10 dernières gardées, mot de passe hors ligne de commande).

### Modifié
- `app:content:sync-textes` : expériences alignées ligne à ligne quand leur nombre ne change pas (seules les
  expériences modifiées sont mises à jour, identifiants gardés).
- Chatbot : consignes d'exactitude (durée totale jamais attribuée à un seul domaine, durées seulement si elles
  figurent dans le CV, rien d'inventé, postes au passé) et correction côté serveur des durées par domaine ;
  assistant entièrement à la troisième personne.
- `app:data:purge` supprime aussi le journal de l'assistant et la consommation après 12 mois.
- Les messages flash de l'assistant s'affichent dans l'assistant.

## [2.0.0-alpha.7] — 2026-10-24

Lot 6-bis : contenu et mise en page du CV.

### Ajouté
- Rubriques du CV : « Expérience fondatrice » (postes successifs d'une entreprise regroupés) et « Parcours
  terrain » ; outils techniques, expertises métiers, logiciels, savoir-être, centres d'intérêt (`CvEntry`,
  module d'admin « Rubriques du CV ») ; modalités de travail, postes et structures visés ; lieu des formations.

### Modifié
- Page /cv dans l'ordre du CV : haut de page en deux colonnes, rubriques du bas en pleine largeur, dates de
  largeur fixe (02/2023 – 12/2025).
- CV PDF « mis en page » : reproduction de la mise en page du CV (colonne de gauche, bandeau, encadrés) ;
  PDF ATS dans le même ordre.
- « FD X 15-140 » (fascicule de documentation) à la place de « NF X 15-140 », partout.

### Retiré
- `app:content:import-textes` (remplacée par `app:content:sync-textes`).

## [2.0.0-alpha.6] — 2026-10-24

Lot 6 : espace recruteur et formulaire de contact.

### Ajouté
- `/contact` (message enregistré, notification sans contenu sensible) et `/espace-recruteur` (demande d'accès
  avec score, lien de connexion unique de 30 min, espace privé : coordonnées, CV complet, projets réservés).
- Admin : recruteurs (valider, refuser, révoquer, renouveler, création manuelle, aperçu en lecture seule),
  messages, paramètres ; notifications e-mail / Discord / Telegram.

## [2.0.0-alpha.5] — 2026-10-23

Lot 5 : calculatrice.

### Ajouté
- Registre des outils, réglages dans l'admin, calculs en JavaScript testés contre l'ancien site (golden) :
  électricité, électronique, capteurs, mécanique, impression 3D, convertisseur, thermodynamique, éclairage,
  terrariophilie.
- Chambre froide (R-290) et enceinte climatique v2 (modes simple et pro, régulation PID), profils de consigne.

## [2.0.0-alpha.4] — 2026-10-22

Lot 4 : projets.

### Ajouté
- Études de cas (plan imposé) et Labo ; publication seulement si complet ; Markdown nettoyé ; images WebP ;
  export et import ZIP (y compris les archives de l'ancien site).

## [2.0.0-alpha.3] — 2026-10-21

Lot 3 : accueil, CV interactif et PDF.

### Ajouté
- Accueil selon la maquette, CV `/cv`, PDF publics (mPDF) sans coordonnées ; règle « rien d'incomplet en public ».

## [2.0.0-alpha.2] — 2026-10-21

Lot 2 : admin et authentification.

### Ajouté
- Comptes admin, mot de passe et clé physique (YubiKey, WebAuthn) obligatoires, procédure de secours hors du
  site, journal des connexions, back-office du CV.

## [2.0.0-alpha.1] — 2026-10-21

Lot 1 : socle.

### Ajouté
- Symfony 8.1 / PHP 8.4, Doctrine, Vite + Tailwind CSS v4 + Stimulus + Turbo, design system et mode sombre,
  en-têtes de sécurité (CSP à nonce), intégration continue (GitHub Actions), script de mise en ligne.
