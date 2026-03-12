<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Form;

use Drupal\Core\Htmx\Htmx;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\metsis_drupal\Utility\MetsisHelper;
use Drupal\metsis_drupal\Service\BokehPlotService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for submitting a URI to generate a Bokeh plot.
 */
class BokehPlotForm extends FormBase {

  /**
   * The MetsisHelper service.
   *
   * @var \Drupal\metsis_drupal\Utility\MetsisHelper
   */
  protected MetsisHelper $metsisHelper;

  /**
   * The Bokeh plot service.
   *
   * @var \Drupal\metsis_drupal\Service\BokehPlotService
   */
  protected BokehPlotService $bokehPlotService;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new static();
    $instance->metsisHelper = $container->get('metsis_drupal.metsis_helper');
    $instance->bokehPlotService = $container->get('metsis_drupal.bokeh_plot_service');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'metsis_drupal_bokeh_plot_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Get the user inputs/form state values.
    $opendap_url = $form_state->getValue('opendap_url', '');
    $lookup_mode = $form_state->getValue('lookup_mode', 'solr');
    $req = json_encode($form_state->getValues());

    $this->getLogger('bokeh')->debug("buildForm @req", ['@req' => $req]);
    // Attach custom css for this form.
    $form['#attached']['library'][] = 'metsis_drupal/metsis_bokeh_form';

    $form['lookup_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('featureType lookup method'),
      '#options' => [
        'solr' => $this->t('ADC (solr internal)'),
        'opendap' => $this->t('OPeNDAP (ds.attrs external)'),
      ],
      '#default_value' => $lookup_mode,
    ];

