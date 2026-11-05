/*
 * Contrôleur Stimulus « calculator--chambre-froide » : bilan frigorifique v2 (lot 5e), modes simple
 * et pro. Lit les champs du mode affiché (data-field) et les éléments des listes du mode pro (portes,
 * vitrages, produits : data-item-field), applique les valeurs par défaut quand un choix change (profil
 * produit, type de porte, protection, vitrage, dégivrage, régime), puis affiche les puissances, le
 * détail poste par poste et le rapport sur le fluide (base de l'admin, règles F-Gas vérifiées), le résumé
 * collant (froid à installer, compresseur, consommation) et les alertes en encadrés. Navigation et barre
 * mobile : calculator--layout.
 *
 * Utilisé par : templates/calculator/tools/thermodynamique/chambre-froide.html.twig
 * Utilise : assets/calculators/chambre_froide.js, fgas.js, fluid_report.js, refrigerants.js, format.js, assets/lib/callout.js
 */
import { Controller } from "@hotwired/stimulus";
import {
    coldRoomPro, coldRoomSimple, DECIMALS, DEFROST_TYPES, DOOR_HEATER, DOOR_PROTECTIONS, DOOR_TYPES, explain, FAN_W_PER_M3, GLAZING_TYPES,
    panelU, PRODUCT_PROFILES, REGIME_PRESETS, respirationRate, SUGGESTED_RUN_HOURS,
} from "../../calculators/chambre_froide.js";
import { applicableRules, isBanned, proposedRefrigerants, ruleText } from "../../calculators/fgas.js";
import { fluidReport } from "../../calculators/fluid_report.js";
import { REFRIGERANTS, refrigerantsFromRows } from "../../calculators/refrigerants.js";
import { formatNumber, parseNumber, showResult } from "../../calculators/format.js";
import { createCallout } from "../../lib/callout.js";

/** Groupes des profils produits. */
const PROFILE_GROUPS = { alimentaire: "Alimentaire", pharma: "Pharmaceutique et santé", industrie: "Industrie et chimie", inerte: "Matières inertes", autre: "Autres" };

/** Fluide choisi par défaut si aucun n'est coché « Fluide par défaut » dans l'admin (décision du 2026-10-23). */
const DEFAULT_FLUID = "R290";

/** Fluide dont le type d'installation par défaut est le groupe autonome (charge réduite dans le local). */
const R290_FLUID = "R290";

/** Libellés des éléments des listes. */
const ITEM_TITLES = { doors: "Porte", glazings: "Vitrage", products: "Produit" };

/** Listes dont la valeur est un nombre. */
const NUMERIC_SELECTS = new Set(["thickness", "leaves"]);

/** Valeur saisie sans zéros inutiles (« 0,16 », « 250 »). */
const plain = (value) => new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 4, useGrouping: false }).format(value);

/** Nombre affiché. */
const n = (value, digits) => formatNumber(value, digits);

/** Hypothèses d'un produit : état à l'entrée, chaleur par kg, durée de refroidissement. */
function productText(p, index, count) {
    const name = count > 1 ? `Produit ${index + 1}` : "Produits";
    if (p.dailyKg <= 0) {
        return `${name} : aucune entrée journalière.`;
    }
    const state = p.freezing ? `congelés sur place (chaleur latente ${n(p.latentHeat, 1)} kJ/kg)` : p.frozenAtEntry ? "déjà congelés à l'entrée" : "non congelés";
    const source = p.pullDown === p.profilePullDown ? `durée par défaut du profil « ${p.profileLabel} »` : "durée saisie";
    const warning = p.shouldFreeze ? " Attention : ces produits entrent au-dessus de leur point de congélation dans une chambre plus froide ; choisissez « Congélation sur place : Oui » pour compter leur congélation." : "";

    return `${name} : ${n(p.dailyKg, 0)} kg/j entrant à ${plain(p.entryTemp)} °C, ${state}, ${n(p.energyPerKg, 1)} kJ/kg à retirer ; charge répartie sur ${plain(p.pullDown)} h de refroidissement (${source}, et non sur 24 h).${warning}`;
}

