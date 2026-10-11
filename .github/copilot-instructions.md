# Copilot instructions for METSIS Drupal

## Repository and runtime

- This repository is the `metno/metsis_drupal` custom Drupal module. The repository root is the module root; the local Drupal document root is `web/`.
- The target runtime is Drupal 11 with PHP 8.4 (the module declares compatibility with Drupal 10, 11, and 12). The primary local workflow uses DDEV; commands run inside the container without a `ddev` prefix, while host commands use `ddev exec`, `ddev drush`, or `ddev composer`.
- Do not edit generated or ignored dependencies under `web/core`, `web/modules/contrib`, `vendor`, `node_modules`, or `web/sites/*/files`. The CI preparation action symlinks this module into `web/modules/custom/metsis_drupal`.
- Solr connection details and other environment-specific secrets belong in environment configuration such as `.ddev/.env`, not in tracked code or configuration.
- The repo is a module-first workspace: code lives at the repository root, while `web/` is a disposable Drupal site used for testing. `config/` holds default config; `config-sync/` is the dev site export area and should only contain intentional changes.
- The module includes a submodule at `modules/nbs_extensions` with its own settings and HTMX/Form API request flow. Treat it as a separate but dependent extension, not as a browser-only add-on.

## Commands

Install dependencies and prepare the local Drupal instance:

```bash
ddev start
ddev composer install
ddev drush cr
```

The repository's Composer scripts are also the canonical PHP tool entry points:

```bash
composer test                                      # PHPUnit using phpunit.xml
composer phpcs                                     # PHPCS using phpcs.xml
composer phpstan                                   # PHPStan using phpstan.neon
vendor/bin/phpunit tests/src/Unit/FooTest.php      # one PHPUnit file
vendor/bin/phpunit --filter testMethod tests/src/Unit/FooTest.php  # one test method
vendor/bin/phpcs -s src/Service/Foo.php            # one PHP file
vendor/bin/phpstan analyse --configuration=phpstan.neon src/Service/Foo.php
```

For the full project paths used by the quality workflow:

```bash
vendor/bin/phpcs -s --report-full --report-summary
vendor/bin/phpstan analyse --configuration=phpstan.neon
vendor/bin/phpunit --configuration=phpunit.xml tests/
```

Frontend checks use the root Node manifest:

```bash
npm install
npx eslint .
npx prettier --check .
npx eslint path/to/file.js
npx prettier --check path/to/file.js
```

Useful Drupal commands after PHP, YAML, route, service, or library changes:

```bash
ddev drush cr
ddev drush config:status
ddev drush cex -y
ddev drush cim -y
ddev drush ws --severity=error --count=20
```

The GitHub Actions workflow in `.github/workflows/quality.yml` runs ESLint, Prettier, PHPStan, PHPCS, and PHPUnit. PHPStan intentionally excludes `web/modules/custom` because this repository's module source is at the repository root; use the repository configuration and CI command rather than inventing another analysis path.

## Architecture

- Drupal routes, forms, controllers, services, plugins, hooks, templates, and configuration live at the repository root. `web/` is a disposable development site used to execute the module.
- Search is built on Views + Search API + Solr. `src/EventSubscriber/SearchApiSubscriber.php` and `src/EventSubscriber/SearchApiSolrSubscriber.php` alter query/request behavior; custom Views plugins under `src/Plugin/views/` provide result rows, Solr filters, fields, and map areas.
- Solr documents are the search result domain model rather than loaded node entities. `SolrConnectorProvider`, `SolrQueryFactory`, and `SolrDocumentLoader` form the low-level retrieval layer. `MetadataDocumentNormalizer` converts documents into stable presentation data, while `ResultRowRenderer` and `MetadataDocumentController` render result rows and metadata documents.
- Metadata export and enrichment are separate domain paths: `MetadataExportService` handles MMD/XSLT-oriented exports, and `MetVocabService` parses/caches MMD SKOS vocabulary data for labels and popovers.
- Search and metadata UI is progressively enhanced Drupal output. Twig templates and SDC components under `templates/` and `components/` provide the server-rendered structure; HTMX endpoints supply metadata/catalog fragments; Drupal libraries in `metsis_drupal.libraries.yml` attach behavior and styles.
- Interactive maps are separate Vite applications under `js/metsis/`: Preact/OpenLayers powers the main map and bounding-box filter. Their entrypoints are attached through the `metsis_map` and `bbox_map_filter` Drupal libraries. Keep map state and projection logic in the frontend app rather than duplicating it in PHP.
- Services are autowired/autoconfigured in `metsis_drupal.services.yml`, with explicit aliases where a type-hinted class or interface must resolve to a named service. Controllers and forms are intentionally thin and delegate domain work to services.

