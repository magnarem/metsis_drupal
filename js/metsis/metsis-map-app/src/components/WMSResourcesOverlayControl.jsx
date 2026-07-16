import { useEffect, useMemo, useRef, useState } from "preact/hooks";
import TileLayer from "ol/layer/WebGLTile.js";
import TileWMS from "ol/source/TileWMS.js";
import WMSCapabilities from "ol/format/WMSCapabilities.js";
import {
  buildBlacklistedLayerSet,
  buildCapabilitiesUrl,
  collectNamedLayers,
  selectInitialLayer,
} from "@utils/wmsLayerUtils";

function normalizeWmsResources(geojsonFeatures) {
  if (!geojsonFeatures || !Array.isArray(geojsonFeatures.features)) {
    return [];
  }

  const dedup = new Map();

  geojsonFeatures.features.forEach((feature) => {
    const resources = feature?.properties?.wms_resources;
    if (!Array.isArray(resources)) {
      return;
    }

    resources.forEach((resource) => {
      const url = typeof resource?.url === "string" ? resource.url.trim() : "";
      if (!url) {
        return;
      }

      const explicitLayers = Array.isArray(resource?.layers)
        ? resource.layers
            .map((layer) => (typeof layer === "string" ? layer.trim() : ""))
            .filter(Boolean)
        : [];

      const urlLayerParam =
        getQueryParam(url, "LAYERS") || getQueryParam(url, "layers");
      const urlLayers = urlLayerParam
        ? urlLayerParam
            .split(",")
            .map((layer) => layer.trim())
            .filter(Boolean)
        : [];
      const layers = explicitLayers.length > 0 ? explicitLayers : urlLayers;

      const baseUrl = stripQuery(url);
      if (!baseUrl) {
        return;
      }

      const key = `${baseUrl}|${layers.join(",")}`;
      if (!dedup.has(key)) {
        dedup.set(key, { url: baseUrl, layers });
      }
    });
  });

  return Array.from(dedup.values());
}

function stripQuery(url) {
  try {
    const parsed = new URL(url);
    return `${parsed.origin}${parsed.pathname}`;
  } catch {
    return url.split("?")[0] || "";
  }
}

function getQueryParam(url, key) {
  try {
    const parsed = new URL(url);
    return parsed.searchParams.get(key);
  } catch {
    const [, query] = url.split("?");
    if (!query) {
      return null;
    }
    const params = new URLSearchParams(query);
    return params.get(key);
  }
}

const WMSResourcesOverlayControl = ({
  mapInstance,
  geojsonFeatures,
  wmsConfig,
}) => {
  const [isActive, setIsActive] = useState(false);
  const overlayLayersRef = useRef([]);
  const blacklistedLayersSet = useMemo(
    () => buildBlacklistedLayerSet(wmsConfig?.blacklistedLayers),
    [wmsConfig?.blacklistedLayers],
  );
  const preferredLayers = useMemo(
    () =>
      Array.isArray(wmsConfig?.preferredLayers)
        ? wmsConfig.preferredLayers
        : [],
    [wmsConfig?.preferredLayers],
  );

  const wmsResources = useMemo(
    () => normalizeWmsResources(geojsonFeatures),
    [geojsonFeatures],
  );

  useEffect(() => {
    if (!mapInstance) {
      return;
    }

    let isCancelled = false;
    overlayLayersRef.current.forEach((layer) => mapInstance.removeLayer(layer));
    overlayLayersRef.current = [];

    if (!isActive || wmsResources.length === 0) {
      return;
    }

    const capabilitiesFormat = new WMSCapabilities();

    const resolveResourceLayer = async (resource) => {
      let layerCandidates = resource.layers;

      if (layerCandidates.length === 0) {
        try {
          const response = await fetch(buildCapabilitiesUrl(resource.url));
          if (!response.ok) {
            return null;
          }

          const xml = await response.text();
          const parsed = capabilitiesFormat.read(xml);
          const capabilityLayers =
            parsed?.Capability?.Layer?.Layer &&
            Array.isArray(parsed.Capability.Layer.Layer)
              ? parsed.Capability.Layer.Layer
              : [];
          const namedLayers = capabilityLayers.flatMap((layer) =>
            collectNamedLayers(layer, []),
          );
          layerCandidates = namedLayers.map((layer) => layer.name);
        } catch {
          return null;
        }
      }

      const filtered = layerCandidates.filter(
        (layerName) => !blacklistedLayersSet.has(layerName.toLowerCase()),
      );
      if (filtered.length === 0) {
        return null;
      }

      const selected = selectInitialLayer(
        filtered.map((name) => ({ name })),
        preferredLayers,
      );
      if (!selected) {
        return null;
      }

      return {
        url: resource.url,
        layerName: selected,
      };
    };

    const attachLayers = async () => {
      const resolvedLayers = await Promise.all(
        wmsResources.map((resource) => resolveResourceLayer(resource)),
      );

      if (isCancelled) {
        return;
      }

      const overlayLayers = resolvedLayers.filter(Boolean).map((resource) => {
        const params = {
          TILED: true,
          VERSION: "1.3.0",
          LAYERS: resource.layerName,
        };

        const layer = new TileLayer({
          source: new TileWMS({
            url: resource.url,
            params,
          }),
          opacity: 1,
          zIndex: 30,
        });

        mapInstance.addLayer(layer);
        return layer;
      });

      overlayLayersRef.current = overlayLayers;
    };

    attachLayers();

    return () => {
      isCancelled = true;
      overlayLayersRef.current.forEach((layer) =>
        mapInstance.removeLayer(layer),
      );
      overlayLayersRef.current = [];
    };
  }, [
    mapInstance,
    isActive,
    wmsResources,
    blacklistedLayersSet,
    preferredLayers,
  ]);

  useEffect(() => {
    if (wmsResources.length === 0) {
      setIsActive(false);
    }
  }, [wmsResources.length]);

  if (!mapInstance || wmsResources.length === 0) {
    return null;
  }

  return (
    <div className="metsis-wms-resource-controls">
      <button
        type="button"
        className="metsis-wms-resource-toggle"
        onClick={() => setIsActive((previous) => !previous)}
      >
        {isActive
          ? "Hide all WMS resources"
          : `Visualise all WMS resources (${wmsResources.length})`}
      </button>
    </div>
  );
};

export default WMSResourcesOverlayControl;
