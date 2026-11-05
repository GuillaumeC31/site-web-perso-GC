/*
 * Jeux d'entrées de la catégorie Thermodynamique, pour le banc capture-golden.mjs.
 *
 * - Chauffe d'un volume d'eau, gaz parfaits, point de rosée (blocs 1 et 2) : contrôleur « calculator ».
 *   Le bloc 3 (psychromètre) n'est pas capturable : ses champs ne sont pas déclarés dans l'ancien
 *   contrôleur, il ne fonctionnait pas (docs/CALCULATRICE_ANOMALIES.md).
 * - Chauffe d'un fluide (fluid-heating), fluides frigorigènes (fluides-frigo).
 * - Chambre froide et enceinte climatique : valeurs par défaut des anciens formulaires (« initial »),
 *   modes simple et pro. L'ancien gabarit n'avait pas tous les éléments attendus par le contrôleur
 *   (l'affichage s'arrêtait en erreur) : le banc crée tous les éléments déclarés, on capture donc les
 *   valeurs calculées par l'ancien code.
 * - Librairie psychrometrics.js (utilisée par les deux derniers outils) : appels directs.
 */
const calculator = "assets/controllers/calculator_controller.js";
const omit = ["convType"];

/** Options des listes de la chambre froide et de l'enceinte (valeurs de l'ancien gabarit). */
const productProfiles = ["fresh_generic", "meat", "fish", "dairy", "vegetables", "bakery", "beverages", "frozen", "pharma_cold", "vaccines", "blood_plasma", "pharma_ambient", "cosmetics", "chemicals", "solvents", "lab_reagents", "plastics", "metals", "cardboard", "textile", "electronics", "flowers", "wine", "ice", "custom"];
const panelBrands = ["generic_pur", "generic_pir", "isocab", "arcus", "laine_roche"];
const coldRoomSelects = {
    sUsage: ["storage", "prep"], sRegime: ["positive", "positive0", "negative", "negative25"], sAirlock: ["no", "yes"],
    sLoadMode: ["empty", "loaded"], sPanelBrand: panelBrands, sThickness: ["80", "100", "120", "150", "200"],
    sFloorInsulated: ["yes", "no"], sGlazing: ["no", "yes"], sProductProfile: productProfiles,
    sFluid: ["R448A", "R449A", "R404A", "R290", "R744", "R717"],
    pUsage: ["storage", "prep"], pAirlock: ["no", "yes"], pPanelBrand: panelBrands, pThickness: ["100", "120", "150", "200"],
    pFloorInsulated: ["yes", "no"], pGlazing: ["no", "yes"], pDoorType: ["swing", "sliding", "sectional", "rapid", "pivot"],
    pDoorJoints: ["3", "4"], pDoorPorthole: ["no", "yes"], pLightType: ["led_sealed", "led_std", "fluorescent", "hps", "none"],
    pLightDefrost: ["no", "yes"], pProductProfile: productProfiles, pFreezingInRoom: ["no", "yes"],
    pPeopleActivity: ["rest", "light", "moderate", "heavy"], pFluid: ["R448A", "R449A", "R290", "R744", "R717"],
};
const coldRoomInitial = {
    sLength: 4, sWidth: 3, sHeight: 2.5, sUsage: "storage", sRegime: "negative", sAirlock: "no", sLoadMode: "loaded",
    sPanelBrand: "generic_pir", sThickness: "120", sPanelU: 0.16, sFloorInsulated: "yes", sGlazing: "no", sGlazingArea: 0,
    sAirRenewal: 0, sAirRhExt: 50, sAirRhSet: 85, sProductProfile: "fresh_generic", sProductCp: 3.5, sProductLatent: 250,
    sProductWaterPct: 75, sDailyKg: 500, sEntryTemp: 10, sPullDownHours: 12, sRunHours: 18, sMargin: 15, sFluid: "R448A",
    pLength: 4, pWidth: 3, pHeight: 2.5, pUsage: "storage", pTSet: -18, pAirlock: "no", pBridgePct: 10, pPanelBrand: "generic_pir",
    pThickness: "120", pPanelU: 0.16, pFloorInsulated: "yes", pTExtCeiling: 32, pTExtWall: 25, pTExtFloor: 15, pGlazing: "no",
    pGlazingArea: 0, pGlazingU: 3, pDoorCount: 1, pDoorW: 0.9, pDoorH: 2, pDoorType: "swing", pDoorU: 0.55, pDoorJoints: "4",
    pDoorPorthole: "no", pDoorPortholeArea: 0.15, pDoorPortholeU: 3.5, pDoorFrameU: 1.2, pOpeningsPerDay: 20, pOpenDuration: 30,
    pDoorActivityHours: 8, pDoorLocalTemp: 25, pLightType: "led_sealed", pLightCount: 4, pLightUnitW: 18, pLighting: 10,
    pLightHours: 12, pLightSimult: 100, pLightDefrost: "no", pProductProfile: "fresh_generic", pProductCp: 3.5, pProductLatent: 250,
    pProductWaterPct: 75, pStoredKg: 2000, pDailyKg: 500, pEntryTemp: 10, pFreezingInRoom: "no", pPullDownHours: 12, pPeople: 1,
    pPeopleHours: 4, pPeopleActivity: "light", pEquipment: 0, pAirRenewal: 0, pAirTExt: 25, pAirRhExt: 50, pAirRhSet: 85,
    pDefrostPower: 3, pDefrostMin: 20, pDefrostCycles: 3, pRunHours: 18, pMargin: 15, pFluid: "R448A", pCopOverride: 0,
};
const coldRoomOutputs = ["resTransmission", "resProduct", "resInfiltration", "resInternal", "resAir", "resDefrost", "resDoorTrans", "resLighting", "resPeople", "resAirSensible", "resAirLatent", "resTotalW", "resFrigoKw", "resElecKw", "resVolume", "resSurface"];
const coldRoomSpans = [...coldRoomOutputs, "panelInfo", "sProductHint", "pProductHint", "resBreakdownBody"];
const coldSimple = (set) => ({ steps: [{ set }, { call: "calculate" }], outputs: coldRoomOutputs });
const coldPro = (set) => ({ steps: [{ call: "setMode", params: { mode: "pro" } }, { set }, { call: "calculate" }], outputs: coldRoomOutputs });
const coldProfile = (prefix, profile, set = {}) => ({
    steps: [...(prefix === "pro" ? [{ call: "setMode", params: { mode: "pro" } }] : []), { set: { [`${prefix === "pro" ? "p" : "s"}ProductProfile`]: profile } }, { call: "onProductProfileChange", params: { prefix } }, { set }, { call: "calculate" }],
    outputs: coldRoomOutputs,
});

