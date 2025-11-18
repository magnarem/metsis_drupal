# Preact Openlayers Map Application for METSIS Drupal

## Architecture Overview

#### Key Components

- Drupal Search API View:
  - Handles the Solr query and displays search results.
  - Triggers AJAX-based updates when filters (e.g., bounding box) change.

- Preact Map Application:
  - Renders the map and provides interactivity (bounding box drawing, geocoder, WMS rendering, etc.).
  - Communicates with the Search API View via AJAX or event listeners.

- OpenLayers:
  - Manages the map rendering and interaction (bounding box drawing, WMS layers, GeoJSON features).

#### Interaction Between Components

- Search API View ↔ Map App:
  - The map app listens for Search API results (via AJAX or event listeners) and updates the map with GeoJSON features.
  - The map app triggers new Search API queries when the user draws a bounding box or uses the geocoder.

- Preact Components for Modularity:
  - Each UI feature (e.g., geocoder, layer switcher, bounding box drawer) is implemented as a reusable Preact component.
  - The map app dynamically loads only the necessary components for a given context (e.g., different maps with different controls).

### Component-Based Architecture

Each feature is implemented as a separate Preact component. The parent component (MapApp) dynamically includes the components based on the context.

#### MapApp: The Parent Component

The MapApp component is the parent component that dynamically includes child components based on configuration (via props). You pass a config object to MapApp to enable or disable specific features.

#### Reusable components

| Component Name     | Responsibility                                                                                        |
| ------------------ | ----------------------------------------------------------------------------------------------------- | --- |
| MapContainer       | Wraps the OpenLayers map and handles map state.                                                       |     |
| BoundingBoxDrawer  | Allows users to draw a bounding box and triggers Search API queries.                                  |
| GeoJSONLayer       | Displays GeoJSON features on the map.                                                                 |
| WMSLayerManager    | Handles rendering of WMS layers for features with wms url.                                            |
| GeocoderControl    | Provides a search bar for location-based filtering.                                                   |
| LayerSwitcher      | Manages layer visibility, order, legends, and layer metadata.                                         |
| ProjectionSwitcher | The ProjectionSwitcher component allows users to dynamically change the projection of the map's view. |

### Examples

#### Example config

```javascript
const searchContextConfig = {
  mapOptions: {
    center: [0, 0],
    zoom: 2,
  },
  features: {
    geojson: true,
    geojsonData: {}, // GeoJSON data from Search API response.
    boundingBox: true,
    wms: true,
    wmsUrl: "https://example.com/wms",
    defaultWmsLayers: ["layer1", "layer2"],
    geocoder: true,
    layerSwitcher: true,
  },
  events: {
    onBboxDrawn: (bbox) => {
      console.log("Bounding box drawn:", bbox);
      // Trigger Search API query with bbox.
    },
    onGeocode: (location) => {
      console.log("Geocoder result:", location);
      // Trigger Search API query with location proximity.
    },
  },
};
```

#### Use in Application:

```javascript
import { render } from "preact";
import MapApp from "./MapApp";

render(<MapApp config={searchContextConfig} />, document.getElementById("app"));
```

#### Example: Switching Contexts Dynamically

If your application supports both Search Context and WMS Visualization Context, you can dynamically switch configurations based on user input or page context.

##### Example:

```javascript
const context = getCurrentContext(); // Determine the context dynamically.
const config = context === "search" ? searchContextConfig : wmsContextConfig;

render(<MapApp config={config} />, document.getElementById("app"));
```
