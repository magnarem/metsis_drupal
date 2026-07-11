/**
 * @file
 * Metsis Map App entry point.
 *
 * Minimal entry point that imports the mount module (plain JS) for Big Pipe
 * compatibility, along with styles and projections.
 */

// Add debugging if we are in development mode
if (import.meta.env.MODE === "development") {
  import("preact/debug");
}

import MapApp from "@components/MapApp.jsx";
import { render } from "preact";

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
      "EPSG:5041": "WGS 84 / UPS North (E,N)",
      "EPSG:5042": "WGS 84 / UPS South (E,N)",
    },
    defaultProjection: "EPSG:3857",
    defaultCenters: {
      "EPSG:4326": [0, 0],
      "EPSG:3857": [0, 0],
      "EPSG:32661": [2000000, 2000000],
      "EPSG:32761": [2000000, 2000000],
      "EPSG:3574": [1779594.83, -2120838.53],
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

function buildMapAppSettings(settings, selector) {
  const drupalConfig = settings?.mapApp ?? {};
  const instanceConfig =
    settings?.metsis_drupal?.map_app?.instances?.[selector] ?? {};
  const configuredProjection =
    instanceConfig?.features?.defaultProjection ??
    settings?.metsis_drupal?.map_app?.default_projection ??
    drupalConfig?.features?.defaultProjection;
  const supportedProjections = {
    ...baseMapAppSettings.features.supportedProjections,
    ...(drupalConfig?.features?.supportedProjections ?? {}),
    ...(instanceConfig?.features?.supportedProjections ?? {}),
  };

  const mergedSettings = {
    ...baseMapAppSettings,
    ...drupalConfig,
    ...instanceConfig,
    mapOptions: {
      ...baseMapAppSettings.mapOptions,
      ...(drupalConfig.mapOptions ?? {}),
      ...(instanceConfig.mapOptions ?? {}),
    },
    features: {
      ...baseMapAppSettings.features,
      ...(drupalConfig.features ?? {}),
      ...(instanceConfig.features ?? {}),
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
 * Initialize the MapApp component on a DOM element.
 *
 * Deferred JSX rendering — safe to call from Drupal behavior even when
 * Big Pipe is enabled. Preact is ready by the time this is invoked.
 *
 * @param {Element} elem - The mount element.
 * @param {Object} config - The resolved map app configuration.
 */
export function initMapApp(elem, config) {
  console.log("[METSIS MapApp] initializing map app", elem, config);
  render(<MapApp config={config} />, elem);
}

/**
 * Drupal behavior for the MapApp.
 *
 * Uses plain JavaScript (no JSX) at the module scope to avoid
 * Big Pipe + JSX transpilation race conditions.
 *
 * @type {Drupal~behavior}
 *
 * @prop {Drupal~behaviorAttach} attach
 *   Attaches the map to the map container.
 */
(
  function (Drupal, once) {
    Drupal.behaviors.metsisMapApp = {
      attach: function (context, settings) {
        const selectors = getMountSelectors(settings);

        selectors.forEach((selector) => {
          const mapAppSettings = buildMapAppSettings(settings, selector);

          once(
            `initialize-metsis-map-app-${selectorToOnceKey(selector)}`,
            selector,
            context,
          ).forEach((elem) => {
            console.log(
              "METSIS MapApp Behaviour...initialize map app",
              selector,
              elem,
            );
            initMapApp(elem, mapAppSettings);
          });
        });
      },
    };
  }
)(Drupal, once);
