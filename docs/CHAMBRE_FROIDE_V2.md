# Chambre froide v2 (lot 5e) — règles, valeurs et cas de contrôle

Document de travail du lot 5e. Statuts : **à valider** (proposition), **validé** (date), **écarté**.
Implémentation : `assets/calculators/chambre_froide.js` (bilan), `assets/calculators/fgas.js` (fluide et
règlement), données de l'admin (fluides, règles F-Gas, panneaux des marques). Contre-calcul indépendant :
`scripts/calculator/cold_room_hand_check.py` (section 4).

---

## 1. Règles F-Gas proposées (règlement (UE) 2024/573)

Sources consultées le 2026-10-23 : synthèses secondaires (refrigerants.net, egolog.ro, projetcelsius.com).
Le texte officiel d'EUR-Lex n'a pas pu être lu automatiquement : **numéros d'articles et de points de
l'annexe IV à confirmer sur le texte consolidé** avant tout affichage. Les lignes marquées « (517/2014) »
sont reprises de l'ancien règlement et à confirmer dans l'annexe IV du nouveau.

### 1.1 Interdictions de mise sur le marché (annexe IV) — équipements de réfrigération

| # | Équipement (cas de la calculatrice) | Fluides interdits | Depuis | Exception | Référence | Statut |
|---|---|---|---|---|---|---|
| R1 | Équipement de réfrigération fixe, tous types | PRG ≥ 2 500 | 1er janv. 2020 | Applications refroidissant en dessous de −50 °C | annexe IV, point à confirmer (517/2014) | à valider |
| R2 | Centrale multipostes (« multipack ») à usage commercial, puissance nominale ≥ 40 kW | gaz fluorés PRG ≥ 150 | 1er janv. 2022 | Circuit primaire d'une cascade : PRG < 1 500 admis | annexe IV, point à confirmer (517/2014) | à valider |
| R3 | Réfrigération autonome (groupe autonome, monobloc, « self-contained »), sauf refroidisseurs de liquide | gaz fluorés PRG ≥ 150 | 1er janv. 2025 | Sauf si nécessaire pour les exigences de sécurité du site | annexe IV, point à confirmer | à valider |
| R4 | Autre réfrigération fixe (split, groupe de condensation à distance…), sauf refroidisseurs et catégories déjà citées | gaz fluorés PRG ≥ 150 | 1er janv. 2030 | Sauf si nécessaire pour les exigences de sécurité du site | annexe IV, point à confirmer | à valider |

**Décision du 2026-10-23** : R1 à R4 sont vérifiées par Guillaume sur le texte officiel. Elles sont
enregistrées comme données de l'admin (Calculatrice › Règles F-Gas : article, point de l'annexe, PRG,
date, source), chargées depuis `config/calculator/fgas_rules.json` avec `verified = false`, et **ne sont
pas transmises au site** tant que la case « Vérifiée sur le texte officiel » n'est pas cochée.

Affichage proposé dans l'outil : fluide grisé, avec « Interdit pour [équipement] depuis le [date] —
règlement (UE) 2024/573, annexe IV, point [n] ». Pour R3 et R4, mention de l'exception de sécurité.
La liste des fluides de la calculatrice ne propose de toute façon aucun fluide de PRG ≥ 2 500.

### 1.2 Entretien et maintenance (article 13, numéro à confirmer)

