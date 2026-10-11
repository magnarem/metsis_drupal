import { useEffect, useMemo, useRef, useState } from "preact/hooks";
import TileLayer from "ol/layer/WebGLTile.js";
import TileWMS from "ol/source/TileWMS.js";
import WMSCapabilities from "ol/format/WMSCapabilities.js";
import {
  buildBlacklistedLayerSet,
  selectInitialLayer,
} from "@utils/wmsLayerUtils";
import {
  getSharedLayerNames,
  normalizeWmsResources,
  resolveWmsResource,
  updateOverlayLayerNames,
} from "@utils/wmsResources";

const WMSResourcesOverlayControl = ({
  mapInstance,
  geojsonFeatures,
  wmsConfig,
}) => {
  const [isActive, setIsActive] = useState(false);
  const [loading, setLoading] = useState(false);
  const [failures, setFailures] = useState([]);
  const [sharedNames, setSharedNames] = useState([]);
  const [selectedName, setSelectedName] = useState("");
  const overlayLayersRef = useRef([]);
  const blacklistedLayers = useMemo(
    () => buildBlacklistedLayerSet(wmsConfig?.blacklistedLayers),
    [wmsConfig?.blacklistedLayers],
  );
  const preferredLayers = useMemo(
    () => wmsConfig?.preferredLayers ?? [],
    [wmsConfig?.preferredLayers],
  );
  const wmsResources = useMemo(
    () => normalizeWmsResources(geojsonFeatures),
    [geojsonFeatures],
  );

  useEffect(() => {
    setFailures([]);
    setSharedNames([]);
    setSelectedName("");
    setLoading(false);
    if (!mapInstance || !isActive || !wmsResources.length) return;

    const controller = new AbortController();
    const overlays = [];
    const format = new WMSCapabilities();
    setLoading(true);
    const fetchCapabilities = async (url, signal) => {
      const response = await fetch(url, { signal });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      return format.read(await response.text());
    };

    const attachLayers = async () => {
      try {
        const resolved = await Promise.all(
          wmsResources.map((resource) =>
            resolveWmsResource(resource, {
              blacklistedLayers,
              preferredLayers,
              signal: controller.signal,
              fetchCapabilities,
            }),
          ),
        );
        if (controller.signal.aborted) return;
        const names = getSharedLayerNames(resolved);
        const initialName = selectInitialLayer(
          names.map((name) => ({ name })),
          preferredLayers,
        );
        resolved
          .filter((resource) => !resource.error)
          .forEach((resource) => {
            const layer = new TileLayer({
              source: new TileWMS({
                url: resource.url,
                params: {
                  TILED: true,
                  VERSION: resource.version,
                  LAYERS: initialName || resource.layerName,
                },
              }),
              opacity: 1,
              zIndex: 30,
            });
            mapInstance.addLayer(layer);
            overlays.push(layer);
          });
        overlayLayersRef.current = overlays;
        setFailures(resolved.filter((resource) => resource.error));
        setSharedNames(names);
        setSelectedName(initialName);
        setLoading(false);
      } catch (error) {
        if (!controller.signal.aborted) {
          setFailures([{ url: "", error: error.message }]);
          setLoading(false);
        }
      }
    };
    attachLayers();

    return () => {
      controller.abort();
      overlays.forEach((layer) => {
        mapInstance.removeLayer(layer);
        layer.getSource()?.dispose();
        layer.dispose();
      });
      overlayLayersRef.current = [];
    };
  }, [mapInstance, isActive, wmsResources, blacklistedLayers, preferredLayers]);

  useEffect(() => {
    if (!wmsResources.length) setIsActive(false);
  }, [wmsResources.length]);

  if (!mapInstance || !wmsResources.length) return null;
  return (
    <div className="metsis-wms-resource-controls">
      <button
        type="button"
        className="metsis-wms-resource-toggle"
        aria-pressed={isActive}
        onClick={() => setIsActive((previous) => !previous)}
      >
        {isActive
          ? Drupal.t("Hide all WMS resources")
          : Drupal.t("Visualise all WMS resources (@count)", {
              "@count": wmsResources.length,
            })}
      </button>
      {isActive && loading && (
        <p role="status">{Drupal.t("Loading WMS capabilities…")}</p>
      )}
      {isActive && !loading && sharedNames.length > 0 && (
        <label>
          {Drupal.t("Layer for all WMS resources")}
          <select
            value={selectedName}
            onChange={(event) => {
              const name = event.currentTarget.value;
              if (!sharedNames.includes(name)) return;
              updateOverlayLayerNames(overlayLayersRef.current, name);
              setSelectedName(name);
            }}
          >
            {sharedNames.map((name) => (
              <option key={name} value={name}>
                {name}
              </option>
            ))}
          </select>
        </label>
      )}
      {isActive && failures.length > 0 && (
        <div role="status">
          <p>
            {Drupal.t(
              "Some WMS resources could not be loaded. A shared layer selector is unavailable.",
            )}
          </p>
          <ul>
            {failures.map((failure) => (
              <li key={failure.url}>
                {failure.url}: {failure.error}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
};

export default WMSResourcesOverlayControl;
