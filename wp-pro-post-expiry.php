<?php
/**
 * Plugin Name:       WP Pro Post Expiry
 * Description:       Give posts an expiry date & time (edit screen, list column and Quick Edit). When a post expires it is switched to Draft, Private, moved to Trash, or moved into a chosen category — optionally together with all its WPML translations. Includes a live countdown ([wppe_countdown] shortcode or automatic).
 * Version:           1.2.0
 * Last Updated:      2026-11-18
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WP Pro Design
 * Plugin URI:        https://github.com/sixness/wp-pro-post-expiry
 * Update URI:        https://github.com/sixness/wp-pro-post-expiry
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-pro-post-expiry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPPE_VERSION', '1.2.0' );
define( 'WPPE_GITHUB_REPO', 'sixness/wp-pro-post-expiry' );
define( 'WPPE_UPDATED', '2026-11-18' );
define( 'WPPE_OPTION', 'wppe_settings' );
define( 'WPPE_META_KEY', '_wppe_expiry_datetime' );
define( 'WPPE_LEGACY_META_KEY', 'hs_expiry_datetime' ); // Used by the old "Special Offers expiry" snippet.
define( 'WPPE_CRON_HOOK', 'wppe_check_expired' );
define( 'WPPE_LOCK', 'wppe_check_lock' );
define( 'WPPE_INTERVALS', array( 5, 15, 60 ) );
define( 'WPPE_ACTIONS', array( 'draft', 'private', 'trash', 'category' ) );

/* ===========================================================================
 * Settings
 * ======================================================================== */

/**
 * Find a category ID by slug without WPML's language filter.
 */
function wppe_category_id_by_slug( $slug ) {
	global $wpdb;
	$id = $wpdb->get_var( $wpdb->prepare(
		"SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'category' AND t.slug = %s ORDER BY t.term_id ASC LIMIT 1",
		$slug
	) );
	return $id ? (int) $id : 0;
}

/**
 * Defaults. On this site they reproduce the current behaviour
 * (Special Offers -> "Expired Room Offers"); elsewhere they fall back to Posts -> Draft.
 */
function wppe_defaults() {
	$term = wppe_category_id_by_slug( 'expired-room-offers' );
	return array(
		'post_types' => post_type_exists( 'special-offers' ) ? array( 'special-offers' ) : array( 'post' ),
		'wpml_sync'  => 'yes',
		'interval'   => 15,
		'action'     => $term ? 'category' : 'draft',
		'category'   => $term,
		'cd_auto'    => 'none',
		'cd_label'   => 'Offer ends in',
		'cd_expired' => 'This offer has expired',
	);
}

function wppe_settings() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$saved = get_option( WPPE_OPTION, null );
	$cache = is_array( $saved ) ? wp_parse_args( $saved, wppe_defaults() ) : wppe_defaults();
	return $cache;
}

/**
 * Post types the plugin is active for (only ones that exist).
 */
function wppe_post_types() {
	$s = wppe_settings();
	return array_values( array_filter( (array) $s['post_types'], 'post_type_exists' ) );
}

function wppe_is_enabled_type( $post_type ) {
	return in_array( $post_type, wppe_post_types(), true );
}

/**
 * Save defaults once, after post types are registered.
 */
add_action( 'admin_init', function () {
	if ( false === get_option( WPPE_OPTION, false ) ) {
		add_option( WPPE_OPTION, wppe_defaults() );
	}
} );

/* ===========================================================================
 * One-time migration from the old snippet's meta key
 * ======================================================================== */
add_action( 'admin_init', function () {
	if ( get_option( 'wppe_legacy_migrated' ) ) {
		return;
	}
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
		WPPE_LEGACY_META_KEY
	) );
	foreach ( (array) $rows as $row ) {
		if ( '' === (string) get_post_meta( (int) $row->post_id, WPPE_META_KEY, true ) ) {
			update_post_meta( (int) $row->post_id, WPPE_META_KEY, $row->meta_value );
		}
	}
	update_option( 'wppe_legacy_migrated', WPPE_VERSION, false );
} );

/* ===========================================================================
 * Helpers
 * ======================================================================== */

/** datetime-local (Y-m-dTH:i) in site time -> 'Y-m-d H:i:s', or '' if invalid/empty. */
function wppe_parse_input( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}
	foreach ( array( 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s' ) as $format ) {
		$dt = DateTime::createFromFormat( $format, $raw, wp_timezone() );
		if ( $dt ) {
			return $dt->format( 'Y-m-d H:i:00' );
		}
	}
	return '';
}

/** Stored value -> datetime-local input value. */
function wppe_to_input( $value ) {
	return $value ? str_replace( ' ', 'T', substr( $value, 0, 16 ) ) : '';
}

function wppe_tz_label() {
	$tz = wp_timezone_string();
	return $tz ? $tz : 'site time';
}

/* ===========================================================================
 * Edit screen meta box
 * ======================================================================== */
add_action( 'add_meta_boxes', function ( $post_type ) {
	if ( ! wppe_is_enabled_type( $post_type ) ) {
		return;
	}
	add_meta_box( 'wppe_expiry', 'Post Expiry', 'wppe_render_meta_box', $post_type, 'side', 'high' );
} );

