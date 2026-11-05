"""
Contre-calcul indépendant de l'enceinte climatique v2 : 3 cas validés le 2026-10-23.

Les formules sont celles de docs/ENCEINTE_CLIMATIQUE_V2.md (section 3), ressaisies ici sans reprendre le
code JavaScript (assets/calculators/enceinte_climatique.js) : les deux calculs doivent donner les mêmes
valeurs. Les résultats servent de cas de référence (assets/calculators/golden/enceinte_climatique_v2.json),
rejoués par assets/calculators/enceinte_climatique.test.js et vérifiés en CI.

Usage (depuis guillaume_c2/) :
  python scripts/calculator/climatic_chamber_hand_check.py           affiche le détail
  python scripts/calculator/climatic_chamber_hand_check.py --write   écrit les cas de référence
  python scripts/calculator/climatic_chamber_hand_check.py --check   vérifie les cas de référence (CI)
"""
import json
import math
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")

GOLDEN = Path(__file__).resolve().parents[2] / "assets" / "calculators" / "golden" / "enceinte_climatique_v2.json"

P = 101325.0
LV = 2501.0  # kJ/kg, chaleur latente de vaporisation à 0 °C
COP_MAX = 5.0  # plafond validé le 2026-10-23
EVAP_APPROACH = 8.0  # K : évaporation = consigne − 8 K (comme la chambre froide)
SURFACE_OFFSET = 2.0  # K : surface de la batterie = évaporation + 2 K (validé le 2026-10-23)
FAN_W_PER_M3 = {"monobloc": 60.0, "modular": 35.0, "modular_outdoor": 35.0}  # W/m³ selon le type d'enceinte
# Régulation PID (section 8 du document, validée le 2026-10-23)
AIR_CHANGES = {"monobloc": 150.0, "modular": 60.0, "modular_outdoor": 60.0}  # brassage (vol/h)
SENSOR_T, SENSOR_RH = 20.0, 30.0  # s : temps de réponse des sondes de température et d'humidité
SPAN_T = 150.0  # K : étendue de mesure −50 à +100 °C
ACTUATOR_MIN = {"heat": 0.0, "cold": 30.0, "humid": 10.0, "dry": 0.0}  # % : relais statique, variateur, vapeur modulante, vanne
STAGING_RATIO = 3.0  # installée > 3 × maintien : étages conseillés
QUIET = False


def say(text):
    """Affiche une ligne du détail (sauf avec --write et --check)."""
    if not QUIET:
        print(text)


def psat(t, ice=False):
    """Pression de vapeur saturante (Pa), Magnus sur l'eau (17,67 / 243,5) ou la glace (22,46 / 272,62)."""
    b, c = (22.46, 272.62) if ice and t < 0 else (17.67, 243.5)
    return 611.2 * math.exp(b * t / (c + t))


def air(t, rh):
    """Teneur en eau w (kg/kg), enthalpie h (kJ/kg air sec), masse volumique ρ (kg/m³)."""
    pv = psat(t, t < 0) * rh / 100
    w = 0.62198 * pv / (P - pv)
    tk = t + 273.15
    return {"t": t, "rh": rh, "w": w, "h": 1.006 * t + w * (LV + 1.86 * t), "rho": (P - pv) / (287.05 * tk) + pv / (461.5 * tk)}


def dew_point(t, rh):
    a = 17.67 * t / (243.5 + t) + math.log(rh / 100)
    return 243.5 * a / (17.67 - a)


def w_sat(t):
    pv = psat(t, t < 0)
    return 0.62198 * pv / (P - pv)


def u_panel(e_mm, lam):
    return 1 / (0.17 + e_mm / 1000 / lam)


def door_exchange(width, height, local, inside, openings, seconds, efficiency=0.0):
    """Échange d'air par une porte (ASHRAE, Gosney et Olama), côté dense = air froid. Débit moyen d'air sec (kg/s)."""
    cold, warm = (inside, local) if inside["rho"] >= local["rho"] else (local, inside)
    if abs(cold["rho"] - warm["rho"]) < 1e-12:
        return 0.0
    fm = (2 / (1 + (cold["rho"] / warm["rho"]) ** (1 / 3))) ** 1.5
    flow_open = 0.221 * width * height * cold["rho"] * math.sqrt(1 - warm["rho"] / cold["rho"]) * math.sqrt(9.81 * height) * fm
    return flow_open * (openings * seconds / 86400) * 0.8 * (1 - efficiency)


