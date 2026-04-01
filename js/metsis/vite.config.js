import { defineConfig } from "vite";
import preact from "@preact/preset-vite";
import path from "path";
import { fileURLToPath } from "url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const port = 5173;
const origin = `localhost:${port}`;

export default defineConfig({
  plugins: [preact()],
  // Use relative base so preload-helper resolves chunk URLs correctly
  // regardless of the deployment subdirectory (e.g. Drupal module paths).
  base: "./",
  build: {
    manifest: true,
    rollupOptions: {
      input: {
        metsisMapApp: path.resolve(__dirname, "metsis-map-app/src/index.jsx"),
        bboxMapFilter: path.resolve(__dirname, "bbox-map-filter/src/main.js"),
        olCss: path.resolve(__dirname, "node_modules/ol/ol.css"),
      },
      output: {
        manualChunks: {
          ol: ["ol"],
        },
      },
    },
    outDir: path.resolve(__dirname, "dist"), // Output to the module's dist directory
    emptyOutDir: true,
    sourcemap: true,
  },
  resolve: {
    // Special path aliases for cleaner imports. Used for metsis-map-app
    alias: {
      "@": path.resolve(__dirname, "./metsis-map-app/src"),
      "@components": path.resolve(__dirname, "metsis-map-app/src/components"),
      "@hooks": path.resolve(__dirname, "metsis-map-app/src/hooks"),
      "@styles": path.resolve(__dirname, "metsis-map-app/src/styles"),
      "@assets": path.resolve(__dirname, "metsis-map-app/src/assets"),
      "@utils": path.resolve(__dirname, "metsis-map-app/src/utils"),
    },
  },
  server: {
    open: false,
    host: "0.0.0.0",
    port: port,
    origin: origin,
    cors: {
      origin: /https?:\/\/([A-Za-z0-9\-.]+)?(\.ddev\.site)(?::\d+)?$/,
    },
    strictPort: true,
    allowedHosts: ["metsis-drupal.ddev.site", "localhost"],
  },
});
