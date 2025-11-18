<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\EventSubscriber;

use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\metsis_drupal\MetsisConstants;
use Drupal\Core\Render\Markup;
use Drupal\views\Ajax\ViewAjaxResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscribe to ajax views responses.
 */
class ViewsAjaxResponseSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::RESPONSE][] = ['onResponse', 0];
    return $events;
  }

  /**
   * Act on thr Ajax response.
   */
  public function onResponse(ResponseEvent $event) {
    $response = $event->getResponse();

    // Check if it's an AjaxResponse from a Views AJAX request.
    if ($response instanceof ViewAjaxResponse) {
      $view_id = $response->getView()->id();
      if ($view_id === MetsisConstants::METSIS_SEARCH_VIEW_ID) {
        $commands = &$response->getCommands();
        // dpm($commands, 'Original Ajax Commands');
        // Collect keys of commands to remove.
        $commandsToRemove = [];

        foreach ($commands as $key => $command) {
          if (isset($command['method']) && $command['method'] === 'replaceWith') {
            $dom_id = $response->getView()->dom_id;
            if ($command['selector'] === ".js-view-dom-id-$dom_id") {
              // Define xpath selectors.
              // Extract the full view HTML.
              $exposedFormSelector = "exposed-form-wrapper-$dom_id";
              $resultsHeaderSelector = "results-header-replace-wrapper-$dom_id";
              $mainResultsSelector = "metsis-search-main-results-$dom_id";
              // Parse the HTML content.
              $html = $command['data'];
              $dom = new \DOMDocument();
              @$dom->loadHTML($html->__toString());

              // Extract specific parts of the HTML.
              $xpath = new \DOMXPath($dom);
              $mainDiv = $xpath->query("//div[contains(@class, 'metsis-search-layout')]");
              $exposedFormWrapper = $xpath->query("//aside[contains(@id, '$exposedFormSelector')]");
              $resultsHeaderReplaceWrapper = $xpath->query("//div[contains(@id, '$resultsHeaderSelector')]");
              $metsisSearchMainResults = $xpath->query("//div[contains(@id, '$mainResultsSelector')]");

              if ($mainDiv->length > 0) {
                // // Extract and reapply attributes using InvokeCommand.
                foreach ($mainDiv->item(0)->attributes as $attr) {
                  $response->addCommand(new InvokeCommand(
                    ".js-view-dom-id-$dom_id", 'attr', [$attr->nodeName, $attr->nodeValue]));
                }
              }
              // Add new commands for each extracted part.
              if ($exposedFormWrapper->length > 0) {
                $form_markup = Markup::create($dom->saveHTML($exposedFormWrapper->item(0)));
                // dpm($form_markup, 'exposed form markup');.
                $response->addCommand(new ReplaceCommand('#' . $exposedFormSelector, $form_markup));
              }
              if ($resultsHeaderReplaceWrapper->length > 0) {
                $resultsHeaderReplaceWrapperMarkup = Markup::create($dom->saveHTML($resultsHeaderReplaceWrapper->item(0)));
                $response->addCommand(new ReplaceCommand('#' . $resultsHeaderSelector, $resultsHeaderReplaceWrapperMarkup));
              }
              if ($metsisSearchMainResults->length > 0) {
                $metsisSearchMainResultsMarkup = Markup::create($dom->saveHTML($metsisSearchMainResults->item(0)));
                $response->addCommand(new ReplaceCommand('#' . $mainResultsSelector, $metsisSearchMainResultsMarkup));
              }
              // Collect the key of the command to be removed.
              $commandsToRemove[] = $key;
            }
          }
          if (isset($command['method']) && $command['method'] === 'prepend') {
            // Ensure the command has the required keys.
            if (isset($command['selector']) && isset($command['data'])) {
              // Replace the prepend command with a ReplaceCommand.
              $response->addCommand(new ReplaceCommand('.metsis-messages-container', $command['data']));
              $commandsToRemove[] = $key;
            }
          }
        }
        // Remove the collected commands after the loop.
        foreach ($commandsToRemove as $key) {
          unset($commands[$key]);
        }
        // Reindex the commands array to maintain sequential keys.
        $commands = array_values($commands);
        // dpm($commands, 'Modified Ajax Commands');.
      }
    }
  }

}
