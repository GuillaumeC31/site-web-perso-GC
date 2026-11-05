# Calculatrice — anomalies de l'ancien site

Règle décidée au lot 5 : les outils sont d'abord **portés à l'identique** (mêmes formules, mêmes
constantes, mêmes arrondis), puis vérifiés contre les résultats de l'ancien site
(`assets/calculators/golden/`, capturés par `scripts/calculator/capture-golden.mjs`). Les anomalies
sont listées ici ; chaque correction est décidée outil par outil.

Quand une correction est appliquée, les cas de référence de l'ancien site sont **tous gardés** : un
cas modifié reçoit sa nouvelle valeur attendue dans le tableau `CORRECTIONS` du test de la catégorie
(ex. `assets/calculators/electricite.test.js`), avec la source en commentaire. Le test vérifie aussi
qu'aucune correction ne vise un cas inexistant.

| Statut | Signification |
|---|---|
| À décider | Reproduit tel quel sur le nouveau site, en attente de décision |
| Corrigé | Correction validée et appliquée (date et source indiquées) |
| Conservé | Comportement gardé volontairement |

---

## Électricité (lot 5a)

### A1 — Résistivité différente entre chute de tension et câbles — Corrigé (2026-10-22)
- **Outils** : `electricite/chute-tension` et `electricite/calculs-cables`.
- **Anomalie** : « Chute de tension » utilisait ρ = 0,0225 Ω·mm²/m (cuivre seulement) ;
  « Dimensionnement des câbles » utilisait ρ = 0,0171 (cuivre) et 0,0282 (aluminium), valeurs à 20 °C.
  Pour 50 m, 10 A et 2,5 mm² de cuivre : 9,00 V d'un côté, 6,84 V de l'autre.
- **Décision** : une seule résistivité de calcul pour les deux outils, au sens de la NF C 15-100 /
  UTE C 15-105 (conducteurs en service) : **cuivre 0,0225, aluminium 0,036 Ω·mm²/m**. Choix du matériau
  dans les deux outils ; valeur et norme affichées sous chaque résultat.
- **Correction** : `RHO` et `resistivityNote()` dans `assets/calculators/electricite.js` ; liste
  « Matériau » ajoutée à la chute de tension. Cas de référence modifiés (6) : section cuivre 28,50 → 37,50 mm²,
  aluminium 47,00 → 60,00 ; longueur cuivre 4,39 → 3,33 m, aluminium 31,07 → 24,34 ; chute de tension
  cuivre 6,84 → 9,00 V, aluminium 22,21 → 28,35. Les 4 cas de « Chute de tension » (cuivre) sont inchangés.
- **Reste une limite** (affichée sur la page) : chute de tension en monophasé, cos φ = 1, sans réactance.

### A2 — Condensateur Steinmetz : méthode « puissance » surévaluée — Corrigé (2026-10-22)
- **Outil** : `electricite/steinmetz`.
- **Anomalie** : méthode « puissance » C = 50 × P(kW) / 230 × 1000, soit **326 µF pour 1,5 kW**, alors
  que la méthode « courant » (C = 14 × I) donne **91 µF pour 6,5 A**, courant typique du même moteur.
- **Décision** : corriger la méthode « puissance » avec la règle d'environ 70 µF/kW (moteur 230/400 V
  couplé en triangle, réseau monophasé 230 V 50 Hz), formule affichée.
- **Formule retenue** (validée le 2026-10-22) : C (µF) = 70 × P (kW) ; saisie en ch : P (kW) = P (ch) / 1,35962.
  Méthode « courant » (C = 14 × I) inchangée. Source affichée : règle pratique (aucune norme citée).
- **Correction** : `STEINMETZ_MICROFARAD_PER_KW` dans `assets/calculators/electricite.js`, formule et
  hypothèse affichées sur la page. Cas de référence modifiés (3) : 1,5 kW 326 → 105 µF ;
  0,37 kW 80 → 26 µF ; 2 ch 320 → 103 µF. Les deux méthodes sont désormais cohérentes
  (1,5 kW : 105 µF ; 6,5 A : 91 µF).

### A3 — Valeurs négatives, nulles ou hors plage acceptées — Corrigé (2026-10-22)
- **Outils** : tous les outils Électricité.
- **Anomalie** : une tension négative donnait une puissance négative (−1 840 W) ; pour Q, un cos φ > 1
  était ramené à 1 et un cos φ négatif accepté ; dans les câbles, une longueur nulle ou une section
  négative affichait « 0,00 » sans explication.
- **Décision** : refuser les valeurs négatives ou nulles quand elles n'ont pas de sens, avec un message clair.
- **Correction** : module `assets/calculators/inputs.js` (règles : strictement positive, positive ou
  nulle, cos φ entre 0 et 1, entier ≥ 1) et règles de chaque calcul (`INPUTS` dans `electricite.js`) ;
  message affiché sous le bloc (ex. « Longueur (L) : la valeur doit être strictement positive. »).
  Un champ vide n'est pas une erreur : le résultat reste « — ». Cas de référence modifiés (6) :
  P avec U = −230 V ; Q avec cos φ = 1,2 et −0,5 ; section avec L = 0 ; longueur avec courant vide ;
  chute de tension avec S = −2,5 → plus de résultat.