| # | Règle | Depuis | Statut |
|---|---|---|---|
| S1 | Entretien des équipements de réfrigération avec des gaz fluorés de PRG ≥ 2 500 interdit (sauf gaz régénérés ou recyclés jusqu'en 2030) | 1er janv. 2025 | à valider |
| S2 | Entretien de la réfrigération fixe (hors refroidisseurs) avec des gaz fluorés vierges de PRG ≥ 750 restreint (exceptions de l'article 13) | 1er janv. 2032 | à valider |
| S3 | Seuils de charge associés (en t éq. CO₂) | — | **inconnus, à relever dans le texte** |

**Décision du 2026-10-23** : S1 (PRG ≥ 2 500 depuis le 01/01/2025) et S2 (PRG ≥ 750 en réfrigération
fixe hors refroidisseurs à partir du 01/01/2032) confirmées ; seuils de charge (S3) vérifiés par
Guillaume. S1 et S2 sont des règles de l'admin de type « entretien » (charge minimale vide en
attendant), masquées comme R1 à R4 tant qu'elles ne sont pas vérifiées.

### 1.3 Contrôles d'étanchéité et détection des fuites (articles 4 et 5, numéros à confirmer)

| # | Charge (t éq. CO₂) | Contrôle d'étanchéité | Avec système de détection | Statut |
|---|---|---|---|---|
| L1 | < 5 | non obligatoire (sauf cas particuliers) | — | à valider |
| L2 | ≥ 5 et < 50 | tous les 12 mois | tous les 24 mois | à valider |
| L3 | ≥ 50 et < 500 | tous les 6 mois | tous les 12 mois | à valider |
| L4 | ≥ 500 | tous les 3 mois | tous les 6 mois | à valider |
| L5 | Système de détection des fuites obligatoire | équipements fixes ≥ 500 t éq. CO₂ | — | à valider |

**Validé le 2026-10-23** (texte officiel) : contrôles d'étanchéité (article 5) 5 / 50 / 500 t éq. CO₂ →
tous les 12 / 6 / 3 mois, intervalles doublés avec un système de détection ; exemption des équipements
hermétiquement scellés de moins de 10 t éq. CO₂ ; détection obligatoire à partir de 500 t (article 6),
vérifiée tous les 12 mois. Ces seuils sont affichés (`fgas.js`, `LEAK_CHECKS`). L'exemption ne s'applique
que si la case « Hermétiquement scellé (étiqueté par le fabricant) » est cochée : cochée par défaut pour
un groupe autonome, modifiable (décision du 2026-10-23).

---

## 2. Valeurs par défaut (toutes modifiables en mode pro, toutes indicatives)

Le reste de ce tableau a été validé le 2026-10-23 ; les valeurs marquées « fixé » ont été fixées par
Guillaume ce jour-là.

### 2.1 Enveloppe

| Valeur | Retenu | Statut |
|---|---|---|
| Résistances superficielles (Rsi + Rse) | 0,17 m²·K/W ; U = 1 / (0,17 + e/λ) | validé |
| λ PIR / PUR / XPS / EPS / laine de roche | 0,022 / 0,023 / 0,034 / 0,036 / 0,040 W/(m·K) | validé |
| Panneaux des marques | saisis dans l'admin (U ou λ, épaisseur, fiche et date) ; aucun codé en dur | validé |
| Parois / plafond (mode simple) | 25 / 32 °C ; repères pro : chauffé 20, non chauffé 25, extérieur 32 °C | validé |
| Sol : terre-plein / local / vide sanitaire | 12 / 20 / 10 °C ; sol non isolé U = 1,5 | validé |
| Câble antigel sous dalle | 15 W/m², fraction réintroduite **0,1**, taux de fonctionnement **30 %** | fixé |

### 2.2 Portes et infiltrations (ASHRAE, Gosney et Olama)

| Valeur | Retenu | Statut |
|---|---|---|
| Formule | q = 0,221 × A × Δh × ρ_int × (1 − ρ_ext/ρ_int)^0,5 × (g × H)^0,5 × F_m ; charge = q × D_t × D_f × (1 − E) | validé |
| Givre | part latente = débit d'air sec × Δw ; en négatif, + 334 kJ/kg de solidification | validé (méthode) |
| D_f | 0,8 | validé |
| Humidité extérieure / intérieure | 50 % / 85 % (sur la glace en négatif) | validé |
| Efficacité : aucune / lanières / rideau d'air / sas | 0 / **0,7** (note « 0,85 à 0,95 si neuf et bien entretenu ») / 0,65 / 0,90 | fixé (lanières) |
| Cordon chauffant | 40 W/m en négatif (0 en positif), fraction 0,7 ; périmètre + montant central si 2 vantaux | validé |
| U porte isotherme / vitrage double / triple | 0,55 / 2,8 / 1,6 W/(m²·K) ; cadre 1,2 W/(m²·K) sur 6 cm (ancien site) | validé |
| Vitrage chauffé | puissance saisie, fraction 0,7 | validé |

### 2.3 Équipements et occupants

| Valeur | Retenu | Statut |
|---|---|---|
| Ventilateurs | **2,5 W/m³** en mode simple ; en pro, puissance de l'évaporateur saisie ou ratio W/kW frigorifique | fixé |
| Éclairage | 8 W/m², 12 h/j (simple) ; puissance saisie en pro | validé |
| Personnes | 270 − 6 × T (W) | validé |
| Câble d'évacuation | 40 W/m, fraction **0,1**, sous tension pendant le dégivrage et l'égouttage | fixé |
| Soupape chauffée | 20 W, fraction 1 | validé |
| Étagères | champ supprimé | validé |

### 2.4 Produits

| Valeur | Retenu | Statut |
|---|---|---|
| Chaleur latente | teneur en eau × 334 kJ/kg (C12) | validé |
| Respiration | W/t saisis par produit ; profils « légumes » et « fleurs » : moyennes indicatives interpolées (voir 5.4) | à valider |
| Emballages : carton / bois (palettes) / plastique | cp 1,4 / **1,7** / 1,9 kJ/(kg·K) | fixé (bois) |

### 2.5 Dégivrage, fonctionnement, fluide

| Valeur | Retenu | Statut |
|---|---|---|
| Fraction réintroduite : électrique / gaz chauds / naturel | **0,5 / 0,3 / 0** ; mode simple : électrique en négatif, **naturel (à l'arrêt du froid) en positif** | fixé |
| Temps de fonctionnement suggéré | 16 h/j (positif), 18 h/j (négatif), modifiable | validé |
| Marge | **10 %**, une seule valeur pour les deux modes | fixé |
| COP | 0,45 × T_év / (T_cond − T_év), T_év = consigne − 8 K, T_cond = air au condenseur + 10 K, air au condenseur **35 °C** par défaut ; aucune correction par fluide | validé |
| Fluide par défaut | **R-290** pour tous les types d'installation (case « Fluide par défaut » de l'admin) ; R-600a proposé pour les petits équipements autonomes | fixé |
| Charge de fluide | autonome 0,3 kg/kW ; split 0,6 kg/kW + 0,05 kg/m ; centrale 2 kg/kW | validé (estimation) |
| Charge des groupes autonomes R-290 | **0,15 kg/kW** (masse volumique liquide plus faible que celle des HFC), à vérifier avec les fiches constructeurs ; modifiable en mode pro (« Charge de fluide », kg/kW) | fixé (2026-10-23) |
| Efficacité des protections de porte | saisie en **%** (0 à 100) : lanières 70 %, rideau d'air 65 %, sas 90 % | fixé (2026-10-23) |
| Type d'installation par défaut | **groupe autonome** hermétiquement scellé quand le fluide est le R-290 ; split pour les autres fluides ; le type choisi à la main est conservé | fixé (2026-10-23) |
| Limite de charge d'un groupe autonome hermétiquement scellé (A2, A3) | norme produit **IEC 60335-2-89** : 13 × LFL par circuit (0,49 kg de R-290), conditions affichées (au-delà de 150 g : surface minimale du local, circulation d'air, essais de fuite), à vérifier sur le texte de la norme ; split, centrale et A2L : contrôle NF EN 378-1 (20 % de la LFL × volume) | fixé (2026-10-23) |

