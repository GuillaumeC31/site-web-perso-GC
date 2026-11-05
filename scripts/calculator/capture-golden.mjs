/*
 * Banc de non-régression de la calculatrice : exécute le code de l'ANCIEN site (lecture seule)
 * sur des jeux d'entrées, et enregistre ses résultats comme valeurs de référence.
 *
 * Les nouveaux modules de calcul (assets/calculators/) doivent retrouver exactement ces
 * valeurs : c'est la preuve des « mêmes résultats qu'aujourd'hui » (CDC § 8).
 *
 * Fonctionnement : chaque ancien contrôleur Stimulus est monté dans un DOM simulé (jsdom),
 * avec un élément par « target » (et ses valeurs Stimulus) ; on remplit les champs, on appelle
 * la méthode de calcul comme le ferait l'ancien formulaire (ou un scénario en plusieurs étapes),
 * puis on lit les résultats. Une librairie pure de l'ancien site (« library ») est appelée directement.
 *
 * Usage (depuis guillaume_c2/) :
 *   node scripts/calculator/capture-golden.mjs ../guillaume_c electricite
 * Écrit : assets/calculators/golden/<categorie>.json (à versionner).
 * Cas d'entrée : scripts/calculator/cases/<categorie>.mjs
 */
import { createRequire } from "node:module";
import { writeFileSync, mkdirSync } from "node:fs";
import { resolve, dirname } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const [oldRootArg, category] = process.argv.slice(2);
if (!oldRootArg || !category) {
    console.error("Usage : node scripts/calculator/capture-golden.mjs <chemin de l'ancien projet> <categorie>");
    process.exit(1);
}

const here = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(here, "../..");
const oldRoot = resolve(oldRootArg);
const require = createRequire(resolve(projectRoot, "package.json"));
const { JSDOM } = require("jsdom");

// DOM simulé, installé avant le chargement de Stimulus (qui lit window et document).
const dom = new JSDOM("<!doctype html><html><body></body></html>", { url: "https://localhost/" });
for (const name of ["window", "document", "Node", "Element", "HTMLElement", "MutationObserver", "Event", "CustomEvent", "KeyboardEvent"]) {
    globalThis[name] = name === "window" ? dom.window : dom.window[name];
}
globalThis.console.log = () => {}; // les anciens contrôleurs écrivent dans la console

// Même instance de Stimulus que celle importée par les anciens contrôleurs.
const { Application } = await import(pathToFileURL(resolve(oldRoot, "node_modules/@hotwired/stimulus/dist/stimulus.js")).href);
const { cases } = await import(pathToFileURL(resolve(here, "cases", `${category}.mjs`)).href);

const application = Application.start(dom.window.document.documentElement);
const results = [];

