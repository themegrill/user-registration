<?php
/** Feed access regression; run wp eval-file FILE MODE on disposable WordPress. @package UserRegistration/Tests */
if ( '1' !== getenv( 'UR_SECURITY_DISPOSABLE' ) ) { throw new RuntimeException( 'Disposable install required.' ); }
$count = 0;
$check = function ( $value, $message ) use ( &$count ) { if ( ! $value ) { throw new RuntimeException( $message ); } ++$count; };
$mode = $args[0] ?? 'legacy';
$ids = array();
$user_id = wp_insert_user( array( 'user_login' => 'feeds-' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$original_options = array();
foreach ( array( 'user_registration_content_restriction_enable', 'user_registration_content_restriction_whole_site_access', 'user_registration_content_restriction_allow_access_to' ) as $key ) { $original_options[$key] = get_option($key); }
try {
	update_option( 'user_registration_content_restriction_enable', 'yes' );
	update_option( 'user_registration_content_restriction_whole_site_access', 'legacy' === $mode ? 'yes' : 'no' );
	update_option( 'user_registration_content_restriction_allow_access_to', '0' );
	$id = wp_insert_post( array( 'post_title' => 'SECRETTITLE-8844', 'post_content' => 'SECRETDATA-8844 internal strategy', 'post_excerpt' => 'SECRETEXCERPT-8844', 'post_status' => 'publish', 'post_type' => 'post', 'post_author' => 1 ) );
	$ids[] = $id;
	$public_id = wp_insert_post( array( 'post_title' => 'Public fixture', 'post_content' => 'PUBLICDATA-8844', 'post_status' => 'publish' ) );
	$ids[] = $public_id;
	$comment_id = wp_insert_comment( array( 'comment_post_ID' => $id, 'comment_content' => 'SECRETCOMMENT-8844', 'comment_approved' => 1 ) );
	$public_comment_id = wp_insert_comment( array( 'comment_post_ID' => $public_id, 'comment_content' => 'PUBLICCOMMENT-8844', 'comment_approved' => 1 ) );
	if ( 'per-post' === $mode ) {
		update_post_meta( $id, 'urcr_meta_override_global_settings', 'on' );
		update_post_meta( $id, 'urcr_allow_to', '0' );
	} elseif ( 'legacy' !== $mode ) {
		$target = array( 'type' => 'wp_posts', 'value' => array( (string) $id ) );
		if ( 'whole-site' === $mode ) { $target = array( 'type' => 'whole_site' ); }
		if ( 'post-type' === $mode ) { $target = array( 'type' => 'post_types', 'value' => array( 'post' ) ); }
		$condition = array( 'type' => 'user_state', 'value' => 'logged-in' );
		if ( 'membership' === $mode ) {
			$plan_id = wp_insert_post( array( 'post_type' => 'ur_membership', 'post_status' => 'publish', 'post_title' => 'Feed membership', 'post_content' => '{"status":true}' ) );
			$ids[] = $plan_id;
			update_post_meta( $plan_id, 'ur_membership', '{}' );
			$condition = array( 'type' => 'membership', 'value' => array( (string) $plan_id ) );
		}
		$rule = array( 'enabled' => true, 'target_contents' => array( $target ), 'logic_map' => array( 'type' => 'group', 'logic_gate' => 'AND', 'conditions' => array( $condition ) ), 'actions' => array( array( 'type' => 'message', 'access_control' => 'access', 'message' => 'Restricted' ) ) );
		$ids[] = wp_insert_post( array( 'post_type' => 'urcr_access_rule', 'post_status' => 'publish', 'post_title' => 'Feed fixture rule', 'post_content' => wp_slash( wp_json_encode( $rule ) ) ) );
	}
	wp_set_current_user( 0 );
	$check( ! urcr_is_content_access_granted( $id ), $mode . ' access fixture denies anonymous readers' );
	global $wp_query, $post;
	$base = array( 'post__in' => array( $id, $public_id ), 'post_type' => 'post', 'post_status' => 'publish' );
	foreach ( array( 'rss', 'rss2', 'rdf', 'atom' ) as $format ) {
		$wp_query = new WP_Query( array_merge( $base, array( 'feed' => $format ) ) );
		$check( ! in_array( $id, wp_list_pluck( $wp_query->posts, 'ID' ), true ), $format . ' excludes restricted item including title/guid' );
		$public_allowed = in_array( $mode, array( 'per-post', 'membership' ), true );
		$check( $public_allowed === in_array( $public_id, wp_list_pluck( $wp_query->posts, 'ID' ), true ), 'Public items retain their access decision' );
		$callback = 'do_feed_' . $format;
		ob_start(); $callback( false ); $xml = ob_get_clean();
		$check( $public_allowed === str_contains( $xml, 'PUBLICDATA-8844' ), 'Public content retains its access decision in XML' );
		$check( ! str_contains( $xml, 'SECRETDATA-8844' ) && ! str_contains( $xml, 'SECRETEXCERPT-8844' ) && ! str_contains( $xml, 'SECRETTITLE-8844' ), $format . ' XML contains no restricted fields' );
	}
	foreach ( array( array( 'cat' => 1 ), array( 'author' => 1 ), array( 'tag' => 'fixture' ) ) as $archive ) {
		wp_set_post_tags( $id, 'fixture' );
		$query = new WP_Query( array_merge( $base, $archive, array( 'feed' => 'rss2' ) ) );
		$check( ! in_array( $id, wp_list_pluck( $query->posts, 'ID' ), true ), 'Archive feed excludes restricted post' );
	}
	foreach ( array( array(), array( 'p' => $id ) ) as $scope ) {
		foreach ( array( 'rss2', 'atom' ) as $format ) {
			$wp_query = new WP_Query( array_merge( $scope, array( 'feed' => $format, 'withcomments' => 1 ) ) );
			URCR_Syndication_Restriction::filter_feed_comments();
			$check( ! in_array( (string) $comment_id, array_map( 'strval', wp_list_pluck( (array) $wp_query->comments, 'comment_ID' ) ), true ), 'Global/per-post comment feed removes protected comments' );
			ob_start(); ( 'rss2' === $format ? 'do_feed_rss2' : 'do_feed_atom' )( true ); $xml = ob_get_clean();
			$check( ! str_contains( $xml, 'SECRETCOMMENT-8844' ), 'Comment feed XML does not expose protected comments' );
		}
	}
	$post = get_post( $id );
	$check( '' === apply_filters( 'the_content_feed', $post->post_content ), 'Feed-content fallback blocks direct rendering' );
	$check( '' === apply_filters( 'the_excerpt_rss', $post->post_excerpt ), 'Feed-excerpt fallback blocks direct rendering' );
	$check( false === get_oembed_response_data( $id, 600 ), 'oEmbed data denied' );
	$sitemap = new WP_Query( array_merge( $base, apply_filters( 'wp_sitemaps_posts_query_args', array() ) ) );
	$check( ! in_array( $id, wp_list_pluck( $sitemap->posts, 'ID' ), true ), 'Sitemap excludes restricted item' );
	$search = new WP_Query( array( 's' => 'SECRETTITLE-8844' ) );
	$check( ! in_array( $id, wp_list_pluck( $search->posts, 'ID' ), true ), 'Search excludes restricted item' );
	wp_set_current_user( $user_id );
	$query = new WP_Query( array_merge( $base, array( 'feed' => 'rss2' ) ) );
	$check( ( 'membership' !== $mode ) === in_array( $id, wp_list_pluck( $query->posts, 'ID' ), true ), 'Eligible readers retain access; nonmembers remain denied' );
	wp_set_current_user( 1 );
	$query = new WP_Query( array_merge( $base, array( 'feed' => 'rss2' ) ) );
	$check( in_array( $id, wp_list_pluck( $query->posts, 'ID' ), true ), 'Admin access preserved' );
	update_option( 'user_registration_content_restriction_enable', 'no' ); wp_set_current_user( 0 );
	$query = new WP_Query( array_merge( $base, array( 'feed' => 'rss2' ) ) );
	$check( in_array( $id, wp_list_pluck( $query->posts, 'ID' ), true ), 'Disabled restriction preserves feed' );
} finally {
	wp_set_current_user( 0 );
	foreach ( $original_options as $key => $value ) { update_option( $key, $value ); }
	foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user_id );
}
echo $count . " assertions passed ($mode)\n";