export default class extends Controller {
    static values = { refrigerants: Array, rules: Array, panels: Array, today: String };
    static targets = [
        "modeButton", "form", "frigoKw", "kwhDay", "totalKw", "compressorKw", "cop", "consumption", "frost", "volume", "message", "summary", "productSummary",
        "breakdown", "fluidReport", "summaryFrigo", "summaryCompressor", "summaryConsumption", "summaryAlerts", "alertCount", "doorList", "glazingList", "productList", "doorTemplate", "glazingTemplate", "productTemplate",
    ];

    /** Prépare les listes (fluides, profils, éléments du mode pro), puis calcule. */
    connect() {
        this.mode = "simple";
        this.nextId = 1;
        this.refrigerants = this.refrigerantsValue.length > 0 ? refrigerantsFromRows(this.refrigerantsValue) : REFRIGERANTS;
        this.panels = Object.fromEntries(this.panelsValue.map((panel) => [String(panel.id), panel]));
        for (const form of this.formTargets) {
            this.fillFluids(form.querySelector("[data-fluid-select]"));
            this.applyInstallationDefault(form);
            this.updatePanel(form);
            this.updateRunHint(form);
        }
        const simple = this.formFor("simple");
        this.fillProfiles(simple.querySelector("[data-product-profile]"), "fresh_generic");
        this.applyProfileTo(simple, "fresh_generic");
        this.appendItem("doors", false);
        this.appendItem("products", false);
        this.updateEmptyNotes();
        this.calculate();
    }

    /** Change de mode (boutons Simple / Pro). */
    setMode(event) {
        this.mode = event.currentTarget.dataset.mode;
        for (const button of this.modeButtonTargets) {
            button.setAttribute("aria-pressed", String(button.dataset.mode === this.mode));
        }
        for (const form of this.formTargets) {
            form.hidden = form.dataset.mode !== this.mode;
        }
        this.calculate();
    }

    /** Ajoute un élément à une liste (bouton « Ajouter ») et place le curseur dans son premier champ. */
    addItem(event) {
        const item = this.appendItem(event.currentTarget.dataset.listName, true);
        item.querySelector("input, select")?.focus();
        this.updateEmptyNotes();
        this.calculate();
    }

    /** Supprime un élément d'une liste après confirmation, puis rend la main au bouton « Ajouter » de la liste. */
    removeItem(event) {
        if (!window.confirm(event.currentTarget.dataset.confirm ?? "Supprimer cet élément ?")) {
            return;
        }
        const item = event.currentTarget.closest("[data-item]");
        const list = item.parentElement;
        item.remove();
        this.renumber(list);
        this.element.querySelector(`[data-list-name="${list.dataset.list}"]`)?.focus();
        this.updateEmptyNotes();
        this.calculate();
    }

    /** Applique le profil produit choisi (mode simple ou produit du mode pro). */
    applyProfile(event) {
        this.applyProfileTo(event.target.closest("[data-item]") ?? event.target.closest("[data-mode]"), event.target.value);
        this.calculate();
    }

    /** Panneau ou épaisseur changés : champs utiles et coefficient U affiché. */
    changePanel(event) {
        this.updatePanel(event.target.closest("[data-mode]"));
        this.calculate();
    }

    /** Régime du mode simple changé : suggestion du temps de fonctionnement. */
    changeRegime(event) {
        this.updateRunHint(event.target.closest("[data-mode]"));
        this.calculate();
    }

    /** Consigne du mode pro changée : équipements propres au négatif, cordons, temps de fonctionnement suggéré. */
    changeTemperature(event) {
        const form = event.target.closest("[data-mode]");
        const negative = parseNumber(event.target.value) < 0;
        if (negative !== this.proNegative) {
            this.proNegative = negative;
            for (const name of ["antiFreeze", "drain", "reliefValve"]) {
                this.field(form, name).value = negative ? "yes" : "no";
            }
            for (const heater of this.doorListTarget.querySelectorAll('[data-item-field="heaterWPerM"]')) {
                heater.value = plain(negative ? DOOR_HEATER.wPerM : 0);
            }
            for (const item of this.productListTarget.querySelectorAll("[data-item]")) {
                this.itemField(item, "respiration").value = plain(negative ? 0 : respirationRate(this.itemField(item, "profile").value, parseNumber(event.target.value)));
            }
        }
        this.updateRunHint(form);
        this.calculate();
    }

