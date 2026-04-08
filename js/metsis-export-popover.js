/**
 * @file
 * Fallback behavior for export popovers when native Popover API is unavailable.
 */

(function (Drupal, once) {
  "use strict";

  if (Drupal.metsis === undefined) {
    Drupal.metsis = {};
  }

  Drupal.metsis.rowPlot = {
    setOpen(trigger, isOpen) {
      trigger.dataset.plotOpen = isOpen ? "true" : "false";
      trigger.setAttribute("aria-expanded", isOpen ? "true" : "false");
      const label = isOpen
        ? trigger.dataset.labelOpen || "Close plot ×"
        : trigger.dataset.labelClosed || "Plot";
      trigger.textContent = label;

      const target = this.getTarget(trigger);
      if (target && target.parentElement) {
        const closeButton =
          target.parentElement.querySelector(".metsis-plot-close");
        if (closeButton) {
          closeButton.classList.toggle("hidden", !isOpen);
        }
      }
    },

    getTarget(trigger) {
      const id = trigger.dataset.plotTarget;
      return id ? document.getElementById(id) : null;
    },

    getSpinner(trigger) {
      const id = trigger.dataset.plotSpinner;
      return id ? document.getElementById(id) : null;
    },

    showSpinner(trigger) {
      const spinner = this.getSpinner(trigger);
      if (spinner) {
        spinner.classList.remove("hidden");
      }
    },

    hideSpinner(trigger) {
      const spinner = this.getSpinner(trigger);
      if (spinner) {
        spinner.classList.add("hidden");
      }
    },

    closePlot(trigger) {
      const target = this.getTarget(trigger);
      if (target) {
        target.innerHTML = "";
      }
      this.hideSpinner(trigger);
      this.setOpen(trigger, false);
      trigger.removeAttribute("aria-busy");
    },

    beforeRequest(trigger) {
      this.showSpinner(trigger);
      trigger.setAttribute("aria-busy", "true");
    },

    afterSettle(trigger) {
      const finalize = () => {
        const target = this.getTarget(trigger);
        const hasContent = !!target && target.innerHTML.trim() !== "";
        this.hideSpinner(trigger);
        this.setOpen(trigger, hasContent);
        trigger.removeAttribute("aria-busy");
      };

      // If there is already rendered content, hide spinner immediately.
      const targetNow = this.getTarget(trigger);
      if (targetNow && targetNow.innerHTML.trim() !== "") {
        finalize();
        return;
      }

      if (
        window.Bokeh === undefined ||
        !Array.isArray(Bokeh.documents) ||
        Bokeh.documents.length === 0
      ) {
        window.setTimeout(finalize, 250);
        return;
      }

      let checks = 0;
      const maxChecks = 50;
      const interval = window.setInterval(() => {
        checks += 1;
        const isAnyIdle = Bokeh.documents.some((doc) => doc && doc.is_idle);
        if (isAnyIdle || checks >= maxChecks) {
          window.clearInterval(interval);
          finalize();
        }
      }, 200);
    },

    onError(trigger) {
      this.hideSpinner(trigger);
      this.setOpen(trigger, false);
      trigger.removeAttribute("aria-busy");
    },
  };

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

  Drupal.behaviors.metsisRowPlotToggle = {
    attach(context) {
      once("metsis-row-plot-trigger", ".metsis-plot-trigger", context).forEach(
        (trigger) => {
          Drupal.metsis.rowPlot.setOpen(trigger, false);

          // We use a custom HTMX trigger event (metsis:loadPlot).
          // Click handling becomes deterministic: closed => load, open => close.
          trigger.addEventListener("click", (event) => {
            event.preventDefault();
            if (trigger.dataset.plotOpen === "true") {
              event.stopPropagation();
              event.stopImmediatePropagation();
              Drupal.metsis.rowPlot.closePlot(trigger);
              return;
            }

            if (window.htmx && typeof window.htmx.trigger === "function") {
              window.htmx.trigger(trigger, "metsis:loadPlot");
            }
          });
        },
      );

      once("metsis-row-plot-close", ".metsis-plot-close", context).forEach(
        (closeButton) => {
          closeButton.addEventListener("click", (event) => {
            event.preventDefault();
            const triggerId = closeButton.dataset.plotTrigger;
            if (!triggerId) {
              return;
            }
            const trigger = document.getElementById(triggerId);
            if (trigger) {
              Drupal.metsis.rowPlot.closePlot(trigger);
            }
          });
        },
      );
    },
  };
})(Drupal, once);
