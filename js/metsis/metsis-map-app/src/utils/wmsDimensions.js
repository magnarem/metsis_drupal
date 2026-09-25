/**
 * Utilities for parsing and handling WMS dimension metadata.
 * Covers TIME (ISO 8601), ELEVATION / DEPTH / HEIGHT, and any custom dimension.
 * No external date library — ISO 8601 duration expansion is done natively.
 */

/** Maximum number of values expanded from a start/end/duration range. */
const MAX_RANGE_VALUES = 1000;

/**
 * Parse an ISO 8601 duration string into its component parts.
 * Handles P[nY][nM][nW][nD][T[nH][nM][nS]].
 *
 * @param {string} isoString  e.g. "PT1H", "P1D", "P1Y2M3DT4H5M6S"
 * @returns {{years:number,months:number,weeks:number,days:number,hours:number,minutes:number,seconds:number}|null}
 *   Component breakdown, or null if unparseable / zero.
 */
export function parseDurationComponents(isoString) {
  if (!isoString || typeof isoString !== "string") return null;

  const match = isoString.match(
    /^P(?:(\d+(?:\.\d+)?)Y)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)W)?(?:(\d+(?:\.\d+)?)D)?(?:T(?:(\d+(?:\.\d+)?)H)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)S)?)?$/,
  );
  if (!match) return null;

  const [, y, mo, w, d, h, mi, s] = match.map((v) =>
    v !== undefined ? parseFloat(v) : 0,
  );

  const hasAny = [y, mo, w, d, h, mi, s].some((v) => v > 0);
  return hasAny
    ? {
        years: y,
        months: mo,
        weeks: w,
        days: d,
        hours: h,
        minutes: mi,
        seconds: s,
      }
    : null;
}

/**
 * Add ISO 8601 duration components to a UTC timestamp.
 * Years/months use calendar-accurate Date arithmetic (leap years, variable month
 * length) rather than fixed-length ms approximations, which drift over long ranges
 * (e.g. P30Y) and can produce a value the WMS server doesn't recognise.
 *
 * @param {number} ms          epoch milliseconds
 * @param {object} components  as returned by parseDurationComponents()
 * @returns {number}  epoch milliseconds
 */
function addDurationComponents(ms, components) {
  const date = new Date(ms);
  if (components.years) {
    date.setUTCFullYear(date.getUTCFullYear() + components.years);
  }
  if (components.months) {
    date.setUTCMonth(date.getUTCMonth() + components.months);
  }

  const exactMsOffset =
    components.weeks * 7 * 86_400_000 +
    components.days * 86_400_000 +
    components.hours * 3_600_000 +
    components.minutes * 60_000 +
    components.seconds * 1_000;

  return date.getTime() + exactMsOffset;
}

/**
 * Expand a WMS start/end/duration range triplet into an array of ISO 8601 strings.
 * Capped at MAX_RANGE_VALUES to prevent runaway expansion for high-frequency datasets.
 *
 * @param {string} start     ISO 8601 timestamp, e.g. "2020-01-01T00:00:00Z"
 * @param {string} end       ISO 8601 timestamp
 * @param {string} duration  ISO 8601 duration, e.g. "PT1H", "P1D"
 * @returns {string[]}
 */
export function expandRangeToValues(start, end, duration) {
  const components = parseDurationComponents(duration);
  if (!components) return start ? [start] : [];

  const startMs = Date.parse(start);
  const endMs = Date.parse(end);
  if (isNaN(startMs) || isNaN(endMs) || startMs > endMs) {
    return start ? [start] : [];
  }

  const values = [];
  let current = startMs;
  while (current <= endMs && values.length < MAX_RANGE_VALUES) {
    values.push(new Date(current).toISOString());
    const next = addDurationComponents(current, components);
    if (next <= current) break;
    current = next;
  }
  return values;
}

/**
 * Parse a WMS dimension values string into an ordered array of value strings.
 * Handles:
 *   - Comma-separated lists:          "0,10,20,50,100"
 *   - ISO 8601 range triplets:        "2020-01-01T00:00:00Z/2021-01-01T00:00:00Z/P1D"
 *   - Mixed (comma-separated ranges): "2020-01-01T00:00:00Z/2020-06-01T00:00:00Z/P1D,2021-01-01T00:00:00Z"
 *
 * @param {string} valuesString
 * @returns {string[]}
 */
