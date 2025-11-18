import { useEffect } from "preact/hooks";
import { Tile as TileLayer } from "ol/layer";
import TileWMS from "ol/source/TileWMS";

const WMSLayerManager = ({ mapInstance, wmsUrls, defaultLayers }) => {
  useEffect(() => {
    if (!mapInstance || !wmsUrls || wmsUrls.length === 0) return;

    // Support both single and multiple WMS URLs.
    const urls = Array.isArray(wmsUrls) ? wmsUrls : [wmsUrls];

    const layers = [];

    urls.forEach((wmsUrl) => {
      defaultLayers.forEach((layerName) => {
        const layer = new TileLayer({
          source: new TileWMS({
            url: wmsUrl,
            params: { LAYERS: layerName, TILED: true },
            serverType: "geoserver",
          }),
        });
        mapInstance.addLayer(layer);
        layers.push(layer);
      });
    });

    return () => {
      // Remove layers on component unmount.
      layers.forEach((layer) => mapInstance.removeLayer(layer));
    };
  }, [mapInstance, wmsUrls, defaultLayers]);

  return null;
};

export default WMSLayerManager;
