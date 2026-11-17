<?php
/**
 * Plugin Name: TopicMachine Publisher
 * Plugin URI: https://topicmachine.com/docs#wordpress
 * Description: Adds accessible article navigation and optional search metadata for TopicMachine articles on WordPress.
 * Version: 1.0.1
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: SyntaxSurge
 * Author URI: https://topicmachine.com/
 * License: GPL-2.0-or-later
 * Text Domain: topicmachine-publisher
 * Update URI: https://github.com/syntaxsurge/topicmachine-wordpress
 *
 * @package TopicMachinePublisher
 */

namespace TopicMachine\Publisher;

use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.0.1';
const RELEASE_URL = 'https://github.com/syntaxsurge/topicmachine-wordpress';
const RELEASE_API = 'https://api.github.com/repos/syntaxsurge/topicmachine-wordpress/releases';
const RELEASE_ASSET = 'topicmachine-publisher.zip';
const UPDATE_CACHE = 'topicmachine_publisher_release';
const MAX_RELEASE_BYTES = 2 * 1024 * 1024;

/**
 * Remove only TopicMachine's marked presentation blocks, retaining all other source bytes.
 */
function strip_article_chrome( string $html ): string {
	// Match the marked opener directly so an enclosing section cannot swallow it.
	$pattern = <<<'REGEX'
~<!--.*?-->(*SKIP)(*F)
|<(?<literal>script|style|pre|code|template)\b(?:"[^"]*"|'[^']*'|[^>"'])*>.*?</\k<literal>\s*>(*SKIP)(*F)
|<(?<tag>nav|p|aside|section|div)\b
 (?=[^>]*\sdata-block\s*=\s*["']?(?:article-meta|author-bio|breadcrumbs|toc)(?=["'\s>]))
 (?<attributes>(?:"[^"]*"|'[^']*'|[^>"'])*)>(?<body>.*?)</\k<tag>\s*>~isx
REGEX;

	return preg_replace_callback( $pattern, static function ( array $match ): string {
		$tag = strtolower( $match['tag'] );
		$opener = new WP_HTML_Tag_Processor( '<' . $tag . $match['attributes'] . '>' );
		$opener->next_tag();
		$block = $opener->get_attribute( 'data-block' );
		$expected = array(
			'nav'     => array( 'toc', 'breadcrumbs' ),
			'p'       => array( 'article-meta', 'author-bio' ),
			'aside'   => array( 'author-bio' ),
			'section' => array( 'author-bio' ),
			'div'     => array( 'author-bio' ),
		);
		if ( ! in_array( $block, $expected[ $tag ], true ) || preg_match( '~<' . $tag . '\b~i', $match['body'] ) ) {
			return $match[0];
		}
		return '';
	}, $html ) ?? $html;
}

/**
 * Collect H2s and give unanchored headings stable fragment targets.
 */
function add_contents( string $html, string $label ): string {
	$pattern = <<<'REGEX'
~<!--.*?-->(*SKIP)(*F)
|<(?<ignored>script|style|pre|code|template|nav|aside)\b(?:"[^"]*"|'[^']*'|[^>"'])*>.*?</\k<ignored>\s*>(*SKIP)(*F)
|<(?<utility>section|div)\b(?=[^>]*\sdata-block\s*=\s*["']?(?:sources|citations|infographic|quiz|poll|calculator|cta)(?=["'\s>]))(?:"[^"]*"|'[^']*'|[^>"'])*>.*?</\k<utility>\s*>(*SKIP)(*F)
|(?<opener><h2\b(?:"[^"]*"|'[^']*'|[^>"'])*>)(?<label>.*?)</h2\s*>~isx
REGEX;

	if ( ! preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return $html;
	}

	$ids = array();
	$rendered = new WP_HTML_Tag_Processor( $html );
	while ( $rendered->next_tag() ) {
		if ( 'doc-toc' === $rendered->get_attribute( 'role' ) ||
			( 'DETAILS' === $rendered->get_tag() &&
				( null !== $rendered->get_attribute( 'data-topicmachine-contents' ) ||
					null !== $rendered->get_attribute( 'data-me-contents' ) ) )
		) {
			return $html;
		}
		$id = $rendered->get_attribute( 'id' );
		if ( is_string( $id ) && '' !== $id ) {
			$ids[ $id ] = ( $ids[ $id ] ?? 0 ) + 1;
		}
	}

	$items = array();
	$headings = array();
	$insert_at = null;
	foreach ( $matches as $match ) {
		$title = html_entity_decode( wp_strip_all_tags( $match['label'][0] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title = trim( (string) preg_replace( '/\s+/u', ' ', $title ) );
		if ( '' === $title ) {
			continue;
		}

		list( $opening, $offset ) = $match['opener'];
		$heading = new WP_HTML_Tag_Processor( $opening );
		$heading->next_tag( 'H2' );
		if ( null !== $heading->get_attribute( 'hidden' ) || 'true' === $heading->get_attribute( 'aria-hidden' ) ) {
			continue;
		}
		$id = $heading->get_attribute( 'id' );
		if ( is_string( $id ) && ( $ids[ $id ] ?? 0 ) > 1 ) {
			// Leave ambiguous authored IDs intact and avoid a link to the wrong section.
			continue;
		}
		if ( ! is_string( $id ) || '' === $id ) {
			$base = 'section-' . ( rawurldecode( sanitize_title( $title ) ) ?: (string) ( count( $items ) + 1 ) );
			$id = $base;
			for ( $suffix = 2; isset( $ids[ $id ] ); ++$suffix ) {
				$id = $base . '-' . $suffix;
			}
			$ids[ $id ] = 1;
			$heading->set_attribute( 'id', $id );
			$headings[] = array( $heading->get_updated_html(), $offset, strlen( $opening ) );
		}
		$insert_at ??= $offset;
		$items[] = sprintf( '<li><a href="#%s">%s</a></li>', rawurlencode( $id ), esc_html( $title ) );
	}

	if ( count( $items ) < 3 ) {
		return $html;
	}
	foreach ( array_reverse( $headings ) as list( $opening, $offset, $length ) ) {
		$html = substr_replace( $html, $opening, $offset, $length );
	}

	$contents = '<details class="tm-contents" data-topicmachine-contents><summary>' . esc_html( $label ) .
		'</summary><nav role="doc-toc" aria-label="' . esc_attr( $label ) . '"><ul>' . implode( '', $items ) . '</ul></nav></details>';
	return substr_replace( $html, $contents . "\n", $insert_at, 0 );
}

/**
 * Limit enhancements to the actual public post or page being requested.
 */
function public_entry_id(): int {
	if ( is_admin() || is_feed() || is_preview() || is_front_page() ||
		( defined( 'REST_REQUEST' ) && REST_REQUEST ) ||
		! is_singular( array( 'post', 'page' ) )
	) {
		return 0;
	}

	$post_id = (int) get_queried_object_id();
	return $post_id && 'publish' === get_post_status( $post_id ) && ! post_password_required( $post_id )
		? $post_id
		: 0;
}

function is_current_entry_content( int $post_id ): bool {
	return $post_id && in_the_loop() && is_main_query() && ! doing_filter( 'get_the_excerpt' ) &&
		$post_id === (int) get_the_ID();
}

function render_contents( string $content ): string {
	$post_id = public_entry_id();
	if ( ! is_current_entry_content( $post_id ) ) {
		return $content;
	}

	$language = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : null;
	$is_arabic = $language ? 'ar' === $language : str_starts_with( get_locale(), 'ar' );

	/** Filter the Contents disclosure label for a localized post. */
	$label = apply_filters(
		'topicmachine_publisher_contents_label',
		$is_arabic ? 'محتويات الصفحة' : __( 'Contents', 'topicmachine-publisher' ),
		$post_id
	);
	return add_contents( strip_article_chrome( $content ), (string) $label );
}
add_filter( 'the_content', __NAMESPACE__ . '\render_contents', 14 );

function enqueue_styles(): void {
	if ( public_entry_id() ) {
		wp_enqueue_style( 'topicmachine-publisher', plugins_url( 'assets/publisher.css', __FILE__ ), array(), VERSION );
	}
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_styles' );

/**
 * Only supplement the public head of a post published by TopicMachine.
 */
function managed_post_id(): int {
	$post_id = public_entry_id();
	if ( ! $post_id || 'post' !== get_post_type( $post_id ) ) {
		return 0;
	}

	$content = (string) get_post_field( 'post_content', $post_id, 'raw' );
	return preg_match( '~<!--\s*topicmachine-publication:[a-f0-9]{32}\s*-->~', $content )
		? $post_id
		: 0;
}

/**
 * Collapse formatting newlines in prose before wpautop can insert hard breaks.
 * Match literal elements and tags as complete tokens to preserve their bytes.
 */
function collapse_paragraph_newlines( string $html ): string {
	if ( ! str_contains( $html, "\n" ) && ! str_contains( $html, "\r" ) ) {
		return $html;
	}

	$tokens = <<<'REGEX'
~<!--.*?(?:-->|$)
|<(?<literal>script|style|pre|code|textarea|template|svg)\b(?:"[^"]*"|'[^']*'|[^<>"'])*>.*?(?:</\k<literal>\s*>|$)
|<(?<closing>/)?(?<tag>[a-z][a-z0-9:-]*)(?=[\s/>])(?:"[^"]*"|'[^']*'|[^<>"'])*>
|(?<text>[^<]+)~isx
REGEX;
	$in_paragraph = false;
	$widget_tag   = '';
	$widget_depth = 0;

	return preg_replace_callback( $tokens, static function ( array $token ) use ( &$in_paragraph, &$widget_tag, &$widget_depth ): string {
		$tag     = strtolower( $token['tag'] ?? '' );
		$closing = ! empty( $token['closing'] );
		if ( '' !== $widget_tag ) {
			if ( $widget_tag === $tag ) {
				$widget_depth += $closing ? -1 : 1;
				if ( 0 === $widget_depth ) {
					$widget_tag = '';
				}
			}
			return $token[0];
		}

		if ( '' !== $tag && ! $closing && preg_match( '~\sdata-shortcode(?:-source)?(?=[\s=>])~i', $token[0] ) ) {
			$opener = new WP_HTML_Tag_Processor( $token[0] );
			$opener->next_tag();
			if ( null !== $opener->get_attribute( 'data-shortcode' ) || null !== $opener->get_attribute( 'data-shortcode-source' ) ) {
				$widget_tag   = $tag;
				$widget_depth = 1;
				return $token[0];
			}
		}
		if ( 'p' === $tag ) {
			$in_paragraph = ! $closing;
		}

		return $in_paragraph && isset( $token['text'] )
			? preg_replace( '~[ \t\f\r]*[\r\n][ \t\f\r\n]*~', ' ', $token['text'] ) ?? $token['text']
			: $token[0];
	}, $html ) ?? $html;
}

function normalize_published_paragraphs( string $content ): string {
	return is_current_entry_content( managed_post_id() ) ? collapse_paragraph_newlines( $content ) : $content;
}
add_filter( 'the_content', __NAMESPACE__ . '\normalize_published_paragraphs', 8 );

function excerpt( int $post_id ): string {
	return trim( wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_excerpt', $post_id, 'raw' ) ), true ) );
}

/**
 * Keep editorial overrides owned by Yoast. A saved excerpt fills its fallback.
 */
function yoast_description( $value ) {
	$post_id = managed_post_id();
	if ( ! is_string( $value ) || ! $post_id ||
		'' !== trim( (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) )
	) {
		return $value;
	}

	return excerpt( $post_id ) ?: $value;
}
add_filter( 'wpseo_metadesc', __NAMESPACE__ . '\yoast_description' );
add_filter( 'wpseo_opengraph_desc', __NAMESPACE__ . '\yoast_description' );
add_filter( 'wpseo_twitter_description', __NAMESPACE__ . '\yoast_description' );

function render_metadata(): void {
	$post_id = managed_post_id();
	if ( ! $post_id ) {
		return;
	}

	$seo_owner_present = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) ||
		defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) ||
		defined( 'THE_SEO_FRAMEWORK_VERSION' ) || defined( 'JETPACK__VERSION' );
	/**
	 * Set this false when a theme or another SEO integration already emits these tags.
	 */
	if ( ! apply_filters( 'topicmachine_publisher_fallback_metadata', ! $seo_owner_present, $post_id ) ) {
		return;
	}

	$permalink = get_permalink( $post_id );
	if ( ! $permalink ) {
		return;
	}

	$description = excerpt( $post_id );
	$title       = wp_strip_all_tags( get_the_title( $post_id ), true );
	$image_id    = (int) get_post_thumbnail_id( $post_id );
	$image       = $image_id ? wp_get_attachment_image_src( $image_id, 'full' ) : false;
	$alt         = $image_id ? (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';
	$published   = get_post_datetime( $post_id, 'date', 'gmt' );
	$modified    = get_post_datetime( $post_id, 'modified', 'gmt' );
	$properties  = array(
		'og:type'             => 'article',
		'og:title'            => $title,
		'og:description'      => $description,
		'og:url'              => $permalink,
		'og:site_name'        => get_bloginfo( 'name' ),
		'og:image'            => $image ? $image[0] : '',
		'og:image:width'      => $image ? (string) $image[1] : '',
		'og:image:height'     => $image ? (string) $image[2] : '',
		'og:image:alt'        => $image ? $alt : '',
		'article:published_time' => $published ? $published->format( DATE_W3C ) : '',
		'article:modified_time'  => $modified ? $modified->format( DATE_W3C ) : '',
	);
	$names       = array(
		'description'         => $description,
		'twitter:card'        => $image ? 'summary_large_image' : 'summary',
		'twitter:title'       => $title,
		'twitter:description' => $description,
		'twitter:image'       => $image ? $image[0] : '',
		'twitter:image:alt'   => $image ? $alt : '',
	);

	foreach ( array( 'property' => $properties, 'name' => $names ) as $attribute => $metadata ) {
		foreach ( $metadata as $key => $value ) {
			if ( '' !== $value ) {
				printf( "<meta %s=\"%s\" content=\"%s\">\n", esc_attr( $attribute ), esc_attr( $key ), esc_attr( $value ) );
			}
		}
	}
}
add_action( 'wp_head', __NAMESPACE__ . '\render_metadata' );

/**
 * A core WordPress update is offered only for a verified release asset.
 *
 * @return array{version:string,url:string,package:string,digest:string,size:int}|null
 */
function parse_release( array $release ): ?array {
	$tag = $release['tag_name'] ?? null;
	if ( ! is_string( $tag ) || ! preg_match( '~\Av(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z~D', $tag ) ||
		strlen( $tag ) > 48 ||
		! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ||
		! is_array( $release['assets'] ?? null )
	) {
		return null;
	}

	$url = RELEASE_URL . '/releases/tag/' . $tag;
	$package = RELEASE_URL . '/releases/download/' . $tag . '/' . RELEASE_ASSET;
	foreach ( $release['assets'] as $asset ) {
		if ( ! is_array( $asset ) || RELEASE_ASSET !== ( $asset['name'] ?? null ) ||
			$package !== ( $asset['browser_download_url'] ?? null ) ||
			! is_int( $asset['size'] ?? null ) || $asset['size'] < 1 || $asset['size'] > MAX_RELEASE_BYTES ||
			! is_string( $asset['digest'] ?? null ) || ! preg_match( '~\Asha256:([a-f0-9]{64})\z~D', $asset['digest'], $digest )
		) {
			continue;
		}

		return array( 'version' => substr( $tag, 1 ), 'url' => $url, 'package' => $package, 'digest' => $digest[1], 'size' => $asset['size'] );
	}

	return null;
}

function fetch_release( string $path = 'latest' ): ?array {
	$response = wp_safe_remote_get(
		RELEASE_API . '/' . $path,
		array(
			'timeout' => 6,
			'redirection' => 0,
			'limit_response_size' => 128 * 1024,
			'headers' => array( 'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28' ),
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$release = json_decode( wp_remote_retrieve_body( $response ), true, 16 );
	return is_array( $release ) ? parse_release( $release ) : null;
}

function available_update( $update, array $plugin_data, string $plugin_file ) {
	if ( plugin_basename( __FILE__ ) !== $plugin_file || RELEASE_URL !== ( $plugin_data['UpdateURI'] ?? null ) ) {
		return $update;
	}

	$release = get_site_transient( UPDATE_CACHE );
	if ( false === $release ) {
		$release = fetch_release();
		set_site_transient( UPDATE_CACHE, $release ?? array(), $release ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
	}
	if ( empty( $release ) || ! version_compare( $release['version'], $plugin_data['Version'] ?? VERSION, '>' ) ) {
		return $update;
	}

	return array(
		'id'           => RELEASE_URL,
		'slug'         => 'topicmachine-publisher',
		'version'      => $release['version'],
		'url'          => $release['url'],
		'package'      => $release['package'],
		'requires'     => $plugin_data['RequiresWP'] ?? '6.4',
		'requires_php' => $plugin_data['RequiresPHP'] ?? '8.1',
	);
}
add_filter( 'update_plugins_github.com', __NAMESPACE__ . '\available_update', 10, 3 );

function plugin_information( $result, string $action, object $args ) {
	if ( 'plugin_information' !== $action || 'topicmachine-publisher' !== ( $args->slug ?? null ) ) {
		return $result;
	}

	$release = get_site_transient( UPDATE_CACHE );
	$notes   = $release['url'] ?? RELEASE_URL . '/releases';
	return (object) array(
		'name'         => 'TopicMachine Publisher',
		'slug'         => 'topicmachine-publisher',
		'version'      => $release['version'] ?? VERSION,
		'author'       => 'SyntaxSurge',
		'homepage'     => 'https://topicmachine.com/docs',
		'requires'     => '6.4',
		'requires_php' => '8.1',
		'sections'     => array(
			'description' => esc_html__( 'Accessible contents navigation and search metadata for WordPress articles.', 'topicmachine-publisher' ),
			'changelog'   => sprintf( '<a href="%s">%s</a>', esc_url( $notes ), esc_html__( 'Read the release notes on GitHub', 'topicmachine-publisher' ) ),
		),
	);
}
add_filter( 'plugins_api', __NAMESPACE__ . '\plugin_information', 10, 3 );


/**
 * Verify a specific tagged zip immediately before WordPress installs it.
 */
function verify_download( $reply, string $package ) {
	if ( false !== $reply || ! preg_match(
		'~\A' . preg_quote( RELEASE_URL . '/releases/download/', '~' ) . '(v[0-9]+\.[0-9]+\.[0-9]+)/' . preg_quote( RELEASE_ASSET, '~' ) . '\z~D',
		$package,
		$match
	) ) {
		return $reply;
	}

	$release = fetch_release( 'tags/' . $match[1] );
	if ( ! $release || $package !== $release['package'] ) {
		return new \WP_Error( 'topicmachine_release_unverified', __( 'The TopicMachine release could not be verified. Try the update again later.', 'topicmachine-publisher' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	$file = wp_tempnam( RELEASE_ASSET );
	if ( ! $file ) {
		return new \WP_Error( 'topicmachine_release_tempfile', __( 'WordPress could not create a temporary file for the TopicMachine update.', 'topicmachine-publisher' ) );
	}

	$response = wp_safe_remote_get(
		$package,
		array(
			'timeout' => 30,
			'redirection' => 3,
			'stream' => true,
			'filename' => $file,
			'limit_response_size' => $release['size'] + 1,
		)
	);
	$digest = is_readable( $file ) ? hash_file( 'sha256', $file ) : false;
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ||
		$release['size'] !== filesize( $file ) || ! is_string( $digest ) || ! hash_equals( $release['digest'], $digest )
	) {
		wp_delete_file( $file );
		return new \WP_Error( 'topicmachine_release_checksum', __( 'The TopicMachine update did not match its published checksum.', 'topicmachine-publisher' ) );
	}

	return $file;
}
add_filter( 'upgrader_pre_download', __NAMESPACE__ . '\verify_download', 10, 2 );

function update_action_link( array $actions ): array {
	if ( current_user_can( 'update_plugins' ) ) {
		$actions['topicmachine_update'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( wp_nonce_url( self_admin_url( 'admin-post.php?action=topicmachine_check_updates' ), 'topicmachine_check_updates' ) ),
			esc_html__( 'Check for updates', 'topicmachine-publisher' )
		);
	}
	return $actions;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\update_action_link' );
add_filter( 'network_admin_plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\update_action_link' );

function check_updates(): void {
	if ( ! current_user_can( 'update_plugins' ) ) {
		wp_die( esc_html__( 'You cannot check for plugin updates.', 'topicmachine-publisher' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'topicmachine_check_updates' );
	delete_site_transient( UPDATE_CACHE );
	wp_clean_plugins_cache( true );
	wp_update_plugins();
	wp_safe_redirect( self_admin_url( 'update-core.php' ) );
	exit;
}
add_action( 'admin_post_topicmachine_check_updates', __NAMESPACE__ . '\check_updates' );
