import "ol/ol.css";
import "./style.css";

// Selector for the map container
// const mapContainerSelector = "#bbox-map-filter-container";

// Define variables for map and layers
let map;
let bboxFilterSource;
let bboxFilterVectorLayer;
let olModulesPromise;

const MAP_PROJECTION = "EPSG:3857";
const WEB_MERCATOR_MIN_X = -20037508.342789244;
const WEB_MERCATOR_MAX_X = 20037508.342789244;

function ensureAttributionContainer(mapContainer) {
  const existing = document.getElementById("bbox-map-filter-attribution");
  if (existing) {
    return existing;
  }

  const container = document.createElement("div");
  container.id = "bbox-map-filter-attribution";
  mapContainer.insertAdjacentElement("afterend", container);

  return container;
}

function getBboxInputs(root = document) {
  const minX = root.querySelector('input[name="bbox[minX]"]');
  const maxX = root.querySelector('input[name="bbox[maxX]"]');
  const minY = root.querySelector('input[name="bbox[minY]"]');
  const maxY = root.querySelector('input[name="bbox[maxY]"]');

  if (minX && maxX && minY && maxY) {
    return [minX, maxX, minY, maxY];
  }

  return [];
}

function loadOlModules() {
  if (!olModulesPromise) {
    olModulesPromise = Promise.all([
      import("ol/Map.js"),
      import("ol/View.js"),
      import("ol/Feature.js"),
      import("ol/geom/Polygon.js"),
      import("ol/interaction/Draw.js"),
      import("ol/control/defaults.js"),
      import("ol/layer/WebGLTile.js"),
      import("ol/source/OSM.js"),
      import("ol/layer/Vector.js"),
      import("ol/source/Vector.js"),
      import("ol/proj.js"),
      import("ol/control/Attribution.js"),
    ]).then(
      ([
        mapModule,
        viewModule,
        featureModule,
        polygonModule,
        drawModule,
        controlsModule,
        tileLayerModule,
        osmModule,
        vectorLayerModule,
        vectorSourceModule,
        projModule,
        attributionModule,
      ]) => ({
        Map: mapModule.default,
        View: viewModule.default,
        Feature: featureModule.default,
        Polygon: polygonModule.default,
        Draw: drawModule.default,
        createBox: drawModule.createBox,
        defaultControls: controlsModule.defaults,
        TileLayer: tileLayerModule.default,
        OSM: osmModule.default,
        VectorLayer: vectorLayerModule.default,
        VectorSource: vectorSourceModule.default,
        toLonLat: projModule.toLonLat,
        fromLonLat: projModule.fromLonLat,
        transformExtent: projModule.transformExtent,
        Attribution: attributionModule.default,
      }),
    );
  }

  return olModulesPromise;
}

// Function to initialize the map
async function initializeMap() {
  const {
    Map,
    View,
    Feature,
    Polygon,
    Draw,
    createBox,
    defaultControls,
    TileLayer,
    OSM,
    VectorLayer,
    VectorSource,
    toLonLat,
    fromLonLat,
    transformExtent,
    Attribution,
  } = await loadOlModules();

  console.log("Initializing BBOX Filter map...");

  const mapContainer = document.getElementById("bbox-map-filter-container");
  if (!mapContainer) {
    return;
  }
  const attributionContainer = ensureAttributionContainer(mapContainer);

  const form = mapContainer.closest("form");
  const fieldset = mapContainer.closest("fieldset");
  const bboxInputs = fieldset
    ? getBboxInputs(fieldset)
    : getBboxInputs(form ?? document);

  // Add osm baseLayer
  const baseLayer = new TileLayer({
    source: new OSM({
      crossOrigin: "anonymous",
      referrerPolicy: "strict-origin-when-cross-origin",
    }),
  });

  // Add a vector source for holding the bbox draw filter
  bboxFilterSource = new VectorSource({ wrapX: true });

  // Add the bbox source to a bbox Layer
  bboxFilterVectorLayer = new VectorLayer({
    source: bboxFilterSource,
  });

  // Attribution control, collapsed by default
  const attribution = new Attribution({
    target: attributionContainer,
    //className: "bbox-map-filter-attribution",
    collapsible: true,
    collapsed: true,
  });

  // Initialize the map
  map = new Map({
    target: mapContainer,
    layers: [baseLayer, bboxFilterVectorLayer],
    view: new View({
      center: fromLonLat([0, 51.5], MAP_PROJECTION),
      projection: MAP_PROJECTION,
      zoom: 0,
    }),
    controls: defaultControls({ attribution: false }).extend([attribution]),
  });

  const mapProjectionCode = map.getView().getProjection().getCode();

  const features = bboxFilterSource.getFeatures();
  if (features.length) {
    map.getView().fit(bboxFilterSource.getExtent(), { maxZoom: 16 });
  }

  // Add pointermove event to display coordinates
  map.on("pointermove", function (evt) {
    const coords = toLonLat(evt.coordinate, mapProjectionCode);
    const lat = coords[1].toFixed(6);
    const lon = coords[0].toFixed(6);
    const locTxt = "lon: " + lon + " lat: " + lat;

    let coords_wrapper = document.getElementById("coords");
    if (coords_wrapper) {
      let coords_element = coords_wrapper.querySelector(".coords");
      coords_element.innerHTML = locTxt;
    }
  });

  // Add the draw interaction
  addBboxDrawFilterInteraction(
    Draw,
    createBox,
    form,
    bboxInputs,
    mapProjectionCode,
    toLonLat,
    transformExtent,
  );

  // Draw existing bbox if present in input fields
  drawBoundingBoxFromInputs(Polygon, Feature, mapProjectionCode, fromLonLat);
}

