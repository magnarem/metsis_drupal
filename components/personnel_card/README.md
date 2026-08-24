# personnel_card

Personnel card Single Directory Component for METSIS metadata rendering.

## Props

- `personnel_type` (required): Personnel type used to select icon (`Person` => `person`, `Organisation` => `organization`).
- `icon_pack` (optional): Icon pack id. Default `metsis_drupal`.
- `icon_size` (optional): Icon size in pixels. Default `20`.

## Slots

- `content`: Card body content (typically personnel fields).

## Usage

```twig
{% embed 'metsis_drupal:personnel_card' with {
  personnel_type: person.type,
  icon_size: 20,
} only %}
  {% block content %}
    <dl>
      <div><dt>Name</dt><dd>{{ person.name }}</dd></div>
    </dl>
  {% endblock %}
{% endembed %}
```
