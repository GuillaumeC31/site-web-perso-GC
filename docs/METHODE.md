# Méthode de travail

Ce document décrit comment le site guillaume-couget.fr est cadré, développé, vérifié et mis en ligne. Les
exemples viennent de l'historique réel du projet ([CHANGELOG.md](../CHANGELOG.md)).

## 1. Cadrage

- **Cahier des charges** écrit par Guillaume : pages, fonctions, règles de sécurité et de confidentialité, niveau
  de qualité attendu (par exemple, analyse statique PHPStan au niveau 6 au minimum).
- **Textes du site** rédigés et validés par Guillaume dans un fichier unique. Une commande les reporte dans la base,
  en montrant d'abord un aperçu des changements ; rien n'est écrit avant sa validation.
- **Règles de confidentialité** :
  - CV public allégé : deux ou trois points par poste, le détail étant réservé à l'espace recruteur ;
  - coordonnées et détails du parcours visibles seulement dans l'espace recruteur, dont l'accès est validé à la
    main ;
  - données sensibles jamais publiées : une ligne marquée comme telle dans le fichier des textes n'est jamais lue,
    et un test vérifie qu'elle n'apparaît ni sur le site, ni dans l'espace recruteur, ni dans les PDF, ni dans ce
    que reçoit l'assistant IA.

## 2. Organisation en lots

- Le projet avance par **lots** : socle, administration et authentification, accueil et CV, projets, calculatrice,
  espace recruteur, assistant IA, finitions, puis corrections. Chaque lot a ses consignes écrites, ses critères
  d'acceptation et ses tests.
- Le développement se fait sur une **branche dédiée**, fusionnée seulement après validation.
- **Versions** : une préversion `2.0.0-alpha.N` par lot validé, une candidate `2.0.0-rc.1` recettée sur un site de
  test séparé, la mise en ligne en `2.0.0` (4 novembre 2026), puis `2.0.x` pour les corrections et `2.x.0` pour
  les nouveautés.
- Un **tag** n'est posé que lorsque l'intégration continue est verte sur le commit exact. Elle lance notamment :
  contrôle anti-secrets, audit des dépendances, Vitest, contre-calculs des outils de calcul, PHP-CS-Fixer,
  PHPStan, contrôle du schéma de la base, PHPUnit et audit des protections des routes de l'admin.

## 3. Rôle de l'IA