def cop(t_evap, cond_air=35):
    te, tc = t_evap, cond_air + 10
    return min(COP_MAX, 0.45 * (te + 273.15) / (tc - te))


def door_ua(door):
    """Transmission de la porte (W/K) : U × S + cadre (U 1,2 sur 6 cm du périmètre)."""
    return 0.55 * door["w"] * door["h"] + 1.2 * 2 * (door["w"] + door["h"]) * 0.06


def envelope(c, local, fresh):
    """Bilan sensible des échanges (W, + = apport) et bilan d'eau (kg/h, + = eau entrante)."""
    t, ins, door = c["t_set"], c["inside"], c["door"]
    rows = {"walls": c["U"] * c["S"] * (local["t"] - t), "doorTransmission": door_ua(door) * (local["t"] - t)}
    flows = {
        "leaks": (c["leak_pct"] / 100 * c["V"] * ins["rho"] / 3600, local),
        "infiltration": (door_exchange(door["w"], door["h"], local, ins, door["openings"], door["seconds"]), local),
        "freshAir": (c["fresh_m3h"] * ins["rho"] / 3600, fresh),
    }
    water = 0.0
    for key, (m, ext) in flows.items():
        rows[key] = m * (1.006 + 1.86 * ext["w"]) * (ext["t"] - t) * 1000
        water += m * (ext["w"] - ins["w"]) * 3600
    return rows, water


def balance(c, env, water, internal_w, t_surface, td):
    """Point fixe : humidification ↔ chaleur sensible de la vapeur ↔ froid sensible ↔ condensation sur la batterie.
    Bilan d'eau net = eau entrante − condensation : négatif → humidification, positif → séchage."""
    t, ins, rh = c["t_set"], c["inside"], c["rh_set"]
    cond = humid = 0.0
    hs = cold_sensible = 0.0
    for _ in range(500):
        hs = humid / 3600 * (2676 - (LV + 1.86 * t)) * 1000  # vapeur à 100 °C
        cold_sensible = max(0.0, env + internal_w + hs)
        new_cond = 0.0
        if rh and cold_sensible > 0 and t_surface < td:
            # Point de contact : l'air qui touche la batterie en sort à la température de surface, saturé, et porte tout le froid sensible
            m_contact = cold_sensible / 1000 / (1.006 * (t - t_surface))
            new_cond = m_contact * (ins["w"] - w_sat(t_surface)) * 3600
        net = water - new_cond
        new_humid = max(0.0, -net) if rh else 0.0
        done = abs(new_humid - humid) < 1e-13 and abs(new_cond - cond) < 1e-13
        humid, cond = new_humid, new_cond
        if done:
            break
    dry = max(0.0, water - cond) if rh else 0.0
    return {"humid": humid, "cond": cond, "hs": hs, "cold_sensible": cold_sensible, "dry": dry}


