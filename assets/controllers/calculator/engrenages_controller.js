/*
 * Contrôleur Stimulus « calculator--engrenages » : train d'engrenages simple ou à deux étages,
 * avec les alertes d'interférence (B4 : seuils calculés depuis l'angle de pression) et de dent de chasse.
 *
 * Utilisé par : templates/calculator/tools/mecanique/engrenages.html.twig
 * Utilise : assets/calculators/mecanique.js, assets/calculators/format.js
 */
import { Controller } from "@hotwired/stimulus";
import { DECIMALS, explain, gearTrain, undercutLimits } from "../../calculators/mecanique.js";
import { formatNumber, parseNumber, showResult } from "../../calculators/format.js";

/** Styles des alertes, par niveau. */
const ALERT_STYLES = {
    danger: "rounded-lg border-l-4 border-red-500 bg-red-50 p-3 text-sm text-red-900 dark:bg-red-950/50 dark:text-red-100",
    success: "rounded-lg border-l-4 border-emerald-500 bg-emerald-50 p-3 text-sm text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100",
    warning: "rounded-lg border-l-4 border-amber-500 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/50 dark:text-amber-100",
};

export default class extends Controller {
    static targets = ["z1", "z2", "z3", "z4", "angle", "limits", "speed", "torque", "efficiency", "ratio", "speedOut", "torqueOut", "message", "alerts"];

    /** Recalcule le train d'engrenages. */
    calculate() {
        const teeth = [this.z1Target, this.z2Target, this.z3Target, this.z4Target].map((target) => parseNumber(target.value));
        const speed = parseNumber(this.speedTarget.value);
        const torque = parseNumber(this.torqueTarget.value);
        const efficiency = parseNumber(this.efficiencyTarget.value);
        const angle = parseNumber(this.angleTarget.value);
        const result = gearTrain(...teeth, speed, torque, efficiency, angle);
        const error = explain("gearTrain", ...teeth) ?? explain("gearAngle", angle) ?? explain("gearSpeed", speed) ?? explain("gearTorque", torque, efficiency);

        this.renderLimits(angle);
        this.ratioTarget.textContent = result ? `1 : ${formatNumber(result.reduction, DECIMALS.ratio)}` : "—";
        showResult(this.speedOutTarget, result?.speedOut ?? null, DECIMALS.speed, this.messageTarget, error);
        showResult(this.torqueOutTarget, result?.torqueOut ?? null, DECIMALS.torque);
        this.renderAlerts(result);
    }

    /** Affiche les nombres de dents minimaux pour l'angle de pression saisi. */
    renderLimits(angle) {
        if (explain("gearAngle", angle) !== null || Number.isNaN(angle)) {
            this.limitsTarget.textContent = "—";
            return;
        }
        const { exact, theoretical, practical } = undercutLimits(angle);
        this.limitsTarget.textContent = `z théorique = 2 / sin²(${formatNumber(angle, 1)}°) = ${formatNumber(exact, 1)} → ${theoretical} dents · z pratique ≈ 5/6 × ${formatNumber(exact, 1)} → ${practical} dents`;
    }

    /** Affiche les alertes de denture. */
    renderAlerts(result) {
        const alerts = [];
        const undercut = result?.undercut;
        if (undercut?.level === "strong") {
            alerts.push(["danger", "Interférence de taillage", `La plus petite roue a ${undercut.minTeeth} dents, sous la limite pratique de ${undercut.practical} dents : pied de dent creusé et affaibli. Augmentez le nombre de dents, l'angle de pression ou prévoyez un déport de denture.`]);
        } else if (undercut?.level === "warning") {
            alerts.push(["warning", "Risque d'interférence", `La plus petite roue a ${undercut.minTeeth} dents, sous la limite théorique de ${undercut.theoretical} dents : léger creusement du pied de dent possible (toléré jusqu'à ${undercut.practical} dents).`]);
        }
        if (result?.huntingTooth) {
            alerts.push(["success", "Dent de chasse optimale", "Les nombres de dents sont premiers entre eux : l'usure sera répartie sur toutes les dents."]);
        } else if (result) {
            alerts.push(["warning", "Usure localisée", `Paires non premières entre elles (PGCD = ${result.gcds.join(" et ")}) : chaque dent touchera souvent les mêmes dents en face. Ajouter ou retirer une dent améliore la répartition de l'usure.`]);
        }

        this.alertsTarget.replaceChildren(
            ...alerts.map(([level, title, text]) => {
                const item = document.createElement("li");
                item.className = ALERT_STYLES[level];
                const strong = document.createElement("strong");
                strong.textContent = `${title} : `;
                item.append(strong, text);

                return item;
            }),
        );
    }
}
