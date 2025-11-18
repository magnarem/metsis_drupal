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

/**
 * Returns responses for METSIS Search routes.
 */
final class MetsisStatusReportController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a StatusPageController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct($entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
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

    /** @var \Drupal\search_api\Entity\Index[] $index */
    $index = $index_storage->load(MetsisConstants::METSIS_SOLR_INDEX_ID);

    /** @var \Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend $backend */
    $backend = $server->getBackend();

    /** @var \Drupal\search_api_solr\SolrConnectorInterface */
    $connector = $backend->getSolrConnector();
    $cloud = $connector instanceof SolrCloudConnectorInterface;

    // Gather metsis solr server information.
    $info["server"]["connection"] = $server->getBackendConfig()["connector_config"];
    $info["server"]["status"] = $backend->isAvailable();
    $info["server"]["solr_version"] = $connector->getSolrVersion();
    $info["server"]["description"] = $server->getDescription();

    $info["index"]["ping"] = $connector->pingCore();
    $info["index"]["schema_version"] = $connector->getSchemaVersionString();
    $info["index"]["stats"] = $connector->getStatsSummary();
    $info["index"]["data"] = $connector->getLuke();

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
      'core' => [
        'title' => $this->t('Collection (core)'),
        'value' => $this->t("<strong>@core</strong>",
          [
            '@core' => $info["server"]["connection"]["core"],
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
      'schema_version' => [
        'title' => $this->t('Schema version'),
        'value' => $this->t("<strong>@schema_version</strong>",
          [
            '@schema_version' => $info["index"]["schema_version"],
          ],
        ),
        'severity' => RequirementSeverity::Info,
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
      'doc_status' => [
        'title' => $this->t('Indexed'),
        'value' => $indexed_message,
        'severity' => RequirementSeverity::Info,
      ],

    ];

    $build['report']['#requirements'] = $requirements;
    /* dpm($info, 'METSIS Status Report Info'); */
    return $build;
  }

}
