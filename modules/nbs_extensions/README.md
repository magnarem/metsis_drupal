# NBS Extensions

NBS-specific NetCDF generation requests for METSIS search results. Requires
`metsis_drupal` and Drupal core HTMX (Drupal 11.4+ or 12).

## Setup

Enable `nbs_extensions`, rebuild caches, and configure **NBS Extensions** under
**Configuration → METNO** (`/admin/config/metno/nbs-extensions`).

- `nbs_extensions.settings.netcdf_ondemand_service_endpoint`: HTTP(S) backend
  endpoint. Keep credentials and deployment-specific connection details in
  environment-managed configuration, not committed YAML.
- `nbs_extensions.settings.netcdf_ondemand_products`: supported product types,
  configured through Drupal's configuration API. Defaults include GRD and the
  supported Sentinel MSI/SAR products listed in `config/install`.
- Users need `access content` and an authenticated account with an email address.

The backend receives a JSON POST with `inputs.email` from the authenticated
account and `inputs.products` containing the dataset title (falling back to its
metadata identifier or Solr ID). A successful response is HTTP 200 with a
non-empty scalar `value` containing the confirmation message.

## Search action and authentication

Eligible rows show **Request "CF-NetCDF file"**. Unsupported product types,
invalid dataset IDs and existing HTTP `.nc` resources suppress the action;
OPeNDAP resources do not.

The link retains `/metsis/netcdf-on-demand/{datasetId}` as its full-page fallback.
HTMX instead opens `/metsis/netcdf-on-demand/modal/{datasetId}` in the existing
`#metsis-metadata-modal-root`, using the shared METSIS dialog template, close,
Escape and backdrop behavior. The parent integration must supply that root once
per page; this submodule does not create another root.
The wrapper passes the translated `NetCDF on demand` title through the shared
dialog's `title` property.

Anonymous users receive a scoped adapter around core `UserLoginForm`.
Authentication, blocked-account checks, flood registration/denial/clearing and
session finalization are delegated unchanged to core. The site's regular login
form is not altered. Invalid credentials stay in the modal with validation
messages. Core flood denial is displayed as a modal error instead of swapping a
bare 403 page; authentication remains denied.

After successful HTMX login, the response emits `nbsNetcdfAuthenticated` with
status 204. A declarative HTMX listener then GETs the authenticated
`/metsis/netcdf-on-demand/modal-request/{datasetId}` using the newly finalized
session. It replaces the modal body's contents, generating fresh authenticated
Form API tokens. The search page does not navigate or reload. Unrelated page
chrome can still display the pre-login state until subsequent navigation.
Core's HTMX renderer supplies validation messages once. Body-level replacement
includes those messages, so failed-login errors disappear on successful login
instead of persisting beside the authenticated form.

The actual request endpoints remain authentication- and permission-protected.
Modal fragments are private, non-storable and have zero render-cache lifetime.
Drupal's HTMX behavior reattaches behaviors and states after swaps; the
submodule behavior restores focus and scroll. Without JavaScript, the original
protected URL retains Drupal's normal access handling; users can log in through
the site's standard login flow, or the scoped login URL redirects to that same
full-page request form after login.

Only local Drupal username/password login is supported. External SSO/MFA
continuations are not implemented; those integrations need their own continuation
requirements before extending this adapter.

## Request outcomes

The confirmation checkbox is required server-side and disables the request
button until selected. Missing datasets return 404. Missing account email,
unconfigured backend, HTTP failure, invalid backend response or transport errors
remain visible and retryable. On acceptance the rebuilt form shows the
confirmation, disables submission and rejects repeat submission of that
accepted form state. This is not backend-wide idempotency: a newly opened form
can create a new request.

## Testing

From the repository root inside the development container:

```bash
vendor/bin/phpcbf -s modules/nbs_extensions/src modules/nbs_extensions/tests
vendor/bin/phpcs -s modules/nbs_extensions/src modules/nbs_extensions/tests
vendor/bin/phpstan analyse --configuration=phpstan.neon modules/nbs_extensions/src modules/nbs_extensions/tests
vendor/bin/phpunit --configuration=phpunit.xml modules/nbs_extensions/tests
npx eslint modules/nbs_extensions/js/netcdf-modal.js
npx prettier --check modules/nbs_extensions/js/netcdf-modal.js modules/nbs_extensions/*.yml
```

Functional tests use real Drupal users, authentication, flood checks, session
cookies and Form API, including invalid CSRF and duplicate-submit checks, with
an in-memory Solr connector and intercepted test
backend. They require the configured PHPUnit database and base URL, but do not
contact production Solr or backend services.
The backend service uses an isolated test HTTP client with no network-capable
handler. Unexpected backend URLs fail immediately, while the configured test
endpoint returns synthetic error or accepted responses.
Test setup installs the parent's
required optional English Solr field types and excludes only its unrelated
default View's existing BEF/bbox schema gaps from strict schema checking.

Targeted PHPStan runs can report the repository's unmatched global
`Unsafe usage of new static` ignore pattern when analyzing only this module;
this is a repository configuration diagnostic, not a module code error.

## Tasks and Problems

- 2026-10-10: Added scoped core login and authenticated NetCDF request fragments,
  the existing-dialog HTMX action enhancement, private no-store response policy,
  focus restoration, and focused unit/functional regression coverage. Root
  templates, scripts, configuration and documentation remain parent-owned.
