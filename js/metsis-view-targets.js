(function (Drupal) {
  "use strict";

  Drupal.behaviors.metsisViewTargets = {
    attach() {
      if (!document.querySelector(".metsis-messages-container")) {
        const messages = document.createElement("div");
        messages.className = "metsis-messages-container";
        document.body.append(messages);
      }
      if (!document.getElementById("metsis-metadata-modal-root")) {
        const root = document.createElement("div");
        root.id = "metsis-metadata-modal-root";
        root.className = "metsis-metadata-modal-target";
        root.setAttribute("aria-live", "polite");
        document.body.append(root);
      }
    },
  };
})(Drupal);