function wppe_render_meta_box( $post ) {
	$value = get_post_meta( $post->ID, WPPE_META_KEY, true );
	wp_nonce_field( 'wppe_save', 'wppe_nonce' );
	echo '<p><label for="wppe_expiry_datetime"><strong>Expiry date &amp; time</strong> (' . esc_html( wppe_tz_label() ) . ')</label></p>';
	echo '<input type="datetime-local" id="wppe_expiry_datetime" name="wppe_expiry_datetime" value="' . esc_attr( wppe_to_input( $value ) ) . '" style="width:100%">';
	echo '<p class="description">' . esc_html( wppe_action_description() ) . ' Leave empty for no expiry.</p>';
}

function wppe_action_description() {
	$s    = wppe_settings();
	$sync = ( 'yes' === $s['wpml_sync'] && defined( 'ICL_SITEPRESS_VERSION' ) ) ? ' (and all its translations)' : '';
	switch ( $s['action'] ) {
		case 'private':
			return 'When this time passes, this post' . $sync . ' will be set to Private.';
		case 'trash':
			return 'When this time passes, this post' . $sync . ' will be moved to the Trash.';
		case 'category':
			$term = get_term( (int) $s['category'], 'category' );
			$name = ( $term && ! is_wp_error( $term ) ) ? '"' . $term->name . '"' : 'the chosen category';
			return 'When this time passes, this post' . $sync . ' will have all its categories removed and be placed in ' . $name . '.';
		default:
			return 'When this time passes, this post' . $sync . ' will be switched to Draft.';
	}
}