    /** Type de porte changé : coefficient U du type. */
    changeDoorType(event) {
        this.itemField(event.target.closest("[data-item]"), "u").value = plain((DOOR_TYPES[event.target.value] ?? DOOR_TYPES.swing).u);
        this.calculate();
    }

    /** Protection de porte changée : efficacité par défaut et note. */
    changeProtection(event) {
        const item = event.target.closest("[data-item]");
        this.itemField(item, "efficiency").value = plain((DOOR_PROTECTIONS[event.target.value] ?? DOOR_PROTECTIONS.none).efficiency * 100);
        this.updateProtectionNote(item);
        this.calculate();
    }

    /** Type de vitrage changé : coefficient U du type. */
    changeGlazingType(event) {
        this.itemField(event.target.closest("[data-item]"), "u").value = plain((GLAZING_TYPES[event.target.value] ?? GLAZING_TYPES.double).u);
        this.calculate();
    }

    /** Type d'installation changé : « hermétiquement scellé » coché par défaut pour un groupe autonome (modifiable). */
    changeInstallation(event) {
        const form = event.target.closest("[data-mode]");
        event.target.dataset.edited = "true";
        this.field(form, "hermetic").value = event.target.value === "self_contained" ? "yes" : "no";
        this.calculate();
    }

    /** Fluide changé : type d'installation par défaut du fluide (tant que le type n'a pas été choisi à la main). */
    changeFluid(event) {
        this.applyInstallationDefault(event.target.closest("[data-mode]"));
        this.calculate();
    }

    /**
     * R-290 : groupe autonome (monobloc) par défaut, « hermétiquement scellé » coché, pour limiter la charge
     * dans le local (décision du 2026-10-23) ; un split reste possible, avec l'avertissement NF EN 378-1.
     */
    applyInstallationDefault(form) {
        const installation = this.field(form, "installation");
        if (installation.dataset.edited === "true") {
            return;
        }
        const type = this.field(form, "fluid").value === R290_FLUID ? "self_contained" : "split";
        installation.value = type;
        this.field(form, "hermetic").value = type === "self_contained" ? "yes" : "no";
    }

    /** Type de dégivrage changé : fraction réintroduite par défaut ; aucune puissance pour l'air. */
    changeDefrost(event) {
        const form = event.target.closest("[data-mode]");
        this.field(form, "defrostFraction").value = plain((DEFROST_TYPES[event.target.value] ?? DEFROST_TYPES.electric).fraction);
        this.field(form, "defrostKw").readOnly = event.target.value === "air";
        this.calculate();
    }

    /** Retient qu'un champ a été modifié à la main (le temps de fonctionnement suggéré ne l'écrase plus). */
    markEdited(event) {
        event.target.dataset.edited = "true";
    }

