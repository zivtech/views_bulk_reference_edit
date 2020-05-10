<?php

namespace Drupal\views_bulk_reference_edit\Plugin\Action;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\views_bulk_operations\Service\ViewsbulkOperationsViewData;
use Drupal\views_bulk_operations\Service\ViewsBulkOperationsActionProcessor;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\views_bulk_edit\Plugin\Action\ModifyEntityValues;

/**
 * Modify referenced entity field values.
 *
 * @Action(
 *   id = "views_bulk_reference_edit",
 *   label = @Translation("Modify referenced entity field values"),
 *   type = ""
 * )
 */
class ModifyReferencedEntityValues extends ModifyEntityValues {

  /**
   * Referenced Entities by referencing entity id.
   *
   * @var array
   */
  protected $referencedEntities;

  /**
   * Object constructor.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin Id.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\views_bulk_operations\Service\ViewsbulkOperationsViewData $viewDataService
   *   The VBO view data service.
   * @param \Drupal\views_bulk_operations\Service\ViewsBulkOperationsActionProcessor $actionProcessor
   *   The VBO action processor.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   Bundle info object.
   * @param \Drupal\Core\Database\Connection $database
   *   Database conection.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ViewsbulkOperationsViewData $viewDataService, ViewsBulkOperationsActionProcessor $actionProcessor, EntityTypeManagerInterface $entityTypeManager, EntityTypeBundleInfoInterface $bundleInfo, Connection $database) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $viewDataService, $actionProcessor, $entityTypeManager, $bundleInfo, $database);
  }

  /**
   * Helper method to get bundles displayed by the view.
   *
   * @return array
   *   Array of entity bundles returned by the current view
   *   keyed by entity type IDs.
   */
  protected function getViewBundles() {

    $bundle_data = [];

    if (!empty($this->context['list']) || $this->context["selected_count"] == $this->context["total_results"]) {

      $bundle_info = $this->bundleInfo->getAllBundleInfo();

      // Map bundle info to configuration array.
      $bundle_info_map = [];
      foreach ($bundle_info as $entity_type => $bundles) {
        foreach ($bundles as $bundle => $label) {
          $key = $entity_type . "_" . $bundle;
          $bundle_info_map[$key] = ["entity_type" => $entity_type, "bundle" => $bundle, "label" => $label['label']];
        }

      }

      // Map referenced entities to get all entity types.
      foreach ($this->context["preconfiguration"] as $key => $entity_config) {

        // $key = $entity_type_id . "_" . $bundle;.
        $whitelist_fields = $this->context["preconfiguration"][$key]['whitelist'];

        $whitelist_fields = array_filter($whitelist_fields, function ($v, $k) {
          return $v !== 0;
        }, ARRAY_FILTER_USE_BOTH);

        // Check if entity type whitelisted by checking the array values
        // have any value but zero
        // https://bugs.php.net/bug.php?id=39579
        // https://blog.josephscott.org/2012/03/12/why-php-strings-equal-zero/
        if (!empty($whitelist_fields)) {
          $label = $bundle_info_map[$key]['label'];
          $bundle_id = $bundle_info_map[$key]['bundle'];
          $entity_type_id = $bundle_info_map[$key]['entity_type'];
          $bundle_data[$entity_type_id][$bundle_id] = $label;
        }
      }

    }
    else {
      \Drupal::messenger()->addError($this->t('No content selected'));
      return [];
    }

    return $bundle_data;

  }

