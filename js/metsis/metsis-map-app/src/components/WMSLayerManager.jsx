import { useEffect, useMemo, useRef, useState } from "preact/hooks";
import TileLayer from "ol/layer/Tile.js";
import TileWMS from "ol/source/TileWMS.js";
import WMSCapabilities from "ol/format/WMSCapabilities.js";
import {
  chooseBestProjectionForExtent,
  deriveCapabilitiesVersion,
  fitViewToGeographicExtent,
  mergeGeographicExtents,
  switchMapViewProjection,
} from "@utils/mapProjection";
import {
  buildBlacklistedLayerSet,
  collectNamedLayers,
  selectInitialLayer,
} from "@utils/wmsLayerUtils";

function normalizeEndpoints(wmsConfig) {
  const configuredEndpoints = Array.isArray(wmsConfig?.endpoints)
    ? wmsConfig.endpoints
    : [];

  if (configuredEndpoints.length > 0) {
    return configuredEndpoints
      .filter((endpoint) => endpoint?.serviceUrl)
      .map((endpoint, index) => ({
        id: endpoint.id || `wms_${index}`,
        label: endpoint.label || endpoint.serviceUrl,
        serviceUrl: endpoint.serviceUrl,
        capabilitiesUrl: endpoint.capabilitiesUrl || endpoint.serviceUrl,
      }));
  }

  const rawUrls = wmsConfig?.wmsUrls;
  const urls = Array.isArray(rawUrls) ? rawUrls : rawUrls ? [rawUrls] : [];

  return urls.filter(Boolean).map((url, index) => ({
    id: `wms_${index}`,
    label: url,
    serviceUrl: url,
    capabilitiesUrl: url,
  }));
}