    /** Recalcule le bilan du mode affiché. */
    calculate() {
        const form = this.formFor(this.mode);
        if (this.mode === "simple") {
            // Chambre négative : produits déjà congelés à −15 °C, la température d'entrée saisie n'est pas utilisée
            this.field(form, "entryTemp").readOnly = (REGIME_PRESETS[this.field(form, "regime").value]?.tSet ?? 0) < 0;
        }
        const input = this.mode === "simple" ? this.readSimple(form) : this.readPro(form);
        const result = this.mode === "simple" ? coldRoomSimple(input) : coldRoomPro(input);
        const error = explain(this.mode, input);

        showResult(this.frigoKwTarget, result?.frigoKw ?? null, DECIMALS.kw, this.messageTarget, error);
        showResult(this.kwhDayTarget, result?.kwhDay ?? null, DECIMALS.kwh);
        showResult(this.totalKwTarget, result ? result.totalW / 1000 : null, DECIMALS.kw);
        showResult(this.compressorKwTarget, result?.compressorKw ?? null, DECIMALS.kw);
        showResult(this.copTarget, result?.cop ?? null, DECIMALS.cop);
        showResult(this.consumptionTarget, result?.totalElectricKwhDay ?? null, DECIMALS.kwh);
        showResult(this.summaryFrigoTarget, result?.frigoKw ?? null, DECIMALS.kw);
        showResult(this.summaryCompressorTarget, result?.compressorKw ?? null, DECIMALS.kw);
        showResult(this.summaryConsumptionTarget, result?.totalElectricKwhDay ?? null, DECIMALS.kwh);
        showResult(this.frostTarget, result?.waterKgDay ?? null, DECIMALS.kg);
        showResult(this.volumeTarget, result?.geo.volume ?? null, DECIMALS.volume);
        this.summaryTarget.textContent = result
            ? `Surface intérieure ${n(result.geo.surface, DECIMALS.surface)} m², panneaux U = ${n(result.u, 3)} W/(m²·K) · ${plain(result.runHours)} h de marche par jour, marge ${plain(result.margin)} % · ${result.tSet < 0 ? "givre" : "condensats"} ${n(result.waterKgDay, 1)} kg/j · électricité : compresseur ${n(result.compressorKwhDay, 1)} kWh/j et résistances, éclairage, ventilateurs ${n(result.electricKwhDay, 1)} kWh/j.`
            : "";
        this.productSummaryTarget.textContent = result ? result.products.map((p, i) => productText(p, i, result.products.length)).join(" ") : "";
        this.renderBreakdown(result);
        this.renderAlerts(this.renderFluid(form, result), result);
        if (this.mode === "pro") {
            this.updateFansHint(form, result);
        }
    }

    /** Saisies du mode simple. */
    readSimple(form) {
        const v = this.values(form);

        return {
            length: v.length, width: v.width, height: v.height, regime: v.regime, usage: v.usage, doorProtection: v.doorProtection,
            panel: this.readPanel(form), floorInsulated: v.floorInsulated, glazing: v.glazing, glazingArea: v.glazingArea, loadMode: v.loadMode,
            product: { profile: v.productProfile, dailyKg: v.dailyKg, entryTemp: v.entryTemp, cp: v.productCp, waterPct: v.productWaterPct, pullDown: v.pullDownHours },
            airRenewal: v.airRenewal, airRhExt: v.airRhExt, runHours: v.runHours, margin: v.margin,
            refrigerant: { fluid: this.refrigerants[v.fluid] ?? null, fluidCode: v.fluid, installation: v.installation, hermetic: v.hermetic === "yes", rules: this.rulesValue, today: this.todayValue },
        };
    }

    /** Saisies du mode pro. */
    readPro(form) {
        const v = this.values(form);
        const items = (list) => [...list.querySelectorAll("[data-item]")].map((item) => this.values(item, "data-item-field"));

        return {
            mode: "pro", length: v.length, width: v.width, height: v.height, tSet: v.tSet, rhSet: v.rhSet,
            tExtWall: v.tExtWall, tExtCeiling: v.tExtCeiling, tExtFloor: v.tExtFloor, bridgePct: v.bridgePct, floorInsulated: v.floorInsulated === "yes",
            panel: this.readPanel(form),
            antiFreeze: { enabled: v.antiFreeze === "yes", wPerM2: v.antiFreezeWPerM2, fraction: v.antiFreezeFraction, dutyPct: v.antiFreezeDutyPct },
            // Efficacité des protections saisie en %
            doors: items(this.doorListTarget).map((d) => ({ ...d, efficiency: d.efficiency / 100, heaterFraction: DOOR_HEATER.fraction })),
            glazings: items(this.glazingListTarget).map((g) => ({ area: g.area, u: g.u, localTemp: g.localTemp, heated: g.heated === "yes", heaterW: g.heaterW, heaterFraction: 0.7 })),
            lighting: { watts: v.lightingW, hours: v.lightHours }, people: { count: v.people, hours: v.peopleHours },
            fans: { mode: v.fansMode, value: v.fansValue }, otherW: v.otherW,
            drain: { enabled: v.drain === "yes", lengthM: v.drainLength, wPerM: v.drainWPerM, fraction: 0.1, dripMinutes: v.dripMinutes },
            reliefValve: { enabled: v.reliefValve === "yes", watts: v.reliefValveW, fraction: 1 },
            products: items(this.productListTarget).map((p) => ({ ...p, freezing: p.freezing === "yes" })),
            airRenewal: v.airRenewal, airTExt: v.airTExt, airRhExt: v.airRhExt,
            defrost: { type: v.defrostType, powerMode: "kw", powerKw: v.defrostKw, cycles: v.defrostCycles, minutes: v.defrostMin, fraction: v.defrostFraction },
            runHours: v.runHours, margin: v.margin,
            refrigerant: {
                fluid: this.refrigerants[v.fluid] ?? null, fluidCode: v.fluid, installation: v.installation, hermetic: v.hermetic === "yes", lineLength: v.lineLength, condenserAir: v.condenserAir,
                detection: v.detection === "yes", copOverride: v.copOverride, chargeRatio: v.chargeRatio, rules: this.rulesValue, today: this.todayValue,
            },
        };
    }

