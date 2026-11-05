"""
Contre-calcul indépendant de la chambre froide v2 (lot 5e) : cas par défaut et 3 cas du cahier.

Les formules sont ressaisies ici depuis docs/CHAMBRE_FROIDE_V2.md, sans reprendre le code JavaScript
(assets/calculators/chambre_froide.js) : les deux calculs doivent donner les mêmes valeurs. Les résultats
servent de cas de référence (assets/calculators/golden/chambre_froide_v2.json), rejoués par
assets/calculators/chambre_froide.test.js ; le détail est recopié dans docs/CHAMBRE_FROIDE_V2.md (section 4).
Les postes portent les clés du code JavaScript (walls, doorTransmission…).

Usage (depuis guillaume_c2/) :
  python scripts/calculator/cold_room_hand_check.py           affiche le détail
  python scripts/calculator/cold_room_hand_check.py --write   écrit les cas de référence
  python scripts/calculator/cold_room_hand_check.py --check   vérifie les cas de référence (CI)
"""
import json
import math
import sys
from pathlib import Path

GOLDEN = Path(__file__).resolve().parents[2] / "assets" / "calculators" / "golden" / "chambre_froide_v2.json"

P = 101325.0


def psat(t, ice=False):
    """Pression de vapeur saturante (Pa), Magnus sur l'eau (17,67 / 243,5) ou sur la glace (22,46 / 272,62)."""
    b, c = (22.46, 272.62) if ice and t < 0 else (17.67, 243.5)
    return 611.2 * math.exp(b * t / (c + t))


def air(t, rh, ice=False):
    """Teneur en eau (kg/kg), enthalpie (kJ/kg air sec), masse volumique (kg/m³)."""
    pv = psat(t, ice) * rh / 100
    w = 0.62198 * pv / (P - pv)
    tk = t + 273.15
    return w, 1.006 * t + w * (2501 + 1.86 * t), (P - pv) / (287.05 * tk) + pv / (461.5 * tk)


def infiltration(width, height, t_loc, rh_loc, t_in, rh_in, openings, seconds, efficiency):
    """Infiltration ASHRAE (Gosney et Olama), en W, avec la part latente et le givre."""
    wi, hi, ri = air(t_in, rh_in, t_in < 0)
    we, he, re = air(t_loc, rh_loc)
    dh = he - hi
    fm = (2 / (1 + (ri / re) ** (1 / 3))) ** 1.5
    q_open = 0.221 * width * height * dh * ri * math.sqrt(1 - re / ri) * math.sqrt(9.81 * height) * fm
    dt = openings * seconds / 86400
    avg = q_open * dt * 0.8 * (1 - efficiency)
    dry = avg / dh
    water = dry * (we - wi) * 86400
    fusion = water * 334 * 1000 / 86400 if t_in < 0 else 0
    latent = dry * (we - wi) * (2501 + 1.86 * t_loc) * 1000
    return {"q_open_kw": q_open, "dt": dt, "total": avg * 1000 + fusion, "latent": latent, "fusion": fusion, "water": water}


def u_panel(thickness_mm, lam):
    return 1 / (0.17 + thickness_mm / 1000 / lam)


def door_trans(width, height, u, t_loc, t_in):
    return (u * width * height + 1.2 * 2 * (width + height) * 0.06) * abs(t_loc - t_in)


def cop(t_set, cond_air):
    te, tc = t_set - 8, cond_air + 10
    return 0.45 * (te + 273.15) / (tc - te)


def solve(case):
    """Bilan : postes (W, kWh/j), point fixe pour les postes « par kW frigorifique »."""
    frigo = 0.0
    for _ in range(60):
        lines = case["lines"](frigo)
        total = sum(w for w, _ in lines.values())
        new = total / 1000 * 24 / case["run"] * (1 + case["margin"] / 100)
        if abs(new - frigo) < 1e-10:
            break
        frigo = new
    kwh = sum(e for _, e in lines.values())
    return lines, total, kwh, frigo


