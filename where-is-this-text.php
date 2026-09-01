<?php
/**
 * Plugin Name:       Where Is This Text
 * Plugin URI:        https://github.com/aliafshany/wp-where-is-this-text
 * Description:       Paste a piece of text you can see on your site and get told exactly which admin screen edits it — including widgets, theme options, term meta and Elementor layouts. Read-only.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       where-is-this-text
 *
 * ---------------------------------------------------------------------------
 * What problem this solves
 * ---------------------------------------------------------------------------
 * You see a sentence on your site. You have no idea which screen produces it.
 * It could be a post, a widget, a theme option, a term description, or a page
 * built with a page builder — and WordPress gives you nowhere to look it up.
 *
 * Search-and-replace plugins find the string in the database, but they can only
 * link you to an editor for the sources that have one: posts, comments and
 * users. A hit in wp_options comes back as a bare column name with nothing to
 * click, because an option has no edit screen. This plugin closes that last
 * step: it maps the container back to the screen that edits it.
 *
 * ---------------------------------------------------------------------------
 * Two things it does that a plain LIKE search cannot
 * ---------------------------------------------------------------------------
 * 1. Page builders store text as JSON, and json_encode() escapes non-ASCII by
 *    default. Elementor's _elementor_data therefore contains no readable
 *    Persian, Arabic, Greek, Hebrew, Cyrillic, CJK or accented Latin at all —
 *    only \uXXXX escapes. A search for the text as you see it will never match
 *    it. This plugin searches the JSON-escaped form too, which is why builder
 *    content shows up here and nowhere else.
 *
 * 2. Arabic ي/ك and Persian ی/ک are different code points that render almost
 *    identically. Text typed on one keyboard will not match text stored from
 *    the other. Both spellings are searched.
 *
 * ---------------------------------------------------------------------------
 * Safety
 * ---------------------------------------------------------------------------
 * This plugin is strictly read-only. It runs SELECT statements and renders a
 * table. There is no code path that writes to the database, and it sends
 * nothing off your server. On a front-end request it attaches no hook at all,
 * so it cannot affect your site's output, your page cache or your CDN.
 *
 * @package where-is-this-text
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Maximum rows fetched per source, per needle variant. */
const WIT_LIMIT = 40;

/** Needles shorter than this are refused; they match everything. */
const WIT_MIN_CHARS = 3;

/** Characters of context shown either side of a match. */
const WIT_CONTEXT = 55;

/**
 * Register the screen under Tools.
 *
 * The is_admin() guard sits here rather than at the top of the file on purpose.
 * Nothing below registers a hook by itself, so a front-end request still ends
 * up with zero callbacks attached and zero queries run — while the functions
 * stay defined, which lets WP-CLI exercise the search without a browser.
 */
if ( is_admin() ) {
	add_action(
		'admin_menu',
		function () {
			add_management_page(
				__( 'Where is this text?', 'where-is-this-text' ),
				__( 'Where is this text?', 'where-is-this-text' ),
				'manage_options',
				'where-is-this-text',
				'wit_render_screen'
			);
		}
	);
}

/**
 * Build the list of strings to look for.
 *
 * @param string $needle Raw text typed by the user.
 * @return string[] Distinct strings to LIKE-match.
 */
function wit_variants( $needle ) {
	$needle = trim( $needle );

	// Use only the first non-empty line. Pasting several paragraphs otherwise
	// produces a needle that exists nowhere, because the stored copy has real
	// newlines where the paste has none. This is the single most common reason
	// a search "finds nothing".
	$lines = preg_split( '/\r\n|\r|\n/', $needle );
	foreach ( $lines as $line ) {
		if ( trim( $line ) !== '' ) {
			$needle = trim( $line );
			break;
		}
	}

	if ( $needle === '' ) {
		return array();
	}

	$variants = array( $needle );

	// Arabic <-> Persian letter forms: visually near-identical, different code points.
	$variants[] = strtr( $needle, array( 'ي' => 'ی', 'ك' => 'ک' ) );
	$variants[] = strtr( $needle, array( 'ی' => 'ي', 'ک' => 'ك' ) );

	// JSON-escaped form, for page-builder payloads and any other JSON column.
	foreach ( array_values( array_unique( $variants ) ) as $variant ) {
		$json = wp_json_encode( $variant );
		if ( is_string( $json ) && strlen( $json ) > 2 ) {
			$escaped = substr( $json, 1, -1 ); // Strip the surrounding quotes.
			if ( $escaped !== $variant ) {
				$variants[] = $escaped;
			}
		}
	}

	/**
	 * Filter the search variants.
	 *
	 * Useful for transliteration or for normalising your own locale's
	 * look-alike characters.
	 *
	 * @param string[] $variants Strings that will be LIKE-matched.
	 * @param string   $needle   The single line the user actually typed.
	 */
	$variants = apply_filters( 'wit_variants', $variants, $needle );

	return array_values( array_unique( array_filter( (array) $variants, 'strlen' ) ) );
}

