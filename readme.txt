=== Where Is This Text ===
Contributors: aliafshany
Tags: search, find, elementor, widgets, admin
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Paste text you can see on your site and get the exact admin screen that edits it. Widgets, theme options, term meta and Elementor included. Read-only.

== Description ==

You can see the text on your site. WordPress will not tell you which screen edits it.

It might be a post, a widget in a sidebar you have forgotten the name of, a theme option buried in a serialised blob, a term description, or a block of text inside an Elementor layout. The admin search only covers posts, so there is nowhere to look this up.

Search-and-replace plugins do find the string in the database, but they can only build an edit link for the sources that have one: posts, comments and users. A hit in `wp_options` comes back as a bare column name with nothing to click, because an option has no edit screen. You end up knowing the text lives in something called `widget_text` and no wiser about where that actually is.

This plugin does the last step. It maps the container back to the screen that edits it, and gives you a button that goes there.

= Two things it does that a plain database search cannot =

**It finds text inside page builders.** Page builders store content as JSON, and PHP's `json_encode()` escapes non-ASCII characters by default. Elementor's `_elementor_data` therefore contains no readable Persian, Arabic, Hebrew, Greek, Cyrillic, CJK or accented Latin at all — only `\uXXXX` escape sequences. Searching for the text the way you see it can never match. This plugin searches the JSON-escaped form alongside the literal one.

**It handles look-alike characters.** Arabic ي and ك render almost identically to Persian ی and ک but are different Unicode code points, so text typed on one keyboard will not match text stored from the other. Both spellings are searched automatically.

= It narrows the search when nothing matches =

The most common reason a text search finds nothing is that the pasted text spans two separate fields — a heading and the subtitle underneath it, for example. No single database row contains both, so an exact search correctly returns zero, which reads to the user as broken.

When the whole line matches nothing, this plugin retries automatically with progressively shorter phrases and tells you which shorter phrase actually matched.

= What it searches =

* Posts, pages and any custom post type — title, content and excerpt
* Post meta, including page-builder payloads
* Options — widgets resolved to the specific sidebar, Customizer settings, site title and tagline, WooCommerce settings, and anything else named
* Term meta
* Term names and descriptions

Widget results say whether the instance is live on the site or parked in Inactive Widgets, so you do not spend ten minutes editing a dead copy. Results are ordered so live, editable things come first.

= Read-only, by construction =

This plugin runs SELECT statements and renders a table. There is no code path that writes to the database, and nothing is ever sent off your server — no HTTP requests, no telemetry, no external services.

On a front-end request it attaches no hook at all, so it cannot affect your site's output, your page cache or your CDN. The screen requires the `manage_options` capability, checked both when the menu is registered and again when the page renders, and the form is nonce-protected. Every query goes through `$wpdb->prepare()` with `esc_like()`, all output is escaped, rows are capped and long columns are truncated in SQL.

It stores no options and creates no tables. Uninstalling leaves nothing behind.

== Installation ==

1. Upload the plugin through **Plugins → Add New → Upload Plugin**, or copy the folder to `wp-content/plugins/`.
2. Activate it through the **Plugins** screen.
3. Go to **Tools → Where is this text?**

It can also be used as a must-use plugin: copy `where-is-this-text.php` into `wp-content/mu-plugins/`. No activation is needed in that case.

== Frequently Asked Questions ==

= Does it change anything on my site? =

No. It only reads. Every edit is one you make yourself, on the screen it points you to.

= Why does it find nothing for my text? =

Paste one short line rather than several paragraphs. Stored text has real line breaks between paragraphs where a paste flattens them, so a multi-paragraph paste matches nothing. If a short distinctive phrase still finds nothing, the text may be inside an image, or printed by theme or plugin code rather than stored in the database.

= Does it work with page builders other than Elementor? =

Text stored by any builder that keeps JSON in post meta will be found, because the escaped-JSON search is generic. Only Elementor gets a direct link into its own editor; other builders link to the normal WordPress editor.

= Can it search my theme and plugin files too? =

No, and that is deliberate. Scanning PHP files is useless when a theme is encrypted or minified, and a search tool that doubles as a file editor is a security liability. This plugin searches the database only.

= Does it do search and replace? =

No, also deliberately. This tool finds; you edit. Replacing text inside a serialised option is one of the classic ways to corrupt a WordPress site.

= Is it safe on a large site? =

Rows are capped across all search variants rather than per variant, long columns are truncated inside the SQL query rather than pulled whole, and searches under three characters are refused.

== Changelog ==

= 1.0.1 =
* Renamed all functions and constants to a distinctive prefix.
* Added explicit sanitisation of the submitted search term.

= 1.0.0 =
* First release.
