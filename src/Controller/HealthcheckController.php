<?php

namespace Drupal\drupal_healthcheck\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Database\Database;

class HealthcheckController extends ControllerBase {
  /**
   * HealthcheckController constructor.
   * @param ModuleHandlerInterface $module_handler
   */
  public function __construct(ModuleHandlerInterface $module_handler) {
    $this->moduleHandler = $module_handler;
  }

  /**
   * @param ContainerInterface $container
   * @return HealthcheckController|ControllerBase
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler')
    );
  }

  public function healthcheck() {
    $httpStatus = 200;

    $responseData = [
      'status' => 1,
      'time' => time(),
      'details' => []
    ];

    // DATABASE CONNECTION
    $db = (int) Database::getConnectionInfo('default');
    $responseData['details']['db'] = $db;
    if (!$db) {
      $httpStatus = 500;
    }

    // MEMCACHED CONNECTION
    if ($this->moduleHandler->moduleExists('memcache') && $memcachedSettings = Settings::get('memcache')) {
      $memcached = new \Memcached();
      $memcachedStatus = 1;

      if (array_key_exists('servers', $memcachedSettings)) {
        foreach ($memcachedSettings['servers'] as $key => $value) {
          $hostAndPort = explode(':', $key);

          if ($hostAndPort &&
              $memcached->addServer($hostAndPort[0], $hostAndPort[1]) &&
              !($memcached->getStats()[$hostAndPort[0].':'.$hostAndPort[1]]['pid'] > 0))
          {
            $memcachedStatus = 0;
            $httpStatus = 500;
            break;
          }
        }
      }

      $responseData['details']['memcached'] = $memcachedStatus;
    }

    // ELASTICSEARCH CONNECTION
    if ($this->moduleHandler->moduleExists('elasticsearch_connector')) {
      $elasticStatus = 1;
      $clusterManager = \Drupal::service('elasticsearch_connector.cluster_manager');
      $clusters = $clusterManager->loadAllClusters();

      foreach ($clusters as $cluster) {
        if (!$cluster->status) {
          $elasticStatus = 0;
          $httpStatus = 500;
          break;
        }
      }

      $responseData['details']['elasticsearch'] = $elasticStatus;
    }

    return new JsonResponse($responseData, $httpStatus);
  }

  public function status() {
    $responseData = [
      'status' => 1,
      'time' => time()
    ];

    return new JsonResponse($responseData, 200);
  }
}
