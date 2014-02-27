<?php

/**
 * @file
 * Definition of Drupal\stage_file_proxy\EventSubscriber\ProxySubscriber.
 */

namespace Drupal\stage_file_proxy\EventSubscriber;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use Drupal\stage_file_proxy\FetchManagerInterface;
use Drupal\Component\Utility\String;
use \Drupal\Component\Utility\Url;

/**
 * Stage file proxy subscriber for controller requests.
 */
class ProxySubscriber implements EventSubscriberInterface {

  /**
   * The manager used to fetch the file against.
   *
   * @var \Drupal\stage_file_proxy\FetchManager
   */
  protected $manager;

  /**
   * Construct the FetchManager.
   *
   * @param \Drupal\stage_file_proxy\FetchManager $manager
   *   The manager used to fetch the file against.
   */
  public function __construct(FetchManagerInterface $manager) {
    $this->manager = $manager;
  }

  /**
   * Fetch the file according the its origin.
   *
   * @param Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The Event to process.
   */
  public function checkFileOrigin(GetResponseEvent $event) {
    $file_dir = $this->manager->filePublicPath();
    if (strpos(request_path(), $file_dir) !== 0) {
      return;
    }

    // Note if the origin server files location is different. This
    // must be the exact path for the remote site's public file
    // system path, and defaults to the local public file system path.
    $remote_file_dir = trim(\Drupal::config('stage_file_proxy.settings')->get('origin_dir'));
    if (!$remote_file_dir) {
      $remote_file_dir = $file_dir;
    }

    $relative_path = drupal_substr(request_path(), drupal_strlen($file_dir) + 1);

    // Get the origin server.
    $server = \Drupal::config('stage_file_proxy.settings')->get('origin');

    if ($server) {
      // Is this imagecache? Request the root file and let imagecache resize.
      if (\Drupal::config('stage_file_proxy.settings')->get('origin') && $original_path = $this->manager->styleOriginalPath($relative_path, TRUE)) {
        $relative_path = file_uri_target($original_path);
        if (file_exists($original_path)) {
          // Imagecache can generate it without our help.
          return;
        }
      }

      $query = \Drupal::request()->query->all();
      $query_parameters = Url::filterQueryParameters($query);

      if (\Drupal::config('stage_file_proxy.settings')->get('hotlink')) {
        $location = url("$server/$remote_file_dir/$relative_path", array(
          'query' => $query_parameters,
          'absolute' => TRUE,
        ));
        header("Location: $location");
        exit;
      }
      elseif ($this->manager->fetch($server, $remote_file_dir, $relative_path)) {
        // Refresh this request & let the web server work out mime type, etc.
        $location = url(request_path(), array('query' => $query_parameters));
        header("Location: $location");
        exit;
      }
      else {
        watchdog('stage_file_proxy', 'Stage File Proxy encountered an unknown error by retrieving file @file', array('@file' => $server . '/' . drupal_encode_path($remote_file_dir . '/' . $relative_path)), WATCHDOG_ERROR);
        throw new NotFoundHttpException();
        exit;
      }
    }
  }

  /**
   * Registers the methods in this class that should be listeners.
   *
   * @return array
   *   An array of event listener definitions.
   */
  static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = array('checkFileOrigin');
    return $events;
  }

}
