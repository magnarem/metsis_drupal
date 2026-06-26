import View from "ol/View.js";
import {
  get as getProjection,
  getPointResolution,
  transform,
  transformExtent,
} from "ol/proj.js";
import { containsExtent } from "ol/extent.js";

const FALLBACK_WORLD_EXTENTS = {
  "EPSG:32661": [-180, 60, 180, 90],
  "EPSG:32761": [-180, -90, 180, -60],
};

export function deriveCapabilitiesVersion(parsedCapabilities) {
  const version =
    parsedCapabilities?.version ||
    parsedCapabilities?.Version ||
    parsedCapabilities?.WMS_Capabilities?.version;

  return typeof version === "string" && version.trim() !== ""
    ? version.trim()
    : "1.3.0";
}

function normalizeNumericExtent(values) {
  if (!Array.isArray(values) || values.length !== 4) {
    return null;
  }

  const normalized = values.map((value) => Number(value));
  if (normalized.some((value) => !Number.isFinite(value))) {
    return null;
  }

  const [minX, minY, maxX, maxY] = normalized;
  if (minX >= maxX || minY >= maxY) {
    return null;
  }

  return normalized;
}

function normalizeExtentValue(value) {
  if (Array.isArray(value)) {
    return normalizeNumericExtent(value);
  }

  if (value && typeof value === "object" && Array.isArray(value.extent)) {
    return normalizeNumericExtent(value.extent);
  }

  return null;
}

function normalizeProjectionCode(code) {
  return typeof code === "string" ? code.trim().toUpperCase() : "";
}

function normalizeBoundingBoxEntry(bbox) {
  if (!bbox || typeof bbox !== "object") {
    return null;
  }

  const projectionCode = normalizeProjectionCode(
    bbox.crs || bbox.CRS || bbox.srs || bbox.SRS,
  );

  const rawExtent =
    normalizeExtentValue(bbox) ||
    normalizeNumericExtent([
      bbox.minx ?? bbox.minX,
      bbox.miny ?? bbox.minY,
      bbox.maxx ?? bbox.maxX,
      bbox.maxy ?? bbox.maxY,
    ]);
  if (!rawExtent) {
    return null;
  }

  if (projectionCode === "EPSG:4326") {
    return {
      projectionCode,
      extent: normalizeNumericExtent([
        rawExtent[1],
        rawExtent[0],
        rawExtent[3],
        rawExtent[2],
      ]),
    };
  }

  if (projectionCode === "CRS:84") {
    return {
      projectionCode,
      extent: rawExtent,
    };
  }

  return {
    projectionCode,
    extent: rawExtent,
  };
}

function extractExtentFromBoundingBoxes(layerDefinition) {
  const rawBoundingBoxes = layerDefinition?.BoundingBox;
  const boundingBoxes = Array.isArray(rawBoundingBoxes)
    ? rawBoundingBoxes
    : rawBoundingBoxes
      ? [rawBoundingBoxes]
      : [];

  const normalizedBoxes = boundingBoxes
    .map((bbox) => normalizeBoundingBoxEntry(bbox))
    .filter(Boolean);

  const epsg4326Box = normalizedBoxes.find(
    (bbox) => bbox.projectionCode === "EPSG:4326",
  );
  if (epsg4326Box?.extent) {
    return epsg4326Box.extent;
  }

  const crs84Box = normalizedBoxes.find(
    (bbox) => bbox.projectionCode === "CRS:84",
  );
  if (crs84Box?.extent) {
    return crs84Box.extent;
  }

  return null;
}

export function mergeGeographicExtents(extents) {
  if (!Array.isArray(extents) || extents.length === 0) {
    return null;
  }

  let mergedExtent = null;

  for (const extent of extents) {
    const normalized = normalizeNumericExtent(extent);
    if (!normalized) {
      continue;
    }

    if (!mergedExtent) {
      mergedExtent = [...normalized];
      continue;
    }

    mergedExtent[0] = Math.min(mergedExtent[0], normalized[0]);
    mergedExtent[1] = Math.min(mergedExtent[1], normalized[1]);
    mergedExtent[2] = Math.max(mergedExtent[2], normalized[2]);
    mergedExtent[3] = Math.max(mergedExtent[3], normalized[3]);
  }

  return mergedExtent;
}

export function extractLayerGeographicExtent(
  layerDefinition,
  fallbackExtent = null,
) {
  const bboxExtent = extractExtentFromBoundingBoxes(layerDefinition);
  if (bboxExtent) {
    return bboxExtent;
  }

  const geographicBbox = layerDefinition?.EX_GeographicBoundingBox;
  const parsedGeographicExtent = normalizeExtentValue(geographicBbox);
  if (parsedGeographicExtent) {
    return parsedGeographicExtent;
  }

  if (geographicBbox && typeof geographicBbox === "object") {
    const parsedExtent = normalizeNumericExtent([
      geographicBbox.westBoundLongitude,
      geographicBbox.southBoundLatitude,
      geographicBbox.eastBoundLongitude,
      geographicBbox.northBoundLatitude,
    ]);

    if (parsedExtent) {
      return parsedExtent;
    }
  }

  const lonLatBbox = layerDefinition?.LatLonBoundingBox;
  const parsedLonLatExtent = normalizeExtentValue(lonLatBbox);
  if (parsedLonLatExtent) {
    return parsedLonLatExtent;
  }

  if (lonLatBbox && typeof lonLatBbox === "object") {
    const parsedExtent = normalizeNumericExtent([
      lonLatBbox.minx,
      lonLatBbox.miny,
      lonLatBbox.maxx,
      lonLatBbox.maxy,
    ]);

    if (parsedExtent) {
      return parsedExtent;
    }
  }

  return normalizeNumericExtent(fallbackExtent);
}

