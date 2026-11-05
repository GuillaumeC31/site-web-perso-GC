# Référentiel normatif — liste de travail

> Pense-bête des normes, guides et textes réglementaires utiles à mes métiers et à mes projets.
> Base du futur référentiel public du site (page `/normes`, lot 5-ter).
> Créé le 23/10/2026, complété le 23/10/2026. **Aucune ligne n'est vérifiée** : chaque statut est à contrôler avant publication.

## Règles (droit d'auteur)

- ✅ Autorisé : référence, titre officiel, éditeur, **résumé écrit avec mes mots**, lien vers la source officielle.
- ✅ La **réglementation** (lois, décrets, arrêtés, règlements et directives UE) est publique : citation et lien libres (Légifrance, EUR-Lex).
- ❌ Interdit : recopier le texte, les tableaux, les abaques ou les figures d'une norme, ou diffuser un PDF de norme.
- ❌ Pas de résumé assez détaillé pour remplacer l'achat de la norme.

## Légende

| Colonne | Contenu |
|---|---|
| **Type** | **R** = réglementation (obligatoire) · **N** = norme (volontaire, sauf si un texte la rend obligatoire) · **G** = guide ou spécification · **D** = document de référence non normatif |
| **Application / obligatoire si** | Cas d'application, ou texte qui rend la norme obligatoire |
| **Textes liés** | Normes, guides ou règlements à lire ensemble |
| **Mots-clés** | Types de projets, pour retrouver vite les textes d'un projet |
| **Source** | Où vérifier et acheter (voir « Où vérifier ») |
| **Statut** | ⚠️ à vérifier · ✅ en vigueur (vérifiée le JJ/MM/AAAA) · ❌ remplacée par … |
| **Outils** | Outils de la calculatrice qui citent ce texte |

## Où vérifier

| Source | Pour |
|---|---|
| AFNOR (boutique.afnor.org) | Normes NF, NF EN, NF EN ISO, NF DTU : version en vigueur, date, texte remplaçant |
| IEC (webstore.iec.ch) | Normes IEC |
| ISO (iso.org) | Normes ISO |
| Légifrance (legifrance.gouv.fr) | Lois, décrets, arrêtés, codes |
| EUR-Lex (eur-lex.europa.eu) | Règlements et directives européens |
| Autres | ich.org (pharma), bipm.org (métrologie), ipc.org (électronique), astm.org, rfc-editor.org, modbus.org |

## Index des mots-clés

`habitat` · `tertiaire` · `industrie` · `armoire` · `schéma` · `dessin` · `machine` · `ATEX` · `IoT` · `produit` · `CEM` · `PCB` · `froid` · `F-Gas` · `chambre-froide` · `alimentaire` · `CVC` · `ECS` · `énergie` · `GTB` · `supervision` · `enceinte` · `pharma` · `essais` · `mesure` · `métrologie` · `incendie` · `ERP` · `éclairage` · `IRVE` · `photovoltaïque` · `réseau` · `cybersécurité` · `3D` · `terrarium` · `qualité`

---

