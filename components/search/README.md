# Search

Renders a search input with a magnifier button and optional additional controls.

## Usage

```twig
{% embed 'metsis_drupal:search' with {
  placeholder: 'Search datasets by title or keyword'|t,
} only %}
  {% block other_components %}
    <button type="reset">{{ 'Clear search'|t }}</button>
  {% endblock %}
{% endembed %}
```

## Additional information

The placeholder defaults to the translated text "Type your text".
Schema-valid prop and slot examples are in [search.component.yml](search.component.yml).