    /** Panneau choisi : générique (λ de l'isolant), modèle de l'admin, ou personnalisé. */
    readPanel(form) {
        const v = this.values(form);
        const [source, key] = v.panel.split(":");
        if (source === "generic") {
            return { source, insulation: key, thickness: v.thickness };
        }
        if (source === "model") {
            return { source, u: this.panels[key]?.u ?? NaN };
        }

        return source === "custom_lambda" ? { source, lambda: v.lambda, thickness: v.customThickness } : { source, u: v.customU };
    }

    /** Valeurs d'un formulaire ou d'un élément : nombres (NaN si vide) et valeurs des listes. */
    values(root, attribute = "data-field") {
        const values = {};
        for (const element of root.querySelectorAll(`[${attribute}]`)) {
            if (attribute === "data-field" && element.closest("[data-item]")) {
                continue;
            }
            const name = element.getAttribute(attribute);
            values[name] = element.tagName === "SELECT" && !NUMERIC_SELECTS.has(name) ? element.value : parseNumber(element.value);
        }

        return values;
    }

    /** Formulaire d'un mode. */
    formFor(mode) {
        return this.formTargets.find((element) => element.dataset.mode === mode);
    }

    /** Champ d'un formulaire par son nom. */
    field(form, name) {
        return form.querySelector(`[data-field="${name}"]`);
    }

    /** Champ d'un élément de liste par son nom. */
    itemField(item, name) {
        return item.querySelector(`[data-item-field="${name}"]`);
    }

    /** Crée un élément d'une liste depuis son modèle (identifiants uniques), avec les valeurs par défaut. */
    appendItem(listName, userAdded) {
        const [list, template] = {
            doors: [this.doorListTarget, this.doorTemplateTarget],
            glazings: [this.glazingListTarget, this.glazingTemplateTarget],
            products: [this.productListTarget, this.productTemplateTarget],
        }[listName];
        const id = this.nextId++;
        list.append(document.createRange().createContextualFragment(template.innerHTML.replaceAll("__ID__", String(id))));
        const item = list.lastElementChild;
        const tSet = parseNumber(this.field(this.formFor("pro"), "tSet").value);
        if (listName === "doors") {
            this.itemField(item, "heaterWPerM").value = plain(tSet < 0 ? DOOR_HEATER.wPerM : 0);
            this.updateProtectionNote(item);
        } else if (listName === "products") {
            this.fillProfiles(this.itemField(item, "profile"), "fresh_generic");
            this.applyProfileTo(item, "fresh_generic");
        }
        this.proNegative = tSet < 0;
        this.renumber(list);

        return userAdded ? item : list.lastElementChild;
    }

    /** Numérote les éléments d'une liste (« Porte 1 », « Porte 2 »…). */
    renumber(list) {
        const title = ITEM_TITLES[list.dataset.list];
        [...list.querySelectorAll("[data-item-title]")].forEach((legend, index) => {
            legend.textContent = `${title} ${index + 1}`;
        });
    }

