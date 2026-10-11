import {
  buildCapabilitiesUrl,
  collectNamedLayers,
  selectInitialLayer,
} from "./wmsLayerUtils.js";
import { deriveCapabilitiesVersion } from "./mapProjection.js";

export function normalizeWmsResources(geojsonFeatures) {
  const resources = new Map();
  const features = Array.isArray(geojsonFeatures?.features)
    ? geojsonFeatures.features
    : [];
  for (const feature of features) {
    const candidates = feature?.properties?.wms_resources;
    if (!Array.isArray(candidates)) continue;
    for (const resource of candidates) {
      if (typeof resource?.url !== "string") continue;
      let parsed;
      try {
        parsed = new URL(resource.url.trim());
      } catch {
        continue;
      }
      if (!["http:", "https:"].includes(parsed.protocol)) continue;
      const parameters = new Map(
        [...parsed.searchParams].map(([key, value]) => [
          key.toUpperCase(),
          value,
        ]),
      );
      const explicit = Array.isArray(resource.layers)
        ? resource.layers.filter(
            (name) => typeof name === "string" && name.trim(),
          )
        : [];
      const layers = [
        ...new Set(
          (explicit.length
            ? explicit
            : (parameters.get("LAYERS") ?? "").split(",")
          )
            .map((name) => name.trim())
            .filter(Boolean),
        ),
      ];
      // Retain endpoint-specific query parameters, not GetMap operation parameters.
      for (const key of [...parsed.searchParams.keys()]) {
        if (
          [
            "SERVICE",
            "REQUEST",
            "VERSION",
            "LAYERS",
            "STYLES",
            "FORMAT",
            "TRANSPARENT",
            "BBOX",
            "WIDTH",
            "HEIGHT",
            "CRS",
            "SRS",
            "TILED",
          ].includes(key.toUpperCase())
        )
          parsed.searchParams.delete(key);
      }
      parsed.hash = "";
      const url = parsed.toString();
      const version = parameters.get("VERSION") || null;
      const key = JSON.stringify([url, [...layers].sort(), version]);
      if (!resources.has(key)) resources.set(key, { url, layers, version });
    }
  }
  return [...resources.values()];
}

export function getSharedLayerNames(resolutions) {
  if (!resolutions.length || resolutions.some((result) => result.error))
    return [];
  const first = new Set(resolutions[0].layerNames);
  if (!first.size) return [];
  return resolutions.every((result) => {
    const names = new Set(result.layerNames);
    return (
      names.size === first.size && [...first].every((name) => names.has(name))
    );
  })
    ? [...first]
    : [];
}

export function updateOverlayLayerNames(overlays, name) {
  overlays.forEach((overlay) =>
    overlay.getSource()?.updateParams({ LAYERS: name }),
  );
}

export async function resolveWmsResource(
  resource,
  { blacklistedLayers, preferredLayers, signal, fetchCapabilities },
) {
  try {
    const parsed = await fetchCapabilities(
      buildCapabilitiesUrl(resource.url),
      signal,
    );
    signal?.throwIfAborted();
    if (!parsed?.Capability?.Layer) throw new Error("Invalid WMS capabilities");
    const advertised = collectNamedLayers(parsed.Capability.Layer).map(
      (layer) => layer.name,
    );
    // Explicit restrictions are authoritative, never replaced by capability names.
    const names = resource.layers.length ? resource.layers : advertised;
    const layerNames = [...new Set(names)].filter(
      (name) => !blacklistedLayers.has(name.toLowerCase()),
    );
    if (!layerNames.length) throw new Error("No selectable WMS layers");
    return {
      ...resource,
      layerNames,
      layerName: selectInitialLayer(
        layerNames.map((name) => ({ name })),
        preferredLayers,
      ),
      version: deriveCapabilitiesVersion(parsed),
    };
  } catch (error) {
    if (signal?.aborted || error.name === "AbortError") throw error;
    return {
      ...resource,
      error: error.message || "Capabilities request failed",
      layerNames: [],
    };
  }
}
