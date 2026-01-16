// Add debugging if we are in development mode
if (import.meta.env.MODE === "development") {
  import("preact/debug");
}

import { render } from "preact";
import MapApp from "@components/MapApp";
import "@styles/main.css";
import "./projections";

console.log("Metsis Map App running in " + import.meta.env.MODE + " mode.");

// Define the container where the map app will be rendered
// const mapAppContainer = document.getElementById("metsis-map-app");

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

// Listen for AJAX complete events to update the map dynamically.
(function ($, Drupal, once) {
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
})(window.jQuery, window.Drupal, window.once);