def season(c, label, local, fresh):
    t, ins, rh = c["t_set"], c["inside"], c["rh_set"]
    m = 1 + c["margin"] / 100
    rows, water = envelope(c, local, fresh)
    env = sum(rows.values())
    internal = sum(p for p, _, _ in c["internal"].values())  # dimensionnement : tous les apports présents
    internal_avg = sum(p * h / 24 for p, h, _ in c["internal"].values())  # consommation : durées déclarées
    td = dew_point(t, rh) if rh else None
    t_evap = t - EVAP_APPROACH
    t_surface = t_evap + SURFACE_OFFSET
    steam_kj = 4.186 * (100 - c["water_t"]) + 2257

    b = balance(c, env, water, internal, t_surface, td)
    cond_latent = b["cond"] / 3600 * LV * 1000
    humid_power = b["humid"] / 3600 * steam_kj * 1000
    if b["dry"] > 0:
        t_coil = td - 2
        m_coil = b["dry"] / 3600 / (ins["w"] - w_sat(t_coil))
        dry_latent, dry_sensible = b["dry"] / 3600 * LV * 1000, m_coil * 1.006 * (t - t_coil) * 1000
    else:
        t_coil, dry_latent, dry_sensible = None, 0.0, 0.0
    cold = b["cold_sensible"] + cond_latent
    heat = max(0.0, -env)  # apports internes non déduits (C9)

    say(f"\n-- {label} : local {local['t']} °C / {local['rh']} %, air neuf {fresh['t']} °C / {fresh['rh']} %")
    for k, v in rows.items():
        say(f"   {k:<22} {v:9.2f} W")
    say(f"   {'apports internes':<22} {internal:9.2f} W ({', '.join(f'{k} {p:.0f} W × {h:g} h/j' for k, (p, h, _) in c['internal'].items())})")
    say(f"   {'bilan d eau':<22} {water * 1000:9.2f} g/h")
    if rh:
        say(f"   batterie : évaporation {t_evap:.2f} °C, surface {t_surface:.2f} °C, point de rosée {td:.2f} °C → "
            + (f"condensation {b['cond'] * 1000:.2f} g/h (latent {cond_latent:.2f} W)" if b["cond"] else "pas de condensation"))
        if b["humid"]:
            say(f"   humidification {b['humid'] * 1000:.2f} g/h → {humid_power:.2f} W, sensible apporté {b['hs']:.2f} W")
        if b["dry"]:
            say(f"   séchage {b['dry'] * 1000:.2f} g/h : batterie à {t_coil:.2f} °C, latent {dry_latent:.2f} W, sensible {dry_sensible:.2f} W")
    say(f"   DIMENSIONNEMENT (× {m:.2f}) : froid {cold * m:.2f} W ; chauffe {heat * m:.2f} W ; humidification {humid_power * m:.2f} W ; "
        f"séchage {(dry_latent + dry_sensible) * m:.2f} W (+ réchauffage {dry_sensible * m:.2f} W)")

    # Consommation (kWh/j) : apports selon leur durée déclarée ; équipements électriques de l'enceinte seulement (pas la charge en essai)
    bc = balance(c, env, water, internal_avg, t_surface, td)
    net = env + internal_avg + bc["hs"]
    cond_latent_c = bc["cond"] / 3600 * LV * 1000
    k = cop(t_evap)
    k_dry = cop(td - 2 - 5) if bc["dry"] else k
    cold_th = max(0.0, net) + cond_latent_c
    heat_c = max(0.0, -net)
    humid_p = bc["humid"] / 3600 * steam_kj * 1000
    dry_p = (dry_latent + dry_sensible) if bc["dry"] else 0.0
    reheat_c = dry_sensible if bc["dry"] else 0.0
    equipment = sum(p * h for p, h, equip in c["internal"].values() if equip) / 1000
    others = (humid_p + dry_p / k_dry + reheat_c) * 24 / 1000 + equipment
    kwh = (cold_th / k + heat_c) * 24 / 1000 + others
    say(f"   CONSOMMATION : {kwh:.3f} kWh/j (froid moyen {cold_th:.2f} W ÷ COP {k:.4f}, chauffe {heat_c:.2f} W, humidification {humid_p:.2f} W, "
        f"équipements {equipment:.3f} kWh/j) ; eau {bc['humid'] * 24:.3f} L/j, condensats {(bc['dry'] + bc['cond']) * 24:.3f} L/j")
    return {
        "lines": {k2: round(v, 6) for k2, v in rows.items()},
        "envelopeW": round(env, 6), "internalW": round(internal, 6), "internalAvgW": round(internal_avg, 6),
        "waterKgH": round(water, 9), "condensationKgH": round(b["cond"], 9), "humidificationKgH": round(b["humid"], 9), "dryingKgH": round(b["dry"], 9),
        "humidifierSensibleW": round(b["hs"], 6), "coldSensibleW": round(b["cold_sensible"], 6), "condensationLatentW": round(cond_latent, 6),
        "coldW": round(cold * m, 6), "heatW": round(heat * m, 6), "humidificationW": round(humid_power * m, 6),
        "dryingW": round((dry_latent + dry_sensible) * m, 6), "reheatW": round(dry_sensible * m, 6),
        "cop": round(k, 9), "consumptionKwhDay": round(kwh, 6), "waterLDay": round(bc["humid"] * 24, 6), "condensateLDay": round((bc["dry"] + bc["cond"]) * 24, 6),
        # Pour la régulation « froid permanent + réchauffage »
        "_net": net, "_cond_latent_c": cond_latent_c, "_others": others,
    }