---

## 3. Aperçu du cas par défaut recalculé (mode simple : 4 × 3 × 2,5 m, −18 °C, 500 kg/j)

Panneaux PIR 120 mm (U = 0,178), porte sans protection, split au R-290, 18 h de marche, marge 10 %,
air au condenseur 35 °C.

| Poste | v1 | v2 | Détail |
|---|---|---|---|
| Parois, plafond et sol | 0,40 kW | **0,44** | U 0,178 par λ (catalogue v1 : 0,16) ; sol à 12 °C (v1 : 15 °C) |
| Portes (transmission) | 0,06 | 0,06 | inchangé |
| Cordons chauffants | — | **0,16** | 40 W/m × 5,8 m × 0,7 |
| Infiltrations | 0,001 | **0,22** | q porte ouverte 60,3 kW × D_t 0,0043 × 0,8 ; dont latent 0,074, givre 0,010 |
| Câble antigel | — | 0,005 | 15 W/m² × 12 m² × 30 % × 0,1 |
| Dégivrage | 0,06 | **0,047** | 1,67 kW × 4 × 20 min × 0,5, lissé sur 24 h |
| Évacuation des condensats | — | 0,001 | 40 W/m × 2 m × 8,3 % × 0,1 |
| Soupape chauffée | — | 0,020 | |
| Éclairage | 0,05 | 0,05 | inchangé |
| Ventilateurs | — (équipements 0,02) | **0,075** | 2,5 W/m³ × 30 m³ |
| Produits (déjà congelés à −15 °C) | 0,07 | 0,07 | inchangé (C12) |
| **Charge totale** | **0,66** | **1,14 kW** | |
| **Puissance frigorifique à installer** | **1,01** (marge 15 %) | **1,67 kW** | × 24/18 × 1,10 |
| Besoin journalier | — | 26,6 kWh/j | givre 2,5 kg/j |
| COP / compresseur | 2,1 / 0,48 kW | 1,57 / 1,07 kW | T_év −26 °C, T_cond 45 °C |
| Consommation électrique | — | 29,7 kWh/j | compresseur 17,0 + résistances, éclairage, ventilateurs 12,7 |
| Fluide R-290 | — | 1,5 kg, 0,005 t éq. CO₂ | **avertissement** : charge au-delà de la limite du local (20 % × 0,038 × 30 m³ = 0,23 kg) |
| Fluide R-290, groupe autonome scellé (défaut depuis le 2026-10-23) | — | 0,25 kg (0,15 kg/kW) | aucun avertissement : sous la limite par circuit IEC 60335-2-89 (13 × 0,038 = 0,49 kg) |

