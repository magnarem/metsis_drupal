export function getSearchGeojson(settings, searchViewKey) {
  const search = settings?.metsis_drupal?.search;
  // A scoped map must never borrow another View's legacy results.
  return (
    (searchViewKey
      ? search?.instances?.[searchViewKey]?.geojson_feature_collection
      : search?.results?.geojson_feature_collection) ?? null
  );
}

export function mergeSearchSettings(previousSettings, settings) {
  const previousSearch = previousSettings?.metsis_drupal?.search ?? {};
  const nextSearch = settings?.metsis_drupal?.search ?? {};
  return {
    metsis_drupal: {
      search: {
        ...previousSearch,
        ...nextSearch,
        instances: {
          ...previousSearch.instances,
          ...nextSearch.instances,
        },
      },
    },
  };
}
