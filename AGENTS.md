# AGENTS.md

AI coding guide for METSIS Search (metsis_drupal) Drupal module project.

## AI Response Requirements

**Communication style:**

- Code over explanations - provide implementations, not descriptions
- Be direct, skip preambles
- Assume Drupal expertise - no over-explaining basics
- Suggest better approaches with code
- Show only changed code sections with minimal context
- Complete answers in one response when possible
- Use Drupal APIs, not generic PHP
- Ask if requirements are ambiguous

**Response format:**

- Production-ready code with imports/dependencies
- Inline comments explain WHY, not WHAT
- Include full file paths
- Proper markdown code blocks

### 1. Before responding to the user

After completing any code changes, run through this checklist before returning to the user:

1. [ ] All tests pass (`phpunit`)
2. [ ] `DOCUMENTATION.md` and `README.md` are up to date
3. [ ] `DISCUSSIONS_LOG.md` is updated with a summary of this interaction
4. [ ] `AI_REASONING.md` updated if any new complex reasoning was produced
5. [ ] Code has been reviewed for refactoring opportunities; improvements implemented if clear
6. [ ] No secrets, credentials, or personal data are present in any file
7. [ ] No security risk, malicious behavior, secret data leak, vulnerability

### 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

### 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:

- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:

- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

### 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:

- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:

```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

## Project Overview

- **Platform**: Drupal 11.3.5 / single site (dev test instance in `web/`)
- **Context**: Custom Drupal module (`metno/metsis_drupal`, v4.x-dev) — interactive search frontend for MET Norway's Scientific Information System (METSIS). Integrates Search API + Solr for climate/weather dataset metadata discovery.
- **Architecture**: Standalone module with Views row/filter/field plugins, Solr integration, Preact + OpenLayers JS apps (built with Vite), SDC components, and a dev Drupal 11 instance in `web/` for testing.
- **Security**: Standard — no sensitive user data; public-facing read-only search. API keys for Solr in environment variables.
- **Languages**: Single (English)
- **Custom Entities**: None
- **Role System**: Standard Drupal roles (anonymous, authenticated, administrator) in dev instance
- **Use Cases**: Dataset/metadata search via Solr, bounding-box spatial filtering, temporal filtering, dataset detail views, metadata export, Bokeh data plots

## Date Verification Rule

**CRITICAL**: Before writing dates to `.md` files, run `date` first.

## Git Workflow

**Before PR**: Run `phpcs`, `phpstan`, tests, `drush cex` and `checkall` (inside container).

**.gitignore essentials**:

```gitignore
web/core/
web/modules/contrib/
web/themes/contrib/
vendor/
web/sites/*/files/
web/sites/*/settings.local.php
.ddev/
node_modules/
.env
```

## Development Environment dev

**Web root**: `web/`

**Command context rule**:

- Inside container: run tools directly (`drush`, `composer`, `php`, `npm`).
- From host shell: use `ddev` prefixes (`ddev drush`, `ddev composer`, etc.). refere to the [ddev-expert skill](.agents/skills/ddev-expert/SKILL.md)

### Setup

```bash
git clone git@github.com:magnarem/metsis_drupal.git && cd metsis_drupal
ddev start && ddev poser && ddev symlink-project && drush si -y && drush cr
```

**DDEV**: `ddev ssh` (open container shell), `ddev describe` (info)

### Custom DDEV Commands

Location: `.ddev/commands/host/<name>`
**WARNING**: Don't use `## #ddev-generated` comments - they break command recognition.

### Drush Commands

**Prefix with ddev when outside container**

```bash
# Core commands
drush status                    # Status check
drush cr                        # Cache rebuild
drush cex                       # Config export
drush cim                       # Config import
drush updb                      # Database updates

# Database & PHP eval
drush sql:query "SELECT * FROM node_field_data LIMIT 5;"
drush php:eval "echo 'Hello World';"

# Test services and entities
drush php:eval "var_dump(\Drupal::hasService('entity_type.manager'));"
drush php:eval "\$node = \Drupal::entityTypeManager()->getStorage('node')->load(1); var_dump(\$node->getTitle());"
drush php:eval "var_dump(\Drupal::config('system.site')->get('name'));"
drush php:eval "var_dump(\Drupal::service('custom_module.service_name'));"

# Quick setup (pull from platform)
ddev pull platform -y && drush cim -y && drush cr && drush uli
```

### Composer

```bash
composer outdated 'drupal/*'                    # Check updates
composer update drupal/<module> --with-deps     # Update module
composer require drupal/core:X.Y.Z drupal/core-recommended:X.Y.Z --update-with-all-dependencies  # Core update
```

**Scripts** in `composer.json`: `build`, `deploy`, `test`, `phpcs`, `phpstan`

### Environment Variables

Store in `.ddev/.env` (gitignored). Access: `$_ENV['VAR']`. Restart DDEV after changes.

### Patches

Structure: `./patches/{core,contrib/<module>,custom}/`

In `composer.json` → `extra.patches`:

```json
"drupal/module": {"#123 Fix": "patches/contrib/module/fix.patch"}
```

Sources: local files, Drupal.org issue queue, GitHub PRs
Always include issue numbers in descriptions. Monitor upstream for merged patches.

## Code Quality Tools

```bash
# PHPStan - static analysis
ddev exec vendor/bin/phpstan analyze web/modules/custom --level=1

# PHPCS - coding standards check
ddev exec vendor/bin/phpcs --standard=Drupal web/modules/custom/

# PHPCBF - auto-fix coding standards
ddev exec vendor/bin/phpcbf --standard=Drupal web/modules/custom/

# Rector - code modernization (run in container)
ddev ssh && vendor/bin/rector process web/modules/custom --dry-run

# Upgrade Status - Drupal compatibility check
ddev drush upgrade_status:analyze --all
```

**Config files**: `phpstan.neon`, `phpcs.xml`, `rector.php`
**Run before**: commits, PRs, Drupal upgrades

After PHP changes, run the checks in this order: PHPCBF on changed PHP files,
PHPCS on the same files, PHPStan using `phpstan.neon`, then PHPUnit using
`phpunit.xml`. Example from the repository root:

```bash
vendor/bin/phpcbf -s src/ChangedFile.php tests/src/Unit/ChangedFileTest.php
vendor/bin/phpcs -s src/ChangedFile.php tests/src/Unit/ChangedFileTest.php
vendor/bin/phpstan analyse --configuration=phpstan.neon
vendor/bin/phpunit --configuration=phpunit.xml tests/
```

## Testing

```bash
# PHPUnit
ddev exec vendor/bin/phpunit web/modules/custom
ddev exec vendor/bin/phpunit tests/src/Unit/MyTest.php
ddev exec vendor/bin/phpunit --coverage-html coverage web/modules/custom

# Codeception
ddev exec vendor/bin/codecept run <acceptance|functional|unit>
ddev exec vendor/bin/codecept run --steps --debug --html

# Debug failed tests
ddev exec vendor/bin/phpunit --testdox --verbose <test-file>
```

**Drupal test types** (in `tests/src/`): `Unit/` (isolated), `Kernel/` (minimal bootstrap), `Functional/` (full Drupal), `FunctionalJavascript/`

**Codeception structure**: `tests/{acceptance,functional,unit,_support,_data,_output}/`, config: `codeception.yml`

## Debugging

```bash
# Xdebug
ddev xdebug on|off                              # Toggle (disable when not debugging for perf)

# Container & DB access
ddev ssh                                         # Web container
ddev mysql                                       # MySQL CLI
ddev mysql -e "SELECT..."                        # Direct query
ddev export-db --file=backup.sql.gz             # Export
ddev import-db --file=backup.sql.gz             # Import

# Logs
ddev logs -f                                     # Container logs (follow)
ddev drush watchdog:show --count=50 --severity=Error

# State
ddev drush state:get|set|delete <key> <value>
```

**IDE**: PhpStorm (port 9003), VS Code (PHP Debug extension)
**Tips**: `ddev describe` (URLs/services), `ddev debug` (DDEV issues), Twig debug in `development.services.yml`

## Performance

```bash
# Cache
ddev drush cr                                    # Rebuild all
ddev drush cache:clear <render|dynamic_page_cache|config>

# Redis (if enabled)
ddev redis-cli INFO stats|memory
ddev redis-cli FLUSHALL                          # Clear Redis

# DB performance
ddev mysql -e "SELECT table_name, round(((data_length+index_length)/1024/1024),2) 'MB' FROM information_schema.TABLES WHERE table_schema=DATABASE() ORDER BY (data_length+index_length) DESC;"
ddev mysql -e "SHOW VARIABLES LIKE 'slow_query%';"
ddev drush sql:query "OPTIMIZE TABLE cache_bootstrap, cache_config, cache_data, cache_default, cache_discovery, cache_dynamic_page_cache, cache_entity, cache_menu, cache_render;"
```

