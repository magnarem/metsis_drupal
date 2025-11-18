import { useEffect } from "preact/hooks";
import { Vector as VectorLayer } from "ol/layer";
import { Vector as VectorSource } from "ol/source";
import GeoJSON from "ol/format/GeoJSON";
import { Style, Fill, Stroke } from "ol/style";

const GeoJSONLayer = ({ mapInstance, geojsonFeatures, projection }) => {
  useEffect(() => {
    if (!mapInstance || !projection || !geojsonFeatures) return;
    console.log("GeoJSON event: ", projection, geojsonFeatures);
    // Remove all previous vector layers except the base layer
    mapInstance.getLayers().forEach((layer) => {
      if (layer instanceof VectorLayer) {
        mapInstance.removeLayer(layer);
      }
    });

    // Always read GeoJSON as EPSG:4326 and transform to map's projection
    const source = new VectorSource({
      wrapX: true,
      features: new GeoJSON().readFeatures(geojsonFeatures, {
        dataProjection: "EPSG:4326",
        featureProjection: projection,
      }),
    });

    const layer = new VectorLayer({
      source: source,
      style: styleFunction,
    });
    mapInstance.addLayer(layer);

    // Optionally fit map to features
    const features = source.getFeatures();
    if (features.length) {
      mapInstance.getView().fit(source.getExtent(), { maxZoom: 16 });
      mapInstance.getView().setZoom(mapInstance.getView().getZoom() - 0.3);
    }

    // Cleanup: Remove the layer when the component unmounts or geojsonData/projection changes.
    return () => {
      mapInstance.removeLayer(layer);
    };
  }, [mapInstance, projection, geojsonFeatures]);

  return null;
};

function styleFunction(feature) {
  const geometry = feature.getGeometry();

  // Determine if the geometry is world-bound
  const isWorld = isWorldBound(geometry);
  // Define fill and stroke styles
  let fillColor, strokeColor;

  if (isWorld) {
    // World-bound geometry
    fillColor = "rgba(52, 5, 79, 0.2)"; // Transparent blue fill
    strokeColor = "rgba(65, 5, 100, 1)"; // Solid blue stroke
  } else {
    fillColor = "rgba(49, 12, 214, 0.5)";
    strokeColor = "rgba(23, 6, 96, 1)"; // Black stroke for other geometries
  }
  // Return a style object
  return new Style({
    fill: new Fill({
      color: fillColor,
    }),
    stroke: new Stroke({
      color: strokeColor,
      width: 2,
    }),
  });
}

// Function to check if a geometry spans the entire world
function isWorldBound(geometry) {
  const extent = geometry.getExtent();
  const width = extent[2] - extent[0]; // maxX - minX
  return width >= 360; // World-bound if longitude covers the entire globe
}

export default GeoJSONLayer;
