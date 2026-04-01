import { useEffect } from "preact/hooks";
import Draw, { createBox } from "ol/interaction/Draw.js";
import VectorLayer from "ol/layer/Vector.js";
import VectorSource from "ol/source/Vector.js";
import { bbox as bboxStrategy } from "ol/loadingstrategy.js";

const BoundingBoxDrawer = ({ mapInstance, onBboxDrawn }) => {
  useEffect(() => {
    if (!mapInstance) return;

    // Add a vector layer for the bounding box.
    const source = new VectorSource({
      strategy: bboxStrategy,
    });
    const layer = new VectorLayer({ source });
    mapInstance.addLayer(layer);

    // Add the draw interaction for rectangles.
    const draw = new Draw({
      source,
      type: "Circle",
      geometryFunction: createBox(),
    });
    mapInstance.addInteraction(draw);

    // Listen for the end of drawing.
    draw.on("drawend", (e) => {
      const extent = e.feature.getGeometry().getExtent();
      onBboxDrawn(extent);
    });

    return () => {
      mapInstance.removeInteraction(draw);
      mapInstance.removeLayer(layer);
    };
  }, [mapInstance]);

  return null;
};

export default BoundingBoxDrawer;