def thermal_masses(c):
    """Capacités thermiques (kJ/K) : air, peau inox 1 mm, étagères, structure et batteries, charge."""
    masses = {"air": c["inside"]["rho"] * c["V"] * 1.006, "peau inox 1 mm": c["S"] * 7.9 * 0.5, "étagères inox": 30 * 0.5, "structure, batteries": 40 * 0.6}
    masses.update(c.get("load_masses", {}))
    return masses


def ramp(c, label, local, fresh):
    """Mise en température depuis le départ (par défaut : le local de la saison) : énergie Σ(m·cp)·ΔT,
    pertes ou apports moyens à la température moyenne de la rampe. Durée souhaitée (60 min par défaut) :
    puissance nécessaire = (énergie / durée + pertes ou apports) × (1 + marge) ; si une puissance est
    installée (saisie), durée = énergie / (P − pertes ou apports)."""
    t, ins, door = c["t_set"], c["inside"], c["door"]
    m = 1 + c["margin"] / 100
    masses = thermal_masses(c)
    cap = sum(masses.values())
    t0 = c.get("t_start", local["t"])
    mean = (t0 + t) / 2
    ua_local = c["U"] * c["S"] + door_ua(door) + c["leak_pct"] / 100 * c["V"] * ins["rho"] / 3600 * 1006
    ua_fresh = c["fresh_m3h"] * ins["rho"] / 3600 * 1006
    energy = cap * abs(t - t0)
    out = {"startT": t0, "capacityKjK": round(cap, 6), "energyKj": round(energy, 6)}
    say(f"\n-- Mise en régime ({label}) depuis {t0} °C : capacité {cap:.3f} kJ/K ({', '.join(f'{k} {v:.2f}' for k, v in masses.items())}), énergie {energy:.2f} kJ")
    if t == t0:
        return out
    heating = t > t0
    if heating:
        flow = ua_local * (mean - local["t"]) + ua_fresh * (mean - fresh["t"])
        out.update({"direction": "heat", "meanLossW": round(flow, 6)})
        installed = c.get("heater_w")
    else:
        flow = ua_local * (local["t"] - mean) + ua_fresh * (fresh["t"] - mean) + sum(p for p, _, _ in c["internal"].values())
        out.update({"direction": "cool", "meanGainW": round(flow, 6)})
        installed = c.get("cold_w")
    what = "chauffe : pertes moyennes" if heating else "froid : apports moyens"
    if installed:
        out["powerW"] = installed
        out["minutes"] = round(energy * 1000 / (installed - flow) / 60, 6) if installed > flow else None
        say(f"   {what} {flow:.2f} W ; {installed:.2f} W installés → {out['minutes']} min")
    else:
        p = (energy * 1000 / (c["ramp_minutes"] * 60) + flow) * m
        out["requiredW"] = round(p, 6)
        say(f"   {what} {flow:.2f} W ; en {c['ramp_minutes']} min → {p:.2f} W (marge comprise)")
    return out


