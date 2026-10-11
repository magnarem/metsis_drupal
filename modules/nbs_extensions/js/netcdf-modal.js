/**
 * @file
 * Restores focus and scroll after NetCDF modal body swaps.
 */

(function (Drupal) {
  "use strict";

  Drupal.behaviors.nbsNetcdfModal = {
    attach(context) {
      const content = context.matches?.("#nbs-netcdf-modal-content")
        ? context
        : context.querySelector?.("#nbs-netcdf-modal-content");
      const dialog = content?.closest("dialog[data-metsis-metadata-dialog]");
      if (!dialog) {
        return;
      }

      const body = content.closest(".metsis-metadata-dialog__body");
      if (body) {
        body.scrollTop = 0;
      }
      dialog.scrollTop = 0;
      const focus = content.querySelector(
        ".error input, input.error, input:not([type='hidden']):not([disabled]), button:not([disabled]), a[href]",
      );
      if (focus) {
        focus.focus({ preventScroll: true });
      } else {
        content.tabIndex = -1;
        content.focus({ preventScroll: true });
      }
    },
  };
})(Drupal);
