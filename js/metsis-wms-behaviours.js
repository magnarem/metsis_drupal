/**
 * @file
 * Behaviors for WMS visualization close buttons in search result rows.
 */

(function (Drupal, once) {
  "use strict";

  if (Drupal.metsis === undefined) {
    Drupal.metsis = {};
  }

  Drupal.metsis.rowWms = {
    closeWms(closeButton) {
      // Find the parent container (metsis-wms-container).
      const container = closeButton.closest(".metsis-wms-container");
      if (!container) {
        return;
      }

      // Empty the target div to remove the map app and visualization.
      const target = container.querySelector(".metsis-wms-target");
      if (target) {
        target.innerHTML = "";
      }

      // Close the entire container if it's inline mode.
      container.innerHTML = "";
    },
  };

  Drupal.behaviors.metsisRowWmsClose = {
    attach(context) {
      once("metsis-wms-close", "[data-metsis-wms-close]", context).forEach(
        (closeButton) => {
          closeButton.addEventListener("click", (event) => {
            event.preventDefault();
            Drupal.metsis.rowWms.closeWms(closeButton);
          });
        },
      );
    },
  };
})(Drupal, once);