const WMSLayerManager = ({
  mapInstance,
  wmsConfig,
  currentProjection,
  supportedProjectionCodes = [],
  onProjectionChange,
}) => {
  const [activeEndpointId, setActiveEndpointId] = useState("");
  const [availableLayers, setAvailableLayers] = useState([]);
  const [selectedLayer, setSelectedLayer] = useState("");
  const [selectedStyle, setSelectedStyle] = useState("");
  const [wmsVersion, setWmsVersion] = useState("1.3.0");
  const [isLoading, setIsLoading] = useState(false);
  const [capabilitiesError, setCapabilitiesError] = useState("");
  const lastFittedLayerRef = useRef("");

  const endpoints = useMemo(() => normalizeEndpoints(wmsConfig), [wmsConfig]);
  const blacklistedLayersSet = useMemo(
    () => buildBlacklistedLayerSet(wmsConfig?.blacklistedLayers),
    [wmsConfig?.blacklistedLayers],
  );

  useEffect(() => {
    if (!endpoints.length) {
      setActiveEndpointId("");
      return;
    }

    const endpointExists = endpoints.some(
      (endpoint) => endpoint.id === activeEndpointId,
    );
    if (!endpointExists) {
      setActiveEndpointId(endpoints[0].id);
    }
  }, [endpoints, activeEndpointId]);

  const activeEndpoint = useMemo(
    () =>
      endpoints.find((endpoint) => endpoint.id === activeEndpointId) || null,
    [endpoints, activeEndpointId],
  );

  useEffect(() => {
    if (!activeEndpoint) {
      setAvailableLayers([]);
      setSelectedLayer("");
      setSelectedStyle("");
      return;
    }

    const controller = new AbortController();
    const capabilitiesFormat = new WMSCapabilities();

    const loadCapabilities = async () => {
      setIsLoading(true);
      setCapabilitiesError("");

      try {
        const response = await fetch(activeEndpoint.capabilitiesUrl, {
          signal: controller.signal,
        });
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }

        const xml = await response.text();
        const parsed = capabilitiesFormat.read(xml);
        setWmsVersion(deriveCapabilitiesVersion(parsed));
        const capabilityLayers =
          parsed?.Capability?.Layer?.Layer &&
          Array.isArray(parsed.Capability.Layer.Layer)
            ? parsed.Capability.Layer.Layer
            : [];

        const namedLayers = capabilityLayers.flatMap((layer) =>
          collectNamedLayers(layer, []),
        );
        const filteredLayers = namedLayers.filter(
          (layer) => !blacklistedLayersSet.has(layer.name.toLowerCase()),
        );

        console.info("[METSIS/WMS] Capabilities parsed", {
          endpointId: activeEndpoint.id,
          endpointLabel: activeEndpoint.label,
          namedLayerCount: namedLayers.length,
          filteredLayerCount: filteredLayers.length,
          layersWithExtent: filteredLayers.filter((layer) =>
            Array.isArray(layer.geographicExtent),
          ).length,
        });

        setAvailableLayers(filteredLayers);
        const initialLayer = selectInitialLayer(
          filteredLayers,
          wmsConfig?.preferredLayers,
        );
        setSelectedLayer(initialLayer);

        const initialLayerDefinition = filteredLayers.find(
          (layer) => layer.name === initialLayer,
        );
        setSelectedStyle(initialLayerDefinition?.styles?.[0]?.name || "");
      } catch (error) {
        if (error?.name !== "AbortError") {
          setCapabilitiesError(
            "Failed to load WMS capabilities for the selected endpoint.",
          );
          setWmsVersion("1.3.0");
        }
      } finally {
        setIsLoading(false);
      }
    };

    loadCapabilities();

    return () => {
      controller.abort();
    };
  }, [activeEndpoint, blacklistedLayersSet, wmsConfig?.preferredLayers]);

  useEffect(() => {
    const selectedLayerDefinition = availableLayers.find(
      (layer) => layer.name === selectedLayer,
    );

    const hasStyle = selectedLayerDefinition?.styles?.some(
      (style) => style.name === selectedStyle,
    );
    if (!hasStyle) {
      setSelectedStyle(selectedLayerDefinition?.styles?.[0]?.name || "");
    }
  }, [availableLayers, selectedLayer, selectedStyle]);

  useEffect(() => {
    if (!mapInstance || !activeEndpoint || !selectedLayer) {
      return;
    }

    const params = {
      LAYERS: selectedLayer,
      TILED: true,
      VERSION: wmsVersion,
    };
    if (selectedStyle) {
      params.STYLES = selectedStyle;
    }

    const layer = new TileLayer({
      source: new TileWMS({
        url: activeEndpoint.serviceUrl,
        reprojectionErrorThreshold: 0.1,
        params,
      }),
    });

    mapInstance.addLayer(layer);

    return () => {
      mapInstance.removeLayer(layer);
    };
  }, [mapInstance, activeEndpoint, selectedLayer, selectedStyle, wmsVersion]);

  const selectedLayerDefinition = availableLayers.find(
    (layer) => layer.name === selectedLayer,
  );

  const extentToFit = useMemo(() => {
    const availableExtents = availableLayers
      .map((layer) => layer.geographicExtent)
      .filter(Array.isArray);

    if (availableExtents.length > 1) {
      const merged = mergeGeographicExtents(availableExtents);
      console.info("[METSIS/WMS] Using merged extent across layers", {
        availableExtents,
        mergedExtent: merged,
      });
      return merged;
    }

    const singleExtent =
      selectedLayerDefinition?.geographicExtent || availableExtents[0] || null;
    console.info("[METSIS/WMS] Using single-layer extent", {
      selectedLayer: selectedLayerDefinition?.name || selectedLayer || null,
      extent: singleExtent,
    });
    return singleExtent;
  }, [availableLayers, selectedLayerDefinition]);

  useEffect(() => {
    if (!mapInstance || !extentToFit) {
      return;
    }

    const supportedCodes = Array.isArray(supportedProjectionCodes)
      ? supportedProjectionCodes
      : [];
    const fallbackCode =
      currentProjection || mapInstance.getView()?.getProjection()?.getCode();
    const targetProjection = chooseBestProjectionForExtent(
      extentToFit,
      supportedCodes,
      {
        preferredCode: "EPSG:32661",
        fallbackCode,
      },
    );

    console.info("[METSIS/WMS] Fit decision", {
      selectedLayer: selectedLayer || "all",
      extentToFit,
      supportedCodes,
      currentProjection,
      fallbackCode,
      targetProjection,
    });

    if (!targetProjection) {
      return;
    }

    const fitKey = `${selectedLayer || "all"}|${targetProjection}|${extentToFit.join(",")}`;
    if (lastFittedLayerRef.current === fitKey) {
      console.debug("[METSIS/WMS] Skipping duplicate fit", { fitKey });
      return;
    }

    const switchedProjection = switchMapViewProjection(
      mapInstance,
      targetProjection,
    );
    const didFit = fitViewToGeographicExtent(
      mapInstance,
      extentToFit,
      targetProjection,
      {
        maxZoom: 12,
        padding: [16, 16, 16, 16],
      },
    );

    const view = mapInstance.getView();
    console.info("[METSIS/WMS] Applied map fit", {
      switchedProjection,
      didFit,
      projection: view?.getProjection()?.getCode(),
      center: view?.getCenter?.(),
      zoom: view?.getZoom?.(),
      resolution: view?.getResolution?.(),
      fitKey,
    });

    if (typeof onProjectionChange === "function") {
      onProjectionChange(targetProjection);
    }

    lastFittedLayerRef.current = fitKey;
  }, [
    mapInstance,
    selectedLayer,
    extentToFit,
    supportedProjectionCodes,
    currentProjection,
    onProjectionChange,
  ]);

  if (!endpoints.length) {
    return null;
  }

  return (
    <div className="metsis-wms-controls">
      {endpoints.length > 1 && (
        <label>
          Endpoint
          <select
            value={activeEndpointId}
            onChange={(event) => setActiveEndpointId(event.target.value)}
          >
            {endpoints.map((endpoint) => (
              <option key={endpoint.id} value={endpoint.id}>
                {endpoint.label}
              </option>
            ))}
          </select>
        </label>
      )}

      {isLoading && <p>Loading WMS capabilities...</p>}
      {capabilitiesError && <p>{capabilitiesError}</p>}

      {!isLoading && !capabilitiesError && availableLayers.length === 0 && (
        <p>No renderable layers found after applying blacklist rules.</p>
      )}

      {!isLoading && !capabilitiesError && availableLayers.length > 0 && (
        <>
          <label>
            Layer
            <select
              value={selectedLayer}
              onChange={(event) => setSelectedLayer(event.target.value)}
            >
              {availableLayers.map((layer) => (
                <option key={layer.name} value={layer.name}>
                  {layer.title}
                </option>
              ))}
            </select>
          </label>

          {selectedLayerDefinition?.styles?.length > 0 && (
            <label>
              Style
              <select
                value={selectedStyle}
                onChange={(event) => setSelectedStyle(event.target.value)}
              >
                {selectedLayerDefinition.styles.map((style) => (
                  <option key={style.name} value={style.name}>
                    {style.title}
                  </option>
                ))}
              </select>
            </label>
          )}
        </>
      )}
    </div>
  );
};

export default WMSLayerManager;
