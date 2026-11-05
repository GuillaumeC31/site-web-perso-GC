# Enceinte climatique v2 — valeurs par défaut, profils et cas de contrôle

Document de référence de l'enceinte climatique v2. Valeurs et 3 cas **validés le 2026-10-23**, puis
implémentés (`assets/calculators/enceinte_climatique.js`) ; hypothèses de la section 6 validées le
2026-10-23 avec deux corrections de calcul ; types d'enceinte (section 7) et régulation PID (section 8)
validés le 2026-10-23, la régulation implémentée (section 9 de la page). Toutes les valeurs sont indicatives et
marquées « à vérifier » sur le site.

Principe (cahier 5 et compléments A à K) : réutiliser le moteur de la chambre froide v2 (panneaux par λ ou
modèles de l'admin, vitrages, portes et infiltrations ASHRAE, cordons, renouvellement d'air, fluides et
contrôles F-Gas / NF EN 378), et ajouter les postes propres aux enceintes. Contre-calcul indépendant des
3 cas : `scripts/calculator/climatic_chamber_hand_check.py` (`--write` écrit les cas de référence
`assets/calculators/golden/enceinte_climatique_v2.json`, rejoués par Vitest ; `--check` en CI).

L'outil **dimensionne les puissances**. La stabilité et l'homogénéité réelles se vérifient par
caractérisation (FD X 15-140, IEC 60068-3-5 et 3-6) : l'outil ne calcule pas l'homogénéité et l'indique.

Révision du 2026-10-23 (2e version) : condensation sur la batterie froide, dimensionnement séparé de la
consommation, modulation par défaut à ± 0,5 K, ventilateurs selon le type d'enceinte.

---

## 1. Profils de consigne (gérés dans l'admin, avec leur source)

Tous **à vérifier** dans l'admin par Guillaume (décision du 2026-10-23).

| Secteur | Profil | Consigne | Tolérances | Source |
|---|---|---|---|---|
| Pharmaceutique | Long terme | 25 °C / 60 % HR | ± 2 °C / ± 5 % HR | ICH Q1A(R2) |
| Pharmaceutique | Long terme (zones chaudes) | 30 °C / 65 % HR | ± 2 °C / ± 5 % HR | ICH Q1A(R2) |
| Pharmaceutique | Long terme (zones chaudes et humides) | 30 °C / 75 % HR | ± 2 °C / ± 5 % HR | ICH Q1A(R2) |
| Pharmaceutique | Intermédiaire | 30 °C / 65 % HR | ± 2 °C / ± 5 % HR | ICH Q1A(R2) |
| Pharmaceutique | Accéléré | 40 °C / 75 % HR | ± 2 °C / ± 5 % HR | ICH Q1A(R2) |
| Pharmaceutique | Réfrigéré | 5 °C (humidité non régulée) | ± 3 °C | ICH Q1A(R2) |
| Pharmaceutique | Congelé | −20 °C (humidité non régulée) | ± 5 °C | ICH Q1A(R2) |
| Électronique | Chaleur humide continue | 40 °C / 93 % HR | ± 2 °C / ± 3 % HR | IEC 60068-2-78 |
| Électronique | Chaleur sèche | 55 °C ou 70 °C (humidité non régulée) | ± 2 °C | IEC 60068-2-2 |
| Électronique | Froid | −25 °C ou −40 °C | ± 3 °C | IEC 60068-2-1 |
| Œuvres d'art, archives | Conservation | 20 °C / 50 % HR | ± 2 °C / ± 5 % HR | ASHRAE Handbook Applications, « Museums, galleries, archives and libraries » |
| Cosmétique | Stabilité accélérée | 40 °C / 75 % HR | ± 2 °C / ± 5 % HR | pratique du secteur (lignes directrices Cosmetics Europe) |
| Industriel | Personnalisé | saisi | saisies | — |

Aucun profil alimentaire. Le profil remplit les consignes, qui restent modifiables.

---

## 2. Valeurs par défaut, section par section

### 2.1 Enceinte (section 1)

