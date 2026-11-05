# Veille de sécurité

Journal des failles qui concernent le site (PHP, Symfony, dépendances, hébergement) : date, référence,
impact, action menée.

| Date | Référence | Composant | Impact | Action menée |
|---|---|---|---|---|
| 2026-10-23 | CVE-2026-109618, CVE-2026-109619 (« PHP2Shell ») | PHP (cœur), toutes les installations qui servent des pages web (PHP-FPM, CGI, mod_php…) | Critique (CVSS v4.0 9.2) : exécution de code à distance sans authentification dans certaines conditions ; versions concernées : PHP 8.4 avant 8.4.27 (et 8.2, 8.3, 8.5 avant 8.2.35, 8.3.36, 8.5.12). Preuve de concept publique selon le Centre canadien pour la cybersécurité (AL26-026). | Version minimale du site : PHP 8.4.27 (CI, script de déploiement qui refuse une version inférieure, README) ; `session.upload_progress.enabled = 0` et limites d'envoi au plus juste dans `public/.user.ini` (atténuations : elles ne remplacent pas la mise à jour) ; mise à jour de PHP sur le poste (Laragon) dès que la version est disponible ; à vérifier chez l’hébergeur avant le premier déploiement. |

## Sources consultées (2026-10-23)

- Centre canadien pour la cybersécurité, alerte AL26-026 : https://cyber.gc.ca/en/alerts-advisories/al26-026-critical-vulnerabilities-impacting-php-cve-2026-109618-cve-2026-109619
- Fastly, analyse technique : https://www.fastly.com/es/blog/critical-rce-vulnerabilities-discovered-core-php-cve-2026-109618-cve-2026-109619
- À vérifier à la source : annonces de php.net (versions 8.4.27 et suivantes) et avis de sécurité du dépôt php-src sur GitHub.