## Conventions specific to this codebase

- Use Drupal APIs and dependency injection. Add dependencies to constructors and `metsis_drupal.services.yml`; do not introduce new `\Drupal::service()` calls in classes.
- Follow the existing service layering in `metsis_drupal.services.yml`: infrastructure services first, configuration/domain services next, rendering/UI services above them, and search/helpers at the top. Reuse existing services before adding another Solr or metadata abstraction.
- Treat Solr field names and document shapes as external input. Normalize them in `MetadataDocumentNormalizer` or a focused service before Twig rendering; preserve existing fallback behavior and structured render-array conventions.
- Keep user-facing strings translatable (`$this->t()`/translation services, `TranslatableMarkup`, or Twig translation). Escape or use safe render arrays for Solr-derived values; never concatenate raw values into markup, SQL, CSS selectors, or route URLs.
- Add access requirements to routes and use parameterized Drupal database APIs. Preserve cache metadata on render arrays and invalidate the appropriate cache tags when changing cached vocabulary/config data.
- Use PHP attributes for new Drupal plugins and OOP hook classes where the surrounding code supports them. Keep procedural module files limited to compatibility or module-level hooks already established in the project.
- For UI behavior, attach JS/CSS through Drupal libraries rather than inline assets. HTMX-swapped fragments must explicitly reattach the relevant Drupal behavior/popover/dialog initializer, following the existing `afterSwap` patterns.
- SDC component contracts belong in each component's `.component.yml`; keep reusable presentation in components and pass normalized data from controllers/services. Do not duplicate metadata formatting logic across Twig templates.
- Frontend source is modern ESM/JSX and is linted/formatted from the repository root. Use the existing Vite app structure and shared projection utilities when changing OpenLayers behavior.
- Configuration belongs in the appropriate `config/install`, `config/optional`, `config/schema`, or `config/sync` location. After changing active Drupal configuration locally, inspect `drush config:status` and export only intentional changes.
- Tests are organized under `tests/src/Unit`, `Kernel`, `Functional`, and `FunctionalJavascript`. Prefer focused unit tests for normalizers and pure transformations; use kernel tests for service/cache integration and functional tests for routes or browser behavior. If MMD vocabulary behavior is exercised, ensure the `vendor/metno/mmd` resource is available as the CI preparation action does.
- Before a PR, run the same quality checks as CI and, when the Drupal site is available, run cache rebuild/config checks. Do not commit local environment files, generated assets, dependency trees, or secrets.

## DDEV workflow

- Use DDEV for local runtime and environment management. Inside the container, run `drush`, `composer`, `php`, and `npm` directly; from the host, use `ddev exec`, `ddev drush`, and `ddev composer`.
- Typical startup and verification flow:

```bash
ddev start
ddev composer install
ddev drush cr
ddev drush config:status
```

- For debugging, use `ddev logs`, `ddev ssh`, `ddev xdebug on|off`, and `ddev describe`; keep mail, db and Solr endpoints in DDEV-managed config rather than hardcoded in repo files.
- Do not rely on generated `web/*` or `vendor` artifacts for source edits; they are runtime output or dependencies.

## Drupal-specific workflow

- Before implementing custom functionality, check whether a Drupal contrib module, recipe, or existing pattern in this codebase already solves it.
- Prefer Drupal CLI generators for new modules, controllers, forms, and services when the change is routine; it produces consistent structure and standards.
- For any custom config object, include a matching schema in `config/schema/` and prefer `Drupal\Config\Entity` / config API patterns over ad hoc YAML assumptions.
- In forms/controllers/plugins, use constructor injection and container factories rather than static service access.
- Keep hooks thin and OOP-aware where the project already uses them (`src/Hook/` or attributes); do not scatter procedural code across the repo.
- Always add cache metadata to render arrays and use appropriate cache tags/contexts when returning content derived from Solr, vocabularies, or config.
- All user-visible strings should go through translation APIs; do not hardcode UI text in PHP or Twig.

## CLI-first and testing expectations

- When adding Drupal code that is boilerplate, prefer generator-based scaffolding and then customize the generated file instead of hand-writing a module skeleton.
- For a single test file or filtered test method, run the narrowest possible command rather than the whole suite:

```bash
vendor/bin/phpunit tests/src/Unit/FooTest.php
vendor/bin/phpunit --filter testMethod tests/src/Unit/FooTest.php
```

- Use `phpcs`, `phpstan`, and the relevant PHPUnit target before merge; check file-level or test-level failures first, then widen scope only if needed.
- Keep new functionality covered at the level that matches the work: pure transformation logic in unit tests, service/cache behavior in kernel tests, and route/UI flows in functional tests.
