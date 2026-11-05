/*
 * Contrôleur Stimulus « calculator--eclairage-phyto » : éclairage horticole — besoins d'une culture,
 * architecte de spectre (curseurs PAR rééquilibrés à 100 %, UV et far-red à part : D4), simulateur de
 * panneau, DLI et photopériode, ePPF en option, spectres relatifs en SVG.
 *
 * Utilisé par : templates/calculator/tools/eclairage/eclairage-phyto.html.twig
 * Utilise : assets/calculators/eclairage.js, assets/calculators/format.js, assets/lib/spectrum_chart.js
 */
import { Controller } from "@hotwired/stimulus";
import { architect, balanceShares, BANDS, dliFromPpfd, explain, hoursForDli, requirement, simulate, spectrum } from "../../calculators/eclairage.js";
import { formatNumber, parseNumber, showResult } from "../../calculators/format.js";
import { renderSpectrum } from "../../lib/spectrum_chart.js";

/** Description du spectre pour les lecteurs d'écran : bande dominante. */
function describe(weights) {
    const max = Math.max(...weights);
    const dominant = max > 0 ? BANDS[weights.indexOf(max)].label.toLowerCase() : null;

    return dominant ? `Spectre relatif indicatif, dominante ${dominant}` : "Spectre relatif indicatif, vide";
}

export default class extends Controller {
    static targets = [
        "plant", "stage", "area", "hours", "loss", "ppe", "reqPpfd", "reqDli", "reqPpf", "reqWatts", "reqMessage", "reqRange",
        "showEppf", "eppfLine", "archPpf", "archLedWatts", "share", "archUv", "archFarRed", "archMessage", "archLeds", "archTotal", "archWatts", "archPpfOut", "archFarRedOut", "archUvOut", "archEppf", "archChart",
        "simCount", "simPpe", "simLedWatts", "simPpf", "simWatts", "simFarRed", "simUv", "simEppf", "simMessage", "simWarning", "simChart",
        "dliPpfd", "dliHours", "dliResult", "dliMessage", "targetDli", "targetPpfd", "targetHours", "hoursMessage",
    ];

    /** Calcule tous les blocs avec les valeurs par défaut. */
    connect() {
        this.calcReq();
        this.calcArch();
        this.calcSim();
        this.calcDli();
        this.calcHours();
    }

    /** Bloc 1 : besoins de la culture. */
    calcReq() {
        const args = [this.areaTarget, this.hoursTarget, this.ppeTarget].map((target) => parseNumber(target.value));
        const result = requirement(this.plantTarget.value, this.stageTarget.value, args[0], args[1], Number(this.lossTarget.value), args[2]);
        showResult(this.reqPpfdTarget, result?.ppfd ?? null, 0, this.reqMessageTarget, explain("requirement", ...args));
        showResult(this.reqDliTarget, result?.dli ?? null, 1);
        showResult(this.reqPpfTarget, result?.ppf ?? null, 0);
        showResult(this.reqWattsTarget, result?.watts ?? null, 0);
        this.reqRangeTarget.textContent = result ? `Fourchette de PPFD pour cette culture : ${result.range} µmol/m²/s.` : "";
        this.requiredPpf = result?.ppf ?? null;
    }

    /** Reporte le PPF du bloc 1 dans l'architecte, puis y place le focus. */
    transfer() {
        if (this.requiredPpf === null) {
            return;
        }
        this.archPpfTarget.value = String(Math.round(this.requiredPpf));
        this.calcArch();
        const block = this.archPpfTarget.closest("section");
        const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        block.scrollIntoView({ behavior: reduceMotion ? "instant" : "smooth", block: "start" });
        block.focus({ preventScroll: true });
    }

    /** Affiche ou masque l'ePPF (400–750 nm) dans les blocs 2 et 3. */
    toggleEppf() {
        for (const line of this.eppfLineTargets) {
            line.hidden = !this.showEppfTarget.checked;
        }
    }

    /** Parts PAR saisies (%), dans l'ordre des curseurs. */
    shares() {
        return this.shareTargets.map((target) => Number(target.value));
    }

