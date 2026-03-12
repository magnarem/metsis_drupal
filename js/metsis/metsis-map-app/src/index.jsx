// Add debugging if we are in development mode
if (import.meta.env.MODE === "development") {
  import("preact/debug");
}

import { render } from "preact";
import MapApp from "@components/MapApp";
import "@styles/main.css";
import "./projections";

// Some basic logging to confirm the app is running and in which mode.
console.log("Metsis Map App running in " + import.meta.env.MODE + " mode.");

// Initial configuration
const mapAppSettings = {
  mapOptions: {
    center: [7, 50],
    zoom: 2,
  },
  features: {
    geojson: true,
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
      "EPSG:32661": "UPS North (WGS 84)",
      "EPSG:32761": "UPS South (WGS 84)",
    },
    defaultProjection: "EPSG:4326",
    defaultCenters: {
      "EPSG:4326": [0, 0],
      "EPSG:3857": [0, 0],
      "EPSG:32661": [2000000, 2000000],
      "EPSG:32761": [2000000, 2000000],
    },
  },
};

/**
 * Drupal behavior for the MapApp.
 *
 * @type {Object}
 *
 * @prop {Function} attach
 *   Attaches the map to the map container.
 */
(
  function ($, Drupal, once) {
    Drupal.behaviors.metsisMapApp = {
      attach: function (context, settings) {
        once(
          "initialize-metsis-map-app",
          "#metsis-map-app",
          context,
          settings,
        ).forEach((elem) => {
          console.log("METSIS MapApp Behaviour...initialize map app");
          // First time initialize
          console.log(elem, settings);
          console.log("Rendering map with mapAppSettings:", mapAppSettings);
          render(<MapApp config={mapAppSettings} />, elem);
        });
      },
    };
  }
)(window.jQuery, window.Drupal, window.once);