## 1. Électricité — installations et sécurité des personnes

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| NF C 15-100 | Installations électriques basse tension : conception, protection des personnes et des biens, sections, mise à la terre | N | Obligatoire dans les logements neufs et rénovés via la réglementation de la construction ; référence de fait en tertiaire | UTE C 15-105, NF C 14-100, NF EN 60898-1 | habitat, tertiaire | AFNOR | ⚠️ | Câbles, chute de tension, Ib, protections |
| UTE C 15-105 | Guide de calcul : sections, chutes de tension, courants de court-circuit, longueurs protégées | G | Méthodes de calcul applicables à la NF C 15-100 | NF C 15-100 | habitat, tertiaire, industrie | AFNOR | ⚠️ | Câbles, chute de tension, court-circuit |
| NF C 14-100 | Installations de branchement basse tension (raccordement au réseau public) | N | Partie entre le réseau public et l'installation privée | NF C 15-100 | habitat, tertiaire | AFNOR | ⚠️ | — |
| NF C 13-100 / NF C 13-200 | Postes de livraison et installations haute tension | N | Sites raccordés en HTA | NF C 15-100, NF EN 60076 | industrie | AFNOR | ⚠️ | Transformateur |
| NF C 18-510 | Opérations sur les ouvrages et installations électriques : prévention du risque électrique, habilitations | N | Référence pour satisfaire aux obligations du Code du travail sur les opérations électriques | Code du travail R4544 | industrie, tertiaire | AFNOR | ⚠️ | — |
| Code du travail, art. R4215-1 et suivants | Obligations du maître d'ouvrage pour la conception et la réalisation des installations électriques des lieux de travail | R | Bâtiments destinés à recevoir des travailleurs | NF C 15-100 | tertiaire, industrie | Légifrance | ⚠️ | — |
| Code du travail, art. R4544-1 et suivants | Opérations sur les installations électriques : habilitation, formation | R | Tout employeur dont le personnel intervient sur ou à proximité d'installations électriques | NF C 18-510 | industrie, tertiaire | Légifrance | ⚠️ | — |
| NF EN 60529 | Degrés de protection des enveloppes (code IP) | N | Choix des enveloppes selon l'environnement | NF EN 62262 | armoire, produit | AFNOR | ⚠️ | Indices IP |
| NF EN 62262 | Degrés de protection contre les impacts mécaniques (code IK) | N | Choix des enveloppes exposées aux chocs | NF EN 60529 | armoire, produit | AFNOR | ⚠️ | Indices IP / IK |
| NF EN 60898-1 | Disjoncteurs pour usage domestique et analogue | N | Protection des circuits en habitat et petit tertiaire | NF C 15-100 | habitat, tertiaire | AFNOR | ⚠️ | Choix du disjoncteur |
| NF EN 60947-2 | Disjoncteurs industriels basse tension | N | Protection en industrie et tableaux de puissance | NF EN 61439 | industrie, armoire | AFNOR | ⚠️ | Choix du disjoncteur |
| NF EN 61439 (série) | Ensembles d'appareillage basse tension (tableaux et armoires) : conception, vérification, échauffement | N | Fabrication et vérification des tableaux et armoires | NF EN 60204-1, NF EN 60445 | armoire, industrie | AFNOR | ⚠️ | Aéraulique d'armoire |
| NF EN 60076 (série) | Transformateurs de puissance | N | Choix et caractéristiques des transformateurs | NF C 13-100 | industrie | AFNOR | ⚠️ | Transformateur |
| NF EN 60034-1 | Machines électriques tournantes : caractéristiques assignées | N | Moteurs électriques | NF EN 60204-1 | machine, industrie | AFNOR | ⚠️ | Puissance moteur |
| NF C 15-100, section 722 | Installations d'alimentation des véhicules électriques (IRVE) | N | Toute installation de recharge de véhicules | Obligations de qualification des installateurs IRVE (décret à vérifier) | IRVE, habitat, tertiaire | AFNOR | ⚠️ | Temps de charge VE |
| UTE C 15-712-1 | Guide : installations photovoltaïques raccordées au réseau | G | Conception des installations PV | NF C 15-100 | photovoltaïque | AFNOR | ⚠️ | — |
| NF EN 1838 | Éclairage de sécurité : exigences d'éclairement | N | Éclairage de sécurité des bâtiments | NF C 71-800, règlement de sécurité ERP | éclairage, ERP, incendie | AFNOR | ⚠️ | — |
| NF C 71-800 | Blocs autonomes d'éclairage de sécurité (BAES) | N | Choix des blocs d'éclairage de sécurité | NF EN 1838 | éclairage, ERP | AFNOR | ⚠️ | — |

## 2. Bureau d'études — schémas, repérage et dessin technique

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| NF EN 60445 | Identification des bornes et des conducteurs (couleurs, marquages) | N | Câblage des armoires et des installations | NF EN 60204-1, NF EN 61439 | schéma, armoire | AFNOR | ⚠️ | — |
| IEC 60617 | Symboles graphiques pour schémas électriques (base de données) | N | Réalisation des schémas électriques | IEC 61082 | schéma | IEC | ⚠️ | — |
| NF EN IEC 81346 (série) | Principes de structuration et de désignation des équipements (repères -K1, -Q2…) | N | Repérage des équipements dans les schémas et sur site | IEC 61082, NF EN 60445 | schéma, armoire | AFNOR | ⚠️ | — |
| IEC 61082-1 | Établissement des documents utilisés en électrotechnique | N | Dossiers de schémas et documentation technique | IEC 60617, NF EN IEC 81346 | schéma | IEC | ⚠️ | — |
| ISO 128 (série) | Dessins techniques : principes généraux de représentation | N | Plans et mises en plan CAO | ISO 129-1 | dessin | ISO | ⚠️ | — |
| ISO 129-1 | Dessins techniques : indication des cotes et des tolérances | N | Cotation des plans | ISO 128, ISO 2768 | dessin | ISO | ⚠️ | — |
| ISO 2768-1 / -2 | Tolérances générales (dimensionnelles et géométriques) | N | Plans sans tolérance indiquée sur chaque cote | ISO 1101 | dessin | ISO | ⚠️ | — |
| ISO 1101 | Spécification géométrique des produits (GPS) : tolérancement de forme, d'orientation, de position | N | Pièces mécaniques précises | ISO 2768 | dessin | ISO | ⚠️ | — |

