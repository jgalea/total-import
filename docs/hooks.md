# Total Import hooks and public classes

Everything here is a stable surface: a hook or class listed below keeps its name and signature within a minor version, and changes only with a note in the changelog. Every hook's default leaves Total Import behaving as it does with nothing attached.

Register `importright_targets` and `importright_field_handlers` callbacks on `plugins_loaded` or earlier. Total Import builds both lists once per request on first use, and a callback added after that has no effect; with `WP_DEBUG` on, it triggers `_doing_it_wrong()`.

## Runs

| Hook | Kind and arguments | Default | Since |
|---|---|---|---|
| `importright_run_created` | action `( int $run_id, int $import_id )` | | 0.1.0 |
| `importright_run_finished` | action `( array $run )`, after a run completes, fails or is cancelled | | 0.1.0 |
| `importright_run_phases` | filter `( string[] $phases, array $run ): string[]`, once, when the reader is exhausted | `[]`: the run completes | 0.2.0 |
| `importright_run_phase_{$phase}` | filter `( ?array $result, array $run, array $phase_state ): ?array` | `null`: the phase is skipped with a run notice | 0.2.0 |
| `importright_batch_processed` | action `( array $run, array $keys, array $result, Session $session )`, inside the batch transaction | | 0.2.0 |
| `importright_run_description` | filter `( array $description, array $run ): array` | unchanged | 0.2.0 |
| `importright_batch_transaction` | filter `( bool $use, array $run )`, wraps each batch and each phase step in a transaction | `true` | 0.1.0 |
| `importright_lock_ttl` | filter `( int $seconds )` | `600` | 0.1.0 |
| `importright_background_time_limit` | filter `( float $seconds, int $run_id )` per background step | `20.0` | 0.1.0 |
| `importright_ajax_time_limit` | filter `( float $seconds )` per admin request | `10.0` | 0.1.0 |
| `importright_watchdog_grace` | filter `( int $seconds )` without a heartbeat before a background run counts as stalled | `120` | 0.1.0 |
| `importright_preview_rows` | filter `( int $rows, array $run )` kept by a dry run | `Session::PREVIEW_ROWS` | 0.1.0 |
| `importright_log_limit` | filter `( int $rows, int $run_id )` | `Log::DEFAULT_LIMIT` | 0.1.0 |

### Phases

A phase runs after the last record, under the run's lock, inside the same claim, user switch, transaction, resume and watchdog machinery as a batch. Dry runs run phases too; check `$run['dry_run']`.

The phase filter returns `{ done: bool, state: array, counts: array<string,int>, errors: list, previews: list }`. `state` comes back as `$phase_state` on the next step. `counts` are added to `$run['state']['phase_counts'][ $phase ]`, which stays on the run after it completes. `errors` and `previews` use the run log's entry shape `{ index, key, level, message }`. A throwing phase rolls back its step and fails the run.

### `importright_batch_processed` keys

`$keys` maps each non-empty unique key in the batch to its action: `create`, `update`, `unchanged`, `skip` or `fail` (dry runs report what would happen). PHP stores numeric-string array keys as integers, so a unique key `"123"` arrives as `123`. Cast keys to string before hashing or comparing them.

### `importright_run_description`

Add `extra_counts`, a list of `{ key, label, value }`, and the run panel and run screen show each as a tile after the standard counts.

## Imports and sources

| Hook | Kind and arguments | Default | Since |
|---|---|---|---|
| `importright_import_saved` | action `( int $id, array $definition )` on create and update | | 0.1.0 |
| `importright_import_deleted` | action `( int $id )` | | 0.1.0 |
| `importright_definition_extensions` | filter `( array $normalized, array $input_extensions, array $definition ): array\|WP_Error`; a `WP_Error` blocks the save | `[]` | 0.2.0 |
| `importright_source_types` | filter `( array<string,string> $types )` type => label | upload, url, path | 0.2.0 |
| `importright_normalize_source` | filter `( array\|WP_Error\|null $normalized, array $source, ?array $stored )`; `$stored` is the saved source of the same type, so a secret left blank can be kept | `null`: "no valid source" error | 0.2.0 |
| `importright_fetch_source` | filter `( array\|WP_Error\|null $fetched, array $source, string $dest, int $run_id )`; write the file to `$dest` and return `{ name }`. `$run_id` is `0` when the wizard inspects a sample | `null`: "no valid source" error | 0.2.0 |
| `importright_mask_source` | filter `( array $source ): array`, before a source reaches the browser or an exported template | unchanged | 0.2.0 |

