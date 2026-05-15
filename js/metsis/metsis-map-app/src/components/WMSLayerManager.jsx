import { useEffect, useMemo, useState } from "preact/hooks";
import TileLayer from "ol/layer/Tile.js";
import TileWMS from "ol/source/TileWMS.js";
import WMSCapabilities from "ol/format/WMSCapabilities.js";

function collectNamedLayers(layer, layers = []) {
  if (!layer || typeof layer !== "object") {
    return layers;
  }

  if (typeof layer.Name === "string" && layer.Name.trim() !== "") {
    layers.push({
      name: layer.Name,
      title: layer.Title || layer.Name,
      styles: Array.isArray(layer.Style)
        ? layer.Style.map((style) => ({
            name: style?.Name || "",
            title: style?.Title || style?.Name || "",
          })).filter((style) => style.name)
        : [],
    });
  }

  if (Array.isArray(layer.Layer)) {
    layer.Layer.forEach((childLayer) => collectNamedLayers(childLayer, layers));
  }

  return layers;
}

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

function selectInitialLayer(filteredLayers, preferredLayers) {
  if (!filteredLayers.length) {
    return "";
  }

  const preferred = Array.isArray(preferredLayers)
    ? preferredLayers.map((layer) => String(layer).trim().toLowerCase())
    : [];
  const byName = new Map(
    filteredLayers.map((layer) => [layer.name.toLowerCase(), layer.name]),
  );

  for (const candidate of preferred) {
    if (byName.has(candidate)) {
      return byName.get(candidate);
    }
  }

  return filteredLayers[0].name;
}

const WMSLayerManager = ({ mapInstance, wmsConfig }) => {
  const [activeEndpointId, setActiveEndpointId] = useState("");
  const [availableLayers, setAvailableLayers] = useState([]);
  const [selectedLayer, setSelectedLayer] = useState("");
  const [selectedStyle, setSelectedStyle] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [capabilitiesError, setCapabilitiesError] = useState("");

  const endpoints = useMemo(() => normalizeEndpoints(wmsConfig), [wmsConfig]);
  const blacklistedLayersSet = useMemo(() => {
    const source = Array.isArray(wmsConfig?.blacklistedLayers)
      ? wmsConfig.blacklistedLayers
      : [];
    return new Set(
      source
        .map((layerName) => String(layerName).trim().toLowerCase())
        .filter(Boolean),
    );
  }, [wmsConfig?.blacklistedLayers]);

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
    };
    if (selectedStyle) {
      params.STYLES = selectedStyle;
    }

    const layer = new TileLayer({
      source: new TileWMS({
        url: activeEndpoint.serviceUrl,
        params,
      }),
    });

    mapInstance.addLayer(layer);

    return () => {
      mapInstance.removeLayer(layer);
    };
  }, [mapInstance, activeEndpoint, selectedLayer, selectedStyle]);

  const selectedLayerDefinition = availableLayers.find(
    (layer) => layer.name === selectedLayer,
  );

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