**Optimization**: Enable page cache + dynamic page cache, CSS/JS aggregation, Redis/Memcache, CDN for assets, image styles with lazy loading

## Code Standards

Also refere to the [drupal-expert skill](.agents/skills/drupal-expert/SKILL.md)

### Core Principles

- **SOLID/DRY**: Follow SOLID principles, extract repeated logic
- **PHP 8.4**: Use strict typing: `declare(strict_types=1);`
- **Drupal Standards**: PSR-12 based, English comments only

### Module Structure

Location: `/web/modules/custom/metsis_[module_name]/`
Naming: `metsis_[descriptive_name]` — prevents conflicts with contrib

```
metsis_[module_name]/
├── metsis_[module_name].{info.yml,module,install,routing.yml,permissions.yml,services.yml,libraries.yml}
├── src/                          # PSR-4: \Drupal\[module_name]\[Subdir]\ClassName
│   ├── Entity/                   # Custom entities
│   ├── Form/                     # Forms (ConfigFormBase, FormBase)
│   ├── Controller/               # Route controllers
│   ├── Plugin/{Block,Field/FieldWidget,Field/FieldFormatter}/
│   ├── Service/                  # Custom services
│   └── EventSubscriber/          # Event subscribers
├── templates/                    # Twig templates
├── css/ & js/                    # Assets
```

**PSR-4**: `src/Form/MyForm.php` → `\Drupal\my_module\Form\MyForm`

### Entity Development Patterns

```php
// 1. Enums instead of magic numbers
enum EntityStatus: string {
  case Draft = 0;
  case Published = 1;
}

// 2. Getter methods instead of direct field access
public function getStatus(): int {
  return (int) $this->get('status')->value;
}

// 3. Safe migrations with backward compatibility
function metsis_drupal_update_XXXX() {
  $manager = \Drupal::entityDefinitionUpdateManager();
  $field = $manager->getFieldStorageDefinition('field_name', 'entity_type');
  if ($field) {
    $new_def = BaseFieldDefinition::create('field_type')->setSettings([...]);
    $manager->updateFieldStorageDefinition($new_def);
    drupal_flush_all_caches();
    \Drupal::logger('module')->info('Migration completed.');
  }
}
```

**Migration safety**: Backup DB, test on staging, ensure backward compatibility, log changes, have rollback plan.

### Drupal Best Practices

```php
// Database API - always use placeholders, never raw SQL
$query = \Drupal::database()->select('node_field_data', 'n')
  ->fields('n', ['nid', 'title'])->condition('status', 1)->range(0, 10);
$results = $query->execute()->fetchAll();

// Dependency Injection - avoid \Drupal:: static calls in classes
class MyService {
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}
}

// Caching - use tags and contexts
$build = [
  '#markup' => $content,
  '#cache' => ['tags' => ['node:' . $nid], 'contexts' => ['user'], 'max-age' => 3600],
];
\Drupal\Core\Cache\Cache::invalidateTags(['node:' . $nid]);
\Drupal::cache()->set($cid, $data, time() + 3600, ['my_module']);
```

```php
// Queue API - for heavy operations
$queue = \Drupal::queue('my_module_processor');
$queue->createItem(['data' => $data]);
// QueueWorker plugin: @QueueWorker(id="...", cron={"time"=60})

// Entity System - always use entity type manager
$storage = \Drupal::entityTypeManager()->getStorage('node');
$node = $storage->load($nid);
$query = $storage->getQuery()
  ->condition('type', 'article')->condition('status', 1)
  ->accessCheck(TRUE)->sort('created', 'DESC')->range(0, 10);
$nids = $query->execute();

// Form API - extend FormBase, implement getFormId(), buildForm(), validateForm(), submitForm()
$form['field'] = ['#type' => 'textfield', '#title' => $this->t('Name'), '#required' => TRUE];
$form_state->setErrorByName('field', $this->t('Error message.'));

// Translation - always use t() for user-facing strings
$this->t('Hello @name', ['@name' => $name]);

// Config API
$config = \Drupal::config('my_module.settings')->get('key');
\Drupal::configFactory()->getEditable('my_module.settings')->set('key', $value)->save();

// Permissions
user_role_grant_permissions($role_id, ['permission']);
user_role_revoke_permissions($role_id, ['permission']);
```

### Code Style

- Type declarations/hints required, PHPDoc for classes/methods
- Align `=>` in arrays, `=` in variable definitions
- Controllers: final classes, DI, keep thin
- Services: register in `services.yml`, single responsibility
- Logging: `\Drupal::logger('module')->notice('message')`
- Entity updates: always use update hooks in `.install`, maintain backward compatibility

## Directory Structure

**Module root**: `/` (module lives at repo root, not inside `web/`)
**Key paths**: `src/` (PHP), `templates/` (Twig), `css/` (styles), `js/` (behaviours + Vite apps), `components/` (SDC), `config/install/` (default config), `config/optional/` (optional config), `config-sync/` (dev-instance config), `tests/` (PHPUnit)
**Dev Drupal instance**: `web/` — used for local testing only, not deployed

**Development paths**: routes → `metsis_drupal.routing.yml`, forms → `src/Form/`, hooks → `src/Hook/`, services → `src/Service/`, views plugins → `src/Plugin/views/`, permissions → `metsis_drupal.permissions.yml`, updates → `metsis_drupal.install`

## Configuration Management

````bash
ddev drush cex                    # Export config
ddev drush cim                    # Import config
ddev drush config:status          # Show differences
## Security

**Principles**: HTTPS required, sanitize input, use DB abstraction (no raw SQL), env vars for secrets, proper access checks

```bash
# Security updates
ddev drush pm:security
ddev composer update drupal/core-recommended --with-dependencies
ddev composer update --security-only

# Audit
ddev drush role:perm:list
ddev drush watchdog:show --severity=Error --count=100
````

## Dev guides

Instructions for different drupal development tasks can be found here: [https://camoa.github.io/dev-guides/drupal/](https://camoa.github.io/dev-guides/drupal/)
**Hardening**: `chmod 444 settings.php`, `chmod 755 sites/default/files`, disable PHP in files dir

**Code**: Use placeholders in queries, `Html::escape()` for output, `$account->hasPermission()` for access, Form API for validation

## Headless/API-First Development

### JSON:API (Core)

```bash
ddev drush pm:enable jsonapi
# Optional: ddev composer require drupal/jsonapi_extras
```

**Endpoints**:

```
GET  /jsonapi/node/article                                    # List all
GET  /jsonapi/node/article/{uuid}?include=field_image,uid    # With relations
GET  /jsonapi/node/article?filter[status]=1&sort=-created&page[limit]=10
POST /jsonapi/node/article  (Content-Type: application/vnd.api+json, Authorization: Bearer {token})
```

### GraphQL

```bash
ddev composer require drupal/graphql drupal/graphql_compose
ddev drush pm:enable graphql graphql_compose
# Explorer at /admin/config/graphql
```

### Authentication (Simple OAuth)

```bash
ddev composer require drupal/simple_oauth && ddev drush pm:enable simple_oauth
openssl genrsa -out keys/private.key 2048 && openssl rsa -in keys/private.key -pubout -out keys/public.key
# POST /oauth/token with grant_type, client_id, client_secret, username, password
# Use: Authorization: Bearer {access_token}
```

### CORS (in services.yml)

```yaml
cors.config:
  enabled: true
  allowedOrigins: ["http://localhost:3000"]
  allowedMethods: ["GET", "POST", "PATCH", "DELETE", "OPTIONS"]
  allowedHeaders: ["*"]
  supportsCredentials: true
