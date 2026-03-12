<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\EventSubscriber;

use Solarium\QueryType\Select\Query\Query;
use Drupal\search_api_solr\Event\PreQueryEvent;
use Drupal\search_api_solr\Event\PostConvertedQueryEvent;
use Drupal\search_api_solr\Event\PostExtractResultsEvent;
use Drupal\search_api_solr\Event\SearchApiSolrEvents;
use Drupal\search_api_solr\Event\PostFieldMappingEvent;
use Drupal\metsis_drupal\LoggerTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\metsis_drupal\Utility\MetsisHelper;

/**
 * Search API Solr events subscriber.
 */
class SearchApiSolrSubscriber implements EventSubscriberInterface {
  use LoggerTrait;

  /**
   * METSIS config (immutable).
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $metsisConfig;

  /**
   * Metsis search helper service.
   *
   * @var \Drupal\metsis_drupal\Utility\MetsisHelper
   */
  protected $metsisHelper;

  /**
   * Default solr search fields needed for metsis_search.
   *
   * @var array
   */
  protected $defaultFields = [
    'id',
    'personnel_organisation',
    'project_long_name',
    'project_short_name',
    'temporal_extent_start_date',
    'temporal_extent_end_date',
    'last_metadata_update_datetime',
    'abstract',
    'related_url*',
    'isParent',
    'isChild',
    'data_access_url_opendap',
    'feature_type',
    'data_access_url_http',
    'score',
    'geographic_extent_rectangle_south',
    'geographic_extent_rectangle_north',
    'geographic_extent_rectangle_west',
    'geographic_extent_rectangle_east',
    'use_constraint_identifier',
    'iso_topic_category',
    'activity_type',
    'dataset_production_status',
    'metadata_status',
    'data_center_long_name',
    'data_center_short_name',
    'data_center_url',
    'personnel_name',
    'metadata_identifier',
    'collection',
    'dataset_citation_doi',
    'data_access_url_ftp',
    'data_access_url_ogc_wms',
    'data_access_wms_layers',
    'total_children:[subquery]',
    'found_children:[subquery]',
    'parent:[subquery]',
    'thumbnail_url',
  ];

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory, MetsisHelper $metsis_helper) {
    $this->metsisConfig = $config_factory->get('metsis_drupal.settings');
    $this->metsisHelper = $metsis_helper;
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

      /** @var \Solarium\QueryType\Select\Query\Query $solarium_query */
      $solarium_query = $event->getSolariumQuery();

      /** @var \Drupal\search_api\Query\QueryInterface $query */
      $query = $event->getSearchApiQuery();

      // Add custom solr queries when we have a metsis tag.
      if ($query->hasTag('metsis')) {

        // Retrieve geojson and wkt fields from solr.
        $solarium_query->addField('geometry_geojson');
        $solarium_query->addField('geometry_wkt');

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
        if ($selected_collections = $this->metsisConfig->get('selected_collections')) {
          $solarium_query->createFilterQuery('collection')
            ->setQuery('collection:(' . implode(" ", array_keys($selected_collections)) . ')');
        }

        // Add parent/child filter.
        if (!$query->hasTag('flat_search')) {
          $solarium_query->addFilterQuery([
            'key' => 'parent_child_filter',
            'query' => '(isParent:true isParent:false) AND isChild:false',
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
            'query' => "(isParent:true isParent:false) AND isChild:true",
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
  public function postConvertedQuery(PostConvertedQueryEvent $event) {
    // Actions that should be taken on select queries.
    if ($event->getSolariumQuery() instanceof Query) {

      /** @var \Solarium\QueryType\Select\Query\Query $solarium_query */
      $solarium_query = $event->getSolariumQuery();

      /** @var \Solarium\Core\Query\Helper $helper */
      $helper = $solarium_query->getHelper();

      /** @var \Drupal\search_api\Query\QueryInterface $query */
      $query = $event->getSearchApiQuery();

      // Search within children if we have a metsis search.
      if ($query->hasTag('metsis')) {

        $main_query = $solarium_query->getQuery();

        $main_query_filters = $solarium_query->getFilterQueries();

        // Highlighting fails with join query, so we use main query in hl.q.
        $solarium_query->getHighlighting()->setQuery($main_query);
        $hl = $solarium_query->getHighlighting();

        // Escape the main query for the join query's 'v' parameter.
        $escaped_query = $helper->escapeLocalParamValue($main_query);

        // Construct the parent/child join query.
        $child_q_join = "{!join from=related_dataset_id to=id v=$escaped_query}";
        $solarium_query->setQuery($main_query . ' || (' . $child_q_join . ')');

        // Construct the child found/child total subqueries.
        $solarium_query->addParam('found_children.q', $main_query);
        $child_query_filters = $this->createChildQueryFilters($main_query_filters);
        $solarium_query->addParam('found_children.fq', $child_query_filters);
        $solarium_query->addParam('found_children.rows', '0');

        /*
         * Create subquery for getting the total number of children for each
         * parent document in the main query result set.
         */
        $solarium_query->addParam('total_children.q', '{!terms f=related_dataset_id v=$row.id}');
        $solarium_query->addParam('total_children.fq', '+metadata_status:"Active"');
        $solarium_query->addParam('total_children.rows', '0');

        /*
         * Add parent subquery to get information about parent
         * if dataset is a child.
         */
        $solarium_query->addParam('parent.q', '{!terms f=id v=$row.related_dataset_id}');
        $solarium_query->addParam('parent.fq', 'isParent:"true"');
        $solarium_query->addParam('parent.rows', '1');
        $solarium_query->addParam('parent.fl', 'id,metadata_identifier,title, abstract, related_url_landing*,temporal*date*');
      }
    }
  }

  /**
   * Handle the post field mapping event.
   */
  public function postFieldMapping(PostFieldMappingEvent $event) {
    $field_mapping = $event->getFieldMapping();

  } // $bbox_filter = {

  // }
  // $this->getQuery()->setOption('bbox_filter",$field, "$minX,$maxX,$maxY,$minY", $this->operator);

  /**
   * Handle the post extract results Event.
   */
  public function postExtractResults(PostExtractResultsEvent $event) {
    $results = $event->getSearchApiResultSet();
    // $solr_results = $event->getSolariumResult();
    // $body = $solr_results->getResponse()->getBody();
    // foreach ($results as $result) {
    // if ($geojson_field = $result->getField('metsis_drupal_geojson_field')) {
    // $gojeson = $geojson_field->getValues();
    // dpm($gojeson, 'geojson_field');
    // //$result->setField('metsis_drupal_geojson_field', json_decode($geojson_field, TRUE));
    // }
    // }
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

    // Add the same collection filter as from the main query.
    // $child_query_filters[] = $filters['collection']->getOption('query');.
    // Add bbox filter if exits in main query.
    // And to the metsis state for bbox filter.
    // dpm($filters);
    // Some helpers.
    $pattern = '/\+\w+_dataset:"[^"]+"/';
    foreach ($filters as $filter) {
      if ($filter->getOption('query') === '(isParent:true isParent:false) AND isChild:false') {
        continue;
      }
      elseif (strpos($filter->getOption('query'), 'isChild') !== FALSE) {
        $fq = str_replace('isChild:false', 'isChild:true', $filter->getOption('query'));

        if (preg_match($pattern, $filter->getOption('query'), $m) == 1) {
          $fq = preg_replace($pattern, '', $fq);
        }
        if (strpos($fq, 'isParent:true') !== FALSE) {
          // $this->getLogger()->notice("got is parent true");
          $fq = str_replace('isParent:true', '', $fq);
        }
        $child_query_filters[] = $fq;
      }

      elseif (strpos($filter->getOption('query'), 'isParent') !== FALSE) {
        $fq2 = str_replace('isParent:true', '', $filter->getOption('query'));
        $child_query_filters[] = $fq2;

      }
      elseif (preg_match($pattern, $filter->getOption('query'), $m) == 1) {
        $fq = preg_replace($pattern, '', $filter->getOption('query'));
        $child_query_filters[] = $fq;
      }

      else {
        $child_query_filters[] = $filter->getOption('query');
      }

    }
    // Filter on related children.
    $child_query_filters[] = '{!terms f=related_dataset_id v=$row.id}';
    return $child_query_filters;
  }

}