def humidity_ramp(c, label, local):
    """Mise en régime d'humidité : eau = ρ × V × (w_consigne − w_départ), sur la durée souhaitée (30 min par
    défaut). Eau à apporter : humidificateur ; eau à retirer : séchage par batterie à point de rosée − 2 K."""
    ins, m = c["inside"], 1 + c["margin"] / 100
    start = air(c.get("t_start", local["t"]), c.get("rh_start", local["rh"]))
    water = ins["rho"] * c["V"] * (ins["w"] - start["w"])
    rate = water * 60 / c["humid_minutes"]
    humid = max(0.0, rate) / 3600 * (4.186 * (100 - c["water_t"]) + 2257) * 1000 * m
    dry = 0.0
    if rate < 0:
        t_coil = dew_point(c["t_set"], c["rh_set"]) - 2
        m_coil = -rate / 3600 / (ins["w"] - w_sat(t_coil))
        dry = (-rate / 3600 * LV * 1000 + m_coil * 1.006 * (c["t_set"] - t_coil) * 1000) * m
    say(f"   humidité ({label}) : {start['t']} °C / {start['rh']} % → {water * 1000:.2f} g d'eau ; en {c['humid_minutes']} min : {rate * 1000:.2f} g/h, "
        f"humidification {humid:.2f} W, séchage {dry:.2f} W (marge comprise)")
    return {"waterKg": round(water, 9), "rateKgH": round(rate, 9), "powerW": round(humid, 6), "dryingW": round(dry, 6)}


def simc(kp, tau, theta, tau_c):
    """Réglage SIMC (Skogestad) d'un procédé premier ordre + temps mort : Kc (% par unité), Ti (s) ; Ti = 4 (τc + θ) quand τ ≫ θ."""
    return tau / (kp * (tau_c + theta)), min(tau, 4 * (tau_c + theta))


def pid(c, out):
    """Régulation PID : modèle de l'enceinte (premier ordre + temps mort), réglages SIMC de départ (PI, τc = θ),
    plage de modulation des actionneurs et rapport puissance installée / maintien, pour chaque fonction."""
    ins, seasons, inst = c["inside"], out["seasons"], out["installed"]
    ua = c["U"] * c["S"] + door_ua(c["door"]) + (c["leak_pct"] / 100 * c["V"] + c["fresh_m3h"]) * ins["rho"] / 3600 * 1006
    cap = sum(thermal_masses(c).values()) * 1000
    mix = 3600 / AIR_CHANGES[c["kind"]]
    tau, theta = cap / ua, mix + SENSOR_T
    res = {"temperature": {"uaWK": ua, "capacityJK": cap, "tauS": tau, "thetaS": theta}, "loops": {}}
    least = min(s["envelopeW"] for s in seasons.values())
    permanent = sum(p for p, hours, _ in c["internal"].values() if hours >= 24)
    minimum = {
        "heat": min((-s["envelopeW"] for s in seasons.values() if s["envelopeW"] < 0), default=0.0),
        "cold": max(0.0, least + permanent),
    }
    say("\n   RÉGULATION PID")
    for loop, key in (("heat", "heatW"), ("cold", "coldW")):
        p = inst[key]["installedW"]
        if p <= 0:
            continue
        kp = p / (100 * ua)
        kc, ti = simc(kp, tau, theta, theta)
        maint = inst[key]["maintenanceW"]
        ratio = minimum[loop] / p * 100
        res["loops"][loop] = {
            "kp": kp, "kc": kc, "xp": 100 / kc, "xpPct": 100 / kc / SPAN_T * 100, "ti": ti, "td": 0.0,
            "minimumW": minimum[loop], "minRatioPct": ratio, "belowRange": 0 < ratio < ACTUATOR_MIN[loop],
            "installedToMaintenance": p / maint if maint > 0 else None, "staging": maint > 0 and p / maint > STAGING_RATIO,
        }
        say(f"   {loop} : P {p:.0f} W, K_u {kp:.4f} K/%, Xp {100 / kc:.3f} K, Ti {ti:.0f} s ; minimum {minimum[loop]:.1f} W ({ratio:.1f} %)")
    if c["rh_set"]:
        exch = (c["leak_pct"] / 100 * c["V"] + c["fresh_m3h"]) * ins["rho"] / 3600 + door_exchange(c["door"]["w"], c["door"]["h"], c["summer_local"], ins, c["door"]["openings"], c["door"]["seconds"])
        mass = ins["rho"] * c["V"]
        tau_w, theta_w = mass / exch, mix + SENSOR_RH
        res["humidity"] = {"exchangeKgS": exch, "massKg": mass, "tauS": tau_w, "thetaS": theta_w}
        drh_dw = c["rh_set"] / ins["w"]
        ramps = out["rampHumidity"].values()
        rates = {
            "humid": (max([s["humidificationKgH"] for s in seasons.values()] + [max(0.0, r["rateKgH"]) for r in ramps]), [s["humidificationKgH"] for s in seasons.values()]),
            "dry": (max([s["dryingKgH"] for s in seasons.values()] + [max(0.0, -r["rateKgH"]) for r in ramps]), [s["dryingKgH"] for s in seasons.values()]),
        }
        for loop, (rate, needs) in rates.items():
            if rate <= 0:
                continue
            kp = rate / 3600 / 100 / exch * drh_dw
            kc, ti = simc(kp, tau_w, theta_w, theta_w)
            ratio = min(needs) / rate * 100
            res["loops"][loop] = {
                "maxKgH": rate, "kp": kp, "kc": kc, "xp": 100 / kc, "ti": ti, "td": 0.0,
                "minRatioPct": ratio, "belowRange": 0 < ratio < ACTUATOR_MIN[loop],
            }
            say(f"   {loop} : {rate * 1000:.0f} g/h max, K_u {kp:.4f} %HR/%, Xp {100 / kc:.3f} % HR, Ti {ti:.0f} s ; minimum {ratio:.1f} %")
    # Arrondis (fichier de référence)
    for block in [res["temperature"], res.get("humidity", {}), *res["loops"].values()]:
        for k2, v in block.items():
            if isinstance(v, float):
                block[k2] = round(v, 6)
    return res