// Add draw interaction to the map
function addBboxDrawFilterInteraction(
  Draw,
  createBox,
  form,
  bboxInputs,
  mapProjectionCode,
  toLonLat,
  transformExtent,
) {
  // const bboxGeometryFunction = createRegularPolygon(4);
  const bboxGeometryFunction = createBox();

  const draw = new Draw({
    source: bboxFilterSource,
    type: "Circle",
    geometryFunction: bboxGeometryFunction,
  });

  draw.on("drawstart", function () {
    console.log("drawstart event");
    bboxFilterSource.clear();
  });

  draw.on("drawend", function (event) {
    console.log("drawend event");

    // Remove the previous geometry
    bboxFilterSource.clear();

    // Add the new geometry
    const polygon = event.feature.getGeometry();
    console.log("Drawn polygon coordinates:", polygon.getCoordinates());

    // Get extent in map projection and convert to EPSG:4326 for Solr filters.
    const extent = polygon.getExtent();
    const bboxValues = serializeExtentToBboxValues(
      extent,
      mapProjectionCode,
      toLonLat,
      transformExtent,
    );
    const minX = bboxValues.minX;
    const minY = bboxValues.minY;
    const maxX = bboxValues.maxX;
    const maxY = bboxValues.maxY;

    console.log("BBox values: ENVELOPE(", minX, maxX, maxY, minY, ")");

    // Update the exposed-form fields in the current filter fieldset.
    const originalValues = [
      minX.toFixed(8),
      maxX.toFixed(8),
      minY.toFixed(8),
      maxY.toFixed(8),
    ];

    bboxInputs.forEach((input, index) => {
      if (!input) {
        return;
      }

      input.value = originalValues[index] ?? "";
      input.dispatchEvent(new Event("input", { bubbles: true }));
      input.dispatchEvent(new Event("change", { bubbles: true }));
    });

    // Submit the form after 500ms
    setTimeout(() => {
      if (!form) {
        return;
      }

      const submitButton = form.querySelector('[type="submit"]');
      if (!submitButton) {
        return;
      }

      if (typeof form.requestSubmit === "function") {
        form.requestSubmit(submitButton);
      } else {
        submitButton.click();
      }
    }, 500);
  });

  map.addInteraction(draw);
}

// Helper normalize lon function
function normalizeLon(lon) {
  return ((((lon + 180.0) % 360.0) + 360) % 360) - 180;
}

function clampLat(lat) {
  return Math.max(-90, Math.min(90, lat));
}

