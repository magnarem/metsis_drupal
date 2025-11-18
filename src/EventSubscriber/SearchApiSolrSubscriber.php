<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\EventSubscriber;

use Solarium\QueryType\Select\Query\Query;
use Drupal\search_api_solr\Event\PreQueryEvent;
use Drupal\search_api_solr\Event\PostExtractResultsEvent;
use Drupal\search_api_solr\Event\SearchApiSolrEvents;
use Drupal\search_api_solr\Event\PostFieldMappingEvent;
use Drupal\metsis_drupal\LoggerTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Search API Solr events subscriber.
 */
class SearchApiSolrSubscriber implements EventSubscriberInterface {
  use LoggerTrait;
  /* @todo inject custom query helper service */

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SearchApiSolrEvents::PRE_QUERY => 'preQuery',
      SearchApiSolrEvents::POST_EXTRACT_RESULTS => ['postExtractResults', -100],
      SearchApiSolrEvents::POST_FIELD_MAPPING => 'postFieldMapping',
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

}
