/**
 * @file
 * Shared fallback behavior for row-operation popovers.
 */

(function (Drupal, once) {
  "use strict";

  const VIEWPORT_GUTTER = 12;
  const TRIGGER_GAP = 8;

  const resolveSwapTarget = (trigger, event) => {
    const eventTarget = event?.detail?.target;
    if (eventTarget instanceof HTMLElement) {
      return eventTarget;
    }

    if (!(trigger instanceof HTMLElement)) {
      return null;
    }

    const targetSelector = trigger.getAttribute("hx-target");
    if (!targetSelector) {
      return null;
    }

    return document.querySelector(targetSelector);
  };

  const positionPopover = (trigger, popover, mode) => {
    const triggerRect = trigger.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;

    const popoverWidth = Math.max(1, popover.offsetWidth);
    const popoverHeight = Math.max(1, popover.offsetHeight);

    const spaceBelow = viewportHeight - triggerRect.bottom - VIEWPORT_GUTTER;
    const spaceAbove = triggerRect.top - VIEWPORT_GUTTER;
    const openAbove = spaceBelow < 220 && spaceAbove > spaceBelow;

    let top;
    if (openAbove) {
      top = triggerRect.top - popoverHeight - TRIGGER_GAP;
      top = Math.max(VIEWPORT_GUTTER, top);
    } else {
      top = triggerRect.bottom + TRIGGER_GAP;
      top = Math.min(top, viewportHeight - popoverHeight - VIEWPORT_GUTTER);
      top = Math.max(VIEWPORT_GUTTER, top);
    }

    let left = triggerRect.left;
    left = Math.min(left, viewportWidth - popoverWidth - VIEWPORT_GUTTER);
    left = Math.max(VIEWPORT_GUTTER, left);

    if (mode === "native") {
      popover.style.position = "fixed";
      popover.style.top = top + "px";
      popover.style.left = left + "px";
    } else {
      popover.style.position = "absolute";
      popover.style.top = top + window.scrollY + "px";
      popover.style.left = left + window.scrollX + "px";
    }

    popover.style.margin = "0";
  };

  const attachRowOperationPopovers = (context) => {
    once(
      "metsis-row-popover-trigger",
      ".metsis-popover-trigger",
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
        if (!supportsAnchorPositioning) {
          const repositionIfOpen = () => {
            if (!popover.matches(":popover-open")) {
              return;
            }
            positionPopover(trigger, popover, "native");
          };

          popover.addEventListener("toggle", repositionIfOpen);
          window.addEventListener("resize", repositionIfOpen);
          window.addEventListener("scroll", repositionIfOpen, {
            passive: true,
          });
        }
        return;
      }

      // Fallback: initialize as hidden and toggle on trigger click.
      popover.hidden = true;

      trigger.addEventListener("click", (event) => {
        event.preventDefault();
        const willOpen = popover.hidden;
        popover.hidden = !willOpen;
        trigger.setAttribute("aria-expanded", willOpen ? "true" : "false");

        if (willOpen) {
          positionPopover(trigger, popover, "fallback");
        }
      });

      const repositionIfOpen = () => {
        if (!popover.hidden) {
          positionPopover(trigger, popover, "fallback");
        }
      };
      window.addEventListener("resize", repositionIfOpen);
      window.addEventListener("scroll", repositionIfOpen, { passive: true });

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
  };

  if (Drupal.metsis === undefined) {
    Drupal.metsis = {};
  }

  Drupal.metsis.exportPopover = Drupal.metsis.exportPopover || {};
  Drupal.metsis.exportPopover.afterSwap = (trigger, event) => {
    const target = resolveSwapTarget(trigger, event);
    if (target instanceof HTMLElement) {
      attachRowOperationPopovers(target);
    }
  };

  Drupal.metsis.dataAccessPopover = Drupal.metsis.dataAccessPopover || {};
  Drupal.metsis.dataAccessPopover.afterSwap = (trigger, event) => {
    const target = resolveSwapTarget(trigger, event);
    if (target instanceof HTMLElement) {
      attachRowOperationPopovers(target);
    }
  };

  Drupal.behaviors.metsisExportPopoverFallback = {
    attach(context) {
      attachRowOperationPopovers(context);
    },
  };
})(Drupal, once);