### A4 — Nombre de conducteurs décimal tronqué — Corrigé (2026-10-22)
- **Outil** : `electricite/calculs-cables` (masse).
- **Anomalie** : « 2,7 » conducteurs comptait pour 2 ; un champ vide ou 0 comptait pour 1.
- **Décision** : nombre de conducteurs entier obligatoire.
- **Correction** : règle « entier ≥ 1 » avec message. Cas de référence modifiés (2) : conducteurs vides
  (ancien 0,13 kg) et 2,7 (ancien 0,30 kg) → plus de résultat.

### A5 — Relèvement du cos φ : capacité « totale équivalente » ambiguë — Corrigé (2026-10-22)
- **Outil** : `electricite/relevement-cos-phi`.
- **Anomalie** : une seule capacité C = Qc / (U² × 2πf), sans préciser le couplage des condensateurs.
- **Décision** : afficher la capacité par phase pour les montages étoile et triangle.
- **Correction** : U est la tension entre phases. Étoile (chaque condensateur sous U/√3) :
  C = Qc / (U² × 2πf), ce qui est la valeur de l'ancien site ; triangle (sous U) : C = Qc / (3 × U² × 2πf).
  Aucun cas de référence modifié (la valeur « étoile » est celle de l'ancien site) ; nouveau test :
  150 kW, cos φ 0,75 → 0,93, 400 V, 50 Hz : 1 452,4 µF en étoile, 484,1 µF en triangle. Message
  d'information quand le cos φ initial atteint déjà la cible (Qc = 0).

---

## Électronique, capteurs, mécanique, impression 3D, convertisseur (lot 5b)

### A3 étendue — Valeurs sans signification refusées — Corrigé (2026-10-22)
- **Décision** (2026-10-22) : la règle A3 s'applique à toutes les catégories du lot 5.
- **Correction** : règles `INPUTS` de chaque bibliothèque (`electronique.js`, `capteurs.js`,
  `mecanique.js`, `impression3d.js`) avec le module commun `inputs.js` (nouvelle règle : pourcentage
  entre 0 et 100). Exemples : nombre de dents entier ≥ 1, rendement entre 0 et 100 %, tige plus fine
  que le piston, efforts et longueurs strictement positifs, durée d'impression obligatoire.
  Cas de référence modifiés (8) :
  - Électronique : pont diviseur avec R1 = −1 000 Ω (ancien 5,56 V) ; autonomie avec −50 mA (ancien −28,0 h).
  - Mécanique : 12,5 dents (ancien rapport 1 : 3,84) ; vitesse −3 000 tr/min et rendement 120 %
    (ancien −750,0 tr/min et 24,00 N·m, le rapport reste affiché) ; poutre avec force −1 000 N puis
    longueur −500 mm (ancien flèche −0,477 mm et moment −500,00 N·m).
  - Impression 3D : distance obtenue −98,5 mm (ancien −94,42 steps/mm) ; poids −150 g (ancien −2,85 €).

### B1 — Résistance pour LED : puissance conseillée sans marge — Corrigé (2026-10-22)
- **Outils** : `electronique/resistance-led` et, par cohérence, le conseil de puissance de
  `electronique/code-couleur-resistance`.
- **Anomalie** : la puissance conseillée était la première valeur normalisée **supérieure ou égale** à la
  puissance dissipée (1/8, 1/4, 1/2, 1, 2 W). Une résistance qui dissipe 0,25 W était conseillée en
  1/4 W, sans marge.
- **Décision** : puissance conseillée = valeur normalisée immédiatement supérieure à 2 × la puissance
  dissipée (une valeur normalisée égale à 2 × P est retenue).
- **Correction** : `recommendedPowerRating()` dans `assets/calculators/electronique.js`, avec
  `POWER_MARGIN = 2` et la liste `POWER_RATINGS` : 1/8, 1/4, 1/2, 1 et 2 W (résistances à couche),
  puis 3, 5, 7, 10, 15, 20, 25, 50 et 100 W (résistances de puissance) ; au-delà, l'entier supérieur.
  La règle est affichée sous le résultat. Le code couleur utilise la même fonction ; ses libellés
  restent ceux de l'ancien site (« suffisant », « recommandé »).
- **Cas de référence modifiés (5)** : LED 0,176 W : 1/4 → 1/2 W ; 0,438 W : 1/2 → 1 W ;
  7,28 W : 8 → 15 W ; 4,536 W : 5 → 10 W ; code couleur 3 W : « 5 W ou plus » → 7 W. Les autres
  conseils de l'ancien code couleur appliquaient déjà une marge de 2 et sont inchangés.

### B2 — Valeur E24 tronquée sous 1 kΩ — Corrigé (2026-10-22, affichage)
- **Outil** : `electronique/resistance-led`.
- **Anomalie** : la valeur normalisée était affichée sans décimale sous 1 kΩ : « 4 » au lieu de 4,3 Ω.
- **Correction** : `formatResistance()` (« 4,3 Ω », « 470 Ω », « 1,1 kΩ »). Calcul inchangé.
  Cas de référence modifié (1) : 3,6 V, LED 3,2 V, 100 mA → E24 « 4 » devient 4,3 Ω (conseil de
  puissance inchangé par B1 : 0,04 W × 2 = 0,08 W → 1/8 W).

### B3 — Code couleur : valeur E24 la plus proche sans changement de décade — Corrigé (2026-10-22)
- **Outil** : `electronique/code-couleur-resistance`.
- **Anomalie** : `nearestE24()` cherchait dans la décade de la valeur saisie seulement : 99 Ω donnait
  91 Ω alors que 100 Ω est plus proche.
- **Décision** : chercher dans la décade inférieure, la même décade et la décade supérieure, en
  comparant les rapports (distance logarithmique |log(R / E24)|). Afficher aussi les valeurs E24 juste
  en dessous et juste au-dessus.
- **Correction** : `nearestE24()` et `e24Neighbors()` dans `assets/calculators/resistor_colors.js`
  (pour une valeur de la série, ses deux voisines : 1 kΩ → 910 Ω et 1,1 kΩ) ; nouvelle ligne
  « E24 juste en dessous / au-dessus » sur la page.
- **Cas de référence modifié (1)** : 99 Ω → 100 Ω (ancien 91 Ω). Les autres (1 234 Ω → 1,2 kΩ ;
  4,6 Ω → 4,7 Ω ; 0 → aucun résultat) sont inchangés.

### B4 — Engrenages : seuil d'interférence à 13 dents — Corrigé (2026-10-22)
- **Outil** : `mecanique/engrenages`.
- **Anomalie** : l'alerte « interférence » apparaissait sous 13 dents, quel que soit l'angle de pression.
- **Décision** : seuils calculés depuis l'angle de pression α, z_min = 2 / sin²α ; avertissement sous la
  valeur théorique (17 dents à 20°), alerte forte sous 14 (limite pratique à 20°). Formule affichée.
- **Correction** : `undercutLimits()` et `undercutCheck()` dans `assets/calculators/mecanique.js`.
  Nouveau champ « Angle de pression α » (20° par défaut, accepté de 10 à 35°). Limite théorique
  2 / sin²α et limite pratique 5/6 de la théorique (taillage par crémaillère), arrondies à l'entier :
  20° → 17,1 → 17 et 14,2 → 14 ; 14,5° → 32 et 27 ; 25° → 11 et 9. Le test porte sur la plus petite
  roue du train. Les deux limites et leur calcul sont affichés sur la page.
- **Cas de référence modifiés (2)** : 13/47 dents → alerte forte (ancien : aucune) ;
  20/60 puis 15/45 dents → avertissement (ancien : aucun). Les cas à 12 dents gardent leur alerte
  forte ; 17 dents et plus : aucune alerte, comme avant.

### B5 — PT100 : 18,52 Ω affiché « Hors plage » — Corrigé (2026-10-22)
- **Outils** : `mesures-capteurs/pt100` et `pt1000`.
- **Anomalie** : 18,52 Ω est la valeur affichée pour −200 °C (18,5201 Ω), borne de la CEI 60751 ; la
  conversion inverse la jugeait hors plage.
- **Décision** : la plage inclut ses bornes, −200 °C et +850 °C (IEC 60751) ; 18,52 Ω doit donner −200 °C.
- **Correction** : `ptTemperature()` dans `assets/calculators/capteurs.js` accepte une résistance
  jusqu'à une demi-unité du dernier chiffre affiché au-delà des bornes (`RESISTANCE_TOLERANCE` =
  0,005 Ω) et renvoie alors la borne exacte : 18,52 Ω → −200,00 °C ; 185,20 Ω (PT1000) → −200,00 °C ;
  390,48 Ω → 850,00 °C. 18,51 Ω et 390,49 Ω restent hors plage.
