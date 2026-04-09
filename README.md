# Migrate Forward Draft

Drupal migration destination plugin that preserves **forward draft** revisions
when a migration reruns: after updating the published (default) revision, the
plugin replays the pre-existing forward draft as a new non-default revision.

## Why this matters (especially with content moderation)

With **content moderation**, editors often work on a **forward draft**: a newer
revision that is *not* the default, ahead of the published revision. Example:
the live page stays “Published” while someone saves a “Draft” that contains
notes, layout, or fields the migration does not own.

**Migrations** usually update the **default** (published) revision—title, IDs,
flags, or other system-of-record fields. The core `entity:node` destination
applies your row to that revision. On a **rerun**, that is correct for the live
content, but it does nothing to reconcile the editor’s forward draft with the
newly imported values. Editors can effectively **lose the combination** they
needed: up-to-date migration fields on the published revision, plus their draft
work, with migration-owned fields on the draft staying in sync when *you* want
that.

**Migrate** also saves entities with **`setSyncing(TRUE)`** by default
(`EntityContentBase`). Content moderation and other subscribers treat syncing
saves differently from normal form saves (for example, whether a *new*
revision is created or the default is updated in place). Custom code that keys
off syncing during migrate can make forward-draft behavior harder to reason
about. This plugin’s optional **`migration_sync`** setting lets you keep the
default migrate behavior or turn syncing off for the **main** import save; the
forward-draft **replay** is always saved **without** syncing so moderation can
apply its usual rules for a non-default revision.

### Event subscribers that force revisions during migrate

Some codebases add an `entity_presave` (or similar) subscriber that detects
**`$entity->isSyncing()`** during a migration and then calls
**`setNewRevision(TRUE)`** and **`isDefaultRevision(TRUE)`** so every migrate
update becomes a **new default** revision. That pattern exists because migrate
and moderation do not always combine cleanly for new revisions; see
[drupal.org issue #3052115](https://www.drupal.org/project/drupal/issues/3052115).

That approach is useful for audit trails on the live revision, but it interacts
badly with **carry-forward** or replay logic that saves a **non-default** draft
while **`setSyncing(TRUE)`** is still true: the same subscriber can flip the
draft to **default**, collapsing editorial state. Fixes usually include **not**
syncing on that save (treat it as editorial), or narrowing the subscriber so
it does not run when the entity is already non-default. This plugin’s replay
step never uses syncing for that reason. Use **`migration_sync`** on the main
import save to align with whether your site relies on that subscriber during
migrate.

This destination captures any forward draft **before** the migration updates
the default revision, then writes a **new** non-default revision that carries
forward editor-owned values and overlays only the properties listed in
**`forward_revision_overwrite_properties`** from the new default.

### Use case: forms (or catalogue) migrations

A common pattern is a **forms** (or **catalogue**) migration: an external
database, API, or file feed is the system of record for **metadata**—form
number, display title, download URL, revision or issue dates, “retired” flags,
and similar. Editors still need to **enrich** the same nodes with fields the
source does not provide: related resources, teasers, help text, local notes, or
workflow-only content.

1. The migration keeps the **published** node aligned with the feed
   (`overwrite_properties` lists those columns).
2. An editor opens the live form, adds or changes editorial fields, and saves a
   **Draft**—creating a **forward draft** ahead of the default revision.
3. The feed changes (title tweak, new PDF, updated date). The migration **reruns**
   and must update the **default** revision without discarding the draft.

Without this plugin, rerun behavior is at best confusing and at worst **loses**
the draft’s editorial work, or leaves the draft carrying **stale** migrated
metadata while the live site is correct. With **`entity_with_forward_draft`**
you list migration-owned properties in **`forward_revision_overwrite_properties`**
so the replayed draft picks up the new title, URL, or dates from the freshly
imported default revision, while **everything else** on the draft stays as the
editor left it.

## Requirements

- Drupal core Migrate (`drupal:migrate`).

Optional **Migrate Forward Draft Demo** (`migrate_forward_draft_demo`) ships in
`modules/migrate_forward_draft_demo/` under this extension (same layout as
core/contrib nested modules). It adds a sample `product` type, workflow
`mfd_demo`, and `embedded_data` migrations in group **demo**. Enable it only if
you want those examples.

## Usage

```yaml
destination:
  plugin: 'entity_with_forward_draft:node'
  default_bundle: my_type
  migration_sync: true
  overwrite_properties:
    - title
    - field_migration_owned
    - new_revision
    - revision_default
    - revision_log
  forward_revision_overwrite_properties:
    - title
    - field_migration_owned
```

- **`forward_revision_overwrite_properties`:** Optional. If empty or omitted, a
  forward draft is still replayed; only the copy step from the published
  revision is skipped (editor-owned values stay as on the captured draft).
- **`migration_sync`:** Optional, default `true`. Matches core’s migrate entity
  save (`setSyncing(TRUE)`). Set to `false` to approximate saves without the
  migrate sync flag (behavior differs with content moderation). Forward-draft
  replay never uses syncing.

