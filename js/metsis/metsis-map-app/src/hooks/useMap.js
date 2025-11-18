import { useRef, useEffect, useState } from "preact/hooks";
import { Map, View } from "ol";
import { Tile as TileLayer } from "ol/layer";
import OSM from "ol/source/OSM";

const useMap = (mapOptions) => {
  const mapRef = useRef(null); // Store the map instance.
  const [mapInstance, setMapInstance] = useState(null); // Expose the map instance.

  useEffect(() => {
    if (!mapRef.current) return;

    // Initialize the map only once.
    const map = new Map({
      target: mapRef.current,
      layers: [
        new TileLayer({
          source: new OSM(),
        }),
      ],
      view: new View({
        center: mapOptions.center || [0, 0],
        zoom: mapOptions.zoom || 2,
        projection: mapOptions.projection || "EPSG:3857",
      }),
    });

    // Save the map instance to state.
    setMapInstance(map);

    return () => {
      // Cleanup the map instance on unmount.
      map.setTarget(null);
    };
  }, []);

  return { mapRef, mapInstance };
};

export default useMap;