const ichProfiles = ["long_term", "accelerated", "intermediate", "refrigerated", "freezer", "custom"];
const chamberSelects = {
    sIchProfile: ichProfiles, sHumidType: ["boiler", "steam", "ultrasonic", "atomization"],
    sDehumidMethod: ["refrigeration", "desiccant", "electric", "heat_pump", "dry_air"],
    pStabilityClass: ["0.5", "1", "2"], pIchProfile: ichProfiles, pHumidType: ["boiler", "steam", "external_steam", "ultrasonic", "atomization"],
    pDehumidMethod: ["refrigeration", "desiccant", "electric", "heat_pump", "dry_air"], pAirRecovery: ["no", "yes"], pRunMode: ["continuous", "batch"],
};
const chamberInitial = {
    sLength: 2, sWidth: 1.5, sHeight: 2, sUEnvelope: 0.35, sLeakRate: 0.5, sLightingW: 50, sIchProfile: "long_term", sTSet: 25, sRhSet: 60,
    sRhStart: 40, sHumidType: "boiler", sHumidTime: 30, sDehumidMethod: "refrigeration", sDehumidRhTarget: 40, sAirRenewal: 20, sSampleKg: 0,
    pLength: 2, pWidth: 1.5, pHeight: 2, pUEnvelope: 0.35, pLeakRate: 0.3, pTExt: 25, pStabilityClass: "1", pIchProfile: "long_term",
    pTSet: 25, pRhSet: 60, pRhStart: 40, pRampT: 1, pRampRh: 2, pDoorCount: 1, pDoorOpenings: 5, pDoorDuration: 20, pLightingW: 40,
    pPeople: 0, pPeopleMinutes: 30, pSampleKg: 0, pSampleCp: 1.5, pSampleDeltaT: 0, pCableW: 10, pHumidType: "boiler", pHumidTime: 30,
    pWaterTemp: 20, pDehumidMethod: "refrigeration", pDehumidRhTarget: 40, pEvapTemp: 15, pAirRenewal: 20, pAirRecovery: "no",
    pRunMode: "continuous", pMargin: 10, pHeatSafety: 10, pCoolSafety: 10,
};
const chamberOutputs = ["resVolume", "resDewPoint", "resColdKw", "resHeatKw", "resHumidRate", "resHumidKw", "resDehumidRate", "resDehumidKw", "resTransW", "resInternalW", "resLeakW", "resSampleW", "resRampTimeRh", "resEnergyKwh"];
const chamberSpans = [...chamberOutputs, "profileHint", "humidHint", "dehumidHint", "resBreakdownBody"];
const chamberSimple = (set) => ({ steps: [{ set }, { call: "calculate" }], outputs: chamberOutputs });
const chamberPro = (set) => ({ steps: [{ call: "setMode", params: { mode: "pro" } }, { set }, { call: "calculate" }], outputs: chamberOutputs });
const chamberProfile = (prefix, profile, set = {}) => ({
    steps: [...(prefix === "pro" ? [{ call: "setMode", params: { mode: "pro" } }] : []), { set: { [`${prefix === "pro" ? "p" : "s"}IchProfile`]: profile } }, { call: "onIchChange", params: { prefix } }, { set }, { call: "calculate" }],
    outputs: chamberOutputs,
});