/**
 * A short, HTML-escaped snippet of the value around the first match.
 *
 * Every return path escapes. The caller echoes the result unescaped.
 *
 * @param mixed    $value    The stored value.
 * @param string[] $variants Needles to look for.
 * @return string HTML-escaped snippet.
 */
function wit_snippet( $value, $variants ) {
	if ( ! is_string( $value ) ) {
		// maybe_serialize() passes null, ints and bools straight through, so a
		// second cast is needed before the mb_* calls below.
		$value = maybe_serialize( $value );
		$value = is_string( $value ) ? $value : '';
	}
	if ( '' === $value ) {
		return '';
	}

	$pos = false;
	$hit = '';
	foreach ( $variants as $variant ) {
		$found = mb_stripos( $value, $variant, 0, 'UTF-8' );
		if ( false !== $found ) {
			$pos = $found;
			$hit = $variant;
			break;
		}
	}

	if ( false === $pos ) {
		return esc_html( mb_substr( $value, 0, WIT_CONTEXT * 2, 'UTF-8' ) ) . '…';
	}

	$start  = max( 0, $pos - WIT_CONTEXT );
	$length = mb_strlen( $hit, 'UTF-8' ) + ( WIT_CONTEXT * 2 );
	$out    = mb_substr( $value, $start, $length, 'UTF-8' );

	return ( $start > 0 ? '…' : '' ) . esc_html( $out ) . '…';
}

/**
 * Run one LIKE query per needle variant and merge the rows.
 *
 * @param string   $sql_template SQL whose placeholders are $like_count copies of %s followed by %d.
 * @param string[] $variants     Needles.
 * @param string   $key          Column used to de-duplicate rows.
 * @param int      $like_count   How many %s placeholders the template carries.
 * @return array Merged rows.
 */
function wit_query( $sql_template, $variants, $key, $like_count = 1 ) {
	global $wpdb;

	$rows = array();
	foreach ( $variants as $variant ) {
		// The cap is on the MERGED set, not per variant. Without this a search
		// with four variants could pull four times the limit, and the rows are
		// page-builder payloads that run to hundreds of KB each.
		if ( count( $rows ) >= WIT_LIMIT ) {
			break;
		}

		$like   = '%' . $wpdb->esc_like( $variant ) . '%';
		$args   = array_fill( 0, (int) $like_count, $like );
		$args[] = WIT_LIMIT;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql_template is a literal at every call site; all user input arrives through $args.
		$found = $wpdb->get_results( $wpdb->prepare( $sql_template, $args ) );
		foreach ( (array) $found as $row ) {
			$rows[ (string) $row->$key ] = $row;
		}
	}

	return array_slice( array_values( $rows ), 0, WIT_LIMIT );
}

/**
 * get_edit_term_link() can return null; normalise to a string.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy name.
 * @return string
 */
function wit_term_link( $term_id, $taxonomy ) {
	if ( ! $taxonomy ) {
		return '';
	}
	$link = get_edit_term_link( (int) $term_id, $taxonomy );

	return is_string( $link ) ? $link : '';
}

/**
 * get_edit_post_link() can return null; normalise to a string.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function wit_post_link( $post_id ) {
	$link = get_edit_post_link( (int) $post_id, '' );

	return is_string( $link ) ? $link : '';
}

/**
 * Is this post built with Elementor?
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function wit_is_elementor( $post_id ) {
	return 'builder' === get_post_meta( (int) $post_id, '_elementor_edit_mode', true );
}

/**
 * Map an option name to the screen(s) that edit it.
 *
 * Returns a list, not a single row: one option can hold several widget
 * instances, and more than one of them can contain the same sentence.
 *
 * @param string   $name     Option name.
 * @param string[] $variants Needles, used to pin down which widget instance matched.
 * @return array<int,array<string,mixed>>
 */
