/*
 * Graphique du spectre relatif (illustration, non mesuré) : une seule série en aire, de 380 à 780 nm,
 * dessinée en SVG sans librairie (remplace ApexCharts de l'ancien site).
 *
 * - Trait de 2 px et aire légère à la couleur du texte principal (currentColor), grille discrète ;
 * - bande des couleurs du visible sous l'axe (repère, décorative) ;
 * - info-bulle au survol et au clavier (flèches gauche/droite) : longueur d'onde et intensité ;
 * - tableau des valeurs dans un bloc repliable, pour les lecteurs d'écran et l'impression.
 *
 * Utilisé par : assets/controllers/calculator/eclairage_phyto_controller.js
 * Données : spectrum() de assets/calculators/eclairage.js ({x: nm, y: % du maximum}).
 */
const SVG = "http://www.w3.org/2000/svg";
const WIDTH = 400;
const HEIGHT = 150;
const PAD = { left: 8, right: 8, top: 10, bottom: 34 };

/** Couleurs approximatives du visible, pour la bande repère sous l'axe. */
const VISIBLE = [[380, "#7c3aed"], [450, "#2563eb"], [495, "#0891b2"], [530, "#16a34a"], [580, "#eab308"], [620, "#ea580c"], [680, "#dc2626"], [780, "#7f1d1d"]];

/** Crée un élément SVG avec ses attributs. */
function svg(name, attributes = {}) {
    const element = document.createElementNS(SVG, name);
    for (const [key, value] of Object.entries(attributes)) {
        element.setAttribute(key, String(value));
    }

    return element;
}

/**
 * Dessine (ou redessine) le spectre dans un conteneur.
 *
 * @param {HTMLElement}                    container élément vide qui reçoit le graphique
 * @param {Array<{x: number, y: number}>}  data      points (nm, % du maximum)
 * @param {string}                         label     description courte (aria-label)
 */