| Qui | Rôle |
| --- | --- |
| Guillaume Couget | Chef de projet et expert métier : cahier des charges, découpage en lots, décisions techniques et de sécurité, rédaction et validation des contenus, validation métier des outils de calcul (formules, hypothèses, cas de contrôle), recette complète avant chaque version, et accord final (« TOP ») avant toute mise en ligne. |
| Claude (assistant conversationnel d'Anthropic) | Conseil et relecture : aide à la conception et aux choix (sécurité, RGPD, hébergement, versionnage), relecture critique de chaque rapport de développement, rédaction des consignes de chaque lot, accompagnement pas à pas de la mise en production. |
| Claude Code (assistant de développement d'Anthropic) | Développement sous consignes : écriture du code et des tests, documentation, livraison de chaque lot avec un rapport détaillé soumis à validation. |

Chaque rapport de Claude Code est relu par Guillaume avec l'aide de Claude, avant toute fusion. Plusieurs écarts
ont été détectés et corrigés à cette étape. Les décisions, la validation et la responsabilité du résultat restent
celles de Guillaume.

## 4. Validation métier des outils de calcul

- Chaque outil repose sur une **formule** et une **source** (norme ou ouvrage) ; les normes citées sont recensées
  dans [NORMES_REFERENTIEL.md](NORMES_REFERENTIEL.md) et sont en cours de validation dans l'admin.
- Règle pour tout nouvel outil : au moins **5 cas de référence** (cas courant, bornes, valeurs invalides) vérifiés
  par Guillaume avant publication ; tant qu'un outil n'est pas validé, son adresse renvoie vers sa catégorie.
- Les outils les plus complexes (chambre froide, enceinte climatique) ont des cas de contrôle calculés à la main,
  décrits dans leur documentation ([CHAMBRE_FROIDE_V2.md](CHAMBRE_FROIDE_V2.md),
  [ENCEINTE_CLIMATIQUE_V2.md](ENCEINTE_CLIMATIQUE_V2.md)), et un contre-calcul indépendant en Python relancé par la
  CI.
- Les outils repris de l'ancien site sont testés contre les résultats de l'ancien site (cas de référence).

## 5. Recette

- Guillaume teste les **parcours complets** avant chaque version, y compris en se mettant à la place d'un
  recruteur : demande d'accès, validation à distance depuis le téléphone, connexion par lien à usage unique,
  espace recruteur, assistant, export et suppression de ses données. Les tests se font sur ordinateur et sur
  téléphone.
- Exemple : la recette de la candidate `2.0.0-rc.1` a conduit à alléger le CV public avant la mise en ligne
  (quatre corrections de recette).
- Des tests automatiques complètent la recette : parcours recruteur Playwright sur ordinateur et en largeur de
  téléphone, audit d'accessibilité axe-core en thème clair et sombre.

## 6. Sécurité

- **Administration** : mot de passe et clé physique (YubiKey, WebAuthn) obligatoires ; adresse non devinable,
  absente du HTML public sauf sur un appareil de confiance.
- **Navigateur** : politique de sécurité du contenu (CSP avec nonce) et en-têtes de sécurité.
- **Formulaires publics** : anti-robot (Cloudflare Turnstile), champ piège, limites de débit.
- **Cache** : aucune page privée ou personnalisée gardée en cache (`no-store, private`, `Vary: Cookie`).
- **Après chaque mise en ligne** : script de contrôle des en-têtes, lancé depuis le poste (page, fichier statique,
  404, PDF, page privée), qui signale aussi les doublons.
- **Secrets** : jamais dans le dépôt (contrôle à chaque commit et dans la CI).
- **RGPD** : consentement explicite, durées de conservation appliquées par des tâches planifiées, export et
  suppression de ses données par le recruteur lui-même, registre interne des traitements.

## 7. Mise en ligne

- Rien n'est publié sans l'accord écrit de Guillaume (« TOP ») ; une validation de lot, une fusion ou un tag ne
  valent pas accord.
- Seule une version taguée peut être mise en ligne : le script refuse un commit sans tag et, en production, les
  préversions.
- Un retour arrière vers la version précédente est toujours possible, selon une procédure écrite.
- Chaque version publiée reçoit sa Release GitHub, avec les notes du journal des versions.

## 8. Amélioration continue

Quelques problèmes trouvés en recette ou en production, puis corrigés dans la version suivante :

- **En-têtes de sécurité en double** (2.0.1) : une règle du serveur web s'appliquait aussi aux réponses de PHP. Le
  contrôle des en-têtes signale désormais les doublons.
- **Listes de l'admin en erreur** (2.0.1) : quatre entités ne pouvaient pas s'afficher en texte. Un test ouvre
  maintenant chaque liste et chaque formulaire de l'admin.
- **Formulaire de l'admin qui n'enregistrait pas** (2.0.2) : un formulaire ouvert dans une Turbo Frame n'avait pas
  d'adresse d'envoi ; le navigateur l'envoyait à la liste, qui le refusait. L'adresse est maintenant explicite, et
  un repli général couvre les autres formulaires.
- **Réponses d'erreur gardées en cache** (2.0.2) : une page 404 restait en cache avec des en-têtes en double. Toute
  réponse d'erreur est désormais exclue du cache, et le contrôle vérifie une 404.
- **Adresse réelle des visiteurs derrière les mandataires** (2.0.3) : les limites de débit et le journal de
  sécurité voyaient l'adresse du mandataire (proxy). Seule une liste versionnée de mandataires de confiance est
  acceptée, et les en-têtes forgés par un client sont ignorés.
- **Signal d'intérêt envoyé sans clic** (2.0.3) : il ne part plus que sur le bouton « Je suis intéressé », jamais
  sur un simple affichage ou un préchargement.

Quand c'est possible, chaque correction ajoute un test ou un contrôle automatique pour que le problème ne revienne
pas.
