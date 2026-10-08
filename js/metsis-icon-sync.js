/**
 * @file
 * Keeps METSIS form icons in sync with their themed buttons.
 */

(function (Drupal, once) {
  "use strict";

  Drupal.behaviors.metsisIconSync = {
    attach(context) {
      once(
        "metsis-icon-sync",
        "[data-component-id='metsis_drupal:icon_button'], .metsis-search-box__actions",
        context,
      ).forEach((wrapper) => {
        const isSearchBox = wrapper.classList.contains(
          "metsis-search-box__actions",
        );
        const icon = wrapper.querySelector(
          isSearchBox
            ? ".metsis-search-box__button svg"
            : ".icon-button__icon",
        );
        const button = wrapper.querySelector(
          isSearchBox
            ? ".metsis-search-box__button"
            : "input[type='submit'], a, button",
        );

        if (!icon || !button) {
          return;
        }

        let rafId = null;

        const syncIconColor = () => {
          icon.style.color = window.getComputedStyle(button).color;
        };

        const startTracking = () => {
          if (rafId !== null) {
            return;
          }
          const tick = () => {
            syncIconColor();
            rafId = requestAnimationFrame(tick);
          };
          rafId = requestAnimationFrame(tick);
        };

        const stopTracking = () => {
          if (rafId !== null) {
            cancelAnimationFrame(rafId);
            rafId = null;
          }
          syncIconColor();
        };

        syncIconColor();
        button.addEventListener("mouseenter", startTracking);
        button.addEventListener("focus", startTracking);
        button.addEventListener("mouseleave", stopTracking);
        button.addEventListener("blur", stopTracking);
        button.addEventListener("transitionend", stopTracking);
        button.addEventListener("transitioncancel", stopTracking);
      });
    },
  };
})(Drupal, once);
