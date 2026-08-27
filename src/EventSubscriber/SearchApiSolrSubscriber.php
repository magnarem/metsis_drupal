<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Solarium\QueryType\Select\Query\Query;
use Solarium\Core\Query\Helper as SolariumHelper;
use Solarium\Component\Facet\Field as SolariumFacetField;
use Solarium\Component\Facet\FieldValueParametersInterface;

use Drupal\search_api_solr\Event\PreQueryEvent;
use Drupal\search_api_solr\Event\PostConvertedQueryEvent;
use Drupal\search_api_solr\Event\PostExtractResultsEvent;
use Drupal\search_api_solr\Event\SearchApiSolrEvents;
use Drupal\search_api_solr\Event\PostFieldMappingEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\metsis_drupal\Service\ConfigProvider;
use Drupal\metsis_drupal\Utility\MetsisHelper;
use Psr\Log\LoggerInterface;

/**
 * Search API Solr events subscriber.
 */
class SearchApiSolrSubscriber implements EventSubscriberInterface {

  /**
   * Per-query wall-clock timers keyed by Search API query object hash.
   *
   * @var array<string, int>
   */
  protected array $queryTimers = [];

  /**
   * METSIS config provider (immutable).
   *
   * @var \Drupal\metsis_drupal\Service\ConfigProvider
   */
  protected ConfigProvider $configProvider;

  /**
   * Metsis search helper service.
   *
   * @var \Drupal\metsis_drupal\Utility\MetsisHelper
   */
  protected MetsisHelper $metsisHelper;

  /**
   * Profiler logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $profilerLogger;

  /**
   * Default solr search fields needed for metsis_search.
   *
   * @var array
   */
  protected $defaultFields = [
    'id',
    'related_dataset_id',
    'personnel_organisation',
    'project_long_name',
    'project_short_name',
    'project_name',
    'temporal_extent_start_date',
    'temporal_extent_end_date',
    'last_metadata_updated_date',
    'last_metadata_created_date',
    'abstract',
    'related_url*',
    'isParent',
    'isChild',
    'data_access_url_opendap',
    'feature_type',
    'data_access_url_http',
    'score',
    'use_constraint_identifier',
    'use_constraint_license_text',
    'iso_topic_category',
    'activity_type',
    'dataset_production_status',
    'metadata_status',
    'data_center_name',
    'platform_*',
    'data_center_url',
    'personnel_name',
    'metadata_identifier',
    'collection',
    'dataset_citation_doi',
    'data_access_url_ftp',
    'data_access_url_http',
    'data_access_url_ogc_wms',
    'data_access_wms_layers',
    'total_children:[subquery]',
    'found_children:[subquery]',
    'parent:[subquery]',
    'thumbnail_url',
    'personnel_json:[json]',
    'data_access_json:[json]',
    'platform_json:[json]',
    'related_information_json:[json]',
    'last_metadata_update_json:[json]',
    'dataset_citation_json:[json]',
  ];

  /**
   * Low-cardinality facet fields that are good candidates for enum method.
   *
   * @var string[]
   */
  protected array $enumFacetFields = [
    'activity_type',
    'collection',
    'keywords_gcmdloc',
    'keywords_gcmdprov',
    'keywords_gemet',
    'keywords_northemes',
  ];