add_action( 'save_post', function ( $post_id, $post ) {
	if ( ! isset( $_POST['wppe_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wppe_nonce'] ) ), 'wppe_save' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! wppe_is_enabled_type( $post->post_type ) ) {
		return;
	}
	$value = wppe_parse_input( isset( $_POST['wppe_expiry_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['wppe_expiry_datetime'] ) ) : '' );
	if ( $value ) {
		update_post_meta( $post_id, WPPE_META_KEY, $value );
	} else {
		delete_post_meta( $post_id, WPPE_META_KEY );
	}
}, 10, 2 );

/* ===========================================================================
 * List column + Quick Edit
 * ======================================================================== */
add_action( 'admin_init', function () {
	foreach ( wppe_post_types() as $pt ) {
		add_filter( "manage_{$pt}_posts_columns", function ( $cols ) {
			$cols['wppe_expiry'] = 'Expires';
			return $cols;
		} );
		add_action( "manage_{$pt}_posts_custom_column", function ( $col, $post_id ) {
			if ( 'wppe_expiry' !== $col ) {
				return;
			}
			$v = get_post_meta( $post_id, WPPE_META_KEY, true );
			echo $v ? esc_html( wp_date( 'd/m/Y H:i', strtotime( $v . ' ' . wp_timezone_string() ) ) ) : '&mdash;';
			echo '<span class="wppe-raw" hidden>' . esc_html( wppe_to_input( $v ) ) . '</span>';
		}, 10, 2 );
	}
} );

add_action( 'quick_edit_custom_box', function ( $col, $post_type ) {
	if ( 'wppe_expiry' !== $col || ! wppe_is_enabled_type( $post_type ) ) {
		return;
	}
	echo '<fieldset class="inline-edit-col-right"><div class="inline-edit-col">';
	echo '<label><span class="title">Expires</span><span class="input-text-wrap">';
	echo '<input type="datetime-local" name="wppe_expiry_datetime_qe" value="">';
	// Original value, filled in by JS. Saving only changes the expiry if the field differs from this,
	// so a Quick Edit that never loaded the current value can't wipe it.
	echo '<input type="hidden" name="wppe_expiry_datetime_qe_orig" value="">';
	echo '</span></label><p class="description" style="margin:4px 0 0">' . esc_html( wppe_tz_label() ) . '. Clear to remove expiry.</p>';
	echo '</div></fieldset>';
}, 10, 2 );

add_action( 'save_post', function ( $post_id, $post ) {
	if ( ! wp_doing_ajax() || empty( $_POST['action'] ) || 'inline-save' !== $_POST['action'] || ! isset( $_POST['wppe_expiry_datetime_qe'] ) ) {
		return;
	}
	if ( ! check_ajax_referer( 'inlineeditnonce', '_inline_edit', false ) || ! current_user_can( 'edit_post', $post_id ) || ! wppe_is_enabled_type( $post->post_type ) ) {
		return;
	}
	$raw  = trim( sanitize_text_field( wp_unslash( $_POST['wppe_expiry_datetime_qe'] ) ) );
	$orig = isset( $_POST['wppe_expiry_datetime_qe_orig'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wppe_expiry_datetime_qe_orig'] ) ) ) : '';
	if ( $raw === $orig ) {
		return; // Field untouched: keep whatever is stored.
	}
	$value = wppe_parse_input( $raw );
	if ( $value ) {
		update_post_meta( $post_id, WPPE_META_KEY, $value );
	} elseif ( '' === trim( $raw ) ) {
		delete_post_meta( $post_id, WPPE_META_KEY );
	}
}, 10, 2 );

add_action( 'admin_footer-edit.php', function () {
	$screen = get_current_screen();
	if ( ! $screen || ! wppe_is_enabled_type( $screen->post_type ) ) {
		return;
	}
	?>
<script>
jQuery(function ($) {
	if (typeof inlineEditPost === 'undefined') { return; }
	var wppeOrigEdit = inlineEditPost.edit;
	inlineEditPost.edit = function (id) {
		wppeOrigEdit.apply(this, arguments);
		var postId = (typeof id === 'object') ? parseInt(this.getId(id), 10) : parseInt(id, 10);
		if (!postId) { return; }
		var raw = $.trim($('#post-' + postId).find('.wppe-raw').text());
		var $row = $('#edit-' + postId);
		$row.find('input[name="wppe_expiry_datetime_qe"]').val(raw);
		$row.find('input[name="wppe_expiry_datetime_qe_orig"]').val(raw);
	};
});
</script>
	<?php
} );

/* ===========================================================================
 * Scheduling (5 / 15 / 60 minutes) + fallback for sites with DISABLE_WP_CRON
 * ======================================================================== */
add_filter( 'cron_schedules', function ( $schedules ) {
	foreach ( WPPE_INTERVALS as $m ) {
		$key = 'wppe_every_' . $m . '_min';
		if ( ! isset( $schedules[ $key ] ) ) {
			$schedules[ $key ] = array( 'interval' => $m * MINUTE_IN_SECONDS, 'display' => 'Every ' . $m . ' minutes (WP Pro Post Expiry)' );
		}
	}
	return $schedules;
} );

function wppe_interval() {
	$s = wppe_settings();
	$m = (int) $s['interval'];
	return in_array( $m, WPPE_INTERVALS, true ) ? $m : 15;
}

add_action( 'init', function () {
	$recurrence = 'wppe_every_' . wppe_interval() . '_min';
	$current    = wp_get_schedule( WPPE_CRON_HOOK );
	if ( $current && $current !== $recurrence ) {
		wp_clear_scheduled_hook( WPPE_CRON_HOOK );
		$current = false;
	}
	if ( ! $current ) {
		wp_schedule_event( time() + 60, $recurrence, WPPE_CRON_HOOK );
	}
}, 20 );

add_action( 'init', function () {
	if ( wp_doing_cron() || get_transient( WPPE_LOCK ) ) {
		return;
	}
	set_transient( WPPE_LOCK, 1, wppe_interval() * MINUTE_IN_SECONDS );
	do_action( WPPE_CRON_HOOK );
}, 99 );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( WPPE_CRON_HOOK );
	delete_transient( WPPE_LOCK );
} );

/* ===========================================================================
 * The expiry check
 * ======================================================================== */
add_action( WPPE_CRON_HOOK, 'wppe_run_check' );

function wppe_wpml_active() {
	return defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_object_id' );
}

/** The chosen category in every WPML language. */
function wppe_category_in_all_languages( $base ) {
	$ids = array( $base );
	if ( wppe_wpml_active() ) {
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		foreach ( (array) $languages as $code => $lang ) {
			$tid = apply_filters( 'wpml_object_id', $base, 'category', false, $code );
			if ( $tid ) {
				$ids[] = (int) $tid;
			}
		}
	}
	return array_values( array_unique( $ids ) );
}

function wppe_run_check() {
	$s          = wppe_settings();
	$action     = in_array( $s['action'], WPPE_ACTIONS, true ) ? $s['action'] : 'draft';
	$post_types = wppe_post_types();
	$base_term  = 0;
	$query      = array();

	if ( 'category' === $action ) {
		$base_term = (int) $s['category'];
		if ( ! $base_term || ! term_exists( $base_term, 'category' ) ) {
			return; // No valid category chosen: do nothing rather than strip categories.
		}
		$post_types = array_values( array_filter( $post_types, function ( $pt ) {
			return is_object_in_taxonomy( $pt, 'category' );
		} ) );
		$query['tax_query'] = array(
			array(
				'taxonomy'         => 'category',
				'field'            => 'term_id',
				'terms'            => wppe_category_in_all_languages( $base_term ),
				'operator'         => 'NOT IN',
				'include_children' => false,
			),
		);
	}

	if ( empty( $post_types ) ) {
		return;
	}

	$now     = new DateTime( 'now', wp_timezone() );
	$expired = get_posts( array_merge( array(
		'post_type'        => $post_types,
		'post_status'      => 'publish',
		'posts_per_page'   => 200,
		'fields'           => 'ids',
		'suppress_filters' => true, // all languages
		'meta_query'       => array(
			'relation' => 'AND',
			array( 'key' => WPPE_META_KEY, 'value' => '', 'compare' => '!=' ),
			array(
				'key'     => WPPE_META_KEY,
				'value'   => $now->format( 'Y-m-d H:i:s' ),
				'compare' => '<=',
				'type'    => 'DATETIME',
			),
		),
	), $query ) );

	if ( empty( $expired ) ) {
		return;
	}

	$done = array();
	foreach ( $expired as $post_id ) {
		$post_type = get_post_type( $post_id );
		$ids       = array( (int) $post_id );

		if ( 'yes' === $s['wpml_sync'] && wppe_wpml_active() ) {
			$element_type = 'post_' . $post_type;
			$trid         = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );
			if ( $trid ) {
				$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
				foreach ( (array) $translations as $t ) {
					if ( ! empty( $t->element_id ) ) {
						$ids[] = (int) $t->element_id;
					}
				}
			}
		}

		foreach ( array_unique( $ids ) as $id ) {
			if ( isset( $done[ $id ] ) ) {
				continue;
			}
			$done[ $id ] = true;
			wppe_expire_post( $id, $action, $base_term );
		}
	}

	// Let page caches know content changed (no-ops if the plugin isn't installed).
	do_action( 'breeze_clear_all_cache' );
	do_action( 'litespeed_purge_all' );
	do_action( 'wppe_after_check', array_keys( $done ), $action );
}

function wppe_expire_post( $id, $action, $base_term ) {
	$status = get_post_status( $id );
	if ( ! $status || 'trash' === $status ) {
		return;
	}

	switch ( $action ) {
		case 'draft':
		case 'private':
			if ( 'publish' === $status ) {
				wp_update_post( array( 'ID' => $id, 'post_status' => $action ) );
			}
			break;

		case 'trash':
			wp_trash_post( $id );
			break;

		case 'category':
			$term_id = $base_term;
			if ( wppe_wpml_active() ) {
				$lang = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $id, 'element_type' => get_post_type( $id ) ) );
				if ( $lang ) {
					$term_id = (int) apply_filters( 'wpml_object_id', $base_term, 'category', true, $lang );
				}
			}
			if ( ! $term_id ) {
				$term_id = $base_term;
			}
			// Replace (not append): removes every previous category.
			wp_set_object_terms( $id, array( $term_id ), 'category', false );
			clean_post_cache( $id );
			break;
	}

	do_action( 'wppe_post_expired', $id, $action );
}

