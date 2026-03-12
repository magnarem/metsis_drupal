import js from "@eslint/js";
import globals from "globals";
import tseslint from "typescript-eslint";
import { defineConfig, globalIgnores } from "eslint/config";

export default defineConfig([
  {
    files: ["**/*.{js,mjs,cjs,ts,mts,cts,jsx}"],
    plugins: { js },
    extends: ["js/recommended"],
    languageOptions: {
      globals: {
        ...globals.browser,
        Drupal: "writable",
        drupalSettings: "readonly",
        once: "readonly",
        jQuery: "readonly",
        Bokeh: "readonly",
      },
      parserOptions: {
        tsconfigRootDir: import.meta.dirname,
      },
    },
  },
  tseslint.configs.recommended,
  globalIgnores([
    ".ddev",
    "web/**",
    "vendor/**",
    "**/node_modules",
    "**/dist",
    ".phpdoc",
  ]),
]);
