/*
 * SweetAlert2 et sa feuille de style, chargés À LA DEMANDE (fichier séparé produit par Vite) : seulement sur
 * les pages qui affichent une fenêtre ou un message (pas de poids sur les autres pages, Lighthouse inchangé).
 *
 * Version figée dans package.json (11.26.25, licence MIT ; protestware retiré en 11.22.4).
 * Version ESM sans injection de CSS (dist/sweetalert2.esm.js) : la feuille de style passe par Vite, aucun CDN.
 * Thème du site : assets/styles/notify.css, chargé avec SweetAlert2 (rien sur les autres pages).
 *
 * Utilisé par : assets/lib/notify.js (loadSwal())
 */
import Swal from "sweetalert2/dist/sweetalert2.esm.js";
import "sweetalert2/dist/sweetalert2.css";
// Thème du site, après la feuille de SweetAlert2
import "../styles/notify.css";

export default Swal;