export function parseDimensionValues(valuesString) {
  if (!valuesString || typeof valuesString !== "string") return [];

  const result = [];
  for (const segment of valuesString.trim().split(",")) {
    const trimmed = segment.trim();
    if (!trimmed) continue;

    const parts = trimmed.split("/");
    if (parts.length === 3) {
      // start / end / duration range
      const expanded = expandRangeToValues(
        parts[0].trim(),
        parts[1].trim(),
        parts[2].trim(),
      );
      result.push(...expanded);
    } else if (parts.length === 2 && parts[0].trim() && parts[1].trim()) {
      // start / end with no resolution — include both endpoints only
      result.push(parts[0].trim(), parts[1].trim());
    } else {
      result.push(trimmed);
    }
  }

  // Remove duplicates while preserving order
  return [...new Set(result)];
}

/**
 * Return the WMS request parameter key for a dimension's canonical name.
 * Per OGC WMS 1.3.0 spec §C.4:
 *   "time"                      → TIME
 *   "elevation"|"depth"|"height"→ ELEVATION
 *   anything else               → DIM_<NAME>
 *
 * @param {string} canonicalName  lowercase, DIM_-stripped name
 * @returns {string}
 */
export function wmsParamKey(canonicalName) {
  switch (canonicalName) {
    case "time":
      return "TIME";
    case "elevation":
    case "depth":
    case "height":
      return "ELEVATION";
    default:
      return `DIM_${canonicalName.toUpperCase()}`;
  }
}

/**
 * Format a dimension value for human-readable display.
 *
 * - ISO 8601 timestamps are rendered as "YYYY-MM-DD HH:mm UTC".
 * - Numeric values get the unit symbol appended.
 *
 * @param {string} value
 * @param {string} [units]      e.g. "ISO8601", "m", "hPa"
 * @param {string} [unitSymbol] optional, falls back to units string
 * @returns {string}
 */
export function formatDimensionDisplayValue(
  value,
  units = "",
  unitSymbol = "",
) {
  if (!value) return "";

  const looksLikeTime =
    (units && units.toUpperCase() === "ISO8601") ||
    /^\d{4}-\d{2}-\d{2}T/.test(value) ||
    /^\d{4}-\d{2}-\d{2}$/.test(value);

  if (looksLikeTime) {
    try {
      const d = new Date(value);
      if (!isNaN(d.getTime())) {
        // "2020-01-15 06:00 UTC"
        return d.toISOString().slice(0, 16).replace("T", " ") + " UTC";
      }
    } catch {
      // fall through to raw value
    }
  }

  const symbol = unitSymbol || units || "";
  return symbol ? `${value} ${symbol}` : value;
}

/**
 * Format a dimension value for use in a WMS request parameter.
 * Some WMS servers reject sub-second precision on TIME (expect %Y-%m-%dT%H:%M:%SZ).
 *
 * @param {string} canonicalName
 * @param {string} value
 * @returns {string}
 */
export function formatWmsParamValue(canonicalName, value) {
  if (canonicalName === "time" && typeof value === "string") {
    return value.replace(/\.\d+Z$/, "Z");
  }
  return value;
}

/**
 * Build an initial WMS dimension params object from a layer's dimension definitions,
 * honouring each dimension's advertised default value when available.
 *
 * @param {Array<{canonicalName: string, defaultValue: string, values: string[]}>} dims
 * @returns {object}  e.g. { TIME: "2020-01-01T00:00:00Z", ELEVATION: "0" }
 */
export function buildInitialDimParams(dims) {
  if (!Array.isArray(dims)) return {};
  const params = {};
  for (const dim of dims) {
    if (!dim.values.length) continue;
    const defaultIdx = dim.defaultValue
      ? dim.values.indexOf(dim.defaultValue)
      : -1;
    const idx = defaultIdx >= 0 ? defaultIdx : 0;
    params[wmsParamKey(dim.canonicalName)] = formatWmsParamValue(
      dim.canonicalName,
      dim.values[idx],
    );
  }
  return params;
}
