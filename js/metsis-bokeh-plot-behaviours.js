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

    afterRequest(trigger) {
      let checks = 0;
      const maxChecks = 250;
      const interval = window.setInterval(() => {
        checks += 1;

        if (
          window.Bokeh !== undefined &&
          Array.isArray(Bokeh.documents) &&
          Bokeh.documents.length > 0
        ) {
          const isAnyIdle = Bokeh.documents.some((doc) => doc && doc.is_idle);
          if (isAnyIdle) {
            window.clearInterval(interval);
            const target = this.getTarget(trigger);
            const hasContent = !!target && target.innerHTML.trim() !== "";
            this.hideSpinner(trigger);
            this.setOpen(trigger, hasContent);
            trigger.removeAttribute("aria-busy");
            return;
          }
        }

        // Safety stop: don't poll forever.
        if (checks >= maxChecks) {
          window.clearInterval(interval);
          this.onError(trigger);
        }
      }, 200);
    },

    onError(trigger) {
      this.hideSpinner(trigger);
      this.setOpen(trigger, false);
      trigger.removeAttribute("aria-busy");
    },
  };

  Drupal.behaviors.metsisRowPlotToggle = {
    attach(context) {
      once("metsis-row-plot-trigger", ".metsis-plot-trigger", context).forEach(
        (trigger) => {
          Drupal.metsis.rowPlot.setOpen(trigger, false);

          // Fallback listeners for environments where hx-on attributes
          // are not applied consistently on settle events.
          trigger.addEventListener("htmx:afterRequest", () => {
            Drupal.metsis.rowPlot.afterRequest(trigger);
          });
          trigger.addEventListener("htmx:responseError", () => {
            Drupal.metsis.rowPlot.onError(trigger);
          });

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