```

### Architecture Patterns

- **Fully Decoupled**: Drupal API + React/Vue/Next.js frontend
- **Progressively Decoupled**: Drupal pages + JS framework for interactive components
- **Hybrid**: Mix of Drupal templates and API-driven sections

### API Best Practices

OAuth tokens (not basic auth), rate limiting, HTTPS, validate input, API documentation (`drupal/openapi`)

## SEO & Structured Data

### Core Modules

**Naming**: `node--[type]--[view-mode].html.twig`, `paragraph--[type].html.twig`, `block--[type].html.twig`, `field--[name]--[entity].html.twig`

**Override**: Enable debug → view source for suggestions → copy from core/themes → place in templates/ → `ddev drush cr`

**Template directory structure**:

```
templates/
├── block/           # Block templates
├── content/         # Node templates
├── field/           # Field templates
├── form/            # Form element templates
├── layout/          # Layout templates
├── misc/            # Miscellaneous templates
├── navigation/      # Menu and navigation
├── paragraph/       # Paragraph templates
└── views/           # Views templates
```

### Preprocess Functions (`metsis_drupal.module`)

Module-level preprocess/theme hooks live in `metsis_drupal.module`. Hook implementations are organised in `src/Hook/`.

```php
function metsis_drupal_preprocess_views_view(&$variables) {
  // ...
}
function metsis_drupal_theme_suggestions_alter(array &$suggestions, array $variables, $hook) {
  // ...
}
```

### Single Directory Components (SDC)

Drupal 10.1+ core, or `ddev composer require drupal/sdc` for 10.0

**Structure**: `components/[name]/` with `[name].component.yml`, `[name].twig`, optional `.css`/`.js`

**component.yml**:

```yaml
name: Card
props:
  {
    type: object,
    properties: { title: { type: string }, link: { type: string } },
  }