function serializeExtentToBboxValues(
  extent,
  mapProjectionCode,
  toLonLat,
  transformExtent,
) {
  const extent4326 = transformExtent(extent, mapProjectionCode, "EPSG:4326");
  const minY = clampLat(Math.min(extent4326[1], extent4326[3]));
  const maxY = clampLat(Math.max(extent4326[1], extent4326[3]));

  const lowerLeft = toLonLat([extent[0], extent[1]], mapProjectionCode);
  const upperLeft = toLonLat([extent[0], extent[3]], mapProjectionCode);
  const lowerRight = toLonLat([extent[2], extent[1]], mapProjectionCode);
  const upperRight = toLonLat([extent[2], extent[3]], mapProjectionCode);

  const leftLonRaw = (lowerLeft[0] + upperLeft[0]) / 2;
  const rightLonRaw = (lowerRight[0] + upperRight[0]) / 2;
  const leftLon = normalizeLon(leftLonRaw);
  const rightLon = normalizeLon(rightLonRaw);

  let crossesDateline =
    leftLonRaw < -180 ||
    leftLonRaw > 180 ||
    rightLonRaw < -180 ||
    rightLonRaw > 180;

  if (
    mapProjectionCode === MAP_PROJECTION &&
    (extent[0] < WEB_MERCATOR_MIN_X || extent[2] > WEB_MERCATOR_MAX_X)
  ) {
    crossesDateline = true;
  }

  if (leftLon > rightLon) {
    crossesDateline = true;
  }

  const minX = crossesDateline ? leftLon : Math.min(leftLon, rightLon);
  const maxX = crossesDateline ? rightLon : Math.max(leftLon, rightLon);

  return {
    minX,
    minY,
    maxX,
    maxY,
  };
}

// Function to draw the bounding box from input values
function drawBoundingBoxFromInputs(
  Polygon,
  Feature,
  mapProjectionCode,
  fromLonLat,
) {
  const bboxInputs = getBboxInputs(document);
  const minXInput = bboxInputs[0] ?? null;
  const maxXInput = bboxInputs[1] ?? null;
  const minYInput = bboxInputs[2] ?? null;
  const maxYInput = bboxInputs[3] ?? null;

  if (
    minXInput &&
    maxXInput &&
    minYInput &&
    maxYInput &&
    minXInput.value &&
    maxXInput.value &&
    minYInput.value &&
    maxYInput.value
  ) {
    const minX = parseFloat(minXInput.value);
    let maxX = parseFloat(maxXInput.value);
    const minY = parseFloat(minYInput.value);
    const maxY = parseFloat(maxYInput.value);
    console.log(
      "BBox values read from inputs: ENVELOPE(",
      minX,
      maxX,
      maxY,
      minY,
    );
    // Handle dateline crossing
    let coordinates;
    if (minX > maxX) {
      maxX += 360;
      // prettier-ignore
      //maxX = (maxX + 360) % 360 - 180;
      console.log("Dateline crossing adjusted maxX: ", maxX);
    }

    // Normal case
    coordinates = [
      [minX, minY],
      [minX, maxY],
      [maxX, maxY],
      [maxX, minY],
      [minX, minY],
    ];

    if (mapProjectionCode !== "EPSG:4326") {
      coordinates = coordinates.map((coordinate) =>
        fromLonLat(coordinate, mapProjectionCode),
      );
    }
    // console.log("Normal coordinates: ", coordinates);

    // Draw the polygon on the map
    const polygon = new Polygon([coordinates]);
    const feature = new Feature(polygon);
    bboxFilterSource.addFeature(feature);
    const features = bboxFilterSource.getFeatures();
    if (features.length) {
      map.getView().fit(bboxFilterSource.getExtent(), { maxZoom: 16 });
      map.getView().setZoom(map.getView().getZoom() - 0.3);
    }
  } else {
    // If inputs are empty, clear the layer source
    bboxFilterSource.clear();
  }
}

/**
 * Drupal behavior for the BBOX map filter.
 *
 * @type {Drupal~behavior}
 *
 * @prop {Drupal~behaviorAttach} attach
 *   Attaches the map to the map container.
 */
(
  function (Drupal, once) {
    Drupal.behaviors.BboxFilter = {
      attach: function (context) {
        once(
          "handle-bbox-map-filter-update",
          ".bbox-map-filter-container",
          context,
        ).forEach(() => {
          console.log("Bbox drupal Behaviour...initialize bbox map filter");
          const container = document.getElementById(
            "bbox-map-filter-container",
          );
          if (!container) {
            return;
          }

          let initialized = false;
          const start = () => {
            if (initialized) {
              return;
            }
            initialized = true;
            initializeMap();
          };

          if ("IntersectionObserver" in window) {
            const observer = new IntersectionObserver(
              (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                  observer.disconnect();
                  start();
                }
              },
              { rootMargin: "200px" },
            );
            observer.observe(container);
          }

          ["pointerenter", "click", "focusin"].forEach((eventName) => {
            container.addEventListener(eventName, start, {
              once: true,
              passive: true,
            });
          });
        });
      },
    };
    //};
  }
)(Drupal, once);
