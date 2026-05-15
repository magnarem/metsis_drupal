/**
 * @file
 * Fallback behavior for vocabulary info popovers when native Popover API is unavailable.
 */

(function (Drupal, once) {
  "use strict";

  if (Drupal.metsis === undefined) {
    Drupal.metsis = {};
  }

  Drupal.behaviors.metsisVocabPopoverFallback = {
    attach(context) {
      once(
        "metsis-vocab-popover-trigger",
        ".metsis-vocab-popover-trigger",
        context,
      ).forEach((trigger) => {
        const popoverId = trigger.getAttribute("popovertarget");
        if (!popoverId) {
          return;
        }

        const popover = document.getElementById(popoverId);
        if (!popover) {
          return;
        }

        const supportsNativePopover = typeof popover.showPopover === "function";

        if (supportsNativePopover) {
          popover.addEventListener("toggle", () => {
            if (!popover.matches(":popover-open")) {
              return;
            }

            const rect = trigger.getBoundingClientRect();
            const top = rect.bottom + 6;
            const left = Math.min(rect.left, window.innerWidth - 320);

            popover.style.position = "fixed";
            popover.style.top = top + "px";
            popover.style.left = Math.max(12, left) + "px";
            popover.style.margin = "0";
          });

          return;
        }

        popover.hidden = true;

        trigger.addEventListener("click", (event) => {
          event.preventDefault();
          const willOpen = popover.hidden;
          popover.hidden = !willOpen;
          trigger.setAttribute("aria-expanded", willOpen ? "true" : "false");

          if (willOpen) {
            const rect = trigger.getBoundingClientRect();
            const top = rect.bottom + 6 + window.scrollY;
            const left = Math.max(12, Math.min(rect.left + window.scrollX, window.innerWidth - 320));
            popover.style.position = "absolute";
            popover.style.top = top + "px";
            popover.style.left = left + "px";
            popover.style.margin = "0";
          }
        });

        document.addEventListener("click", (event) => {
          if (popover.hidden) {
            return;
          }

          if (!popover.contains(event.target) && !trigger.contains(event.target)) {
            popover.hidden = true;
            trigger.setAttribute("aria-expanded", "false");
          }
        });
      });
    },
  };
})(Drupal, once);