---

## 4. Trois cas de contrôle calculés à la main

Les valeurs ci-dessous viennent de `scripts/calculator/cold_room_hand_check.py`, où chaque formule est
ressaisie depuis ce document, indépendamment du code JavaScript. Ses résultats sont les cas de référence
`assets/calculators/golden/chambre_froide_v2.json` (`--write`), rejoués poste par poste par
`chambre_froide.test.js` ; la CI relance le script (`--check`). Air au condenseur 35 °C, marge 10 %.

### 4.1 Cas 1 — chambre positive de légumes, avec respiration

5 × 4 × 3 m, +4 °C, 90 % ; PUR 100 mm (U = 1 / (0,17 + 0,1/0,023) = 0,2213) ; parois et plafond à
25 °C, sol non isolé sur terre-plein (12 °C, U = 1,5) ; 1 porte battante 1,2 × 2,1 m avec lanières
(E = 0,7), 30 ouvertures de 20 s, local 25 °C 50 % ; éclairage 200 W × 10 h ; 1 personne × 2 h ;
ventilateurs 120 W ; légumes 1 000 kg/j entrant à 15 °C (cp 4,0, 8 h), stock 4 t, respiration 32 W/t
(interpolée entre 20 W/t à 0 °C et 35 W/t à 5 °C) ; palettes 80 kg/j (cp 1,7) ; dégivrage par l'air ;
16 h de marche ; groupe autonome R-290.

| Poste | Calcul | W | kWh/j |
|---|---|---|---|
| Parois, plafond, sol | 0,2213 × 54 × 21 + 0,2213 × 20 × 21 + 1,5 × 20 × 8 | 583,97 | 14,02 |
| Portes | (0,55 × 2,52 + 1,2 × 6,6 × 0,06) × 21 | 39,09 | 0,94 |
| Infiltrations | 29,81 kW × (30 × 20 / 86 400) × 0,8 × 0,3 | 49,68 | 1,19 |
| Éclairage | 200 × 10 / 24 | 83,33 | 2,00 |
| Personnes | (270 − 24) × 2 / 24 | 20,50 | 0,49 |
| Ventilateurs | 120 | 120,00 | 2,88 |
| Respiration | 32 × 4 | 128,00 | 3,07 |
| Produits | 1 000 × 4,0 × 11 / (8 × 3 600) kJ/s | 1 527,78 | 12,22 |
| Emballages | 80 × 1,7 × 11 / (8 × 3 600) | 51,94 | 0,42 |
| **Total** | | **2 604,29** | **37,23** |

