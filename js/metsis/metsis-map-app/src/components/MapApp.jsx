import { useState } from "preact/hooks";

import "@styles/MapApp.css";
import { useReactiveDrupalSettings } from "@hooks/drupalSettingsHook";

import MapContainer from "@components/MapContainer.jsx";
import GeoJSONLayer from "@components/GeoJSONLayer.jsx";
import BoundingBoxDrawer from "@components/BoundingBoxDrawer.jsx";
import ProjectionSwitcher from "@components/ProjectionSwitcher.jsx";
import LayerSwitcher from "@components/LayerSwitcher.jsx";
import WMSLayerManager from "@components/WMSLayerManager.jsx";
import TooltipHover from "@components/TooltipHover";

const MapApp = ({ config }) => {
  // console.log('Projection Switcher Enabled:', config.features.projectionSwitcher);
  // console.log('Bounding Box filter Enabled', config.features.BoundingBoxDrawer);
  const [olMap, setMap] = useState(0); // State to hold the map reference
  const [projection, setProjection] = useState(
    config?.features?.defaultProjection || "EPSG:3857",
  ); //Hold the proejction state
  // The geojson data of the current search from drupal settings
  const geojsonResultData = useReactiveDrupalSettings();
  // Render the MapApp component
  return (
    /* Add the ProjectionSwitcher Component if enabled */
    <div id="map-app-components-wrapper">
      {config.features.projectionSwitcher && olMap && (
        <ProjectionSwitcher
          mapInstance={olMap}
          supportedProjections={config.features.supportedProjections}
          defaultProjection={projection}
          setProjection={setProjection}
        />
      )}

      <MapContainer setMap={setMap} config={config} projection={projection}>
        {/* Add GeoJSON features if enabled*/}
        {config.features.geojson && olMap && (
          <GeoJSONLayer
            mapInstance={olMap}
            geojsonFeatures={geojsonResultData}
            projection={projection}
          />
        )}
        {config.features.geojson && olMap && (
          <TooltipHover mapInstance={olMap} />
        )}
      </MapContainer>

      {/* Enable bounding box drawing if enabled */}
      {config.features.boundingBox && (
        <BoundingBoxDrawer
          mapInstance={olMap}
          onBboxDrawn={config.events.onBboxDrawn}
        />
      )}

      {/* Add WMS layers if enabled */}
      {config.features.wms && (
        <WMSLayerManager
          mapInstance={olMap}
          wmsUrls={config.features.wmsUrl}
          defaultLayers={config.features.defaultWmsLayers}
        />
      )}

      {/* Enable geocoder if enabled */}
      {config.features.geocoder &&
        {
          /* <GeocoderControl
        mapInstance={olMap}
        onGeocode={config.events.onGeocode}
      /> */
        }}

      {/* Add layer switcher if enabled */}
      {config.features.layerSwitcher && <LayerSwitcher mapInstance={olMap} />}

      {/* Add projection switcher if enabled */}
    </div>
  );
};

export default MapApp;
