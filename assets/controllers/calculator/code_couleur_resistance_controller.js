/*
 * Contrôleur Stimulus « calculator--code-couleur-resistance » : code couleur des résistances
 * (CEI 60062), décodage (anneaux → valeur) et codage (valeur → anneaux), aperçu des anneaux,
 * plage de tolérance, valeur E24 proche, conseil de puissance.
 *
 * Portage de l'ancien contrôleur (resistor_color_controller.js de guillaume_c) ; les listes et
 * l'aperçu sont construits avec le DOM (aucun HTML assemblé en texte). Affichage des nombres en français.
 *
 * Utilisé par : templates/calculator/tools/electronique/code-couleur-resistance.html.twig
 * Utilise : assets/calculators/resistor_colors.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { BAND_DIGITS, colorMeta, decodeBands, e24Neighbors, encodeValue, formatOhms, MULTIPLIERS, nearestE24, suggestPowerRating, TEMP_COEFFS, TOLERANCES } from "../../calculators/resistor_colors.js";
import { parseNumber } from "../../calculators/format.js";

/** Couleur par défaut de chaque anneau (1 kΩ ±5 %, coefficient 100 ppm). */
const DEFAULT_BAND_VALUES = { 1: "1", 2: "0", 3: "2", 4: "gold", 5: "gold", 6: "brown" };

/** Libellé du rôle de chaque anneau. */
const ROLE_LABELS = { digit: "chiffre", mult: "multiplicateur", tol: "tolérance", temp: "coefficient de température" };

/** Rôles des anneaux selon leur nombre. */
const ROLES = {
    4: ["digit", "digit", "mult", "tol"],
    5: ["digit", "digit", "digit", "mult", "tol"],
    6: ["digit", "digit", "digit", "mult", "tol", "temp"],
};

/** Repère affiché sur chaque anneau de l'aperçu. */
const PREVIEW_LABELS = { digit: null, mult: "×", tol: "±", temp: "T" };

/** Affichage français des valeurs (« 4.70 kΩ » → « 4,70 kΩ »). */
const french = (text) => text.replace(".", ",");

/** Options proposées pour un rôle. */
function optionsForRole(role) {
    if (role === "digit") {
        return BAND_DIGITS.map((c) => ({ id: c.id, label: c.name }));
    }
    if (role === "mult") {
        return MULTIPLIERS.map((c) => ({ id: c.id, label: `${c.name} ×${c.factor.toLocaleString("fr-FR")}` }));
    }
    if (role === "tol") {
        return TOLERANCES.map((c) => ({ id: c.id, label: `±${c.pct.toLocaleString("fr-FR")} % ${c.name}` }));
    }

    return TEMP_COEFFS.map((c) => ({ id: c.id, label: `${c.ppm} ppm ${c.name}` }));
}

export default class extends Controller {
    static targets = ["modeTab", "bandCount", "bandSelect", "bandRoleLabel", "preview", "resultValue", "resultTolerance", "resultTemp", "resultRange", "targetValue", "targetTolerance", "targetPower", "powerAdvice", "encodeResult", "encodeNote", "nearestE24", "e24Neighbors"];

    /** Démarre en décodage, 4 anneaux. */
    connect() {
        this.mode = "decode";
        this.onBandCountChange();
    }

    /** Passe d'un sens de conversion à l'autre. */
    setMode(event) {
        this.mode = event.currentTarget.dataset.mode;
        for (const tab of this.modeTabTargets) {
            tab.setAttribute("aria-pressed", String(tab.dataset.mode === this.mode));
        }
        for (const panel of this.element.querySelectorAll("[data-mode-panel]")) {
            panel.hidden = panel.dataset.modePanel !== this.mode;
        }
        this.refresh();
    }

    /** Affiche les anneaux utiles et leurs couleurs possibles. */
    onBandCountChange() {
        const count = this.count();
        for (const select of this.bandSelectTargets) {
            const band = Number(select.dataset.bandIndex);
            const role = ROLES[count][band - 1] ?? null;
            select.closest("[data-band-row]").hidden = role === null;
            if (role === null) {
                continue;
            }
            this.fillSelect(select, role, select.value || DEFAULT_BAND_VALUES[band]);
            const label = this.bandRoleLabelTargets.find((element) => Number(element.dataset.bandIndex) === band);
            if (label) {
                label.textContent = `Anneau ${band} — ${ROLE_LABELS[role]}`;
            }
        }
        this.refresh();
    }

    /** Recalcule selon le sens choisi. */
    refresh() {
        if (this.mode === "encode") {
            this.encodeFromValue();
        } else {
            this.decodeFromBands();
        }
    }

    /** Anneaux → valeur. */
    decodeFromBands() {
        const count = this.count();
        const bandIds = this.visibleSelects(count).map((select) => select.value);
        this.renderPreview(bandIds, count);
        const decoded = decodeBands(bandIds, count);
        if (!decoded) {
            this.showResults(null);
            return;
        }
        this.showResults(decoded.ohms, decoded.tolerancePct, decoded.tempPpm);
    }

