/**
 * @file
 * Behaviors for WMS visualization close buttons in search result rows.
 */

(function (Drupal) {
  "use strict";

  if (Drupal.metsis === undefined) {
    Drupal.metsis = {};
  }

  Drupal.metsis.rowWms = {
    closeWms(closeButton) {
      const container = closeButton.closest(".metsis-wms-container");
      if (!container) {
        return;
      }

      // Clear only the HTMX target's innerHTML so the target div stays in
      // the DOM and HTMX can re-inject into it when the user clicks
      // "Visualise WMS" again.
      const target = container.querySelector(".metsis-wms-target");
      if (target) {
        target.innerHTML = "";
      }
    },
  };

  // Use document-level event delegation so close buttons work inside
  // HTMX-injected content without requiring Drupal.attachBehaviors().
  document.addEventListener("click", function (event) {
    const closeButton = event.target.closest("[data-metsis-wms-close]");
    if (!closeButton) {
      return;
    }
    event.preventDefault();
    Drupal.metsis.rowWms.closeWms(closeButton);
  });

})(Drupal);
