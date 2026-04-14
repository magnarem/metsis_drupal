/**
 * @file
 * Fallback behavior for export popovers when native Popover API is unavailable.
 */

(function (Drupal, once) {
  "use strict";

  if (Drupal.metsis === undefined) {
    Drupal.metsis = {};
  }

  Drupal.behaviors.metsisExportPopoverFallback = {
    attach(context) {
      once(
        "metsis-export-popover-trigger",
        ".metsis-export-trigger",
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
        const supportsAnchorPositioning = CSS.supports("anchor-name", "--x");

        // If Popover API is supported, let the browser handle it,
        // but still position it near the trigger when anchor positioning
        // is not supported by this browser.
        if (supportsNativePopover) {
          console.log("[metsis-export-popover] Using native Popover API", {
            triggerId: trigger.id || null,
            popoverId,
          });

          if (!supportsAnchorPositioning) {
            console.log(
              "[metsis-export-popover] Native popover without anchor positioning support; applying JS positioning fallback",
              {
                triggerId: trigger.id || null,
                popoverId,
              },
            );
            popover.addEventListener("toggle", () => {
              if (!popover.matches(":popover-open")) {
                return;
              }
              const rect = trigger.getBoundingClientRect();
              popover.style.position = "fixed";
              popover.style.top = rect.bottom + 4 + "px";
              popover.style.left = rect.left + "px";
              popover.style.margin = "0";
            });
          }
          return;
        }

        console.log(
          "[metsis-export-popover] Native Popover API unavailable; using click fallback",
          {
            triggerId: trigger.id || null,
            popoverId,
          },
        );

        // Fallback: initialize as hidden and toggle on trigger click.
        popover.hidden = true;

        trigger.addEventListener("click", (event) => {
          event.preventDefault();
          const willOpen = popover.hidden;
          popover.hidden = !willOpen;
          trigger.setAttribute("aria-expanded", willOpen ? "true" : "false");
          console.log("[metsis-export-popover] Fallback toggle", {
            triggerId: trigger.id || null,
            popoverId,
            isOpen: willOpen,
          });
        });

        document.addEventListener("click", (event) => {
          if (popover.hidden) {
            return;
          }
          if (
            !popover.contains(event.target) &&
            !trigger.contains(event.target)
          ) {
            popover.hidden = true;
            trigger.setAttribute("aria-expanded", "false");
          }
        });
      });
    },
  };
})(Drupal, once);
