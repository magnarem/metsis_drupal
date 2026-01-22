/**
 * @file
 * Javascript for controlling the metsis search.
 */

/**
 * Hacking a bit the filter summary ajax events for custom searchbox.
 *
 * @type {Drupal~behavior}
 *
 * @prop {Drupal~behaviorAttach} attach
 *   Attaches the map to the map container.
 */

const isAjaxing = () =>
  Drupal.ajax.instances.some(
    (instance) => instance && instance.ajaxing === true,
  );

(function ($, Drupal, once) {
  "use strict";

  Drupal.behaviors.customSearchBoxReset = {
    attach(context, settings) {
      // Iterate over the views.ajaxViews object to extract view information.
      if (settings?.views?.ajaxViews) {
        once(
          "metsis-handle-searchbox",
          ".views-filters-summary",
          context,
        ).forEach((element) => {
          const filterSummary = element;
          Object.keys(settings.views.ajaxViews).forEach((viewKey) => {
            const viewSettings = settings.views.ajaxViews[viewKey];

            // Extract the view_dom_id.
            const viewDomId = viewSettings.view_dom_id;
            if (viewDomId) {
              // Construct the selector for the view container.
              const viewSelector = `.js-view-dom-id-${viewDomId}`;
              const viewElement = document.querySelector(viewSelector);
              // Find the custom input within the view container.
              const customInput =
                viewElement.querySelector('input[name="text"]');
              filterSummary
                .querySelectorAll("a.remove-filter")
                .forEach((removeLink) => {
                  // Check if the dataset.removeSelector starts with "text:"
                  if (
                    removeLink.dataset.removeSelector?.startsWith("text:")
                    // || removeLink.dataset.removeSelector?.startsWith("bbox:")
                  ) {
                    removeLink.addEventListener("click", () => {
                      // Reset the custom input value.
                      customInput.value = "";

                      // Trigger the form submission via the submit button.
                      const form = customInput.form; // Get the form associated with the input.
                      if (form) {
                        const submitButton =
                          form.querySelector('[type="submit"]'); // Find the submit button.
                        if (submitButton) {
                          $(submitButton).trigger("click"); // Trigger the AJAX behavior.
                        }
                      }
                    });
                  }
                });
            }
          });

          // Handle "reset all" clicks.
          element.querySelectorAll("a.reset").forEach((resetLink) => {
            resetLink.addEventListener("click", () => {
              const customInput = document.querySelector('input[name="text"]');
              if (customInput) {
                customInput.value = ""; // Reset the value.
                //make sure the /views/ajax?text= are removed from the ajax request
                //MAYBE I can call the
              }
            });
          });
        });
      }
    },
  };

  // Create logic for attaching and detaching the Drupal.behaviours
  Drupal.behaviors.metsisMapApp = {
    /**
     * @param context
     * @param drupalSettings
     */
    attach: function (context, drupalSettings) {
      // Listen for AJAX complete events to update the map app dynamically.
      if (drupalSettings?.views?.ajaxViews) {
        once(
          "metsis-handle-map-update",
          ".metsis-results-pager-rows-footer-wrapper",
          context,
        ).forEach(() => {
          $(document).on("ajaxStart", function () {
            console.log(
              "Is Ajaxing: ",
              isAjaxing(),
              " AJAX Started ",
              "Removing previous geojson",
            );
            // console.log("Ajax event: ", event);

            delete drupalSettings?.metsis_drupal?.search.results
              ?.geojson_feature_collection;
          });
          $(document).on("ajaxComplete", function () {
            //console.log("IsAjaxing: ", isAjaxing(), " AJAX complete ");
          });
        });
      }
    },
  };

  Drupal.behaviors.collectionParentFilter = {
    attach: function (context, settings) {
      once(
        "metsis-collection-button",
        ".metsis-collection-button",
        context,
      ).forEach((button) => {
        const collectionId = button.getAttribute("data-collection-id");
        console.log(
          "Collection filter button clicked for collection ID:",
          collectionId,
        );
        console.log("Settings:", settings);
        console.log("context:", context);
        button.addEventListener("click", function (event) {
          event.preventDefault();
          console.log("inside colletion id:", collectionId);

          // Get the collection ID from the button

          // Find the closest form (exposed filter form)
          const form = document.getElementById(
            "views-exposed-form-metsis-search-results",
          );
          if (!form) {
            console.error(
              "Could not find parent form for collection filter button.",
            );
            return;
          }

          // Get the filter input name from settings or fallback to default
          const filterName = "related_dataset";
          const input = form.querySelector(`[name='${filterName}']`);

          if (input) {
            input.value = collectionId;
            // Submit the form (trigger AJAX if present)
            const $form = $(form);
            if ($form.hasClass("js-view-dom-id")) {
              $form.find("[type='submit']").trigger("click");
            } else {
              form.submit();
            }
          } else {
            console.error("Could not find collection filter input in form.");
          }
        });
      });
    },
  };
})(jQuery, Drupal, once);
