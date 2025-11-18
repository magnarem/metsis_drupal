import { useSyncExternalStore } from "preact/compat";

export function useReactiveDrupalSettings() {
  // Subscribe to AJAX complete events
  const subscribe = (callback) => {
    if (window.jQuery) {
      window.jQuery(document).on("ajaxComplete", callback);
      return () => window.jQuery(document).off("ajaxComplete", callback);
    }
    return () => {};
  };
  // Always return the latest value from window
  const getSnapshot = () =>
    window.drupalSettings?.metsis_drupal?.search?.results
      ?.geojson_feature_collection || null;
  return useSyncExternalStore(subscribe, getSnapshot);
}