    /** Valeur → anneaux. */
    encodeFromValue() {
        const count = this.count();
        const ohms = parseNumber(this.targetValueTarget.value);
        if (!Number.isFinite(ohms) || ohms <= 0) {
            this.encodeResultTarget.textContent = "—";
            this.encodeNoteTarget.textContent = "Entrez une valeur de résistance valide (strictement positive).";
            this.showResults(null);
            return;
        }

        const encoded = encodeValue(ohms, count, this.targetToleranceTarget.value);
        if (!encoded) {
            this.encodeResultTarget.textContent = "Combinaison non représentable";
            this.encodeNoteTarget.textContent = "Essayez 5 ou 6 anneaux, ou ajustez la valeur (série E24).";
            this.showResults(null);
            return;
        }

        encoded.bandIds.forEach((id, index) => {
            const select = this.bandSelectTargets.find((element) => Number(element.dataset.bandIndex) === index + 1);
            const role = ROLES[count][index];
            if (select && role) {
                this.fillSelect(select, role, id);
            }
        });
        this.renderPreview(encoded.bandIds, count);

        const names = encoded.bandIds.map((id, index) => colorMeta(ROLES[count][index], id)?.name ?? id);
        this.encodeResultTarget.textContent = french(formatOhms(encoded.ohms));
        this.encodeNoteTarget.textContent = `Code : ${names.join(" — ")}`;

        const tolerance = TOLERANCES.find((t) => t.id === encoded.bandIds[count === 4 ? 3 : 4]);
        const temp = count === 6 ? TEMP_COEFFS.find((t) => t.id === encoded.bandIds[5]) : null;
        this.showResults(encoded.ohms, tolerance?.pct ?? 5, temp?.ppm ?? null);
    }

    /** Conseil de puissance nominale (marge de 2). */
    updatePowerAdvice() {
        const watts = parseNumber(this.targetPowerTarget.value);
        this.powerAdviceTarget.textContent = Number.isFinite(watts) && watts > 0 ? suggestPowerRating(watts) : "Indiquez la puissance dissipée pour une recommandation.";
    }

    /** Affiche valeur, tolérance, plage, E24 la plus proche et encadrantes (B3), coefficient de température. */
    showResults(ohms, tolerancePct = null, tempPpm = null) {
        if (ohms === null) {
            for (const target of [this.resultValueTarget, this.resultToleranceTarget, this.resultRangeTarget, this.nearestE24Target, this.e24NeighborsTarget, this.resultTempTarget]) {
                target.textContent = "—";
            }
            return;
        }
        this.resultValueTarget.textContent = french(formatOhms(ohms));
        this.resultToleranceTarget.textContent = `± ${tolerancePct.toLocaleString("fr-FR")} %`;
        this.resultRangeTarget.textContent = `${french(formatOhms(ohms * (1 - tolerancePct / 100)))} → ${french(formatOhms(ohms * (1 + tolerancePct / 100)))}`;
        this.nearestE24Target.textContent = french(formatOhms(nearestE24(ohms)));
        const { below, above } = e24Neighbors(ohms);
        this.e24NeighborsTarget.textContent = `${french(formatOhms(below))} / ${french(formatOhms(above))}`;
        this.resultTempTarget.textContent = tempPpm ? `${tempPpm} ppm/°C` : "—";
    }

    /** Remplit la liste d'un anneau et sélectionne la couleur voulue (ou la couleur par défaut). */
    fillSelect(select, role, preferredId) {
        const options = optionsForRole(role);
        const band = Number(select.dataset.bandIndex);
        const selected = options.some((o) => o.id === preferredId) ? preferredId : options.some((o) => o.id === DEFAULT_BAND_VALUES[band]) ? DEFAULT_BAND_VALUES[band] : options[0]?.id;
        select.replaceChildren(...options.map((option) => new Option(option.label, option.id)));
        select.value = selected ?? "";
    }

    /** Dessine les anneaux (couleurs de la CEI 60062). */
    renderPreview(bandIds, count) {
        const bands = bandIds.map((id, index) => {
            const role = ROLES[count][index];
            const meta = colorMeta(role, id);
            const band = document.createElement("span");
            band.className = `flex h-20 w-10 items-center justify-center rounded-md border border-secondary-500 text-[10px] font-bold shadow-md sm:h-24 sm:w-12 ${meta?.textClass ?? "text-white"}`;
            band.style.background = meta?.hex ?? "transparent";
            band.textContent = PREVIEW_LABELS[role] ?? String(index + 1);

            return band;
        });
        const body = document.createElement("div");
        body.className = "flex items-center gap-2 rounded-full bg-amber-100 px-6 py-2 dark:bg-amber-900/40";
        body.append(...bands);
        this.previewTarget.replaceChildren(body);
    }

    /** Nombre d'anneaux choisi. */
    count() {
        return Number(this.bandCountTarget.value);
    }

    /** Listes des anneaux utiles, dans l'ordre. */
    visibleSelects(count) {
        return this.bandSelectTargets.filter((select) => Number(select.dataset.bandIndex) <= count).sort((a, b) => Number(a.dataset.bandIndex) - Number(b.dataset.bandIndex));
    }
}
