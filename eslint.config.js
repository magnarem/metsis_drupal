import js from "@eslint/js";
import globals from "globals";
import * as parser from "@typescript-eslint/parser";
import { defineConfig, globalIgnores } from "eslint/config";

export default defineConfig([
  {
    files: ["**/*.{js,mjs,cjs,ts,mts,cts,jsx}"],
    plugins: { js },
    extends: ["js/recommended"],
    languageOptions: {
      parser: parser,
      parserOptions: {
        ecmaVersion: 2020, // Use modern ECMAScript features
        sourceType: "module", // Enable ES modules
        ecmaFeatures: {
          jsx: true, // Enable JSX support
        },
        tsconfigRootDir: import.meta.dirname,
      },
      globals: {
        ...globals.browser,
        Drupal: "writable",
        drupalSettings: "readonly",
        once: "readonly",
        jQuery: "readonly",
        Bokeh: "readonly",
      },
    },
  },
  {
    files: ["**/*.d.ts"],
    rules: {
      "no-unused-vars": "off",
    },
  },
  globalIgnores([
    ".ddev",
    "web/**",
    "vendor/**",
    "docs/**",
    "bla/**",
    "**/node_modules",
    "**/dist",
    ".phpdoc",
  ]),
]);
