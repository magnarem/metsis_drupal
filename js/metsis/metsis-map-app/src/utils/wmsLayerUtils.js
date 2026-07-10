import { extractLayerGeographicExtent } from "@utils/mapProjection";

export function collectNamedLayers(layer, layers = [], inheritedExtent = null) {
  if (!layer || typeof layer !== "object") {
    return layers;
  }

  const geographicExtent = extractLayerGeographicExtent(layer, inheritedExtent);

  if (typeof layer.Name === "string" && layer.Name.trim() !== "") {
    const styles = Array.isArray(layer.Style)
      ? layer.Style.map((style) => ({
          name: style?.Name || "",
          title: style?.Title || style?.Name || "",
          // LegendURL declared by the server in capabilities — null when absent.
          legendUrl:
            style?.LegendURL?.[0]?.OnlineResource?.["xlink:href"] ||
            null,
        })).filter((style) => style.name)
      : [];

    layers.push({
      name: layer.Name,
      title: layer.Title || layer.Name,
      abstract: layer.Abstract || null,
      geographicExtent,
      styles,
      // True only when at least one style advertises a LegendURL in capabilities.
      hasLegend: styles.some((style) => !!style.legendUrl),
    });
  }

  if (Array.isArray(layer.Layer)) {
    layer.Layer.forEach((childLayer) =>
      collectNamedLayers(
        childLayer,
        layers,
        geographicExtent || inheritedExtent,
      ),
    );
  }

  return layers;
}

export function buildBlacklistedLayerSet(blacklistedLayers) {
  const source = Array.isArray(blacklistedLayers) ? blacklistedLayers : [];
  return new Set(
    source
      .map((layerName) => String(layerName).trim().toLowerCase())
      .filter(Boolean),
  );
}

export function selectInitialLayer(layers, preferredLayers) {
  if (!Array.isArray(layers) || layers.length === 0) {
    return "";
  }

  const preferred = Array.isArray(preferredLayers)
    ? preferredLayers.map((layer) => String(layer).trim().toLowerCase())
    : [];

  const byName = new Map(
    layers
      .filter(
        (layer) => typeof layer?.name === "string" && layer.name.trim() !== "",
      )
      .map((layer) => [layer.name.toLowerCase(), layer.name]),
  );

  for (const candidate of preferred) {
    if (byName.has(candidate)) {
      return byName.get(candidate);
    }
  }

  return layers[0]?.name || "";
}

export function buildCapabilitiesUrl(url) {
  try {
    const parsed = new URL(url);
    parsed.searchParams.set("SERVICE", "WMS");
    parsed.searchParams.set("REQUEST", "GetCapabilities");
    return parsed.toString();
  } catch {
    const separator = url.includes("?") ? "&" : "?";
    return `${url}${separator}SERVICE=WMS&REQUEST=GetCapabilities`;
  }
}
