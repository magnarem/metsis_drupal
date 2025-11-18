<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\EventSubscriber;

use Drupal\search_api\Event\MappingFieldTypesEvent;
use Drupal\search_api\Event\DeterminingServerFeaturesEvent;
use Drupal\search_api\Event\GatheringPluginInfoEvent;
use Drupal\search_api\Event\MappingViewsFieldHandlersEvent;
use Drupal\search_api\Event\MappingViewsHandlersEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Search API events subscriber.
 */
class SearchApiSubscriber implements EventSubscriberInterface {
  use StringTranslationTrait;

  /**
   * Adds the mapping how to treat some Solr special fields in views.
   *
   * @param \Drupal\search_api\Event\MappingFieldTypesEvent $event
   *   The Search API event.
   */
  public function onMappingFieldTypes(MappingFieldTypesEvent $event) {
    $mapping = &$event->getFieldTypeMapping();

    // Set the views field handler to our custom leaflet_hanlder.
    $mapping['geojson'] = 'leaflet_geometry_field';
    $mapping['bbox'] = 'solr_bbox';
    $mapping['rpt'] = 'rpt';
    $mapping['dr'] = 'solr_date_range';
    // $mapping['metsis_url'] = 'uri';
    // $mapping['solr_url'] = 'uri';
  }

  /**
   * Adds the mapping how to treat some Solr special fields in views.
   *
   * @param \Drupal\search_api\Event\MappingViewsFieldHandlersEvent $event
   *   The Search API event.
   */
  public function onMappingViewsFieldHandlers(MappingViewsFieldHandlersEvent $event) {
    $mapping = &$event->getFieldHandlerMapping();

    // Set the views field handler to our custom leaflet_hanlder.
    $mapping['geojson']['id'] = 'leaflet_geometry_field';
    // $mapping['metsis_url']['id'] = 'uri';
  }

  /**
   * Adds the mapping how to treat some Solr special fields in views.
   *
   * @param \Drupal\search_api\Event\MappingViewsHandlersEvent $event
   *   The Search API event.
   */
  public function onMappingViewsHandlers(MappingViewsHandlersEvent $event) {
    $mapping = &$event->getHandlerMapping();
    // $mapping['geofield']['field']['id'] = 'location';
    $mapping['geojson']['field']['id'] = 'leaflet_geometry_field';
    $mapping['solr_bbox']['filter'] = [
      'id' => 'metsis_filter_bbox',
      'title' => $this->t('Solr BBox filter'),
      'group' => $this->t('METSIS Filters'),
      'help' => $this->t('Spatial filter on Solr BBoxField'),
    ];
    $mapping['rpt']['filter'] = [
      'id' => 'metsis_filter_bbox',
      'title' => $this->t('Solr BBox filter (RPT)'),
      'group' => $this->t('METSIS Filters'),
      'help' => $this->t('Spatial filter on Solr RecursivePrefixTreeField (RPT)'),
    ];
    $mapping['solr_date_range']['filter'] = [
      'id' => 'metsis_filter_date_range',
      'title' => $this->t('Solr DateRange filter'),
      'group' => $this->t('METSIS Filters'),
      'help' => $this->t('Filter on Solr DateRangeField'),
    ];
  }

  /**
   * Handeling custom server features.
   *
   * @param \Drupal\search_api\Event\DeterminingServerFeaturesEvent $event
   *   The event.
   */
  public function determeningServerFeatures(DeterminingServerFeaturesEvent $event) {
  }

  /**
   * Handeling custom datatypes.
   *
   * @param \Drupal\search_api\Event\GatheringPluginInfoEvent $event
   *   The event.
   */
  public function gatheringDataTypes(GatheringPluginInfoEvent $event) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {

    return [
      SearchApiEvents::MAPPING_FIELD_TYPES => 'onMappingFieldTypes',
      SearchApiEvents::MAPPING_VIEWS_FIELD_HANDLERS => 'onMappingViewsFieldHandlers',
      SearchApiEvents::MAPPING_VIEWS_HANDLERS => 'onMappingViewsHandlers',
      SearchApiEvents::GATHERING_DATA_TYPES  => 'gatheringDataTypes',
      SearchApiEvents::DETERMINING_SERVER_FEATURES => 'determeningServerFeatures',
    ];

  }

}