/* ===========================================================================
 * Countdown: [wppe_countdown] shortcode + optional automatic display
 * ======================================================================== */

/** Translatable setting text (WPML String Translation). */
function wppe_string( $key, $name ) {
	$s     = wppe_settings();
	$value = isset( $s[ $key ] ) ? (string) $s[ $key ] : '';
	return (string) apply_filters( 'wpml_translate_single_string', $value, 'wp-pro-post-expiry', $name );
}

add_action( 'admin_init', function () {
	if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
		return;
	}
	$s = wppe_settings();
	do_action( 'wpml_register_single_string', 'wp-pro-post-expiry', 'Countdown label', (string) $s['cd_label'] );
	do_action( 'wpml_register_single_string', 'wp-pro-post-expiry', 'Countdown expired message', (string) $s['cd_expired'] );
} );

/**
 * Expiry of a post; falls back to the WPML original when a translation has none.
 */
function wppe_get_expiry( $post_id ) {
	$value = (string) get_post_meta( $post_id, WPPE_META_KEY, true );
	if ( '' === $value && wppe_wpml_active() ) {
		$type     = get_post_type( $post_id );
		$original = (int) apply_filters( 'wpml_original_element_id', null, $post_id, 'post_' . $type );
		if ( $original && $original !== (int) $post_id ) {
			$value = (string) get_post_meta( $original, WPPE_META_KEY, true );
		}
	}
	return $value;
}

function wppe_format_remaining( $seconds ) {
	$seconds = max( 0, (int) $seconds );
	$d       = intdiv( $seconds, DAY_IN_SECONDS );
	$h       = intdiv( $seconds % DAY_IN_SECONDS, HOUR_IN_SECONDS );
	$m       = intdiv( $seconds % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );
	$sec     = $seconds % MINUTE_IN_SECONDS;
	$out     = $d > 0 ? $d . 'd ' : '';
	return $out . sprintf( '%02dh %02dm %02ds', $h, $m, $sec );
}

function wppe_render_countdown( $post_id, $label = null, $expired = null ) {
	$post_id = (int) $post_id;
	if ( ! $post_id ) {
		return '';
	}
	$value = wppe_get_expiry( $post_id );
	if ( '' === $value ) {
		return '';
	}
	$expires = strtotime( $value . ' ' . wp_timezone_string() );
	if ( ! $expires ) {
		return '';
	}

	$label   = null === $label ? wppe_string( 'cd_label', 'Countdown label' ) : $label;
	$expired = null === $expired ? wppe_string( 'cd_expired', 'Countdown expired message' ) : $expired;
	$left    = $expires - time();

	wppe_enqueue_countdown_assets();

	$classes = 'wppe-countdown' . ( $left <= 0 ? ' is-expired' : '' );
	$html    = '<div class="' . esc_attr( $classes ) . '" data-expires="' . esc_attr( $expires * 1000 ) . '" data-expired-text="' . esc_attr( $expired ) . '"' . ( $left <= 0 && '' === $expired ? ' hidden' : '' ) . '>';
	if ( $left > 0 ) {
		if ( '' !== $label ) {
			$html .= '<span class="wppe-countdown__label">' . esc_html( $label ) . '</span> ';
		}
		$html .= '<span class="wppe-countdown__time">' . esc_html( wppe_format_remaining( $left ) ) . '</span>';
	} else {
		$html .= '<span class="wppe-countdown__expired">' . esc_html( $expired ) . '</span>';
	}
	$html .= '</div>';
	return $html;
}