## 3. Machines, automatisme et atmosphères explosives

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| Directive 2006/42/CE (« Machines ») | Exigences de sécurité des machines, marquage CE | R | Mise sur le marché des machines ; remplacement prévu par le règlement (UE) 2023/1230 : vérifier la date d'application | NF EN 60204-1, NF EN ISO 13849-1 | machine | EUR-Lex | ⚠️ | — |
| Règlement (UE) 2023/1230 | Nouveau règlement « Machines » | R | Machines mises sur le marché après sa date d'application | Directive 2006/42/CE | machine | EUR-Lex | ⚠️ | — |
| NF EN 60204-1 | Sécurité des machines : équipement électrique des machines | N | Armoires et câblage des machines | NF EN 61439, NF EN 60445 | machine, armoire | AFNOR | ⚠️ | — |
| NF EN ISO 13849-1 | Parties des systèmes de commande relatives à la sécurité (niveaux de performance PL) | N | Fonctions de sécurité (arrêt d'urgence, protecteurs) | Directive Machines | machine | AFNOR | ⚠️ | — |
| IEC 61131-3 | Automates programmables : langages de programmation | N | Programmation des automates | — | machine, supervision | IEC | ⚠️ | — |
| Spécification Modbus Application Protocol | Protocole Modbus (spécification de l'organisation Modbus) | D | Communication entre automates, régulateurs et supervision | — | supervision, réseau | modbus.org | ⚠️ | Registres Modbus |
| Directive 2014/34/UE (« ATEX produits ») | Appareils destinés à être utilisés en atmosphères explosibles | R | Mise sur le marché d'appareils pour zones ATEX | NF EN IEC 60079, directive 1999/92/CE | ATEX, froid, produit | EUR-Lex | ⚠️ | — |
| Directive 1999/92/CE (« ATEX utilisateurs ») | Protection des travailleurs exposés aux atmosphères explosives (zonage) | R | Sites présentant un risque d'atmosphère explosive | Directive 2014/34/UE | ATEX, industrie | EUR-Lex | ⚠️ | — |
| NF EN IEC 60079 (série) | Atmosphères explosives : matériels et installations électriques | N | Équipements en zone ATEX, dont certaines installations à fluides inflammables (R290, A3) | NF EN 378, directive 2014/34/UE | ATEX, froid | AFNOR | ⚠️ | — |

## 4. Électronique, produits connectés et mise sur le marché

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| Directive 2014/35/UE (« Basse tension ») | Sécurité du matériel électrique de 50 à 1000 V AC / 75 à 1500 V DC | R | Mise sur le marché de matériel électrique dans ces plages | NF EN IEC 62368-1 | produit | EUR-Lex | ⚠️ | — |
| Directive 2014/30/UE (« CEM ») | Compatibilité électromagnétique | R | Mise sur le marché d'appareils électriques et électroniques | NF EN 55032, IEC 61000-6 | produit, CEM, IoT | EUR-Lex | ⚠️ | — |
| Directive 2014/53/UE (« RED ») | Équipements radioélectriques (Wi-Fi, Bluetooth, LoRa…) | R | Tout produit intégrant un émetteur radio | Règlement (UE) 2024/2847 | IoT, produit | EUR-Lex | ⚠️ | — |
| Directive 2011/65/UE (« RoHS ») | Limitation des substances dangereuses dans les équipements électriques et électroniques | R | Mise sur le marché d'équipements électriques et électroniques | Directive 2012/19/UE | produit | EUR-Lex | ⚠️ | — |
| Directive 2012/19/UE (« DEEE ») | Déchets d'équipements électriques et électroniques : collecte, marquage | R | Producteurs et metteurs sur le marché | Directive RoHS | produit | EUR-Lex | ⚠️ | — |
| Règlement (UE) 2023/1542 | Batteries et déchets de batteries | R | Produits intégrant des batteries | Directive DEEE | produit, IoT | EUR-Lex | ⚠️ | — |
| Règlement (UE) 2024/2847 (« Cyber Resilience Act ») | Exigences de cybersécurité des produits comportant des éléments numériques | R | Produits connectés mis sur le marché (application progressive : vérifier les dates) | IEC 62443, directive RED | IoT, cybersécurité, produit | EUR-Lex | ⚠️ | — |
| IEC 62443 (série) | Cybersécurité des systèmes d'automatisation et de commande industriels | N | Supervision, automates, réseaux industriels | Règlement (UE) 2024/2847 | cybersécurité, supervision | IEC | ⚠️ | — |
| NF EN IEC 62368-1 | Sécurité des équipements audio/vidéo, de l'information et de la communication | N | Présomption de conformité à la directive Basse tension pour ces équipements | Directive 2014/35/UE | produit | AFNOR | ⚠️ | — |
| NF EN 55032 | CEM des équipements multimédias : émissions | N | Essais CEM des produits électroniques | IEC 61000-6, directive CEM | CEM, produit | AFNOR | ⚠️ | — |
| IEC 61000-6 (série) | CEM : normes génériques d'immunité et d'émission (résidentiel, industriel) | N | Produits sans norme CEM spécifique | Directive CEM | CEM, produit, industrie | IEC | ⚠️ | — |
| IPC-2221 | Conception des circuits imprimés : largeur de piste selon le courant, distances d'isolement | D | Conception de cartes électroniques | IPC-A-610 | PCB, IoT | IPC | ⚠️ | — |
| IPC-A-610 | Critères d'acceptabilité des assemblages électroniques | D | Contrôle qualité des cartes assemblées | IPC-2221 | PCB, qualité | IPC | ⚠️ | — |
| IEC 60062 | Codes de marquage des résistances et condensateurs (code couleur) | N | Identification des composants | IEC 60063 | PCB | IEC | ⚠️ | Code couleur des résistances |
| IEC 60063 | Séries de valeurs préférentielles (E6, E12, E24…) | N | Choix des valeurs normalisées | IEC 60062 | PCB | IEC | ⚠️ | Résistance pour LED, code couleur |

## 5. Froid, fluides frigorigènes et F-Gas

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| Règlement (UE) 2024/573 (« F-Gas ») | Gaz à effet de serre fluorés : contrôles d'étanchéité, restrictions, calendrier de réduction, PRG | R | Toute installation contenant des gaz fluorés | Code de l'environnement R543-75, NF EN 378 | froid, F-Gas | EUR-Lex | ⚠️ | Fluides frigorigènes, tonnage CO₂ |
| Règlement (UE) 2024/573, art. 5 et 6 | Contrôles d'étanchéité (5 / 50 / 500 t éq. CO₂ → 12 / 6 / 3 mois, doublés avec détection ; hermétiquement scellé < 10 t exempté) ; détection obligatoire ≥ 500 t, vérifiée tous les 12 mois | R | Équipements contenant des gaz fluorés | Art. 13, annexe IV | froid, F-Gas, chambre-froide | EUR-Lex | ✅ (seuils confirmés par Guillaume le 2026-10-23) | Chambre froide |
| Règlement (UE) 2024/573, art. 11 et annexe IV | Interdictions de mise sur le marché (R1 à R4 de docs/CHAMBRE_FROIDE_V2.md) | R | Équipements de réfrigération neufs | Art. 13 | froid, F-Gas, chambre-froide | EUR-Lex | ⚠️ (règles de l'admin, masquées tant que non vérifiées) | Chambre froide |
| Règlement (UE) 2024/573, art. 13 | Entretien : PRG ≥ 2 500 interdit depuis le 01/01/2025 ; PRG ≥ 750 en réfrigération fixe hors refroidisseurs à partir du 01/01/2032 (seuils de charge à relever) | R | Entretien et maintenance | Annexe IV | froid, F-Gas, chambre-froide | EUR-Lex | ⚠️ (règles S1 et S2 de l'admin, masquées tant que non vérifiées) | Chambre froide |
| NF EN 378-1, annexe C | Limites de charge des fluides inflammables (limite pratique, m1, m2, m3 selon la LFL) | N | Systèmes au R-290, A2L… | ISO 817 | froid, chambre-froide | AFNOR | ⚠️ (facteurs à vérifier selon l'édition) | Chambre froide |
| IEC 60335-2-89 (NF EN 60335-2-89) | Sécurité des appareils de réfrigération commerciale à groupe incorporé ou à distance : charge maximale des fluides inflammables par circuit (13 × LFL pour les A2 et A3, soit environ 0,49 kg de R-290) et conditions associées (au-delà de 150 g : surface minimale du local, circulation d'air, essais de fuite) | N | Norme produit des groupes autonomes (monoblocs) et vitrines ; présomption de conformité à la directive Basse tension | NF EN 378-1, ISO 817, directive 2014/35/UE | froid, chambre-froide, produit | IEC / AFNOR | ⚠️ (facteur 13 × LFL et conditions à vérifier sur le texte de la norme) | Chambre froide (groupe autonome hermétiquement scellé) |
| ASHRAE Handbook — Refrigeration, chap. « Refrigerated-facility loads » | Charges d'une chambre froide : infiltration par les portes (Gosney et Olama), protections, respiration des produits, personnes (270 − 6 T) | Guide | Bilans frigorifiques | ASHRAE Fundamentals (psychrométrie) | froid, chambre-froide | ASHRAE | ⚠️ | Chambre froide |
| Code de l'environnement, art. R543-75 et suivants | Manipulation des fluides frigorigènes en France : attestation de capacité, certificats d'aptitude, fiches d'intervention | R | Entreprises et opérateurs intervenant sur les circuits frigorifiques | Règlement (UE) 2024/573 | froid, F-Gas | Légifrance | ⚠️ | Fluides frigorigènes |
| NF EN 378 (parties 1 à 4) | Systèmes frigorifiques et pompes à chaleur : sécurité et environnement (charges admissibles, classes de fluides, local technique) | N | Conception, installation et maintenance des systèmes frigorifiques | ISO 817, NF EN 13313, NF EN 14276 | froid, chambre-froide | AFNOR | ⚠️ | Fluides frigorigènes, chambre froide |
| ISO 817 | Fluides frigorigènes : désignation et classification de sécurité (A1, A2L, A3…) | N | Identification des fluides et de leur danger | NF EN 378 | froid | ISO | ⚠️ | Fluides frigorigènes |
| NF EN 13313 | Systèmes frigorifiques et pompes à chaleur : compétence du personnel | N | Qualification des intervenants | NF EN 378 | froid | AFNOR | ⚠️ | — |
| NF EN 14276 (parties 1 et 2) | Équipements sous pression des systèmes frigorifiques : récipients et tuyauteries | N | Présomption de conformité à la DESP pour ces équipements | Directive 2014/68/UE | froid | AFNOR | ⚠️ | — |
| NF EN 13136 | Systèmes frigorifiques : dispositifs de limitation de pression (dimensionnement des soupapes) | N | Choix des soupapes de sécurité | NF EN 378, NF EN 14276 | froid | AFNOR | ⚠️ | — |
| NF EN 14511 (série) | Climatiseurs, refroidisseurs et pompes à chaleur : conditions d'essai, puissances, EER et COP | N | Comparaison des performances des machines | NF EN 14825 (SCOP, SEER) | froid, CVC | AFNOR | ⚠️ | COP / EER |
| Directive 2014/68/UE (« DESP ») | Équipements sous pression | R | Équipements dépassant les seuils de pression et de volume | NF EN 14276 | froid, industrie | EUR-Lex | ⚠️ | — |
| Règlement (UE) 2015/1095 | Écoconception des armoires frigorifiques professionnelles, cellules de refroidissement rapide, groupes de condensation, refroidisseurs industriels | R | Mise sur le marché de ces équipements | — | froid, chambre-froide | EUR-Lex | ⚠️ | — |

## 6. Chambres froides et hygiène alimentaire

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| Règlement (CE) 852/2004 | Hygiène des denrées alimentaires (chaîne du froid, HACCP) | R | Tout exploitant du secteur alimentaire | Arrêté du 21 décembre 2009 | alimentaire, chambre-froide | EUR-Lex | ⚠️ | Chambre froide |
| Arrêté du 21 décembre 2009 | Règles sanitaires applicables au commerce de détail, dont les températures de conservation des denrées | R | Commerces de détail et restauration | Règlement (CE) 852/2004 | alimentaire, chambre-froide | Légifrance | ⚠️ | Chambre froide |

## 7. Génie climatique, chauffage, ventilation et eau chaude sanitaire

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| NF EN 12828 | Systèmes de chauffage à eau dans les bâtiments : conception (dont expansion et sécurité) | N | Conception des installations de chauffage à eau | NF EN 12831-1 | CVC | AFNOR | ⚠️ | Vase d'expansion |
| NF EN 12831-1 | Méthode de calcul de la charge thermique de chauffage (déperditions) | N | Dimensionnement des émetteurs et générateurs | NF EN ISO 6946 | CVC | AFNOR | ⚠️ | Bilan thermique d'une paroi |
| NF EN ISO 6946 | Résistance et coefficient de transmission thermiques des parois | N | Calcul des coefficients U | NF EN 12831-1 | CVC | AFNOR | ⚠️ | Bilan thermique d'une paroi |
| NF EN 16798 (série) | Performance énergétique des bâtiments : ventilation, qualité de l'air intérieur | N | Conception de la ventilation des bâtiments tertiaires | — | CVC, tertiaire | AFNOR | ⚠️ | Puissance air, gaines |
| NF DTU des séries 60, 65 et 68 | Règles de l'art : plomberie, chauffage, ventilation | N | Marchés de travaux qui y font référence | — | CVC, habitat | AFNOR / CSTB | ⚠️ | — |
| RE2020 (décret et arrêtés) | Réglementation environnementale des bâtiments neufs | R | Bâtiments neufs selon leur usage et leur date de dépôt de permis | — | habitat, tertiaire, énergie | Légifrance | ⚠️ | — |
| Arrêté du 30 novembre 2005 | Installations d'eau chaude sanitaire : températures et prévention de la légionelle | R | Installations d'ECS des bâtiments concernés | Arrêté du 23 juin 1978 (à vérifier) | ECS, CVC | Légifrance | ⚠️ | Chauffe d'un volume d'eau |

## 8. Énergie et gestion technique des bâtiments (GTB)

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| Décret « BACS » (automatisation et contrôle des bâtiments tertiaires) | Obligation d'équiper certains bâtiments tertiaires d'un système d'automatisation et de contrôle | R | Bâtiments tertiaires au-delà d'un seuil de puissance des systèmes de chauffage ou de climatisation (seuils et dates à vérifier) | NF EN ISO 52120-1, dispositif Éco Énergie Tertiaire | GTB, supervision, tertiaire, énergie | Légifrance | ⚠️ | — |
| Dispositif Éco Énergie Tertiaire (« décret tertiaire ») | Réduction progressive des consommations d'énergie des bâtiments tertiaires | R | Bâtiments tertiaires au-delà d'un seuil de surface (à vérifier) | Décret BACS | énergie, tertiaire | Légifrance | ⚠️ | — |
| NF EN ISO 52120-1 (ex EN 15232) | Contribution de l'automatisation et de la GTB à la performance énergétique (classes A à D) | N | Évaluation et choix d'un système de GTB | Décret BACS | GTB, supervision | AFNOR | ⚠️ | — |
| NF EN ISO 50001 | Systèmes de management de l'énergie | N | Démarche volontaire ou exigée par certains dispositifs | — | énergie, qualité | AFNOR | ⚠️ | — |

## 9. Enceintes climatiques, essais d'environnement et pharmaceutique

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| FD X 15-140 | Caractérisation et vérification des enceintes climatiques et thermostatiques (fascicule de documentation AFNOR, non normatif) | D | Caractérisation des enceintes avant usage et périodiquement | IEC 60068-3-5 / -3-6, JCGM 100 | enceinte, métrologie | AFNOR | ⚠️ | Enceinte climatique, caractérisation d'enceinte |
| IEC 60068-3-5 / 60068-3-6 | Confirmation des performances des chambres d'essais en température / en température et humidité | N | Vérification des enceintes d'essais | FD X 15-140 | enceinte, essais | IEC | ⚠️ | Caractérisation d'enceinte |
| NF EN 60068-2 (série) | Essais d'environnement : froid (-2-1), chaleur sèche (-2-2), variation de température (-2-14), chaleur humide (-2-30, -2-78) | N | Qualification des produits aux conditions climatiques | IEC 60068-3-5 | essais, produit | AFNOR | ⚠️ | Chocs thermiques, enceinte climatique (profils) |
| ICH Q1A(R2) | Essais de stabilité des substances et produits pharmaceutiques (conditions de conservation) | D | Dossiers de stabilité des médicaments | ICH Q1B, Q1C, Q1E | pharma, enceinte | ich.org | ⚠️ | Enceinte climatique (ICH) |
| ICH Q1B | Essais de photostabilité | D | Exposition à la lumière des produits pharmaceutiques | ICH Q1A(R2) | pharma, éclairage | ich.org | ⚠️ | Vieillissement UV |
| ICH Q1C | Essais de stabilité des nouvelles formes pharmaceutiques | D | Nouvelles formes d'un produit déjà enregistré | ICH Q1A(R2) | pharma | ich.org | ⚠️ | — |
| ICH Q1E | Évaluation des données de stabilité | D | Analyse et extrapolation des résultats de stabilité | ICH Q1A(R2) | pharma | ich.org | ⚠️ | — |
| BPF / GMP, annexe 15 | Bonnes pratiques de fabrication : qualification et validation (QI, QO, QP) | R | Fabricants de médicaments (BPF rendues obligatoires par la réglementation pharmaceutique) | ICH Q1A(R2), 21 CFR Part 11 | pharma, enceinte | ANSM / Commission européenne | ⚠️ | — |
| 21 CFR Part 11 (FDA) | Enregistrements et signatures électroniques | R | Données soumises à la FDA (États-Unis) : enregistreurs, supervision | BPF annexe 11 | pharma, supervision | ecfr.gov | ⚠️ | — |

## 10. Mesure, instrumentation et métrologie

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| IEC 60751 | Sondes à résistance de platine industrielles (PT100, PT1000) : tables et classes de tolérance | N | Mesure de température par sonde platine | IEC 60584 | mesure | IEC | ⚠️ | Sondes PT100 / PT1000 |
| IEC 60584-1 / -3 | Thermocouples : tables de référence, tolérances, câbles d'extension | N | Mesure de température par thermocouple | IEC 60751 | mesure | IEC | ⚠️ | Thermocouples |
| NF EN 60381-1 | Signaux analogiques en courant continu pour les systèmes de commande (4-20 mA) | N | Boucles de courant en instrumentation | — | mesure, supervision | AFNOR | ⚠️ | Mise à l'échelle 4-20 mA |
| JCGM 100 (GUM) | Évaluation et expression des incertitudes de mesure | D | Calcul d'incertitude | JCGM 200 | métrologie | bipm.org | ⚠️ | Précision d'un instrument |
| JCGM 200 (VIM) | Vocabulaire international de métrologie | D | Définitions des termes de métrologie | JCGM 100 | métrologie | bipm.org | ⚠️ | — |
| NF EN ISO/IEC 17025 | Compétence des laboratoires d'étalonnage et d'essais | N | Laboratoires accrédités (COFRAC) | JCGM 100 | métrologie, qualité | AFNOR | ⚠️ | — |

## 11. Sécurité incendie

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| Arrêté du 25 juin 1980 (règlement de sécurité ERP) | Dispositions générales de sécurité contre l'incendie dans les établissements recevant du public | R | Tous les ERP, selon leur type et leur catégorie | NF EN 1838, NF C 71-800 | incendie, ERP | Légifrance | ⚠️ | — |

## 12. Éclairage et vieillissement des matériaux

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| NF EN 12464-1 | Éclairage des lieux de travail intérieurs : niveaux d'éclairement | N | Conception de l'éclairage des locaux de travail | Code du travail (éclairage, à vérifier) | éclairage, tertiaire | AFNOR | ⚠️ | Lux et lumens |
| ASTM G154 | Exposition des matériaux non métalliques aux lampes UV fluorescentes | N | Essais de vieillissement accéléré | NF EN ISO 4892 | essais, éclairage | ASTM | ⚠️ | Vieillissement UV |
| NF EN ISO 4892 (série) | Plastiques : méthodes d'exposition à des sources lumineuses de laboratoire | N | Essais de vieillissement accéléré | ASTM G154 | essais | AFNOR | ⚠️ | Vieillissement UV |
| ANSI E1.11 (USITT DMX512-A) | Protocole DMX512 pour le contrôle de l'éclairage scénique | N | Éclairage scénique et architectural | — | éclairage | ESTA | ⚠️ | Adresse DMX |

## 13. Réseaux et informatique

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| RFC 791 / RFC 4632 | Protocole IPv4 et adressage CIDR | D | Plans d'adressage réseau | — | réseau | rfc-editor.org | ⚠️ | Sous-réseaux IPv4 |
| ISO/IEC 11801 (série) | Câblage générique des locaux (cuivre, fibre) | N | Conception du câblage réseau | NF EN 50173 | réseau, tertiaire | ISO / IEC | ⚠️ | — |
| NF EN 50173 (série) | Systèmes de câblage générique (équivalent européen) | N | Conception du câblage réseau | ISO/IEC 11801 | réseau | AFNOR | ⚠️ | — |

## 14. Impression 3D

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| ISO/ASTM 52900 | Fabrication additive : principes généraux et terminologie | N | Vocabulaire commun de la fabrication additive | — | 3D | ISO | ⚠️ | — |

## 15. Terrariophilie et détention d'animaux

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| Arrêté du 8 octobre 2018 | Règles générales de détention d'animaux d'espèces non domestiques (déclaration, autorisation selon les espèces et les effectifs) | R | Détention d'animaux d'espèces non domestiques en France | Code de l'environnement L413-2, règlement (CE) 338/97 | terrarium | Légifrance | ⚠️ | — |
| Code de l'environnement, art. L413-2 et suivants | Certificat de capacité et autorisation d'ouverture d'établissement | R | Selon les espèces et les effectifs détenus | Arrêté du 8 octobre 2018 | terrarium | Légifrance | ⚠️ | — |
| Règlement (CE) n° 338/97 | Application de la CITES dans l'Union européenne (commerce des espèces protégées, annexes A à D) | R | Achat, vente et détention d'espèces inscrites aux annexes | Arrêté du 8 octobre 2018 | terrarium | EUR-Lex | ⚠️ | — |
| Règlement (UE) 1143/2014 | Espèces exotiques envahissantes : liste des espèces interdites de détention, d'élevage et de vente | R | Espèces inscrites sur la liste de l'Union | Règlement (CE) 338/97 | terrarium | EUR-Lex | ⚠️ | — |
| NF EN 60335-2-… (selon l'appareil) | Sécurité des appareils électrodomestiques (chauffages, lampes, brumisateurs) | N | Choix et fabrication des équipements de terrarium | Directive 2014/35/UE | terrarium, produit | AFNOR | ⚠️ | Chauffage terrarium |

## 16. Qualité et management

| Référence | Objet (résumé) | Type | Application / obligatoire si | Textes liés | Mots-clés | Source | Statut | Outils |
|---|---|---|---|---|---|---|---|---|
| NF EN ISO 9001 | Systèmes de management de la qualité : exigences | N | Démarche volontaire, souvent exigée par les clients | NF EN ISO 14001 | qualité | AFNOR | ⚠️ | — |
| NF EN ISO 14001 | Systèmes de management environnemental | N | Démarche volontaire | NF EN ISO 9001 | qualité, énergie | AFNOR | ⚠️ | — |

---

## Exemples de recherche par projet

| Projet | Mots-clés à filtrer | Textes principaux |
|---|---|---|
| Armoire électrique de machine | `armoire`, `machine`, `schéma` | NF EN 60204-1, NF EN 61439, NF EN 60445, NF EN IEC 81346, directive Machines |
| Chambre froide commerciale | `froid`, `F-Gas`, `chambre-froide`, `alimentaire` | Règlement F-Gas, NF EN 378, IEC 60335-2-89, R543-75, règlement (CE) 852/2004, arrêté du 21/12/2009 |
| Enceinte de stabilité pharmaceutique | `enceinte`, `pharma`, `métrologie` | ICH Q1A(R2), FD X 15-140, BPF annexe 15, 21 CFR Part 11, IEC 60751 |
| Objet connecté (ESP32, LoRa) | `IoT`, `produit`, `CEM`, `cybersécurité` | Directives RED, CEM, RoHS, Cyber Resilience Act, IPC-2221 |
| Supervision d'un bâtiment tertiaire | `GTB`, `supervision`, `énergie` | Décret BACS, NF EN ISO 52120-1, Modbus, IEC 62443 |
| Installation électrique d'un logement | `habitat` | NF C 15-100, UTE C 15-105, NF C 14-100, NF EN 60898-1 |

## À ajouter (idées en vrac)

- 

## Procédure de vérification d'une ligne

1. Chercher la référence sur la source officielle (voir « Où vérifier »).
2. Noter la **version en vigueur** (année, amendements) et, si besoin, le texte qui la remplace.
3. Vérifier la colonne « Application / obligatoire si » : seuils, dates, champ d'application.
4. Relire le résumé : il doit rester court et écrit avec mes mots.
5. Passer le statut à « ✅ en vigueur (vérifiée le JJ/MM/AAAA) » et renseigner le lien exact.
6. Re-vérifier au moins une fois par an les textes cités par les outils de la calculatrice.
