# Where Is This Text

**You can see the text on your site. WordPress won't tell you which screen edits it.**

This plugin closes that gap. Paste a line you can see on the front end, and it tells you exactly where that text is stored and gives you a button straight to the screen that edits it.

It is strictly read-only. It never changes anything.

**[نسخهٔ فارسی — README.fa.md](README.fa.md)**

---

## The problem

A sentence appears on your site. Where does it come from? It might be:

- a post, page or product
- a **widget** in some sidebar you've forgotten the name of
- a **theme option** stored in one big serialised blob
- a **term description** or a custom field on a category
- a block of text inside an **Elementor** layout

WordPress gives you nowhere to look this up. The admin search only covers posts.

Search-and-replace plugins do find the string in the database — but they can only link you to an editor for the sources that *have* one: posts, comments and users. A hit in `wp_options` comes back as a bare column name with nothing to click, because an option has no edit screen. You're left knowing the text is in something called `widget_text` and no wiser about where that is.

This plugin does the last step: it maps the container back to the screen.

---

## Two things it does that a plain database search cannot

### 1. It finds text inside page builders

Page builders store their content as JSON, and PHP's `json_encode()` escapes non-ASCII characters by default. So Elementor's `_elementor_data` contains **no readable Persian, Arabic, Hebrew, Greek, Cyrillic, CJK or accented Latin at all** — only `\uXXXX` escape sequences.

Searching for the text the way you see it will never match. Ever. This is why builder content is invisible to every off-the-shelf search plugin, and it's the main reason this one exists.

This plugin searches the JSON-escaped form as well as the literal one.

### 2. It handles look-alike characters

Arabic `ي` / `ك` and Persian `ی` / `ک` render almost identically but are different Unicode code points. Text typed on one keyboard will not match text stored from the other. Both spellings are searched automatically.

---

## It narrows the search when nothing matches

The most common reason a text search "finds nothing" is that the pasted text spans **two separate fields** — a heading and the subtitle underneath it, for example. No single database row contains both, so an exact search correctly returns zero, which reads to the user as broken.

When the whole line matches nothing, this plugin automatically retries with progressively shorter phrases (dropping trailing words, then leading words) and tells you which shorter phrase actually matched:

> No row contained the whole line — usually that means two separate fields were pasted one after the other. These results are for the shorter phrase: **پرداخت امن و مطمئن**

It also uses only the first non-empty line of whatever you paste, because stored text has real line breaks where a paste flattens them.

---

## What it searches, and where each result points

| Source | Points you to |
|---|---|
| `wp_posts` — title, content, excerpt | that post/page/product editor, or the **Elementor** editor if the page is builder-made |
| `wp_postmeta` — custom fields | the parent item's edit screen; `_elementor_data` opens Elementor |
| `wp_options` — `widget_*` | Appearance → Widgets, naming **which sidebar** the widget sits in, and whether it is live or parked in Inactive Widgets |
| `wp_options` — `theme_mods_*` | Appearance → Customize |
| `wp_options` — `blogname`, `blogdescription` | Settings → General |
| `wp_options` — `woocommerce_*` | WooCommerce → Settings |
| `wp_options` — anything else | named, with a note that it belongs to a plugin/theme settings page |
| `wp_termmeta` | that term's edit screen |
| `wp_term_taxonomy` — name and description | that term's edit screen |

Results are ordered so live, editable things come first and inactive leftovers last.

---

## Install

**As a normal plugin**

1. Download this repository as a ZIP.
2. Plugins → Add New → Upload Plugin → choose the ZIP → Install → Activate.

**Or as a must-use plugin** (always on, cannot be deactivated by accident)

Copy `where-is-this-text.php` into `wp-content/mu-plugins/`. Create that folder if it doesn't exist. No activation needed.

**Or with WP-CLI**

```bash
wp plugin install https://github.com/aliafshany/wp-where-is-this-text/archive/refs/heads/main.zip --activate
```

Requires WordPress 5.6+ and PHP 7.4+. No dependencies, no build step, no settings page.

---

## Use

Go to **Tools → Where is this text?**

Paste **one short line** of text you can see on your site and press **Find it**.

You get a table: what the thing is, which screen edits it, the matched text in context, and an **Open** button that takes you there.

Tips:

- One line, not several paragraphs.
- A short distinctive phrase beats a long one.
- If it finds nothing, the text may be inside an image, or generated by code rather than stored in the database.

---

## Safety

This matters, so it's stated precisely.

- **Read-only.** The plugin runs `SELECT` statements and renders a table. There is no code path that writes to the database.
- **Nothing leaves your server.** No HTTP requests, no telemetry, no external services.
- **Zero front-end footprint.** The `is_admin()` guard wraps the only `add_action()` call, so a visitor request attaches no callback and runs no query. It cannot affect your site's output, your page cache or your CDN.
- **Locked to administrators.** `manage_options` is required both to register the screen and again when it renders, and the form is nonce-checked.
- **Injection-safe.** Every query goes through `$wpdb->prepare()` with `esc_like()`. All table names come from `$wpdb` properties.
- **Output-safe.** Everything rendered is escaped — `esc_html()` for text, `esc_url()` for links.
- **Bounded.** Rows are capped per source across all search variants, long columns are truncated in SQL rather than pulled whole, and needles under 3 characters are refused.

Uninstalling is deleting the file. The plugin stores no options, creates no tables and leaves nothing behind.

---

## Extending

Three filters, if you need them:

```php
// Point an option at your own theme's settings page.
// Many themes keep every setting in one serialised option.
add_filter( 'wheretext_option_location', function ( $location, $name ) {
    if ( 'my_theme_options' === $name ) {
        $location['what']  = 'Theme options';
        $location['where'] = 'Dashboard → Theme Settings';
        $location['url']   = admin_url( 'admin.php?page=my-theme' );
    }
    return $location;
}, 10, 2 );

// Add your own look-alike character normalisation or transliteration.
add_filter( 'wheretext_variants', function ( $variants, $needle ) {
    $variants[] = str_replace( 'ß', 'ss', $needle );
    return $variants;
}, 10, 2 );

// Post-process or add to the result set.
add_filter( 'wheretext_results', function ( $results, $variants ) {
    return $results;
}, 10, 2 );
```

---

## Limitations

Honest list:

- **Text rendered by code is not findable.** If a theme or plugin prints a hardcoded string, it is in a PHP file, not the database. This plugin searches the database only — deliberately, because scanning theme files is useless when the theme is encrypted or minified, and dangerous when it doubles as a file editor.
- **Only Elementor is special-cased** among page builders. Other builders that store JSON in postmeta will still be *found* (the escaped-JSON search is generic) but the result links to the normal editor rather than to the builder.
- **Serialised option rows can't be pinpointed further than the option.** For widgets the exact instance and sidebar are resolved; for a theme's monolithic settings blob, you get the option and the settings page, not the individual field.
- **No search-and-replace.** By design. This tool finds; you edit. Replacing text inside a serialised option is how sites get corrupted.

---

## License

MIT. See [LICENSE](LICENSE).