Puissance à installer : 2,604 × 24/16 × 1,1 = **4,297 kW** ; COP 0,45 × 269,15 / 49 = 2,472.

### 4.2 Cas 2 — chambre négative, câble sous dalle et 2 portes

6 × 5 × 3 m, −20 °C, 85 % (glace) ; PIR 150 mm (U = 0,1431) ; parois 20 °C, plafond 25 °C, sol isolé
sur terre-plein 12 °C avec câble antigel (15 W/m², 30 %, 0,1) ; porte 1 battante 1 × 2 m, lanières
(0,7), 20 × 30 s, local 20 °C 60 % ; porte 2 coulissante 2 vantaux 1,8 × 2,2 m (U 0,5), rideau d'air
(0,65), 10 × 60 s, quai 15 °C 70 % ; cordons 40 W/m ; éclairage 240 W × 8 h ; 2 personnes × 2 h ;
ventilateurs 40 W par kW frigorifique ; câble d'évacuation 3 m, soupape 20 W ; 1 000 kg/j de produits
déjà congelés à −15 °C (cp 1,9, 12 h) ; dégivrage électrique 3 kW, 4 × 25 min, 0,5 ; split R-455A,
15 m de liaisons.

| Poste | Calcul | W | kWh/j |
|---|---|---|---|
| Parois, plafond, sol | 0,1431 × (66 × 40 + 30 × 45 + 30 × 32) | 708,34 | 17,00 |
| Portes | 2 portes, U × S + cadre, × ΔT | 150,74 | 3,62 |
| Cordons | 40 × 6 × 0,7 + 40 × (8 + 2,2) × 0,7 | 453,60 | 10,89 |
| Infiltrations | porte 1 : 104,74 ; porte 2 : 206,91 | 311,66 | 7,48 |
| Câble antigel | 15 × 30 × 30 % × 0,1 | 13,50 | 0,32 |
| Évacuation | 40 × 3 × (4 × 35 / 1 440) × 0,1 | 1,17 | 0,03 |
| Soupape | 20 | 20,00 | 0,48 |
| Éclairage | 240 × 8 / 24 | 80,00 | 1,92 |
| Personnes | 2 × (270 + 120) × 2 / 24 | 65,00 | 1,56 |
| Ventilateurs | 40 × 3,316 kW (point fixe) | 132,63 | 3,18 |
| Dégivrage | 3 × (100/60) × 0,5 = 2,5 kWh/j | 104,17 | 2,50 |
| Produits | 1 000 × 1,9 × 5 / (12 × 3 600) | 219,91 | 2,64 |
| **Total** | | **2 260,70** | **51,62** |

Puissance à installer : **3,316 kW** (2,261 × 24/18 × 1,1) ; charge estimée 0,6 × 3,316 + 0,05 × 15 =
2,74 kg de R-455A (A2L, PRG 148), 0,41 t éq. CO₂.

### 4.3 Cas 3 — chambre négative, congélation sur place et vitrage chauffé

4 × 4 × 2,8 m, −25 °C ; panneau personnalisé λ 0,022, 180 mm (U = 0,1197) ; parois et plafond à 25 °C,
sol isolé 12 °C avec câble antigel ; porte battante 0,9 × 2 m sans protection, 12 × 20 s, local 25 °C
50 %, cordon 40 W/m ; vitrage triple 0,5 m² (U 1,6) chauffé 60 W ; éclairage 100 W × 6 h ;
ventilateurs 150 W ; évacuation 2 m, soupape 20 W ; viande 300 kg/j entrant à 8 °C, congelée sur
place (3,2 × 9,7 + 0,70 × 334 + 1,8 × 23,3 = 306,78 kJ/kg, 14 h), carton 15 kg/j ; dégivrage
électrique 2 kW, 4 × 20 min ; centrale R-744.

