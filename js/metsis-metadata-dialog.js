/**
 * @file
 * Behaviors for the HTMX-driven metadata dialog.
 */

(function (Drupal) {
  "use strict";

  const DIALOG_SELECTOR = "dialog[data-metsis-metadata-dialog]";
  const DIALOG_BODY_SELECTOR = ".metsis-metadata-dialog__body";
  const CLOSE_MS = 180;
  let listenersBound = false;

  const resetDialogScroll = (dialog) => {
    if (!dialog) {
      return;
    }

    dialog.scrollTop = 0;
    const body = dialog.querySelector(DIALOG_BODY_SELECTOR);
    if (body instanceof HTMLElement) {
      body.scrollTop = 0;
    }
  };

  const closeDialogWithFade = (dialog) => {
    if (!dialog || dialog.classList.contains("is-closing")) {
      return;
    }

    dialog.classList.add("is-closing");
    window.setTimeout(() => {
      if (dialog.open) {
        dialog.close();
      }
      dialog.remove();
    }, CLOSE_MS);
  };

  Drupal.metsis = Drupal.metsis || {};

  Drupal.behaviors.metsisMetadataDialog = {
    attach() {
      if (listenersBound) {
        return;
      }
      listenersBound = true;

      document.body.addEventListener("htmx:afterSwap", (event) => {
        const target = event?.detail?.target;
        if (!(target instanceof HTMLElement)) {
          return;
        }

        const dialog = target.querySelector(DIALOG_SELECTOR);
        if (!dialog) {
          return;
        }

        resetDialogScroll(dialog);

        if (typeof dialog.showModal === "function" && !dialog.open) {
          dialog.showModal();
        }

        // Some browsers restore scroll after initial layout; enforce top again.
        window.requestAnimationFrame(() => {
          resetDialogScroll(dialog);
        });
      });

      document.addEventListener("click", (event) => {
        const targetElement = event.target instanceof Element ? event.target : null;
        if (!targetElement) {
          return;
        }

        const closeButton = targetElement.closest("[data-metsis-dialog-close]");
        if (closeButton) {
          const dialog = closeButton.closest(DIALOG_SELECTOR);
          closeDialogWithFade(dialog);
          return;
        }

        const dialog = targetElement.closest(DIALOG_SELECTOR);
        if (!dialog) {
          return;
        }

        const rect = dialog.getBoundingClientRect();
        const isInsideDialog =
          event.clientX >= rect.left &&
          event.clientX <= rect.right &&
          event.clientY >= rect.top &&
          event.clientY <= rect.bottom;

        if (!isInsideDialog) {
          closeDialogWithFade(dialog);
        }
      });

      document.addEventListener(
        "cancel",
        (event) => {
          const dialog = event.target.closest?.(DIALOG_SELECTOR) ?? null;
          closeDialogWithFade(dialog);
        },
        true,
      );
    },
  };
})(Drupal);
