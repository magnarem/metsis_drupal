/* Declare Globals */
/* global DrupalSettings, jQueryStatic, TransliterateType, Once */
declare global {
  interface Window {
    jQuery: jQueryStatic;
    drupalSettings: DrupalSettings;
    transliterate: TransliterateType;
    once: Once;
    Drupal: Drupal;
  }
}
export {};
