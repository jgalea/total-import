=== Total Import ===
Contributors: wpmayor, jeangalea
Tags: import, csv, xml, excel, json
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import CSV, Excel, XML and JSON into posts, pages, custom post types, terms, users and WooCommerce products. Re-run to update, dry run, undo.

== Description ==

Total Import imports spreadsheets and feeds into WordPress, and keeps them in sync when you run the import again. There are no limits on rows, imports or runs.

= Sources and formats =

* CSV and TSV with delimiter and encoding detection, Excel .xlsx (pick the sheet), XML (pick the repeating element) and JSON. Gzip and zip files are unpacked.
* Upload a file, download one from a URL (including Google Sheets links), or read a file already on the server.
* Files are read as a stream and imported in batches, so large files don't run out of memory, and an interrupted run resumes where it stopped.

= Mapping =

* A six-step wizard with a preview of the first records and a live preview of every mapped field.
* Map columns to the title, content, excerpt, slug, status, date, author, parent, menu order, comment and ping status and any custom field, with templates such as `{title} - {sku}`. Click or drag a column into a field.
* Categories, tags and custom taxonomies, several terms per cell and paths such as `Sports > Football`. Missing terms can be created.
* Featured image and gallery from a URL, a Media Library file name or an attachment ID, with alt text, title and caption. Images imported before are reused instead of downloaded again.
* Taxonomy terms: name, slug, description, parent and term meta.
* Users: profile fields, role and user meta. Passwords are never imported; new users get a random one.
* WooCommerce simple products: SKU, regular and sale price, stock, backorders, weight and dimensions, categories, tags, attributes, featured image and gallery. Price and stock updates skip the full product save when nothing else changed.
* Yoast SEO and Rank Math title and description.

= Updates, dry runs and undo =

* Records are matched on a unique key you choose. Running the import again updates what it created, skips unchanged records, and changes only the fields you allow.
* Existing content can be adopted on the first run by ID, slug, title, SKU, email or a custom field.
* A dry run shows what would be created, updated or skipped, field by field, without writing anything.
* History lists every run with its counts and error log (also as CSV). A run can be undone: changed values are put back, created posts go to the trash, and anything edited since the run is left alone.
* Runs can continue in the background, through Action Scheduler when another plugin such as WooCommerce has loaded it, or through WP-Cron.

= Moving from WP All Import =

Total Import reads the imports WP All Import has on the same site, or a template or WP All Export bundle file, and converts them. A report shows which settings were mapped, approximated or left out before anything is saved.

Total Import isn't affiliated with or endorsed by WP All Import or Soflyy. WP All Import and WP All Export are their trademarks.

= WP-CLI =

`wp importright create`, `run`, `runs`, `log`, `rollback` and `migrate`, for scripts and server cron jobs.

== Frequently Asked Questions ==

= How do I move my imports from WP All Import? =

Go to Total Import > Migrate from WP All Import. If WP All Import is on the same site, its imports are listed: click Preview migration to see the report, then Migrate. Total Import also copies WP All Import's list of the posts each import created, so the first run updates those posts instead of making duplicates. From another site, export the template (WP All Import > Settings > Templates) or a WP All Export bundle and upload it with the data file. WP All Import and its data aren't changed, and you can keep both plugins active while you compare.

= Why can't my user import give users the Editor role? =

Editors can post unfiltered HTML, so Total Import treats Editor like Administrator and other roles that can manage users, plugins or settings. In the import's Target step, tick "Allow roles that can manage the site". The person running the import also needs to be allowed to promote users. Existing users with such a role are never changed by an import that doesn't allow it.

= Who can run imports? =

Administrators: running imports needs the `manage_options` capability by default. The `importright_capability` filter changes that capability. Each run writes as the user who started it, whoever continues it later, and WordPress's own checks still apply to that user: creating, editing and publishing each post type, assigning other authors, editing terms, and protected custom fields (names starting with `_`). A post the user can't publish is saved as pending. From WP-CLI, pass `--user`.

= Can I import images or files from my local network? =

No. Source and image URLs must resolve to public addresses. The `importright_allow_private_urls` filter (default false, receives the URL) lets a private host through. It's meant for local testing only; don't use it on a live site.

= What does undo restore, and what doesn't it? =

Undoing a run puts back the fields, custom fields, terms and featured images it changed, moves posts and products it created to the trash, and deletes the terms, users and images it added when nothing else uses them. Anything edited after the run, by hand or by another import, is skipped and listed so you can check it. It doesn't remove revisions WordPress saved during the run, restore data other plugins wrote when the posts were saved, or bring back a run whose undo data has been cleaned up. By default, undo data is kept for the last 5 runs of each import and for 30 days; change that in Total Import > Settings.

= How are existing images matched? =

An image URL is reused when an earlier import downloaded the same URL, or when the Media Library already has a file with the same name. Turn off file name matching in the import's matching step if different images in your feed share a name such as `image.jpg`.

= How do background runs work? =

Total Import doesn't bundle a job queue. When another plugin such as WooCommerce has loaded Action Scheduler, background runs go through it, otherwise through WP-Cron. WP-Cron only runs when the site gets visits, so for big imports [hook WP-Cron into the system task scheduler](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/). A watchdog checks every five minutes for a stalled run and restarts it on whichever of the two is available.

= What happens to my data when I delete the plugin? =

Imports, run history and undo data are kept, so reinstalling picks up where you left off. To remove them, tick "Delete all Total Import data" in Total Import > Settings before deleting the plugin. Imported posts, terms, users and products are never deleted.

== External services ==

Total Import doesn't contact any service of its own and sends no usage data.

It makes a request only to a URL you enter: a data file to import (including a published Google Sheets link) or an image or file named in your data. It sends a plain GET request identifying the plugin by name and version, plus the username and password if you add them to a URL source. The response is saved to your site and nothing from your site is sent. Where the file lives is your choice, so the terms and privacy policy of that host apply; for Google Sheets they're Google's [terms](https://policies.google.com/terms) and [privacy policy](https://policies.google.com/privacy).

== Screenshots ==

1. The imports list with each import's last run.
2. The mapping step: columns to insert and a live preview of every field.
3. A dry run with the changes it would make, field by field.
4. Run history with counts, status and undo state.
5. Undoing a run: what will be restored, trashed or skipped, before anything changes.
6. Migrating from WP All Import, with the report of mapped and approximated settings.
7. Settings and the server status check.

== Changelog ==

= 0.2.2 =
* Undoing a run that changed a post's status now recounts its terms, so category and tag counts stay right.
* New importright_migration_converted filter lets add-ons adjust a WP All Import conversion and its report. See docs/hooks.md.

= 0.2.1 =
* Rolling back an update run now restores the record map exactly, including when each record was last imported.
* docs/hooks.md lists every class other plugins can call directly.

= 0.2.0 =
* Developer hooks for extra run phases, per-batch processing, run summaries, import definition extensions, extra source types, extra post object types in rollbacks and WP All Import product types. The reference is in docs/hooks.md.
* Admin hooks for wizard fields, Imports list columns, settings sections and extra count tiles, plus wp.hooks filters in the wizard script.
* Run history shows WP-CLI as the origin of runs started with wp importright run --background.
* The record map notes whether each record was created by the import or matched to existing content, and rollbacks restore it.
* A debug notice when a target or field handler filter is added too late to take effect.

= 0.1.0 =
* First release.
