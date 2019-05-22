<?php

namespace Drupal\drupal_healthcheck\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Site\Settings;
use Drupal\elasticsearch_connector\ClusterManager;
use Drupal\elasticsearch_connector\ElasticSearch\ClientManagerInterface;
use nodespark\DESConnector\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Database\Database;

class HealthcheckController extends ControllerBase {
  
  /**
   * The page cache kill switch.
   *
   * @var \Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   */
  protected $killSwitch;
  
  /**
   * HealthcheckController constructor.
   * @param ModuleHandlerInterface $module_handler
   * @param KillSwitch $kill_switch
   */
  public function __construct(ModuleHandlerInterface $module_handler, KillSwitch $kill_switch) {
    $this->moduleHandler = $module_handler;
    $this->killSwitch = $kill_switch;
  }
  
  /**
   * @param ContainerInterface $container
   * @return HealthcheckController|ControllerBase
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('page_cache_kill_switch')
    );
  }
  
  public function healthcheck() {
    $this->killSwitch->trigger();
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
      
      $responseData['status'] = $memcachedStatus;
      $responseData['details']['memcached'] = $memcachedStatus;
    }
    
    // ELASTICSEARCH CONNECTION
    if ($this->moduleHandler->moduleExists('elasticsearch_connector')) {
      $elasticStatus = 1;
      /**
       * @var ClusterManager $clusterManager
       */
      $clusterManager = \Drupal::service('elasticsearch_connector.cluster_manager');
      /**
       * @var ClientManagerInterface $clientManager
       */
      $clientManager = \Drupal::service('elasticsearch_connector.client_manager');
      $clusters = $clusterManager->loadAllClusters();
      
      foreach ($clusters as $cluster) {
        /**
         * @var ClientInterface $client
         */
        $client = $clientManager->getClientForCluster($cluster);
        
        if ($client->isClusterOk()) {
          $clusterHealth = $client->cluster()->health();
          
          if ($clusterHealth['status'] !== 'green') {
            $elasticStatus = 0;
            $httpStatus = 500;
            break;
          }
        } else {
          $elasticStatus = 0;
          $httpStatus = 500;
          break;
        }
      }
      
      $responseData['status'] = $elasticStatus;
      $responseData['details']['elasticsearch'] = $elasticStatus;
    }
    
    $response = new JsonResponse($responseData, $httpStatus);
    
    return $this->uncacheableResponse($response);
  }
  
  public function status() {
    $this->killSwitch->trigger();
    $responseData = [
      'status' => 1,
      'time' => time()
    ];
    
    $response = new JsonResponse($responseData, 200);
    
    return $this->uncacheableResponse($response);
  }
  
  private function uncacheableResponse(JsonResponse $response) : JsonResponse {
    $response->setPrivate();
    $response->setMaxAge(0);
    $response->setSharedMaxAge(0);
    $response->headers->addCacheControlDirective('must-revalidate', true);
    $response->headers->addCacheControlDirective('no-store', true);
    $response->headers->addCacheControlDirective('private', true);
    $response->headers->removeCacheControlDirective('public');
    
    return $response;
  }
}
