# Migrate Forward Draft

Drupal migration destination plugin that preserves **forward draft** revisions
when a migration reruns: after updating the published (default) revision, the
plugin replays the pre-existing forward draft as a new non-default revision.

## Requirements

- Drupal core Migrate (`drupal:migrate`).

Optional **Migrate Forward Draft Demo** submodule (`migrate_forward_draft_demo`)
provides a sample `product` content type, a workflow, and `embedded_data`
migrations in group **demo**. Enable it only if you want those examples.

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

Continuous integration for this package uses the [Drupal GitLab templates](https://git.drupalcode.org/project/gitlab_templates)
(see `.gitlab-ci.yml` in this extension directory).
