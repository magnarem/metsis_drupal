/**
 * @file
 * Sync the icon color with the button's computed text color on state changes.
 * Uses requestAnimationFrame during CSS transitions so the icon follows
 * the button's animated color, not just its pre- or post-transition value.
 */

(function ($, Drupal, once) {
  "use strict";

  Drupal.behaviors.iconButtonIconSync = {
    attach: function (context) {
      once(
        "metsis-icon-button-sync",
        "[data-component-id='metsis_drupal:icon_button']",
        context,
      ).forEach((wrapper) => {
        const icon = wrapper.querySelector(".icon-button__icon");
        const button = wrapper.querySelector("input[type='submit'], a, button");

        if (!icon || !button) {
          return;
        }

        let rafId = null;

        const syncIconColor = () => {
          icon.style.color = window.getComputedStyle(button).color;
        };

        // Poll each frame until the transition completes.
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
          // Do one final read after the transition has fully settled.
          syncIconColor();
        };

        // Initial sync on page load.
        syncIconColor();

        // Start tracking when state changes begin.
        button.addEventListener("mouseenter", startTracking);
        button.addEventListener("focus", startTracking);

        // Stop tracking once the transition ends or the state reverts.
        button.addEventListener("mouseleave", stopTracking);
        button.addEventListener("blur", stopTracking);

        // transitionend is the definitive signal that the animation is done.
        button.addEventListener("transitionend", stopTracking);
        button.addEventListener("transitioncancel", stopTracking);
      });
    },
  };
})(jQuery, Drupal, once);