| Poste | Calcul | W | kWh/j |
|---|---|---|---|
| Parois, plafond, sol | 0,1197 × (44,8 × 50 + 16 × 50 + 16 × 37) | 434,88 | 10,44 |
| Vitrage | 1,6 × 0,5 × 50 | 40,00 | 0,96 |
| Vitrage chauffé | 60 × 0,7 | 42,00 | 1,01 |
| Porte | (0,55 × 1,8 + 1,2 × 5,8 × 0,06) × 50 | 70,38 | 1,69 |
| Cordon | 40 × 5,8 × 0,7 | 162,40 | 3,90 |
| Infiltrations | 74,04 kW × (12 × 20 / 86 400) × 0,8 | 171,55 | 4,12 |
| Câble antigel | 15 × 16 × 30 % × 0,1 | 7,20 | 0,17 |
| Évacuation, soupape | 0,67 + 20 | 20,67 | 0,50 |
| Éclairage | 100 × 6 / 24 | 25,00 | 0,60 |
| Ventilateurs | 150 | 150,00 | 3,60 |
| Dégivrage | 2 × (80/60) × 0,5 = 1,33 kWh/j | 55,56 | 1,33 |
| Produits | 300 × 306,78 / (14 × 3 600) | 1 826,07 | 25,56 |
| Emballages | 15 × 1,4 × 33 / (14 × 3 600) | 13,75 | 0,19 |
| **Total** | | **3 019,45** | **54,07** |

Puissance à installer : **4,429 kW** ; COP 0,45 × 240,15 / 78 = 1,386.

---

## 5. Hypothèses ajoutées pendant l'implémentation — validées le 2026-10-23

Toutes validées, avec ces ajustements : case « hermétiquement scellé » (point 8), air au condenseur
35 °C (point 3), « limite non calculable : LFL non renseignée » et avertissement si la charge dépasse la
limite du local (point 7), fluide par défaut R-290 (point 9), dégivrage naturel en positif (point 1).

1. **Mode simple, dégivrage électrique en négatif** : résistances de 1 kW par kW frigorifique, 4 dégivrages
   de 20 min par jour ; dégivrage par l'air en positif.
2. **Câble d'évacuation** : 2 m en mode simple ; sous tension pendant le dégivrage et 10 min d'égouttage.
3. **Mode simple** : liaisons de 10 m (split), air au condenseur **35 °C**, atelier 1 personne × 4 h.
4. **Respiration** (W/t, interpolée) : légumes 20 / 35 / 60 / 100 et fleurs 40 / 70 / 120 / 180 à
   0 / 5 / 10 / 15 °C — moyennes indicatives à remplacer par la valeur du produit (tables ASHRAE) ;
   respiration non comptée en mode simple.
5. **Charge des produits** : comptée en puissance sur la durée de refroidissement (méthode de l'ancien
   site) ; le besoin en kWh/j compte l'énergie réelle ; la part de chaque poste est rapportée au besoin.
6. **COP** : aucune correction par fluide n'est appliquée (correction « à valider » dans la liste).
7. **NF EN 378-1** (fluides A2L, A2, A3, B2L) : limite pratique = 20 % de la LFL × volume de la chambre ;
   seuils m1, m2, m3 = 4, 26, 130 m³ × LFL (facteurs des A2L et catégorie d'accès à vérifier selon
   l'édition en vigueur). LFL ajoutées dans l'admin (R-454A et R-444A non renseignées).
8. **Hermétiquement scellé** : case cochée par défaut pour un groupe autonome, modifiable ; l'exemption
   (< 10 t éq. CO₂) ne s'applique que si elle est cochée.
9. **Nouveaux fluides** : R-513A (631), R-449A (1 397), R-448A (1 387), R-452A (2 140), PRG AR4 ; glissements
   indicatifs ; tout est marqué « à vérifier » dans l'admin.