function getWorldExtentForProjection(projectionCode) {
  const projection = getProjection(projectionCode);
  if (!projection) {
    return null;
  }

  const worldExtent = projection.getWorldExtent();
  if (Array.isArray(worldExtent) && worldExtent.length === 4) {
    return worldExtent;
  }

  return FALLBACK_WORLD_EXTENTS[projectionCode] || null;
}

export function extentFitsProjectionWorld(extent4326, projectionCode) {
  const extent = normalizeNumericExtent(extent4326);
  if (!extent) {
    return false;
  }

  const worldExtent = getWorldExtentForProjection(projectionCode);
  if (!worldExtent) {
    return false;
  }

  return containsExtent(worldExtent, extent);
}

export function chooseBestProjectionForExtent(
  extent4326,
  supportedProjectionCodes,
  options = {},
) {
  const supportedCodes = Array.from(
    new Set((supportedProjectionCodes || []).filter(Boolean)),
  );

  if (!supportedCodes.length) {
    return null;
  }

  const preferredCode = options.preferredCode || "EPSG:32661";
  const fallbackCode = options.fallbackCode;

  if (
    supportedCodes.includes(preferredCode) &&
    extentFitsProjectionWorld(extent4326, preferredCode)
  ) {
    return preferredCode;
  }

  for (const code of supportedCodes) {
    if (extentFitsProjectionWorld(extent4326, code)) {
      return code;
    }
  }

  if (fallbackCode && supportedCodes.includes(fallbackCode)) {
    return fallbackCode;
  }

  if (supportedCodes.includes("EPSG:3857")) {
    return "EPSG:3857";
  }

  return supportedCodes[0];
}

export function switchMapViewProjection(mapInstance, targetProjectionCode) {
  if (!mapInstance || !targetProjectionCode) {
    return false;
  }

  const currentView = mapInstance.getView();
  const currentProjection = currentView?.getProjection();
  const newProjection = getProjection(targetProjectionCode);

  if (!currentView || !currentProjection || !newProjection) {
    return false;
  }

  if (currentProjection.getCode() === newProjection.getCode()) {
    return false;
  }

  const currentResolution = currentView.getResolution();
  const currentCenter = currentView.getCenter();
  const currentRotation = currentView.getRotation();

  if (!currentCenter || !Number.isFinite(currentResolution)) {
    return false;
  }

  const newCenter = transform(currentCenter, currentProjection, newProjection);

  const currentMpu = currentProjection.getMetersPerUnit();
  const newMpu = newProjection.getMetersPerUnit();
  const canScaleResolution =
    Number.isFinite(currentMpu) &&
    Number.isFinite(newMpu) &&
    currentMpu > 0 &&
    newMpu > 0;

  let newResolution = currentResolution;
  if (canScaleResolution) {
    const currentPointResolution =
      getPointResolution(
        currentProjection,
        1 / currentMpu,
        currentCenter,
        "m",
      ) * currentMpu;
    const newPointResolution =
      getPointResolution(newProjection, 1 / newMpu, newCenter, "m") * newMpu;

    if (
      Number.isFinite(currentPointResolution) &&
      Number.isFinite(newPointResolution) &&
      newPointResolution > 0
    ) {
      newResolution =
        (currentResolution * currentPointResolution) / newPointResolution;
    }
  }

  const newView = new View({
    center: newCenter,
    resolution: Number.isFinite(newResolution)
      ? newResolution
      : currentResolution,
    rotation: currentRotation,
    projection: newProjection,
  });

  mapInstance.setView(newView);
  return true;
}

export function fitViewToGeographicExtent(
  mapInstance,
  extent4326,
  targetProjectionCode,
  options = {},
) {
  if (!mapInstance || !targetProjectionCode) {
    return false;
  }

  const validExtent = normalizeNumericExtent(extent4326);
  if (!validExtent) {
    return false;
  }

  let projectedExtent;
  try {
    projectedExtent = transformExtent(
      validExtent,
      "EPSG:4326",
      targetProjectionCode,
    );
  } catch {
    return false;
  }

  const normalizedProjectedExtent = normalizeNumericExtent(projectedExtent);
  if (!normalizedProjectedExtent) {
    return false;
  }

  const view = mapInstance.getView();
  if (!view) {
    return false;
  }

  view.fit(normalizedProjectedExtent, {
    size: mapInstance.getSize(),
    padding: options.padding || [16, 16, 16, 16],
    duration: options.duration ?? 250,
    maxZoom: options.maxZoom ?? 8,
  });

  return true;
}
