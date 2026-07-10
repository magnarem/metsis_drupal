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

// ---------------------------------------------------------------------------
// Explicit mount API
// ---------------------------------------------------------------------------
// Called from hx-on:htmx:AfterSettle on the WMS trigger button so the map
// app initializes (and re-initializes) reliably in HTMX-swapped content.
// Bypasses Drupal.behaviors / once() entirely for HTMX usage.
window.MetsisMapApp = {
  /**
   * Mount (or re-mount) MapApp on a specific DOM element.
   *
   * Reads instance config from the element's data-metsis-map-config JSON
   * attribute. Falls back to an empty config (base defaults only) when the
   * attribute is absent.
   *
   * @param {string} selector - CSS selector for the mount element.
   */
  mount(selector) {
    const elem = document.querySelector(selector);
    if (!elem) {
      console.warn("[METSIS MapApp] mount(): no element for selector", selector);
      return;
    }

    let instanceConfig = {};
    const rawConfig = elem.dataset?.metsisMapConfig;
    if (rawConfig) {
      try {
        instanceConfig = JSON.parse(rawConfig);
      } catch (err) {
        console.warn("[METSIS MapApp] mount(): failed to parse data-metsis-map-config", err);
      }
    }

    // Wrap instanceConfig in the shape buildMapAppSettings expects.
    const settings = {
      metsis_drupal: {
        map_app: {
          mount_selectors: [selector],
          instances: { [selector]: instanceConfig },
        },
      },
    };

    const config = buildMapAppSettings(settings, selector);
    console.info("[METSIS MapApp] mount()", selector, config);
    render(<MapApp config={config} />, elem);
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
if (
  typeof window.Drupal !== "undefined" &&
  typeof window.once !== "undefined"
) {
  (function ($, Drupal, once) {
    Drupal.behaviors.metsisMapApp = {
      attach: function (context, settings) {
        const selectors = getMountSelectors(settings);

        selectors.forEach((selector) => {
          const mapAppSettings = buildMapAppSettings(settings, selector);
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
  })(window.jQuery, window.Drupal, window.once);
} else if (import.meta.env.MODE === "development") {
  // Dev-mode fallback: Drupal and once are absent (plain Vite dev server).
  // Mount directly on the default selector so the app renders in isolation.
  console.info(
    "[METSIS MapApp] Drupal behaviour system not detected — using dev-mode direct mount.",
  );

  const devMount = () => {
    const selectors = getMountSelectors({});
    selectors.forEach((selector) => {
      const elem = document.querySelector(selector);
      if (!elem) {
        console.warn(
          `[METSIS MapApp] Dev mount: no element found for selector "${selector}". ` +
            `Add <div id="metsis-map-app"></div> to your dev HTML page.`,
        );
        return;
      }
      const devSettings = buildMapAppSettings({}, selector);
      console.log("[METSIS MapApp] Dev-mode render into", selector, devSettings);
      render(<MapApp config={devSettings} />, elem);
    });
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", devMount);
  } else {
    devMount();
  }
}
