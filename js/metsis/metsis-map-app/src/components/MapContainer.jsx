import { useEffect, useRef } from "preact/hooks";
import "ol/ol.css";
import "../styles/MapContainer.css";
import Map from "ol/Map.js";
import View from "ol/View.js";
// import TileLayer from "ol/layer/Tile";
import TileLayer from "ol/layer/WebGLTile.js";
import Source from "ol/source/ImageTile.js";
// import OSM from "ol/source/OSM";
import ScaleLine from "ol/control/ScaleLine.js";
import { defaults as defaultControls } from "ol/control/defaults.js";
import FullScreen from "ol/control/FullScreen.js";
import { transform } from "ol/proj.js";
import Attribution from "ol/control/Attribution.js";

const MapContainer = ({ setMap, config, projection, children }) => {
  const mapRef = useRef(null); // Reference to the map container

  const attribution = new Attribution({
    collapsible: false,
  });

  // Initialize the map and OL-Cesium
  useEffect(() => {
    if (!mapRef.current || !projection) return;

    // Create OpenLayers map
    console.log("Initializing OpenLayers map...", mapRef.current.id);
    console.log("Projection: ", projection);
    const scaleControl = new ScaleLine({
      units: "metric",
      bar: true,
      text: true,
      steps: 4,
      minWidth: 140,
    });
    const fullScreenControl = new FullScreen();

    const mapControls = [fullScreenControl, scaleControl, attribution];

    const olMap = new Map({
      target: mapRef.current,
      layers: [
        new TileLayer({
          source: new Source({
            url: "https://tile.openstreetmap.org/{z}/{x}/{y}.png",
            crossOrigin: "anonymous",
            attributions:
              '&#169; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors.',
          }), // OpenStreetMap tiles
        }),
      ],
      view: new View({
        center: transform(config.mapOptions.center, "EPSG:4326", projection), // Center at longitude 0, latitude 0
        zoom: config.mapOptions.zoom, // Initial zoom level
        projection: projection,
      }),
      controls: defaultControls().extend(mapControls),
    });

    function checkSize() {
      const small = olMap.getSize()[0] < 600;
      attribution.setCollapsible(small);
      attribution.setCollapsed(small);
      if (small) {
        olMap.removeControl(scaleControl);
      } else {
        olMap.addControl(scaleControl);
      }
    }

    olMap.on("change:size", checkSize);
    checkSize();
    console.log("Map initialized.");

    setMap(olMap);

    // Clean up
    return () => {
      olMap.setTarget(null);
    };
  }, []);

  // Pass projection as prop to children
  return (
    <div
      class="map-container"
      ref={mapRef}
      style={{ width: "100%", height: "600px", position: "relative" }}
    >
      {children && typeof children === "function"
        ? children({ projection })
        : children}
    </div>
  );
};

export default MapContainer;