add_shortcode( 'wppe_countdown', function ( $atts ) {
	$atts = shortcode_atts( array(
		'id'      => 0,
		'label'   => null,
		'expired' => null,
	), $atts, 'wppe_countdown' );
	$post_id = $atts['id'] ? absint( $atts['id'] ) : get_the_ID();
	return wppe_render_countdown( $post_id, $atts['label'], $atts['expired'] );
} );

add_filter( 'the_content', function ( $content ) {
	static $done = false;
	$s = wppe_settings();
	if ( $done || 'none' === $s['cd_auto'] || is_admin() || ! is_singular( wppe_post_types() ) ) {
		return $content;
	}
	if ( (int) get_the_ID() !== (int) get_queried_object_id() || has_shortcode( $content, 'wppe_countdown' ) ) {
		return $content;
	}
	$box = wppe_render_countdown( get_the_ID() );
	if ( '' === $box ) {
		return $content;
	}
	$done = true;
	return 'before' === $s['cd_auto'] ? $box . $content : $content . $box;
}, 20 );

function wppe_enqueue_countdown_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	wp_register_style( 'wppe-countdown', false, array(), WPPE_VERSION );
	wp_enqueue_style( 'wppe-countdown' );
	wp_add_inline_style( 'wppe-countdown', '.wppe-countdown{margin:0 0 1em;font-weight:600}.wppe-countdown__time{font-variant-numeric:tabular-nums;white-space:nowrap}' );

	wp_register_script( 'wppe-countdown', false, array(), WPPE_VERSION, true );
	wp_enqueue_script( 'wppe-countdown' );
	wp_add_inline_script( 'wppe-countdown', <<<'JS'
(function () {
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function fmt(ms) {
		var t = Math.max(0, Math.floor(ms / 1000));
		var d = Math.floor(t / 86400), h = Math.floor(t % 86400 / 3600), m = Math.floor(t % 3600 / 60), s = t % 60;
		return (d > 0 ? d + 'd ' : '') + pad(h) + 'h ' + pad(m) + 'm ' + pad(s) + 's';
	}
	function expire(el) {
		var text = el.getAttribute('data-expired-text') || '';
		el.classList.add('is-expired');
		if (!text) { el.hidden = true; return; }
		el.innerHTML = '';
		var span = document.createElement('span');
		span.className = 'wppe-countdown__expired';
		span.textContent = text;
		el.appendChild(span);
	}
	function tick() {
		var els = document.querySelectorAll('.wppe-countdown:not(.is-expired)');
		var now = Date.now();
		for (var i = 0; i < els.length; i++) {
			var el = els[i], left = parseInt(el.getAttribute('data-expires'), 10) - now;
			if (left <= 0) { expire(el); continue; }
			var t = el.querySelector('.wppe-countdown__time');
			if (t) { t.textContent = fmt(left); }
		}
	}
	function start() {
		// Fix up pages served from cache that were rendered before expiry.
		var all = document.querySelectorAll('.wppe-countdown');
		for (var i = 0; i < all.length; i++) {
			if (parseInt(all[i].getAttribute('data-expires'), 10) <= Date.now() && !all[i].querySelector('.wppe-countdown__expired')) { expire(all[i]); }
		}
		tick();
		setInterval(tick, 1000);
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
JS
	);
}

/* ===========================================================================
 * Settings page (Settings -> Post Expiry)
 * ======================================================================== */
add_action( 'admin_menu', function () {
	add_options_page( 'WP Pro Post Expiry', 'Post Expiry', 'manage_options', 'wp-pro-post-expiry', 'wppe_render_settings_page' );
} );

add_filter( 'plugin_row_meta', function ( $meta, $file ) {
	if ( plugin_basename( __FILE__ ) === $file ) {
		$meta[] = 'Last updated: ' . esc_html( wp_date( 'j M Y', strtotime( WPPE_UPDATED . ' 12:00:00' ) ) );
	}
	return $meta;
}, 10, 2 );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=wp-pro-post-expiry' ) ) . '">Settings</a>' );
	return $links;
} );

add_action( 'admin_init', function () {
	register_setting( 'wppe_settings_group', WPPE_OPTION, array(
		'type'              => 'array',
		'sanitize_callback' => 'wppe_sanitize_settings',
		'default'           => wppe_defaults(),
	) );
} );

