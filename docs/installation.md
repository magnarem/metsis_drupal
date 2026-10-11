# Installation and configuration

[Back to README](../README.md#3-installation)

## 1. Requirements

Use Drupal `^11.4 || ^12` with a compatible PHP runtime (PHP 8.4 in development
and CI). The authoritative dependency lists are
[`metsis_drupal.info.yml`](../metsis_drupal.info.yml) and
[`composer.json`](../composer.json).

The search UI requires:

- Views and Editor from Drupal core.
- Search API, Search API Solr, Search API Autocomplete, and Solr Autocomplete.
- Facets, Facets Exposed Filters, and Facets Searchbox Widget.
- Better Exposed Filters, Tagify, and Tagify Facets.
- Views Filters Summary with BEF, Search API, and accessibility integrations.
- Views AJAX History, Leaflet, and Vite.

Solr Admin, Solr Devel, and Search API DB are optional tools/backends, not
required frontend dependencies. Metadata export and vocabulary features require
the MMD resources under `vendor/metno/mmd`, including their XML/XSLT resources.

## 2. Enable the module

In an existing Drupal project, place the module in
`web/modules/custom/metsis_drupal` (adjust `web/` to your document root) and
install its Composer dependencies through the site's Composer setup.
The repository itself is module-first, not a complete recommended-project site.
For the disposable local test site, follow [DDEV setup](development/README.md#1-ddev-setup).

From the Drupal project root:

```bash
vendor/bin/drush en metsis_drupal -y
vendor/bin/drush cr
```

Drupal enables required modules and imports default configuration from
[`config/install`](../config/install). The module grants anonymous users
`access metsis metadata export` on installation; review this permission for
your deployment.

## 3. Configure search

1. Configure the `metsis_solr` server under Search API and connect it to the
   intended external Solr index. Keep credentials and deployment-specific
   connection settings in environment-managed configuration.
2. Verify that the Solr fields match the shipped `metsis` index.
   The server configuration references English Solr field-type configuration;
   ensure the matching Search API Solr resources are available before installing.
3. Configure METSIS at `/admin/config/metno/metsis-drupal` and inspect the
   status report at `/admin/reports/metsis-status-report`.
4. Open `/metsis/search`. See [Search and Views](search-and-views.md) for
   custom displays, exposed blocks, and layout settings.

## 4. Installed autocomplete

[`search_api_autocomplete.search.metsis_search.yml`](../config/install/search_api_autocomplete.search.metsis_search.yml)
enables autocomplete for the `metsis_search` View on the `metsis` index.
Defaults include a two-character minimum, up to 15 suggestions, and autosubmit.
Configured suggestion fields exist in the shipped index.

Manage autocomplete through the Search API index's autocomplete settings.
Existing installations keep their saved settings: changing `config/install`
does not overwrite active configuration.

## 5. Optional extensions and upgrades

- [NBS Extensions](../modules/nbs_extensions/README.md): NetCDF requests,
  supported product settings, backend configuration, and modal login.
- [Dynamic landing pages](metadata.md#3-dynamic-landing-pages): standalone
  dataset detail pages with metadata and visualisation.

After deploying updates:

```bash
vendor/bin/drush updb -y
vendor/bin/drush cr
vendor/bin/drush config:status
```

Update `11001` migrates legacy block exclusions; `11002` adds the original
search View's managed map without replacing custom map areas; `11003` enables
the sidebar display extender while preserving other extenders.
Review configuration differences and export only intentional changes.
