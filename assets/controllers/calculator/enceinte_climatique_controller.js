/*
 * Contrôleur Stimulus « calculator--enceinte-climatique » : enceinte climatique v2, modes simple et pro.
 * Lit les champs du mode affiché (data-field) et les éléments des listes du mode pro (portes, vitrages,
 * apports manuels : data-item-field), applique les valeurs par défaut quand un choix change (profil de
 * consigne, type d'enceinte et saisie par volume, ensoleillement du module, consigne sous +5 °C, protection,
 * classe de stabilité, fluide), puis affiche les quatre puissances à installer (cartes de la section 9 et
 * résumé collant), les alertes (version courte avec un lien dans le résumé, texte complet dans la section
 * concernée), les résultats été et hiver côte à côte, le détail poste par poste, les résultats propres à
 * chaque section, la régulation PID et le rapport sur le fluide. Navigation et barre mobile : calculator--layout.
 *
 * Utilisé par : templates/calculator/tools/thermodynamique/enceinte-climatique.html.twig
 * Utilise : assets/calculators/enceinte_climatique.js, chambre_froide.js (panneaux, portes, vitrages), fgas.js,
 *           fluid_report.js, refrigerants.js, format.js, assets/lib/callout.js
 */
import { Controller } from "@hotwired/stimulus";
import {
    ACTUATORS, CHAMBER_KINDS, chamberPro, chamberSimple, DECIMALS, defaultDoor, DEFAULTS, DRYING_TYPES, explain, HEATING_TYPES, HUMIDIFIER_TYPES, PID_DEFAULTS, PROFILES,
    REGULATION_MODES, STABILITY_T, volumeToDimensions,
} from "../../calculators/enceinte_climatique.js";
import { DOOR_PROTECTIONS, GLAZING_TYPES, panelU } from "../../calculators/chambre_froide.js";
import { applicableRules, isBanned, proposedRefrigerants, ruleText } from "../../calculators/fgas.js";
import { fluidReport } from "../../calculators/fluid_report.js";
import { REFRIGERANTS, refrigerantsFromRows } from "../../calculators/refrigerants.js";
import { formatNumber, parseNumber, showResult } from "../../calculators/format.js";
import { createCallout } from "../../lib/callout.js";

/** Fluide choisi par défaut si aucun n'est coché « Fluide par défaut » dans l'admin. */
const DEFAULT_FLUID = "R290";

/** Version courte des alertes (résumé collant) et texte du lien vers le texte complet, par code d'alerte. */
const SHORT_ALERTS = {
    condensation: ["Condensation sur la batterie froide", "voir le conseil"],
    frost: ["Givrage de la batterie de séchage", "voir le détail"],
    rampHeat: ["Mise en température non atteinte", "voir le détail"],
    rampCold: ["Mise en froid non atteinte", "voir le détail"],
    underHeat: ["Chauffe installée sous le maintien", "voir le détail"],
    underCold: ["Froid installé sous le maintien", "voir le détail"],
    range: ["Plage de modulation insuffisante", "voir la régulation"],
    staging: ["Puissance installée trop forte : étages conseillés", "voir la régulation"],
    onOff: ["Actionneur tout ou rien pour une stabilité serrée", "voir la régulation"],
    charge: ["Charge de fluide au-delà de la limite", "voir le détail"],
};

/** Libellés des fonctions régulées. */
const LOOP_LABELS = { heat: "Chauffe", cold: "Froid", humid: "Humidification", dry: "Séchage" };

/** Libellés des éléments des listes. */
const ITEM_TITLES = { doors: "Porte", glazings: "Vitrage", manual: "Apport" };

/** Listes dont la valeur est un nombre. */
const NUMERIC_SELECTS = new Set(["thickness", "leaves"]);

/** Valeur saisie sans zéros inutiles (« 0,5 », « 40 »). */
const plain = (value) => new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 4, useGrouping: false }).format(value);

/** Nombre affiché. */
const n = (value, digits) => formatNumber(value, digits);

/** Puissance affichée en W (« 347 W ») ; une valeur arrondie à zéro s'affiche « 0 W », jamais « -0 W ». */
const watts = (value) => `${n(Math.abs(value) < 0.5 ? 0 : value, DECIMALS.w)} W`;

/** Durée affichée (« 10,6 min », « 2 h 09 min »). */
function duration(minutes) {
    if (minutes < 60) {
        return `${n(minutes, 1)} min`;
    }
    const total = Math.round(minutes);

    return `${Math.floor(total / 60)} h ${String(total % 60).padStart(2, "0")} min`;
}

export default class extends Controller {
    static values = { profiles: Array, refrigerants: Array, rules: Array, panels: Array, today: String };
    static targets = [
        "modeButton", "form", "cold", "heat", "drying", "humid", "coldDetail", "heatDetail", "dryingDetail", "humidDetail", "message", "summary",
        "summaryCold", "summaryHeat", "summaryDrying", "summaryHumid", "summaryColdDetail", "summaryHeatDetail", "summaryDryingDetail", "summaryHumidDetail", "summaryAlerts", "alertCount",
        "seasons", "summerHeader", "winterHeader", "breakdown", "fluidReport", "doorList", "glazingList", "manualList", "doorTemplate", "glazingTemplate", "manualTemplate",
    ];

