import { useSyncExternalStore } from "preact/compat";
import {
  getSearchGeojson,
  mergeSearchSettings,
} from "../utils/searchSettings.js";

let currentSettings = window.drupalSettings ?? {};
const listeners = new Set();

export function publishDrupalSettings(settings) {
  currentSettings = mergeSearchSettings(currentSettings, settings);
  listeners.forEach((listener) => listener());
}

export function useReactiveDrupalSettings(searchViewKey) {
  const subscribe = (callback) => {
    listeners.add(callback);
    return () => listeners.delete(callback);
  };
  const getSnapshot = () => getSearchGeojson(currentSettings, searchViewKey);
  return useSyncExternalStore(subscribe, getSnapshot);
}