    /** Affiche « Aucun vitrage » quand la liste est vide. */
    updateEmptyNotes() {
        for (const note of this.element.querySelectorAll("[data-empty-note]")) {
            note.hidden = this.element.querySelector(`[data-list="${note.dataset.emptyNote}"] [data-item]`) !== null;
        }
    }

    /** Note de la protection (lanières : 0,85 à 0,95 si neuf et bien entretenu). */
    updateProtectionNote(item) {
        const protection = DOOR_PROTECTIONS[this.itemField(item, "protection").value] ?? DOOR_PROTECTIONS.none;
        item.querySelector("[data-protection-note]").textContent = protection.note ? `Efficacité : ${plain(protection.efficiency * 100)} % par défaut, ${protection.note.replace("0,85 à 0,95", "85 à 95 %")}.` : "";
    }

    /** Remplit une liste de profils produits, par groupe. */
    fillProfiles(select, selected) {
        select.replaceChildren(...Object.entries(PROFILE_GROUPS).map(([group, label]) => {
            const optgroup = document.createElement("optgroup");
            optgroup.label = label;
            optgroup.append(...Object.entries(PRODUCT_PROFILES).filter(([, profile]) => profile.group === group).map(([key, profile]) => new Option(profile.label, key, false, key === selected)));

            return optgroup;
        }));
    }

    /** Recopie les propriétés d'un profil (mode simple : noms data-field ; mode pro : noms data-item-field). */
    applyProfileTo(root, key) {
        const profile = PRODUCT_PROFILES[key] ?? PRODUCT_PROFILES.fresh_generic;
        const item = root.hasAttribute("data-item");
        const set = (name, value) => {
            const element = item ? this.itemField(root, name) : this.field(root, name);
            if (element) {
                element.value = value === null ? "" : plain(value);
            }
        };
        if (item) {
            const tSet = parseNumber(this.field(this.formFor("pro"), "tSet").value);
            set("cp", profile.cp);
            set("waterPct", profile.waterPct);
            set("entryTemp", profile.entryTemp);
            set("pullDown", profile.pullDown);
            set("freezePoint", profile.freezePoint);
            set("cpFrozen", profile.cpFrozen);
            set("respiration", tSet >= 0 ? respirationRate(key, tSet) : 0);
            this.itemField(root, "freezing").value = key === "ice" ? "yes" : "no";
        } else {
            set("productCp", profile.cp);
            set("productWaterPct", profile.waterPct);
            set("entryTemp", profile.entryTemp);
            set("pullDownHours", profile.pullDown);
        }
        root.querySelector("[data-product-hint]").textContent = profile.hint;
    }

    /** Champs utiles du panneau choisi et coefficient U. */
    updatePanel(form) {
        const source = this.field(form, "panel").value.split(":")[0];
        for (const element of form.querySelectorAll("[data-panel-generic]")) {
            element.closest("div").hidden = source !== "generic";
        }
        for (const element of form.querySelectorAll("[data-panel-custom-lambda]")) {
            element.hidden = source !== "custom_lambda";
        }
        for (const element of form.querySelectorAll("[data-panel-custom-u]")) {
            element.hidden = source !== "custom_u";
        }
        const panel = this.readPanel(form);
        const info = form.querySelector("[data-panel-info]");
        const u = panelU(panel);
        const origin = source === "model" ? ` — fiche : ${this.panels[this.field(form, "panel").value.split(":")[1]]?.source ?? "?"}` : source === "generic" ? " (panneau générique, λ de l'isolant ; à vérifier sur la fiche du fabricant)" : "";
        info.textContent = Number.isFinite(u) ? `U = ${n(u, 3)} W/(m²·K)${origin}` : "";
    }