function wit_locate_option( $name, $variants ) {
	// Widgets: resolve every matching instance and the sidebar each sits in.
	if ( preg_match( '/^widget_(.+)$/', $name, $matches ) ) {
		return wit_locate_widget( $name, $matches[1], $variants );
	}

	if ( 0 === strpos( $name, 'theme_mods_' ) ) {
		$location = array(
			'what'  => __( 'Customizer setting', 'where-is-this-text' ),
			'where' => __( 'Appearance → Customize', 'where-is-this-text' ),
			'url'   => admin_url( 'customize.php' ),
		);
	} elseif ( in_array( $name, array( 'blogname', 'blogdescription' ), true ) ) {
		$location = array(
			'what'  => __( 'Site title or tagline', 'where-is-this-text' ),
			'where' => __( 'Settings → General', 'where-is-this-text' ),
			'url'   => admin_url( 'options-general.php' ),
		);
	} elseif ( 0 === strpos( $name, 'woocommerce_' ) ) {
		$location = array(
			/* translators: %s: option name. */
			'what'  => sprintf( __( 'WooCommerce setting (%s)', 'where-is-this-text' ), $name ),
			'where' => __( 'WooCommerce → Settings', 'where-is-this-text' ),
			'url'   => admin_url( 'admin.php?page=wc-settings' ),
		);
	} else {
		$location = array(
			/* translators: %s: option name. */
			'what'  => sprintf( __( 'Options row: %s', 'where-is-this-text' ), $name ),
			'where' => __( 'This option has no dedicated edit screen. It is normally changed from the settings page of whichever theme or plugin owns it.', 'where-is-this-text' ),
			'url'   => '',
		);
	}

	/**
	 * Filter the screen an option maps to.
	 *
	 * Themes that keep all their settings in one option (a common pattern) can
	 * point that option at their own settings page from here.
	 *
	 * Example:
	 *     add_filter( 'wit_option_location', function ( $location, $name ) {
	 *         if ( 'my_theme_options' === $name ) {
	 *             $location['what']  = 'Theme options';
	 *             $location['where'] = 'Dashboard → Theme Settings';
	 *             $location['url']   = admin_url( 'admin.php?page=my-theme' );
	 *         }
	 *         return $location;
	 *     }, 10, 2 );
	 *
	 * @param array    $location Keys: what, where, url.
	 * @param string   $name     Option name.
	 * @param string[] $variants Search variants.
	 */
	$location = apply_filters( 'wit_option_location', $location, $name, $variants );

	return array( $location );
}

/**
 * Work out which widget instances hold the text, and which sidebar shows each.
 *
 * Every matching instance is returned. The same sentence commonly sits in both
 * a live widget and an old copy parked in "Inactive widgets", and only one of
 * those is worth editing — so both are listed, each saying which it is.
 *
 * @param string   $option_name Option name, e.g. widget_text.
 * @param string   $base        Widget base, e.g. text.
 * @param string[] $variants    Needles.
 * @return array<int,array<string,mixed>>
 */