  /**
   * {@inheritdoc}
   */
  public function buildPreConfigurationForm(array $form, array $values, FormStateInterface $form_state) {

    // Add supported referenced entities.
    $entities = array_map(function ($entity) {
      return $entity->get('label');
    }, \Drupal::entityTypeManager()->getDefinitions());

    foreach ($entities as $entity_type_id => $entity) {

      $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type_id);
      foreach ($bundles as $bundle_id => $bundle) {
        $entityFieldManager = \Drupal::service('entity_field.manager');
        try {
          $fields = array_keys($entityFieldManager->getFieldDefinitions($entity_type_id, $bundle_id));
          $key = $entity_type_id . "_" . $bundle_id;
          $options = array_combine($fields, $fields);

          $key = $entity_type_id . "_" . $bundle_id;

          $form[$key] = [
            '#type' => 'details',
            '#open' => FALSE,
            '#title' => $entity_type_id . ' - ' . $bundle_id,
          ];

          $form[$key]['whitelist'] = [
            '#title' => $this->t('Whitelisted fields for bundle @bundle_label for entity @label.', ['@bundle_label' => $bundle['label'], '@label' => $entity]),
            '#type' => 'checkboxes',
            '#options' => $options,
            '#default_value' => isset($values[$key]['whitelist']) ? $values[$key]['whitelist'] : FALSE,
            '#description' => $this->t('Whitelist supported bundles.'),
          ];

        }
        catch (\Exception $e) {

        }
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {

    $referencedEntities = $this->getReferencedEntities($entity->id());
    // Load referenced entity.
    $result = $this->t('Skip (field is not present on this bundle)');

    foreach ($referencedEntities as $referencedEntity) {

      // Foreach vbo entity, execute all referenced entities
      // set their values based on values taken from configuration.
      $type_id = $referencedEntity->getEntityTypeId();
      $bundle = $referencedEntity->bundle();

      if (isset($this->configuration[$type_id][$bundle])) {
        foreach ($this->configuration[$type_id][$bundle] as $field => $value) {

          $key = $type_id . "_" . $bundle;
          $whitelist_fields = array_diff(array_values($this->context["preconfiguration"][$key]['whitelist']), [0]);

          if (!empty($this->configuration['_add_values'])) {
            /** @var \Drupal\Core\Field\FieldStorageDefinitionInterface $storageDefinition */
            $storageDefinition = $entity->{$field}->getFieldDefinition()
              ->getFieldStorageDefinition();
            $cardinality = $storageDefinition->getCardinality();
            if ($cardinality === $storageDefinition::CARDINALITY_UNLIMITED || $cardinality > 1) {
              $current_value = $entity->{$field}->getValue();
              $value_count = count($current_value);
              foreach ($value as $item) {
                if ($cardinality != $storageDefinition::CARDINALITY_UNLIMITED && $value_count >= $cardinality) {
                  break;
                }
                $current_value[] = $item;
              }
              $value = $current_value;
            }
          }

          // Set only whitelisted fields.
          if (in_array($field, $whitelist_fields)) {
            $referencedEntity->{$field}->setValue($value);
          }
        }

        $referencedEntity->save();
        $result = $this->t('Modify field values');
      }
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedEntities($id) {
    return isset($this->referencedEntities[$id]) ? $this->referencedEntities[$id] : FALSE;
  }

  /**
   * Array of referenced entities
   * keyed by referencing entity IDs.
   *
   * {@inheritdoc}
   */
  public function setReferencedEntities() {

    // Set the referenced entities.
    $vbo_entities = [];

    foreach ($this->context['list'] as $k => $item) {
      list(, , $entity_type_id, $id,) = $item;
      $vbo_entities[] = $id;
    }

    array_map(function ($view_result) use (&$query_data, $vbo_entities) {
      $vbo_entity_id = $view_result->id;
      $relationship_entities = $view_result->_relationship_entities;

      if (in_array($vbo_entity_id, $vbo_entities, TRUE)) {
        foreach ($relationship_entities as $entity_type_id => $entity) {
          $this->referencedEntities[$vbo_entity_id][] = $entity;
        }
      }

    }, $view->result, $vbo_entities);

  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $objects) {

    // Build the Referenced entities array.
    // have to make sure the view has vbo entities selected.
    $this->setReferencedEntities();

    // In case of select all views,.
    return parent::executeMultiple($objects);
  }

  /**
   * Builds the selector form.
   *
   * Given an entity form, create a selector form to provide options to update
   * values.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   * @param array $form
   *   The form we're building the selection options for.
   *
   * @return array
   *   The new selector form.
   */
  protected function getSelectorForm($entity_type_id, $bundle, array &$form) {
    $form['#attached']['library'][] = 'views_bulk_reference_edit/views_bulk_reference_edit';

    $selector['_field_selector'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Select fields to change'),
      '#weight' => -50,
      '#tree' => TRUE,
      '#attributes' => ['class' => ['vbe-selector-fieldset']],
    ];

    $key = $entity_type_id . "_" . $bundle;
    $whitelist_fields = $this->context["preconfiguration"][$key]['whitelist'];
    $form_fields = Element::children($form);
    $fields = array_values(array_intersect($form_fields, $whitelist_fields));

    foreach ($form_fields as $key) {

      if (isset($form[$key]['#access']) && !$form[$key]['#access']) {
        continue;
      }
      if ($key == '_field_selector' || !$element = &$this->findFormElement($form[$key])) {
        continue;
      }

      // Modify the referenced element a bit so it doesn't
      // cause errors and returns correct data structure.
      $element['#required'] = FALSE;
      $element['#tree'] = TRUE;

      // Add the toggle field to the form.
      $selector['_field_selector'][$key] = [
        '#type' => 'checkbox',
        '#title' => $element['#title'],
        '#weight' => isset($form[$key]['#weight']) ? $form[$key]['#weight'] : 0,
        '#tree' => TRUE,
      ];

      // @todo remove the checkbox and the respective form field
      // workarround - check in execute for whitelisted field
      if (!in_array($key, $fields)) {
        $selector['_field_selector'][$key]['#wrapper_attributes'] = ['class' => ['vbo-ref-hidden']];
      }
      // Force the original value to be hidden unless the checkbox is enabled.
      $form[$key]['#states'] = [
        'visible' => [
          sprintf('[name="%s[%s][_field_selector][%s]"]', $entity_type_id, $bundle, $key) => ['checked' => TRUE],
        ],
      ];
    }

    if (empty(Element::children($selector['_field_selector']))) {
      $selector['_field_selector']['#title'] = $this->t('There are no fields available to modify');
    }

    return $selector;
  }

}
