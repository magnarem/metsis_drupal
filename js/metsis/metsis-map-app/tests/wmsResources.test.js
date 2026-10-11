import test from "node:test";
import assert from "node:assert/strict";
import {
  getSharedLayerNames,
  normalizeWmsResources,
  resolveWmsResource,
  updateOverlayLayerNames,
} from "../src/utils/wmsResources.js";
import { buildBlacklistedLayerSet } from "../src/utils/wmsLayerUtils.js";

const resource = { url: "https://example.org/wms", layers: [] };
const capabilities = {
  version: "1.1.1",
  Capability: {
    Layer: {
      Layer: [
        { Name: "Temperature" },
        { Name: "Pressure" },
        { Name: "hidden" },
      ],
    },
  },
};
const options = {
  blacklistedLayers: buildBlacklistedLayerSet(["HIDDEN"]),
  preferredLayers: ["Pressure"],
  fetchCapabilities: async () => capabilities,
};

test("equal reordered effective sets share names, duplicates ignored", () => {
  assert.deepEqual(
    getSharedLayerNames([
      { layerNames: ["Temperature", "Pressure", "Temperature"] },
      { layerNames: ["Pressure", "Temperature"] },
    ]),
    ["Temperature", "Pressure"],
  );
});

test("unequal, partial, empty, failed and differently cased sets never share names", () => {
  for (const results of [
    [],
    [{ layerNames: [] }],
    [{ layerNames: ["a", "b"] }, { layerNames: ["a"] }],
    [{ layerNames: ["Temperature"] }, { layerNames: ["temperature"] }],
    [{ layerNames: ["a"] }, { error: "HTTP 503", layerNames: ["a"] }],
  ])
    assert.deepEqual(getSharedLayerNames(results), []);
});

test("capabilities resolve nested names, blacklist, preference and advertised version", async () => {
  const result = await resolveWmsResource(resource, options);
  assert.deepEqual(result.layerNames, ["Temperature", "Pressure"]);
  assert.equal(result.layerName, "Pressure");
  assert.equal(result.version, "1.1.1");
});

test("explicit resource restrictions stay authoritative and sets compare after blacklist", async () => {
  const explicit = await resolveWmsResource(
    {
      ...resource,
      layers: ["Pressure", "Temperature", "hidden"],
    },
    options,
  );
  const unrestricted = await resolveWmsResource(resource, options);
  assert.deepEqual(getSharedLayerNames([explicit, unrestricted]), [
    "Pressure",
    "Temperature",
  ]);
  const subset = await resolveWmsResource(
    { ...resource, layers: ["Pressure"] },
    options,
  );
  assert.deepEqual(subset.layerNames, ["Pressure"]);
  assert.deepEqual(getSharedLayerNames([subset, unrestricted]), []);
});

test("all overlay sources receive the shared LAYERS parameter", () => {
  const received = [];
  const layers = Array.from({ length: 3 }, () => ({
    getSource: () => ({ updateParams: (params) => received.push(params) }),
  }));
  updateOverlayLayerNames(layers, "Pressure");
  assert.deepEqual(received, Array(3).fill({ LAYERS: "Pressure" }));
});

test("capabilities failures and empty effective sets are explicit", async () => {
  const failure = await resolveWmsResource(resource, {
    ...options,
    fetchCapabilities: async () => {
      throw new Error("HTTP 503");
    },
  });
  assert.equal(failure.error, "HTTP 503");
  const empty = await resolveWmsResource(
    { ...resource, layers: ["hidden"] },
    options,
  );
  assert.equal(empty.error, "No selectable WMS layers");
  const invalid = await resolveWmsResource(resource, {
    ...options,
    fetchCapabilities: async () => ({}),
  });
  assert.equal(invalid.error, "Invalid WMS capabilities");
});

test("cancellation propagates rather than presenting a resource failure", async () => {
  const controller = new AbortController();
  controller.abort();
  await assert.rejects(
    resolveWmsResource(resource, {
      ...options,
      signal: controller.signal,
      fetchCapabilities: async () => {
        throw new DOMException("Aborted", "AbortError");
      },
    }),
    { name: "AbortError" },
  );
});

test("a late capabilities response cannot revive an aborted request", async () => {
  const controller = new AbortController();
  let resolve;
  const pending = resolveWmsResource(resource, {
    ...options,
    signal: controller.signal,
    fetchCapabilities: () =>
      new Promise((done) => {
        resolve = done;
      }),
  });
  controller.abort();
  resolve(capabilities);
  await assert.rejects(pending, { name: "AbortError" });
});

test("resource normalization retains query tokens, deduplicates reordered names and parses version", () => {
  const result = normalizeWmsResources({
    features: [
      {
        properties: {
          wms_resources: [
            {
              url: "https://example.org/wms?dataset=product&layers=a,b&version=1.1.1&request=GetMap",
            },
            {
              url: "https://example.org/wms?dataset=product&VERSION=1.1.1",
              layers: ["b", "a"],
            },
            { url: "javascript:alert(1)" },
          ],
        },
      },
    ],
  });
  assert.deepEqual(result, [
    {
      url: "https://example.org/wms?dataset=product",
      layers: ["a", "b"],
      version: "1.1.1",
    },
  ]);
  assert.deepEqual(normalizeWmsResources({ features: {} }), []);
  assert.deepEqual(
    normalizeWmsResources({
      features: [{ properties: { wms_resources: {} } }],
    }),
    [],
  );
});