def chamber(**k):
    L, W, H = k["dims"]
    k["V"], k["S"] = L * W * H, 2 * (L * W + L * H + W * H)
    k["U"] = u_panel(*k["panel"])
    k["inside"] = air(k["t_set"], k["rh_set"] if k["rh_set"] else 50)
    # Apports : (W, h/j, équipement électrique de l'enceinte compté dans sa consommation)
    k["internal"] = {"ventilateurs": (FAN_W_PER_M3[k["kind"]] * k["V"], 24, True), "éclairage": (40, 2, True), "instrumentation": (10, 24, True), **k.get("extra_internal", {})}
    return k


COMMON = dict(
    dims=(2.0, 1.5, 2.0), panel=(80, 0.023), leak_pct=0.5, margin=10, water_t=15, kind="modular", ramp_minutes=60, humid_minutes=30,
    door={"w": 0.8, "h": 2.0, "openings": 5, "seconds": 20},
    summer_local=air(30, 60), winter_local=air(18, 30), summer_fresh=air(32, 40), winter_fresh=air(-5, 90),
)


def run(c, name):
    say(f"\n=== {name} ===")
    say(f"V {c['V']:.3f} m³, S {c['S']:.3f} m², U {c['U']:.4f} W/(m²·K), ventilateurs {FAN_W_PER_M3[c['kind']]:g} W/m³ (enceinte {c['kind']})")
    if c["rh_set"]:
        say(f"Consigne {c['t_set']} °C / {c['rh_set']} % : w {c['inside']['w'] * 1000:.3f} g/kg, point de rosée {dew_point(c['t_set'], c['rh_set']):.2f} °C")
    seasons = {"summer": season(c, "été", c["summer_local"], c["summer_fresh"]), "winter": season(c, "hiver", c["winter_local"], c["winter_fresh"])}
    ramps = {"summer": ramp(c, "été", c["summer_local"], c["summer_fresh"]), "winter": ramp(c, "hiver", c["winter_local"], c["winter_fresh"])}
    out = {"seasons": seasons, "rampTemperature": ramps}
    if c["rh_set"]:
        out["rampHumidity"] = {"summer": humidity_ramp(c, "été", c["summer_local"]), "winter": humidity_ramp(c, "hiver", c["winter_local"])}

    # Puissances à installer = max(maintien été, maintien hiver, mise en régime), ou puissance installée saisie
    def required(direction):
        return [r["requiredW"] for r in ramps.values() if r.get("direction") == direction and "requiredW" in r]

    hum = out.get("rampHumidity", {}).values()
    installed_power = {
        "coldW": {"maintenanceW": max(s["coldW"] for s in seasons.values()), "rampW": max(required("cool"), default=0.0)},
        "heatW": {"maintenanceW": max(s["heatW"] for s in seasons.values()), "rampW": max(required("heat"), default=0.0)},
        "humidificationW": {"maintenanceW": max(s["humidificationW"] for s in seasons.values()), "rampW": max((h["powerW"] for h in hum), default=0.0)},
        "dryingW": {"maintenanceW": max(s["dryingW"] for s in seasons.values()), "rampW": max((h["dryingW"] for h in out.get("rampHumidity", {}).values()), default=0.0)},
    }
    entered = {"coldW": c.get("cold_w"), "heatW": c.get("heater_w")}
    for key, v in installed_power.items():
        v["installedW"] = entered.get(key) or max(v["maintenanceW"], v["rampW"])
        v.update({k2: round(x, 6) for k2, x in v.items()})
        say(f"   À INSTALLER {key} : {v['installedW']:.2f} W (maintien {v['maintenanceW']:.2f} W, mise en régime {v['rampW']:.2f} W)")
    out["installed"] = installed_power

    # Régulation : « froid permanent + réchauffage » avec le froid à installer ; réchauffage dimensionné sans
    # apports internes (charge la plus faible), consommations comparées à la modulation
    installed = installed_power["coldW"]["installedW"]
    least = min(s["envelopeW"] for s in seasons.values())
    regulation = {"installedColdW": round(installed, 6), "reheatW": round(installed - least, 6), "modulatedKwhDay": {}, "permanentKwhDay": {}}
    for key, s in seasons.items():
        reheat = installed - s["_net"] - s["_cond_latent_c"]
        regulation["modulatedKwhDay"][key] = s["consumptionKwhDay"]
        regulation["permanentKwhDay"][key] = round((installed / s["cop"] + reheat) * 24 / 1000 + s["_others"], 6)
    say(f"\n   RÉGULATION : froid installé {installed:.2f} W ; réchauffage (froid permanent) {installed - least:.2f} W ; "
        f"modulation {regulation['modulatedKwhDay']} ; froid permanent + réchauffage {regulation['permanentKwhDay']} kWh/j")
    out["regulation"] = regulation
    out["pid"] = pid(c, out)
    for s in seasons.values():
        for key in ("_net", "_cond_latent_c", "_others"):
            del s[key]
    return out


