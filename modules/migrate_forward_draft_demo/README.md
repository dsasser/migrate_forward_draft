# Migrate Forward Draft Demo

Optional submodule. Enable it to install:

- A `product` content type with `field_product_sku` and `field_product_notes`.
- Editorial-style workflow `mfd_demo` applied to `product`.
- Migration group **demo** with two sample migrations using `embedded_data`:
  - `demo_product_standard` — `entity:node` destination.
  - `demo_product` — `entity_with_forward_draft:node` destination.

## Usage

1. Enable **Migrate Forward Draft Demo** (and **Migrate Tools** if you use Drush).
2. Import the group:

   ```bash
   drush migrate:import --group=demo
   ```

3. Create a forward draft on a product node, change the embedded rows in the
   migration config or use `migrate:import demo_product --update` after editing
   source data, and compare revision behavior to `demo_product_standard`.

The migrations ship with static `data_rows`; edit the migration configuration
if you want different sample products.
