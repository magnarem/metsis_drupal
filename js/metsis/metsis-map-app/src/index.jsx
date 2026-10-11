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
import { publishDrupalSettings } from "./hooks/drupalSettingsHook.js";

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
    showAllWms: true,
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

  const instanceSelectors = Object.keys(
    settings?.metsis_drupal?.map_app?.instances ?? {},
  );
  if (Array.isArray(configuredSelectors)) {
    const selectors = configuredSelectors.filter(
      (selector) => typeof selector === "string" && selector.trim() !== "",
    );
    return [...new Set([...selectors, ...instanceSelectors])];
  }

  if (typeof configuredSelectors === "string") {
    const selectors = configuredSelectors
      .split(",")
      .map((selector) => selector.trim())
      .filter(Boolean);
    return [...new Set([...selectors, ...instanceSelectors])];
  }

  return [...new Set([DEFAULT_MOUNT_SELECTOR, ...instanceSelectors])];
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
const mounts = new Map();
let latestSettings = window.drupalSettings ?? {};

function unmount(elem) {
  render(null, elem);
  mounts.delete(elem);
}

Drupal.behaviors.metsisMapApp = {
  attach(context, settings = window.drupalSettings ?? {}) {
    const previousInstances =
      latestSettings?.metsis_drupal?.map_app?.instances ?? {};
    latestSettings = {
      ...latestSettings,
      ...settings,
      metsis_drupal: {
        ...latestSettings.metsis_drupal,
        ...settings.metsis_drupal,
        map_app: {
          ...latestSettings?.metsis_drupal?.map_app,
          ...settings?.metsis_drupal?.map_app,
          instances: {
            ...previousInstances,
            ...settings?.metsis_drupal?.map_app?.instances,
          },
        },
      },
    };
    publishDrupalSettings(settings);
    mounts.forEach((config, elem) => {
      if (!elem.isConnected) unmount(elem);
    });

    // AJAX can replace results while retaining a map outside the replaced fragment.
    getMountSelectors(latestSettings).forEach((selector) => {
      const config = buildMapAppSettings(latestSettings, selector);
      document.querySelectorAll(selector).forEach((elem) => {
        const signature = JSON.stringify(config);
        if (mounts.get(elem) !== signature) {
          initMapApp(elem, config);
          mounts.set(elem, signature);
        }
      });
    });
  },
  detach(context, settings, trigger) {
    if (trigger !== "unload") return;
    mounts.forEach((config, elem) => {
      if (context === elem || context.contains?.(elem)) unmount(elem);
    });
  },
};

document.addEventListener("htmx:beforeCleanupElement", (event) => {
  Drupal.behaviors.metsisMapApp.detach(event.detail.elt, {}, "unload");
});
document.addEventListener("htmx:afterSwap", (event) => {
  Drupal.behaviors.metsisMapApp.attach(
    event.detail.target,
    window.drupalSettings,
  );
});
if (window.jQuery) {
  window.jQuery(document).on("ajaxComplete.metsisMapApp", () => {
    Drupal.behaviors.metsisMapApp.attach(document, window.drupalSettings);
  });
}

// The library may itself arrive in an AJAX/HTMX fragment after Drupal's attach.
Drupal.behaviors.metsisMapApp.attach(document, window.drupalSettings);
