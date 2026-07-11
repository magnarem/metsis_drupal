import { useEffect, useState } from "preact/hooks";
import "../styles/LegendPanel.css";

/**
 * LegendPanel component displays WMS layer legend.
 *
 * Updates when layer or style selection changes.
 * Resolution changes are intentionally ignored — legend graphics are
 * resolution-independent in this context and re-fetching on every zoom
 * would produce unnecessary network requests.
 */
const LegendPanel = ({
  mapInstance,
  wmsLayer,
  selectedLayer,
  selectedStyle,
}) => {
  const [legendUrl, setLegendUrl] = useState(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState(null);

  // Update legend URL when layer, style, or resolution changes
  useEffect(() => {
    if (!mapInstance || !wmsLayer || !selectedLayer) {
      setLegendUrl(null);
      return;
    }

    const updateLegend = () => {
      setIsLoading(true);
      setError(null);

      try {
        const resolution = mapInstance.getView()?.getResolution();
        const wmsSource = wmsLayer.getSource();

        if (!wmsSource || typeof wmsSource.getLegendUrl !== "function") {
          console.debug(
            "[METSIS/WMS] Legend: No WMS source or getLegendUrl method",
          );
          setLegendUrl(null);
          setIsLoading(false);
          return;
        }
        let legendParams = selectedStyle ? { STYLE: selectedStyle } : undefined;
        if (selectedStyle.includes("boxfill/")) {
          const palette = selectedStyle.split("boxfill/").pop();
          legendParams = selectedStyle ? { PALETTE: palette } : undefined;
        } else {
          legendParams = selectedStyle ? { STYLE: selectedStyle } : undefined;
        }

        const url = wmsSource.getLegendUrl(resolution, legendParams);

        if (!url) {
          console.debug("[METSIS/WMS] Legend: No legend URL available");
          setLegendUrl(null);
        } else {
          console.info("[METSIS/WMS] Legend URL updated", {
            selectedLayer,
            selectedStyle: selectedStyle || "",
            resolution,
            url: url.substring(0, 100) + "...",
          });
          setLegendUrl(url);
        }
      } catch (err) {
        console.warn("[METSIS/WMS] Legend: Error fetching legend URL", err);
        setError(err.message);
        setLegendUrl(null);
      } finally {
        setIsLoading(false);
      }
    };

    // Fetch once on mount / whenever layer or style changes.
    updateLegend();
  }, [mapInstance, wmsLayer, selectedLayer, selectedStyle]);

  // Don't render if no legend
  if (!legendUrl) {
    return null;
  }

  return (
    <div className="metsis-legend-panel">
      <div className="metsis-legend-header">
        <h3 className="metsis-legend-title">Legend</h3>
      </div>
      <div className="metsis-legend-content">
        {isLoading && (
          <div className="metsis-legend-loading">Loading legend...</div>
        )}
        {error && (
          <div className="metsis-legend-error">
            Error loading legend: {error}
          </div>
        )}
        {legendUrl && !isLoading && (
          <img
            src={legendUrl}
            alt="WMS Layer Legend"
            className="metsis-legend-image"
            onLoad={() => setIsLoading(false)}
            onError={() => {
              setError("Failed to load legend image");
              setLegendUrl(null);
            }}
          />
        )}
      </div>
    </div>
  );
};

export default LegendPanel;