export function renderSpectrum(container, data, label) {
    const x = (nm) => PAD.left + ((nm - 380) / 400) * (WIDTH - PAD.left - PAD.right);
    const y = (pct) => PAD.top + (1 - pct / 100) * (HEIGHT - PAD.top - PAD.bottom);
    const baseline = y(0);
    const id = container.dataset.chartId ??= `spectrum-${Math.random().toString(36).slice(2, 8)}`;

    const chart = svg("svg", { viewBox: `0 0 ${WIDTH} ${HEIGHT}`, class: "h-auto w-full max-w-2xl overflow-visible text-primary-700 dark:text-primary-300", role: "img", "aria-label": label, tabindex: 0 });
    const defs = svg("defs");
    const gradient = svg("linearGradient", { id: `${id}-visible`, x1: 0, x2: 1, y1: 0, y2: 0 });
    for (const [nm, color] of VISIBLE) {
        gradient.append(svg("stop", { offset: `${((nm - 380) / 400) * 100}%`, "stop-color": color }));
    }
    defs.append(gradient);
    chart.append(defs);

    // Zone PAR (400–700 nm), repère discret : le PPF ne compte que cette zone
    chart.append(svg("rect", { x: x(400), y: PAD.top, width: x(700) - x(400), height: baseline - PAD.top, class: "fill-secondary-100 dark:fill-secondary-800/60", "aria-hidden": "true" }));
    const parLabel = svg("text", { x: x(550), y: PAD.top + 9, "text-anchor": "middle", class: "fill-secondary-600 text-[8px] dark:fill-secondary-300", "aria-hidden": "true" });
    parLabel.textContent = "PAR 400–700 nm";
    chart.append(parLabel);

    // Grille discrète et axe
    for (const nm of [400, 450, 500, 550, 600, 650, 700, 750]) {
        chart.append(svg("line", { x1: x(nm), x2: x(nm), y1: PAD.top, y2: baseline, class: "stroke-secondary-200 dark:stroke-secondary-700", "stroke-dasharray": "3 3" }));
        const tick = svg("text", { x: x(nm), y: HEIGHT - 4, "text-anchor": "middle", class: "fill-secondary-600 text-[9px] dark:fill-secondary-300" });
        tick.textContent = String(nm);
        chart.append(tick);
    }
    chart.append(svg("rect", { x: x(380), y: baseline + 3, width: x(780) - x(380), height: 6, rx: 2, fill: `url(#${id}-visible)`, "aria-hidden": "true" }));

    // Série : aire et trait
    const points = data.map((point) => `${x(point.x).toFixed(1)},${y(point.y).toFixed(1)}`);
    chart.append(svg("path", { d: `M${x(data[0].x)},${baseline} L${points.join(" L")} L${x(data.at(-1).x)},${baseline} Z`, fill: "currentColor", "fill-opacity": 0.12 }));
    chart.append(svg("path", { d: `M${points.join(" L")}`, fill: "none", stroke: "currentColor", "stroke-width": 2, "stroke-linejoin": "round", "stroke-linecap": "round" }));

    // Info-bulle : repère vertical et valeur du point le plus proche
    const cursor = svg("line", { y1: PAD.top, y2: baseline, class: "stroke-secondary-500", visibility: "hidden" });
    const dot = svg("circle", { r: 4, fill: "currentColor", class: "stroke-white dark:stroke-secondary-900", "stroke-width": 2, visibility: "hidden" });
    const tip = svg("text", { y: PAD.top + 2, "text-anchor": "middle", class: "fill-secondary-900 text-[10px] font-semibold dark:fill-white", visibility: "hidden" });
    chart.append(cursor, dot, tip);
    let current = null;
    const show = (index) => {
        current = Math.max(0, Math.min(data.length - 1, index));
        const point = data[current];
        for (const element of [cursor, dot, tip]) {
            element.setAttribute("visibility", "visible");
        }
        cursor.setAttribute("x1", x(point.x));
        cursor.setAttribute("x2", x(point.x));
        dot.setAttribute("cx", x(point.x));
        dot.setAttribute("cy", y(point.y));
        tip.setAttribute("x", Math.min(WIDTH - 40, Math.max(40, x(point.x))));
        tip.textContent = `${point.x} nm · ${Math.round(point.y)} %`;
    };
    const hide = () => {
        current = null;
        for (const element of [cursor, dot, tip]) {
            element.setAttribute("visibility", "hidden");
        }
    };
    const hit = svg("rect", { x: x(380), y: PAD.top, width: x(780) - x(380), height: baseline - PAD.top, fill: "transparent" });
    hit.addEventListener("pointermove", (event) => {
        const box = chart.getBoundingClientRect();
        const nm = 380 + (((event.clientX - box.left) / box.width) * WIDTH - PAD.left) / (WIDTH - PAD.left - PAD.right) * 400;
        show(Math.round((nm - 380) / 10));
    });
    hit.addEventListener("pointerleave", hide);
    chart.append(hit);
    chart.addEventListener("keydown", (event) => {
        if (event.key === "ArrowRight" || event.key === "ArrowLeft") {
            event.preventDefault();
            show((current ?? 0) + (event.key === "ArrowRight" ? 1 : -1));
        }
    });
    chart.addEventListener("blur", hide);

    // Tableau des valeurs
    const details = document.createElement("details");
    details.className = "mt-2 text-sm text-secondary-700 dark:text-secondary-300";
    const summary = document.createElement("summary");
    summary.className = "cursor-pointer font-medium";
    summary.textContent = "Valeurs du spectre (tableau)";
    const table = document.createElement("table");
    table.className = "mt-2 w-full text-left font-mono text-xs";
    const head = table.createTHead().insertRow();
    for (const text of ["Longueur d'onde (nm)", "Intensité relative (%)"]) {
        const th = document.createElement("th");
        th.scope = "col";
        th.className = "py-1 pr-4";
        th.textContent = text;
        head.append(th);
    }
    const body = table.createTBody();
    for (const point of data) {
        const row = body.insertRow();
        row.insertCell().textContent = String(point.x);
        row.insertCell().textContent = String(Math.round(point.y));
    }
    details.append(summary, table);

    container.replaceChildren(chart, details);
}