    /** Curseur déplacé : les autres se partagent le reste, puis recalcul. */
    balance(event) {
        const shares = balanceShares(this.shares(), Number(event.target.dataset.index));
        this.shareTargets.forEach((target, index) => {
            target.value = String(shares[index]);
        });
        this.calcArch();
    }

    /** Bloc 2 : LED par bande, total, puissance et spectre. */
    calcArch() {
        const shares = this.shares();
        shares.forEach((share, index) => {
            this.element.querySelector(`[data-share-label="${index}"]`).textContent = `${share} %`;
        });
        const ppf = parseNumber(this.archPpfTarget.value);
        const ledWatts = parseNumber(this.archLedWattsTarget.value);
        const [uv, farRed] = [this.archUvTarget, this.archFarRedTarget].map((target) => parseNumber(target.value));
        const result = architect(ppf, ledWatts, shares, uv, farRed);
        const error = explain("architect", ppf, ledWatts, uv, farRed) ?? (result && result.bands.length === 0 ? `La somme des parts PAR doit faire 100 % (actuellement ${result.sum} %).` : null);
        this.archMessageTarget.hidden = error === null;
        this.archMessageTarget.textContent = error ?? "";

        const ok = result !== null && result.bands.length > 0;
        this.archLedsTargets.forEach((cell, index) => {
            cell.textContent = ok ? String(result.bands[index].leds) : "—";
        });
        this.archTotalTarget.textContent = ok ? String(result.totalLeds) : "—";
        this.archWattsTarget.textContent = ok ? `${formatNumber(result.totalWatts, 0)} W` : "—";
        this.archPpfOutTarget.textContent = ok ? formatNumber(result.ppf, 0) : "—";
        this.archFarRedOutTarget.textContent = ok ? formatNumber(result.farRedFlux, 0) : "—";
        this.archUvOutTarget.textContent = ok ? formatNumber(result.uvFlux, 0) : "—";
        this.archEppfTarget.textContent = ok ? formatNumber(result.eppf, 0) : "—";
        const weights = ok ? result.bands.map((band) => band.leds) : BANDS.map(() => 0);
        renderSpectrum(this.archChartTarget, spectrum(weights), describe(weights));
    }

    /** Bloc 3 : PPF et puissance du panneau, alerte photobiologique, spectre. */
    calcSim() {
        const counts = this.simCountTargets.map((target) => parseNumber(target.value));
        const ppe = parseNumber(this.simPpeTarget.value);
        const ledWatts = parseNumber(this.simLedWattsTarget.value);
        const result = simulate(counts, ppe, ledWatts);
        showResult(this.simPpfTarget, result?.ppf ?? null, 0, this.simMessageTarget, explain("simulate", counts, ppe, ledWatts));
        showResult(this.simWattsTarget, result?.watts ?? null, 0);
        showResult(this.simFarRedTarget, result?.farRedFlux ?? null, 0);
        showResult(this.simUvTarget, result?.uvFlux ?? null, 0);
        showResult(this.simEppfTarget, result?.eppf ?? null, 0);
        this.simWarningTarget.hidden = !result?.photobiologicalWarning;
        const weights = result ? counts : counts.map(() => 0);
        renderSpectrum(this.simChartTarget, spectrum(weights), describe(weights));
    }

    /** Bloc 4a : DLI depuis le PPFD. */
    calcDli() {
        const args = [this.dliPpfdTarget, this.dliHoursTarget].map((target) => parseNumber(target.value));
        showResult(this.dliResultTarget, dliFromPpfd(...args), 1, this.dliMessageTarget, explain("dli", ...args));
    }

    /** Bloc 4b : heures pour un DLI visé. */
    calcHours() {
        const args = [this.targetDliTarget, this.targetPpfdTarget].map((target) => parseNumber(target.value));
        showResult(this.targetHoursTarget, hoursForDli(...args), 1, this.hoursMessageTarget, explain("dliHours", ...args));
    }
}
