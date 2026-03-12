/**
 * @file
 * Javascript for testing the metsi map app.
 */

const initialConfig = drupalSettings.MetsisMapConfig || {
  defaultProjection: "EPSG:3857",
  mapOptions: {
    center: [0, 0],
    zoom: 2,
  },
  features: {
    geojson: false,
    geojsonData: null,
    boundingBox: false,
    wms: false,
    wmsUrl: null,
    defaultWmsLayers: null,
    geocoder: false,
    layerSwitcher: false,
    projectionSwitcher: true,
    supportedProjections: {
      "EPSG:4326": "WGS 84",
      "EPSG:3857": "Pseudo-Mercator",
    },
    defaultProjection: "EPSG:3857",
  },
};

// keep track of whether the map has been initialized
let isMapInitialized = false;

(function ($, Drupal) {
  "use strict";
  /**
   * Initialize map.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the map to the map container.
   */
  Drupal.behaviors.metsisMapApp = {
    /**
     * @param context
     * @param drupalSettings
     * @param {Object} drupalSettings.metsisMap
     */
    attach: function () {
      $("#metsis-map-app").map(function () {
        if (
          !isMapInitialized &&
          typeof window.initializeMetsisMapApp == "function"
        ) {
          console.log("Initializing the MetsisMapApp...");
          window.initializeMetsisMapApp(initialConfig);
          isMapInitialized = true; // Mark as initialized.
        }
      });

      // Listen for AJAX complete events to update the map app dynamically.
      $(document).ajaxComplete(function () {
        console.log("AJAX complete - updating map app...");
        //const updatedConfig = settings.MetsisMapConfig || {};
        //window.initializeMapApp(updatedConfig); // Update the app with new config.
      });
    },
    //detach: function (context, drupalSettings) {},
  };
})(jQuery, Drupal);
