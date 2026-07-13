/**
 * Small SVG spinner shown while WMS capabilities are being fetched.
 * Pure CSS animation — no image assets or external dependencies.
 */
const WmsSpinner = () => (
  <span className="wms-spinner" role="status" aria-label="Loading WMS capabilities">
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
    >
      <circle
        cx="12"
        cy="12"
        r="10"
        stroke="currentColor"
        stroke-width="2.5"
        stroke-dasharray="31.4 31.4"
        stroke-linecap="round"
      />
    </svg>
    <span className="wms-spinner-text">Loading…</span>
  </span>
);

export default WmsSpinner;