/** Fluides de l'ancien site pour « Chauffe d'un fluide » (eau, huile thermique, glycol, air). */
const fluidOptions = [
    { value: "1", data: { cp: 4185, rho: 1000 } },
    { value: "3", data: { cp: 2000, rho: 850 } },
    { value: "5", data: { cp: 2400, rho: 1113 } },
    { value: "13", data: { cp: 1005, rho: 1.204 } },
    { value: "custom", data: { cp: "", rho: "" } },
];
const heating = (set, call = "calculate", extra = []) => ({ steps: [{ set }, { call }, ...extra], outputs: ["energyResult", "power", "time"] });

export const cases = [
    {
        tool: "chauffe-eau",
        controller: calculator,
        identifier: "calculator",
        omit,
        cases: [
            { method: "calculateWaterTime", inputs: { vWater: 200, tStartWater: 20, tEndWater: 60, pWater: 3000 }, outputs: ["resultEWaterTime", "resultTimeWater"] },
            { method: "calculateWaterTime", inputs: { vWater: 150, tStartWater: 12.5, tEndWater: 55, pWater: 2200 }, outputs: ["resultEWaterTime", "resultTimeWater"] },
            { method: "calculateWaterTime", inputs: { vWater: 200, tStartWater: 60, tEndWater: 20, pWater: 3000 }, outputs: ["resultEWaterTime", "resultTimeWater"] },
            { method: "calculateWaterTime", inputs: { vWater: 200, tStartWater: 20, tEndWater: 60, pWater: "" }, outputs: ["resultEWaterTime", "resultTimeWater"] },
            { method: "calculateWaterTime", inputs: { vWater: 200, tStartWater: 20, tEndWater: 60, pWater: 0 }, outputs: ["resultEWaterTime", "resultTimeWater"] },
            { method: "calculateWaterTime", inputs: { vWater: 200, tStartWater: 20, tEndWater: 60, pWater: -3000 }, outputs: ["resultEWaterTime", "resultTimeWater"] },
            { method: "calculateWaterTime", inputs: { vWater: -200, tStartWater: 20, tEndWater: 60, pWater: 3000 }, outputs: ["resultEWaterTime", "resultTimeWater"] },
            { method: "calculateWaterTime", inputs: { vWater: "", tStartWater: 20, tEndWater: 60, pWater: 3000 }, outputs: ["resultEWaterTime", "resultTimeWater"] },
            { method: "calculateWaterPower", inputs: { vWaterPow: 200, tStartWaterPow: 20, tEndWaterPow: 60, timeWaterPow: 2.5 }, outputs: ["resultEWaterPow", "resultPowWater"] },
            { method: "calculateWaterPower", inputs: { vWaterPow: 300, tStartWaterPow: 10, tEndWaterPow: 65, timeWaterPow: 6 }, outputs: ["resultEWaterPow", "resultPowWater"] },
            { method: "calculateWaterPower", inputs: { vWaterPow: 200, tStartWaterPow: 20, tEndWaterPow: 60, timeWaterPow: 0 }, outputs: ["resultEWaterPow", "resultPowWater"] },
            { method: "calculateWaterPower", inputs: { vWaterPow: 200, tStartWaterPow: 20, tEndWaterPow: 60, timeWaterPow: -2 }, outputs: ["resultEWaterPow", "resultPowWater"] },
            { method: "calculateWaterPower", inputs: { vWaterPow: 200, tStartWaterPow: 20, tEndWaterPow: "", timeWaterPow: 2 }, outputs: ["resultEWaterPow", "resultPowWater"] },
        ],
    },
    {
        tool: "gaz-parfaits",
        controller: calculator,
        identifier: "calculator",
        omit,
        cases: [
            { method: "calculateGas", inputs: { nGas: 1, tGas: 0, vGas: 22.4 }, outputs: ["resultGas"] },
            { method: "calculateGas", inputs: { nGas: 1, tGas: 20, vGas: 22.4 }, outputs: ["resultGas"] },
            { method: "calculateGas", inputs: { nGas: 2.5, tGas: 80, vGas: 10 }, outputs: ["resultGas"] },
            { method: "calculateGas", inputs: { nGas: 0.5, tGas: -40, vGas: 1.5 }, outputs: ["resultGas"] },
            { method: "calculateGas", inputs: { nGas: 1, tGas: 20, vGas: 0 }, outputs: ["resultGas"] },
            { method: "calculateGas", inputs: { nGas: -1, tGas: 20, vGas: 22.4 }, outputs: ["resultGas"] },
            { method: "calculateGas", inputs: { nGas: 1, tGas: -300, vGas: 22.4 }, outputs: ["resultGas"] },
            { method: "calculateGas", inputs: { nGas: 1, tGas: 20, vGas: -5 }, outputs: ["resultGas"] },
            { method: "calculateGas", inputs: { nGas: "", tGas: 20, vGas: 22.4 }, outputs: ["resultGas"] },
        ],
    },
    {
        tool: "point-rosee",
        controller: calculator,
        identifier: "calculator",
        omit,
        cases: [
            { method: "calculatePsy1", inputs: { psyT1: 20, psyHr1: 50 }, outputs: ["psyTd1", "psyHa1", "psyPv1"] },
            { method: "calculatePsy1", inputs: { psyT1: 25, psyHr1: 60 }, outputs: ["psyTd1", "psyHa1", "psyPv1"] },
            { method: "calculatePsy1", inputs: { psyT1: -5, psyHr1: 80 }, outputs: ["psyTd1", "psyHa1", "psyPv1"] },
            { method: "calculatePsy1", inputs: { psyT1: 35.5, psyHr1: 95 }, outputs: ["psyTd1", "psyHa1", "psyPv1"] },
            { method: "calculatePsy1", inputs: { psyT1: 20, psyHr1: 100 }, outputs: ["psyTd1", "psyHa1", "psyPv1"] },
            { method: "calculatePsy1", inputs: { psyT1: 20, psyHr1: 0 }, outputs: ["psyTd1", "psyHa1", "psyPv1"] },
            { method: "calculatePsy1", inputs: { psyT1: 20, psyHr1: 120 }, outputs: ["psyTd1", "psyHa1", "psyPv1"] },
            { method: "calculatePsy1", inputs: { psyT1: 20, psyHr1: -10 }, outputs: ["psyTd1", "psyHa1", "psyPv1"] },
            { method: "calculatePsy1", inputs: { psyT1: "", psyHr1: 50 }, outputs: ["psyTd1", "psyHa1", "psyPv1"] },
            { method: "calculatePsy2", inputs: { psyT2: 20, psyTd2: 9.3 }, outputs: ["psyHr2", "psyHa2"] },
            { method: "calculatePsy2", inputs: { psyT2: 25, psyTd2: 16.7 }, outputs: ["psyHr2", "psyHa2"] },
            { method: "calculatePsy2", inputs: { psyT2: 20, psyTd2: 20 }, outputs: ["psyHr2", "psyHa2"] },
            { method: "calculatePsy2", inputs: { psyT2: 5, psyTd2: -3 }, outputs: ["psyHr2", "psyHa2"] },
            { method: "calculatePsy2", inputs: { psyT2: 20, psyTd2: 25 }, outputs: ["psyHr2", "psyHa2"] },
            { method: "calculatePsy2", inputs: { psyT2: 20, psyTd2: "" }, outputs: ["psyHr2", "psyHa2"] },
        ],
    },
    {
        tool: "chauffe-fluide",
        controller: "assets/controllers/fluid_heating_controller.js",
        identifier: "fluid-heating",
        selects: ["fluidType"],
        selectOptions: { fluidType: fluidOptions },
        spans: ["energyResult"],
        initial: { fluidType: "1", cp: 4185, rho: 1000, tempStart: 20, tempEnd: 60 },
        cases: [
            heating({ volume: 200 }),
            heating({ volume: 200, power: 3000 }),
            heating({ volume: 200, time: 2.5 }),
            heating({ volume: 1000, tempStart: 15, tempEnd: 85, power: 12000 }),
            heating({ volume: 200, tempStart: 60, tempEnd: 20, power: 3000 }),
            heating({ volume: 1, tempStart: 20, tempEnd: 21, power: 100 }),
            heating({ volume: 200, power: 0 }, "calculateTime", []),
            { steps: [{ set: { volume: 200 } }, { call: "calculate" }, { set: { power: 3000 } }, { call: "calculateTime", event: "power" }], outputs: ["energyResult", "power", "time"] },
            { steps: [{ set: { volume: 200 } }, { call: "calculate" }, { set: { time: 4 } }, { call: "calculatePower", event: "time" }], outputs: ["energyResult", "power", "time"] },
            { steps: [{ set: { volume: 200 } }, { call: "calculate" }, { set: { time: -1 } }, { call: "calculatePower", event: "time" }], outputs: ["energyResult", "power", "time"] },
            { steps: [{ set: { fluidType: "3" } }, { call: "changeFluid" }, { set: { volume: 500, tempStart: 20, tempEnd: 180, power: 9000 } }, { call: "calculate" }], outputs: ["energyResult", "power", "time", "cp", "rho"] },
            { steps: [{ set: { fluidType: "5" } }, { call: "changeFluid" }, { set: { volume: 100, time: 1.5 } }, { call: "calculate" }], outputs: ["energyResult", "power", "time", "cp", "rho"] },
            { steps: [{ set: { fluidType: "13" } }, { call: "changeFluid" }, { set: { volume: 50000, tempStart: 10, tempEnd: 35, power: 2000 } }, { call: "calculate" }], outputs: ["energyResult", "power", "time", "cp", "rho"] },
            { steps: [{ set: { fluidType: "custom" } }, { call: "changeFluid" }, { set: { cp: 3800, rho: 1050, volume: 80, power: 1500 } }, { call: "calculate" }], outputs: ["energyResult", "power", "time"] },
            heating({ volume: -200, power: 3000 }),
            heating({ volume: "" }),
        ],
    },
    {
        tool: "fluides-frigorifiques",
        controller: "assets/controllers/fluides_frigo_controller.js",
        identifier: "fluides-frigo",
        selects: ["fluidSelect"],
        selectOptions: { fluidSelect: ["R12", "R22", "R134a", "R404A", "R407C", "R410A", "R32", "R454A", "R454B", "R454C", "R455A", "R1234yf", "R1234ze", "R290", "R600a", "R744", "R717"] },
        spans: ["gwpDisplay", "teqDisplay", "statusDisplay", "tableBody"],
        omit: ["filterFamily", "filterStatus", "filterClass"],
        cases: [
            { method: "calculate", inputs: { fluidSelect: "R410A", chargeInput: 2.5 }, outputs: ["gwpDisplay", "teqDisplay", "statusDisplay"] },
            { method: "calculate", inputs: { fluidSelect: "R404A", chargeInput: 10 }, outputs: ["gwpDisplay", "teqDisplay", "statusDisplay"] },
            { method: "calculate", inputs: { fluidSelect: "R22", chargeInput: 3 }, outputs: ["gwpDisplay", "teqDisplay", "statusDisplay"] },
            { method: "calculate", inputs: { fluidSelect: "R32", chargeInput: 1.2 }, outputs: ["gwpDisplay", "teqDisplay", "statusDisplay"] },
            { method: "calculate", inputs: { fluidSelect: "R134a", chargeInput: 3.5 }, outputs: ["gwpDisplay", "teqDisplay", "statusDisplay"] },
            { method: "calculate", inputs: { fluidSelect: "R290", chargeInput: 0.15 }, outputs: ["gwpDisplay", "teqDisplay", "statusDisplay"] },
            { method: "calculate", inputs: { fluidSelect: "R744", chargeInput: 50 }, outputs: ["gwpDisplay", "teqDisplay", "statusDisplay"] },
            { method: "calculate", inputs: { fluidSelect: "R717", chargeInput: 100 }, outputs: ["gwpDisplay", "teqDisplay", "statusDisplay"] },
            { method: "calculate", inputs: { fluidSelect: "R1234yf", chargeInput: 0.6 }, outputs: ["gwpDisplay", "teqDisplay", "statusDisplay"] },
            { method: "calculate", inputs: { fluidSelect: "R410A", chargeInput: "" }, outputs: ["gwpDisplay", "teqDisplay", "statusDisplay"] },
            { method: "calculate", inputs: { fluidSelect: "R410A", chargeInput: -5 }, outputs: ["gwpDisplay", "teqDisplay", "statusDisplay"] },
        ],
    },
    {
        tool: "chambre-froide",
        controller: "assets/controllers/chambre_froide_controller.js",
        identifier: "chambre-froide",
        selects: Object.keys(coldRoomSelects),
        selectOptions: coldRoomSelects,
        spans: coldRoomSpans,
        initial: coldRoomInitial,
        cases: [
            coldSimple({}),
            coldSimple({ sRegime: "positive" }),
            coldSimple({ sRegime: "positive0", sUsage: "prep" }),
            coldSimple({ sRegime: "negative25", sAirlock: "yes" }),
            coldSimple({ sLoadMode: "empty" }),
            coldSimple({ sLength: 6, sWidth: 5, sHeight: 3, sFloorInsulated: "no", sGlazing: "yes", sGlazingArea: 2 }),
            coldSimple({ sRegime: "positive", sAirRenewal: 100, sAirRhExt: 60, sAirRhSet: 85 }),
            coldSimple({ sRegime: "negative", sAirRenewal: 50 }),
            coldSimple({ sFluid: "R290", sRunHours: 16, sMargin: 10 }),
            coldSimple({ sMargin: 0 }),
            coldSimple({ sLength: "", sWidth: 3, sHeight: 2.5 }),
            coldSimple({ sLength: -4 }),
            coldProfile("simple", "meat"),
            coldProfile("simple", "vegetables", { sRegime: "positive" }),
            coldProfile("simple", "frozen", { sRegime: "negative25" }),
            coldProfile("simple", "pharma_cold", { sRegime: "positive" }),
            { steps: [{ set: { sPanelBrand: "laine_roche", sThickness: "200" } }, { call: "onPanelChange" }], outputs: [...coldRoomOutputs, "sPanelU", "panelInfo"] },
            { steps: [{ set: { sPanelBrand: "isocab", sThickness: "100" } }, { call: "onPanelChange" }], outputs: [...coldRoomOutputs, "sPanelU", "panelInfo"] },
            coldPro({}),
            coldPro({ pTSet: 2 }),
            coldPro({ pTSet: 0 }),
            coldPro({ pTSet: 4, pFloorInsulated: "no", pTExtFloor: 12 }),
            coldPro({ pBridgePct: 0, pGlazing: "yes", pGlazingArea: 1.5, pGlazingU: 1.1 }),
            coldPro({ pDoorCount: 2, pDoorW: 1.2, pDoorH: 2.2, pDoorJoints: "3", pDoorPorthole: "yes" }),
            { steps: [{ call: "setMode", params: { mode: "pro" } }, { set: { pDoorType: "rapid" } }, { call: "onDoorTypeChange" }], outputs: [...coldRoomOutputs, "pDoorU"] },
            coldPro({ pAirlock: "yes", pOpeningsPerDay: 60, pOpenDuration: 15, pDoorActivityHours: 10 }),
            coldPro({ pLightCount: 0, pLighting: 12 }),
            coldPro({ pLightDefrost: "yes", pLightSimult: 50 }),
            coldPro({ pPeople: 3, pPeopleHours: 6, pPeopleActivity: "moderate", pEquipment: 500 }),
            coldPro({ pDailyKg: 0, pStoredKg: 3000 }),
            coldPro({ pDefrostPower: 0 }),
            coldPro({ pAirRenewal: 200, pAirTExt: 30, pAirRhExt: 70, pAirRhSet: 90 }),
            coldPro({ pCopOverride: 2.5, pFluid: "R744" }),
            coldPro({ pRunHours: 20, pMargin: 0 }),
            { ...coldProfile("pro", "ice", { pDailyKg: 200, pEntryTemp: 15 }) },
            { ...coldProfile("pro", "fish", { pTSet: -25, pFreezingInRoom: "yes" }) },
            coldPro({ pLength: 10, pWidth: 8, pHeight: 4, pTSet: -30, pPanelU: 0.1 }),
        ],
    },
    {
        tool: "enceinte-climatique",
        controller: "assets/controllers/enceinte_climatique_controller.js",
        identifier: "enceinte-climatique",
        selects: Object.keys(chamberSelects),
        selectOptions: chamberSelects,
        spans: chamberSpans,
        initial: chamberInitial,
        cases: [
            chamberSimple({}),
            chamberProfile("simple", "accelerated"),
            chamberProfile("simple", "intermediate"),
            chamberProfile("simple", "refrigerated"),
            chamberProfile("simple", "freezer"),
            chamberSimple({ sHumidType: "ultrasonic" }),
            chamberSimple({ sHumidType: "steam", sHumidTime: 60 }),
            chamberSimple({ sDehumidMethod: "desiccant", sDehumidRhTarget: 30 }),
            chamberSimple({ sDehumidMethod: "dry_air" }),
            chamberSimple({ sLength: 3, sWidth: 2, sHeight: 2.2, sUEnvelope: 0.25, sLeakRate: 1, sLightingW: 100 }),
            chamberSimple({ sTSet: 0 }),
            chamberSimple({ sRhStart: 70 }),
            chamberSimple({ sLength: "" }),
            chamberPro({}),
            chamberPro({ pStabilityClass: "0.5" }),
            chamberPro({ pStabilityClass: "2", pMargin: 20 }),
            chamberPro({ pAirRecovery: "yes" }),
            chamberPro({ pPeople: 2, pPeopleMinutes: 60 }),
            chamberPro({ pSampleKg: 50, pSampleCp: 2, pSampleDeltaT: 20 }),
            chamberPro({ pHumidType: "steam", pWaterTemp: 10 }),
            chamberPro({ pHumidType: "atomization" }),
            chamberPro({ pDehumidMethod: "heat_pump", pEvapTemp: 5 }),
            chamberPro({ pTExt: 35, pTSet: 40, pRhSet: 75 }),
            chamberPro({ pDoorCount: 2, pDoorOpenings: 20, pDoorDuration: 30 }),
            chamberProfile("pro", "refrigerated"),
            chamberProfile("pro", "accelerated", { pTExt: 22 }),
        ],
    },
    {
        tool: "psychrometrie",
        library: "assets/lib/psychrometrics.js",
        cases: [
            ...[-20, 0, 5, 20, 25, 40].map((t) => ({ fn: "saturationPressurePa", args: [t] })),
            { fn: "dewPoint", args: [25, 60] }, { fn: "dewPoint", args: [20, 50] }, { fn: "dewPoint", args: [5, 90] }, { fn: "dewPoint", args: [25, 0] },
            { fn: "absoluteHumidityGPerM3", args: [25, 60] }, { fn: "absoluteHumidityGPerM3", args: [2, 85] }, { fn: "absoluteHumidityGPerM3", args: [-18, 85] },
            { fn: "humidityRatio", args: [25, 60] }, { fn: "humidityRatio", args: [40, 75] },
            { fn: "relativeHumidityFromDewPoint", args: [20, 9.3] }, { fn: "relativeHumidityFromDewPoint", args: [20, 25] },
            { fn: "sensibleAirPowerW", args: [100, 20] }, { fn: "sensibleAirPowerW", args: [100, -20] },
            { fn: "latentDehumidPowerW", args: [100, 25, 60, 2, 85] }, { fn: "latentDehumidPowerW", args: [100, 25, 50, -18, 85] }, { fn: "latentDehumidPowerW", args: [100, 10, 40, 25, 60] },
            { fn: "humidificationRateGPerH", args: [6, 25, 40, 60, 30] }, { fn: "humidificationRateGPerH", args: [6, 25, 60, 40, 30] },
            { fn: "humidificationThermalPowerW", args: [100, "boiler", 20] }, { fn: "humidificationThermalPowerW", args: [100, "ultrasonic", 20] }, { fn: "humidificationThermalPowerW", args: [100, "autre", 20] },
            { fn: "dehumidRefrigPowerW", args: [6, 25, 60, 40, 15] }, { fn: "dehumidRefrigPowerW", args: [6, 40, 75, 60, 30] },
        ],
    },
];
