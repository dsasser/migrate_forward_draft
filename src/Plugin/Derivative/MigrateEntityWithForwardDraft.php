<?php

namespace Drupal\migrate_forward_draft\Plugin\Derivative;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deriver for entity_with_forward_draft destination plugins.
 *
 * Generates a plugin instance for every revisionable, fieldable entity type,
 * mirroring core's MigrateEntityRevision deriver pattern.
 */
class MigrateEntityWithForwardDraft implements ContainerDeriverInterface {

  /**
   * Derivative definitions.
   *
   * @var array
   */
  protected array $derivatives = [];

  /**
   * Entity type definitions.
   *
   * @var EntityTypeInterface[]
   */
  protected array $entityDefinitions;

  /**
   * Constructs a MigrateEntityWithForwardDraft deriver.
   *
   * @param EntityTypeInterface[] $entity_definitions
   *   Entity type plugin definitions from the entity type manager.
   */
  public function __construct(array $entity_definitions) {
    $this->entityDefinitions = $entity_definitions;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): ContainerDeriverInterface|MigrateEntityWithForwardDraft|static {
    return new static(
      $container->get('entity_type.manager')->getDefinitions()
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinition($derivative_id, $base_plugin_definition) {
    if (!empty($this->derivatives) && !empty($this->derivatives[$derivative_id])) {
      return $this->derivatives[$derivative_id];
    }
    $this->getDerivativeDefinitions($base_plugin_definition);
    return $this->derivatives[$derivative_id] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    foreach ($this->entityDefinitions as $entity_type_id => $entity_info) {
      if (self::isSupportedEntityType($entity_info)) {
        $this->derivatives[$entity_type_id] = [
          'id' => "entity_with_forward_draft:$entity_type_id",
          'class' => $base_plugin_definition['class'],
          'requirements_met' => 1,
          'provider' => $entity_info->getProvider(),
        ] + $base_plugin_definition;
      }
    }
    return $this->derivatives;
  }

  /**
   * Whether the entity type can use the forward-draft destination.
   *
   * Revision support is determined with
   * \Drupal\Core\Entity\EntityTypeInterface::isRevisionable(), not by treating
   * the revision key name as a boolean.
   */
  protected static function isSupportedEntityType(EntityTypeInterface $entity_type): bool {
    return $entity_type->isRevisionable()
      && $entity_type->entityClassImplements(FieldableEntityInterface::class);
  }

}
