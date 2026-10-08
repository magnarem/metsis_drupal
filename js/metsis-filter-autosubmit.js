/**
 * @file
 * Submits spatial and date-range filters when their values are complete.
 */

(function (Drupal, once) {
  "use strict";

  Drupal.behaviors.metsisFilterAutosubmit = {
    attach(context) {
      once(
        "metsis-filter-autosubmit",
        "[data-metsis-bbox-autosubmit], [data-metsis-date-range-autosubmit]",
        context,
      ).forEach((operator) => {
        operator.addEventListener("change", () => {
          const wrapper = operator.closest(
            ".bbox-exposed-filter-wrapper, .fieldset-wrapper",
          );
          const form = operator.closest("form");
          if (!wrapper || !form) {
            return;
          }

          const isDateRange = operator.hasAttribute(
            "data-metsis-date-range-autosubmit",
          );
          const selectors = isDateRange
            ? ["min", "max"].map(
                (bound) => `[data-metsis-date-range-bound="${bound}"]`,
              )
            : ["minX", "maxX", "minY", "maxY"].map(
                (coordinate) => `[data-metsis-bbox-coordinate="${coordinate}"]`,
              );
          const fields = selectors.map((selector) =>
            wrapper.querySelector(selector),
          );
          if (
            fields.some(
              (field) =>
                !field ||
                field.value.trim() === "" ||
                (!isDateRange && !Number.isFinite(Number(field.value))),
            )
          ) {
            return;
          }

          form.querySelector("[data-bef-auto-submit-click]")?.click();
        });
      });
    },
  };
})(Drupal, once);
