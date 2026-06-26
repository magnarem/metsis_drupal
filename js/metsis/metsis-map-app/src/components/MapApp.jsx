import { useEffect, useState } from "preact/hooks";

import "@styles/MapApp.css";
import { useReactiveDrupalSettings } from "@hooks/drupalSettingsHook";

import MapContainer from "@components/MapContainer.jsx";
import GeoJSONLayer from "@components/GeoJSONLayer.jsx";
import ProjectionSwitcher from "@components/ProjectionSwitcher.jsx";
import LayerSwitcher from "@components/LayerSwitcher.jsx";
import TooltipHover from "@components/TooltipHover";
import WMSResourcesOverlayControl from "@components/WMSResourcesOverlayControl.jsx";

const MapApp = ({ config }) => {
  // console.log('Projection Switcher Enabled:', config.features.projectionSwitcher);
  // console.log('Bounding Box filter Enabled', config.features.BoundingBoxDrawer);
  const [olMap, setMap] = useState(0); // State to hold the map reference
  const [projection, setProjection] = useState(
    config?.features?.defaultProjection || "EPSG:3857",
  ); //Hold the proejction state
  const [boundingBoxDrawerModule, setBoundingBoxDrawerModule] = useState(null);
  const [wmsLayerManagerModule, setWmsLayerManagerModule] = useState(null);

  useEffect(() => {
    let isMounted = true;

    if (!config?.features?.boundingBox) {
      setBoundingBoxDrawerModule(null);
      return;
    }

    import("@components/BoundingBoxDrawer.jsx").then((module) => {
      if (isMounted) {
        setBoundingBoxDrawerModule({ component: module.default });
      }
    });

    return () => {
      isMounted = false;
    };
  }, [config?.features?.boundingBox]);

  useEffect(() => {
    let isMounted = true;

    if (!config?.features?.wms) {
      setWmsLayerManagerModule(null);
      return;
    }

    import("@components/WMSLayerManager.jsx").then((module) => {
      if (isMounted) {
        setWmsLayerManagerModule({ component: module.default });
      }
    });

    return () => {
      isMounted = false;
    };
  }, [config?.features?.wms]);

  const BoundingBoxDrawer = boundingBoxDrawerModule?.component || null;
  const WMSLayerManager = wmsLayerManagerModule?.component || null;

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

      {/* Add layer switcher if enabled */}
      {config.features.layerSwitcher && <LayerSwitcher mapInstance={olMap} />}
      {config.features.geojson && olMap && (
        <WMSResourcesOverlayControl
          mapInstance={olMap}
          geojsonFeatures={geojsonResultData}
          wmsConfig={{
            preferredLayers: config.features.wmsPreferredLayers,
            blacklistedLayers: config.features.wmsBlacklistedLayers,
          }}
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
      {config.features.boundingBox && BoundingBoxDrawer && (
        <BoundingBoxDrawer
          mapInstance={olMap}
          onBboxDrawn={config.events.onBboxDrawn}
        />
      )}

      {/* Add WMS layers if enabled */}
      {config.features.wms && WMSLayerManager && (
        <WMSLayerManager
          mapInstance={olMap}
          currentProjection={projection}
          supportedProjectionCodes={Object.keys(
            config?.features?.supportedProjections || {},
          )}
          onProjectionChange={setProjection}
          wmsConfig={{
            endpoints: config.features.wmsEndpoints,
            wmsUrls: config.features.wmsUrl,
            defaultLayers: config.features.defaultWmsLayers,
            preferredLayers: config.features.wmsPreferredLayers,
            blacklistedLayers: config.features.wmsBlacklistedLayers,
          }}
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
    </div>
  );
};

export default MapApp;
