<?php

declare(strict_types=1);

namespace Drupal\Tests\migrate_forward_draft\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\node\Entity\Node;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;

/**
 * Tests that EntityWithForwardDraft preserves forward draft revisions.
 *
 * @coversDefaultClass \Drupal\migrate_forward_draft\Plugin\migrate\destination\EntityWithForwardDraft
 *
 * @group migrate_forward_draft
 */
class EntityWithForwardDraftTest extends MigrateTestBase {

  use ContentModerationTestTrait;
  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'workflows',
    'content_moderation',
    'migrate',
    'migrate_forward_draft',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'user']);

    // Create the product content type.
    $this->createContentType(['type' => 'product', 'name' => 'Product']);

    // Add field_product_sku (string) - migration-owned.
    FieldStorageConfig::create([
      'field_name' => 'field_product_sku',
      'entity_type' => 'node',
      'type' => 'string',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_product_sku',
      'entity_type' => 'node',
      'bundle' => 'product',
      'label' => 'Product SKU',
    ])->save();

    // Add field_product_notes (text_long) - editor-owned.
    FieldStorageConfig::create([
      'field_name' => 'field_product_notes',
      'entity_type' => 'node',
      'type' => 'text_long',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_product_notes',
      'entity_type' => 'node',
      'bundle' => 'product',
      'label' => 'Product Notes',
    ])->save();

    // Set up editorial workflow for the product type.
    $workflow = $this->createEditorialWorkflow();
    $workflow->getTypePlugin()->addEntityTypeAndBundle('node', 'product');
    $workflow->save();
  }

  /**
   * Creates a migration instance with the given source data rows.
   *
   * Uses migration YAML files from the module's migrations/ directory and
   * overrides the embedded_data source rows at runtime. This mirrors the
   * pattern used in va.gov-cms's Migrator helper.
   *
   * @param string $migration_id
   *   A migration plugin ID from the module's migrations/ directory.
   * @param array $data_rows
   *   The source data rows to inject into the embedded_data source.
   *
   * @return \Drupal\migrate\Plugin\MigrationInterface
   *   The configured migration instance.
   */
  protected function getMigrationWithRows(string $migration_id, array $data_rows): MigrationInterface {
    /** @var \Drupal\migrate\Plugin\MigrationInterface $migration */
    $migration = \Drupal::service('plugin.manager.migration')
      ->createInstance($migration_id);
    $source_config = $migration->getSourceConfiguration();
    $source_config['data_rows'] = $data_rows;
    $migration->set('source', $source_config);
    $migration->setStatus(0);
    $migration->getIdMap()->prepareUpdate();
    return $migration;
  }

  /**
   * Runs a migration with the given source data rows.
   *
   * @param string $migration_id
   *   The migration plugin ID.
   * @param array $data_rows
   *   The source data rows.
   */
  protected function runMigration(string $migration_id, array $data_rows): void {
    $migration = $this->getMigrationWithRows($migration_id, $data_rows);
    (new MigrateExecutable($migration, new MigrateMessage()))->import();
  }

  /**
   * Creates a forward draft revision on a node.
   *
   * @param int $nid
   *   The node ID.
   * @param string $notes_value
   *   The value to set on field_product_notes.
   */
  protected function createForwardDraft(int $nid, string $notes_value): void {
    $node = Node::load($nid);
    $this->assertNotNull($node, "Node $nid must exist before creating a forward draft.");

    $draft = clone $node;
    $draft->setNewRevision(TRUE);
    $draft->isDefaultRevision(FALSE);
    $draft->set('moderation_state', 'draft');
    $draft->set('field_product_notes', $notes_value);
    $draft->setRevisionLogMessage('Editor note added.');
    $draft->save();
  }

  /**
   * The entity:node destination may drop forward-draft editor notes on rerun.
   *
   * Environment-dependent.
   *
   * @covers ::import
   *
   * @group migrate_forward_draft_bug
   */
  public function testForwardDraftIsLostWithStandardDestination(): void {
    $initial_rows = [['id' => 1, 'title' => 'Product One', 'sku' => 'ABC-001']];

    // Initial migration: creates a published product node.
    $this->runMigration('test_product_standard', $initial_rows);

    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['type' => 'product', 'field_product_sku' => 'ABC-001']);
    $this->assertCount(1, $nodes, 'One product node should exist after initial migration.');
    $node = reset($nodes);
    $nid = (int) $node->id();

    // Editor creates a forward draft with custom notes.
    $this->createForwardDraft($nid, 'Important editor note');

    // Verify the forward draft exists before rerun.
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $latest_vid = (int) key(
      $storage->getQuery()
        ->allRevisions()
        ->condition('nid', $nid)
        ->sort('vid', 'DESC')
        ->range(0, 1)
        ->accessCheck(FALSE)
        ->execute()
    );
    $default_vid = (int) $storage->load($nid)->getRevisionId();
    $this->assertGreaterThan($default_vid, $latest_vid, 'Forward draft should be ahead of default revision.');

    // Rerun migration with updated SKU.
    $updated_rows = [['id' => 1, 'title' => 'Product One', 'sku' => 'ABC-002']];
    $this->runMigration('test_product_standard', $updated_rows);

    // Assert the forward draft's editor notes survived the rerun.
    // THIS ASSERTION FAILS with entity:node — the forward draft is lost.
    $storage->resetCache();
    $new_latest_vid = (int) key(
      $storage->getQuery()
        ->allRevisions()
        ->condition('nid', $nid)
        ->sort('vid', 'DESC')
        ->range(0, 1)
        ->accessCheck(FALSE)
        ->execute()
    );
    $latest_revision = $storage->loadRevision($new_latest_vid);
    $this->assertEquals(
      'Important editor note',
      $latest_revision->get('field_product_notes')->value,
      'The editor note in the forward draft should survive the migration rerun.'
    );
  }

  /**
   * Forward draft is replayed; migration-owned fields overlay from default.
   *
   * @covers ::import
   */
  public function testForwardDraftIsPreservedWithPlugin(): void {
    $initial_rows = [['id' => 1, 'title' => 'Product One', 'sku' => 'ABC-001']];

    // Initial migration.
    $this->runMigration('test_product_forward', $initial_rows);

    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['type' => 'product', 'field_product_sku' => 'ABC-001']);
    $this->assertCount(1, $nodes, 'One product node should exist after initial migration.');
    $node = reset($nodes);
    $nid = (int) $node->id();

    // Editor creates a forward draft with custom notes.
    $this->createForwardDraft($nid, 'Important editor note');

    // Rerun migration with updated SKU.
    $updated_rows = [['id' => 1, 'title' => 'Product One', 'sku' => 'ABC-002']];
    $this->runMigration('test_product_forward', $updated_rows);

    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();

    // The default (published) revision should have the updated SKU.
    $default_node = $storage->load($nid);
    $this->assertEquals(
      'ABC-002',
      $default_node->get('field_product_sku')->value,
      'The published default revision should have the updated SKU.'
    );

    // The latest revision should be the replayed forward draft.
    $latest_vid = (int) key(
      $storage->getQuery()
        ->allRevisions()
        ->condition('nid', $nid)
        ->sort('vid', 'DESC')
        ->range(0, 1)
        ->accessCheck(FALSE)
        ->execute()
    );
    $default_vid = (int) $default_node->getRevisionId();
    $this->assertGreaterThan($default_vid, $latest_vid, 'A new forward draft should exist ahead of the published revision.');

    $latest_revision = $storage->loadRevision($latest_vid);
    $this->assertFalse(
      $latest_revision->isDefaultRevision(),
      'The latest revision should not be the default revision.'
    );
    $this->assertEquals(
      'Important editor note',
      $latest_revision->get('field_product_notes')->value,
      'The editor note should be preserved in the replayed forward draft.'
    );
    $this->assertEquals(
      'ABC-002',
      $latest_revision->get('field_product_sku')->value,
      'The migration-owned SKU should be updated in the replayed forward draft.'
    );
  }

  /**
   * No replay when there is no forward draft (latest revision stays default).
   *
   * With migration_sync TRUE, content moderation often updates the default
   * revision in place during migrate.
   *
   * @covers ::import
   */
  public function testMigrationWithNoForwardDraftIsUnchanged(): void {
    $initial_rows = [['id' => 1, 'title' => 'Product One', 'sku' => 'ABC-001']];

    // Initial migration.
    $this->runMigration('test_product_forward', $initial_rows);

    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['type' => 'product', 'field_product_sku' => 'ABC-001']);
    $node = reset($nodes);
    $nid = (int) $node->id();

    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('node');

    // Rerun migration with no forward draft present.
    $updated_rows = [['id' => 1, 'title' => 'Product One', 'sku' => 'ABC-002']];
    $this->runMigration('test_product_forward', $updated_rows);

    $storage->resetCache();
    $node_after = $storage->load($nid);

    // The migration update should have been applied to the node.
    $this->assertEquals(
      'ABC-002',
      $node_after->get('field_product_sku')->value,
      'The migration-owned SKU should be updated after rerun.'
    );

    // No phantom draft should have been created: the latest revision should
    // be the default revision (no extra revision created by the plugin).
    $latest_vid = (int) key(
      $storage->getQuery()
        ->allRevisions()
        ->condition('nid', $nid)
        ->sort('vid', 'DESC')
        ->range(0, 1)
        ->accessCheck(FALSE)
        ->execute()
    );
    $this->assertEquals(
      (int) $node_after->getRevisionId(),
      $latest_vid,
      'The latest revision should be the default when no forward draft existed.'
    );

    // Only one product node should exist — no phantom node created.
    $product_count = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'product')
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertEquals(1, $product_count, 'Only one product node should exist.');
  }

  /**
   * First import has no prior map IDs, so captureForwardDraft returns NULL.
   *
   * @covers ::import
   */
  public function testFirstImportCreatesSingleRevision(): void {
    $rows = [['id' => 1, 'title' => 'Product One', 'sku' => 'ABC-001']];
    $this->runMigration('test_product_forward', $rows);

    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['type' => 'product']);
    $this->assertCount(1, $nodes);
    $nid = (int) reset($nodes)->id();

    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $revision_ids = $storage->getQuery()
      ->allRevisions()
      ->condition('nid', $nid)
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(1, $revision_ids, 'First import should create exactly one revision.');
  }

  /**
   * Empty forward overwrite list still replays draft without default overlays.
   *
   * @covers ::import
   */
  public function testForwardDraftReplayedWithEmptyForwardOverwriteProperties(): void {
    $initial_rows = [['id' => 1, 'title' => 'Product One', 'sku' => 'ABC-001']];
    $this->runMigration('test_product_forward_empty_forward', $initial_rows);

    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['type' => 'product', 'field_product_sku' => 'ABC-001']);
    $nid = (int) reset($nodes)->id();
    $this->createForwardDraft($nid, 'Editor only on draft');

    $updated_rows = [['id' => 1, 'title' => 'Product One Updated', 'sku' => 'ABC-002']];
    $this->runMigration('test_product_forward_empty_forward', $updated_rows);

    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();

    $default_node = $storage->load($nid);
    $this->assertEquals('ABC-002', $default_node->get('field_product_sku')->value);
    $this->assertStringContainsString('Updated', (string) $default_node->getTitle());

    $latest_vid = (int) key(
      $storage->getQuery()
        ->allRevisions()
        ->condition('nid', $nid)
        ->sort('vid', 'DESC')
        ->range(0, 1)
        ->accessCheck(FALSE)
        ->execute()
    );
    $latest = $storage->loadRevision($latest_vid);
    $this->assertFalse($latest->isDefaultRevision());
    $this->assertEquals('Editor only on draft', $latest->get('field_product_notes')->value);
    $this->assertEquals(
      'ABC-001',
      $latest->get('field_product_sku')->value,
      'SKU on replayed draft should not be copied from published when overlay list is empty.'
    );
  }

  /**
   * Forward-draft replay works when migration_sync is FALSE on the destination.
   *
   * @covers ::import
   */
  public function testForwardDraftPreservedWhenMigrationSyncIsFalse(): void {
    $initial_rows = [['id' => 1, 'title' => 'Product One', 'sku' => 'ABC-001']];
    $this->runMigration('test_product_forward_no_sync', $initial_rows);

    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['type' => 'product', 'field_product_sku' => 'ABC-001']);
    $nid = (int) reset($nodes)->id();
    $this->createForwardDraft($nid, 'Note with sync off');

    $updated_rows = [['id' => 1, 'title' => 'Product One', 'sku' => 'ABC-002']];
    $this->runMigration('test_product_forward_no_sync', $updated_rows);

    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();

    $default_node = $storage->load($nid);
    $this->assertEquals('ABC-002', $default_node->get('field_product_sku')->value);

    $latest_vid = (int) key(
      $storage->getQuery()
        ->allRevisions()
        ->condition('nid', $nid)
        ->sort('vid', 'DESC')
        ->range(0, 1)
        ->accessCheck(FALSE)
        ->execute()
    );
    $default_vid = (int) $default_node->getRevisionId();
    $this->assertGreaterThan($default_vid, $latest_vid);

    $latest = $storage->loadRevision($latest_vid);
    $this->assertFalse($latest->isDefaultRevision());
    $this->assertEquals('Note with sync off', $latest->get('field_product_notes')->value);
    $this->assertEquals('ABC-002', $latest->get('field_product_sku')->value);
  }

}