slots: { content: { title: Content } }
```

**Usage**: `{% include 'metsis_drupal:doi' with { doi_url: url } %}` or `{% embed %}` for slots

**Module SDC components** (in `components/`):

- `metsis_drupal:bbox_form_tabs` — bounding box filter tabs
- `metsis_drupal:cc_license` — Creative Commons license icon
- `metsis_drupal:doi` — DOI icon + link
- `metsis_drupal:search` — search component

**Commands**: `ddev drush sdc:list`, `ddev drush pm:enable sdc`

### Troubleshooting

```bash
rm -rf node_modules package-lock.json && npm install   # Reset deps
rm -rf dist/ css/ js/compiled/                          # Clear build cache
```

**Performance**: Minify for prod, imagemin, critical CSS, font-display:swap, CSS/JS aggregation, AdvAgg module

## Environment Indicators

- **Visual verification**: Check indicators display correctly on all pages
- **Color scheme**: GREEN (Local), BLUE (DEV), ORANGE (STG), RED (PROD)
- **Never commit "LOCAL"** as value in `environment_indicator.indicator.yml` for production! Always use "PROD" and red color.

## Documentation

**MANDATORY**: Document work in "Tasks and Problems" section. Use real date (`date` command). Document: modules, fixes, config changes, optimizations, problems/solutions.

## Common Tasks

### New Module

```bash
# Create /web/modules/custom/metsis_<name>/ with:
# - metsis_<name>.info.yml (name, type:module, core_version_requirement:^10||^11||^12, package:METNO)
# - metsis_<name>.module (hooks), .routing.yml, .permissions.yml, .services.yml as needed
ddev drush pm:enable metsis_<name> && ddev drush cr
```

### Update Core

```bash
ddev export-db --file=backup.sql.gz                                    # Backup
ddev composer update drupal/core-recommended drupal/core-composer-scaffold --with-dependencies
ddev drush updb && ddev drush cr                                       # Updates + cache
```

### Database Migration

```php
// In metsis_drupal.install
function metsis_drupal_update_10001() {
  // Use EntityDefinitionUpdateManager for field changes
  // Check field exists, update displays, log completion
  drupal_flush_all_caches();
}
```

### Tests

```bash
ddev exec vendor/bin/phpunit tests   # PHPUnit (run from module root)
ddev exec vendor/bin/codecept run                                 # Codeception
# Dirs: tests/src/Unit/, Kernel/, Functional/; tests/acceptance/
```

### Permissions

```bash
ddev drush role:perm:list [role]                                  # List
# PHP: user_role_grant_permissions($role_id, ['perm1']); drupal_flush_all_caches();
```

## Troubleshooting

### Quick Fixes

```bash
ddev drush cr                                                     # Clear cache
ddev restart                                                      # Restart containers
ddev xdebug on|off                                               # Debug mode
ddev drush watchdog:show --count=50                              # Check logs
```

### Cache Not Clearing

```bash
drush cr                                                     # Standard
rm -rf web/sites/default/files/php/twig/* && ddev drush cr       # Twig
ddev drush sql:query "TRUNCATE cache_render;" && ddev drush cr   # Nuclear
```

### Database Issues

```bash
ddev drush sql:cli                     # Check connection (SELECT 1;)
ddev drush updb && ddev drush entity:updates   # Pending updates
ddev mysql -e "REPAIR TABLE <name>;"   # Repair table
```

### DDEV Issues

```bash
ddev restart                           # Soft restart
ddev stop && ddev start                # Full restart
ddev delete -O && ddev start           # Recreate containers
ddev logs                              # View logs
```

### Module Installation

```bash
ddev composer why-not drupal/<module>  # Check deps
ddev composer require drupal/<module> && ddev drush pm:enable <module>
ddev drush updb && ddev drush entity:updates   # Schema issues
```

### Permissions

```bash
ddev exec chmod -R 775 web/sites/default/files
ddev exec chmod 444 web/sites/default/settings.php
```

### WSOD (White Screen)

```bash
ddev drush config:set system.logging error_level verbose
ddev logs && ddev drush watchdog:show --count=50
ddev exec tail -f /var/log/php/php-fpm.log    # Check fatal errors
```

### Config Import Fails

```bash
ddev drush config:status              # Check status
ddev drush config:set system.site uuid [correct-uuid]  # UUID mismatch
```

### Memory Issues

```bash
echo "memory_limit = 512M" >> .ddev/php/php.ini && ddev restart
# Or: ddev exec php -d memory_limit=1G vendor/bin/drush <cmd>
```

## Additional Resources

- **Project Documentation**: `AGENTS.md` (this file) and `docs/`
- **Drupal Documentation**: https://www.drupal.org/docs
- **DDEV Documentation**: https://ddev.readthedocs.io/

---

# <!--

PROJECT-SPECIFIC SECTIONS BELOW
Add sections specific to your project here
===========================================

-->

# <!--

# HOW TO DISCOVER FULL ENTITY STRUCTURE - GUIDE FOR AI/LLM

Use this guide to discover the complete entity structure of a Drupal project.
Priority order: 1) Config YAML files (most complete), 2) Drush commands, 3) PHP evaluation

### 1. CONFIG YAML FILES (Primary Source)

Default config directory: `./config/sync/` (see "Directory Structure" section for verification commands and multisite paths)

**Entity Type Config File Patterns:**

| Entity Type           | Config File Pattern                | Example                                             |
| --------------------- | ---------------------------------- | --------------------------------------------------- |
| Content Types         | `node.type.*.yml`                  | `node.type.article.yml`                             |
| Field Storage         | `field.storage.*.yml`              | `field.storage.node.field_image.yml`                |
| Field Instance        | `field.field.*.yml`                | `field.field.node.article.field_image.yml`          |
| Paragraph Types       | `paragraphs.paragraphs_type.*.yml` | `paragraphs.paragraphs_type.text.yml`               |
| Media Types           | `media.type.*.yml`                 | `media.type.image.yml`                              |
| Taxonomy Vocabularies | `taxonomy.vocabulary.*.yml`        | `taxonomy.vocabulary.tags.yml`                      |
| View Modes            | `core.entity_view_mode.*.yml`      | `core.entity_view_mode.node.teaser.yml`             |
| Form Modes            | `core.entity_form_mode.*.yml`      | `core.entity_form_mode.node.default.yml`            |
| View Display          | `core.entity_view_display.*.yml`   | `core.entity_view_display.node.article.default.yml` |
| Form Display          | `core.entity_form_display.*.yml`   | `core.entity_form_display.node.article.default.yml` |

**Commands to list config files:**

```bash
# List all content type configs
ls config/sync/node.type.*.yml

# List all field storage configs
ls config/sync/field.storage.*.yml

# List all paragraph type configs
ls config/sync/paragraphs.paragraphs_type.*.yml

# Read specific config file
cat config/sync/node.type.article.yml
```

### 2. DRUSH COMMANDS

```bash
# List all entity types
ddev drush entity:info

# List bundles for entity type
ddev drush entity:bundle-info node
ddev drush entity:bundle-info paragraph
ddev drush entity:bundle-info media
ddev drush entity:bundle-info taxonomy_term

# List fields for entity type and bundle
ddev drush field:list node article
ddev drush field:list paragraph text

# Get field info
ddev drush field:info node article field_image

# Export all config
ddev drush config:export

# Get specific config
ddev drush config:get node.type.article

# List all config
ddev drush config:list | grep node.type
```

### 3. PHP/DRUSH PHP:EVAL

For programmatic access to field definitions:

```bash
# Get all fields for content type
ddev drush php:eval "
\$fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'article');
foreach (\$fields as \$name => \$field) {
  echo \$name . ' - ' . \$field->getLabel() . ' (' . \$field->getType() . ')' . PHP_EOL;
}
"

# Get field settings
ddev drush php:eval "
\$field = \Drupal\field\Entity\FieldConfig::loadByName('node', 'article', 'field_image');
print_r(\$field->getSettings());
"

# Get all bundles for entity type
ddev drush php:eval "
\$bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo('node');
foreach (\$bundles as \$id => \$info) {
  echo \$id . ' - ' . \$info['label'] . PHP_EOL;
}
"

# Export full entity structure as JSON
ddev drush php:eval "
\$entity_types = ['node', 'paragraph', 'media', 'taxonomy_term'];
\$result = [];
foreach (\$entity_types as \$entity_type) {
  \$bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo(\$entity_type);
  foreach (\$bundles as \$bundle_id => \$bundle_info) {
    \$fields = \Drupal::service('entity_field.manager')->getFieldDefinitions(\$entity_type, \$bundle_id);
    \$field_list = [];
    foreach (\$fields as \$name => \$field) {
      \$field_list[\$name] = [
        'label' => (string) \$field->getLabel(),
        'type' => \$field->getType(),
        'required' => \$field->isRequired(),
      ];
    }
    \$result[\$entity_type][\$bundle_id] = [
      'label' => \$bundle_info['label'],
      'fields' => \$field_list,
    ];
  }
}
echo json_encode(\$result, JSON_PRETTY_PRINT);
"
```

### RECOMMENDED WORKFLOW

1. **First**: Check if `config/sync/` directory exists and list YAML files
2. **Second**: Use `ddev drush entity:bundle-info [type]` for quick overview
3. **Third**: Use `ddev drush field:list [type] [bundle]` for field details
4. **Fourth**: Use `php:eval` for complex queries or full export

-->

## Drupal Entities Structure

Complete reference of content types, media types, taxonomies, and custom entities. See "HOW TO DISCOVER FULL ENTITY STRUCTURE" guide above for discovery commands.

### Content Types (Node Bundles)

```toon
content_types[4]{machine_name,label,description,features}:
  dataset,Dataset,Scientific dataset in the Meteorological context,"revisions,menu_ui"
  article,Article,Time-sensitive content like news and blog posts,"revisions"
  page,Basic page,Static content such as About us,"revisions"
  movie,Movie,(dev/demo content type),"revisions,menu_ui"
```

### Paragraph Types

None — Paragraphs module is not used in this project.

### Media Types

None — Media module types are not configured in this project.

### Taxonomy Vocabularies

```toon
taxonomies[5]{machine_name,label,description}:
  dataset_variables,dataset variables,Variables describing the dataset
  countries,Countries,(country tagging)
  keywords,Keywords,(keyword tagging)
  tags,Tags,Used to group articles on similar topics
  genres,Genres,(dev/demo vocabulary)
```

### Custom Entities

None — no custom content entities. All data is sourced from Solr index at query time.

### Entity Relationships

- **Dataset** → **dataset_variables** taxonomy (via field reference, for variable tagging)
- **Dataset** → **keywords** taxonomy (keyword tagging)
- **Article** → **tags** taxonomy (via `field_tags`)
- Search results are Solr documents, not node entities — relationships are encoded in Solr fields

### Field Patterns

**Common field naming patterns in this project**:

- `field_[name]` - Standard field prefix
- `field_[prefix]_[name]` - Module-specific fields (e.g., `field_meta_tags`)
- Base fields: `title`, `body`, `created`, `changed`, `uid`, `status`

**Key Field Types**:

- Reference fields: `entity_reference`, `entity_reference_revisions`
- Text fields: `string`, `text_long`, `text_with_summary`
- Date fields: `datetime`, `daterange`, `timestamp`
- Media: `image`, `file`
- Structured: `link`, `address`, `telephone`

### View Modes

**Node View Modes** (standard Drupal defaults in use):

- `full` - Full content display
- `teaser` - Summary/card display

### Module Constants

See `src/MetsisConstants.php` for module-wide constants (e.g. field names, Solr field mappings).

### Entity Access Patterns

- View: `access content` | Edit own: `edit own [type] content` | Delete own: `delete own [type] content` | Admin: `administer [type] content`

### Migration Patterns

Not applicable — no data migrations. Dataset records are indexed from external Solr/MMD sources.

## Project-Specific Features

1. Solr Search Integration

- Core configs: `config/install/search_api.server.metsis_solr.yml`, `config/install/search_api.index.metsis.yml`, `config/install/views.view.metsis_search.yml`
- Query customization: `src/EventSubscriber/SearchApiSolrSubscriber.php` and related subscribers
- Search result rows are Solr documents rendered via custom row plugin/service, not loaded node entities

2. Custom Views Plugins

- Row plugin: `src/Plugin/views/row/MetsisSearchRow.php`
- Filters: `src/Plugin/views/filter/MetsisSolrBboxFilter.php`, `src/Plugin/views/filter/MetsisSolrDateRangeFilter.php`, `src/Plugin/views/filter/MetsisParentFilter.php`
- Field/area plugins: `src/Plugin/views/field/LeafletGeometryField.php`, `src/Plugin/views/area/MetsisMapArea.php`

3. Frontend Apps (Vite + Preact + OpenLayers)

- Source root: `js/metsis/`
- Apps: `metsis-map-app`, `bbox-map-filter`
- Build output: `js/metsis/dist/` (consumed through `metsis_drupal.libraries.yml`)

4. Search Result Rendering Stack

- Main results layout template: `templates/views-view--metsis-search--results.html.twig`
- Row template: `templates/metsis-search-row-default.html.twig`
- Row renderer service: `src/Service/ResultRowRenderer.php`
- Row/search CSS split: `css/metsis_default_row_layout.css` (row), `css/metsis_search_layout.css` (view layout)

## Development Workflow

- Document all significant changes in "Tasks and Problems" section below
- Follow the format and examples provided
- Review existing entries before making architectural changes
- Always run `date` command to get current date before adding entries

---

## Tasks and Problems Log

**Format**: `YYYY-MM-DD | [TYPE] Description` — Types: TASK, PROBLEM/SOLUTION, CONFIG, PERF, SECURITY, NOTE

Run `date` first. Add new entries at top. Include file paths, module names, config keys.

```
[Add entries here - newest first]

2026-10-10 | TASK: Added positive quick-search block selections, conditional configuration controls, predicate visibility, compact layout, and bbox map-height overrides
           | FILES: src/Plugin/Block/MetsisSearchExposedFormBlock.php, src/Plugin/views/filter/MetsisSolrBboxFilter.php, src/Plugin/views/filter/MetsisSolrDateRangeFilter.php, config/schema/metsis_drupal{,.views}.schema.yml, metsis_drupal.install, metsis_drupal.libraries.yml, css/metsis_{bbox_map_filter,search_exposed_form_block}.css, components/bbox_form_tabs/bbox_form_tabs.css, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php, tests/src/Unit/Plugin/views/filter/MetsisExposedFiltersTest.php, README.md, DISCUSSIONS_LOG.md
           | NOTE: Update 11001 preserves existing visibility; Form API states gate applicable controls, hidden exposed predicates submit Intersects without overriding fixed View operators, custom operator IDs and nested BEF wrappers are supported, map height inherits from Views unless overridden, and mobile maps/tabs fit their parent containers

2026-10-08 | TASK: Aligned exposed-form block columns and made secondary Search configurable
           | FILES: src/Plugin/Block/MetsisSearchExposedFormBlock.php, css/metsis_search_exposed_form_block.css, js/metsis-icon-sync.js, assets/icons/magnifier.svg, components/icon_button/icon_button.component.yml, templates/components/input--submit--search-results-submit.html.twig, src/Hook/MetsisSearchFormHooks.php, metsis_drupal.libraries.yml, config/schema/metsis_drupal.schema.yml, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php, README.md, DISCUSSIONS_LOG.md
           | NOTE: Overrode Solo's date-range alignment on block columns, reset exposed-item margins, extracted icon color tracking to a shared library using currentColor for both search magnifiers, and added disable/weight/column settings for the secondary Search action

2026-10-08 | PROBLEM/SOLUTION: Prevented Solo clear-fix pseudo-elements from wrapping the exposed-form block columns
           | FILES: src/Plugin/Block/MetsisSearchExposedFormBlock.php, css/metsis_search_exposed_form_block.css, js/metsis-filter-autosubmit.js, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php, README.md, DISCUSSIONS_LOG.md
           | NOTE: Solo adds .solo-clear pseudo-elements to every container; hiding those generated flex items only within the block grid keeps columns side-by-side. The block now disables BEF and METSIS filter-change autosubmit and adds an always-visible Search button with a magnifier icon

2026-10-08 | PROBLEM/SOLUTION: Removed disabled filter wrappers and stabilized the block flex layout against Solo container classes
           | FILES: src/Plugin/Block/MetsisSearchExposedFormBlock.php, css/metsis_search_exposed_form_block.css, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php, README.md, DISCUSSIONS_LOG.md
           | NOTE: Disabled filters now remove their generated wrapper in addition to raw value/operator elements; Solo's container preprocess adds .solo-date-range to outer containers with date widgets, so the block explicitly overrides that theme layout and uses viewport breakpoints for responsive flex columns

2026-10-08 | PROBLEM/SOLUTION: Replaced exposed-form height reflow with configurable responsive column count
           | FILES: src/Plugin/Block/MetsisSearchExposedFormBlock.php, css/metsis_search_exposed_form_block.css, metsis_drupal.libraries.yml, config/schema/metsis_drupal.schema.yml, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php, README.md, DISCUSSIONS_LOG.md
           | NOTE: Removed the JavaScript height packer; the block now groups filters in Form API render arrays, balances Automatic placement across one to three configured columns, clamps out-of-range assignments, and responsively stacks the grid without requiring a custom outer block template

2026-10-08 | PROBLEM/SOLUTION: Overrode Solo date-range flex rules to align stacked temporal controls
           | FILES: css/metsis_search_layout.css, DISCUSSIONS_LOG.md
           | NOTE: Scoped date-grid rules with sufficient specificity over the theme's .solo-date-range layout and reset flex sizing, padding, and margins on the wrappers, labels, and inputs

2026-10-08 | PROBLEM/SOLUTION: Aligned temporal date field wrappers and inset the predicate control
           | FILES: src/Plugin/views/filter/MetsisSolrDateRangeFilter.php, css/metsis_search_layout.css, README.md, DISCUSSIONS_LOG.md
           | NOTE: Added targeted form wrapper classes and scoped responsive layout rules to prevent theme margins from offsetting the end date field and to keep the predicate dropdown narrower than the fieldset

2026-10-08 | TASK: Gated temporal-filter autosubmit on both dates and aligned date controls
           | FILES: src/Plugin/views/filter/MetsisSolrDateRangeFilter.php, src/Plugin/views/filter/MetsisSolrBboxFilter.php, js/metsis-filter-autosubmit.js, metsis_drupal.libraries.yml, css/metsis_search_layout.css, README.md, DISCUSSIONS_LOG.md
           | NOTE: Temporal operator and date changes bypass BEF's unconditional autosubmit and trigger a search only when both date inputs are selected; the two date fields now use aligned responsive grid columns

2026-10-08 | TASK: Widened the bounding-box map and gated predicate autosubmit on coordinates
           | FILES: components/bbox_form_tabs/bbox_form_tabs.css, src/Plugin/views/filter/MetsisSolrBboxFilter.php, js/metsis-bbox-filter-autosubmit.js, metsis_drupal.libraries.yml, README.md, DISCUSSIONS_LOG.md
           | NOTE: Increased the component map minimum width with a viewport cap; operator changes bypass BEF's unconditional autosubmit and submit only when all four coordinate values are numeric, leaving map drawing submission unchanged

2026-10-08 | PROBLEM/SOLUTION: Matched data-access popover link colors to themed export buttons and clarified file/new-tab behavior for assistive technology
           | FILES: src/Plugin/views/row/MetsisSearchRow.php, css/metsis_default_row_layout.css, tests/src/Unit/Plugin/views/row/MetsisSearchRowTest.php, README.md, DISCUSSIONS_LOG.md
           | NOTE: Data-access links now carry the theme's .button class without a competing wrapper border/color; direct HTTP labels identify common file formats, links opening a new tab announce it in link text, and description text no longer overrides the accessible name

2026-10-08 | TASK: Made the exposed-form block grid independent of theme wrappers and restored compact column stacking
           | FILES: src/Plugin/Block/MetsisSearchExposedFormBlock.php, css/metsis_search_exposed_form_block.css, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php, README.md, DISCUSSIONS_LOG.md
           | NOTE: Exposed widgets and operators are grouped into independently stacked column containers; the block's submit button now says Search and uses the existing magnifier button, with the secondary Update filters/icon-button action omitted

2026-10-08 | TASK: Added per-filter grid-column selectors and applied the block grid to the actual exposed-form wrapper
           | FILES: src/Plugin/Block/MetsisSearchExposedFormBlock.php, config/schema/metsis_drupal.schema.yml, css/metsis_search_exposed_form_block.css, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php, README.md
           | NOTE: Block settings support Automatic or explicit columns 1-3; the grid styles now target the theme's inner .solo-block.exposed-form wrapper, and column 3 maps to column 2 at the two-column breakpoint

2026-10-08 | TASK: Added per-block exposed-filter weights and retained the column-first responsive layout
           | FILES: src/Plugin/Block/MetsisSearchExposedFormBlock.php, config/schema/metsis_drupal.schema.yml, css/metsis_search_exposed_form_block.css, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php, README.md
           | NOTE: Block settings now expose Drupal weight controls per active Views filter, apply weights to their actual exposed widgets including wrapper elements, and keep the actions row spanning the responsive 2-3 column grid

2026-10-08 | PROBLEM/SOLUTION: Kept the search controls in Form API's processed element tree while wrapping them in the search-box SDC
           | FILES: src/Hook/MetsisSearchFormHooks.php, src/Hook/MetsisThemeHooks.php, components/search_box/*, templates/container--metsis-search-box.html.twig, tests/src/Unit/Hook/MetsisSearchFormHooksTest.php, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php
           | NOTE: Moving controls into SDC #slots bypassed Form API name/context processing and triggered a Better Exposed Filters warning; a container theme suggestion now embeds the SDC around its normally processed children

2026-10-08 | TASK: Wrapped the exposed search controls in a reusable SDC component
           | FILES: src/Hook/MetsisSearchFormHooks.php, components/search_box/*, css/metsis_search_layout.css, tests/src/Unit/Hook/MetsisSearchFormHooksTest.php, README.md
           | NOTE: The SDC keeps Drupal's themed input/submit elements intact and owns the magnifier overlay styles, so both the View and exposed-form block receive the styling

2026-10-07 | TASK: Styled the METSIS exposed-form block as a column-first responsive filter grid
           | FILES: src/Plugin/Block/MetsisSearchExposedFormBlock.php, metsis_drupal.libraries.yml, css/metsis_search_exposed_form_block.css, css/metsis_search_layout.css, components/bbox_form_tabs/bbox_form_tabs.css, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php, README.md
           | NOTE: Added a block-scoped responsive grid that fills top-to-bottom before moving right; moved bbox map sizing/attribution styling from the page layout CSS into the reusable bbox_form_tabs SDC styling

2026-10-07 | TASK: Grant anonymous users the metadata export permission on METSIS installation
           | FILES: metsis_drupal.install, tests/src/Kernel/MetsisDrupalInstallUninstallTest.php
           | NOTE: Added the permission to the anonymous role from metsis_drupal_install() and a kernel assertion for the install-hook behavior

2026-10-07 | PROBLEM/SOLUTION: Fixed METSIS exposed-form block access check that hid the block
           | FILES: src/Plugin/Block/MetsisSearchExposedFormBlock.php, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php, DISCUSSIONS_LOG.md
           | NOTE: blockAccess() previously tested displaySet before getView() initialized it, denying access unconditionally; now the placed block renders and emits the configured Views form and theme suggestions

2026-10-07 | PROBLEM/SOLUTION: Removed Devel-only debug calls from the METSIS exposed-form block
           | FILES: src/Plugin/Block/MetsisSearchExposedFormBlock.php, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php
           | NOTE: Debug dpm() calls caused the block unit test to fail when Devel is not bootstrapped; Drupal's 303 after the block configuration POST is the normal successful redirect

2026-10-07 | TASK: Populated facet filters in the exposed-form block and hid sort/pager controls
           | FILES: src/Plugin/Block/MetsisSearchExposedFormBlock.php, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php, README.md, DISCUSSIONS_LOG.md
           | NOTE: Executes the results View when exposed Facets filters need result-backed options, removes sort/pager widgets, and weights temporal extent before geographic bounds; runtime verification confirmed facet options render

2026-10-06 | TASK: Added configurable METSIS search exposed-form block
           | FILES: src/Plugin/Block/MetsisSearchExposedFormBlock.php, src/Hook/MetsisThemeHooks.php, config/schema/metsis_drupal.schema.yml, tests/src/Unit/Plugin/Block/MetsisSearchExposedFormBlockTest.php, README.md, DISCUSSIONS_LOG.md
           | NOTE: Renders the current metsis_search results exposed form, dynamically lists exposed filters for block-level hiding, submits to the configured View route, and provides block/form Twig suggestions

2026-10-06 | TASK: Added product-aware NetCDF on-demand action to NBS search rows
           | FILES: modules/nbs_extensions/config/{install/nbs_extensions.settings.yml,schema/nbs_extensions.schema.yml}, modules/nbs_extensions/nbs_extensions.services.yml, modules/nbs_extensions/src/Service/NetCDFOnDemandButtonBuilder.php, modules/nbs_extensions/tests/src/Unit/NetCDFOnDemandButtonBuilderTest.php, src/Plugin/views/row/MetsisSearchRow.php, README.md
           | NOTE: Added the default supported product list and schema; rows now link to the NetCDF request controller only for configured products without an existing HTTP .nc resource

2026-10-05 | TASK: Ported the NetCDF on-demand request flow into nbs_extensions with core HTMX
           | FILES: modules/nbs_extensions/nbs_extensions.routing.yml, modules/nbs_extensions/nbs_extensions.services.yml, modules/nbs_extensions/nbs_extensions.links.menu.yml, modules/nbs_extensions/config/schema/nbs_extensions.schema.yml, modules/nbs_extensions/config/install/nbs_extensions.settings.yml, modules/nbs_extensions/src/{Controller,Form,Service}/*.php, modules/nbs_extensions/tests/src/Unit/NetCDFOnDemandServiceTest.php, README.md
           | NOTE: Added an authenticated Form API request page, core HTMX progressive enhancement, configurable HTTP(S) backend endpoint, isolated request service with safe failure handling, and unit coverage

2026-10-05 | TASK: Scaffolded the NBS Extensions submodule
           | FILES: modules/nbs_extensions/nbs_extensions.info.yml, modules/nbs_extensions/nbs_extensions.module, README.md
           | NOTE: Added the METNO-packaged nbs_extensions module with Drupal 10/11 compatibility and a dependency on metsis_drupal

2026-10-02 | TASK: Fixed SDC Devel findings and completed component schema examples
           | FILES: components/*/*.component.yml, components/{cc_license,dataset_citation,doi,metadata_person_link,search,temporal_extent}/*.twig, templates/metsis-metadata-document.html.twig, modules/dynamic_landing_pages/templates/dynamic-landing-page.html.twig, tests/src/Unit/SdcComponentsTest.php, README.md, components/{cc_license,search}/README.md
           | NOTE: Updated metadata schema references, defined nested citation props, added realistic prop/slot examples, preserved explicit false boolean options and zero-valued text, aligned omitted DOI color/license width with schema defaults, and moved citation DOI composition into a resource_icon slot in both callers; regression coverage validates all ten components and runs the optional installed SDC Devel rules

2026-09-25 | TASK: Added inline WMS and supported OPeNDAP feature visualisations after the dynamic landing page Data Access table
           | FILES: src/Service/DatasetVisualisationBuilder.php, src/Plugin/views/row/MetsisSearchRow.php, metsis_drupal.services.yml, metsis_drupal.libraries.yml, css/metsis_visualisations.css, css/metsis_default_row_layout.css, modules/dynamic_landing_pages/src/Controller/DynamicLandingPagesController.php, modules/dynamic_landing_pages/src/Hook/ThemeHooks.php, modules/dynamic_landing_pages/templates/dynamic-landing-page.html.twig, modules/dynamic_landing_pages/css/dynamic_landing_pages.css, tests/src/Unit/DatasetVisualisationBuilderTest.php
           | NOTE: Extracted the search result's HTMX Bokeh/WMS controls into a shared DatasetVisualisationBuilder; landing pages now show Visualise <feature_type> when a valid OPeNDAP URL and feature_type are present and Visualise WMS when data_access_json contains a valid OGC WMS resource, with each response rendered inline below its button controls

2026-09-25 | TASK: Replaced the "Open this collection in catalog" plain anchor link with an HTMX-driven icon_button (magnifier-catalog icon)
           | FILES: src/Service/CatalogButtonBuilder.php, src/Controller/CatalogController.php, metsis_drupal.routing.yml, metsis_drupal.services.yml, src/Service/MetadataDocumentNormalizer.php, src/Controller/MetadataDocumentController.php, modules/dynamic_landing_pages/src/Controller/DynamicLandingPagesController.php, templates/metsis-metadata-document.html.twig, modules/dynamic_landing_pages/templates/dynamic-landing-page.html.twig, css/metsis_metadata_document.css
           | NOTE: Clicking the button now issues an HTMX GET to the new metsis_drupal.catalog_htmx_redirect route (CatalogController::htmxRedirect), which responds with an HX-Redirect header to view.metsis_search.results?related_dataset={id} instead of exposing the destination as a plain <a href>; MetadataDocumentNormalizer::buildParentChildInfo() now returns catalog_identifier instead of a pre-built catalog_url, and the new CatalogButtonBuilder service (shared by both controllers) renders the metsis_drupal:icon_button SDC component with the existing magnifier-catalog icon

2026-09-25 | TASK: Applied the same Related dataset placement/popover logic to the dynamic_landing_pages module
           | FILES: modules/dynamic_landing_pages/src/Controller/DynamicLandingPagesController.php, modules/dynamic_landing_pages/templates/dynamic-landing-page.html.twig
           | NOTE: Landing page controller now merges Related dataset into the summary map via MetadataDocumentNormalizer::mergeRelatedDatasetIntoSummary() and loads parent title/abstract/temporal_extent fields; template's render_vocab_value macro gained the same vocabulary.rows support and the two duplicated manual Related dataset rows were removed since the row now renders through the standard summary loop

2026-09-25 | TASK: Reordered Related dataset into Core metadata (after Metadata identifier) with vocab-style (i) popover showing parent Title/Abstract/Temporal extent, and simplified License rendering to a plain identifier link (skipping vocab lookup)
           | FILES: src/Service/MetadataDocumentNormalizer.php, src/Controller/MetadataDocumentController.php, templates/metsis-metadata-document.html.twig, tests/src/Unit/MetadataDocumentNormalizerTest.php
           | NOTE: Added buildLicenseValueNode() (href=use_constraint_resource, text=use_constraint_identifier, no link when resource missing), buildParentDatasetInfo()/buildTemporalExtentText() to populate a generic 'rows' popover payload, and mergeRelatedDatasetIntoSummary() to splice Related dataset into the summary map; extended render_vocab_value Twig macro to support vocabulary.rows alongside existing vocabulary.entries rendering

2026-08-13 | TASK: Integrated personnel_card into dynamic landing pages and added dedicated two-column Core metadata + map overview layout
           | FILES: modules/dynamic_landing_pages/templates/dynamic-landing-page.html.twig, modules/dynamic_landing_pages/src/Controller/DynamicLandingPagesController.php, modules/dynamic_landing_pages/dynamic_landing_pages.libraries.yml, modules/dynamic_landing_pages/css/dynamic_landing_pages.css
           | NOTE: dynamic_landing_pages now ships its own library/CSS, renders personnel entries via metsis_drupal personnel_card SDC, and moves geometry map beside Core metadata in a landing-page-specific responsive grid

2026-08-13 | TASK: Reverted personnel_card usage in metadata document popup and restored legacy personnel field rendering
           | FILES: templates/metsis-metadata-document.html.twig
           | NOTE: Kept components/personnel_card in metsis_drupal for dynamic_landing_pages use; rollback removes embed-only scope issue that caused personnel details to disappear and left only the type icon

2026-08-13 | TASK: Added new personnel_card SDC with type-based Icon API mapping and integrated it into metadata personnel rendering
           | FILES: components/personnel_card/personnel_card.component.yml, components/personnel_card/personnel_card.twig, components/personnel_card/personnel_card.css, components/personnel_card/README.md, templates/metsis-metadata-document.html.twig
           | NOTE: Personnel entries now render one card per entry using an embedded SDC slot; icon selection is handled in Twig (Person -> person, Organisation -> organization, fallback -> organization) while preserving existing ORCID/ROR/email metadata_person_link behavior

2026-07-02 | TASK: Replaced deprecated check_markup() usage with processed_text render arrays for Drupal 11.4 compatibility
           | FILES: src/Controller/MetadataDocumentController.php, src/Service/ResultRowRenderer.php
           | NOTE: Abstract rendering now returns #type=processed_text arrays, markdown detection retained, and validation passed with phpunit (84 tests) + targeted phpstan (no errors)

2026-07-02 | TASK: Added inline WMS visualisation button with HTMX fragment mount per result row
           | FILES: src/Plugin/views/row/MetsisSearchRow.php, src/Controller/WmsController.php, metsis_drupal.routing.yml, js/metsis/metsis-map-app/src/index.jsx, src/Hook/MetsisThemeHooks.php, templates/metsis-wms-document.html.twig
           | NOTE: Visualise WMS now loads a row-scoped HTMX fragment into a unique map app mount id, while preserving the existing full-page WMS route and avoiding config collisions with the main result map

2026-06-25 | TASK: Tightened WMS auto-fit zoom to reduce empty map margins around selected extent
           | FILES: js/metsis/metsis-map-app/src/components/WMSLayerManager.jsx
           | NOTE: Increased fit maxZoom from 8 to 12 and reduced fit padding to 8px on each side so automatic extent fit zooms in more aggressively

2026-06-25 | PROBLEM/SOLUTION: Updated WMS extent parser to match OpenLayers capabilities output shapes
           | FILES: js/metsis/metsis-map-app/src/utils/mapProjection.js, js/metsis/metsis-map-app/src/components/WMSLayerManager.jsx
           | NOTE: Geographic extents now accept OpenLayers array/object forms for EX_GeographicBoundingBox, BoundingBox.extent, and LatLonBoundingBox.extent, with raw-field diagnostics logged when extraction still fails

2026-06-25 | PROBLEM/SOLUTION: Fixed WMS geographic extent extraction by preferring EPSG:4326 BoundingBox values and inheriting parent layer extents
           | FILES: js/metsis/metsis-map-app/src/utils/mapProjection.js, js/metsis/metsis-map-app/src/components/WMSLayerManager.jsx
           | NOTE: EPSG:4326 WMS 1.3 BoundingBox values are now converted from latitude/longitude axis order into geographic extents, CRS:84 is supported, and child layers fall back to parent geographic extents when they do not declare their own

2026-06-25 | PROBLEM/SOLUTION: Added WMS extent-fit diagnostics and prevented ProjectionSwitcher from overriding programmatic WMS fit/projection updates
           | FILES: js/metsis/metsis-map-app/src/components/WMSLayerManager.jsx, js/metsis/metsis-map-app/src/components/ProjectionSwitcher.jsx
           | NOTE: Added console logging for extracted layer extents, merged extent, fit target projection, and post-fit view state; ProjectionSwitcher now applies projection changes only for explicit user selections

2026-06-25 | TASK: Added WMS map fit-to-extent behavior for single and multi-resource capabilities
           | FILES: js/metsis/metsis-map-app/src/components/WMSLayerManager.jsx, js/metsis/metsis-map-app/src/utils/mapProjection.js
           | NOTE: Map now fits to selected layer extent when one resource is available, or to the minimal merged geographic extent when multiple WMS resources are listed

2026-06-01 | PROBLEM/SOLUTION: Replaced direct dynamic imports of proj4 internal package files with a local wrapper module to avoid Vite dev-server MIME/CORS failures for custom projection loading
           | FILES: js/metsis/metsis-map-app/src/projections.js, js/metsis/metsis-map-app/src/utils/proj4Runtime.js
           | NOTE: Vite now resolves the minimal proj4 runtime through a local source module instead of browser-loading bare package-internal URLs like proj4/lib/projections/laea.js

2026-06-01 | PERF: Halved proj4 chunk by replacing full package import with minimal core+defs runtime and only required stere/laea projection modules loaded lazily
           | FILES: js/metsis/metsis-map-app/src/projections.js, js/metsis/metsis-map-app/src/index.jsx, js/metsis/metsis-map-app/src/components/MapContainer.jsx, js/metsis/metsis-map-app/src/components/ProjectionSwitcher.jsx, js/metsis/metsis-map-app/src/components/WMSLayerManager.jsx
           | NOTE: proj4 vendor chunk reduced from 129.21 kB (43.14 kB gzip) to 65.44 kB (22.31 kB gzip) while keeping OL proj4 bridge as a small dynamic chunk and preserving on-demand loading

2026-06-01 | PERF: Reduced map app bundle size by replacing WebGL OSM base layer with Tile+OSM and removing redundant EPSG:4326 WKT projection registration
           | FILES: js/metsis/metsis-map-app/src/components/MapContainer.jsx, js/metsis/metsis-map-app/src/projections.js, js/metsis/metsis-map-app/src/utils/mapProjection.js
           | NOTE: Build dropped from 353 to 333 transformed modules; primary entry chunk reduced from 225.08 kB (72.90 kB gzip) to 156.83 kB (52.91 kB gzip) while preserving shared OL chunks across metsis-map-app and bbox-map-filter

2026-06-01 | TASK: Derived WMS VERSION from capabilities, auto-selected supported map projection, and fit view to selected WMS layer extent
           | FILES: js/metsis/metsis-map-app/src/components/WMSLayerManager.jsx, js/metsis/metsis-map-app/src/components/ProjectionSwitcher.jsx, js/metsis/metsis-map-app/src/components/MapApp.jsx, js/metsis/metsis-map-app/src/utils/mapProjection.js
           | NOTE: Added reusable projection utility functions; prefers EPSG:32661 when selected layer extent fits projection world extent; keeps projection switcher state synchronized with parent updates

2026-05-20 | SECURITY: Added CSS ID sanitization for Solr-derived values to prevent unsafe selector injection
           | FILES: src/Plugin/views/row/MetsisSearchRow.php
           | NOTE: New sanitizeIdValue() method ensures all IDs from Solr data (row_id, metadata_identifier) are safe for CSS selectors before use; reusable for popover IDs, anchor names, plot element IDs

2026-05-20 | TASK: Fixed vocabulary popover empty-space regression by sizing rendered height to content while keeping viewport cap
          | FILES: js/metsis-vocab-popover.js
          | NOTE: positionPopover now computes contentHeight via scrollHeight and sets explicit height=min(content, viewport cap); overflow remains auto only when content exceeds available height

2026-05-20 | TASK: Adjusted vocabulary popover sizing to shrink-wrap short text while preserving viewport-aware expansion for longer content
          | FILES: css/metsis_vocab_popover.css
          | NOTE: Switched to intrinsic width (inline-size:max-content + max-inline-size cap) and enabled overflow-wrap:anywhere so long unbroken terms can wrap without forcing oversized popovers

2026-05-19 | TASK: Added scoped METSIS exposed-form fieldset template with legend-embedded vocab popover support and modern card styling
          | FILES: src/Hook/MetsisSearchFormHooks.php, src/Hook/MetsisThemeHooks.php, templates/fieldset--metsis-search.html.twig, css/metsis_search_layout.css, css/metsis_vocab_popover.css
          | NOTE: Fieldsets in the METSIS search exposed form now use fieldset__metsis_search suggestion; facet popovers moved from description injection into legend header render slot to avoid spacing and checkbox/radio class bleed

2026-05-19 | TASK: Normalized info icon SVG geometry for Drupal Icon API rendering and added inverted variant
          | FILES: assets/icons/info.svg, assets/icons/info-inv.svg
          | NOTE: Replaced inherited fill/stroke compound path with explicit circle/rect geometry so outline and fill render consistently through icon extraction/template sizing

2026-05-18 | TASK: Added mapped facet-header vocabulary popovers and split shared popover CSS into metsis_vocab_popover library
          | FILES: src/Hook/MetsisThemeHooks.php, templates/facets-item-list.html.twig, css/metsis_vocab_popover.css, css/metsis_metadata_document.css, css/metsis_search_layout.css, templates/views-view--metsis-search--results.html.twig, metsis_drupal.libraries.yml
          | NOTE: preprocess_facets_item_list now injects Activity type and Collection group metadata for popover rendering; search view now attaches metsis_vocab_popover so styles/behavior are shared across metadata and facet contexts

2026-05-15 | TASK: Added HTMX AfterSwap vocab popover initializer with dynamic positioning attach for metadata dialog swaps
          | FILES: src/Plugin/views/row/MetsisSearchRow.php, js/metsis-vocab-popover.js
          | NOTE: Metadata trigger now invokes Drupal.metsis.vocabPopover.afterSwap(this); vocab popover script gained namespaced afterSwap resolver and reusable attach function to bind viewport-aware positioning for swapped dialog content

2026-05-15 | TASK: Added HTMX AfterSwap export popover initializer with dynamic positioning for swapped dialog contexts
          | FILES: src/Plugin/views/row/MetsisSearchRow.php, js/metsis-export-popover.js
          | NOTE: Metadata trigger now invokes Drupal.metsis.exportPopover.afterSwap(this) after swap; export popover script gained namespaced afterSwap resolver and viewport-aware JS positioning for non-anchor and fallback modes

2026-05-15 | TASK: Switched metadata dialog behavior initialization to explicit HTMX AfterSwap callback via Htmx class
          | FILES: src/Plugin/views/row/MetsisSearchRow.php, js/metsis-metadata-dialog.js
          | NOTE: Added Drupal.metsis.metadataDialog.afterSwap(this, event) hook to metadata trigger button and moved dialog open/attachBehaviors flow into a dedicated JS function for HTMX-swapped dialog content

2026-05-15 | TASK: Added vocabulary popover support to Core metadata summary fields with aggregated multi-value concept rendering for Collection and ISO topic category
          | FILES: src/Service/MetadataDocumentNormalizer.php, templates/metsis-metadata-document.html.twig, css/metsis_metadata_document.css, tests/src/Unit/MetadataDocumentNormalizerTest.php
          | NOTE: Added configured Solr-to-vocabulary mappings for summary fields, introduced one-button multi-concept popovers for Collection_Keywords and ISO_Topic_Category, and switched popover alt-label wording to Entry terms

2026-05-15 | TASK: Added null-cache fallback for MetVocabService dedicated cache lookups and split tests into kernel integration plus lightweight unit coverage
          | FILES: src/Service/MetVocabService.php, tests/src/Kernel/MetVocabServiceNullCacheKernelTest.php, tests/src/Unit/MetVocabServiceTest.php
          | NOTE: MetVocab lookups now resolve from in-memory index when cache.backend.null is active; kernel test verifies lookupByLabel/lookupByUri/getGroup with NullBackend and unit tests now target internal fallback/refresh logic only

2026-05-15 | TASK: Replaced platform_json fallback dump with structured Platform/Instrument/Ancillary rendering and MetVocab popovers
          | FILES: src/Service/MetadataDocumentNormalizer.php, templates/metsis-metadata-document.html.twig, css/metsis_metadata_document.css, js/metsis-vocab-popover.js, metsis_drupal.libraries.yml, tests/src/Unit/MetadataDocumentNormalizerTest.php
          | NOTE: Removed duplicate platform_json section, added explicit Name labels, kept resource links, switched vocabulary collection lookups to underscore keys (Instrument_Modes, Polarisation_Modes, Product_Types), and enabled info-icon popovers backed by MetVocabService

2026-05-15 | TASK: Kept OPeNDAP .html suffix only in data access link href while preserving original link text
          | FILES: src/Service/MetadataDocumentNormalizer.php
          | NOTE: For OPeNDAP entries, href now uses landing-page URL with .html, but fallback link text remains the original endpoint URL

2026-05-15 | TASK: Added OPeNDAP resource URL normalization to THREDDS landing pages in data access rendering
          | FILES: src/Service/MetadataDocumentNormalizer.php
          | NOTE: data_access_json entries with type OPeNDAP now append .html to resource URLs (without duplicating suffix and preserving query/fragment)

2026-05-15 | TASK: Reused structured link rendering for data_access_json in metadata document
          | FILES: src/Service/MetadataDocumentNormalizer.php, templates/metsis-metadata-document.html.twig
          | NOTE: Data access now uses the same type label, link text, rel, target, and DOI icon behavior as related information

2026-05-15 | TASK: Added structured related information rendering with type labels, smart link text, and DOI icon support
          | FILES: src/Service/MetadataDocumentNormalizer.php, templates/metsis-metadata-document.html.twig, css/metsis_metadata_document.css
          | NOTE: related_information_json now renders each type as label, opens resources in new window with rel="noopener noreferrer nofollow", and prefers description as link text except when it matches type

2026-05-15 | TASK: Reset metadata dialog scroll position to top on every HTMX open
          | FILES: js/metsis-metadata-dialog.js
          | NOTE: Dialog and body scrollTop are now reset before and after showModal() to avoid restored scroll position when reopening records

2026-05-15 | TASK: Switched rectangular polygon classification to use geospatial_bounds3d ENVELOPE WKT
          | FILES: src/Service/MetadataDocumentNormalizer.php
          | NOTE: Geometry type label now treats Polygon as Rectangular polygon when Solr geospatial_bounds3d starts with ENVELOPE(

2026-05-15 | TASK: Simplified rectangular polygon validation to one exterior ring with 5 points and 4 unique vertices
          | FILES: src/Service/MetadataDocumentNormalizer.php
          | NOTE: Rectangular polygon detection now uses project-specific rules (closed ring + axis-aligned edges) and removed extra x/y uniqueness constraints

2026-05-15 | TASK: Simplified rectangular polygon detection for geometry type labels to use 4 vertices plus axis-aligned edges
          | FILES: src/Service/MetadataDocumentNormalizer.php
          | NOTE: Rectangular polygon detection now checks for a closed ring with four vertices and horizontal or vertical edges instead of matching every expected corner

2026-05-15 | TASK: Refined geometry type labeling to classify axis-aligned polygon GeoJSON as Rectangular polygon
          | FILES: src/Service/MetadataDocumentNormalizer.php
          | NOTE: Time and geography now distinguishes generic Polygon from rectangular bounding-box style polygons

2026-05-15 | TASK: Added derived geometry type label to the metadata document Time and geography section from geometry_geojson
          | FILES: src/Service/MetadataDocumentNormalizer.php
          | NOTE: Geometry type now renders as a human-readable value such as Point, Polygon, or Multi Polygon alongside the Leaflet map

2026-05-15 | TASK: Replaced Drupal AJAX metadata modal trigger with HTMX + native HTML5 dialog workflow while keeping existing metadata page endpoint
          | FILES: src/Plugin/views/row/MetsisSearchRow.php, src/Controller/MetadataDocumentController.php, metsis_drupal.routing.yml, src/Hook/MetsisThemeHooks.php, templates/metsis-metadata-document-dialog.html.twig, metsis_drupal.libraries.yml, css/metsis_metadata_dialog.css, js/metsis-metadata-dialog.js
          | NOTE: Added new HTMX route /metsis/metadata/htmx/{id}, custom dialog wrapper with close button, animated backdrop, and 80% width modal presentation

2026-05-14 | TASK: Added structured rendering for personnel and dataset citations on metadata document page using reusable SDC components
          | FILES: src/Controller/MetadataDocumentController.php, templates/metsis-metadata-document.html.twig, css/metsis_metadata_document.css, components/metadata_person_link/*, components/dataset_citation/*
          | NOTE: Dataset citation now renders label/value rows, DOI icon for doi.org resource links with full URL, and generated scientific citation strings

2026-05-04 | PERF: Set Solr join local params to method=dvWithScore and score=none for METSIS parent/child joins
          | FILES: src/EventSubscriber/SearchApiSolrSubscriber.php, metsis_drupal.services.yml
          | NOTE: Removed temporary A/B toggles for join method/score and kept profiler output with fixed join settings

2026-05-04 | PERF: Disabled search_api_solr_devel to stop forced Solr debug params and expensive request/response Kint dumps
          | MODULE: search_api_solr_devel
          | COMMANDS: drush pm:uninstall search_api_solr_devel -y, drush cr

2026-03-27 | TASK: Made search page meta description configurable via settings form and hook
          | FILES: src/Form/MetsisSettingsForm.php, src/Hook/SearchPageMetaTagHook.php
          | CONFIG: Added metsis_drupal.settings.search_meta_description in config/install/metsis_drupal.settings.yml and config/schema/metsis_drupal.schema.yml

Examples:
2024-01-15 | TASK: Created custom module d_custom_feature for special workflow
2024-01-15 | PROBLEM: Config import failing with UUID mismatch
          | SOLUTION: drush config:set system.site uuid [correct-uuid]
2024-01-14 | CONFIG: Enabled Redis cache backend in settings.php
2024-01-14 | PERF: Enabled CSS/JS aggregation and AdvAgg module
2024-01-13 | SECURITY: Applied security update for Drupal core 10.1.8
2024-01-13 | NOTE: Custom entity queries must include ->accessCheck(TRUE/FALSE)
```
