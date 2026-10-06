# Cc_license

Renders a linked Creative Commons license icon using the `metsis_drupal_cc`
icon pack.

## Usage

```twig
{% include 'metsis_drupal:cc_license' with {
  license_id: 'CC-BY-4.0',
  license_url: 'https://creativecommons.org/licenses/by/4.0/',
  icon_id: 'by',
  icon_alt_text: 'Creative Commons Attribution 4.0 International',
  width: 88,
} only %}
```

## Additional information

The default icon width is 88 pixels. Accessible text uses `icon_alt_text`,
falling back to `license_id` when no descriptive label is supplied.
Schema-valid examples are in [cc_license.component.yml](cc_license.component.yml).