- **Cas de référence modifié (1)** : PT100 18,52 Ω → −200,00 °C, sans alerte (ancien : « Hors plage »).

### Non-régression du lot 5b
178 cas de l'ancien site capturés (`golden/electronique.json` 64, `mesures-capteurs.json` 55,
`mecanique.json` 24, `impression-3d.json` 14, `convertisseur.json` 21) : 18 reçoivent leur nouvelle
valeur attendue (A3 étendue : 8 ; B1 : 5 ; B2 : 1 ; B3 : 1 ; B4 : 2 ; B5 : 1), les 160 autres restent
identiques.
Le facteur 0,7 de l'autonomie de batterie n'est pas une anomalie : il était annoncé sur l'ancienne page.

---

## Thermodynamique (lot 5c)

Particularité : sur l'ancien site, trois blocs ne fonctionnaient pas du tout (C2, C11). Le banc de
capture crée tous les éléments attendus par l'ancien code : les valeurs de référence sont donc celles
que l'ancien code calcule, même quand l'ancienne page ne les affichait pas.
Décision du 2026-10-23 : toutes les anomalies du lot sont corrigées.

### A3 étendue — Corrigé (2026-10-22)
Cas de référence modifiés (16) : chauffe d'eau avec une puissance, un volume ou une durée négatifs (3) ;
gaz parfaits avec n négatif, T sous le zéro absolu, V négatif (3) ; point de rosée avec HR = 0 % ou
120 % (2) ; fluides frigorigènes avec une charge vide (ancien « 0.000 ») ou négative (2) ; chauffe d'un
fluide avec une puissance nulle, un volume négatif ou vide (3) ; chambre froide avec une longueur vide
ou négative (2) ; enceinte climatique avec une longueur vide (1). Seules les dimensions sont
obligatoires dans la chambre froide et l'enceinte ; un autre champ vide prend la valeur par défaut.

