/*
 * État de l'en-tête fixe selon le défilement (logique pure, testée par header_scroll.test.js) :
 * - ordinateur : toujours visible, réduit (« compact ») dès que la page a défilé ;
 * - mobile : masqué quand on descend, réaffiché quand on remonte ; jamais masqué en haut de page,
 *   menu ouvert ou focus dans l'en-tête (navigation au clavier).
 *
 * Utilisé par : assets/controllers/site_header_controller.js
 */

/** Défilement (px) à partir duquel l'en-tête est réduit. */
export const COMPACT_AFTER = 16;

/** Déplacement minimal (px) pour changer d'état sur mobile (évite les à-coups). */
export const DIRECTION_THRESHOLD = 8;

/** En haut de page (px), l'en-tête reste toujours visible. */
export const ALWAYS_VISIBLE_UNTIL = 80;

/**
 * Calcule le nouvel état de l'en-tête.
 *
 * @param {{y: number, anchorY: number, hidden: boolean, mobile: boolean, pinned: boolean}} s
 *        y : défilement actuel ; anchorY : position du dernier changement de sens ; hidden : état actuel ;
 *        pinned : menu ouvert ou focus dans l'en-tête
 * @returns {{hidden: boolean, compact: boolean, anchorY: number}}
 */
export function nextHeaderState({ y, anchorY, hidden, mobile, pinned }) {
    const compact = y > COMPACT_AFTER;
    if (!mobile || pinned || y <= ALWAYS_VISIBLE_UNTIL) {
        return { hidden: false, compact, anchorY: y };
    }
    const delta = y - anchorY;
    if (!hidden && delta > DIRECTION_THRESHOLD) {
        return { hidden: true, compact, anchorY: y };
    }
    if (hidden && delta < -DIRECTION_THRESHOLD) {
        return { hidden: false, compact, anchorY: y };
    }

    // Même sens : la référence suit le point extrême atteint
    return { hidden, compact, anchorY: hidden ? Math.max(anchorY, y) : Math.min(anchorY, y) };
}