/** Transforme un nom de valeur Stimulus en attribut (« r0 » → « r0 », « signalMin » → « signal-min »). */
const kebab = (name) => name.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`);

for (const tool of cases) {
    const module = await import(pathToFileURL(resolve(oldRoot, tool.controller ?? tool.library)).href);

    // Librairie pure de l'ancien site : appel direct des fonctions exportées.
    if (tool.library) {
        for (const testCase of tool.cases) {
            const value = module[testCase.fn](...testCase.args);
            results.push({ tool: tool.tool, fn: testCase.fn, args: testCase.args, result: value === undefined ? null : value });
        }
        continue;
    }

    const identifier = tool.identifier;
    if (!application.router.modulesByIdentifier.has(identifier)) {
        // Remplacements (« stub », ex. librairie de graphiques) : propriétés figées, même si l'ancien
        // contrôleur les affecte lui-même au démarrage
        let controllerClass = module.default;
        if (tool.stub) {
            controllerClass = class extends module.default {};
            for (const [name, value] of Object.entries(tool.stub)) {
                Object.defineProperty(controllerClass.prototype, name, { get: () => value, set: () => {} });
            }
        }
        application.register(identifier, controllerClass);
    }

    for (const testCase of tool.cases) {
        // Un élément par target déclaré par l'ancien contrôleur (input, ou select si demandé).
        const root = dom.window.document.createElement("div");
        root.setAttribute("data-controller", identifier);
        // Valeurs Stimulus (ex. R0 d'une sonde PT, bornes d'un signal)
        for (const [name, value] of Object.entries({ ...(tool.values ?? {}), ...(testCase.values ?? {}) })) {
            root.setAttribute(`data-${identifier}-${kebab(name)}-value`, String(value));
        }
        for (const target of module.default.targets ?? []) {
            // Targets d'autres outils du même contrôleur qui déclenchent du code au démarrage
            if ((tool.omit ?? []).includes(target)) {
                continue;
            }
            // select si l'ancien formulaire utilisait une liste, span si le résultat était écrit en texte (innerText, innerHTML)
            const tag = (tool.selects ?? []).includes(target) ? "select" : (tool.spans ?? []).includes(target) ? "span" : "input";
            // Target répété (ex. 10 interrupteurs DIP) : copies dans l'ordre, avec leurs attributs data-* ;
            // l'élément principal (le dernier) reçoit les valeurs initiales ci-dessous
            const count = tool.repeat?.[target]?.count ?? 1;
            for (let index = 0; index < count - 1; index++) {
                const copy = dom.window.document.createElement(tag);
                copy.setAttribute(`data-${identifier}-target`, target);
                for (const [key, value] of Object.entries(tool.repeat[target].data(index))) {
                    copy.setAttribute(`data-${key}`, String(value));
                }
                root.append(copy);
            }
            const element = dom.window.document.createElement(tag);
            element.setAttribute(`data-${identifier}-target`, target);
            for (const [key, value] of Object.entries(tool.repeat?.[target]?.data(count - 1) ?? {})) {
                element.setAttribute(`data-${key}`, String(value));
            }
            if (tag === "select") {
                for (const option of tool.selectOptions?.[target] ?? []) {
                    const node = dom.window.document.createElement("option");
                    // Option simple (« cu ») ou avec attributs data-* (« { value, data: { min: 4 } } »)
                    const spec = typeof option === "object" ? option : { value: option };
                    node.value = String(spec.value);
                    for (const [key, data] of Object.entries(spec.data ?? {})) {
                        node.setAttribute(`data-${key}`, String(data));
                    }
                    element.append(node);
                }
            }
            for (const [name, value] of Object.entries(tool.initial ?? {})) {
                if (name === target) {
                    element.value = String(value);
                }
            }
            root.append(element);
        }
        dom.window.document.body.replaceChildren(root);
        await new Promise((done) => setTimeout(done, 0)); // connexion du contrôleur

        const controller = application.getControllerForElementAndIdentifier(root, identifier);
        const targetElement = (name) => root.querySelector(`[data-${identifier}-target="${name}"]`);
        const setInputs = (inputs) => {
            for (const [name, value] of Object.entries(inputs)) {
                targetElement(name).value = String(value);
            }
        };

        // Scénario en étapes (saisies et appels successifs), ou saisie puis un seul appel.
        const steps = testCase.steps ?? [{ set: { ...(tool.defaults ?? {}), ...testCase.inputs } }, { call: testCase.method, event: testCase.event }];
        for (const step of steps) {
            if (step.set) {
                setInputs(step.set);
            }
            if (step.call) {
                // Événement simulé : champ déclencheur (event) ou paramètres d'action Stimulus (params)
                const source = step.event === undefined ? null : step.eventIndex === undefined ? targetElement(step.event) : root.querySelectorAll(`[data-${identifier}-target="${step.event}"]`)[step.eventIndex];
                const event = source ? { target: source, currentTarget: source } : step.params ? { params: step.params } : undefined;
                controller[step.call](event);
            }
        }

        const outputs = {};
        for (const name of testCase.outputs) {
            // « @interrupteur:state » : attribut data-state de chaque élément du target, dans l'ordre
            if (name.startsWith("@")) {
                const [target, attribute] = name.slice(1).split(":");
                outputs[name] = [...root.querySelectorAll(`[data-${identifier}-target="${target}"]`)].map((element) => element.dataset[attribute]).join(",");
                continue;
            }
            // « !alerte » : l'élément est-il affiché (classe hidden absente) ?
            if (name.startsWith("!")) {
                outputs[name] = targetElement(name.slice(1)).classList.contains("hidden") ? "masqué" : "visible";
                continue;
            }
            const element = targetElement(name);
            outputs[name] = element.tagName === "SPAN" ? String(element.innerText ?? element.textContent).replace(/\s+/g, " ").trim() : element.value;
        }

        results.push(testCase.steps
            ? { tool: tool.tool, steps: testCase.steps, outputs }
            : { tool: tool.tool, method: testCase.method, event: testCase.event ?? null, inputs: { ...(tool.defaults ?? {}), ...testCase.inputs }, outputs });
    }
}

const outputDirectory = resolve(projectRoot, "assets/calculators/golden");
mkdirSync(outputDirectory, { recursive: true });
writeFileSync(resolve(outputDirectory, `${category}.json`), JSON.stringify({ source: "ancien site guillaume_c (capture automatique)", category, results }, null, 2) + "\n");
process.stdout.write(`${results.length} résultats de référence écrits dans assets/calculators/golden/${category}.json\n`);
dom.window.close();
process.exit(0);