def cases():
    c1 = chamber(**COMMON, t_set=40, rh_set=75, fresh_m3h=0, heater_w=2000)
    c2 = chamber(**COMMON, t_set=25, rh_set=60, fresh_m3h=30)
    # Électronique en essai : 500 W pendant 12 h/j déclarées, 50 kg (cp 0,9) ; sa consommation n'est pas celle de l'enceinte
    c3 = chamber(**COMMON, t_set=5, rh_set=None, fresh_m3h=0,
                 extra_internal={"électronique": (500, 12, False)}, load_masses={"électronique 50 kg": 50 * 0.9})
    return {
        "case1": run(c1, "Cas 1 — 40 °C / 75 % HR, chauffe électrique 2 kW"),
        "case2": run(c2, "Cas 2 — 25 °C / 60 % HR, air neuf 30 m³/h"),
        "case3": run(c3, "Cas 3 — 5 °C, électronique 500 W (12 h/j), descente en 60 min, stabilité ±0,5 K"),
    }


if __name__ == "__main__":
    mode = sys.argv[1] if len(sys.argv) > 1 else ""
    QUIET = bool(mode)
    doc = {"_comment": "Cas de référence de l'enceinte climatique v2, produits par scripts/calculator/climatic_chamber_hand_check.py --write (contre-calcul indépendant). Ne pas modifier à la main.", "cases": cases()}
    if mode == "--write":
        GOLDEN.write_text(json.dumps(doc, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"Écrit : {GOLDEN}")
    elif mode == "--check":
        stored = json.loads(GOLDEN.read_text(encoding="utf-8"))
        if stored["cases"] != json.loads(json.dumps(doc["cases"])):
            print("Les cas de référence de l'enceinte climatique v2 ne correspondent plus au contre-calcul : relancez --write après vérification.")
            sys.exit(1)
        print("Cas de référence de l'enceinte climatique v2 : conformes au contre-calcul.")