function wppe_sanitize_settings( $input ) {
	$input = is_array( $input ) ? $input : array();
	$out   = array();

	$valid_types       = array_keys( wppe_available_post_types() );
	$out['post_types'] = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['post_types'] ?? array() ) ), $valid_types ) );

	$out['wpml_sync'] = ( isset( $input['wpml_sync'] ) && 'no' === $input['wpml_sync'] ) ? 'no' : 'yes';

	$interval        = (int) ( $input['interval'] ?? 15 );
	$out['interval'] = in_array( $interval, WPPE_INTERVALS, true ) ? $interval : 15;

	$action        = sanitize_key( $input['action'] ?? 'draft' );
	$out['action'] = in_array( $action, WPPE_ACTIONS, true ) ? $action : 'draft';

	$out['category'] = absint( $input['category'] ?? 0 );

	$cd_auto           = sanitize_key( $input['cd_auto'] ?? 'none' );
	$out['cd_auto']    = in_array( $cd_auto, array( 'none', 'before', 'after' ), true ) ? $cd_auto : 'none';
	$out['cd_label']   = sanitize_text_field( $input['cd_label'] ?? '' );
	$out['cd_expired'] = sanitize_text_field( $input['cd_expired'] ?? '' );

	if ( empty( $out['post_types'] ) ) {
		add_settings_error( WPPE_OPTION, 'wppe_no_types', 'No post types selected — nothing will expire until you pick at least one.', 'warning' );
	}
	if ( 'category' === $out['action'] ) {
		if ( ! $out['category'] || ! term_exists( $out['category'], 'category' ) ) {
			add_settings_error( WPPE_OPTION, 'wppe_no_cat', 'Please choose a category. Until you do, expired posts are left untouched.', 'error' );
		}
		$no_cat = array_filter( $out['post_types'], function ( $pt ) {
			return ! is_object_in_taxonomy( $pt, 'category' );
		} );
		if ( $no_cat ) {
			add_settings_error( WPPE_OPTION, 'wppe_no_cat_support', 'These post types don\'t use categories and will be skipped by the "Move to category" action: ' . esc_html( implode( ', ', $no_cat ) ) . '.', 'warning' );
		}
	}

	// Restart the schedule with the new interval straight away.
	wp_clear_scheduled_hook( WPPE_CRON_HOOK );
	delete_transient( WPPE_LOCK );

	return $out;
}

function wppe_available_post_types() {
	$types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $types['attachment'] );
	foreach ( array( 'elementor_library', 'e-landing-page', 'e-floating-buttons' ) as $skip ) {
		unset( $types[ $skip ] );
	}
	return $types;
}

