<?php
namespace Podlove\Feeds;
use \Podlove\Model;

function handle_feed_proxy_redirects() {

	$paged = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;

	$is_feedburner_bot = isset( $_SERVER['HTTP_USER_AGENT'] ) && preg_match( "/feedburner|feedsqueezer/i", $_SERVER['HTTP_USER_AGENT'] );
	$is_manual_redirect = ! isset( $_REQUEST['redirect'] ) || $_REQUEST['redirect'] != "no";
	$is_feed_page = $paged > 1;
	$feed = Model\Feed::find_one_by_slug( get_query_var( 'feed' ) );

	if ( ! $feed )
		return;

	// most HTTP/1.0 client's don't understand 307, so we fall back to 302
	$http_status_code = $_SERVER['SERVER_PROTOCOL'] == "HTTP/1.0" ? 302 : $feed->redirect_http_status;

	if ( ! $is_feed_page && strlen( $feed->redirect_url ) > 0 && $is_manual_redirect && ! $is_feedburner_bot && $http_status_code > 0 ) {
		header( sprintf( "Location: %s", $feed->redirect_url ), TRUE, $http_status_code );
		exit;
	} else { // don't redirect; prepare feed
		status_header(200);
		RSS::prepare_feed( $feed->slug );
	}

}

# Prio 11 so it hooks *after* the domain mapping plugin.
# This is important when one moves a domain. That way the domain gets
# remapped/redirected correctly by the domain mapper before being redirected by us.
add_action( 'template_redirect', '\Podlove\Feeds\handle_feed_proxy_redirects', 11 );

function generate_podcast_feed() {	
	remove_podPress_hooks();
	remove_powerPress_hooks();
	RSS::render();
}

add_action( 'init', function() {

	foreach ( Model\Feed::all() as $feed ) {
		add_feed( $feed->slug,  "\Podlove\Feeds\generate_podcast_feed" );
	}

	if ( isset( $_REQUEST['page'] ) && $_REQUEST['page'] == 'podlove_feeds_settings_handle' ) {
		flush_rewrite_rules();
	}

} );

function override_feed_item_limit( $limits ) {
	global $wp_query;

	if ( ! is_feed() )
		return $limits;

	if ( ! $feed = \Podlove\Model\Feed::find_one_by_slug( get_query_var( 'feed_slug' ) ) )
		return $limits;

	$custom_limit = (int) $feed->limit_items;

	if ( $custom_limit > 0 ) {
		return "LIMIT $custom_limit";	
	} elseif ( $custom_limit == 0 ) {
		return $limits; // WordPress default
	} else {
		return ''; // no limit
	}
}
add_filter( 'post_limits', '\Podlove\Feeds\override_feed_item_limit', 20, 1 );

/**
 * Make sure that PodPress doesn't vomit anything into our precious feeds
 * in case it is still active.
 */
function remove_podPress_hooks() {
	remove_filter( 'option_blogname', 'podPress_feedblogname' );
	remove_filter( 'option_blogdescription', 'podPress_feedblogdescription' );
	remove_filter( 'option_rss_language', 'podPress_feedblogrsslanguage' );
	remove_filter( 'option_rss_image', 'podPress_feedblogrssimage' );
	remove_action( 'rss2_ns', 'podPress_rss2_ns' );
	remove_action( 'rss2_head', 'podPress_rss2_head' );
	remove_filter( 'rss_enclosure', 'podPress_dont_print_nonpodpress_enclosures' );
	remove_action( 'rss2_item', 'podPress_rss2_item' );
	remove_action( 'atom_head', 'podPress_atom_head' );
	remove_filter( 'atom_enclosure', 'podPress_dont_print_nonpodpress_enclosures' );
	remove_action( 'atom_entry', 'podPress_atom_entry' );
}

function remove_powerPress_hooks() {
	remove_action( 'rss2_ns', 'powerpress_rss2_ns' );
	remove_action( 'rss2_head', 'powerpress_rss2_head' );
	remove_action( 'rss2_item', 'powerpress_rss2_item' );
}