### C1 — Chauffe d'un fluide : énergie arrondie avant la durée et la puissance — Corrigé (2026-10-23)
- **Anomalie** : l'ancien outil relisait l'énergie affichée (arrondie à 0,01 kWh) : 1 L d'eau chauffé
  de 1 K (0,00116 kWh) avec 100 W donnait 0,00 h.
- **Décision** : calculs sur l'énergie exacte, arrondi à l'affichage seulement.
- **Correction** : `chauffe_fluide_controller.js` passe l'énergie exacte à `heatingHours()` et
  `heatingWatts()`. Cas modifiés (3) : 1 L, 100 W : 0,00 → 0,01 h ; 100 L de glycol en 1,5 h :
  1980 → 1979 W ; 80 L personnalisé, 1500 W : 2,37 → 2,36 h.

### C2 — Point de rosée : bloc « Psychromètre » inopérant — Corrigé (2026-10-22, nécessaire)
- **Anomalie** : les champs du bloc 3 n'étaient pas déclarés dans l'ancien contrôleur : le calcul ne
  s'exécutait jamais.
- **Correction** : code de l'ancien site porté (`fromWetBulb()`), test écrit à la main :
  20 °C sec, 15 °C humide, 1013,25 hPa → 58,6 % HR, rosée 11,64 °C, 10,12 g/m³.