    /** Prépare les listes (profils, fluides, éléments du mode pro), applique le profil par défaut, puis calcule. */
    connect() {
        this.mode = "simple";
        this.nextId = 1;
        this.profiles = this.profilesValue.length > 0 ? this.profilesValue : PROFILES;
        this.refrigerants = this.refrigerantsValue.length > 0 ? refrigerantsFromRows(this.refrigerantsValue) : REFRIGERANTS;
        this.panels = Object.fromEntries(this.panelsValue.map((panel) => [String(panel.id), panel]));
        const usable = Object.entries(this.refrigerants);
        this.defaultFluid = (usable.find(([, fluid]) => fluid.default) ?? usable.find(([code]) => code === DEFAULT_FLUID) ?? usable[0])?.[0] ?? null;
        const profile = (this.profiles.find((p) => p.default) ?? this.profiles[0])?.code ?? "custom";
        for (const form of this.formTargets) {
            this.fillProfiles(form.querySelector("[data-profile-select]"), profile);
            this.applyProfileTo(form, profile);
            this.updateKind(form);
        }
        const pro = this.formFor("pro");
        this.fillFluids(pro.querySelector("[data-fluid-select]"));
        this.applyInstallationDefault(pro);
        this.updatePanel(pro);
        this.appendItem("doors");
        this.updateEmptyNotes();
        this.updateTypes(pro);
        this.updateRegulation(pro);
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
        const item = this.appendItem(event.currentTarget.dataset.listName);
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

    /** Profil de consigne choisi : consignes remplies (modifiables), puis calcul. */
    applyProfile(event) {
        const form = event.target.closest("[data-mode]");
        this.applyProfileTo(form, event.target.value);
        if (form.dataset.mode === "pro") {
            this.updateDoorHeaters(form);
        }
        this.calculate();
    }

    /** Consigne modifiée à la main : le profil passe à « Personnalisé ». */
    markCustom(event) {
        const form = event.target.closest("[data-mode]");
        const select = form.querySelector("[data-profile-select]");
        const profile = this.profiles.find((p) => p.code === select.value);
        const tSet = parseNumber(this.field(form, "tSet").value);
        const rhSet = parseNumber(this.field(form, "rhSet").value);
        if (profile && (profile.tSet !== tSet || (profile.rhSet ?? NaN) !== rhSet) && !(profile.rhSet === null && Number.isNaN(rhSet) && profile.tSet === tSet)) {
            select.value = "custom";
            this.updateProfileHint(form);
        }
    }

    /** Consigne du mode pro changée : profil personnalisé, cordons de cadre (40 W/m sous +5 °C). */
    changeTemperature(event) {
        this.markCustom(event);
        this.updateDoorHeaters(event.target.closest("[data-mode]"));
        this.calculate();
    }

    /**
     * Type d'enceinte changé : champs propres au type ; en mode pro, valeurs par défaut du type (ventilation,
     * masses, local, éclairage, porte du monobloc), toutes modifiables.
     */
    changeKind(event) {
        const form = event.target.closest("[data-mode]");
        if (form.dataset.mode === "pro") {
            const kind = CHAMBER_KINDS[event.target.value] ?? CHAMBER_KINDS.modular;
            const set = (name, value) => {
                const element = this.field(form, name);
                if (element) {
                    element.value = plain(value);
                }
            };
            set("fansWPerM3", kind.fanWPerM3);
            for (const [name, value] of Object.entries(kind.masses)) {
                set(name, value);
            }
            set("summerLocalT", kind.summer.localT);
            set("summerLocalRh", kind.summer.localRh);
            set("winterLocalT", kind.winter.localT);
            set("winterLocalRh", kind.winter.localRh);
            set("lightingW", kind.lighting.watts);
            set("lightHours", kind.lighting.hours);
            this.field(form, "sun").value = "no";
            this.field(form, "frostProtected").value = "yes";
            set("airChanges", PID_DEFAULTS.airChanges[event.target.value] ?? PID_DEFAULTS.airChanges.modular);
            if (kind.volumeLiters) {
                set("volumeLiters", kind.volumeLiters);
            }
        }
        this.updateKind(form);
        this.calculate();
    }

    /** Saisie par volume ou par dimensions changée (monobloc), ou dimensions modifiées : dimensions déduites, porte. */
    changeSize(event) {
        this.updateSize(event.target.closest("[data-mode]"));
        this.calculate();
    }

    /**
     * Module chauffé hors gel ou non : mode simple, température extérieure minimale affichée ; mode pro, hiver
     * du module à 5 °C / 70 % (chauffé) ou à l'extérieur, −5 °C / 90 % (non chauffé), valeurs modifiables.
     */
    changeFrost(event) {
        const form = event.target.closest("[data-mode]");
        if (form.dataset.mode === "pro") {
            const kind = CHAMBER_KINDS.modular_outdoor;
            const winter = event.target.value === "no" ? kind.unheatedWinter : kind.winter;
            this.field(form, "winterLocalT").value = plain(winter.localT);
            this.field(form, "winterLocalRh").value = plain(winter.localRh);
        }
        this.updateKind(form);
        this.calculate();
    }

    /** Ensoleillement direct du module (mode pro) : + 5 K sur la température du module en été. */
    changeSun(event) {
        const form = event.target.closest("[data-mode]");
        const extra = CHAMBER_KINDS.modular_outdoor.sunExtraK;
        const field = this.field(form, "summerLocalT");
        field.value = plain(parseNumber(field.value) + (event.target.value === "yes" ? extra : -extra));
        this.calculate();
    }

    /** Champs propres au type d'enceinte : saisie par volume (monobloc), ensoleillement (modulaire extérieure), titre du local. */
    updateKind(form) {
        const kind = this.radioValue(form, "kind");
        for (const element of form.querySelectorAll("[data-show-kind]")) {
            element.hidden = element.dataset.showKind !== kind;
        }
        for (const element of form.querySelectorAll("[data-show-frost]")) {
            element.hidden = this.field(form, "frostProtected")?.value !== element.dataset.showFrost;
        }
        const localTitle = form.querySelector("[data-local-title] h3");
        if (localTitle) {
            localTitle.textContent = kind === "modular_outdoor" ? "Module (intérieur)" : "Local";
        }
        this.updateSize(form);
    }

    /** Volume ou dimensions selon la saisie ; volume et surface intérieurs affichés ; porte sur la face avant d'un monobloc (pro). */
    updateSize(form) {
        const monobloc = this.radioValue(form, "kind") === "monobloc";
        const byVolume = monobloc && this.radioValue(form, "sizeMode") === "volume";
        for (const element of form.querySelectorAll("[data-size]")) {
            element.hidden = (element.dataset.size === "volume") !== byVolume;
        }
        const dims = this.dimensions(form);
        const info = form.querySelector("[data-geometry-info]");
        const valid = [dims.length, dims.width, dims.height].every((value) => value > 0);
        info.textContent = valid
            ? `${byVolume ? `Dimensions déduites : ${n(dims.length, 2)} × ${n(dims.width, 2)} × ${n(dims.height, 2)} m (largeur × profondeur × hauteur). ` : ""}Volume ${n(dims.length * dims.width * dims.height, 2)} m³ (${n(dims.length * dims.width * dims.height * 1000, 0)} L), surface intérieure ${n(2 * (dims.length * dims.width + dims.length * dims.height + dims.width * dims.height), 2)} m².`
            : "";
        const door = this.hasDoorListTarget && form.dataset.mode === "pro" ? this.doorListTarget.querySelector("[data-item]") : null;
        if (door && valid) {
            const size = defaultDoor(this.radioValue(form, "kind"), dims.length, dims.height);
            this.itemField(door, "width").value = plain(Math.round(size.width * 100) / 100);
            this.itemField(door, "height").value = plain(Math.round(size.height * 100) / 100);
        }
    }

    /** Dimensions intérieures d'un formulaire : saisies, ou déduites du volume d'un monobloc. */
    dimensions(form) {
        const v = this.values(form);
        if (v.kind === "monobloc" && v.sizeMode === "volume") {
            return v.volumeLiters > 0 ? volumeToDimensions(v.volumeLiters) : { length: NaN, width: NaN, height: NaN };
        }

        return { length: v.length, width: v.width, height: v.height };
    }

    /** Valeur du bouton radio coché d'un groupe (data-field). */
    radioValue(form, name) {
        return form.querySelector(`[data-field="${name}"]:checked`)?.value ?? null;
    }

    /** Classe de stabilité changée : régulation conseillée (tant que la régulation n'a pas été choisie à la main). */
    changeStability(event) {
        this.updateRegulation(event.target.closest("[data-mode]"));
        this.calculate();
    }

    /** Retient qu'un champ a été modifié à la main. */
    markEdited(event) {
        event.target.dataset.edited = "true";
    }

    /** Type de chauffage, de froid, d'humidification ou de séchage changé : champs utiles. */
    changeType(event) {
        this.updateTypes(event.target.closest("[data-mode]"));
        this.calculate();
    }

    /** Panneau ou épaisseur changés : champs utiles et coefficient U affiché. */
    changePanel(event) {
        this.updatePanel(event.target.closest("[data-mode]"));
        this.calculate();
    }

    /** Protection de porte changée : efficacité par défaut, en %. */
    changeProtection(event) {
        this.itemField(event.target.closest("[data-item]"), "efficiency").value = plain((DOOR_PROTECTIONS[event.target.value] ?? DOOR_PROTECTIONS.none).efficiency * 100);
        this.calculate();
    }

    /** Type de vitrage changé : coefficient U du type. */
    changeGlazingType(event) {
        this.itemField(event.target.closest("[data-item]"), "u").value = plain((GLAZING_TYPES[event.target.value] ?? GLAZING_TYPES.double).u);
        this.calculate();
    }

    /** Type d'installation changé : « hermétiquement scellé » coché pour un groupe autonome (modifiable). */
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

    /** R-290 : groupe autonome hermétiquement scellé par défaut (décision du 2026-10-23), split pour les autres fluides. */
    applyInstallationDefault(form) {
        const installation = this.field(form, "installation");
        if (installation.dataset.edited === "true") {
            return;
        }
        const type = this.field(form, "fluid").value === "R290" ? "self_contained" : "split";
        installation.value = type;
        this.field(form, "hermetic").value = type === "self_contained" ? "yes" : "no";
    }

    /** Recalcule le bilan du mode affiché. */
    calculate() {
        const form = this.formFor(this.mode);
        const input = this.mode === "simple" ? this.readSimple(form) : this.readPro(form);
        const result = this.mode === "simple" ? chamberSimple(input) : chamberPro(input);
        const error = explain(this.mode, input);
        const cards = [["cold", "coldW"], ["heat", "heatW"], ["drying", "dryingW"], ["humid", "humidificationW"]];
        for (const [name, key] of cards) {
            const value = result?.installed[key].installedW ?? null;
            const detail = result ? this.installedDetail(result, key) : "";
            const capital = name.charAt(0).toUpperCase() + name.slice(1);
            showResult(this[`${name}Target`], value, DECIMALS.w, name === "cold" ? this.messageTarget : undefined, name === "cold" ? error : undefined);
            showResult(this[`summary${capital}Target`], value, DECIMALS.w);
            this[`${name}DetailTarget`].textContent = detail;
            this[`summary${capital}DetailTarget`].textContent = detail;
        }
        this.summaryTarget.textContent = result ? this.summaryText(result) : "";
        this.renderAlerts(result);
        this.renderPid(result);
        this.renderSeasons(result);
        this.renderBreakdown(result);
        this.renderSections(result);
        this.renderFluid(form, result);
    }

    /** Saisies du mode simple. */
    readSimple(form) {
        const v = this.values(form);

        return {
            kind: v.kind, sizeMode: v.sizeMode, volumeLiters: v.volumeLiters, length: v.length, width: v.width, height: v.height, sun: v.sun === "yes",
            frostProtected: v.frostProtected !== "no", outdoorMinT: v.outdoorMinT,
            tSet: v.tSet, rhSet: v.rhSet, stabilityT: v.stabilityT, stabilityRh: v.stabilityRh, tStart: v.tStart, rhStart: v.rhStart, rampMinutes: v.rampMinutes, humidMinutes: v.humidMinutes,
            doorProtection: v.doorProtection, airRenewal: v.airRenewal, heatingType: v.heatingType, coolingType: v.coolingType, humidifierType: v.humidifierType, dryingType: v.dryingType,
            refrigerant: {
                fluid: this.refrigerants[this.defaultFluid] ?? null, fluidCode: this.defaultFluid, installation: this.defaultFluid === "R290" ? "self_contained" : "split",
                hermetic: this.defaultFluid === "R290", rules: this.rulesValue, today: this.todayValue,
            },
        };
    }

    /** Saisies du mode pro. */
    readPro(form) {
        const v = this.values(form);
        const items = (list) => [...list.querySelectorAll("[data-item]")].map((item) => this.values(item, "data-item-field"));

        return {
            mode: "pro", kind: v.kind, ...this.dimensions(form), panel: this.readPanel(form), leakPct: v.leakPct,
            summer: { localT: v.summerLocalT, localRh: v.summerLocalRh, freshT: v.summerFreshT, freshRh: v.summerFreshRh },
            winter: { localT: v.winterLocalT, localRh: v.winterLocalRh, freshT: v.winterFreshT, freshRh: v.winterFreshRh },
            masses: { skinKgPerM2: v.skinKgPerM2, skinCp: v.skinCp, shelvesKg: v.shelvesKg, shelvesCp: v.shelvesCp, structureKg: v.structureKg, structureCp: v.structureCp },
            tSet: v.tSet, rhSet: v.rhSet, stabilityT: v.stabilityT, stabilityRh: v.stabilityRh, tStart: v.tStart, rhStart: v.rhStart, rampMinutes: v.rampMinutes, humidMinutes: v.humidMinutes,
            // Efficacité des protections saisie en %
            doors: items(this.doorListTarget).map((d) => ({ ...d, efficiency: d.efficiency / 100, heaterFraction: DEFAULTS.door.heaterFraction })),
            glazings: items(this.glazingListTarget).map((g) => ({ area: g.area, u: g.u, heated: g.heated === "yes", heaterW: g.heaterW, heaterFraction: 0.7 })),
            fans: { wPerM3: v.fansWPerM3 }, lighting: { watts: v.lightingW, hours: v.lightHours }, people: { count: v.people, hours: v.peopleHours },
            instrumentation: { watts: v.instrumentationW, hours: v.instrumentationHours },
            samples: { kg: v.sampleKg, cp: v.sampleCp, deltaT: v.sampleDeltaT, dissipatedW: v.sampleW, hours: v.sampleHours, waterGPerH: v.sampleWater },
            manual: items(this.manualListTarget),
            heating: { type: v.heatingType, efficiency: v.heatingEfficiency, supplyT: v.hotSupplyT, returnT: v.hotReturnT, installedW: v.heaterW },
            cooling: {
                type: v.coolingType, supplyT: v.chilledSupplyT, returnT: v.chilledReturnT, cop: v.cop, surfaceOffset: v.surfaceOffset, condenserAir: v.condenserAir, regulation: v.regulation, installedW: v.coldW,
                refrigerant: {
                    fluid: this.refrigerants[v.fluid] ?? null, fluidCode: v.fluid, installation: v.installation, hermetic: v.hermetic === "yes", lineLength: v.lineLength,
                    detection: v.detection === "yes", chargeRatio: v.chargeRatio, rules: this.rulesValue, today: this.todayValue,
                },
            },
            humidification: { type: v.humidifierType, waterT: v.waterT, efficiency: v.humidEfficiency },
            drying: { type: v.dryingType, cop: v.dryingCop },
            airRenewal: v.airRenewal, runHours: v.runHours, margin: v.margin, internalGainsInHeating: v.internalGainsInHeating === "yes",
            pid: {
                actuators: { heat: v.actHeat, cold: v.actCold, humid: v.actHumid, dry: v.actDry }, coldMinPct: v.coldMinPct, stagingRatio: v.stagingRatio,
                airChanges: v.airChanges, sensorT: v.sensorT, sensorRh: v.sensorRh, spanT: v.spanT, tauFactor: v.tauFactor, derivative: v.derivative === "yes",
                hygroMassKg: v.hygroMassKg, hygroCapacity: v.hygroCapacity,
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

    /** Valeurs d'un formulaire ou d'un élément : nombres (NaN si vide), valeurs des listes et textes (data-item-text). */
    values(root, attribute = "data-field") {
        const values = {};
        for (const element of root.querySelectorAll(`[${attribute}]`)) {
            if (attribute === "data-field" && element.closest("[data-item]")) {
                continue;
            }
            const name = element.getAttribute(attribute);
            if (element.type === "radio") {
                if (element.checked) {
                    values[name] = element.value;
                }
                continue;
            }
            const text = (element.tagName === "SELECT" && !NUMERIC_SELECTS.has(name)) || element.hasAttribute("data-item-text");
            values[name] = text ? element.value : parseNumber(element.value);
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
    appendItem(listName) {
        const [list, template] = {
            doors: [this.doorListTarget, this.doorTemplateTarget],
            glazings: [this.glazingListTarget, this.glazingTemplateTarget],
            manual: [this.manualListTarget, this.manualTemplateTarget],
        }[listName];
        list.append(document.createRange().createContextualFragment(template.innerHTML.replaceAll("__ID__", String(this.nextId++))));
        const item = list.lastElementChild;
        if (listName === "doors") {
            const tSet = parseNumber(this.field(this.formFor("pro"), "tSet").value);
            this.itemField(item, "heaterWPerM").value = plain(tSet < DEFAULTS.door.heaterBelowT ? DEFAULTS.door.heaterWPerM : 0);
        }
        this.renumber(list);

        return item;
    }

    /** Numérote les éléments d'une liste (« Porte 1 », « Porte 2 »…). */
    renumber(list) {
        const title = ITEM_TITLES[list.dataset.list];
        [...list.querySelectorAll("[data-item-title]")].forEach((legend, index) => {
            legend.textContent = `${title} ${index + 1}`;
        });
    }

    /** Affiche « Aucune porte », « Aucun vitrage »… quand une liste est vide. */
    updateEmptyNotes() {
        for (const note of this.element.querySelectorAll("[data-empty-note]")) {
            note.hidden = this.element.querySelector(`[data-list="${note.dataset.emptyNote}"] [data-item]`) !== null;
        }
    }

    /** Cordons de cadre des portes : 40 W/m sous +5 °C, sinon 0. */
    updateDoorHeaters(form) {
        const tSet = parseNumber(this.field(form, "tSet").value);
        const value = plain(tSet < DEFAULTS.door.heaterBelowT ? DEFAULTS.door.heaterWPerM : 0);
        for (const heater of this.doorListTarget.querySelectorAll('[data-item-field="heaterWPerM"]')) {
            heater.value = value;
        }
    }

    /** Régulation conseillée selon la classe de stabilité (modulation pour ± 0,5 et ± 1 K), et conseil affiché. */
    updateRegulation(form) {
        const stability = STABILITY_T[this.field(form, "stabilityT").value] ?? STABILITY_T[2];
        const select = this.field(form, "regulation");
        if (select.dataset.edited !== "true") {
            select.value = stability.modulate ? "modulating" : "on_off";
        }
        form.querySelector("[data-regulation-hint]").replaceChildren(createCallout("info", `Stabilité ${stability.label} : ${stability.advice}.`));
    }

    /** Champs propres au type choisi (eau chaude, rendement, eau glacée, fluide, COP). */
    updateTypes(form) {
        for (const element of form.querySelectorAll("[data-show-for]")) {
            const [name, value] = element.dataset.showFor.split(":");
            element.hidden = this.field(form, name).value !== value;
        }
    }

    /** Liste des profils par secteur, puis « Personnalisé ». */
    fillProfiles(select, selected) {
        const sectors = [...new Set(this.profiles.map((p) => p.sector))];
        select.replaceChildren(
            ...sectors.map((sector) => {
                const optgroup = document.createElement("optgroup");
                optgroup.label = sector;
                optgroup.append(...this.profiles.filter((p) => p.sector === sector).map((p) => new Option(`${p.name} — ${plain(p.tSet)} °C${p.rhSet === null ? "" : ` / ${plain(p.rhSet)} % HR`}`, p.code, false, p.code === selected)));

                return optgroup;
            }),
            new Option("Personnalisé", "custom", false, selected === "custom"),
        );
    }

    /** Recopie la consigne d'un profil (humidité vide si elle n'est pas régulée). */
    applyProfileTo(form, code) {
        const profile = this.profiles.find((p) => p.code === code);
        if (profile) {
            this.field(form, "tSet").value = plain(profile.tSet);
            this.field(form, "rhSet").value = profile.rhSet === null ? "" : plain(profile.rhSet);
        }
        this.updateProfileHint(form);
    }

    /** Tolérances et source du profil choisi, « à vérifier » tant que l'admin ne l'a pas vérifié. */
    updateProfileHint(form) {
        const profile = this.profiles.find((p) => p.code === form.querySelector("[data-profile-select]").value);
        form.querySelector("[data-profile-hint]").textContent = profile
            ? `${profile.sector}, ${profile.name} : tolérances ± ${plain(profile.tolT)} °C${profile.tolRh === null ? " (humidité non régulée)" : ` / ± ${plain(profile.tolRh)} % HR`} — source : ${profile.source}${profile.verified ? "" : " (à vérifier)"}.`
            : "Consigne personnalisée.";
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
        const u = panelU(this.readPanel(form));
        const origin = source === "model" ? ` — fiche : ${this.panels[this.field(form, "panel").value.split(":")[1]]?.source ?? "?"}` : source === "generic" ? " (panneau générique, λ de l'isolant ; à vérifier sur la fiche du fabricant)" : "";
        form.querySelector("[data-panel-info]").textContent = Number.isFinite(u) ? `U = ${n(u, 3)} W/(m²·K)${origin}` : "";
    }

    /** Liste des fluides de l'admin : PRG < 150, puis 150 à 2 500 ; présélection : fluide par défaut. */
    fillFluids(select) {
        const { low, medium } = proposedRefrigerants(this.refrigerants);
        select.replaceChildren(...[["PRG inférieur à 150", low], ["PRG de 150 à 2 500", medium]].filter(([, fluids]) => fluids.length > 0).map(([label, fluids]) => {
            const optgroup = document.createElement("optgroup");
            optgroup.label = label;
            optgroup.append(...fluids.map(([code, fluid]) => {
                const option = new Option(`${fluid.name} (PRG ${fluid.gwp}, ${fluid.class})`, code, false, code === this.defaultFluid);
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

    /**
     * Détail d'une puissance à installer : « maintien : X W · mise en régime : Y W », ou la durée de mise en
     * régime avec la puissance installée saisie.
     */
    installedDetail(r, key) {
        const v = r.installed[key];
        const direction = { heatW: "heat", coldW: "cool" }[key];
        let ramp = `mise en régime : ${watts(v.rampW)}`;
        if (v.entered) {
            const times = Object.values(r.rampTemperature).filter((t) => t.direction === direction).map((t) => (t.minutes === null ? "non atteinte" : duration(t.minutes)));
            ramp = `puissance saisie ; mise en régime : ${times.length > 0 ? times.join(" / ") : "—"}`;
        }

        return `maintien : ${watts(v.maintenanceW)} · ${ramp}`;
    }

    /** Résumé sous les puissances : volume, U, point de rosée, consommation et eau des deux saisons. */
    summaryText(r) {
        const c = r.consumptionKwhDay;
        const dew = r.regulated ? `point de rosée ${n(r.td, 1)} °C, ` : "humidité non régulée, ";

        return `Volume ${n(r.geo.volume, DECIMALS.volume)} m³, surface ${n(r.geo.surface, DECIMALS.surface)} m², U = ${n(r.u, 3)} W/(m²·K) · ${dew}stabilité ${r.stabilityT.label} · consommation électrique ${n(c.summer, DECIMALS.kwh)} kWh/j en été, ${n(c.winter, DECIMALS.kwh)} kWh/j en hiver (${REGULATION_MODES[r.regulation.mode]?.toLowerCase() ?? "modulation"}) · puissances marge de ${plain(r.margin)} % comprise.`;
    }

    /** Avertissements (givrage, consigne non atteinte) et conseils (condensation sur la batterie). */
    renderAlerts(r) {
        const form = this.formFor(this.mode);
        const charge = r?.refrigerant && r.cooling.type === "dx" ? this.fluidReportFor(r).warning : null;
        const alerts = [...(charge ? [{ kind: "warning", code: "charge", section: 10, text: charge }] : []), ...(r?.alerts ?? [])];
        // Texte complet dans la section concernée (le fluide garde le sien dans son rapport)
        for (const container of form.querySelectorAll("[data-section-alerts]")) {
            const section = Number(container.dataset.sectionAlerts);
            container.replaceChildren(...alerts.filter((a) => a.section === section).map((a) => createCallout(a.kind, a.text, a.code === "condensation" ? { advice: "" } : {})));
        }
        const prefix = this.mode === "simple" ? "ec-s" : "ec-p";
        this.summaryAlertsTarget.replaceChildren(...(alerts.length > 0
            ? alerts.map((a) => {
                const [short, more] = SHORT_ALERTS[a.code] ?? [a.text, "voir le détail"];
                const link = Object.assign(document.createElement("a"), {
                    href: `#${a.section === 10 ? "ec-section-10" : `${prefix}-section-${a.section}`}`, textContent: more,
                    className: "font-semibold underline underline-offset-2 hover:no-underline",
                });
                link.dataset.sectionLink = String(a.section);
                link.dataset.action = "calculator--layout#go";
                const content = document.createElement("span");
                content.append(`⚠ ${short} — `, link);

                return createCallout(a.kind, content, { alertCode: a.code }, false);
            })
            : [Object.assign(document.createElement("p"), { className: "text-sm text-secondary-600 dark:text-secondary-300", textContent: "Aucune alerte." })]));
        this.alertCountTarget.textContent = String(alerts.length);
        this.alertCountTarget.toggleAttribute("data-alerts", alerts.length > 0);
    }

    /**
     * Régulation PID (section 9 du mode affiché) : modèle, réglages de départ par fonction (Xp, Ti, Td, plage de
     * modulation), architecture conseillée pour la classe de stabilité, calcul chiffré dans les formules.
     */
    renderPid(r) {
        const form = this.formFor(this.mode);
        const tau = form.querySelector("[data-tau-output]");
        if (tau) {
            tau.textContent = plain(parseNumber(this.field(form, "tauFactor").value) || 1);
        }
        const model = form.querySelector("[data-pid-model]");
        const body = form.querySelector("[data-pid-loops]");
        const detail = form.querySelector("[data-pid-detail]");
        if (r === null) {
            model.textContent = "";
            body.replaceChildren();
            detail.textContent = "";
            return;
        }
        const { temperature: t, humidity: h, loops, settings } = r.pid;
        model.textContent = `Température : UA = ${n(t.uaWK, 2)} W/K, capacité ${n(t.capacityJK / 1000, 1)} kJ/K, τ = ${n(t.tauS / 3600, 2)} h, θ = ${n(t.thetaS, 0)} s (brassage ${n(t.mixS, 0)} s à ${plain(t.airChanges)} vol/h + sonde ${plain(settings.sensorT)} s), τ/θ = ${n(t.tauS / t.thetaS, 0)}.`
            + (h ? ` Humidité : échanges ${n(h.exchangeKgS * 3600, 2)} kg/h, capacité en eau ${n(h.massKg, 1)} kg d'air, τ = ${n(h.tauS / 3600, 2)} h, θ = ${n(h.thetaS, 0)} s.` : "");
        const rows = Object.entries(loops).map(([loop, l]) => {
            const humidityLoop = loop === "humid" || loop === "dry";
            const actuator = (ACTUATORS[loop][settings.actuators[loop]]?.label ?? "").split(" (")[0];
            const xp = humidityLoop ? `${n(l.xp, 2)} % HR` : `${n(l.xp, 2)} K (${n(l.xpPct, 2)} %)`;
            // Puissance minimale à fournir (% de l'installée) / plage minimale de l'actionneur
            const range = `${l.belowRange ? "⚠ " : ""}${n(l.minRatioPct, 0)} % / ${l.actuatorMinPct !== null ? `≥ ${n(l.actuatorMinPct, 0)} %` : "TOR"}`;
            const row = this.row(LOOP_LABELS[loop], [actuator], [xp, `${n(l.ti, 0)} s`, `${n(l.td, 0)} s`, range]);
            // Td : masqué sur mobile (rappelé dans le détail des formules)
            row.cells[3].classList.add("hidden", "sm:table-cell");
            row.dataset.pidLoop = loop;

            return row;
        });
        body.replaceChildren(...rows);
        const archKey = { "0.5": "0.5", 1: "1", 2: "2", 3: "2" }[this.field(form, "stabilityT").value] ?? "2";
        for (const tr of form.querySelectorAll("[data-architecture]")) {
            tr.toggleAttribute("data-current", tr.dataset.architecture === archKey);
        }
        detail.textContent = `Calcul : ${Object.entries(loops).map(([loop, l]) => `${LOOP_LABELS[loop].toLowerCase()} : K_u = ${n(l.kp, 4)}${loop === "humid" || loop === "dry" ? " % HR" : " K"} par %, Kc = ${n(l.kc, 1)} % par ${loop === "humid" || loop === "dry" ? "% HR" : "K"}, Xp = 100 / Kc = ${n(l.xp, 2)}, Ti = ${n(l.ti, 0)} s, Td = ${n(l.td, 0)} s`).join(" ; ")} ; τc = ${plain(settings.tauFactor)} × θ.`;
    }

    /** Tableau été / hiver : puissances de maintien, mises en régime, consommation, eau. */
    renderSeasons(r) {
        if (r === null) {
            this.seasonsTarget.replaceChildren();
            return;
        }
        const { summer, winter } = r.seasons;
        this.summerHeaderTarget.replaceChildren("Été", Object.assign(document.createElement("span"), { className: "block text-xs font-normal", textContent: `local ${plain(summer.local.t)} °C / ${plain(summer.local.rh)} %` }));
        this.winterHeaderTarget.replaceChildren("Hiver", Object.assign(document.createElement("span"), { className: "block text-xs font-normal", textContent: `local ${plain(winter.local.t)} °C / ${plain(winter.local.rh)} %` }));
        const ramp = (season) => {
            const t = r.rampTemperature[season];
            if (t.direction === null) {
                return "départ à la consigne";
            }
            const what = t.direction === "heat" ? "montée" : "descente";
            const from = `de ${plain(t.startT)} °C`;
            if (t.requiredW !== undefined) {
                return `${what} ${from} en ${plain(r.rampMinutes)} min : ${watts(t.requiredW)}`;
            }

            return t.minutes === null ? `${what} ${from} : non atteinte avec ${watts(t.powerW)} installés` : `${what} ${from} : ${duration(t.minutes)} avec ${watts(t.powerW)} installés`;
        };
        const humidRamp = (season) => {
            const h = r.rampHumidity?.[season];
            if (!h) {
                return "—";
            }

            return h.rateKgH >= 0 ? `${n(h.waterKg * 1000, 0)} g d'eau à apporter, ${n(h.rateKgH * 1000, 0)} g/h (${watts(h.powerW)})` : `${n(-h.waterKg * 1000, 0)} g d'eau à retirer, ${n(-h.rateKgH * 1000, 0)} g/h (séchage ${watts(h.dryingW)})`;
        };
        const water = (s) => {
            const net = s.waterKgH - s.condensationKgH;

            return `${net >= 0 ? "+" : "−"} ${n(Math.abs(net) * 1000, 0)} g/h${s.condensationKgH > 0 ? ` (condensation ${n(s.condensationKgH * 1000, 0)} g/h comprise)` : ""}`;
        };
        const rows = [
            ["Refroidissement (maintien)", watts(summer.coldW), watts(winter.coldW)],
            ["Chauffe (maintien)", watts(summer.heatW), watts(winter.heatW)],
            ...(r.regulated ? [
                ["Humidification", `${watts(summer.humidificationW)} (${n(summer.humidificationKgH * 1000, 0)} g/h)`, `${watts(winter.humidificationW)} (${n(winter.humidificationKgH * 1000, 0)} g/h)`],
                ["Séchage", `${watts(summer.dryingW)} (${n(summer.dryingKgH * 1000, 0)} g/h)`, `${watts(winter.dryingW)} (${n(winter.dryingKgH * 1000, 0)} g/h)`],
                ["dont réchauffage après la batterie", watts(summer.reheatW), watts(winter.reheatW)],
                ["Bilan d'eau (+ : à retirer, − : à apporter)", water(summer), water(winter)],
            ] : []),
            ["Mise en température", ramp("summer"), ramp("winter")],
            ...(r.regulated ? [["Mise en régime d'humidité", humidRamp("summer"), humidRamp("winter")]] : []),
            ["Consommation électrique", `${n(r.consumptionKwhDay.summer, DECIMALS.kwh)} kWh/j`, `${n(r.consumptionKwhDay.winter, DECIMALS.kwh)} kWh/j`],
            ...(summer.thermalKwhDay > 0 || winter.thermalKwhDay > 0 ? [["Énergie de chauffe hors électricité", `${n(summer.thermalKwhDay, DECIMALS.kwh)} kWh/j`, `${n(winter.thermalKwhDay, DECIMALS.kwh)} kWh/j`]] : []),
            ["Eau consommée / condensats", `${n(summer.waterLDay, DECIMALS.l)} / ${n(summer.condensateLDay, DECIMALS.l)} L/j`, `${n(winter.waterLDay, DECIMALS.l)} / ${n(winter.condensateLDay, DECIMALS.l)} L/j`],
        ];
        if (r.stabilityT.modulate || r.regulation.mode === "permanent_reheat") {
            rows.push(["Comparaison : modulation", `${n(r.regulation.modulatedKwhDay.summer, DECIMALS.kwh)} kWh/j`, `${n(r.regulation.modulatedKwhDay.winter, DECIMALS.kwh)} kWh/j`]);
            rows.push([`Comparaison : froid permanent (${watts(r.regulation.installedColdW)}) + réchauffage (${watts(r.regulation.reheatW)})`, `${n(r.regulation.permanentKwhDay.summer, DECIMALS.kwh)} kWh/j`, `${n(r.regulation.permanentKwhDay.winter, DECIMALS.kwh)} kWh/j`]);
        }
        this.seasonsTarget.replaceChildren(...rows.map(([label, a, b]) => this.row(label, [], [a, b])));
    }

    /** Détail poste par poste : formule et calcul chiffré, puissance en été et en hiver. */
    renderBreakdown(r) {
        if (r === null) {
            this.breakdownTarget.replaceChildren();
            return;
        }
        const { summer, winter } = r.seasons;
        const keys = [...new Set([...summer.lines, ...winter.lines].map((l) => l.key))];
        const rows = keys.map((key) => {
            const a = summer.lines.find((l) => l.key === key);
            const b = winter.lines.find((l) => l.key === key);
            const ref = a ?? b;
            const details = a && b && a.detail !== b.detail ? [`été : ${a.detail}`, `hiver : ${b.detail}`] : [ref.detail];

            return this.row(ref.label, [ref.formula, ...details], [a ? watts(a.watts) : "—", b ? watts(b.watts) : "—"]);
        });
        rows.push(this.row("Froid sensible, puis froid à installer (× marge)", [], [`${watts(summer.coldSensibleW)} → ${watts(summer.coldW)}`, `${watts(winter.coldSensibleW)} → ${watts(winter.coldW)}`], true));
        this.breakdownTarget.replaceChildren(...rows);
    }

    /** Ligne d'un tableau : libellé (avec textes secondaires) et deux valeurs. */
    row(label, texts, cells, strong = false) {
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
        for (const text of cells) {
            const td = document.createElement("td");
            td.className = `py-2 pl-2 text-right align-top text-xs tabular-nums sm:font-mono sm:text-sm ${strong ? "font-bold" : ""}`;
            td.textContent = text;
            tr.append(td);
        }

        return tr;
    }

    /** Résultats propres aux sections du mode pro : chauffage, refroidissement, humidification, séchage. */
    renderSections(r) {
        const pro = this.formFor("pro");
        const set = (name, text) => {
            pro.querySelector(`[data-section-result="${name}"]`).replaceChildren(...(text === "" ? [] : [createCallout("result", text)]));
        };
        if (r === null || this.mode !== "pro") {
            for (const name of ["heating", "cooling", "humidification", "drying"]) {
                set(name, "");
            }
            return;
        }
        const { summer, winter } = r.seasons;
        const ramps = Object.values(r.rampTemperature);
        const heatRamp = ramps.find((t) => t.direction === "heat");
        const coolRamp = ramps.find((t) => t.direction === "cool");
        const heating = HEATING_TYPES[r.heating.type] ?? HEATING_TYPES.electric;
        const flow = r.heating.type === "hot_water" && r.sizing.heatW > 0 ? ` ; débit d'eau chaude ${n((r.sizing.heatW / 1000 / (4.186 * (r.heating.supplyT - r.heating.returnT))) * 3.6, 3)} m³/h` : r.heating.type === "steam" && r.sizing.heatW > 0 ? ` ; vapeur ${n((r.sizing.heatW / 1000 / 2257) * 3600, 2)} kg/h` : "";
        set("heating", `${heating.label} : à installer ${watts(r.installed.heatW.installedW)} ; maintien ${watts(winter.heatW)} (hiver), ${watts(summer.heatW)} (été)${heatRamp ? ` ; mise en température : ${heatRamp.requiredW !== undefined ? `${watts(heatRamp.requiredW)} nécessaires` : heatRamp.minutes === null ? "non atteinte" : duration(heatRamp.minutes)}` : ""}${flow} ; « froid permanent + réchauffage » : réchauffage de ${watts(r.regulation.reheatW)}.`);
        const chilled = r.cooling.type === "chilled_water" ? ` ; débit d'eau glacée ${n((r.sizing.coldW / 1000 / (4.186 * (r.cooling.returnT - r.cooling.supplyT))) * 3.6, 3)} m³/h` : "";
        set("cooling", `Maintien ${watts(summer.coldW)} (été), ${watts(winter.coldW)} (hiver)${coolRamp ? ` ; mise en froid : ${coolRamp.requiredW !== undefined ? `${watts(coolRamp.requiredW)} nécessaires` : coolRamp.minutes === null ? "non atteinte" : duration(coolRamp.minutes)}` : ""} ; à installer ${watts(r.installed.coldW.installedW)}, COP ${n(r.cop, DECIMALS.cop)} (évaporation ${n(r.tEvap, 1)} °C, surface de la batterie ${n(r.tSurface, 1)} °C)${chilled}.`);
        if (!r.regulated) {
            set("humidification", "Humidité non régulée.");
            set("drying", "Humidité non régulée.");
            return;
        }
        const humidifier = HUMIDIFIER_TYPES[r.humidification.type] ?? HUMIDIFIER_TYPES.steam_electric;
        const humid = (s) => `${n(s.humidificationKgH * 1000, 0)} g/h, ${watts(s.humidificationW)}, ${n(s.waterLDay, DECIMALS.l)} L/j, sensible ${watts(s.humidifierSensibleW)}`;
        set("humidification", `${humidifier.label} — été : ${humid(summer)} ; hiver : ${humid(winter)}.`);
        const drying = DRYING_TYPES[r.drying.type] ?? DRYING_TYPES.dx_coil;
        const dry = (s) => (s.dryingKgH > 0 ? `${n(s.dryingKgH * 1000, 0)} g/h, ${watts(s.dryingW)}${s.dryingCoilT !== null ? ` (batterie à ${n(s.dryingCoilT, 1)} °C, réchauffage ${watts(s.reheatW)})` : ""}, condensats ${n(s.condensateLDay, DECIMALS.l)} L/j` : "aucun");
        set("drying", `${drying.label} — été : ${dry(summer)} ; hiver : ${dry(winter)}.`);
    }

    /** Lignes et avertissement du rapport sur le fluide (fluid_report.js). */
    fluidReportFor(r) {
        return fluidReport(r.refrigerant, { installation: r.cooling.refrigerant.installation, volume: r.geo.volume, rulesCount: this.rulesValue.length, refrigerants: this.refrigerants, smallLabel: "Petite enceinte", place: "l'enceinte" });
    }

    /** Rapport sur le fluide (détente directe) : charge, contrôles, interdictions vérifiées, limites, COP. */
    renderFluid(form, r) {
        const paragraphs = [];
        const ref = r?.refrigerant;
        if (r && r.cooling.type !== "dx") {
            paragraphs.push(Object.assign(document.createElement("p"), { textContent: `Refroidissement par ${r.cooling.type === "chilled_water" ? "eau glacée" : "un autre système"} : pas de fluide frigorigène dans l'enceinte ; COP ${n(r.cop, DECIMALS.cop)}.` }));
        } else if (ref) {
            const select = form.querySelector("[data-fluid-select]");
            if (select) {
                this.updateFluidOptions(select, { installation: r.cooling.refrigerant.installation, capacityKw: r.installed.coldW.installedW / 1000, chargeTonnes: ref.tonnes, today: this.todayValue });
            }
            const report = this.fluidReportFor(r);
            if (report.warning !== null) {
                paragraphs.push(createCallout("warning", report.warning, { chargeWarning: "" }));
            }
            paragraphs.push(...[...report.lines, `COP ${n(r.cop, DECIMALS.cop)} : ${r.cooling.cop > 0 ? "valeur imposée" : `0,45 × Carnot, évaporation ${n(r.tEvap, 1)} °C, condensation ${n(r.cooling.condenserAir + 10, 1)} °C, plafonné à 5`}.`]
                .map((text) => Object.assign(document.createElement("p"), { textContent: text })));
        }
        this.fluidReportTarget.replaceChildren(...paragraphs);
    }
}
