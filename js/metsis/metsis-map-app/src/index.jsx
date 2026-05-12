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

const DEFAULT_MOUNT_SELECTOR = "#metsis-map-app";

// Initial configuration
const baseMapAppSettings = {
  mapOptions: {
    center: [7, 50],
    zoom: 2,
  },
  features: {
    geojson: true,
    boundingBox: false,
    wms: false,
    wmsUrl: null,
    wmsEndpoints: [],
    defaultWmsLayers: null,
    wmsPreferredLayers: [],
    wmsBlacklistedLayers: [],
    geocoder: false,
    layerSwitcher: false,
    projectionSwitcher: true,
    supportedProjections: {
      "EPSG:4326": "WGS 84",
      "EPSG:3857": "Pseudo-Mercator",
      "EPSG:32661": "UPS North (WGS 84)",
      "EPSG:32761": "UPS South (WGS 84)",
    },
    defaultProjection: "EPSG:3857",
    defaultCenters: {
      "EPSG:4326": [0, 0],
      "EPSG:3857": [0, 0],
      "EPSG:32661": [2000000, 2000000],
      "EPSG:32761": [2000000, 2000000],
    },
  },
};

function getMountSelectors(settings) {
  const configuredSelectors =
    settings?.metsis_drupal?.map_app?.mount_selectors ??
    settings?.mapApp?.mountSelectors;

  if (Array.isArray(configuredSelectors)) {
    const selectors = configuredSelectors.filter(
      (selector) => typeof selector === "string" && selector.trim() !== "",
    );
    return selectors.length > 0 ? selectors : [DEFAULT_MOUNT_SELECTOR];
  }

  if (typeof configuredSelectors === "string") {
    const selectors = configuredSelectors
      .split(",")
      .map((selector) => selector.trim())
      .filter(Boolean);
    return selectors.length > 0 ? selectors : [DEFAULT_MOUNT_SELECTOR];
  }

  return [DEFAULT_MOUNT_SELECTOR];
}

function buildMapAppSettings(settings) {
  const drupalConfig = settings?.mapApp ?? {};
  const configuredProjection =
    settings?.metsis_drupal?.map_app?.default_projection;
  const supportedProjections = {
    ...baseMapAppSettings.features.supportedProjections,
    ...(drupalConfig?.features?.supportedProjections ?? {}),
  };

  const mergedSettings = {
    ...baseMapAppSettings,
    ...drupalConfig,
    mapOptions: {
      ...baseMapAppSettings.mapOptions,
      ...(drupalConfig.mapOptions ?? {}),
    },
    features: {
      ...baseMapAppSettings.features,
      ...(drupalConfig.features ?? {}),
      supportedProjections,
    },
  };

  if (
    typeof configuredProjection === "string" &&
    supportedProjections[configuredProjection]
  ) {
    mergedSettings.features.defaultProjection = configuredProjection;
  }

  return mergedSettings;
}

function selectorToOnceKey(selector) {
  return selector.replace(/[^a-zA-Z0-9_-]/g, "_");
}

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
        const selectors = getMountSelectors(settings);
        const mapAppSettings = buildMapAppSettings(settings);

        selectors.forEach((selector) => {
          once(
            `initialize-metsis-map-app-${selectorToOnceKey(selector)}`,
            selector,
            context,
            settings,
          ).forEach((elem) => {
            console.log("METSIS MapApp Behaviour...initialize map app");
            console.log(elem, settings);
            console.log("Rendering map with mapAppSettings:", mapAppSettings);
            render(<MapApp config={mapAppSettings} />, elem);
          });
        });
      },
    };
  }
)(window.jQuery, window.Drupal, window.once);