function wit_locate_widget( $option_name, $base, $variants ) {
	global $wp_registered_sidebars;

	$instances = get_option( $option_name );
	$sidebars  = get_option( 'sidebars_widgets', array() );
	$url       = admin_url( 'widgets.php' );
	$out       = array();

	$fallback = array(
		/* translators: %s: option name. */
		'what'    => sprintf( __( 'Widget (%s)', 'where-is-this-text' ), $option_name ),
		'where'   => __( 'Appearance → Widgets', 'where-is-this-text' ),
		'url'     => $url,
		'snippet' => '',
		'rank'    => 1,
	);

	if ( ! is_array( $instances ) ) {
		return array( $fallback );
	}

	foreach ( $instances as $index => $instance ) {
		if ( ! is_array( $instance ) || ! is_numeric( $index ) ) {
			continue;
		}

		$blob    = maybe_serialize( $instance );
		$matched = false;
		foreach ( $variants as $variant ) {
			if ( is_string( $blob ) && false !== mb_stripos( $blob, $variant, 0, 'UTF-8' ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched ) {
			continue;
		}

		$widget_id = $base . '-' . $index;
		$title     = ( isset( $instance['title'] ) && is_scalar( $instance['title'] ) ) ? trim( (string) $instance['title'] ) : '';
		$body      = ( isset( $instance['text'] ) && is_scalar( $instance['text'] ) ) ? (string) $instance['text'] : $blob;

		$label = '' !== $title
			/* translators: %s: widget title. */
			? sprintf( __( 'Widget “%s”', 'where-is-this-text' ), $title )
			: __( 'Widget', 'where-is-this-text' );

		$area = '';
		foreach ( (array) $sidebars as $sidebar_id => $widget_ids ) {
			if ( is_array( $widget_ids ) && in_array( $widget_id, $widget_ids, true ) ) {
				$area = (string) $sidebar_id;
				break;
			}
		}

		if ( 'wp_inactive_widgets' === $area || '' === $area ) {
			$out[] = array(
				'what'    => $label . ' (' . $widget_id . ')',
				'where'   => __( 'Sitting in “Inactive widgets” — not shown anywhere on the site. This is an old copy; you probably want the other one.', 'where-is-this-text' ),
				'url'     => $url,
				'snippet' => wit_snippet( $body, $variants ),
				'rank'    => 9,
			);
			continue;
		}

		$area_name = isset( $wp_registered_sidebars[ $area ]['name'] )
			? trim( $wp_registered_sidebars[ $area ]['name'] )
			: $area;

		$out[] = array(
			'what'    => $label . ' (' . $widget_id . ')',
			/* translators: %s: sidebar name. */
			'where'   => sprintf( __( 'Live on the site. Appearance → Widgets, in area: %s', 'where-is-this-text' ), $area_name ),
			'url'     => $url,
			'snippet' => wit_snippet( $body, $variants ),
			'rank'    => 0,
		);
	}

	return $out ? $out : array( $fallback );
}

/**
 * Collect every hit.
 *
 * @param string[] $variants Needles.
 * @return array<int,array<string,mixed>> Result rows.
 */
function wit_search( $variants ) {
	global $wpdb;

	if ( empty( $variants ) ) {
		return array();
	}

	$results        = array();
	$seen_elementor = array(); // Post IDs already reported as an Elementor page.

	// ---- posts, pages, custom post types ---------------------------------
	$rows = wit_query(
		"SELECT ID, post_type, post_status, post_title,
		        LEFT( post_content, 65535 ) AS post_content, post_excerpt
		 FROM {$wpdb->posts}
		 WHERE post_type != 'revision'
		   AND post_status != 'auto-draft'
		   AND ( post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s )
		 ORDER BY ID DESC
		 LIMIT %d",
		$variants,
		'ID',
		3
	);

	foreach ( $rows as $row ) {
		$type_object  = get_post_type_object( $row->post_type );
		$label        = $type_object ? $type_object->labels->singular_name : $row->post_type;
		$is_elementor = wit_is_elementor( $row->ID );

		if ( $is_elementor ) {
			$seen_elementor[ (int) $row->ID ] = true;
		}

		$results[] = array(
			'what'    => $label . ' #' . $row->ID . ' — ' . $row->post_title,
			'where'   => $is_elementor
				? __( 'Built with Elementor. The button opens the Elementor editor directly.', 'where-is-this-text' )
				/* translators: 1: post type label, 2: post status. */
				: sprintf( __( 'The %1$s editor (status: %2$s)', 'where-is-this-text' ), $label, $row->post_status ),
			'url'     => $is_elementor
				? admin_url( 'post.php?post=' . (int) $row->ID . '&action=elementor' )
				: wit_post_link( $row->ID ),
			'snippet' => wit_snippet( $row->post_content . ' ' . $row->post_title . ' ' . $row->post_excerpt, $variants ),
			'rank'    => 0,
		);
	}

	// ---- post meta (where page-builder layouts live) ----------------------
	// Elementor keeps a full copy of the layout on every revision, so without
	// the join below a search returns revision rows that cannot be edited.
	$rows = wit_query(
		"SELECT pm.meta_id, pm.post_id, pm.meta_key,
		        LEFT( pm.meta_value, 65535 ) AS meta_value
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_value LIKE %s
		   AND pm.meta_key NOT IN ( '_edit_lock', '_edit_last' )
		   AND p.post_type != 'revision'
		   AND p.post_status != 'auto-draft'
		 ORDER BY pm.meta_id DESC
		 LIMIT %d",
		$variants,
		'meta_id'
	);

	foreach ( $rows as $row ) {
		$title = get_the_title( $row->post_id );

		if ( '_elementor_data' === $row->meta_key ) {
			// The post row above already points at the Elementor editor.
			if ( isset( $seen_elementor[ (int) $row->post_id ] ) ) {
				continue;
			}
			$results[] = array(
				/* translators: 1: post ID, 2: post title. */
				'what'    => sprintf( __( 'Elementor content on #%1$d — %2$s', 'where-is-this-text' ), (int) $row->post_id, $title ),
				'where'   => __( 'Edited in Elementor, not in the WordPress editor. The text lives inside an Elementor widget.', 'where-is-this-text' ),
				'url'     => admin_url( 'post.php?post=' . (int) $row->post_id . '&action=elementor' ),
				'snippet' => wit_snippet( $row->meta_value, $variants ),
				'rank'    => 0,
			);
			continue;
		}

		$results[] = array(
			/* translators: 1: meta key, 2: post ID, 3: post title. */
			'what'    => sprintf( __( 'Custom field %1$s on #%2$d — %3$s', 'where-is-this-text' ), $row->meta_key, (int) $row->post_id, $title ),
			'where'   => __( 'On that item’s edit screen — usually a metabox lower down the page, or inside the settings of the plugin that owns the field.', 'where-is-this-text' ),
			'url'     => wit_post_link( $row->post_id ),
			'snippet' => wit_snippet( $row->meta_value, $variants ),
			'rank'    => 3,
		);
	}

	// ---- options (widgets, theme settings, plugin settings) ---------------
	$rows = wit_query(
		// Transients are excluded in SQL, not afterwards in PHP: filtering after
		// the LIMIT lets cached transient rows eat the whole row budget and hide
		// the real widget or theme-option row. The %% is a literal % inside prepare().
		"SELECT option_id, option_name, LEFT( option_value, 65535 ) AS option_value
		 FROM {$wpdb->options}
		 WHERE option_value LIKE %s
		   AND option_name NOT LIKE '\_transient\_%%'
		   AND option_name NOT LIKE '\_site\_transient\_%%'
		   AND option_name NOT LIKE '\_transient\_timeout\_%%'
		 ORDER BY option_id ASC
		 LIMIT %d",
		$variants,
		'option_id'
	);

	foreach ( $rows as $row ) {
		foreach ( wit_locate_option( $row->option_name, $variants ) as $location ) {
			if ( empty( $location['snippet'] ) ) {
				$location['snippet'] = wit_snippet( $row->option_value, $variants );
			}
			if ( ! isset( $location['rank'] ) ) {
				$location['rank'] = 1;
			}
			$results[] = $location;
		}
	}

	// ---- term meta --------------------------------------------------------
	$rows = wit_query(
		"SELECT tm.meta_id, tm.term_id, tm.meta_key,
		        LEFT( tm.meta_value, 65535 ) AS meta_value, tt.taxonomy
		 FROM {$wpdb->termmeta} tm
		 LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
		 WHERE tm.meta_value LIKE %s
		 ORDER BY tm.meta_id DESC
		 LIMIT %d",
		$variants,
		'meta_id'
	);

	foreach ( $rows as $row ) {
		$term = get_term( (int) $row->term_id );
		$name = ( $term && ! is_wp_error( $term ) ) ? $term->name : ( 'term ' . (int) $row->term_id );

		$results[] = array(
			/* translators: 1: term name, 2: meta key. */
			'what'    => sprintf( __( 'Term “%1$s” — field %2$s', 'where-is-this-text' ), $name, $row->meta_key ),
			'where'   => __( 'The edit screen of that category, tag or term. A custom field there, not the standard description box.', 'where-is-this-text' ),
			'url'     => wit_term_link( (int) $row->term_id, (string) $row->taxonomy ),
			'snippet' => wit_snippet( $row->meta_value, $variants ),
			'rank'    => 2,
		);
	}

	// ---- term names and descriptions --------------------------------------
	$rows = wit_query(
		"SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.description, t.name
		 FROM {$wpdb->term_taxonomy} tt
		 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		 WHERE tt.description LIKE %s OR t.name LIKE %s
		 ORDER BY tt.term_taxonomy_id ASC
		 LIMIT %d",
		$variants,
		'term_taxonomy_id',
		2
	);

	foreach ( $rows as $row ) {
		$results[] = array(
			/* translators: 1: term name, 2: taxonomy name. */
			'what'    => sprintf( __( 'Name or description of “%1$s” (%2$s)', 'where-is-this-text' ), $row->name, $row->taxonomy ),
			'where'   => __( 'The edit screen of that category, tag or term.', 'where-is-this-text' ),
			'url'     => wit_term_link( (int) $row->term_id, (string) $row->taxonomy ),
			'snippet' => wit_snippet( $row->description . ' ' . $row->name, $variants ),
			'rank'    => 2,
		);
	}

	/**
	 * Filter the full result set before it is sorted and shown.
	 *
	 * @param array    $results  Result rows.
	 * @param string[] $variants Search variants.
	 */
	$results = apply_filters( 'wit_results', $results, $variants );

	// Live, editable things first; inactive leftovers last.
	usort(
		$results,
		function ( $a, $b ) {
			$rank_a = isset( $a['rank'] ) ? (int) $a['rank'] : 5;
			$rank_b = isset( $b['rank'] ) ? (int) $b['rank'] : 5;

			return $rank_a <=> $rank_b;
		}
	);

	// Two different rows can describe the same screen; show it once.
	$unique = array();
	foreach ( $results as $result ) {
		$key            = ( isset( $result['what'] ) ? $result['what'] : '' ) . '|' . ( isset( $result['url'] ) ? $result['url'] : '' );
		$unique[ $key ] = $result;
	}

	return array_values( $unique );
}

/**
 * Search, and if the whole line finds nothing, narrow it and try again.
 *
 * Real pastes often join two separate fields into one line — a heading and the
 * subtitle underneath it, say. No single database row contains both, so an exact
 * search correctly returns nothing, which reads to the user as "broken". This
 * drops trailing words, then leading words, until something matches, and reports
 * which shorter phrase actually hit.
 *
 * @param string $needle Raw text typed by the user.
 * @return array{0:array,1:string,2:bool} Results, the phrase that matched, whether it was narrowed.
 */
function wit_search_progressive( $needle ) {
	$variants = wit_variants( $needle );
	if ( ! $variants ) {
		return array( array(), '', false );
	}

	$full    = $variants[0];
	$results = wit_search( $variants );
	if ( $results ) {
		return array( $results, $full, false );
	}

	$words = preg_split( '/\s+/u', $full, -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $words ) || count( $words ) < 3 ) {
		return array( array(), $full, false );
	}

	$attempts = 0;
	$total    = count( $words );

	// Drop words from the end, then from the start. Bounded, so one click cannot
	// turn into dozens of full-table scans.
	for ( $take = $total - 1; $take >= 2 && $attempts < 12; $take-- ) {
		$attempts++;
		$try = implode( ' ', array_slice( $words, 0, $take ) );
		if ( mb_strlen( $try, 'UTF-8' ) < WIT_MIN_CHARS ) {
			break;
		}
		$found = wit_search( wit_variants( $try ) );
		if ( $found ) {
			return array( $found, $try, true );
		}
	}

	for ( $skip = 1; $skip <= $total - 2 && $attempts < 24; $skip++ ) {
		$attempts++;
		$try = implode( ' ', array_slice( $words, $skip ) );
		if ( mb_strlen( $try, 'UTF-8' ) < WIT_MIN_CHARS ) {
			break;
		}
		$found = wit_search( wit_variants( $try ) );
		if ( $found ) {
			return array( $found, $try, true );
		}
	}

	return array( array(), $full, false );
}

/**
 * The admin screen.
 */
function wit_render_screen() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'where-is-this-text' ) );
	}

	$needle   = '';
	$results  = null;
	$notice   = '';
	$narrowed = '';

	if ( isset( $_POST['wit_needle'] ) && check_admin_referer( 'wit_search' ) ) {
		$raw = wp_unslash( $_POST['wit_needle'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitised -- used only as a prepared LIKE value and escaped on output.
		$needle = is_string( $raw ) ? trim( $raw ) : '';

		// Measure the line that will actually be searched, not the whole paste.
		// wit_variants() keeps only the first non-empty line, so "a\nfoobar"
		// would otherwise pass the length check and then run LIKE '%a%' across
		// every table.
		$variants = wit_variants( $needle );
		$first    = $variants ? $variants[0] : '';

		if ( mb_strlen( $first, 'UTF-8' ) < WIT_MIN_CHARS ) {
			$notice = sprintf(
				/* translators: %d: minimum number of characters. */
				__( 'That search is too short. Use at least %d characters on the first line.', 'where-is-this-text' ),
				WIT_MIN_CHARS
			);
		} else {
			list( $results, $matched, $was_narrowed ) = wit_search_progressive( $needle );
			if ( $was_narrowed ) {
				$narrowed = $matched;
			}
		}
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Where is this text?', 'where-is-this-text' ); ?></h1>

		<p>
			<?php esc_html_e( 'Paste a piece of text you can see on your site and this will tell you which screen edits it.', 'where-is-this-text' ); ?>
			<strong><?php esc_html_e( 'Paste one short line, not several paragraphs', 'where-is-this-text' ); ?></strong>
			<?php esc_html_e( '— the stored copy has real line breaks between paragraphs, so a multi-paragraph paste matches nothing.', 'where-is-this-text' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'This tool only reads. It never changes anything — you make the edit yourself on the screen it points you to.', 'where-is-this-text' ); ?>
		</p>

		<?php if ( $notice ) : ?>
			<div class="notice notice-warning"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'wit_search' ); ?>
			<p>
				<input type="text" name="wit_needle" class="regular-text" style="width:100%;max-width:760px;"
					value="<?php echo esc_attr( $needle ); ?>" />
			</p>
			<p><?php submit_button( __( 'Find it', 'where-is-this-text' ), 'primary', 'submit', false ); ?></p>
		</form>

		<?php if ( is_array( $results ) ) : ?>
			<?php if ( '' !== $narrowed ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php esc_html_e( 'No row contained the whole line — usually that means two separate fields (a heading and its subtitle, say) were pasted one after the other. These results are for the shorter phrase:', 'where-is-this-text' ); ?>
						<strong><?php echo esc_html( $narrowed ); ?></strong>
					</p>
				</div>
			<?php endif; ?>

			<h2>
				<?php
				printf(
					/* translators: %d: number of results. */
					esc_html( _n( '%d place found', '%d places found', count( $results ), 'where-is-this-text' ) ),
					count( $results )
				);
				?>
			</h2>

			<?php if ( empty( $results ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'Nothing found. Usually that means more than one line was pasted, the text is inside an image, or the spelling differs from what is stored. Try a shorter phrase.', 'where-is-this-text' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:28%;"><?php esc_html_e( 'What it is', 'where-is-this-text' ); ?></th>
							<th style="width:34%;"><?php esc_html_e( 'Where it is edited', 'where-is-this-text' ); ?></th>
							<th style="width:26%;"><?php esc_html_e( 'Matched text', 'where-is-this-text' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Go', 'where-is-this-text' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $results as $result ) : ?>
						<tr>
							<td><strong><?php echo esc_html( isset( $result['what'] ) ? $result['what'] : '' ); ?></strong></td>
							<td><?php echo esc_html( isset( $result['where'] ) ? $result['where'] : '' ); ?></td>
							<td style="font-size:12px;color:#555;">
								<?php
								// Escaped inside wit_snippet(); echoed raw so the ellipses render.
								echo isset( $result['snippet'] ) ? $result['snippet'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</td>
							<td>
								<?php if ( ! empty( $result['url'] ) ) : ?>
									<a class="button button-secondary" href="<?php echo esc_url( $result['url'] ); ?>">
										<?php esc_html_e( 'Open', 'where-is-this-text' ); ?>
									</a>
								<?php else : ?>
									<span class="description">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}
