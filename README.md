# METSIS Drupal Module

![Drupal](https://img.shields.io/badge/Drupal-11-0678BE?style=for-the-badge&logo=drupal&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=for-the-badge&logo=php&logoColor=white)

[![ESLint](https://img.shields.io/github/actions/workflow/status/magnarem/metsis_drupal/quality.yml?job=ESLint&label=ESLint&style=flat-square)](https://github.com/magnarem/metsis_drupal/actions/workflows/quality.yml)
[![Prettier](https://img.shields.io/github/actions/workflow/status/magnarem/metsis_drupal/quality.yml?job=Prettier%20Check&label=Prettier&style=flat-square)](https://github.com/magnarem/metsis_drupal/actions/workflows/quality.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/magnarem/metsis_drupal/quality.yml?job=PHPStan&label=PHPStan&style=flat-square)](https://github.com/magnarem/metsis_drupal/actions/workflows/quality.yml)
[![PHPCS](https://img.shields.io/github/actions/workflow/status/magnarem/metsis_drupal/quality.yml?job=PHPCS&label=PHPCS&style=flat-square)](https://github.com/magnarem/metsis_drupal/actions/workflows/quality.yml)
[![PHPUnit](https://img.shields.io/github/actions/workflow/status/magnarem/metsis_drupal/quality.yml?job=PHPUnit&label=PHPUnit&style=flat-square)](https://github.com/magnarem/metsis_drupal/actions/workflows/quality.yml)

## Overview

`metsis_drupal` is a custom Drupal module that provides the interactive search frontend for the MET Norway Scientific Information System (METSIS).

The module is focused on:

- dataset and metadata discovery through Search API + Solr
- rich result row rendering and metadata detail dialogs
- metadata export (MMD and transformed formats via XSLT)
- vocabulary-aware metadata enrichment (MMD SKOS vocabularies)
- map and bounding-box filtering with Preact + OpenLayers apps

### NBS extensions

The repository also contains the `nbs_extensions` submodule in
[`modules/nbs_extensions`](modules/nbs_extensions), which provides the NetCDF on-demand request flow. Configure its backend endpoint at `/admin/config/metno/nbs-extensions`; authenticated users can submit requests at `/metsis/netcdf-on-demand/{datasetId}`. The form uses Drupal core HTMX with normal Form API submission as its no-JavaScript fallback.
Its installed configuration lists supported product types in `netcdf_ondemand_products`. Search result rows offer **Request "CF-NetCDF file"** only for those product types when the dataset does not already have an HTTP `.nc` data-access resource.
Data-access popover links use the theme's button styling and include file-type
or new-tab information in their link text for accessibility.
Anonymous visitors can open the request dialog and log in using Drupal's
username/password form. Successful login replaces the dialog body with the
protected request form without reloading search. Authentication, flood checks,
and session finalization remain core Drupal behavior. See the
[NBS extension guide](modules/nbs_extensions/README.md).

### Custom METSIS Views

The required search UI stack includes Search API, Solr and Solr Autocomplete,
Facets (including exposed filters and the searchbox widget), Better Exposed
Filters, Tagify/Tagify Facets, Views AJAX History, and Views Exposed Filters
Summary with its BEF, Search API, and accessibility integrations. These modules
are declared in [`metsis_drupal.info.yml`](metsis_drupal.info.yml); their packages
are already required by Composer. Solr administration/debug modules and the
Search API database backend are not mandatory runtime dependencies.

Autocomplete is installed from
[`search_api_autocomplete.search.metsis_search.yml`](config/install/search_api_autocomplete.search.metsis_search.yml).
It enables the `metsis_search` View on the `metsis` index, with a two-character
minimum, up to 15 suggestions, and autosubmit. All configured suggestion fields
exist in the shipped index. Existing sites retain their active autocomplete
settings; changing install defaults does not overwrite saved configuration.

Views using `search_api_index_metsis` receive METSIS query preparation without
requiring a query tag. `inactive` selects only Inactive metadata; `flat_search`
matches datasets directly, omitting child joins/counts while retaining parent
metadata. Other indexes remain unchanged.

New Views retain the normal Views layout. Add the `metsis` query tag to opt
into the shared results layout. Themes can override
`views-view--metsis-VIEW-ID--DISPLAY-ID.html.twig` and
`views-exposed-form--metsis-VIEW-ID--DISPLAY-ID.html.twig`.
The page-level messages and metadata dialog targets are independent of these
templates.

Under **Advanced → Other → METSIS filter sidebar**, set a positive width in
`rem`, `em`, or `px` (default `24rem`). The setting supports normal Views
default-display inheritance and per-display overrides on METSIS-index Views.
The shared METSIS results layout consumes this width; standard Views layouts
are unchanged. Narrow screens still stack the filters above the results.
Theme overrides can use the `--metsis-sidebar-width` CSS custom property.
Update `11003` enables the display extender while preserving other extenders.

Add **METSIS Map** as a Views header/footer area. Each map has isolated settings
and result geometry, full available width, a 150–1000px height (400px default),
and an optional all-WMS control. The JSON settings must be an object, with
object-valued `features` and `mapOptions` when supplied. Empty results clear
geometry; AJAX/HTMX replacement releases old map instances.
Height validation reads the nested Views options and stores accepted numeric
input as an integer; fractional heights are rejected rather than truncated.
Update `11002` adds the managed map to the original search View without
overwriting existing map areas. Standalone WMS maps retain their 600px default.

The bbox filter's sizing stylesheet is attached through a non-Vite Drupal
library, so development asset rewriting cannot send it to an invalid URL.
Each bbox map owns its map, drawing source, coordinate inputs, resize observer,
and teardown; a View and exposed-form block can render maps on the same page.

Browser regression coverage is in
[`MetsisMapsTest.php`](tests/src/FunctionalJavascript/MetsisMapsTest.php). It
checks rendered result/bbox canvases and heights, tab switching, AJAX
replacement, and simultaneous View/block bbox maps against an isolated test
backend. After configuring a compatible ChromeDriver/WebDriver endpoint via
`MINK_DRIVER_ARGS_WEBDRIVER`, run:

```bash
vendor/bin/phpunit --configuration=phpunit.xml tests/src/FunctionalJavascript/MetsisMapsTest.php
```

This browser suite is currently deferred until the local driver is configured.

The all-products WMS selector appears only when every resource has the same
selectable layer-name set, independent of ordering, and updates the named layer
across all products. Failed capability requests disable the shared selector and
display an error.

Choose **Custom** row style and provide a machine name to use
`metsis-search-row-custom--MACHINE-NAME.html.twig` (replace underscores with
hyphens in Twig filenames). Missing overrides and legacy Detailed/Custom styles
fall back to Default. Module templates are organized under
[`templates/views`](templates/views), [`templates/rows`](templates/rows),
[`templates/metadata`](templates/metadata), and
[`templates/forms`](templates/forms); theme override filenames stay unchanged.

### Dynamic landing pages

The repository also includes the standalone [`modules/dynamic_landing_pages`](modules/dynamic_landing_pages) submodule. It renders dataset landing pages directly from Solr data, with rich metadata summaries, map previews, parent/child dataset context, and export/visualisation controls. The module is intended for catalogue-style detail pages that keep the metadata presentation consistent with the main METSIS search experience while allowing a dedicated, more document-like layout.

## Runtime and Compatibility

- Drupal core: `^10 || ^11 || ^12`
- Primary CI runtime: PHP `8.4`
- Module package: `METNO`

See [metsis_drupal.info.yml](metsis_drupal.info.yml) and [.github/workflows/quality.yml](.github/workflows/quality.yml).

### Local DDEV VS Code and Copilot history

The local [VS Code launcher](.ddev/commands/host/code) lets Dev Containers copy
the host Git configuration, restores `core.autocrlf=input`, and adds the attached
project path to `safe.directory` only when missing. Existing trust entries are
preserved; the launcher no longer introduces trust for every repository.

The assistant-copilot add-on's read-only host mounts are **configuration seeds**,
not live history storage. Its customized
[initialization](.ddev/config.assistant-copilot.yaml) synchronizes configuration
without replacing `session-state/` or the `session-store.db` index and its
SQLite companion files.

[History mounts](.ddev/docker-compose.vscode-history.yaml) persist both stores
under host `~/.ddev-vscode-history/<DDEV project name>/`:

- `vscode-data/` mounts at container `~/.vscode-server/data`, including the
  agent-host catalog and session databases. Server binaries remain disposable.
- `copilot/` mounts at container `~/.copilot`, including transcripts and the
  history index. The host's ordinary `~/.copilot` remains a read-only seed.

**Before any restart or container recreation**, close the remote VS Code window
and run these commands from a host terminal in the project directory:

```bash
ddev vscode-history
ddev debug compose-config
ddev restart
ddev code
```

The [migration helper](.ddev/vscode-history/storage.sh) briefly pauses the running
web container, copies both stores with their SQLite WAL/SHM files, and resumes
it. This briefly interrupts the development site. It refuses nonempty
destinations and retains partial copies on failure. Do not restart after a failed
migration; inspect the reported staging directory first. Do not run this migration
from an integrated terminal inside the container.

For a new project with **no existing web container**, use
`ddev vscode-history --initialize-empty` before the first `ddev start` instead.
The [startup hook](.ddev/config.vscode-history.yaml) requires initialized storage;
it is not a substitute for migrating before invoking a command that might remove
the old container.

After restarting, verify both container paths are bind mounts with `findmnt`,
confirm old sessions appear in VS Code, and repeat a restart to verify newly
created sessions survive. These host/container-recreation checks cannot be run
from the web container's limited `ddev` shim.

Storage is private, project-specific, and outside Git. It can contain credentials
and conversation content; back it up securely. The local launcher and modified
assistant-copilot configuration are custom-owned (their generated markers were
removed), so reconcile future add-on updates manually. These local DDEV files are
ignored/untracked; reinstalling an add-on alone will not reproduce this setup.

## Key Routes and Features

- Admin settings form: `/admin/config/metno/metsis-drupal`
- Status report: `/admin/reports/metsis-status-report`
- Metadata document page: `/metsis/metadata/{id}`
- HTMX metadata modal endpoint: `/metsis/metadata/htmx/{id}`
- Metadata export form/download endpoints under `/metsis/metadata/export/*`
- Bokeh service/form endpoints under `/services/bokeh-plot/*`

Route definitions are in [metsis_drupal.routing.yml](metsis_drupal.routing.yml).

The **METSIS search exposed form** block displays filters from the
`metsis_search` View's `results` display and submits searches to that View,
wherever the block is placed. Configure which filters and the optional
secondary Search button to show, their order, and their column placement in
the block settings. Choose one to three columns; each filter can use automatic
placement or a specific column. The layout stacks on narrow screens, and
filters in the block submit when a Search button is used. Sort and pager
controls are not included. New blocks initially show the search box, temporal
filter, geographic filter, and secondary Search button. Existing blocks retain
their selections; `vendor/bin/drush updb` migrates legacy exclusion settings.
Weight and column controls appear only for selected elements and retain their
preferences when an element is temporarily hidden.

Block settings also offer **Compact presentation**, independent predicate
visibility for the temporal and geographic filters, and a bbox map-height
override. Hidden exposed predicates submit **Intersects**. If the View does not
expose a predicate, its fixed operator remains in effect; hiding is unavailable
if the View's allowed operators exclude Intersects. Configure the map height in
the bbox Views filter (150–1000 pixels, default 250); blocks inherit that height
unless overridden. Map width adapts to the available container, including
narrow columns and non-tabbed maps.

For a left-sidebar search block in Solo, use the theme's `solo-col-1-2`
two-column main layout (sidebar:content = 1:2). The block's column count
controls only its internal filter layout, not the theme's region widths.

Additional usage guides are available in [`docs/`](docs/); more detailed
feature documentation can be added there as the project manual grows.

## Important Services and Classes

Core infrastructure:

- Solr connector provider: [src/Service/SolrConnectorProvider.php](src/Service/SolrConnectorProvider.php)
- Solr query factory: [src/Service/SolrQueryFactory.php](src/Service/SolrQueryFactory.php)
- Solr document loader: [src/Service/SolrDocumentLoader.php](src/Service/SolrDocumentLoader.php)
- Config provider: [src/Service/ConfigProvider.php](src/Service/ConfigProvider.php)

Metadata and rendering:

- Metadata document normalizer: [src/Service/MetadataDocumentNormalizer.php](src/Service/MetadataDocumentNormalizer.php)
- Metadata export service: [src/Service/MetadataExportService.php](src/Service/MetadataExportService.php)
- Result row renderer: [src/Service/ResultRowRenderer.php](src/Service/ResultRowRenderer.php)
- Leaflet map renderer: [src/Service/LeafletMapRenderer.php](src/Service/LeafletMapRenderer.php)

Vocabulary and helper domain logic:

- MMD vocabulary service: [src/Service/MetVocabService.php](src/Service/MetVocabService.php)
- METSIS helper utility: [src/Utility/MetsisHelper.php](src/Utility/MetsisHelper.php)
- Feature type lookup service: [src/Service/FeatureTypeLookupService.php](src/Service/FeatureTypeLookupService.php)

UI/controllers/forms:

- Settings form: [src/Form/MetsisSettingsForm.php](src/Form/MetsisSettingsForm.php)
- Metadata document controller: [src/Controller/MetadataDocumentController.php](src/Controller/MetadataDocumentController.php)
- Metadata export controller/form: [src/Controller/MetadataExportController.php](src/Controller/MetadataExportController.php), [src/Form/MetadataExportForm.php](src/Form/MetadataExportForm.php)
- Bokeh integration: [src/Service/BokehPlotService.php](src/Service/BokehPlotService.php), [src/Controller/BokehPlotController.php](src/Controller/BokehPlotController.php)

Event subscribers:

- Search API subscriber: [src/EventSubscriber/SearchApiSubscriber.php](src/EventSubscriber/SearchApiSubscriber.php)
- Search API Solr subscriber: [src/EventSubscriber/SearchApiSolrSubscriber.php](src/EventSubscriber/SearchApiSolrSubscriber.php)
- Solarium request timing subscriber: [src/EventSubscriber/SolariumRequestTimingSubscriber.php](src/EventSubscriber/SolariumRequestTimingSubscriber.php)
- Views AJAX response subscriber: [src/EventSubscriber/ViewsAjaxResponseSubscriber.php](src/EventSubscriber/ViewsAjaxResponseSubscriber.php)

Service registration and wiring are defined in [metsis_drupal.services.yml](metsis_drupal.services.yml).

## Views Plugins and Frontend Components

Custom Views plugins are under [src/Plugin/views](src/Plugin/views), including row, filter, field, and area plugins.

Frontend assets are provided through [metsis_drupal.libraries.yml](metsis_drupal.libraries.yml), including:

- `metsis_map` (Vite/Preact map app)
- `bbox_map_filter` (bounding-box filter app)
- metadata dialog and vocabulary popover behavior libraries

Single Directory Components are in [components](components), for example DOI, collection, dataset citation, search, and temporal extent components.

### Component schemas and examples

Every component YAML includes schema-valid prop examples and, where applicable,
slot examples. Citation entries declare their full nested structure, matching
the output of `MetadataDocumentNormalizer`. Boolean props preserve explicit
`false` values; omitted values use the documented defaults.

The dataset citation component's `resource_icon` slot is rendered for each DOI
entry. When embedding it in Twig, the slot can use `citation.resource_url` from
the current loop entry. Both metadata document and landing page templates use
this slot to supply the DOI component.

Validate schemas, examples, template behavior, and the installed SDC Devel rules:

```bash
ddev exec vendor/bin/phpunit tests/src/Unit/SdcComponentsTest.php
ddev drush sdc-devel:validate metsis_drupal
```

The SDC Devel check in PHPUnit is skipped if that optional development module
is not installed; core schema and rendering checks still run.

## Dependencies

Drupal module dependencies (from [metsis_drupal.info.yml](metsis_drupal.info.yml)):

- `views`
- `search_api`
- `search_api_solr`
- `search_api_solr_autocomplete`
- `facets`
- `facets_exposed_filters`
- `better_exposed_filters`
- `leaflet`
- `views_filters_summary`
- `views_ajax_history`

Composer/runtime dependencies (from [composer.json](composer.json)) include:

- `drupal/search_api`
- `drupal/search_api_solr`
- `drupal/facets`
- `drupal/leaflet`
- `sweetrdf/easyrdf`

The metadata export/vocabulary features expect MMD resources under `vendor/metno/mmd` in test and runtime contexts where these features are exercised.

## Development and CI

- Quality workflow: [.github/workflows/quality.yml](.github/workflows/quality.yml)
- Reusable Drupal setup action: [.github/actions/drupal-prepare/action.yml](.github/actions/drupal-prepare/action.yml)

The workflow runs ESLint, Prettier, PHPStan, PHPCS, and PHPUnit.

## Context

Project context: <https://adc.met.no/about>
