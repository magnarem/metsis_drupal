# Architecture and integration points

[Back to README](../README.md#2-runtime-and-architecture)

## 1. Source layout

The repository root is the Drupal module root. PHP lives in `src/`,
configuration in `config/`, templates in `templates/`, and SDCs in `components/`.
`web/` is a disposable Drupal test site, not the module source location.
The CI preparation action symlinks the module into `web/modules/custom/metsis_drupal`.

## 2. Services and query integration

| Responsibility         | Implementation                                                              |
| ---------------------- | --------------------------------------------------------------------------- |
| Solr connection        | [SolrConnectorProvider](../src/Service/SolrConnectorProvider.php)           |
| Solr queries           | [SolrQueryFactory](../src/Service/SolrQueryFactory.php)                     |
| Document loading       | [SolrDocumentLoader](../src/Service/SolrDocumentLoader.php)                 |
| Configuration access   | [ConfigProvider](../src/Service/ConfigProvider.php)                         |
| Metadata normalization | [MetadataDocumentNormalizer](../src/Service/MetadataDocumentNormalizer.php) |
| Metadata export        | [MetadataExportService](../src/Service/MetadataExportService.php)           |
| Result rendering       | [ResultRowRenderer](../src/Service/ResultRowRenderer.php)                   |
| Leaflet rendering      | [LeafletMapRenderer](../src/Service/LeafletMapRenderer.php)                 |
| Vocabulary             | [MetVocabService](../src/Service/MetVocabService.php)                       |
| Search helpers         | [MetsisHelper](../src/Utility/MetsisHelper.php)                             |
| Feature types          | [FeatureTypeLookupService](../src/Service/FeatureTypeLookupService.php)     |
| Bokeh                  | [BokehPlotService](../src/Service/BokehPlotService.php)                     |

Controllers/forms include [MetsisSettingsForm](../src/Form/MetsisSettingsForm.php),
[MetadataDocumentController](../src/Controller/MetadataDocumentController.php),
[MetadataExportController](../src/Controller/MetadataExportController.php),
[MetadataExportForm](../src/Form/MetadataExportForm.php), and
[BokehPlotController](../src/Controller/BokehPlotController.php).
Service wiring is in [`metsis_drupal.services.yml`](../metsis_drupal.services.yml).

Search customization uses
[SearchApiSubscriber](../src/EventSubscriber/SearchApiSubscriber.php) and
[SearchApiSolrSubscriber](../src/EventSubscriber/SearchApiSolrSubscriber.php).
[SolariumRequestTimingSubscriber](../src/EventSubscriber/SolariumRequestTimingSubscriber.php)
records timing; [ViewsAjaxResponseSubscriber](../src/EventSubscriber/ViewsAjaxResponseSubscriber.php)
integrates Views responses.
Custom row, filter, field, area, and display-extender plugins live under
[`src/Plugin/views`](../src/Plugin/views).

## 3. Templates and components

Registered templates are organized in
[`templates/views`](../templates/views), [`templates/rows`](../templates/rows),
[`templates/metadata`](../templates/metadata), and
[`templates/forms`](../templates/forms). Theme override filenames stay unchanged.

[Single Directory Components](../components) provide DOI, collection, citation,
search, temporal extent, and other reusable presentation. Each component YAML
contains schema-valid prop/slot examples. Citation entries match normalized
metadata; explicit boolean `false` is preserved and omitted props use defaults.

The citation component's `resource_icon` slot renders for each DOI entry and
can use `citation.resource_url` from the current loop entry. Metadata and
landing-page templates use the slot to supply the DOI component.
See [component validation](development/README.md#3-quality-checks).

## 4. Frontend assets

[`metsis_drupal.libraries.yml`](../metsis_drupal.libraries.yml) attaches:

- `metsis_map`: the Vite/Preact/OpenLayers result and WMS map application.
- `bbox_map_filter`: bounding-box drawing and input.
- Drupal behaviors for metadata dialogs, shared targets, and popovers.

Frontend application source lives under [`js/metsis`](../js/metsis); the built
manifest/assets live in `js/metsis/dist`. Core HTMX progressively enhances
server-rendered pages, with explicit initialization after fragment swaps.