    /** Suggestion du temps de fonctionnement (16 h/j en positif, 18 h/j en négatif), appliquée tant que le champ n'a pas été modifié. */
    updateRunHint(form) {
        const tSet = form.dataset.mode === "simple" ? REGIME_PRESETS[this.field(form, "regime").value]?.tSet ?? 2 : parseNumber(this.field(form, "tSet").value);
        const suggested = tSet < 0 ? SUGGESTED_RUN_HOURS.negative : SUGGESTED_RUN_HOURS.positive;
        const run = form.querySelector("[data-run-hours]");
        if (run.dataset.edited !== "true") {
            run.value = plain(suggested);
        }
        form.querySelector("[data-run-hint]").textContent = `Temps de fonctionnement suggéré : ${suggested} h/j (${SUGGESTED_RUN_HOURS.positive} h/j en positif, ${SUGGESTED_RUN_HOURS.negative} h/j en négatif), le reste du temps étant réservé au dégivrage et à la régulation.`;
    }

    /** Suggestion de la puissance des ventilateurs (mode pro). */
    updateFansHint(form, result) {
        const volume = result?.geo.volume ?? parseNumber(this.field(form, "length").value) * parseNumber(this.field(form, "width").value) * parseNumber(this.field(form, "height").value);
        form.querySelector("[data-fans-hint]").textContent = Number.isFinite(volume)
            ? `Ventilateurs : saisissez la puissance de l'évaporateur choisi (W), ou un ratio en W par kW frigorifique. Repère du mode simple : ${plain(FAN_W_PER_M3)} W/m³ × ${n(volume, 1)} m³ = ${n(FAN_W_PER_M3 * volume, 0)} W.`
            : "";
    }

    /** Liste des fluides de l'admin : PRG < 150, puis 150 à 2 500 (aucun fluide de PRG ≥ 2 500) ; présélection : fluide par défaut de l'admin. */
    fillFluids(select) {
        const { low, medium } = proposedRefrigerants(this.refrigerants);
        const usable = [...low, ...medium];
        const fallback = (usable.find(([, fluid]) => fluid.default) ?? usable.find(([code]) => code === DEFAULT_FLUID) ?? usable[0])?.[0];
        select.replaceChildren(...[["PRG inférieur à 150", low], ["PRG de 150 à 2 500", medium]].filter(([, fluids]) => fluids.length > 0).map(([label, fluids]) => {
            const optgroup = document.createElement("optgroup");
            optgroup.label = label;
            optgroup.append(...fluids.map(([code, fluid]) => {
                const option = new Option(`${fluid.name} (PRG ${fluid.gwp}, ${fluid.class})`, code, false, code === fallback);
                option.dataset.label = option.text;

                return option;
            }));

            return optgroup;
        }));
    }

    /** Grise les fluides interdits pour cette installation (règles vérifiées) et signale les interdictions à venir. */
    updateFluidOptions(select, context) {
        for (const option of select.options) {
            const fluid = this.refrigerants[option.value];
            const items = applicableRules(fluid, this.rulesValue, context).filter(({ rule }) => rule.kind === "placing");
            const banned = isBanned(fluid, this.rulesValue, context);
            const upcoming = items.find(({ inForce }) => !inForce);
            option.disabled = banned && !option.selected;
            option.text = banned ? `${option.dataset.label} — interdit (${items.find(({ inForce }) => inForce).rule.code})` : upcoming ? `${option.dataset.label} — interdit à partir de ${upcoming.rule.since.slice(0, 4)}` : option.dataset.label;
            option.title = items.map(ruleText).join(" ");
        }
    }