function wppe_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s    = wppe_settings();
	$name = WPPE_OPTION;
	$next = wp_next_scheduled( WPPE_CRON_HOOK );
	?>
	<div class="wrap">
		<h1>WP Pro Post Expiry</h1>
		<p class="description">Version <?php echo esc_html( WPPE_VERSION ); ?> &middot; Last updated <?php echo esc_html( wp_date( 'j M Y', strtotime( WPPE_UPDATED . ' 12:00:00' ) ) ); ?></p>
		<?php settings_errors( WPPE_OPTION ); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wppe_settings_group' ); ?>
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row">Post types</th>
					<td>
						<fieldset>
							<?php foreach ( wppe_available_post_types() as $pt => $obj ) : ?>
								<label style="display:block;margin-bottom:4px">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[post_types][]" value="<?php echo esc_attr( $pt ); ?>" <?php checked( in_array( $pt, (array) $s['post_types'], true ) ); ?>>
									<?php echo esc_html( $obj->labels->name ); ?> <code><?php echo esc_html( $pt ); ?></code>
								</label>
							<?php endforeach; ?>
							<p class="description">The expiry field, "Expires" column and Quick Edit field appear for these post types.</p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row">After a post expires</th>
					<td>
						<fieldset>
							<label style="display:block;margin-bottom:6px"><input type="radio" name="<?php echo esc_attr( $name ); ?>[action]" value="draft" <?php checked( $s['action'], 'draft' ); ?>> Change status to <strong>Draft</strong></label>
							<label style="display:block;margin-bottom:6px"><input type="radio" name="<?php echo esc_attr( $name ); ?>[action]" value="private" <?php checked( $s['action'], 'private' ); ?>> Change status to <strong>Private</strong></label>
							<label style="display:block;margin-bottom:6px"><input type="radio" name="<?php echo esc_attr( $name ); ?>[action]" value="trash" <?php checked( $s['action'], 'trash' ); ?>> Move to <strong>Trash</strong></label>
							<label style="display:block;margin-bottom:6px"><input type="radio" name="<?php echo esc_attr( $name ); ?>[action]" value="category" <?php checked( $s['action'], 'category' ); ?>> Move to <strong>category</strong> (removes all existing categories, keeps the post published):</label>
							<div style="margin:2px 0 0 24px">
								<?php
								wp_dropdown_categories( array(
									'taxonomy'          => 'category',
									'name'              => $name . '[category]',
									'id'                => 'wppe_category',
									'selected'          => (int) $s['category'],
									'hide_empty'        => 0,
									'hierarchical'      => 1,
									'show_option_none'  => '— Select a category —',
									'option_none_value' => 0,
								) );
								?>
								<p class="description">With WPML, translated posts get the translated version of this category where one exists; otherwise this one.</p>
							</div>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row">WPML translations</th>
					<td>
						<fieldset>
							<label style="margin-right:16px"><input type="radio" name="<?php echo esc_attr( $name ); ?>[wpml_sync]" value="yes" <?php checked( $s['wpml_sync'], 'yes' ); ?>> Yes</label>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[wpml_sync]" value="no" <?php checked( $s['wpml_sync'], 'no' ); ?>> No</label>
							<p class="description">Yes: when a post expires, all its translations expire with it — set the date on the original only. No: each post expires on its own date.</p>
							<?php if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) : ?>
								<p class="description"><em>WPML isn't active on this site, so this setting has no effect.</em></p>
							<?php endif; ?>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row">Check every</th>
					<td>
						<fieldset>
							<?php foreach ( WPPE_INTERVALS as $m ) : ?>
								<label style="margin-right:16px"><input type="radio" name="<?php echo esc_attr( $name ); ?>[interval]" value="<?php echo esc_attr( $m ); ?>" <?php checked( (int) $s['interval'], $m ); ?>> <?php echo esc_html( $m ); ?> minutes</label>
							<?php endforeach; ?>
							<p class="description">
								Posts are processed up to this long after their expiry time. Times use the site timezone (<?php echo esc_html( wppe_tz_label() ); ?>).
								<?php if ( $next ) : ?>
									<br>Next scheduled check: <?php echo esc_html( wp_date( 'd/m/Y H:i', $next ) ); ?>.
								<?php endif; ?>
							</p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row" colspan="2"><h2 style="margin:0">Countdown</h2></th>
				</tr>

				<tr>
					<th scope="row">Show automatically</th>
					<td>
						<fieldset>
							<label style="margin-right:16px"><input type="radio" name="<?php echo esc_attr( $name ); ?>[cd_auto]" value="none" <?php checked( $s['cd_auto'], 'none' ); ?>> Off</label>
							<label style="margin-right:16px"><input type="radio" name="<?php echo esc_attr( $name ); ?>[cd_auto]" value="before" <?php checked( $s['cd_auto'], 'before' ); ?>> Before content</label>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[cd_auto]" value="after" <?php checked( $s['cd_auto'], 'after' ); ?>> After content</label>
							<p class="description">Adds the countdown to single posts of the selected post types that have an expiry date.<br>
							Or place it yourself with the shortcode <code>[wppe_countdown]</code> — e.g. in an Elementor Shortcode widget, a single template or a loop/card template.<br>
							Options: <code>[wppe_countdown id="123" label="Ends in" expired="Sorry, this offer has ended"]</code></p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="wppe_cd_label">Label</label></th>
					<td>
						<input type="text" class="regular-text" id="wppe_cd_label" name="<?php echo esc_attr( $name ); ?>[cd_label]" value="<?php echo esc_attr( $s['cd_label'] ); ?>">
						<p class="description">Shown before the time, e.g. "Offer ends in 3d 12h 05m 20s".</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="wppe_cd_expired">Expired message</label></th>
					<td>
						<input type="text" class="regular-text" id="wppe_cd_expired" name="<?php echo esc_attr( $name ); ?>[cd_expired]" value="<?php echo esc_attr( $s['cd_expired'] ); ?>">
						<p class="description">Shown when the countdown reaches zero. Leave empty to hide the countdown instead.
						<?php if ( defined( 'ICL_SITEPRESS_VERSION' ) ) : ?>
							<br>Translate both texts in <strong>WPML &rsaquo; String Translation</strong> (domain <code>wp-pro-post-expiry</code>).
						<?php endif; ?></p>
					</td>
				</tr>

			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/* ===========================================================================
 * Warn if an older version is still running (it would double up)
 * ======================================================================== */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$msgs = array();

	global $wpdb;
	$table = $wpdb->prefix . 'snippets';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE active = 1 AND name LIKE 'Special Offers expiry%'" );
		if ( $active ) {
			$msgs[] = 'the old "Special Offers expiry" code snippet is still active — deactivate it in <a href="' . esc_url( admin_url( 'admin.php?page=snippets' ) ) . '">Snippets</a>';
		}
	}
	if ( function_exists( 'voe_expire_offers' ) ) {
		$msgs[] = 'the "Varana Offer Expiry" plugin is still active — deactivate it in <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Plugins</a>';
	}

	if ( $msgs ) {
		echo '<div class="notice notice-warning"><p><strong>WP Pro Post Expiry:</strong> ' . wp_kses_post( implode( '; ', $msgs ) ) . ', so expiry doesn\'t run twice.</p></div>';
	}
} );

/* ===========================================================================
 * Updates from GitHub Releases
 * Publish a release tagged vX.Y.Z with a wp-pro-post-expiry.zip asset and every
 * site shows "Update now" on the Plugins page.
 * ======================================================================== */

/**
 * Latest release info, cached. Returns array( version, package, url, notes, published ) or null.
 */