### C3 — Puissance latente de l'air surévaluée de 20 % — Corrigé (2026-10-23)
- **Outils** : chambre froide (renouvellement d'air), enceinte climatique (air neuf, mode pro).
- **Anomalie** : `latentDehumidPowerW()` multipliait un débit massique (kg/s) par un écart d'humidité
  exprimé par m³ d'air : résultat × 1,2.
- **Décision** : Q_lat = ṁ_air × Δw × L_v, L_v = 2 501 kJ/kg ; avec ρ = 1,2 kg/m³ :
  Q (W) ≈ 0,83 × qv (m³/h) × Δw (g/kg). Formule et hypothèses affichées.
- **Correction** : `psychrometrics.js` (Δw = écart de teneur en eau, kg d'eau par kg d'air sec,
  `humidityRatio()`), note sous les formulaires. Cas modifiés : 2 appels de la librairie
  (757,3 → 682,3 W ; 869,4 → 758,4 W) et 3 scénarios de la chambre froide (latent de l'air).

### C4 — Deux jeux de constantes de Magnus — Corrigé (2026-10-23)
- **Anomalie** : `dewPoint()` utilisait 17,27 / 237,7 °C, le reste 17,67 / 243,5 °C.
- **Décision** : un seul jeu, 17,67 / 243,5 °C (Bolton, 1980), affiché dans la note.
- **Correction** : constante `MAGNUS` de `psychrometrics.js`, mêmes valeurs dans `thermodynamique.js`.
  Cas modifiés : 3 appels de `dewPoint()` (ex. 25 °C, 60 % : 16,684 → 16,705 °C) ; l'outil Point de rosée
  utilisait déjà ces constantes (20 °C, 50 % HR → 9,27 °C, inchangé).

### C5 — Fluides frigorigènes : rapport du GIEC de chaque PRG — Corrigé (2026-10-23)
- **Décision** : chaque PRG indique son rapport GIEC d'origine (AR4 ou AR5) ; la source (règlement
  (UE) 2024/573 et son annexe) ne s'affiche qu'après vérification dans l'admin.
- **Correction** : champ `gwpReport` de chaque fluide (`refrigerants.js`), affiché à côté du PRG
  (« 2088 (AR4) ») et dans le tableau. Les 22 valeurs reprises viennent toutes de l'AR4 (colonne
  « GWP (AR4) » de l'ancien site) ; valeurs et statuts restent à vérifier par rapport à l'annexe du
  règlement avant de valider la source dans l'admin (référence de l'outil : « à vérifier »).
  La liste du calculateur propose les 22 fluides du tableau (17 sur l'ancien site).

### C6 — Zéro remplacé par la valeur par défaut — Corrigé (2026-10-23)
- **Anomalie** : « valeur || défaut » : une consigne de 0 °C devenait 2 °C (chambre froide pro) ou
  25 °C (enceinte), une marge de 0 % devenait 15 %.
- **Décision** : champ vide = valeur par défaut, 0 = 0.
- **Correction** : fonction `or()` des deux librairies ; heures d'éclairage et d'activité des portes
  acceptent 0. Cas modifiés : chambre froide pro à 0 °C (frigo 1,18 → 1,34 kW ; l'ancien calcul était
  celui de 2 °C), marge 0 % en simple (5,98 → 4,79 kW) et en pro (2,54 → 2,21 kW), enceinte à 0 °C.

### C7 — Chambre froide simple : portes lues dans le formulaire pro — Corrigé (2026-10-23)
- **Décision** : hypothèses fixes, affichées dans la note du mode simple.
- **Correction** : `SIMPLE_ASSUMPTIONS` (porte battante isotherme 0,9 × 2 m, U = 0,55, 4 joints, sans
  hublot, 15 ouvertures de 25 s par jour sur 8 h d'activité ; atelier : 2 portes de 1 × 2 m,
  40 ouvertures, infiltration + 20 %). Ce sont les valeurs par défaut de l'ancien formulaire pro :
  aucun cas de référence modifié.

### C8 — Enceinte climatique : déshumidification 1000 fois trop forte — Corrigé (2026-10-23)
- **Anomalie** : puissance en W multipliée par 1000 puis affichée en kW (37,36 kW pour 6 m³,
  898 kWh/j) ; eau multipliée par la masse volumique de l'air ; masse affichée comme un débit.
- **Décision** : corriger les unités.
- **Correction** : eau à retirer (g) = Δ humidité absolue (g/m³) × volume ; débit (g/h) sur la durée
  de transition d'humidité ; puissance moyenne = (sensible + eau × L_v) / durée, en W puis kW. Le
  facteur de la méthode s'applique à la puissance, pas à l'eau. Nouveau résultat « Eau à retirer ».
  Exemple (défaut) : 6 m³, 25 °C, 60 → 40 % HR en 30 min : 27,6 g, 55 g/h, 0,08 kW (ancien 37,36 kW) ;
  froid maximal 0,09 kW, consommation 3,0 kWh/j (ancien 897,6).

### C9 — Enceinte climatique : apports internes — Corrigé (2026-10-23)
- **Anomalie** : apports internes ignorés en mode simple ; en pro, ajoutés au besoin de chauffage.
- **Décision** : ajoutés au besoin de froid (simple et pro) ; en chauffage, non déduits par défaut (cas
  le plus défavorable) ; case « Tenir compte des apports internes en chauffage », décochée par défaut.
- **Correction** : `thermalPower()` reçoit les déperditions de l'enveloppe et les apports internes
  (éclairage, personnes, câbles, échantillons) séparément ; le besoin secondaire (15 %) est calculé sur
  la même base. Tous les scénarios de l'enceinte changent (avec C8 et C10).

### C10 — Champs sans effet — Corrigé (2026-10-23)
- **Décision** : brancher l'humidité cible de déshumidification ; abandonner les autres champs.
- **Correction** : « Humidité cible de déshumidification » (40 % par défaut, comme l'ancien formulaire)
  dans les deux modes. Non repris : renouvellement d'air et charge d'échantillon du mode simple, rampes
  de température et d'humidité et mode de fonctionnement du mode pro de l'enceinte, type d'éclairage de
  la chambre froide pro.

### C11 — Pages de l'ancien site interrompues — Corrigé (2026-10-22, nécessaire)
- **Anomalie** : chambre froide sans total ni puissances frigorifique et électrique ; mode pro de
  l'enceinte inopérant (largeur et hauteur des portes absentes du gabarit).
- **Correction** : tout ce que le code calcule est affiché ; champs « Largeur de porte » et « Hauteur
  de porte » (0,8 × 2 m) ajoutés au mode pro de l'enceinte.

### C12 — Chambre froide : produits et congélation — Corrigé (2026-10-23)
- **Anomalie** : la même capacité thermique au-dessus et au-dessous du point de congélation ; en mode
  simple, des produits « frais à +10 °C » entraient dans une chambre à −18 °C.
- **Décision** :
  - congélation sur place (mode pro) : Q = m × [cp_frais × (T_entrée − T_congélation) + L_congélation +
    cp_congelé × (T_congélation − T_finale)], avec L_congélation = teneur en eau × 334 kJ/kg (la
    teneur en eau n'est comptée qu'une fois : viande à 70 % d'eau → L = 233,8 kJ/kg) ;
  - chambre négative en mode simple : produits déjà congelés à l'entrée, à −15 °C par défaut
    (modifiable en mode pro), Q = m × cp_congelé × (T_entrée − T_consigne) ; la congélation sur place
    reste en mode pro ; hypothèse affichée ;
  - durée de refroidissement des produits affichée sous les résultats (charge répartie sur cette
    durée, et non sur 24 h).
- **Correction** (`chambre_froide.js`) : profils sans chaleur latente stockée (teneur en eau seulement,
  `freezingHeat()` = eau × 334) ; `productEnergyPerKg()` (congélation sur place, produit déjà congelé
  à l'entrée — entrée au point de congélation ou dessous —, sinon chaleur sensible) ; `freezesInRoom()`
  (sur demande, entrée au-dessus et consigne au-dessous du point de congélation, produit contenant de
  l'eau) ; `SIMPLE_ASSUMPTIONS.frozenEntryTemp` = −15 °C ; résumé « Produits » sous les résultats (masse,
  température d'entrée, état, kJ/kg, durée) avec un avertissement si des produits frais entrent dans une
  chambre plus froide que leur point de congélation sans congélation demandée (calcul inchangé).
  Champ « Chaleur latente » retiré des formulaires. Points de congélation et cp congelés des profils
  (frais divers −1,5 °C / 1,9 ; viande −1,7 / 1,8 ; poisson −2,2 / 1,9 ; laitiers −0,6 / 1,9 ;
  légumes −0,8 / 2,0 ; boissons −1,0 / 2,0 ; glace 0 / 2,05 ; personnalisé −1,5 / 1,8) : ordres de
  grandeur à vérifier, modifiables en mode pro.
- **Valeurs** :
  - mode simple par défaut (−18 °C, 500 kg/j) : produits 3,30 → 0,07 kW (5,7 kJ/kg sur 12 h),
    puissance frigorifique 5,98 → 1,01 kW, électrique 2,85 → 0,48 kW ;
  - mode pro par défaut (produits frais à +10 °C, sans congélation demandée) : 2,82 kW, inchangé, avec
    l'avertissement ;
  - viande de 8 °C à −18 °C congelée sur place (pro, 500 kg/j, 14 h) : 3,2 × 9,7 + 0,70 × 334 + 1,8 ×
    16,3 = 294,2 kJ/kg (ancien 244,2), charge produits 2,92 kW, puissance frigorifique 5,55 kW.
  Cas modifiés : 11 scénarios.

### Vérification du 2026-10-23 — Chambre froide, mode simple : baisse du total
Pas d'effet de bord : seuls les produits changent (C12). Postes du cas par défaut (4 × 3 × 2,5 m, −18 °C,
500 kg/j, 18 h de marche, marge 15 %), charge moyenne sur 24 h en kW, avant → après : parois et
vitrage 0,40 → 0,40 ; portes (transmission) 0,06 → 0,06 ; infiltration 0,001 → 0,001 ; air 0 → 0 ;
dégivrage 0,06 → 0,06 ; éclairage 0,05 → 0,05 ; équipements 0,02 → 0,02 ; produits 3,30 → 0,07 ;
total 3,90 → 0,66. La puissance à installer = total × 24/18 × 1,15 (= × 1,533) : hors produits
0,60 × 1,533 = 0,92 kW avant comme après ; produits 5,06 → 0,11 kW ; total 5,98 → 1,01 kW. L'écart
« 2,7 kW » venait de 5,98 − 3,30, qui mélange une puissance à installer (avec durée de marche et
marge) et une charge moyenne. Le mode simple ne compte pas de ventilateurs d'évaporateur
(équipements 2 W/m²) et l'infiltration des portes de l'ancienne méthode est très faible (1,2 W) : points
repris dans la refonte « chambre froide v2 ».
Durée de refroidissement : une seule règle dans les deux modes, la valeur du profil produit
(12 h « frais divers », 14 h « viande / volaille »…), modifiable ; la source est affichée (« durée par
défaut du profil … » ou « durée saisie »).

### Non-régression du lot 5c
156 cas de l'ancien site capturés (`golden/thermodynamique.json`, dont 29 appels de la librairie de
psychrométrie) : 66 reçoivent leur nouvelle valeur attendue, avec la source en commentaire
(`thermodynamique.test.js` 16, `psychrometrics.test.js` 7, `chambre_froide.test.js` 17,
`enceinte_climatique.test.js` 26), les 90 autres restent identiques. Les tableaux de la chambre froide
et de l'enceinte sont préparés avec `scripts/calculator/corrections.mjs` (ancienne et nouvelle valeur
de chaque sortie), puis relus.

**Mise à jour du lot 5e (2026-10-23)** : la chambre froide v2 remplace le formulaire de l'ancien site ;
ses 17 scénarios capturés ne sont plus rejoués (champs différents). Sa non-régression porte sur les
méthodes inchangées — transmission des portes, éclairage, produits (C12), renouvellement d'air (C3) — et
sur 3 cas calculés à la main (`docs/CHAMBRE_FROIDE_V2.md`, section 4). Les scénarios restent dans
`golden/thermodynamique.json` comme trace de l'ancien site.

### E1 — Chambre froide v2 : postes manquants ou sous-estimés de l'ancien site — Corrigé (2026-10-23, lot 5e)
- **Anomalie** : infiltration des portes très faible (1,2 W dans le cas par défaut), pas de ventilateurs
  d'évaporateur, de cordons chauffants, de câble antigel, d'évacuation ni de soupape, une seule porte et
  un seul produit, dégivrage sans type, marques de panneaux codées en dur, COP fixe par fluide.
- **Correction** : `chambre_froide.js` réécrit (infiltrations ASHRAE avec l'humidité et le givre, listes de
  portes, de vitrages et de produits, postes ajoutés, respiration et emballages, dégivrage par type,
  besoin en kWh/j et part de chaque poste) ; `fgas.js` (fluides de l'admin, règles F-Gas vérifiées,
  contrôles, charge estimée, NF EN 378-1, COP de Carnot) ; panneaux génériques par λ et modèles des
  marques dans l'admin ; champ « équipements » du mode simple remplacé par les ventilateurs.
- **Valeurs** (cas par défaut, mode simple) : charge 0,66 → 1,14 kW ; puissance à installer 1,01 → 1,67 kW
  (marge 15 → 10 %) ; détail : `docs/CHAMBRE_FROIDE_V2.md`, section 3.

**Mise à jour de l'enceinte climatique v2 (2026-10-23)** : l'enceinte v2 remplace le formulaire de
l'ancien site ; ses 26 scénarios capturés ne sont plus rejoués (champs différents) et restent dans
`golden/thermodynamique.json` comme trace (`scripts/calculator/replay.mjs` et `corrections.mjs`, qui ne
servaient qu'à ce rejeu, sont retirés). Non-régression : méthodes inchangées (C4, C6, C9) et 3 cas de
référence (`docs/ENCEINTE_CLIMATIQUE_V2.md`, section 4).

### E2 — Enceinte climatique v2 : postes manquants ou simplifiés de l'ancien site — Corrigé (2026-10-23)
- **Anomalie** : un seul cas de local (25 °C), pas d'hiver ni d'air neuf humide, infiltration des portes
  forfaitaire, ventilateurs absents, classe de stabilité utilisée comme majoration de la transmission,
  déshumidification par facteurs de méthode, pas de condensation sur la batterie froide, pas de mise en
  température, consommation = somme des puissances × 24 h.
- **Correction** : `enceinte_climatique.js` réécrit (cas été et hiver, infiltrations ASHRAE avec
  l'humidité, ventilateurs selon le type d'enceinte, échantillons et apports manuels, condensation sur la
  batterie et chaleur sensible de la vapeur par point fixe, séchage par batterie ou dessiccateur, mises en
  régime de température et d'humidité, régulation « froid permanent + réchauffage » comparée à la
  modulation, consommation selon la durée des apports, fluide et contrôles de la chambre froide) ; profils
  de consigne dans l'admin (`ClimaticProfile`).
- **Valeurs** (cas par défaut, mode simple, ICH long terme) : froid 0,09 → 0,32 kW, chauffe 0,03 → 0,05 kW ;
  détail et 3 cas : `docs/ENCEINTE_CLIMATIQUE_V2.md`.

---

## Éclairage et Terrariophilie (lot 5d)

Graphiques : l'éclairage horticole de l'ancien site utilisait ApexCharts pour un spectre indicatif ; le
nouveau site le dessine en SVG (`assets/lib/spectrum_chart.js`), sans nouvelle dépendance, avec une
info-bulle et un tableau des valeurs. Le modèle de spectre est inchangé (`spectrum()`).

### A3 étendue — Corrigé (2026-10-22)
Cas de référence modifiés (29) :
- DMX (7) : adresses 513, 0, −5 et vide ; interrupteurs donnant 0, 513 ou 1023. L'ancien site ramenait
  513 à 512 et affichait « 0 », avec des interrupteurs incohérents.
- Vieillissement UV (4) : irradiance négative ou nulle, phase UV nulle, condensation négative.
- Éclairage horticole (5) : photopériode de 30 h (ramenée à 24 h par l'ancien site), surface négative
  (ramenée à 0), PPE nul (remplacé par 2,5), nombre de LED négatif, 30 h par jour dans le calcul du DLI.
- Terrariophilie (13) : dimensions vides, nulles ou négatives, nombre de buses négatif ou décimal,
  débit vide, volume nul ou négatif, PPFD négatif ou vide, plus de 24 h par jour.

### D1 — DMX : interrupteurs non synchronisés au chargement — Corrigé (2026-10-23, nécessaire)
- **Anomalie** : l'ancien contrôleur appelait au chargement une fonction qui n'existait pas
  (`updateFromAddress`) : la page affichait l'adresse 1 avec tous les interrupteurs sur OFF. Les
  boutons + et − aux bornes (512, 1) laissaient le détail du calcul vide.
- **Correction** : interrupteurs et détail synchronisés dès le chargement et après chaque bouton.
  Cas modifiés (2) : + à 512 et − à 1 (l'adresse reste affichée avec ses interrupteurs).

### D2 — DMX : texte sur l'interrupteur n° 10 — Corrigé (2026-10-23, texte)
- **Anomalie** : « le 10e interrupteur n'est généralement pas utilisé pour l'adresse (qui s'arrête à
  512) », alors que l'adresse 512 s'obtient avec lui seul.
- **Correction** : « Sa valeur (512) ne sert qu'à l'adresse 512, la dernière d'un univers DMX » ; la
  suite du texte (mode autonome, résistance de fin de ligne) est conservée.

### D3 — Vieillissement UV : unité de l'irradiance — Corrigé (2026-10-23)
- **Anomalie** : les valeurs citées (0,89 W/m² pour UVA-340, 0,71 W/m² pour UVB-313) sont, dans
  l'ASTM G154, des irradiances **spectrales** à une longueur d'onde (W/(m²·nm) à 340 nm et à 310 nm).
  La dose calculée était en kJ/(m²·nm), affichée en kJ/m².
- **Décision** : unités spectrales affichées, irradiance en W/(m²·nm) et dose en kJ/(m²·nm) à 340 nm
  (UVA-340) ou 310 nm (UVB-313). Mode large bande plus tard.
- **Correction** : choix de la lampe de référence (`UV_LAMPS` dans `eclairage.js`) qui remplit
  l'irradiance courante et affiche la longueur d'onde de contrôle ; unités des champs, des résultats et
  des formules. Calculs inchangés : aucun cas de référence modifié.

### D4 — Éclairage horticole : UV et far-red comptés dans le PPF — Corrigé (2026-10-23)
- **Anomalie** : le PPF est défini sur le PAR (400–700 nm, ANSI/ASABE S640), mais l'architecte de
  spectre et le simulateur y ajoutaient les bandes UV et far-red.
- **Décision** : PPF et PPFD sur 400–700 nm uniquement ; far-red (700–750 nm) affiché à part, avec
  l'ePPF (400–750 nm) en option ; UV affichés à part, jamais dans le PPF ; DLI calculé sur le PPFD.
- **Correction** (`eclairage.js`, page) : bandes rangées par domaine (`BANDS[].region`, `PAR_BANDS`) ;
  architecte : 5 curseurs PAR de somme 100 %, UV et far-red saisis en % du PPF, résultats PPF,
  far-red, UV et ePPF (case « Afficher l'ePPF ») ; simulateur : PPF des seules LED PAR × PPE moyen,
  flux UV et far-red à part (efficacités indicatives par bande) ; zone PAR repérée sur le spectre.
  Répartition par défaut : parts PAR de l'ancien site ramenées à 100 % (17/17/11/16/39 %), far-red
  11 % en plus, sans UV (mêmes proportions de photons) ; 800 µmol/s de PAR → 133 LED, ePPF 888 µmol/s.
- **Non-régression** : chaque ancien scénario, relu avec le PPF limité au PAR (parts PAR ramenées à
  100 %, UV et far-red en % du PPF), redonne exactement les mêmes nombres de LED. Cas modifiés (2) :
  parts bleu 20, blanc froid 20, vert 10, blanc chaud 15, rouge 35 et far-red 10 (somme des 7 parts
  110 %, refusée par l'ancien site) → répartition valide, 132 LED, 289 W, ePPF 880 µmol/s ; simulateur
  avec 4 LED UV et 6 far-red → PPF 924 µmol/s au lieu de 1008, UV 24 et far-red 39,6 µmol/s à part.

### D5 — Chauffage de terrarium : règle empirique au volume — Corrigé (2026-10-23)
- **Anomalie** : P = volume (L) × ΔT × facteur (verre 0,045 ; bois 0,025), alors que les pertes
  dépendent de la surface des parois et de leur isolation.
- **Décision** : P = Σ(U × S) × ΔT + 0,34 × débit d'air (m³/h) × ΔT, avec un coefficient de sécurité ;
  matériau par paroi, U par défaut affichés et modifiables, calculés par U = 1 / (0,17 + e/λ) ; ancienne
  règle gardée comme « estimation rapide (empirique) » ; rappel « thermostat obligatoire ».
- **Correction** (`terrariophilie.js`, page) : `heatingByWalls()`, `wallU()`, `WALL_MATERIALS`
  (verre 4 mm λ 1,0 → U 5,75 ; OSB 18 mm λ 0,13 → 3,24 ; PVC expansé 10 mm λ 0,06 → 2,97 ; XPS 20 mm
  λ 0,034 → 1,32 W/(m²·K)) ; 6 parois (façade, fond, côtés, dessus, dessous) ; débit = volume ×
  renouvellements par heure (1 par défaut) ; sécurité 1,2 par défaut (à vérifier) ; le point chaud
  visé sert de température intérieure (prudent) ; arrondi aux 25 W supérieurs.
- **Cas calculé à la main** (test) : 90 × 45 × 60 cm tout verre, 20 → 35 °C : Σ(U × S) = 5,747 × 2,43 =
  13,97 W/K ; parois 209,5 W ; air 0,34 × 0,243 × 15 = 1,24 W ; × 1,2 = 252,9 W → **275 W**
  (estimation rapide empirique : 175 W, cas de référence de l'ancien site inchangés).

### D6 — Éclairage des reptiles : distances des tubes T5 — À vérifier (données)
- **Constat** : distances indicatives par zone de Ferguson pour des tubes T5 6 %, 12 % et 14 % avec
  réflecteur ; elles dépendent du modèle de lampe, de son âge et du réflecteur. À vérifier avant de
  valider la source dans l'admin. La mention de marque « SolarMeter » devient « radiomètre UV (type
  Solarmeter) ».

### D7 — Bassin : poids de l'eau seule — Conservé
- **Constat** : le poids affiché est celui de l'eau (1 kg/L), sans le bac, le décor ni le substrat :
  précisé sous le résultat.

### Non-régression du lot 5d
103 cas de l'ancien site capturés (`golden/eclairage.json` 59, `terrariophilie.json` 44) : 34
reçoivent leur nouvelle valeur attendue (A3 : 30 ; D1 : 2 ; D4 : 2, dont la somme des parts à 110 %
qui relevait d'A3), les 69 autres restent identiques ; les nombres de LED des autres scénarios de
l'architecte sont retrouvés en relisant l'ancien PPF selon D4. Le banc de capture accepte désormais des éléments répétés
(10 interrupteurs DIP), la lecture d'un attribut de chacun et le remplacement d'une librairie (ApexCharts)
par un objet vide.
