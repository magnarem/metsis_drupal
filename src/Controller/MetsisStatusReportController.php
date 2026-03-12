<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Controller;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\metsis_drupal\MetsisConstants;
use Drupal\Core\Link;
use Drupal\search_api_solr\SolrCloudConnectorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\metsis_drupal\Service\StatusReportService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api_solr\SearchApiSolrException;

/**
 * Creates a Metsis status report page.
 */
final class MetsisStatusReportController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;


  /**
   * Metsis helper service.
   *
   * @var \Drupal\metsis_drupal\Service\StatusReportService
   */
  protected $statusReportService;

  /**
   * METSIS config (immutable).
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $metsisConfig;

  /**
   * Constructs a StatusPageController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\metsis_drupal\Service\StatusReportService $status_report_service
   *   The metsis status report service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    StatusReportService $status_report_service,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->metsisConfig = $config_factory->get('metsis_drupal.settings');
    $this->statusReportService = $status_report_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('metsis_drupal.status_report_service')
    );
  }

  /**
   * Generate the metsis status report page.
   */
  public function statusReportPage(): array {

    // Initialize the status report array.
    $build['report'] = [
      '#type' => 'status_report',
      '#requirements' => [],
    ];

    if (version_compare(\Drupal::VERSION, '11.2', '>=')) {
      $build['report']['#attached'] = [
        'library' => [
          'system/status.report',
        ],
      ];
    }

    // Load the search API server and index entities.
    $server_storage = $this->entityTypeManager->getStorage('search_api_server');
    $index_storage = $this->entityTypeManager->getStorage('search_api_index');

    // Initialize status information.
    $info = [];
    /** @var \Drupal\search_api\Entity\Server $server */
    $server = $server_storage->load(MetsisConstants::METSIS_SOLR_SERVER_ID);

    /** @var \Drupal\search_api\Entity\Index $index */
    $index = $index_storage->load(MetsisConstants::METSIS_SOLR_INDEX_ID);
    $index_status = $index->status();
    /** @var \Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend $backend */
    $backend = $server->getBackend();

    /** @var \Drupal\search_api_solr\SolrConnectorInterface */
    $connector = $backend->getSolrConnector();
    $cloud = $connector instanceof SolrCloudConnectorInterface;

    // Is available check.
    try {
      $info["server"]["status"] = $backend->isAvailable();
    }
    catch (SearchApiSolrException $e) {
      // If an exception is thrown, we consider the backend unavailable.
      $info["server"]["status"] = FALSE;
    }
    if ($info["server"]["status"] === TRUE) {
      // The configured MMD collections for this site.
      $collections = $this->metsisConfig->get('selected_collections');

      // Gather metsis solr server information.
      $info["server"]["connection"] = $server->getBackendConfig()["connector_config"];
      $info["server"]["solr_version"] = $connector->getSolrVersion();
      $info["server"]["description"] = $server->getDescription();
      $info["index"]["ping"] = $connector->pingCore();
      $info["index"]["schema_version"] = $connector->getSchemaVersionString();
      $info["index"]["stats"] = $connector->getStatsSummary();
      $info["index"]["data"] = $connector->getLuke();
      $info["index"]["status"] = $index_status;
      // Info about the configured MMD collections for this site.
      $info["index"]["collections"] = $collections;

      // Gather parent/child relations.
      $parent_child_info = $this->statusReportService->countParentChildRelations($collections);
      if ($parent_child_info['difference'] == 0) {
        $info['index']['parent_child'] = $this->t('There are @num parents/collections for this site.', [
          '@num' => $parent_child_info['parents_count'],
        ]);
        $info['index']['parent_child_level'] = RequirementSeverity::OK;
      }
      elseif ($parent_child_info['unique_parents'] > $parent_child_info['parents_count']) {
        $num_missing_parents = $parent_child_info['unique_parents'] - $parent_child_info['parents_count'];
        $info['index']['parent_child'] = $this->t('There are @num children pointing to parents that do not exists, or that are not marked as parents.', [
          '@num' => $num_missing_parents,
        ]);
        $info['index']['parent_child_level'] = RequirementSeverity::Warning;
      }
      elseif ($parent_child_info['unique_parents'] < $parent_child_info['parents_count']) {
        $num_missing_children = $parent_child_info['parents_count'] - $parent_child_info['unique_parents'];
        $info['index']['parent_child'] = $this->t('There are @num parents that do not have any children.', [
          '@num' => $num_missing_children,
        ]);
        $info['index']['parent_child_level'] = RequirementSeverity::Warning;
      }

      // Gather other statistics.
      $adc_stats = $this->statusReportService->getOtherStatistics($collections);
      // Process the statsSummary for the status report.
      $pending_msg = $info["index"]["stats"]['@pending_docs'] ? $this->t('(@pending_docs sent but not yet processed)', $info["index"]["stats"]) : '';
      $index_msg = $info["index"]["stats"]['@index_size'] ? $this->t('(@index_size on disk)', $info["index"]["stats"]) : '';
      $indexed_message = $this->t('@num items @pending @index_msg', [
        '@num' => $info["index"]["data"]['index']['numDocs'],
        '@pending' => $pending_msg,
        '@index_msg' => $index_msg,
      ]);

      // If solr server is not available we return an error.
      if ($info["server"]["status"] === FALSE) {
        $url = Url::fromRoute('entity.search_api_server.edit_form')
          ->setRouteParameters(['search_api_server' => MetsisConstants::METSIS_SOLR_SERVER_ID]);
        $link = Link::fromTextAndUrl($this->t('Solr server settings'), $url)->toString();

        $build['report']['#requirements']['server'] = [
          'title' => 'Solr',
          'value' => $this->t('Not available.'),
          'severity' => RequirementSeverity::Error,
          'description' => $this->t('The solr server is not available. Verify connection settings. @url', [
            '@url' => $link,
          ]),
        ];

        return $build;
      }

      // Build the requirements for the status report.
      $requirements = [
        'status' => [
          'title' => $this->t('Status'),
          'value' => "OK",
          'severity' => RequirementSeverity::OK,
        ],
        'server' => [
          'title' => $this->t('Solr server URL'),
          'value' => $this->t("@scheme://@host:@port/@context/",
          [
            '@scheme' => $info["server"]["connection"]["scheme"],
            '@host' => $info["server"]["connection"]["host"],
            '@port' => $info["server"]["connection"]["port"],
            '@context' => $info["server"]["connection"]["context"],

          ],
          ),
          'severity' => RequirementSeverity::Info,
        ],
        'solr_version' => [
          'title' => $this->t('Solr version'),
          'value' => $this->t("@solr_version",
          [
            '@solr_version' => $info["server"]["solr_version"],
          ],
          ),
          'severity' => RequirementSeverity::Info,
        ],
        'desc' => [
          'title' => $this->t('Server description'),
          'value' => $this->t("@desc",
          [
            '@desc' => $info["server"]["description"],
          ],
          ),
          'severity' => RequirementSeverity::Info,
        ],
        'ping' => [
          'title' => $this->t('Ping'),
          'value' => $this->t('The @name @core could be accessed (latency: @millisecs ms).', [
            '@core' => $cloud ? 'collection' : 'core',
            '@name' => $info["server"]["connection"]["core"],
            '@millisecs' => $info["index"]["ping"] * 1000,
          ],
          ),
          'severity' => $info["index"]["ping"] ? RequirementSeverity::OK : RequirementSeverity::Error,
        ],

        'autocommit' => [
          'title' => $this->t('Auto commit'),
          'value' => $this->t("The search should be updated every @time.",
          [
            '@time' => $info["index"]["stats"]["@autocommit_time"],
          ],
          ),

          'severity' => RequirementSeverity::Info,
        ],
        'core' => [
          'title' => $this->t('Collection (core)'),
          'value' => $this->t("<strong>@core</strong>",
          [
            '@core' => $info["server"]["connection"]["core"],
          ],
          ),
          'severity' => RequirementSeverity::Info,
        ],
        'schema_version' => [
          'title' => $this->t('Schema version'),
          'value' => $this->t("<strong>@schema_version</strong>",
          [
            '@schema_version' => $info["index"]["schema_version"],
          ],
          ),
          'severity' => RequirementSeverity::Info,
        ],
        'collections' => [
          'title' => $this->t('Configured collections'),
          'value' => $this->t("<strong>@collections</strong>",
          [
            '@collections' => implode(', ', $info["index"]["collections"]),
          ],
          ),
          'severity' => $info["index"]["collections"] ? RequirementSeverity::Info : RequirementSeverity::Warning,
        ],
        'parent_child' => [
          'title' => $this->t('Parent/child integrity'),
          'value' => $this->t('@text', ['@text' => $info['index']['parent_child']]),
          'severity' => $info['index']['parent_child_level'],
        ],
        'site_status' => [
          'title' => $this->t('This site index (filtered on configured collections)'),
          'value' => $this->t('Total: @total - Active: @active, Inactive: @inactive', [
            '@total' => $adc_stats['total_site'],
            '@active' => $adc_stats['total_site_active'],
            '@inactive' => $adc_stats['total_site_inactive'],
          ]),
          'severity' => RequirementSeverity::Info,
        ],

        'doc_status' => [
          'title' => $this->t('Global index'),
          'value' => $this->t('@message - Active: @active, Inactive: @inactive', [
            '@message' => $indexed_message,
            '@active' => $adc_stats['total_active'],
            '@inactive' => $adc_stats['total_inactive'],
          ]),
          'severity' => RequirementSeverity::Info,
        ],

      ];
    }
    else {
      $requirements = [
        'status' => [
          'title' => $this->t('Status'),
          'value' => $this->t("Solr server or core/collection not available. Check the Solr server and search api settings."),
          'severity' => RequirementSeverity::Error,
        ],
      ];
    }

    $build['report']['#requirements'] = $requirements;
    /* dpm($info, 'METSIS Status Report Info'); */
    return $build;
  }

}