  /**
   * Constructor.
   */
  public function __construct(
    ConfigProvider $config_provider,
    MetsisHelper $metsis_helper,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->configProvider = $config_provider;
    $this->metsisHelper = $metsis_helper;
    $this->profilerLogger = $logger_factory->get('metsis_row_profiler');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SearchApiSolrEvents::PRE_QUERY => 'preQuery',
      SearchApiSolrEvents::POST_EXTRACT_RESULTS => ['postExtractResults', -100],
      SearchApiSolrEvents::POST_FIELD_MAPPING => 'postFieldMapping',
      SearchApiSolrEvents::POST_CONVERT_QUERY => 'postConvertedQuery',
    ];
  }

  /**
   * Handle the prequery Event.
   *
   * @param \Drupal\search_api_solr\Event\PreQueryEvent $event
   *   The pre query event.
   */
  public function preQuery(PreQueryEvent $event) {
    // Actions that should be taken on select queries.
    if ($event->getSolariumQuery() instanceof Query) {

      $query_hash = spl_object_hash($event->getSearchApiQuery());
      $this->queryTimers[$query_hash] = hrtime(TRUE);

      /** @var \Solarium\QueryType\Select\Query\Query $solarium_query */
      $solarium_query = $event->getSolariumQuery();

      /** @var \Drupal\search_api\Query\QueryInterface $query */
      $query = $event->getSearchApiQuery();

      // Add custom solr queries when we have a metsis tag.
      if ($query->hasTag('metsis')) {

        // Retrieve geojson and wkt fields from solr.
        $solarium_query->addField('geometry_geojson');
        // $solarium_query->addField('geometry_wkt');
        /*
         * Add fields not defined in search view but needed for
         * other metsis search backends. I.E MapSearch
         */
        $fields = $solarium_query->getFields();
        $newfields = array_merge($fields, $this->defaultFields);
        // Make sure the fields array contains unique fields.
        $uniq_fields = array_unique($newfields);
        $solarium_query->setFields($uniq_fields);

        // Only show return documents with metadata_status:Active.
        $solarium_query->addFilterQuery([
          'key' => 'active_filter',
          'query' => 'metadata_status:Active',
        ]);
        // Add collection filter if collections are selected.
        if ($selected_collections = $this->configProvider->getSettingsConfig()->get('selected_collections')) {
          $solarium_query->createFilterQuery('collection')
            ->setQuery('collection:(' . implode(" ", array_keys($selected_collections)) . ')');
        }

        // Add parent/child filter.
        if (!$query->hasTag('flat_search')) {
          $solarium_query->addFilterQuery([
            'key' => 'parent_child_filter',
            'query' => '{!tag=parent_child_filter}-isChild:true',
          ]);
        }
        // Handle geojson field transformation.
        if ($geofield_transformer = $query->getOption('metsis_drupal_geojson_field')) {
          $solarium_query->removeField('metsis_drupal_geojson_field');
          $solarium_query->addField('metsis_drupal_geojson_field:[geo f=' . $geofield_transformer . ' w=GeoJSON]');

        }

        // Handle bbox filter.
        if ($bbox_filter = $query->getOption('metsis_bbox_filter')) {
          $solarium_query->addFilterQuery([
            'key' => 'bbox_filter',
            'query' => $bbox_filter,
          ]);
        }

        // Handle date range filter.
        if ($date_range_filter = $query->getOption('metsis_date_range_filter')) {
          $solarium_query->addFilterQuery([
            'key' => 'metsis_date_range_filter',
            'query' => $date_range_filter,
          ]);
        }

        // Handle parent filter.
        if ($parent_filter = $query->getOption('metsis_parent_filter')) {
          $solarium_query->addFilterQuery([
            'key' => 'metsis_parent_filter',
            'query' => $parent_filter,
          ]);
          // Update the parent_child_filter, when we have a parent filter.
          $solarium_query->removeFilterQuery('parent_child_filter');
          $solarium_query->addFilterQuery([
            'key' => 'parent_child_filter',
            'query' => '{!tag=parent_child_filter}isChild:true',
          ]);
        }

      }
    }

  }

  /**
   * Handle the post converted query Event.
   *
   * @param \Drupal\search_api_solr\Event\PostConvertedQueryEvent $event
   *   The post converted query event.
   */
  public function postConvertedQuery(PostConvertedQueryEvent $event): void {
    // Actions that should be taken on select queries.
    if ($event->getSolariumQuery() instanceof Query) {

      /** @var \Solarium\QueryType\Select\Query\Query $solarium_query */
      $solarium_query = $event->getSolariumQuery();

      /** @var \Solarium\Core\Query\Helper $helper */
      $helper = $solarium_query->getHelper();

      /** @var \Drupal\search_api\Query\QueryInterface $query */
      $query = $event->getSearchApiQuery();

      /* Get the parse mode */
      $parse_mode = $query->getParseMode();
      $parse_mode_id = $parse_mode->getPluginId();

      /* Search within children if we have a metsis search */
      if ($query->hasTag('metsis')) {
        // Join tuning defaults for production: skip score aggregation and use
        // the join implementation that performed best in profiling.
        $join_localparams = ' method=dvWithScore score=none';

        $main_query = $solarium_query->getQuery();
        if ($parse_mode_id === 'edismax') {
          $normalized_main_query = $this->normalizeNestedTextQuery($main_query);
        }
        else {
          $normalized_main_query = $main_query;
        }
        // Get the main query filters to pass to the child subquery.
        $main_query_filters = $solarium_query->getFilterQueries();

        $search_keys = $query->getKeys();
        $has_search_terms = !empty($search_keys);

        if ($has_search_terms) {
          $hl = $solarium_query->getHighlighting();

          // Build hl.q from individual search keys to avoid join query
          // interfering with snippet generation.
          $terms = $this->extractSearchTerms($search_keys);
          if (!empty($terms)) {
            $hl_query = implode(' ', array_map(
              fn(string $term) => $this->buildHighlightClause($term, $helper),
              $terms
            ));
          }
          else {
            $hl_query = $main_query;
          }
          $hl->setQuery($hl_query);
          $hl->setMethod('unified');
          $hl->setFields([
            'metadata_identifier',
            'related_dataset',
            'activity_type',
            'title_hl',
            'abstract_hl',
            'personnel_name',
            'personnel_organisation',
            'data_center_long_name',
            'data_center_short_name',
            'keywords_keyword',
            'project_name',
            'platform_name',
            'platform_instrument_name',
            'dataset_citation_title',
            'dataset_citation_doi',
            'use_constraint_license_text',
            'related_information_description',
            'descriptions',
          ]);
          $hl->setRequireFieldMatch(TRUE);
          $hl->setSnippets(2);
          $hl->setFragSize(150);
          $hl->setHighlightMultiTerm(TRUE);
          $hl->setDefaultSummary(FALSE);
          $hl->setUsePhraseHighlighter(TRUE);
          $hl->setMergeContiguous(TRUE);
        }
        else {
          // No search terms: disable highlighting entirely to save Solr work.
          $solarium_query->removeComponent('highlighting');
          // $solarium_query->addParam('hl', 'false');
        }

        // Use lucene query parser. To make use of join and subquerires.
        // Parent/Child matching. Only needed when we have query terms.
        $solarium_query->addParam('defType', 'lucene');
        if ($main_query !== '*:*' && !$query->hasTag('flat_search')) {
          $parent_query_param = 'metsis_parent_text_q';
          $child_query_param  = 'metsis_child_text_q';
          $child_join_param   = 'metsis_child_join_q';

          $solarium_query->addParam($parent_query_param, $normalized_main_query);
          $solarium_query->addParam($child_query_param, $normalized_main_query);

          // Parents that have at least one matching child.
          $solarium_query->addParam(
          $child_join_param,
          '{!join from=related_dataset_id to=id defType=lucene' .
          $join_localparams . ' v=$' . $child_query_param . '}'
          );

          if ($query->getOption('metsis_parent_filter')) {
            // Child-browse mode: fq already restricts results to children of
            // the selected parent via a related_dataset filter. The double-join
            // fallback (scan all parents for text match → join to children)
            // scans the entire id field across all documents, which is
            // expensive and adds nothing — the scope is already pinned.
            // Simply match children that contain the search terms directly.
            $solarium_query->setQuery(
              '{!bool should=$' . $child_query_param . '}'
            );
          }
          else {
            // Parent mode: parent text matches OR parent has matching child.
            $solarium_query->setQuery(
              '{!bool should=$' . $parent_query_param . ' should=$' . $child_join_param . '}'
            );
          }
        }

        // In child-browse mode we extract info about the parent of the child.
        $parent_id = $query->getOption('metsis_parent_id');
        if ($parent_id !== NULL && $parent_id !== '' && !$query->hasTag('flat_search')) {
          $solarium_query->addParam('parent.defType', 'lucene');
          $solarium_query->addParam('parent.q', '{!term f=id v=$row.related_dataset_id}');
          $solarium_query->addParam('parent.fq', 'isParent:"true"');
          $solarium_query->addParam('parent.rows', '1');
          $solarium_query->addParam('parent.fl', 'id,metadata_identifier,title,abstract,related_url_landing*,temporal*date*');
        }

        // Rewrite facet-generated filter queries so parents are kept when a
        // matching child carries the selected facet value.
        $this->expandFacetFiltersToChildren($solarium_query);

        // Exclude the parent_child_filter from all facet field domains so that
        // children matching the query also contribute to facet counts. Without
        // this, facet values that only exist on child documents are invisible
        // because the fq restricts the scoring domain to parents/singletons.
        $facet_set = $solarium_query->getFacetSet();
        foreach ($facet_set->getFacets() as $facet) {
          if ($facet instanceof SolariumFacetField) {
            $field = $facet->getField();
            $facet_field_name = $this->extractFacetFieldName((string) $field);

            if (in_array($facet_field_name, $this->enumFacetFields, TRUE)) {
              // Prefer enum for low-cardinality vocabulary-like facets.
              // Request builder generates raw-field params from facet object.
              $facet->setMethod(FieldValueParametersInterface::METHOD_ENUM);
              $facet->setEnumCacheMinimumDocumentFrequency(1);
            }
            // Keep facet domain broad enough for child-doc values by excluding
            // parent_child_filter from the facet domain.
            if (!in_array('parent_child_filter', $facet->getExcludes(), TRUE)) {
              $facet->addExclude('parent_child_filter');
            }
          }
        }

        // Construct the child found/child total subqueries.
        // Count children that match text directly, and also include all
        // children for this row when the parent row itself matches text.
        // These params must live in the found_children.* namespace to be
        // visible inside the [subquery] request context.
        if (!$query->hasTag('flat_search')) {
          $solarium_query->addParam('found_children.defType', 'lucene');

          $solarium_query->addParam('found_children.child_text_q', $normalized_main_query);
          $solarium_query->addParam('found_children.parent_row_q', '{!term f=id v=$row.id}');
          $solarium_query->addParam('found_children.parent_text_q', $normalized_main_query);
          $solarium_query->addParam(
            'found_children.parent_source_q',
            '{!bool must=$parent_row_q must=$parent_text_q}'
          );
          $solarium_query->addParam(
            'found_children.parent_match_q',
            '{!join from=id to=related_dataset_id defType=lucene' .
            $join_localparams . ' v=$parent_source_q}'
          );
          $solarium_query->addParam(
            'found_children.q',
            '{!bool should=$child_text_q should=$parent_match_q}'
          );
          $child_query_filters = $this->createChildQueryFilters($main_query_filters);
          $solarium_query->addParam('found_children.fq', $child_query_filters);
          $solarium_query->addParam('found_children.rows', '0');

          /*
           * Create subquery for getting the total number of children for each
           * parent document in the main query result set.
           */
          $solarium_query->addParam('total_children.q', 'id:*');
          $solarium_query->addParam('total_children.fl', ['id']);
          $solarium_query->addParam('total_children.fq', [
            '+metadata_status:"Active"',
            'collection:(' . implode(' ', array_keys((array) $this->configProvider->getSettingsConfig()->get('selected_collections'))) . ')',
            'isChild:true',
            '{!term f=related_dataset_id v=$row.id}',
          ]);
          $solarium_query->addParam('total_children.rows', '0');
        }
        /*
         * Add some extra edismax query parameters.
         */
        $solarium_query->addParam('pf', 'full_text^5 match_exact^10');
        $solarium_query->addParam('ps', 2);
        $solarium_query->addParam('qs', 2);
        $solarium_query->addParam('mm', '3<90%');

        /* Use parallel threads for facet processing.*/
        $solarium_query->addParam('facet.threads', 12);

        // Strip all debug params so they cannot be doubled by upstream
        // config. debug.explain.structured forces per-row explain scoring
        // which is as expensive as debugQuery itself.
        $solarium_query->removeParam('debugQuery');
        $solarium_query->removeParam('debug.explain.structured');
        $solarium_query->addParam('debugQuery', 'false');

        // Tell solr to use multiThreaded query.
        $solarium_query->addParam('multiThreaded', 'true');
      }

    }

  }

  /**
   * Handle the post field mapping event.
   */
  public function postFieldMapping(PostFieldMappingEvent $event): void {
  }

  /**
   * Handle the post extract results Event.
   */
  public function postExtractResults(PostExtractResultsEvent $event) {
    $query_hash = spl_object_hash($event->getSearchApiQuery());
    $pipeline_ms = 0.0;
    if (isset($this->queryTimers[$query_hash])) {
      $pipeline_ms = (hrtime(TRUE) - $this->queryTimers[$query_hash]) / 1e6;
      unset($this->queryTimers[$query_hash]);
    }

    $solarium_result = $event->getSolariumResult();
    $solr_qtime = method_exists($solarium_result, 'getQueryTime')
      ? (float) $solarium_result->getQueryTime()
      : 0.0;
    $num_found = method_exists($solarium_result, 'getNumFound')
      ? (int) $solarium_result->getNumFound()
      : 0;

    $response_bytes = 0;
    $debug_query = 'n/a';
    $debug_explain = 'n/a';

    if (method_exists($solarium_result, 'getResponse')) {
      $response = $solarium_result->getResponse();
      if ($response) {
        $body = (string) $response->getBody();
        $response_bytes = strlen($body);
        $decoded = json_decode($body, TRUE);
        if (is_array($decoded)) {
          $params = $decoded['responseHeader']['params'] ?? [];
          if (is_array($params)) {
            if (array_key_exists('debugQuery', $params)) {
              $debug_query = is_array($params['debugQuery'])
                ? implode(',', $params['debugQuery'])
                : (string) $params['debugQuery'];
            }
            if (array_key_exists('debug.explain.structured', $params)) {
              $debug_explain = (string) $params['debug.explain.structured'];
            }
          }
        }
      }
    }

    $this->profilerLogger->debug(
      'Solr execute detail: preQuery_to_extract=@pipeline ms | solr_qtime=@qtime ms | numFound=@num | response_bytes=@bytes | debugQuery=@dq | debug.explain.structured=@de | join_method=@jm | join_score=@js',
      [
        '@pipeline' => round($pipeline_ms, 1),
        '@qtime'    => round($solr_qtime, 1),
        '@num'      => $num_found,
        '@bytes'    => $response_bytes,
        '@dq'       => $debug_query,
        '@de'       => $debug_explain,
        '@jm'       => 'dvWithScore',
        '@js'       => 'none',
      ]
    );
  }

  /**
   * Recursively extracts plain string terms from Search API parsed keys.
   *
   * Skips negated groups and metadata keys (prefixed with '#') so only
   * positive terms end up in the highlight query.
   *
   * @param array|string $keys
   *   Parsed keys as returned by QueryInterface::getKeys().
   *
   * @return string[]
   *   Flat list of individual search terms.
   */
  protected function extractSearchTerms(array|string $keys): array {
    if (is_string($keys)) {
      $terms = [];
      preg_match_all('/"([^"]+)"|(\S+)/', trim($keys), $matches);
      foreach ($matches[0] as $index => $_match) {
        $phrase = $matches[1][$index] ?? '';
        $token = $matches[2][$index] ?? '';
        $candidate = $phrase !== '' ? $phrase : $token;
        if ($candidate !== '') {
          $terms[] = $candidate;
        }
      }
      return $terms;
    }

    // Skip the whole group when it is negated.
    if (!empty($keys['#negation'])) {
      return [];
    }

    $terms = [];
    foreach ($keys as $key => $value) {
      // Skip metadata entries like #conjunction and #negation.
      if (is_string($key) && str_starts_with($key, '#')) {
        continue;
      }
      if (is_string($value)) {
        $terms[] = $value;
      }
      elseif (is_array($value)) {
        // Recurse into nested groups.
        array_push($terms, ...$this->extractSearchTerms($value));
      }
    }

    return $terms;
  }

  /**
   * Build one hl.q clause for a term or phrase.
   *
   * Single tokens are escaped with Solarium's escapeTerm() so that Solr
   * special characters such as colons in metadata identifiers
   * (e.g. no.met.adc:43f02cf0-...) are safely backslash-escaped.
   * Multi-word phrases are wrapped in double quotes via escapePhrase().
   *
   * @param string $term
   *   A single parsed search token or phrase.
   * @param \Solarium\Core\Query\Helper $helper
   *   Solarium query helper for proper Solr escaping.
   *
   * @return string
   *   Solr clause prefixed with full_text.
   */
  protected function buildHighlightClause(string $term, SolariumHelper $helper): string {
    $term = trim($term);

    // Normalize optional wrapping quotes from parse output.
    if (strlen($term) >= 2 && $term[0] === '"' && substr($term, -1) === '"') {
      $term = substr($term, 1, -1);
    }
    if (str_contains($term, ':')) {
      // Multi-word phrase: escapePhrase wraps in quotes and escapes internals.
      return 'match_exact:' . "\"{$term}\"" . ' OR full_text:' . "\"{$term}\"";
    }

    if (str_contains($term, ' ')) {
      // Multi-word phrase: escapePhrase wraps in quotes and escapes internals.
      return 'full_text:' . $helper->escapePhrase($term) . ' OR match_exact:' . $helper->escapePhrase($term);
    }

    // Single token: escapeTerm handles colons, slashes, and all other
    // Solr query-parser special characters.
    return 'full_text:' . $helper->escapeTerm($term) . ' OR match_exact:' . $helper->escapeTerm($term);
  }

  /**
   * Rewrites facet filters to keep parents with matching children.
   *
   * Facet filters are generated as field filters on the parent result set.
   * When a selected facet value only exists on a child document, the parent is
   * filtered out unless we OR the facet clause with a child-to-parent join.
   *
   * @param \Solarium\QueryType\Select\Query\Query $solarium_query
   *   The Solarium select query.
   */
  protected function expandFacetFiltersToChildren(Query $solarium_query): void {
    $join_localparams = ' method=dvWithScore score=none';

    // Collect facet filters to rewrite. Solarium stores the {!tag=facet:...}
    // local param separately from the query body, so we detect via getTags().
    $rewrites = [];
    foreach ($solarium_query->getFilterQueries() as $filter_key => $filter) {
      $tags = $filter->getTags();
      $facet_tags = array_filter($tags, fn(string $t) => str_starts_with($t, 'facet:'));
      if (empty($facet_tags)) {
        continue;
      }

      $query_body = (string) ($filter->getOption('query') ?? '');
      if ($query_body === '') {
        continue;
      }

      $rewrites[(string) $filter_key] = [
        'tags' => $tags,
        'query_body' => $query_body,
      ];
    }

    foreach ($rewrites as $filter_key => $parts) {
      $body_param = $this->buildSolrParamName('metsis_facet_body', (string) $filter_key);
      $join_param = $this->buildSolrParamName('metsis_facet_join', (string) $filter_key);

      $solarium_query->addParam($body_param, $parts['query_body']);
      $solarium_query->addParam(
      $join_param,
      '{!join from=related_dataset_id to=id' . $join_localparams .
      ' v=$' . $body_param . '}'
      );

      $rewritten_body = '{!bool should=$' . $body_param . ' should=$' . $join_param . '}';

      $solarium_query->removeFilterQuery($filter_key);
      $new_filter = $solarium_query->createFilterQuery($filter_key);
      $new_filter->setQuery($rewritten_body);
      foreach ($parts['tags'] as $tag) {
        $new_filter->addTag($tag);
      }
    }
  }

  /**
   * Splits a Solr local param prefix from the main query body.
   *
   * @param string $query
   *   Full query string, optionally prefixed with local params.
   *
   * @return array{0: string, 1: string}
   *   Local params prefix and query body.
   */
  protected function splitLocalParams(string $query): array {
    if (preg_match('/^(\{![^}]+\})(.*)$/', $query, $matches) === 1) {
      return [$matches[1], trim($matches[2])];
    }

    return ['', $query];
  }

  /**
   * Extracts raw facet field name from an optional local-params expression.
   *
   * @param string $facet_field
   *   Facet field, optionally prefixed with local params.
   *
   * @return string
   *   Raw field name.
   */
  protected function extractFacetFieldName(string $facet_field): string {
    [, $field] = $this->splitLocalParams(trim($facet_field));
    return trim($field);
  }

  /**
   * Builds a Solr parameter name by combining a prefix and suffix.
   *
   * @param string $prefix
   *   The prefix for the parameter name.
   * @param string $suffix
   *   The suffix for the parameter name.
   *
   * @return string
   *   The combined and sanitized parameter name.
   */
  protected function buildSolrParamName(string $prefix, string $suffix): string {
    return preg_replace('/[^A-Za-z0-9_]+/', '_', $prefix . '_' . $suffix);
  }

  /**
   * Create child query filters based on main query filters.
   *
   * @param array $filters
   *   The main query filters.
   *
   * @return array
   *   The child query filters.
   *
   * @todo Refactor to the MetsisSolrHelper service.
   */
  protected function createChildQueryFilters(array $filters): array {
    $child_query_filters = [];

    if (isset($filters['metsis_parent_filter'])) {
      unset($filters['metsis_parent_filter']);
    }

    $pattern = '/\+\w+_dataset:"[^"]+"/';
    foreach ($filters as $filter) {
      $query = (string) $filter->getOption('query');

      // Default search result mode excludes children. For child subqueries we
      // need the inverse: only children.
      if ($query === '{!tag=parent_child_filter}-isChild:true') {
        $child_query_filters[] = '{!tag=parent_child_filter}isChild:true';
        continue;
      }

      // Parent browsing mode already targets children, so keep it as-is.
      if ($query === '{!tag=parent_child_filter}isChild:true') {
        $child_query_filters[] = $query;
        continue;
      }

      if (preg_match($pattern, $query) === 1) {
        $child_query_filters[] = preg_replace($pattern, '', $query);
        continue;
      }

      $child_query_filters[] = $query;
    }

    // Restrict the child subquery to children of the current parent row.
    $child_query_filters[] = '{!term f=related_dataset_id v=$row.id}';

    return $child_query_filters;
  }

  /**
   * Normalizes nested text query string.
   *
   * Removes outer parentheses and local params when present.
   *
   * @param string $query
   *   The query string to normalize.
   *
   * @return string
   *   The normalized query string.
   */
  protected function normalizeNestedTextQuery(string $query): string {
    $query = trim($query);

    if (preg_match('/^\((\{![^}]+\}.*)\)$/s', $query, $matches) === 1) {
      return trim($matches[1]);
    }

    return $query;
  }

}