function wppe_github_latest( $force = false ) {
	$cache_key = 'wppe_github_release';
	if ( ! $force ) {
		$cached = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
	}

	$response = wp_remote_get( 'https://api.github.com/repos/' . WPPE_GITHUB_REPO . '/releases/latest', array(
		'timeout' => 10,
		'headers' => array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'WP-Pro-Post-Expiry/' . WPPE_VERSION . '; ' . home_url(),
		),
	) );

	$data = null;
	if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $json ) && ! empty( $json['tag_name'] ) && empty( $json['draft'] ) && empty( $json['prerelease'] ) ) {
			$package = '';
			foreach ( (array) ( $json['assets'] ?? array() ) as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && 'wp-pro-post-expiry.zip' === $asset['name'] ) {
					$package = $asset['browser_download_url'];
					break;
				}
			}
			if ( $package ) {
				$data = array(
					'version'   => ltrim( (string) $json['tag_name'], 'vV' ),
					'package'   => $package,
					'url'       => (string) ( $json['html_url'] ?? 'https://github.com/' . WPPE_GITHUB_REPO ),
					'notes'     => (string) ( $json['body'] ?? '' ),
					'published' => (string) ( $json['published_at'] ?? '' ),
				);
			}
		}
	}

	// Cache 6 hours on success, 1 hour on failure (GitHub allows 60 anonymous requests/hour).
	set_site_transient( $cache_key, $data ? $data : array( 'version' => '' ), $data ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $data;
}

// WordPress 5.8+ asks this filter for plugins whose "Update URI" host is github.com.
add_filter( 'update_plugins_github.com', function ( $update, $plugin_data, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
		return $update;
	}
	$release = wppe_github_latest();
	if ( ! $release ) {
		return $update;
	}
	return array(
		'id'           => 'https://github.com/' . WPPE_GITHUB_REPO,
		'slug'         => 'wp-pro-post-expiry',
		'version'      => $release['version'],
		'url'          => 'https://github.com/' . WPPE_GITHUB_REPO,
		'package'      => $release['package'],
		'requires'     => '6.0',
		'requires_php' => '7.4',
	);
}, 10, 3 );

// "View details" popup on the Plugins / Updates screens.
add_filter( 'plugins_api', function ( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || 'wp-pro-post-expiry' !== $args->slug ) {
		return $result;
	}
	$release = wppe_github_latest();
	$notes   = $release ? $release['notes'] : '';
	return (object) array(
		'name'          => 'WP Pro Post Expiry',
		'slug'          => 'wp-pro-post-expiry',
		'version'       => $release ? $release['version'] : WPPE_VERSION,
		'author'        => '<a href="https://github.com/' . esc_attr( WPPE_GITHUB_REPO ) . '">WP Pro Design</a>',
		'homepage'      => 'https://github.com/' . WPPE_GITHUB_REPO,
		'requires'      => '6.0',
		'requires_php'  => '7.4',
		'last_updated'  => $release ? $release['published'] : '',
		'download_link' => $release ? $release['package'] : '',
		'sections'      => array(
			'description' => '<p>Give posts an expiry date &amp; time. When a post expires it is switched to Draft, Private, moved to Trash, or moved into a chosen category — optionally together with all its WPML translations. Includes Quick Edit support and a live countdown.</p>',
			'changelog'   => $notes ? wpautop( esc_html( $notes ) ) : '<p>See <a href="https://github.com/' . esc_attr( WPPE_GITHUB_REPO ) . '/releases">GitHub Releases</a>.</p>',
		),
	);
}, 10, 3 );

// Make sure the updated files land in the same folder, whatever the zip's top folder is called.
add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	global $wp_filesystem;
	if ( empty( $hook_extra['plugin'] ) || plugin_basename( __FILE__ ) !== $hook_extra['plugin'] || ! $wp_filesystem ) {
		return $source;
	}
	$wanted = trailingslashit( $remote_source ) . 'wp-pro-post-expiry/';
	if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
		return $source;
	}
	return $wp_filesystem->move( $source, $wanted, true ) ? $wanted : $source;
}, 10, 4 );

add_action( 'upgrader_process_complete', function () {
	delete_site_transient( 'wppe_github_release' );
} );

// "Check for updates" link under the plugin name.
add_filter( 'plugin_row_meta', function ( $meta, $file ) {
	if ( plugin_basename( __FILE__ ) === $file && current_user_can( 'update_plugins' ) ) {
		$url    = wp_nonce_url( admin_url( 'plugins.php?wppe_check_update=1' ), 'wppe_check_update' );
		$meta[] = '<a href="' . esc_url( $url ) . '">Check for updates</a>';
	}
	return $meta;
}, 20, 2 );

add_action( 'admin_init', function () {
	if ( empty( $_GET['wppe_check_update'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}
	check_admin_referer( 'wppe_check_update' );
	delete_site_transient( 'wppe_github_release' );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	wp_safe_redirect( admin_url( 'plugins.php?wppe_checked=1' ) );
	exit;
} );

add_action( 'admin_notices', function () {
	if ( empty( $_GET['wppe_checked'] ) ) {
		return;
	}
	$release = wppe_github_latest();
	if ( $release && version_compare( $release['version'], WPPE_VERSION, '>' ) ) {
		$msg = 'WP Pro Post Expiry ' . esc_html( $release['version'] ) . ' is available — use "Update now" below.';
	} else {
		$msg = 'WP Pro Post Expiry is up to date (' . esc_html( WPPE_VERSION ) . ').';
	}
	echo '<div class="notice notice-info is-dismissible"><p>' . $msg . '</p></div>';
} );
