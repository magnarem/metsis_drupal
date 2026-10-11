import test from "node:test";
import assert from "node:assert/strict";
import {
  getSearchGeojson,
  mergeSearchSettings,
} from "../src/utils/searchSettings.js";

test("View DOM IDs isolate result collections and preserve unscoped legacy maps", () => {
  const first = { type: "FeatureCollection", features: [{ id: "first" }] };
  const second = { type: "FeatureCollection", features: [{ id: "second" }] };
  const legacy = { type: "FeatureCollection", features: [{ id: "legacy" }] };
  const settings = {
    metsis_drupal: {
      search: {
        instances: {
          first: { geojson_feature_collection: first },
          second: { geojson_feature_collection: second },
        },
        results: { geojson_feature_collection: legacy },
      },
    },
  };
  assert.equal(getSearchGeojson(settings, "first"), first);
  assert.equal(getSearchGeojson(settings, "second"), second);
  assert.equal(getSearchGeojson(settings), legacy);
  assert.equal(getSearchGeojson(settings, "missing"), null);
});

test("empty AJAX result collections replace prior geometry without global fallback", () => {
  const empty = { type: "FeatureCollection", features: [] };
  const settings = {
    metsis_drupal: {
      search: {
        instances: { first: { geojson_feature_collection: empty } },
        results: {
          geojson_feature_collection: { features: [{ id: "stale" }] },
        },
      },
    },
  };
  assert.equal(getSearchGeojson(settings, "first"), empty);
  assert.equal(getSearchGeojson({}), null);
});

test("partial AJAX settings replace one View and preserve the other", () => {
  const first = { type: "FeatureCollection", features: [{ id: "old" }] };
  const second = { type: "FeatureCollection", features: [{ id: "other" }] };
  const empty = { type: "FeatureCollection", features: [] };
  const previous = {
    metsis_drupal: {
      search: {
        instances: {
          first: { geojson_feature_collection: first },
          second: { geojson_feature_collection: second },
        },
      },
    },
  };
  const merged = mergeSearchSettings(previous, {
    metsis_drupal: {
      search: {
        instances: {
          first: { geojson_feature_collection: empty },
        },
      },
    },
  });
  assert.equal(getSearchGeojson(merged, "first"), empty);
  assert.equal(getSearchGeojson(merged, "second"), second);
  assert.equal(getSearchGeojson(previous, "first"), first);
  assert.equal(
    getSearchGeojson(mergeSearchSettings(merged, {}), "first"),
    empty,
  );
});
