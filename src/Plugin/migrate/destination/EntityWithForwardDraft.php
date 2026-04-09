<?php

namespace Drupal\migrate_forward_draft\Plugin\migrate\destination;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\migrate\Attribute\MigrateDestination;
use Drupal\migrate\Plugin\migrate\destination\EntityContentBase;
use Drupal\migrate\Row;

/**
 * Provides a destination plugin that preserves forward draft revisions.
 *
 * Extends the standard entity content destination to detect when an entity has
 * a forward draft revision (a newer non-default draft ahead of the published
 * default) before the migration saves its update. After the migration saves
 * the new default revision, the plugin replays the forward draft as a new
 * non-default revision. Migration-owned values from the published revision can
 * be merged onto that draft using `forward_revision_overwrite_properties`.
 *
 * Supported and tested in kernel tests: nodes with content_moderation. Other
 * revisionable fieldable entity types use the same storage API
 * (`getLatestRevisionId()`, `loadRevision()`); paragraphs and nested
 * entity_reference_revisions targets are not validated here and may need
 * site-specific handling (see issue queue).
 *
 * Configuration keys (in addition to those inherited from EntityContentBase):
 * - forward_revision_overwrite_properties: (optional) List of field/property
 *   names to copy from the new default revision onto the replayed forward
 *   draft. If omitted or empty, the draft is still replayed so editor-owned
 *   values are preserved, but nothing is copied from the published revision
 *   for those keys.
 * - migration_sync: (optional, default TRUE) When TRUE, the main migration
 *   save uses setSyncing(TRUE) like EntityContentBase. When FALSE, the main
 *   save does not set syncing, which changes how content_moderation and other
 *   subscribers behave (useful to approximate non-migrate saves). The
 *   forward-draft replay save never uses syncing.
 *
 * @code
 * destination:
 *   plugin: 'entity_with_forward_draft:node'
 *   default_bundle: my_type
 *   migration_sync: true
 *   overwrite_properties:
 *     - title
 *     - field_migration_field
 *     - new_revision
 *     - revision_default
 *     - revision_log
 *   forward_revision_overwrite_properties:
 *     - title
 *     - field_migration_field
 * @endcode
 */
#[MigrateDestination(
  id: 'entity_with_forward_draft',
  deriver: 'Drupal\migrate_forward_draft\Plugin\Derivative\MigrateEntityWithForwardDraft'
)]
class EntityWithForwardDraft extends EntityContentBase {

  /**
   * The forward draft entity captured before the migration save.
   */
  protected ?ContentEntityInterface $forwardDraft = NULL;

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $this->forwardDraft = $this->captureForwardDraft($old_destination_id_values);

    $ids = parent::import($row, $old_destination_id_values);

    if ($this->forwardDraft !== NULL && !empty($ids)) {
      $overwrite = $this->configuration['forward_revision_overwrite_properties'] ?? [];
      $overwrite = is_array($overwrite) ? $overwrite : [];
      $this->replayForwardDraft(reset($ids), $overwrite);
    }

    return $ids;
  }

  /**
   * {@inheritdoc}
   *
   * For updates, forces a new default revision before save. Uses
   * migration_sync (default TRUE) to decide whether setSyncing(TRUE) runs,
   * matching EntityContentBase when TRUE.
   */
  protected function save(ContentEntityInterface $entity, array $old_destination_id_values = []) {
    if (!empty($old_destination_id_values)) {
      $entity->setNewRevision(TRUE);
      $entity->isDefaultRevision(TRUE);
    }
    if ($this->configuration['migration_sync'] ?? TRUE) {
      $entity->setSyncing(TRUE);
    }
    $entity->save();
    return [$entity->id()];
  }

  /**
   * Captures the current forward draft revision if one exists.
   *
   * A forward draft is a non-default revision that is newer (higher revision
   * ID) than the current default revision.
   *
   * @param array $old_destination_id_values
   *   The old destination IDs from the migration ID map.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The forward draft entity, or NULL if none exists.
   */
  protected function captureForwardDraft(array $old_destination_id_values): ?ContentEntityInterface {
    if (empty($old_destination_id_values)) {
      return NULL;
    }

    $entity_id = reset($old_destination_id_values);
    $entity = $this->storage->load($entity_id);
    if ($entity === NULL) {
      return NULL;
    }

    $default_revision_id = $entity->getRevisionId();
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = $this->storage;
    $latest_revision_id = $storage->getLatestRevisionId($entity_id);

    if ($latest_revision_id === NULL || $latest_revision_id <= $default_revision_id) {
      return NULL;
    }

    $latest_revision = $storage->loadRevision($latest_revision_id);
    if ($latest_revision === NULL || $latest_revision->isDefaultRevision()) {
      return NULL;
    }

    return $latest_revision;
  }

  /**
   * Replays the captured forward draft as a new non-default revision.
   *
   * Does not call setSyncing() so content moderation can enforce non-default
   * state. Applies $overwrite_properties from the published revision when
   * listed.
   *
   * @param int|string $entity_id
   *   The entity ID of the newly saved default revision.
   * @param array $overwrite_properties
   *   Destination field/property names to copy from the default revision.
   */
  protected function replayForwardDraft(int|string $entity_id, array $overwrite_properties): void {
    $published = $this->storage->load($entity_id);
    if ($published === NULL || $this->forwardDraft === NULL) {
      return;
    }

    $draft = clone $this->forwardDraft;
    $draft->setNewRevision(TRUE);
    $draft->enforceIsNew(FALSE);
    $draft->isDefaultRevision(FALSE);

    foreach ($overwrite_properties as $field_name) {
      if ($published->hasField($field_name) && $draft->hasField($field_name)) {
        $draft->set($field_name, $published->get($field_name)->getValue());
      }
    }

    $draft->setRevisionLogMessage('Draft revision carried forward after migration rerun.');
    $draft->save();
  }

}