def report(name, case, quiet=False):
    lines, total, kwh, frigo = solve(case)
    c = cop(case["t_set"], case["cond_air"])
    if not quiet:
        print(f"\n=== {name} ===")
        for key, (w, e) in lines.items():
            print(f"{key:<22} {w:9.2f} W  {e:7.3f} kWh/j  {100 * e / kwh:5.1f} %")
        print(f"Total {total:.2f} W ; besoin {kwh:.3f} kWh/j ; P frigo {frigo:.4f} kW ; COP {c:.4f} ; P compresseur {frigo / c:.4f} kW")
    return {"lines": {k: {"watts": round(w, 6), "kwhDay": round(e, 6)} for k, (w, e) in lines.items()}, "totalW": round(total, 6), "kwhDay": round(kwh, 6), "frigoKw": round(frigo, 8), "cop": round(c, 8)}


day = lambda w: w * 24 / 1000  # noqa: E731


def default_case():
    t = -18
    u = u_panel(120, 0.022)
    inf = infiltration(0.9, 2, 25, 50, t, 85, 15, 25, 0)

    def lines(frigo):
        walls = u * 35 * 43 + u * 12 * 50 + u * 12 * 30
        defrost_kwh = 1.0 * frigo * (4 * 20 / 60) * 0.5
        rows = {
            "walls": walls, "doorTransmission": door_trans(0.9, 2, 0.55, 25, t), "doorHeaters": 40 * 5.8 * 0.7, "infiltration": inf["total"],
            "antiFreeze": 15 * 12 * 0.30 * 0.1, "defrost": defrost_kwh * 1000 / 24, "drain": 40 * 2 * (4 * 30 / 1440) * 0.1,
            "reliefValve": 20.0, "lighting": 96 * 12 / 24, "fans": 2.5 * 30,
        }
        out = {k: (w, day(w)) for k, w in rows.items()}
        out["defrost"] = (defrost_kwh * 1000 / 24, defrost_kwh)
        e_kg = 1.9 * (-15 - t)
        out["products"] = (500 * e_kg * 1000 / (12 * 3600), 500 * e_kg / 3600)
        return out

    return {"lines": lines, "run": 18, "margin": 10, "t_set": t, "cond_air": 35}


def case1():
    """Chambre positive, légumes avec respiration : 5 × 4 × 3 m, +4 °C, 90 %."""
    t, rh = 4, 90
    u = u_panel(100, 0.023)
    inf = infiltration(1.2, 2.1, 25, 50, t, rh, 30, 20, 0.7)
    resp_rate = 20 + (35 - 20) * (4 - 0) / (5 - 0)  # 32 W/t à 4 °C
    e_kg = 4.0 * (15 - t)  # légumes non congelés : cp × ΔT

    def lines(frigo):
        rows = {
            "walls": u * 54 * 21 + u * 20 * 21 + 1.5 * 20 * 8,  # parois 25 °C, plafond 25 °C, sol non isolé sur terre-plein 12 °C
            "doorTransmission": door_trans(1.2, 2.1, 0.55, 25, t), "infiltration": inf["total"],
            "lighting": 200 * 10 / 24, "people": 1 * (270 - 6 * t) * 2 / 24, "fans": 120.0,
            "respiration": resp_rate * 4.0,
        }
        out = {k: (w, day(w)) for k, w in rows.items()}
        out["defrost"] = (0.0, 0.0)
        out["products"] = (1000 * e_kg * 1000 / (8 * 3600), 1000 * e_kg / 3600)
        out["packaging"] = (80 * 1.7 * (15 - t) * 1000 / (8 * 3600), 80 * 1.7 * (15 - t) / 3600)
        return out

    return {"lines": lines, "run": 16, "margin": 10, "t_set": t, "cond_air": 35}


