/**
 * @file
 * Fallback behavior for vocabulary info popovers when native Popover API is unavailable.
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

  function positionPopover(trigger, popover, mode) {
    const triggerRect = trigger.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;

    // Ensure we measure rendered dimensions before clamping placement.
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

    const availableHeight = openAbove ? spaceAbove : spaceBelow;
    const viewportCap = viewportHeight - VIEWPORT_GUTTER * 2;
    const desiredMaxHeight = availableHeight - TRIGGER_GAP;
    const maxHeight = Math.max(80, Math.min(viewportCap, desiredMaxHeight));

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
    popover.style.height = "auto";
    popover.style.maxHeight = maxHeight + "px";
    popover.style.overflowY = "auto";
    popover.dataset.positioned = "true";
  }

  if (Drupal.metsis === undefined) {
    Drupal.metsis = {};
  }

  const attachVocabPopovers = (context) => {
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
        popover.dataset.positioned = "false";

        trigger.addEventListener("click", () => {
          // Reset so CSS keeps it hidden until we compute coordinates.
          popover.dataset.positioned = "false";
        });

        const repositionIfOpen = () => {
          if (!popover.matches(":popover-open")) {
            return;
          }

          positionPopover(trigger, popover, "native");
        };

        popover.addEventListener("toggle", repositionIfOpen);
        popover.addEventListener("beforetoggle", (event) => {
          if (event.newState === "open") {
            popover.dataset.positioned = "false";
          }
        });
        window.addEventListener("resize", repositionIfOpen);
        window.addEventListener("scroll", repositionIfOpen, { passive: true });

        return;
      }

      popover.hidden = true;
      popover.dataset.positioned = "false";

      trigger.addEventListener("click", (event) => {
        event.preventDefault();
        const willOpen = popover.hidden;
        popover.hidden = !willOpen;
        trigger.setAttribute("aria-expanded", willOpen ? "true" : "false");

        if (willOpen) {
          positionPopover(trigger, popover, "fallback");
        } else {
          popover.dataset.positioned = "false";
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

  Drupal.metsis.vocabPopover = Drupal.metsis.vocabPopover || {};
  Drupal.metsis.vocabPopover.afterSwap = (trigger, event) => {
    const target = resolveSwapTarget(trigger, event);
    if (target instanceof HTMLElement) {
      attachVocabPopovers(target);
    }
  };

  Drupal.behaviors.metsisVocabPopoverFallback = {
    attach(context) {
      attachVocabPopovers(context);
    },
  };
})(Drupal, once);