    /** Tableau poste par poste : libellé, formule et hypothèses chiffrées, charge, besoin, part ; puis le total. */
    renderBreakdown(result) {
        if (result === null) {
            this.breakdownTarget.replaceChildren();
            return;
        }
        const row = (label, texts, kw, kwh, share, strong = false) => {
            const tr = document.createElement("tr");
            const th = document.createElement("th");
            th.scope = "row";
            th.className = `py-2 pr-3 text-left align-top text-xs sm:text-sm ${strong ? "font-bold" : "font-semibold"} text-secondary-900 dark:text-white`;
            th.textContent = label;
            for (const text of texts) {
                const small = document.createElement("span");
                small.className = "mt-0.5 block text-xs font-normal text-secondary-600 dark:text-secondary-300";
                small.textContent = text;
                th.append(small);
            }
            tr.append(th);
            for (const [index, text] of [`${n(kw, DECIMALS.kw)} kW`, `${n(kwh, DECIMALS.kwh)} kWh/j`, share].entries()) {
                const td = document.createElement("td");
                // Part : masquée sur mobile, faute de place
                td.className = `py-2 pl-2 text-right align-top text-xs tabular-nums sm:font-mono sm:text-sm ${index === 2 ? "hidden sm:table-cell" : ""} ${strong ? "font-bold" : ""}`;
                td.textContent = text;
                tr.append(td);
            }

            return tr;
        };
        this.breakdownTarget.replaceChildren(
            ...result.lines.map((l) => row(l.label, [l.formula, l.detail], l.watts / 1000, l.kwhDay, `${n(l.share * 100, DECIMALS.percent)} %`)),
            row("Total", [], result.totalW / 1000, result.kwhDay, "100 %", true),
        );
    }

    /**
     * Alertes du résumé collant, en version courte avec un lien vers le texte complet (rapport sur le fluide et
     * résumé des produits, section 7) ; compteur de la barre mobile.
     *
     * @param {string|null} chargeWarning avertissement de renderFluid()   @param {object|null} result bilan
     */
    renderAlerts(chargeWarning, result) {
        const alerts = [
            ...(chargeWarning ? ["Charge de fluide au-delà de la limite"] : []),
            ...((result?.products ?? []).some((p) => p.shouldFreeze) ? ["Produits à congeler sur place"] : []),
        ];
        this.summaryAlertsTarget.replaceChildren(...(alerts.length > 0
            ? alerts.map((short) => {
                const link = Object.assign(document.createElement("a"), { href: "#cf-section-7", textContent: "voir le détail", className: "font-semibold underline underline-offset-2 hover:no-underline" });
                link.dataset.sectionLink = "7";
                link.dataset.action = "calculator--layout#go";
                const content = document.createElement("span");
                content.append(`⚠ ${short} — `, link);

                return createCallout("warning", content, {}, false);
            })
            : [Object.assign(document.createElement("p"), { className: "text-sm text-secondary-600 dark:text-secondary-300", textContent: "Aucune alerte." })]));
        this.alertCountTarget.textContent = String(alerts.length);
        this.alertCountTarget.toggleAttribute("data-alerts", alerts.length > 0);
    }

    /**
     * Rapport sur le fluide : charge, t éq. CO₂, contrôles, détection, interdictions vérifiées, limites NF EN 378-1 ou IEC 60335-2-89, COP.
     *
     * @returns {string|null} avertissement de charge (repris dans le résumé collant)
     */
    renderFluid(form, result) {
        const select = form.querySelector("[data-fluid-select]");
        const lines = [];
        let warning = null;
        const r = result?.refrigerant;
        if (result && r?.fluid) {
            this.updateFluidOptions(select, { installation: this.field(form, "installation").value, capacityKw: result.frigoKw, chargeTonnes: r.tonnes, today: this.todayValue });
            const report = fluidReport(r, {
                installation: this.field(form, "installation").value, volume: result.geo.volume, rulesCount: this.rulesValue.length,
                refrigerants: this.refrigerants, smallLabel: "Petit équipement autonome", place: "le local",
            });
            lines.push(...report.lines);
            warning = report.warning;
            lines.push(`COP ${n(result.cop, DECIMALS.cop)} : ${Number.isFinite(parseNumber(this.field(form, "copOverride")?.value ?? "")) && parseNumber(this.field(form, "copOverride").value) > 0 ? "valeur imposée" : `estimé avec T_év ${plain(r.estimated.tEvap)} °C et T_cond ${plain(r.estimated.tCond)} °C (fraction de Carnot 0,45, sans correction par fluide)`}.`);
        }
        const paragraphs = lines.map((text) => {
            const p = document.createElement("p");
            p.textContent = text;

            return p;
        });
        if (warning !== null) {
            paragraphs.unshift(createCallout("warning", warning, { chargeWarning: "" }));
        }
        this.fluidReportTarget.replaceChildren(...paragraphs);

        return warning;
    }
}