def case2():
    """Chambre négative, câble sous dalle et 2 portes : 6 × 5 × 3 m, −20 °C."""
    t = -20
    u = u_panel(150, 0.022)
    d1 = infiltration(1.0, 2.0, 20, 60, t, 85, 20, 30, 0.7)
    d2 = infiltration(1.8, 2.2, 15, 70, t, 85, 10, 60, 0.65)

    def lines(frigo):
        rows = {
            "walls": u * 66 * 40 + u * 30 * 45 + u * 30 * 32,  # parois 20 °C, plafond 25 °C, sol isolé sur terre-plein 12 °C
            "doorTransmission": door_trans(1.0, 2.0, 0.55, 20, t) + door_trans(1.8, 2.2, 0.5, 15, t),
            "doorHeaters": 40 * (2 * 3.0) * 0.7 + 40 * (2 * 4.0 + 2.2) * 0.7,  # porte 2 à 2 vantaux : + montant central
            "infiltration": d1["total"] + d2["total"],
            "antiFreeze": 15 * 30 * 0.30 * 0.1,
            "drain": 40 * 3 * (4 * (25 + 10) / 1440) * 0.1, "reliefValve": 20.0,
            "lighting": 240 * 8 / 24, "people": 2 * (270 - 6 * t) * 2 / 24, "fans": 40 * frigo,
        }
        out = {k: (w, day(w)) for k, w in rows.items()}
        defrost_kwh = 3.0 * (4 * 25 / 60) * 0.5
        out["defrost"] = (defrost_kwh * 1000 / 24, defrost_kwh)
        e_kg = 1.9 * (-15 - t)  # produits déjà congelés à −15 °C
        out["products"] = (1000 * e_kg * 1000 / (12 * 3600), 1000 * e_kg / 3600)
        return out

    return {"lines": lines, "run": 18, "margin": 10, "t_set": t, "cond_air": 35}


def case3():
    """Chambre négative, congélation sur place et vitrage chauffé : 4 × 4 × 2,8 m, −25 °C."""
    t = -25
    u = 1 / (0.17 + 0.180 / 0.022)
    inf = infiltration(0.9, 2.0, 25, 50, t, 85, 12, 20, 0)
    # Viande : 3,2 × (8 + 1,7) + 0,70 × 334 + 1,8 × (−1,7 + 25)
    e_kg = 3.2 * (8 + 1.7) + 0.70 * 334 + 1.8 * (-1.7 + 25)

    def lines(frigo):
        rows = {
            "walls": u * 44.8 * 50 + u * 16 * 50 + u * 16 * 37,  # parois et plafond 25 °C, sol isolé sur terre-plein 12 °C
            "glazing": 1.6 * 0.5 * 50, "glazingHeaters": 60 * 0.7,
            "doorTransmission": door_trans(0.9, 2.0, 0.55, 25, t), "doorHeaters": 40 * 5.8 * 0.7, "infiltration": inf["total"],
            "antiFreeze": 15 * 16 * 0.30 * 0.1, "drain": 40 * 2 * (4 * 30 / 1440) * 0.1, "reliefValve": 20.0,
            "lighting": 100 * 6 / 24, "fans": 150.0,
        }
        out = {k: (w, day(w)) for k, w in rows.items()}
        defrost_kwh = 2.0 * (4 * 20 / 60) * 0.5
        out["defrost"] = (defrost_kwh * 1000 / 24, defrost_kwh)
        out["products"] = (300 * e_kg * 1000 / (14 * 3600), 300 * e_kg / 3600)
        out["packaging"] = (15 * 1.4 * (8 - t) * 1000 / (14 * 3600), 15 * 1.4 * (8 - t) / 3600)
        return out

    return {"lines": lines, "run": 18, "margin": 10, "t_set": t, "cond_air": 35}


CASES = {
    "default": ("Cas par défaut (mode simple)", default_case),
    "case1": ("Cas 1 — positive, légumes", case1),
    "case2": ("Cas 2 — négative, câble sous dalle, 2 portes", case2),
    "case3": ("Cas 3 — négative, congélation sur place, vitrage chauffé", case3),
}

if __name__ == "__main__":
    mode = sys.argv[1] if len(sys.argv) > 1 else ""
    results = {key: report(name, build(), quiet=bool(mode)) for key, (name, build) in CASES.items()}
    doc = {"_comment": "Cas de référence de la chambre froide v2, produits par scripts/calculator/cold_room_hand_check.py --write (contre-calcul indépendant). Ne pas modifier à la main.", "cases": results}
    if mode == "--write":
        GOLDEN.write_text(json.dumps(doc, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"Écrit : {GOLDEN}")
    elif mode == "--check":
        stored = json.loads(GOLDEN.read_text(encoding="utf-8"))
        if stored["cases"] != results:
            print("Les cas de référence de la chambre froide v2 ne correspondent plus au contre-calcul : relancez --write après vérification.")
            sys.exit(1)
        print("Cas de référence de la chambre froide v2 : conformes au contre-calcul.")
