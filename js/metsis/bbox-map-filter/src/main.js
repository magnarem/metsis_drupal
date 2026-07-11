import "ol/ol.css";
import "./style.css";

// Selector for the map container
// const mapContainerSelector = "#bbox-map-filter-container";

// Define variables for map and layers
let map;
let bboxFilterSource;
let bboxFilterVectorLayer;
let olModulesPromise;

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
      import("ol/layer/Tile.js"),
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
    Attribution,
  } = await loadOlModules();

  console.log("Initializing BBOX Filter map...");

  const mapContainer = document.getElementById("bbox-map-filter-container");
  if (!mapContainer) {
    return;
  }

  const form = mapContainer.closest("form");
  const fieldset = mapContainer.closest("fieldset");
  const bboxInputs = fieldset
    ? getBboxInputs(fieldset)
    : getBboxInputs(form ?? document);

  // Add osm baseLayer
  const baseLayer = new TileLayer({
    source: new OSM(),
  });

  // Add a vector source for holding the bbox draw filter
  bboxFilterSource = new VectorSource({ wrapX: true });

  // Add the bbox source to a bbox Layer
  bboxFilterVectorLayer = new VectorLayer({
    source: bboxFilterSource,
  });

  // Attribution control, collapsed by default
  const attribution = new Attribution({
    collapsible: true,
    collapsed: true,
  });

  // Initialize the map
  map = new Map({
    target: "bbox-map-filter-container",
    layers: [baseLayer, bboxFilterVectorLayer],
    view: new View({
      center: fromLonLat([0, 51.5]),
      projection: "EPSG:4326",
      zoom: 0,
    }),
    controls: defaultControls().extend(attribution),
  });

  const features = bboxFilterSource.getFeatures();
  if (features.length) {
    map.getView().fit(bboxFilterSource.getExtent(), { maxZoom: 16 });
  }

  // Add pointermove event to display coordinates
  map.on("pointermove", function (evt) {
    const coords = toLonLat(evt.coordinate, "EPSG:4326");
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
  addBboxDrawFilterInteraction(Draw, createBox, form, bboxInputs);

  // Draw existing bbox if present in input fields
  drawBoundingBoxFromInputs(Polygon, Feature);
}

// Add draw interaction to the map
function addBboxDrawFilterInteraction(Draw, createBox, form, bboxInputs) {
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

    console.log("Polygon get Extent:", polygon.getExtent());
    console.log(
      "Polygon Extent transformed:",
      polygon.transform("EPSG:4326", "EPSG:4326").getExtent(),
    );
    //Get the extent of the polygon
    const extent = polygon.getExtent();
    let minX = extent[0];
    const minY = extent[1];
    let maxX = extent[2];
    const maxY = extent[3];
    // Test if crosses dateline, if so normalize lon values
    let crossesDateline;
    if (minX < -180 || minX > 180) {
      crossesDateline = true;
      console.log(
        "minX: ",
        minX,
        " Crosses dateline. Normalizing...: ",
        crossesDateline,
      );
      minX = normalizeLon(minX);
    }
    if (maxX < -180 || maxX > 180) {
      crossesDateline = true;
      console.log(
        "maxX: ",
        maxX,
        " Crosses dateline. Normalizing...: ",
        crossesDateline,
      );
      maxX = normalizeLon(maxX);
    }

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

// Function to draw the bounding box from input values
function drawBoundingBoxFromInputs(Polygon, Feature) {
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
(function (Drupal, once) {
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