    // Wrap the opendap url input in a div container.
    $form['input_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => 'opendap-url-input-wrapper',
      ],
    ];
    // The opendap_url input.
    $form['input_container']['opendap_url'] = [
      '#type' => 'url',
      '#title' => $this->t('OPeNDAP URL'),
      '#description' => $this->t('Enter an OPeNDAP URL for a CF compliant dataset.'),
      '#required' => TRUE,
      '#default_value' => $opendap_url,
      '#placeholder' => 'https://example.com/opendap/dataset',
      '#attributes' => [
        'class' => [
          'opendap-url-input',
        ],
        'pattern' => '^https?:\/\/[a-zA-Z0-9\.\-]+(:[0-9]+)?\/.+$',
        'min-lenght' => 10,
        'list' => 'url-examples',
      ],
    ];

    /*
     * Add a datalist with examples.
     * @todo remove when not needed.
     */
    $form['input-container']['datallist'] = [
      '#type' => 'markup',
      '#markup' => '
          <datalist id="url-examples">
            <option value="https://thredds.met.no/thredds/dodsC/arcticdata/infranor/UiO-Kongsvegen-AWS/UiO-Kongsvegen-AWS-sw200-agg.ncml">
            <option value="https://thredds.met.no/thredds/dodsC/arcticdata/obsSynop/01008">
            <option value="https://opendap1.nodc.no/thredds/dodsC/chemistry/StationM/StationM_2008_2019_v2.nc">
            <option value="https://thredds.met.no/thredds/dodsC/arcticdata/met.no/obs-temp/obs-temp_20892.nc">
            <option value="https://thredds.met.no/thredds/dodsC/arcticdata/arctic-passion/UiT-drifters/AWS-ITO/aws_2022.nc">
          </datalist>
      ',
      '#allowed_tags' => ['datalist', 'option'],
    ];

    // Add a X button for clearing the input field.
    $form['input_container']['clear-input'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#attributes' => [
        'class' => [
          'opendap-url-clear-button',
        ],
        // 'type' => 'reset',
        'onclick' => "Drupal.metsis.bokehPlotForm.clearInput('edit-opendap-url')",
        'aria-label' => 'Clear input',
      ],
    ];
    $form['input_container']['clear-input']['x_circle'] = [
      '#type' => 'icon',
      '#pack_id' => 'metsis_drupal',
      '#icon_id' => 'x-circle',
    ];
    if ($this->isHtmxRequest()) {
      // This is a HTMX request, attempt to validate the URL.
      if ($opendap_url !== '' && UrlHelper::isValid($opendap_url, TRUE) === FALSE) {
        $this->getLogger('bokeh')->error("Not valid url error");
        $message = $this->t('The OPeNDAP URL <strong>%url</strong> is not valid.', ['%url' => $opendap_url]);
        $form['input_container']['opendap_url']['#description'] = '<span class="form-item--error-message">' . $message . '</span>';
        $form['input_container']['opendap_url']['#attributes']['class'][] = 'error';
        $form['input_container']['opendap_url']['#attributes']['class'][] = 'bokeh-error';
      }
    }

    // Add an indicator when an url is processed and validated on server.
    $form['input_container']['spinner-container'] = [
      '#id' => 'spinner',
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'spinner-container',
          'htmx-indicator',
        ],
      ],
      '#allowed_tags' => ['div'],
    ];
    $form['input_container']['spinner-container']['indicator'] = [
      '#type' => 'icon',
      '#pack_id' => 'metsis_drupal_spinners',
      '#icon_id' => 'oval',
      '#settings' => [
        'stroke' => '#0074D9',
        'height' => '32',
        'width' => '32',
      ],
    ];
    // Vanilla JS to clear the plot, and reset the description and spinner.
    $htmx_on_clear_action = "Drupal.metsis.bokehPlotForm.clearPlot();";
    $htmx_on_clear_action .= "Drupal.metsis.bokehPlotForm.addLoadingText();";
    $htmx_on_clear_action .= "Drupal.metsis.bokehPlotForm.showSpinnerClearPlot();";
    $htmx_on_clear_action .= "Drupal.metsis.bokehPlotForm.hideClearButton();";
    // Add htmx for the OPeNDAP URL field to trigger validation and plotting.
    (new Htmx())
      ->post()
      ->onlyMainContent()
      ->validate(TRUE)
      ->trigger('input delay:1s')
      ->target('#edit-input-container')
      ->select('#edit-input-container')
      ->indicator('#spinner')
      ->swap('outerHTML')
      ->on('htmx:BeforeRequest', $htmx_on_clear_action)
      ->on('htmx:AfterRequest', 'Drupal.metsis.bokehPlotForm.showClearButton();')
      ->on('input', 'Drupal.metsis.bokehPlotForm.toggleClearButton(this);')
      ->applyTo($form['input_container']['opendap_url']);

    // A container for the plot and the loader spinner.
    $form['bokeh_plot_container'] = [
      '#type' => 'container',
      '#allowed_tags' => ['div', 'script', 'svg'],
      '#attributes' => [
        'class' => [
          'bokeh-plot-container',
        ],
      ],
    ];

    // The bokeh plot from the bokeh service endpoint will be rendered here.
    $form['bokeh_plot_container']['bokeh_plot'] = [
      '#id' => 'bokeh-plot',
      '#type' => 'container',
      '#allowed_tags' => ['div', 'script'],
    ];

    // Add HTMX for loading the plot. And call the revealPlot javascript action.
    (new Htmx())
      ->swapOob('outerHTML')
      ->on('htmx:AfterSettle', "Drupal.metsis.bokehPlotForm.revealPlot();")
      ->applyTo($form['bokeh_plot_container']['bokeh_plot']);

    // The svg spinner icon will be added inside this container.
    $form['bokeh_plot_container']['bokeh_spinner_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'bokeh-spinner-overlay',
        ],
      ],
    ];
    // Add htmx to the spinner container to add the svg returned.
    (new Htmx())
      ->swapOob('innerHTML:.bokeh-spinner-overlay')
      ->applyTo($form['bokeh_plot_container']['bokeh_spinner_container']);

    // If the URL is empty or invalid, log a warning and return the form.
    if (empty($opendap_url) || UrlHelper::isValid($opendap_url, TRUE) === FALSE) {
      if (!empty($opendap_url)) {
        $this->getLogger('bokeh')->warning('BokehPlotForm: Invalid OPeNDAP URL input: @url', ['@url' => $opendap_url]);
      }
      return $form;
    }

    // Handle the HTMX request to validate the URL and fetch the plot markup.
    if ($this->isHtmxRequest() && UrlHelper::isValid($opendap_url, TRUE)) {
      $this->getLogger('bokeh')->debug("Lookup feature_type! {$lookup_mode}");
      $feature_type = $this->metsisHelper->lookupFeatureType(NULL, $opendap_url, $lookup_mode);
      // If we are here, we have input so also show the X button.
      $form['input_container']['clear-input']['#attributes']['class'][] = 'visible';
      // Invalid feature type message.
      if (NULL === $feature_type) {
        $message = $this->t('The OPeNDAP URL <strong>%url</strong> is valid but the <strong>feature type</strong> could <strong>not</strong> be determined.', ['%url' => $opendap_url]);
        $form['input_container']['opendap_url']['#description'] = '<span class="form-item--error-message">' . $message . '</span>';
        $form['input_container']['opendap_url']['#attributes']['class'][] = 'error';
        $form['input_container']['opendap_url']['#attributes']['class'][] = 'bokeh-error';
        return $form;
      }
      if (404 == $feature_type) {
        $message = $this->t('<em>404:</em> The OPeNDAP URL <strong>%url</strong> is a valid url but no product matching OPeNDAP url was found in Solr', ['%url' => $opendap_url]);
        $form['input_container']['opendap_url']['#description'] = '<span class="form-item--error-message">' . $message . '</span>';
        $form['input_container']['opendap_url']['#attributes']['class'][] = 'error';
        $form['input_container']['opendap_url']['#attributes']['class'][] = 'bokeh-error';
        return $form;
      }
      // Valid message.
      $message = $this->t('&#9989; The OPeNDAP URL <strong>%url</strong> is valid. Found feature type: <strong>%feature_type</strong> generating plot.',
      ['%url' => $opendap_url, '%feature_type' => $feature_type]);
      $form['input_container']['opendap_url']['#description'] = '<span class="form-item--valid-message">' . $message . '</span>';
      $form['input_container']['opendap_url']['#attributes']['class'][] = 'bokeh-valid';

      // Get the plot_markup from the service.
      $plot_markup = $this->bokehPlotService->fetchBokehPlot([
        'url' => $opendap_url,
        'feature_type' => $feature_type,
      ]);
      // If service returns NULL, the backend request failed.
      if ($plot_markup === NULL) {
        $message = $this->t('Something went wrong while fetching the bokeh plot.', ['%url' => $opendap_url]);
        $form['input_container']['opendap_url']['#description'] = '<span class="form-item--error-message">' . $message . '</span>';
        $form['input_container']['opendap_url']['#attributes']['class'][] = 'error';
        $form['input_container']['opendap_url']['#attributes']['class'][] = 'bokeh-error';
        return $form;

      }
      // Add the spinner svg icon to the HTMX response.
      $form['bokeh_plot_container']['bokeh_spinner_container']['spinner'] = [
        '#type' => 'icon',
        '#pack_id' => 'metsis_drupal_spinners',
        '#icon_id' => 'puff',
        '#settings' => [
          'stroke' => '#0074D9',
          'height' => '128',
          'width' => '128',
        ],
        '#attributes' => [
          'class' => [
            'bokeh-spinner',
          ],
        ],
      ];
      // Add the plot markup returned from the bokeh service endpoint.
      $form['bokeh_plot_container']['bokeh_plot']['#markup'] = $plot_markup;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

}
