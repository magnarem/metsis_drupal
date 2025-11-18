/* Declare Globals */
declare global {
  interface Window {
    jQuery: jQueryStatic;
    drupalSettings: DrupalSettings;
    transliterate: TransliterateType;
    Drupal: {
      attachBehaviors: (context: HTMLElement, settings) => void;
    };
  }
}
export {};
