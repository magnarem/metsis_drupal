import { useEffect, useState } from "preact/hooks";
import { formatDimensionDisplayValue, wmsParamKey } from "@utils/wmsDimensions";

/**
 * Human-readable label for a dimension, including unit symbol when present.
 * @param {{canonicalName: string, name: string, unitSymbol: string}} dim
 * @returns {string}
 */
function getDimensionLabel(dim) {
  const symbol = dim.unitSymbol ? ` (${dim.unitSymbol})` : "";
  switch (dim.canonicalName) {
    case "time":
      return "Time";
    case "elevation":
      return `Elevation${symbol}`;
    case "depth":
      return `Depth${symbol}`;
    case "height":
      return `Height${symbol}`;
    default:
      return `${dim.name || dim.canonicalName}${symbol}`;
  }
}

/**
 * Slider + step-button control for a single WMS dimension.
 */
const DimensionRow = ({ dim, currentIndex, onIndexChange }) => {
  const max = dim.values.length - 1;
  const currentValue = dim.values[currentIndex] ?? "";
  const label = getDimensionLabel(dim);
  const displayValue = formatDimensionDisplayValue(
    currentValue,
    dim.units,
    dim.unitSymbol,
  );
  const isTime = dim.canonicalName === "time";

  // Single-value: just display it, no controls.
  if (dim.values.length === 1) {
    return (
      <div className="wms-dimension-row wms-dimension-single">
        <span className="wms-dimension-label">{label}:</span>
        <span className="wms-dimension-value">{displayValue}</span>
      </div>
    );
  }

  return (
    <div className="wms-dimension-row">
      <div className="wms-dimension-header">
        <span className="wms-dimension-label">{label}</span>
        <span className="wms-dimension-value">{displayValue}</span>
      </div>
      <div className="wms-dimension-controls-inner">
        <button
          className="wms-dimension-step"
          type="button"
          disabled={currentIndex <= 0}
          onClick={() => onIndexChange(currentIndex - 1)}
          aria-label={`Previous ${label}`}
        >
          {isTime ? "‹" : "▼"}
        </button>

        <input
          type="range"
          className="wms-dimension-slider"
          min={0}
          max={max}
          value={currentIndex}
          onInput={(e) => onIndexChange(Number(e.target.value))}
          aria-label={`${label} slider`}
        />

        <button
          className="wms-dimension-step"
          type="button"
          disabled={currentIndex >= max}
          onClick={() => onIndexChange(currentIndex + 1)}
          aria-label={`Next ${label}`}
        >
          {isTime ? "›" : "▲"}
        </button>
      </div>
    </div>
  );
};

/**
 * Renders controls for all dimensions of the currently selected WMS layer.
 *
 * Props:
 *   dimensions              — array of dimension defs from collectLayerDimensions()
 *   layerKey                — the selectedLayer name; used to reset state on layer switch
 *   onDimensionParamsChange — callback(params) where params is a WMS param object,
 *                             e.g. { TIME: "2020-01-01T00:00:00.000Z", ELEVATION: "0" }
 */
const WMSDimensionControls = ({
  dimensions,
  layerKey,
  onDimensionParamsChange,
}) => {
  // Map of canonicalName → current value index
  const [indices, setIndices] = useState({});

  // Build WMS params from a (possibly partial) indices map.
  const buildParams = (idxMap) => {
    const params = {};
    for (const dim of dimensions ?? []) {
      const idx = idxMap[dim.canonicalName] ?? 0;
      if (dim.values.length > idx) {
        params[wmsParamKey(dim.canonicalName)] = dim.values[idx];
      }
    }
    return params;
  };

  // Reset indices (and emit initial params) whenever the active layer changes.
  useEffect(() => {
    if (!dimensions?.length) {
      setIndices({});
      onDimensionParamsChange?.({});
      return;
    }

    const init = {};
    for (const dim of dimensions) {
      const defaultIdx = dim.defaultValue
        ? dim.values.indexOf(dim.defaultValue)
        : -1;
      init[dim.canonicalName] = defaultIdx >= 0 ? defaultIdx : 0;
    }
    setIndices(init);
    onDimensionParamsChange?.(buildParams(init));
  }, [layerKey]);
  if (!dimensions?.length) return null;

  const handleIndexChange = (canonicalName, newIndex) => {
    const newIndices = { ...indices, [canonicalName]: newIndex };
    setIndices(newIndices);
    onDimensionParamsChange?.(buildParams(newIndices));
  };

  return (
    <div className="wms-dimension-controls-wrapper">
      {dimensions.map((dim) => (
        <DimensionRow
          key={dim.canonicalName}
          dim={dim}
          currentIndex={indices[dim.canonicalName] ?? 0}
          onIndexChange={(idx) => handleIndexChange(dim.canonicalName, idx)}
        />
      ))}
    </div>
  );
};

export default WMSDimensionControls;
