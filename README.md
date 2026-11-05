# guillaume-couget.fr — code source

Code source du site [guillaume-couget.fr](https://guillaume-couget.fr), réalisé par Guillaume Couget.

Les données de démonstration (profil, parcours, contacts) sont fictives.

## Méthode de travail

Ce site est conçu et piloté par Guillaume Couget. Le code est écrit avec l'assistance de l'IA, sous sa direction :
chaque choix est décidé, vérifié et validé par lui avant la mise en ligne.

| Qui | Rôle |
| --- | --- |
| Guillaume Couget | Chef de projet et expert métier : cahier des charges, découpage en lots, décisions techniques et de sécurité, rédaction et validation des contenus, validation métier des outils de calcul (formules, hypothèses, cas de contrôle), recette complète avant chaque version, et accord final (« TOP ») avant toute mise en ligne. |
| Claude (assistant conversationnel d'Anthropic) | Conseil et relecture : aide à la conception et aux choix (sécurité, RGPD, hébergement, versionnage), relecture critique de chaque rapport de développement, rédaction des consignes de chaque lot, accompagnement pas à pas de la mise en production. |
| Claude Code (assistant de développement d'Anthropic) | Développement sous consignes : écriture des tests, documentation, livraison de chaque lot avec un rapport détaillé soumis à validation. |

Les décisions, la validation et la responsabilité du résultat restent celles de Guillaume.

Chiffres de la version 2.0.3, calculés à partir du code de cette version :

- plus de 500 tests PHPUnit, unitaires et fonctionnels (plus de 770 cas exécutés) ;
- plus de 190 tests Vitest (calculs en JavaScript) ;
- plus de 90 scénarios Playwright (parcours de bout en bout, sur ordinateur et en largeur de téléphone) ;
- PHPStan niveau 6 sans erreur, vérifié par l'intégration continue sur le commit de la version ;
- versions publiées depuis la mise en ligne : 4 (2.0.0 à 2.0.3).

Détail de la méthode : [docs/METHODE.md](docs/METHODE.md). Historique des versions : [CHANGELOG.md](CHANGELOG.md).

## Le site

- **CV numérique** : parcours, compétences, formations et certifications, avec un CV à télécharger en PDF
  (version mise en page et version lisible par les logiciels de recrutement).
- **Espace recruteur sécurisé** : accès sur demande, validé à la main, connexion par lien à usage unique ; CV
  complet et assistant qui répond aux questions à partir du contenu du site.
- **Outils de calcul** : électricité, thermodynamique et froid, mesures et capteurs, mécanique, électronique,
  éclairage, impression 3D, conversion d'unités.
- **Projets** : réalisations classées par catégorie, avec leurs illustrations.

## Stack technique

| Domaine | Outils |
| --- | --- |
| Back-end | Symfony 8.1, PHP 8.4, Doctrine ORM, MariaDB |
| Front-end | Vite, Tailwind CSS, Stimulus et Turbo (Hotwire), Twig Components |
| Documents | mPDF (CV en PDF, QR code) |
| Assistant | Mistral AI (réponses limitées au contenu du site, filtrage des données privées) |

## Points forts

- **Sécurité** : administration protégée par mot de passe et clé physique (YubiKey, WebAuthn), adresse de
  l'administration non devinable, politique de sécurité du contenu (CSP avec nonce), en-têtes de sécurité,
  limites de débit, anti-robot Cloudflare Turnstile sur les formulaires publics, journal de sécurité.
- **RGPD** : consentement explicite, durées de conservation appliquées par des tâches planifiées, export et
  suppression de ses données par le recruteur lui-même.
- **Accessibilité** : navigation au clavier, contrastes vérifiés, pages testées avec axe-core.
- **Tests** : PHPUnit (tests unitaires et fonctionnels), Vitest (calculs en JavaScript), Playwright (parcours de
  bout en bout), PHPStan (analyse statique), PHP-CS-Fixer.

## Contact

Pour toute question, utilisez le [formulaire de contact du site](https://guillaume-couget.fr/contact).

## Licence

Tous droits réservés (voir [LICENSE](LICENSE)).
