import { extractLayerGeographicExtent } from "@utils/mapProjection";
import { parseDimensionValues } from "@utils/wmsDimensions";

/**
 * Extract and normalise dimension definitions from a raw OL-parsed WMS capabilities layer.
 * Handles TIME (ISO 8601), ELEVATION/DEPTH/HEIGHT, and any custom DIM_* dimension.
 *
 * @param {object} rawLayer  layer object from OL WMSCapabilities.read()
 * @returns {Array<{name, canonicalName, units, unitSymbol, defaultValue, values}>}
 */
export function collectLayerDimensions(rawLayer) {
  if (!rawLayer?.Dimension) return [];

  const dimArray = Array.isArray(rawLayer.Dimension)
    ? rawLayer.Dimension
    : [rawLayer.Dimension];

  return dimArray
    .filter((dim) => dim?.name)
    .map((dim) => {
      const rawName = String(dim.name);
      // Canonical name: lowercase, DIM_ prefix stripped (per OGC WMS 1.3.0 §C.4)
      const canonicalName = rawName.toLowerCase().replace(/^dim_/, "");
      const values =
        typeof dim.values === "string" && dim.values.trim()
          ? parseDimensionValues(dim.values)
          : [];
      // Honour the server-advertised default; fall back to the first value.
      const defaultValue = dim.default || values[0] || "";

      return {
        name: rawName,
        canonicalName,
        units: dim.units || "",
        unitSymbol: dim.unitSymbol || "",
        defaultValue,
        values,
      };
    })
    .filter((dim) => dim.values.length > 0);
}

function readLegendHref(legendUrl) {
  if (!legendUrl) {
    return null;
  }

  const entry = Array.isArray(legendUrl) ? legendUrl[0] : legendUrl;
  if (!entry || typeof entry !== "object") {
    return null;
  }

  const onlineResource = entry.OnlineResource;
  if (typeof onlineResource === "string" && onlineResource.trim() !== "") {
    return onlineResource;
  }

  if (onlineResource && typeof onlineResource === "object") {
    const href =
      onlineResource["xlink:href"] ||
      onlineResource.href ||
      onlineResource.Href ||
      onlineResource.url;
    if (typeof href === "string" && href.trim() !== "") {
      return href;
    }
  }

  const fallbackHref = entry["xlink:href"] || entry.href || entry.url;
  return typeof fallbackHref === "string" && fallbackHref.trim() !== ""
    ? fallbackHref
    : null;
}

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
          legendUrl: readLegendHref(style?.LegendURL),
        }))
      : layer?.Style && typeof layer.Style === "object"
        ? [
            {
              name: layer.Style?.Name || "",
              title: layer.Style?.Title || layer.Style?.Name || "",
              legendUrl: readLegendHref(layer.Style?.LegendURL),
            },
          ]
        : [];

    layers.push({
      name: layer.Name,
      title: layer.Title || layer.Name,
      abstract: layer.Abstract || null,
      geographicExtent,
      styles,
      // True only when at least one style advertises a LegendURL in capabilities.
      hasLegend: styles.some((style) => !!style.legendUrl),
      dimensions: collectLayerDimensions(layer),
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
