// Configuration Vite du site.
// - vite-plugin-symfony : manifest et entrypoints lus par pentatrion/vite-bundle ;
//   les contrôleurs Stimulus sont enregistrés par assets/stimulus_bootstrap.js ;
// - @tailwindcss/vite : compilation de Tailwind CSS v4 ;
// - mkcert : HTTPS local pour le serveur de développement (même origine que symfony serve).
import { defineConfig } from "vite";
import symfonyPlugin from "vite-plugin-symfony";
import tailwindcss from "@tailwindcss/vite";
import mkcert from "vite-plugin-mkcert";

export default defineConfig(({ command }) => ({
    plugins: [
        // Certificat local uniquement pour « npm run dev » (inutile au build)
        command === "serve" ? mkcert() : null,
        tailwindcss(),
        // stimulus désactivé : les contrôleurs sont enregistrés par assets/stimulus_bootstrap.js
        // (la transformation du plugin donne de faux identifiants sous Windows).
        symfonyPlugin(),
    ],
    server: {
        host: "127.0.0.1",
        port: 5173,
        strictPort: true,
        cors: { origin: ["https://127.0.0.1:8000", "https://localhost:8000"] },
    },
    build: {
        rollupOptions: {
            input: {
                app: "./assets/app.js",
            },
        },
    },
    test: {
        environment: "node",
        include: ["assets/**/*.test.js"],
    },
}));
