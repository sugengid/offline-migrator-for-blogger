=== Sugeng Offline Migrator for Blogger ===
Contributors: massugeng
Tags: blogger, migration, import, takeout, redirect
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate Blogger to WordPress offline from Google Takeout. Content, images, comments, and 301 redirects from your backup, no online blog needed.

== Description ==

Sugeng Offline Migrator for Blogger moves a Blogger blog to WordPress using only your Google Takeout backup files. Because everything is read from files, blogs that were deleted or locked can still be migrated.

Unlike importers that require your old blog to stay online, Sugeng Offline Migrator for Blogger works fully offline. You download the Takeout archive once, upload it to the wizard, and the plugin does the rest: posts, pages, threaded comments, drafts, labels, images, and 301 redirects.

Key features:

* Import posts, pages, threaded comments, drafts, and labels from the Takeout feed.atom.
* Restore original Blogger slugs and URLs from the b:filename metadata.
* Two image options that can be combined: import offline from the Takeout Albums folder, and/or download still-live external URLs to the media library; image URLs inside content are rewritten automatically. Leave both unchecked to skip image import.
* Two permalink modes: Mode A keeps the original Blogger URLs, Mode B uses new root URLs (/slug/) with automatic 301 redirects from the old URLs.
* Redirect export: review old-to-new URL mapping and export CSV/JSON compatible with the Redirection plugin, so old URLs keep working even after this plugin is removed.
* Optional conversion of content HTML to Gutenberg blocks during import.
* Chunked AJAX processing, safe for large feeds on shared hosting.
* Jobs can be resumed after a page refresh without duplicating content.
* Accepts both .zip and .tgz Takeout archives.

== Installation ==

1. Upload the `sugeng-offline-migrator-for-blogger` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Open Tools → Sugeng Offline Migrator for Blogger in your dashboard.
4. Upload your Google Takeout archive (zip or tgz), choose the blog and permalink mode, then run the migration.

How to get the Takeout file: open Google Takeout, select the Blogger product, export, then download the archive in zip or tgz format.

== Frequently Asked Questions ==

= Does the Blogger blog need to stay online? =

No. All data is read from the Google Takeout backup files, so deleted or locked blogs can still be migrated.

= What is the difference between Mode A and Mode B? =

Mode A keeps the original Blogger URL structure (/2026/03/slug.html). Mode B uses new root URLs (/slug/) and automatically creates 301 redirects from the old URLs, including the /p/slug.html pattern for static pages.

= How do redirects keep working after the plugin is removed? =

Download the redirect export (CSV/JSON) from the Export redirect screen and import it into the Redirection plugin. While Sugeng Offline Migrator for Blogger stays active, old URLs are redirected automatically without any extra plugin.

= Will existing content be deleted? =

No. The plugin only adds new content and never deletes existing posts, pages, or media. Imports are idempotent: re-running the same migration does not create duplicates.

= What is removed on uninstall? =

Only internal plugin options (job state and image mapping). Imported content stays on your site.

== Screenshots ==

1. Step 1: upload the Google Takeout archive.
2. Step 2: choose the blog and permalink mode.
3. Step 3: run the migration with live progress.
4. Migration report and redirect export.

== Changelog ==

= 0.1.4 =
* Default interface language is now English for international users. Simplified wording for the upload limit explanation and upload button.
* Plugin menu moved from the sidebar to Tools → Sugeng Offline Migrator for Blogger.
* Upload status message now clears correctly after the upload finishes.
* Foreign-language translations (including Indonesian) can now be contributed via translate.wordpress.org.

= 0.1.3 =
* Tested up to WordPress 7.1.

= 0.1.2 =
* Plugin renamed to Sugeng Offline Migrator for Blogger with a distinctive name and updated text domain.

= 0.1.1 =
* Chunked AJAX upload: large Takeout archives are split into small parts and reassembled server-side, so files larger than the host's upload_max_filesize still upload successfully.
* Hourly cleanup of abandoned upload sessions.

= 0.1.0 =
* First release: feed.atom parser, content and comment importer, offline image migration from albums, two permalink modes with 301 redirects, chunked AJAX wizard with resume, zip/tgz support.