| Valeur | Proposition | Statut |
|---|---|---|
| Type d'enceinte | « chambre de stabilité accessible » ou « enceinte de laboratoire » (règle les ventilateurs, 2.3) ; accessible par défaut (dimensions par défaut d'une chambre où l'on entre) | validé (2026-10-23) |
| Dimensions intérieures | 2 × 1,5 × 2 m (6 m³, comme l'ancien site) | validé (2026-10-23) |
| Panneaux | PUR 80 mm générique, U = 1 / (0,17 + 0,08/0,023) = 0,274 W/(m²·K) (ancien site : U = 0,35) | validé (2026-10-23) |
| Surface d'échange | les 6 faces (enceinte posée dans un local) | validé (2026-10-23) |
| Fuites | 0,5 % du volume par heure (ancien site), sensible et latent | validé (2026-10-23) |
| Local, cas été / hiver | 30 °C / 60 % HR ; 18 °C / 30 % HR | validé (2026-10-23) |
| Peau intérieure | inox 1 mm : 7,9 kg/m² × surface, cp 0,50 kJ/(kg·K) | validé (2026-10-23) |
| Étagères | inox, 30 kg, cp 0,50 | validé (2026-10-23) |
| Structure (batteries, ventilation) | 40 kg, cp 0,60 | validé (2026-10-23) |

### 2.2 Consignes (section 2)

| Valeur | Proposition | Statut |
|---|---|---|
| Profil | ICH long terme, 25 °C / 60 % HR | validé (2026-10-23) |
| Classe de stabilité en température | ± 0,5 / ± 1 / ± 2 / ± 3 K ; ± 2 K par défaut | validé (2026-10-23) |
| Classe de stabilité en humidité | ± 1 / ± 2 / ± 3 / ± 5 / ± 10 % HR ; ± 5 % par défaut | validé (2026-10-23) |
| Rôle de la classe | conseiller la régulation et l'afficher dans les hypothèses ; aucun supplément sur la transmission | **validé (2026-10-23)** |
| Régulation conseillée (température) | ± 0,5 K et ± 1 K : **modulation** (variateur, by-pass gaz chaud), avec la comparaison « froid permanent + réchauffage » affichée ; ± 2 et ± 3 K : tout ou rien acceptable | **validé (2026-10-23)** |
| Régulation conseillée (humidité) | ± 1 et ± 2 % : humidificateur modulant et séchage permanent ; ± 3 et ± 5 % : modulation ; ± 10 % : tout ou rien | validé (2026-10-23) |
| Conditions de départ | celles du local (cas été) | validé (2026-10-23) |
| Contrainte de temps | aucune (facultative) | — |

### 2.3 Portes et apports internes (section 3)

| Valeur | Proposition | Statut |
|---|---|---|
| Porte | 1 porte battante 0,8 × 2 m (U 0,55, cadre comme la chambre froide), 5 ouvertures de 20 s par jour | validé (2026-10-23) |
| Infiltration | ASHRAE (Gosney et Olama), D_f 0,8, côté dense = air le plus froid | validé (2026-10-23) |
| Ventilateur ou turbine | **35 W/m³** (chambre de stabilité accessible) ou **60 W/m³** (enceinte de laboratoire), modifiable ; 100 % dans l'enceinte, 24 h/24 | validé (2026-10-23) |
| Éclairage | 40 W (ancien site), **2 h/j** | validé (2026-10-23) |
| Personnes | 0 ; si saisies : 270 − 6 × T (W) | validé (2026-10-23) |
| Câbles et instrumentation | 10 W, 24 h/24 | validé (2026-10-23) |
| Cordons chauffants de cadre | 40 W/m si la consigne est sous +5 °C, sinon 0 ; fraction 0,7 | validé (2026-10-23) |
| Échantillons | masse 0 ; cp 1,5 ; puissance dissipée 0 et sa durée ; humidité dégagée ou absorbée 0 g/h | validé (2026-10-23) |
| Apports manuels | liste vide : libellé, W, h/j ou permanent, fraction restant dans l'enceinte, part latente (g/h) | — |

Chaque apport a une durée de fonctionnement : le **dimensionnement** compte tous les apports présents en
même temps ; la **consommation** les compte selon leur durée déclarée.

### 2.4 Chauffage (section 4)

| Valeur | Proposition | Statut |
|---|---|---|
| Type | électrique (rendement 1) ; eau chaude 70 / 50 °C ; vapeur ; autre (rendement saisi) | validé (2026-10-23) |
| Puissance installée | vide : puissance nécessaire calculée ; saisie : temps de montée calculé | — |
| Maintien | pertes (parois, portes, fuites, air neuf), apports internes non déduits (option décochée, C9) | validé (C9) |

### 2.5 Refroidissement (section 5)

| Valeur | Proposition | Statut |
|---|---|---|
| Type | détente directe ; eau glacée 7 / 12 °C ; autre | validé (2026-10-23) |
| Fluide | R-290 par défaut, R-600a proposé pour les petites enceintes ; contrôles F-Gas et NF EN 378-1 de la chambre froide | validé (2026-10-23) |
| COP | 0,45 × Carnot, T_év = consigne − 8 K, air au condenseur 35 °C, **plafonné à 5** | **validé (2026-10-23)** |
| Surface de la batterie froide | température d'évaporation **+ 2 K** par défaut (prudent), modifiable ; note affichée : « à + 3 K, plus de condensation dans ce cas », et conseil de remonter l'évaporation au-dessus du point de rosée | **validé (2026-10-23)** |
| Condensation sur la batterie | si la surface est sous le point de rosée de la consigne : eau condensée = débit d'air au contact × (w_consigne − w_saturé à la surface), avec débit au contact = froid sensible / [1,006 × (T − T_surface)] ; eau ajoutée au besoin d'humidification, chaleur latente (2 501 kJ/kg) ajoutée au besoin de froid ; conseil affiché : « évaporation à haute température, au-dessus du point de rosée, pour éviter que froid et humidification ne se compensent » | validé (2026-10-23) |
| Froid permanent + réchauffage | réchauffage dimensionné prudemment : puissance frigorifique installée − charge la plus faible (cas hiver, **sans** apports internes) | validé (2026-10-23) |

### 2.6 Humidification (section 6)

| Valeur | Proposition | Statut |
|---|---|---|
| Type | vapeur électrique (électrodes ou résistances) | validé (2026-10-23) |
| Eau d'alimentation | 15 °C | validé (2026-10-23) |
| Puissance | débit × [4,186 × (100 − T_eau) + 2 257] kJ/kg | validé (2026-10-23) |
| Chaleur sensible de la vapeur | débit × [2 676 − (2 501 + 1,86 × T)] kJ/kg, ajoutée au besoin de froid | validé (2026-10-23) |
| Adiabatique (pulvérisation, ultrasons) | refroidit l'air de débit × [2 501 + 1,86 × T − 4,186 × T_eau] kJ/kg | validé (2026-10-23) |
| Mise en régime d'humidité | eau = ρ × V × (w_consigne − w_départ) ; durée de transition 30 min (ancien site) | validé (2026-10-23) |

### 2.7 Séchage (section 7)

| Valeur | Proposition | Statut |
|---|---|---|
| Type | batterie froide en détente directe | validé (2026-10-23) |
| Sortie de batterie | point de rosée de consigne − 2 K, air saturé | **validé (2026-10-23)** |
| Puissance | latent = eau × 2 501 kJ/kg ; sensible = débit d'air traversant × 1,006 × (T − T_batterie) ; réchauffage du même sensible | validé (2026-10-23) |
| Givrage | avertissement si le point de rosée de consigne est sous +2 °C : dessiccateur proposé | validé (2026-10-23) |
| Dessiccateur (rotor) | régénération 1 kWh par kg d'eau | **validé (2026-10-23)** |
| COP du séchage | T_év = T_batterie − 5 K | validé (2026-10-23) |

### 2.8 Autres (section 8)

| Valeur | Proposition | Statut |
|---|---|---|
| Renouvellement d'air | 0 m³/h ; air neuf été 32 °C / 40 % HR, hiver −5 °C / 90 % HR | validé (2026-10-23) |
| Temps de fonctionnement | 24 h/24 | validé (cahier) |
| Marge | 10 %, sur les puissances de maintien et de mise en régime | validé (2026-10-23) |
| Apports internes en chauffe | décoché (C9) | validé (C9) |

### 2.9 Mode simple

Mêmes sections, avec : type d'enceinte, dimensions, profil et consignes, classes de stabilité, protection
de la porte, renouvellement d'air, types de chauffe, de froid, d'humidification et de séchage ; le reste
en hypothèses affichées.

---

## 3. Méthode de calcul

Les deux saisons sont calculées côte à côte (local et air neuf d'été, puis d'hiver).

- **Bilan sensible** (W, + = apport) : U × S × (T_local − T) ; portes : (U × S + cadre) × ΔT ; fuites,
  infiltration et air neuf : ṁ × (1,006 + 1,86 × w_ext) × ΔT ; apports internes.
- **Bilan d'eau** (kg/h) : Σ ṁ × (w_ext − w_consigne) + produits + personnes, **− condensation sur la
  batterie froide**. Positif : séchage ; négatif : humidification.
- **Point fixe** : humidification → chaleur sensible de la vapeur → froid sensible → condensation →
  humidification, jusqu'à stabilité.
- **Dimensionnement** (tous les apports présents, × 1,10) : froid = froid sensible + latent de la
  condensation ; chauffe = pertes sans déduire les apports internes (C9) ; humidification ; séchage.
- **Consommation** (kWh/j) : même bilan avec les apports moyennés sur leur durée déclarée ; froid ÷ COP,
  chauffe électrique, humidificateur, séchage ÷ COP et réchauffage, équipements électriques de l'enceinte
  (ventilateurs, éclairage, instrumentation). La charge en essai (électronique) compte comme chaleur, pas
  comme consommation de l'enceinte.
- **Mise en température** : énergie = Σ(m × cp) × ΔT ; pertes ou apports moyens à la température moyenne
  de la rampe ; temps = énergie / (P installée − pertes) ; ou P = énergie / durée + apports moyens.
- **Régulation** : modulation par défaut ; « froid permanent + réchauffage » : le compresseur tourne à
  pleine puissance, le réchauffage compense l'excédent ; les deux consommations sont comparées.

---

## 4. Trois cas de contrôle (contre-calcul indépendant, 2e version)

Enceinte commune : chambre de stabilité accessible 2 × 1,5 × 2 m (V = 6 m³, S = 20 m²), PUR 80 mm
(U = 0,2741), fuites 0,5 %/h, porte 0,8 × 2 m (5 × 20 s/j), ventilateurs 35 W/m³ = 210 W (24 h/j),
éclairage 40 W (2 h/j), instrumentation 10 W (24 h/j), marge 10 %, local été 30 °C / 60 %, hiver
18 °C / 30 %, air neuf été 32 °C / 40 %, hiver −5 °C / 90 %, eau à 15 °C, air au condenseur 35 °C.

### 4.1 Cas 1 — 40 °C / 75 % HR (ICH accéléré), sans air neuf, chauffe électrique 2 kW

Point de rosée 34,73 °C ; évaporation 32 °C, surface de la batterie 34 °C : **sous le point de rosée**.

| | Été | Hiver |
|---|---|---|
| Parois, porte, fuites, infiltration | −71,30 W | −160,34 W |
| Apports internes (dimensionnement) | +260,00 W | +260,00 W |
| Bilan d'eau (fuites, porte) | −25,29 g/h | −59,80 g/h |
| **Condensation sur la batterie** | **174,70 g/h** (latent 121,37 W) | 93,47 g/h (64,93 W) |
| Humidification nécessaire | 200,00 g/h | 153,27 g/h |
| **Froid** (sensible + condensation, × 1,1) | **347,23 W** (194,29 + 121,37) | 185,77 W |
| **Chauffe** (× 1,1) | 78,43 W | **176,37 W** |
| **Humidification** (× 1,1) | **159,67 W** | 122,36 W |
| Séchage | 0 | 0 |
| Consommation | 9,48 kWh/j | 7,96 kWh/j |
| Eau consommée / condensats | 3,99 / 3,38 L/j | 2,87 / 1,43 L/j |

Conseil affiché : évaporation à haute température, au-dessus du point de rosée (par exemple surface à
35 °C ou plus). Sans condensation, l'humidification ne serait que de 25 g/h (20 W). Mise en régime depuis
30 °C / 60 % : capacité 124,66 kJ/K, énergie 1 246,6 kJ, pertes moyennes 33,9 W → **10,6 min** avec 2 kW ;
humidité : 132,3 g d'eau, soit 264,6 g/h (192 W) en 30 min.

### 4.2 Cas 2 — 25 °C / 60 % HR (ICH long terme), air neuf 30 m³/h

Point de rosée 16,71 °C ; surface de la batterie 19 °C : pas de condensation.

| | Été | Hiver |
|---|---|---|
| Parois | +27,41 W | −38,37 W |
| Porte, fuites, infiltration | +7,68 W | −11,12 W |
| Air neuf (sensible) | +70,50 W | −296,86 W |
| Apports internes (dimensionnement) | +260,00 W | +260,00 W |
| Bilan d'eau | +4,36 g/h | −349,49 g/h |
| **Froid** (× 1,1) | **402,15 W** | 0 |
| **Chauffe** (× 1,1, apports non déduits) | 0 | **381,00 W** |
| **Humidification** (× 1,1) | 0 | **279,02 W** (349,5 g/h) |
| **Séchage** (× 1,1) | 12,82 W (batterie à 14,7 °C ; réchauffage 9,49 W) | 0 |
| Consommation | 7,34 kWh/j | 14,10 kWh/j (dont chauffe nette 110,6 W, apports moyens déduits) |
| Eau consommée / condensats | 0 / 0,10 L/j | 8,39 / 0 L/j |

### 4.3 Cas 3 — 5 °C (réfrigéré, humidité non régulée), électronique 50 kg dissipant 500 W pendant 12 h/j, descente de 30 à 5 °C en 60 min, stabilité ± 0,5 K

| | Été | Hiver |
|---|---|---|
| Parois | +137,05 W | +71,27 W |
| Porte, fuites, infiltration | +46,27 W | +21,91 W |
| Apports internes (dimensionnement, électronique en marche) | +760,00 W | +760,00 W |
| **Froid de maintien** (× 1,1) | **1 037,65 W** | 938,50 W |
| Charge moyenne (apports selon leur durée) | 656,66 W | 566,51 W |
| **Consommation, modulation (défaut)** | **11,58 kWh/j** | **10,73 kWh/j** |
| Consommation, froid permanent + réchauffage | 64,34 kWh/j | 66,51 kWh/j |

Descente : capacité 170,65 kJ/K (dont électronique 45,00), énergie 4 266,2 kJ, apports moyens 844,7 W →
2 029,8 W, soit **2 232,7 W à installer** (supérieur au maintien). Réchauffage si l'on choisit « froid
permanent + réchauffage », dimensionné sans apports internes : 2 232,7 − 93,2 = **2 139,6 W**. COP 2,53
(évaporation −3 °C). La modulation est proposée par défaut pour ± 0,5 K ; la comparaison est affichée.

---

## 5. Décisions du 2026-10-23 (points qui restaient à trancher)

1. Surface de la batterie froide = évaporation **+ 2 K** par défaut (prudent), modifiable, avec la note
   « à + 3 K, plus de condensation dans ce cas » et le conseil de remonter la température d'évaporation
   au-dessus du point de rosée.
2. Éclairage 2 h/j et instrumentation 24 h/24 pour la consommation : validés.
3. Type d'enceinte par défaut : chambre de stabilité accessible (35 W/m³) : validé.
4. Régulation : modulation conseillée pour ± 0,5 K et ± 1 K ; tout ou rien acceptable pour ± 2 K et ± 3 K.
5. Non-régression : comme pour la chambre froide, les 26 scénarios de l'ancien formulaire ne sont plus
   rejoués (champs différents) ; ils restent dans `golden/thermodynamique.json` comme trace. Les méthodes
   inchangées sont vérifiées par les tests (C4 point de rosée de Bolton, C6 un 0 saisi reste 0, C9 apports
   internes non déduits du chauffage), les postes refondus par les 3 cas de référence.

---

## 6. Hypothèses ajoutées pendant l'implémentation — validées (2026-10-23)

Validées avec deux corrections de calcul (2026-10-23) :

- **Puissances à installer** : chaque carte (refroidissement, chauffe, séchage, humidification) affiche
  max(maintien été, maintien hiver, mise en régime), ou la puissance installée saisie, avec en dessous
  « maintien : X W · mise en régime : Y W » (ou la durée de mise en régime avec la puissance saisie).
- **Mise en régime** : durées souhaitées par défaut de **60 min** (température) et **30 min** (humidité),
  modifiables dans les deux modes ; l'outil calcule la puissance nécessaire, sauf si une puissance est
  installée (chauffe ou froid) : il calcule alors la durée. Plus de durée calculée avec la seule puissance
  de maintien. Départ plus humide que la consigne : séchage de mise en régime (batterie à point de rosée
  − 2 K).

1. **Mises en régime pour les deux saisons** : départ aux conditions du local de la saison (ou à la
   température et à l'humidité de départ saisies).
2. **Mise en régime** : puissance nécessaire pour la durée souhaitée ; durée calculée seulement avec une
   puissance installée saisie ; « consigne non atteinte » si elle ne couvre pas les pertes ou les apports
   moyens ; avertissement si elle est inférieure au maintien.
3. **Pertes pendant la rampe** : parois, portes, vitrages et fuites (local), plus l'air neuf (à sa propre
   température).
4. **Bilan d'eau net** = eau entrante − condensation sur la batterie : négatif → humidification, positif →
   séchage.
5. **« Froid permanent + réchauffage »** : consommation = froid à installer ÷ COP + réchauffage, avec
   réchauffage = froid à installer − charge nette moyenne − latent condensé.
6. **Chauffe à installer** : max(maintien des deux saisons, mise en régime) ; en « froid permanent +
   réchauffage », au moins le réchauffage dimensionné.
7. **Humidification et séchage de mise en régime** : marge de 10 % appliquée.
8. **Eau glacée** : surface de la batterie = départ + 2 K ; COP du groupe avec une évaporation à
   départ − 5 K ; débit d'eau affiché. **Autre** : COP saisi.
9. **Chauffage à eau chaude, vapeur ou autre** et **humidification par vapeur réseau** : hors de la
   consommation électrique, affichés en « énergie de chauffe hors électricité » (kWh/j).
10. **Humidification adiabatique** : refroidit l'air de débit × (2 501 + 1,86 × T − 4,186 × T_eau) kJ/kg.
11. **Dessiccateur** : 1 kWh/kg ; chaleur d'adsorption ≈ eau × 2 501 kJ/kg ajoutée au froid.
12. **Échantillons, écart à l'introduction** : m × cp × ΔT réparti sur 12 h (dimensionnement), 24 h
    (consommation) ; humidité dégagée (+) ou absorbée (−) ajoutée au bilan d'eau.
13. **Apports manuels** : chaleur = puissance × fraction ; consommation = puissance × durée.
14. **Cordons de cadre** : électricité comptée 24 h/24.
15. **Temps de fonctionnement** inférieur à 24 h : froid de maintien × 24 / heures.
16. **Profils** : « chaleur sèche » et « froid » séparés (4 profils) ; « Personnalisé » dès qu'une consigne
    est modifiée.
17. **Fluide** : charge estimée pour le froid à installer ; mêmes contrôles que la chambre froide.
18. **Régulation par défaut** (mode pro) : suit la classe de stabilité tant qu'elle n'est pas choisie à la main.

### 6.1 Puissances à installer et mises en régime des 3 cas (contre-calcul, 2026-10-23)

| | Cas 1 | Cas 2 | Cas 3 |
|---|---|---|---|
| **Refroidissement à installer** (maintien · mise en régime) | **347,2 W** (347,2 · 0) | **544,5 W** (402,2 · 544,5) | **2 232,7 W** (1 037,7 · 2 232,7) |
| **Chauffe à installer** | **2 000 W saisis** (maintien 176,4) | **580,9 W** (381,0 · 580,9) | 0 |
| **Humidification à installer** | **340,5 W** (159,7 · 340,5) | **279,0 W** (279,0 · 90,9) | — |
| **Séchage à installer** | 0 | **172,3 W** (12,8 · 172,3) | — |
| Mise en température, été | montée 30 → 40 °C : 10,6 min (2 kW) | descente 30 → 25 °C en 60 min : 544,5 W | descente 30 → 5 °C en 60 min : 2 232,7 W |
| Mise en température, hiver | montée 18 → 40 °C : 23,7 min (2 kW) | montée 18 → 25 °C en 60 min : 580,9 W | descente 18 → 5 °C en 60 min : 1 562,3 W |
| Humidité en 30 min, été / hiver | 132,3 g → 211,3 W ; 213,2 g → 340,5 W | 29,3 g à retirer → séchage 172,3 W ; 56,9 g → 90,9 W | — |
| Réchauffage (froid permanent) | 507,6 W | 890,9 W | 2 139,6 W |
| Modulation / froid permanent, été | 9,48 / 12,15 kWh/j | 7,34 / 13,62 kWh/j | 11,58 / 64,34 kWh/j |
| Modulation / froid permanent, hiver | 7,96 / 14,85 kWh/j | 14,10 / 29,97 kWh/j | 10,73 / 66,51 kWh/j |

---

## 7. Types d'enceinte — validés (2026-10-23)

Le type remplit les valeurs par défaut, toutes modifiables en mode pro. « Modulaire » reprend les valeurs
des 3 cas. Module extérieur, hiver : option « module chauffé hors gel » cochée par défaut (5 °C / 70 %) ;
décochée, l'hiver est celui de l'extérieur, température minimale −5 °C par défaut (modifiable), 90 % HR.

| Valeur | Monobloc | Modulaire | Modulaire extérieure |
|---|---|---|---|
| Usage | armoire ou enceinte de laboratoire, 150 à 3 000 L | chambre accessible dans un bâtiment | chambre dans un module extérieur (bungalow, module préfabriqué) |
| Saisie | volume utile en litres **ou** dimensions | dimensions | dimensions |
| Volume par défaut | **1 000 L** ; dimensions déduites : largeur L = V^(1/3), profondeur 0,8 L, hauteur 1,25 L | 2 × 1,5 × 2 m | 2 × 1,5 × 2 m |
| Ventilation | **60 W/m³** | 35 W/m³ | 35 W/m³ |
| Étagères / structure | **15 kg / 25 kg** | 30 kg / 40 kg | 30 kg / 40 kg |
| Peau intérieure | inox 1 mm | inox 1 mm | inox 1 mm |
| Éclairage | **20 W**, 2 h/j | 40 W, 2 h/j | 40 W, 2 h/j |
| Porte | **toute la face avant** (largeur × hauteur intérieures) | 0,8 × 2 m | 0,8 × 2 m |
| Local été / hiver | 30 °C / 60 % ; 18 °C / 30 % | 30 °C / 60 % ; 18 °C / 30 % | **module : 35 °C / 45 % ; 5 °C / 70 % (chauffé hors gel) ou −5 °C / 90 % (non chauffé)** |
| Ensoleillement direct du module | — | — | **+ 5 K en été** (40 °C) |
| Note affichée | la chaleur du groupe frigorifique intégré est rejetée dans le local, pas dans l'enceinte | — | — |

Air neuf inchangé (32 °C / 40 % en été, −5 °C / 90 % en hiver).

---

## 8. Régulation PID — validée et implémentée (2026-10-23)

Section « 9. Régulation » de la page (les Résultats deviennent la section 10), dans les deux modes (le
mode simple affiche les réglages avec les actionneurs par défaut). Les réglages des 3 cas sont des cas de
référence (`pid` dans le fichier golden, contre-calcul Python, Vitest). Option PID : Td = θ / 2,
**validé le 2026-10-24** comme limite de la méthode IMC pour τ ≫ θ ; la page garde la mention « à vérifier
par essai ». Phase 2 (simulation de la réponse) : notée dans `docs/CALCULATRICE_AJOUTS`.

### 8.1 Valeurs par défaut proposées (à vérifier)

| Valeur | Proposition |
|---|---|
| Actionneurs, chauffe | contacteur (cycle long), relais statique (cycle court ≤ 1 s, 0 à 100 %), gradateur à angle de phase (0 à 100 %) ; **relais statique** par défaut |
| Actionneurs, froid | tout ou rien ; compresseur à variateur (plage minimale **30 %**, modifiable) ; by-pass de gaz chaud (0 à 100 %) ; détendeur électronique (plage minimale 10 %) ; vanne d'eau glacée 3 voies (0 à 100 %) ; **variateur** par défaut |
| Actionneurs, humidification | tout ou rien ; vapeur modulante (plage minimale **10 %**) ; **vapeur modulante** par défaut |
| Actionneurs, séchage | vanne de batterie (0 à 100 %) ; dessiccateur modulant (plage minimale 20 %) |
| Puissance minimale à fournir | chauffe : besoin le plus faible des deux saisons ; froid : charge la plus faible (hiver, apports intermittents absents : éclairage et échantillons éteints, apports permanents présents) |
| Seuil « puissance trop forte » | installée > **3 × maintien** (modifiable) : découpage en étages conseillé (étage de base modulé + étage de mise en régime) |
| Brassage | **60 renouvellements par heure** (modulaire), **150 vol/h** (monobloc), modifiable |
| Temps de réponse des sondes | température (PT100 classe A) **20 s** ; humidité (capacitive) **30 s** |
| Étendue de mesure (pour Xp en %) | **−50 à +100 °C** (150 K) ; 0 à 100 % HR |
| Boucle fermée | τc = θ par défaut, curseur « nerveux » (τc = θ/2) à « robuste » (τc = 3 θ) |
| Matériaux hygroscopiques | aucun par défaut (capacité en eau de l'air seule) ; option : masse et capacité d'absorption (g d'eau par kg et par % HR) |

### 8.2 Modèle et méthode

- **Température** : premier ordre + temps mort. UA_total = U × S + portes + fuites + air neuf (W/K) ;
  gain en K par % de sortie K_u = P_installée / (100 × UA_total) ; constante de temps τ = C_totale / UA_total
  (C = air + peau + étagères + structure + échantillons, comme la mise en régime) ; temps mort θ = volume /
  débit de brassage + temps de réponse de la sonde.
- **Humidité** : capacité en eau M = ρ × V (kg d'air sec, plus les matériaux hygroscopiques) ; échanges
  ṁ = fuites + infiltration + air neuf (kg/s) ; τ = M / ṁ ; gain K_u = débit maximal de l'actionneur /
  (100 × ṁ) × ∂HR/∂w (≈ HR / w à température fixe), en % HR par % de sortie.
- **Réglage SIMC (Skogestad)** : Kc = τ / [K_u × (τc + θ)] (en % par K) ; Ti = min(τ, 4 × (τc + θ)) ;
  Td = 0 (PI), option PID. Quand τ ≫ θ (cas habituel des enceintes, τ/θ de 94 à 315 ci-dessous), Ti = 4 ×
  (τc + θ) : réglage « procédé quasi intégrateur ». Bande proportionnelle Xp = 100 / Kc (K), et en % de
  l'étendue de mesure.
- Affichage : « Réglages de départ indicatifs, issus d'un modèle simplifié (l'air réagit plus vite que les
  masses). Terminer la mise au point par un autoréglage du régulateur et des essais. »

### 8.3 Résultats sur les 3 cas (brassage 60 vol/h, sondes 20 s et 30 s, τc = θ)

| | Cas 1 | Cas 2 | Cas 3 |
|---|---|---|---|
| UA_total ; capacité C | 6,78 W/K ; 124,7 kJ/K | 16,63 W/K (air neuf) ; 125,1 kJ/K | 6,78 W/K ; 170,6 kJ/K |
| τ ; θ (température) | 5,1 h ; 80 s (60 + 20) | 2,1 h ; 80 s | 7,0 h ; 80 s |
| **Chauffe** : P ; K_u | 2 000 W ; 2,95 K/% | 581 W ; 0,35 K/% | — |
| Chauffe : **Xp ; Ti ; Td** | **2,6 K (1,7 %) ; 640 s ; 0** | **0,74 K (0,5 %) ; 640 s ; 0** | — |
| Chauffe : taux minimal ; installée / maintien | 3,6 % (71 W) ; **11,3 → étages conseillés** | 59,6 % ; 1,5 | — |
| **Froid** : P ; K_u | 347 W ; 0,51 K/% | 545 W ; 0,33 K/% | 2 233 W ; 3,30 K/% |
| Froid : **Xp ; Ti ; Td** | **0,45 K (0,3 %) ; 640 s ; 0** | **0,70 K (0,5 %) ; 640 s ; 0** | **2,1 K (1,4 %) ; 640 s ; 0** |
| Froid : taux minimal (variateur 30 %) | 17,2 % (60 W) : **sous la plage** → by-pass ou réchauffage PID | 0 % en hiver (froid arrêté) | 14,0 % (313 W) : **sous la plage** → by-pass ou réchauffage PID |
| **Humidification** : débit max ; échanges ; τ ; θ | 426 g/h ; 1,27 kg/h ; 5,2 h ; 90 s | 349 g/h ; 36,1 kg/h ; 0,20 h ; 90 s | — |
| Humidification : **Xp ; Ti** | **6,7 % HR ; 720 s** | **12,5 % HR ; 703 s** | — |

Lecture : les réglages en froid et en chauffe diffèrent (gains différents) ; le cas 1 (± 2 K) montre une
chauffe de 2 kW, 11 fois le maintien : un étage de base modulé (≈ 300 W) et un étage de mise en régime
sont conseillés. Les cas 1 et 3 descendent sous la plage d'un variateur à 30 % : by-pass de gaz chaud ou
froid permanent + réchauffage PID. Le séchage suit le modèle de l'humidification (gain de signe opposé).

### 8.4 Architecture conseillée selon la classe de stabilité (tableau affiché, à vérifier)

| Classe | Architecture |
|---|---|
| ± 0,5 K / ± 1 % HR | sorties continues ou relais statiques à cycle court (≤ 1 s) ; PID chaud / froid en « split range » avec zone morte réglable ; sonde PT100 classe A ; actionneurs modulants |
| ± 1 K / ± 2 à ± 3 % HR | PID modulant, cycle court |
| ± 2 à ± 3 K / ± 5 à ± 10 % HR | PID à cycle plus long ; tout ou rien acceptable pour certains étages |
| Humidité, toutes classes | régulation sur le point de rosée ou l'humidité absolue quand la température varie (couplage température / humidité) |

Rappel affiché : la stabilité (dans le temps, en un point) n'est pas l'homogénéité (dans l'espace) ; la
caractérisation se fait selon le FD X 15-140.