When an import is edited while the plugin that owns an `extensions.<key>` entry is inactive, the stored entry is kept.

## Targets, fields and rollback

| Hook | Kind and arguments | Default | Since |
|---|---|---|---|
| `importright_targets` | filter `( array<string,Target> $targets )` | post, term, user, product with WooCommerce | 0.1.0 |
| `importright_field_handlers` | filter `( array<string,FieldHandler> $handlers )` | meta, taxonomies, images, seo, attributes with WooCommerce | 0.1.0 |
| `importright_post_object_types` | filter `( string[] $types )` journaled and rolled back as posts | post, product, attachment | 0.2.0 |
| `importright_rollback_remove` | filter `( ?string $error, string $type, int $id, array $current )`, for any type outside post, product, attachment, term and user; return `''` when removed, a message when it failed | `null`: built-in handling | 0.2.0 |
| `importright_keep_rollback` | filter `( bool $keep, int $import_id )` | `true` | 0.1.0 |
| `importright_snapshot_skip_meta` | filter `( string[] $keys, string $type )` | | 0.1.0 |
| `importright_rollback_keep_runs`, `importright_rollback_keep_days`, `importright_log_keep_days` | filters `( int )` | settings, `90` days of logs | 0.1.0 |
| `importright_post_statuses` | filter `( string[] $statuses, string $post_type )` | draft, pending, publish, future, private | 0.1.0 |
| `importright_migration_convert` | filter `( array $payload, array $options, Report $report )`, payload `{ handled, type, target, mapping }` for a WP All Import product type other than simple; set `handled` and return the new `target` and `mapping` | not handled: reported as unsupported | 0.2.0 |
| `importright_migration_converted` | filter `( array $converted, array $env, Report $report ): array`, after Total Import converts a WP All Import template or import. `$converted` is `{ definition, tracking, report }`; `$env` is what the converter was given: `product_target`, `feed`, `xpath`, `root_element`, `columns` (the data file's column names, or `null`), `sample`. Change the definition, or call `$report->replace()` and `$report->remove()` to swap or drop lines by WP All Import key. A return value that isn't the same shape is ignored. Not run for import types that can't be converted | unchanged | 0.2.2 |

## Limits and fetching

| Hook | Kind | Default | Since |
|---|---|---|---|
| `importright_allow_private_urls` | filter `( bool $allow, string $url )` | `false` | 0.1.0 |
| `importright_max_download_bytes`, `importright_download_timeout`, `importright_can_pin_connections` | filters | | 0.1.0 |
| `importright_server_path_roots` | filter `( string[] $roots )` | | 0.1.0 |
| `importright_max_unpacked_bytes`, `importright_max_json_bytes`, `importright_max_record_bytes`, `importright_max_image_bytes`, `importright_max_template_bytes` | filters `( int $bytes )` | | 0.1.0 |
| `importright_capability` | filter `( string $capability )` | `manage_options` | 0.1.0 |

## Admin

| Hook | Kind and arguments | Default | Since |
|---|---|---|---|
| `importright_admin_enqueue` | action `( string $screen )` after Total Import's assets are enqueued; its script depends on `wp-hooks` | | 0.2.0 |
| `importright_wizard_data` | filter `( array $data )` passed to the wizard script | unchanged | 0.2.0 |
| `importright_wizard_source_fields`, `importright_wizard_target_fields`, `importright_wizard_matching_fields` | actions printing `<tr>` rows at the end of that step's table | | 0.2.0 |
| `importright_wizard_run_fields` | action printing a block in the run step | | 0.2.0 |
| `importright_pro_hints` | filter `( bool $show )` for the two hint sentences in the wizard | `true` | 0.2.0 |
| `importright_imports_columns` | filter `( array $columns )` on the Imports list | unchanged | 0.2.0 |
| `importright_imports_column_{$name}` | filter `( string $html, array $item )`, passed through `wp_kses_post()` | `''` | 0.2.0 |
| `importright_settings_sections` | action, after the Uninstall section on the settings screen | | 0.2.0 |

Any registered target without a built-in radio gets one in the wizard's target step, labelled with `Target::label()`.

### JavaScript (`wp.hooks`)

| Hook | Kind and arguments | Default |
|---|---|---|
| `importright.definition` | filter `( definition )` before save; write your settings to `definition.extensions.<key>` | unchanged |
| `importright.fill` | action `( saved )` after the wizard loads a saved import | |
| `importright.targetSettings` | filter `( settings, targetType )` | built-in settings |
| `importright.mappingGroups` | filter `( groups, { target, mapping, helpers } )`, helpers `{ el, group, fieldRow, templateField, repeater }` | unchanged |
| `importright.collectMapping` | filter `( mapping )` | unchanged |
| `importright.keepMappingKeys` | filter `( keys )` of mapping sections kept when the target changes | meta, taxonomies, images, seo |

All JavaScript hooks are since 0.2.0.

## Public classes

These classes and methods can be called directly. Guard on `IMPORTRIGHT_VERSION` (0.2.0 or later; the rows from `Imports::get()` down are listed from 0.2.1, `Report` from 0.2.2) before using them.

| Class | Use |
|---|---|
| `Importright\Plugin::capability()` | the capability Total Import screens and runs require |
| `Importright\Runs\Background::start( int $import_id, array $args )` | start a background run; `$args` `{ origin, dry_run, batch_size }`. The origin is kept for display; every run started here is watched by the watchdog |
| `Importright\Runs\Background::backend()` | `action-scheduler` or `wp-cron`, whichever runs background steps right now |
| `Importright\Runs\Actor::become( int $owner )` | switch to the import owner the way runs do |
| `Importright\Runs\Tracker::storage_key( string $key )` | the record map's stored form of a unique key |
| `Importright\Runs\Journal` | `before_update()`, `created()`, `flush()`: record changes so a rollback undoes them |
| `Importright\Runs\Log::add( int $run_id, array $entries )` | write run log entries |
| `Importright\Runs\Session` | the batch session passed to `importright_batch_processed` |
| `Importright\Target\Target`, `Importright\Target\Previewable` | interfaces for targets |
| `Importright\Field\FieldHandler`, `Importright\Field\Previewable` | interfaces for field handlers |
| `Importright\Target\PriceStock` | WooCommerce price and stock rules used by the fast update path |
| `Importright\Field\TaxonomiesHandler::resolve()` | find or create a term from a path |
| `Importright\Media\Library` | find or sideload an image the way imports do |
| `Importright\Mapping\Mapper` | render a mapping's templates against a record |
| `Importright\Imports\Imports::get( int $id )` | an import and its normalized definition |
| `Importright\Runs\Runs::active()`, `Runs::rolling_back()` | whether an import has a run in progress or a rollback under way |
| `Importright\Runs\Context` | passed to targets and handlers; `run_id`, `import_id`, `dry_run`, `settings`, `notice()`, `added()` |
| `Importright\Runs\Diff` | the per-record change list a target fills in |
| `Importright\Target\Permissions::post()`, `::status()` | the capability checks imports apply to a post and to the status a record may set |
| `Importright\Target\RoleGuard::PRIVILEGED_CAPS` | the capabilities that mark a user as privileged; imports never change those users |
| `Importright\Field\MetaHandler::may_write()`, `::is_protected_user_meta()` | whether an import may write a meta key, and the user meta keys it never writes |
| `Importright\Field\OwnsMeta` | interface for a target or handler that writes meta keys the custom-field mapping must leave alone |
| `Importright\Migration\Report::replace()`, `::remove()` | swap the lines for a WP All Import key for one new line (added at the end if there was none), or drop them; used from `importright_migration_converted` |
| `Importright\Registry::handlers()` | the registered field handlers |
| `Importright\Database\Schema::records()`, `::changes()` | the record map and change journal table names |
| `Importright\Admin\Screens::url()`, `::import_id()`, `Screens::EDIT`, `Screens::HISTORY` | admin screen links and the import being edited |
| `Importright\Admin\SettingsScreen::ACTION` | the settings form's action, for extra sections that save with it |
