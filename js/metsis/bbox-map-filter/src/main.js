import "ol/ol.css";
import "./style.css";
import { Map, View } from "ol";
import Feature from "ol/Feature";
import Polygon from "ol/geom/Polygon";
import Draw, { createBox } from "ol/interaction/Draw";
import { defaults as defaultControls } from "ol/control";
import TileLayer from "ol/layer/Tile";
import OSM from "ol/source/OSM";
import VectorLayer from "ol/layer/Vector";
import VectorSource from "ol/source/Vector";
import { toLonLat, fromLonLat } from "ol/proj";
import Attribution from "ol/control/Attribution";

// Selector for the map container
// const mapContainerSelector = "#bbox-map-filter-container";

// Define variables for map and layers
let map;
let bboxFilterSource;
let bboxFilterVectorLayer;

// Function to initialize the map
function initializeMap() {
  console.log("Initializing BBOX Filter map...");

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
  addBboxDrawFilterInteraction();

  // Draw existing bbox if present in input fields
  drawBoundingBoxFromInputs();
}

// Add draw interaction to the map
function addBboxDrawFilterInteraction() {
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

  (function ($) {
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

      // Update the hidden form fields with the bbox values
      document.querySelector('input[name="bbox[minX]"]').value =
        minX.toFixed(8);
      document.querySelector('input[name="bbox[maxX]"]').value =
        maxX.toFixed(8);
      document.querySelector('input[name="bbox[minY]"]').value =
        minY.toFixed(8);
      document.querySelector('input[name="bbox[maxY]"]').value =
        maxY.toFixed(8);

      // Submit the form after 500ms
      setTimeout(() => {
        const form = document.querySelector('input[name="bbox[minX]"]').form;
        if (form) {
          const submitButton = form.querySelector('[type="submit"]');
          if (submitButton) {
            $(submitButton).trigger("click"); // Trigger the AJAX behavior.
          }
        }
      }, 500);
    });
  })(jQuery);

  map.addInteraction(draw);
}

// Helper normalize lon function
function normalizeLon(lon) {
  return ((((lon + 180.0) % 360.0) + 360) % 360) - 180;
}

// Function to draw the bounding box from input values
function drawBoundingBoxFromInputs() {
  const minXInput = document.querySelector('input[name="bbox[minX]"]');
  const maxXInput = document.querySelector('input[name="bbox[maxX]"]');
  const minYInput = document.querySelector('input[name="bbox[minY]"]');
  const maxYInput = document.querySelector('input[name="bbox[maxY]"]');

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
(
  function ($, Drupal, once) {
    Drupal.behaviors.BboxFilter = {
      attach: function (context) {
        once(
          "handle-bbox-map-filter-update",
          ".bbox-map-filter-container",
          context,
        ).forEach(() => {
          console.log("Bbox drupal Behaviour...initialize bbox map filter");
          // First time initialize
          initializeMap();
        });
      },
    };
    //};
  }
)(jQuery, Drupal, once);
