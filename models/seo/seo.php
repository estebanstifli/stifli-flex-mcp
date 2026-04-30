<?php
/**
 * Unified SEO Meta integration.
 *
 * Auto-detects the active SEO plugin (Yoast SEO, Rank Math, All in One SEO)
 * and exposes a single read/write API so MCP tools (`wp_get_seo_meta`,
 * `wp_update_seo_meta`) can stay provider-agnostic.
 *
 * Mirrors the multi-provider pattern used by `models/snippets/snippets.php`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- StifliFlexMcp is the plugin prefix.
class StifliFlexMcp_Seo {

	/**
	 * Detect which SEO plugin is active.
	 *
	 * @return string 'yoast'|'rank-math'|'aioseo'|'none'
	 */
	public static function detectProvider() {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || function_exists( 'rank_math' ) ) {
			return 'rank-math';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) || class_exists( 'AIOSEO\\Plugin\\AIOSEO' ) ) {
			return 'aioseo';
		}
		return 'none';
	}

	/**
	 * Field map per provider. Key = unified field, value = post meta key.
	 *
	 * @param string $provider
	 * @return array
	 */
	private static function fieldMap( $provider ) {
		switch ( $provider ) {
			case 'yoast':
				return array(
					'title'                => '_yoast_wpseo_title',
					'description'          => '_yoast_wpseo_metadesc',
					'focus_keyword'        => '_yoast_wpseo_focuskw',
					'canonical'            => '_yoast_wpseo_canonical',
					'noindex'              => '_yoast_wpseo_meta-robots-noindex',
					'nofollow'             => '_yoast_wpseo_meta-robots-nofollow',
					'facebook_title'       => '_yoast_wpseo_opengraph-title',
					'facebook_description' => '_yoast_wpseo_opengraph-description',
					'facebook_image'       => '_yoast_wpseo_opengraph-image',
					'twitter_title'        => '_yoast_wpseo_twitter-title',
					'twitter_description'  => '_yoast_wpseo_twitter-description',
					'twitter_image'        => '_yoast_wpseo_twitter-image',
				);
			case 'rank-math':
				return array(
					'title'                => 'rank_math_title',
					'description'          => 'rank_math_description',
					'focus_keyword'        => 'rank_math_focus_keyword',
					'canonical'            => 'rank_math_canonical_url',
					'noindex'              => 'rank_math_robots', // array; handled specially
					'nofollow'             => 'rank_math_robots', // array; handled specially
					'facebook_title'       => 'rank_math_facebook_title',
					'facebook_description' => 'rank_math_facebook_description',
					'facebook_image'       => 'rank_math_facebook_image',
					'twitter_title'        => 'rank_math_twitter_title',
					'twitter_description'  => 'rank_math_twitter_description',
					'twitter_image'        => 'rank_math_twitter_image',
				);
			case 'aioseo':
				return array(
					'title'                => '_aioseo_title',
					'description'          => '_aioseo_description',
					'focus_keyword'        => '_aioseo_keyphrases',
					'canonical'            => '_aioseo_canonical_url',
					'noindex'              => '_aioseo_robots_noindex',
					'nofollow'             => '_aioseo_robots_nofollow',
					'facebook_title'       => '_aioseo_og_title',
					'facebook_description' => '_aioseo_og_description',
					'facebook_image'       => '_aioseo_og_image_custom_url',
					'twitter_title'        => '_aioseo_twitter_title',
					'twitter_description'  => '_aioseo_twitter_description',
					'twitter_image'        => '_aioseo_twitter_image_custom_url',
				);
		}
		return array();
	}

	/**
	 * Read unified SEO meta for a post.
	 *
	 * @param int $post_id
	 * @return array|WP_Error
	 */
	public static function getMeta( $post_id ) {
		$provider = self::detectProvider();
		if ( 'none' === $provider ) {
			return new WP_Error( 'no_seo_plugin', 'No supported SEO plugin detected (Yoast, Rank Math, AIOSEO).' );
		}
		$post_id = intval( $post_id );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'invalid_post', 'Invalid post_id.' );
		}

		$out = array(
			'post_id'  => $post_id,
			'provider' => $provider,
		);

		// AIOSEO 4.x stores most data in a custom table; try its API first when available.
		if ( 'aioseo' === $provider ) {
			$aioseo_row = self::aioseoFetchRow( $post_id );
			if ( is_array( $aioseo_row ) ) {
				$out = array_merge( $out, $aioseo_row );
				return $out;
			}
		}

		$map = self::fieldMap( $provider );

		if ( 'rank-math' === $provider ) {
			$rm_robots = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( ! is_array( $rm_robots ) ) {
				$rm_robots = array();
			}
			$seen_robots = false;
			foreach ( $map as $unified => $meta_key ) {
				if ( 'noindex' === $unified ) {
					$out[ $unified ] = in_array( 'noindex', $rm_robots, true );
					$seen_robots     = true;
					continue;
				}
				if ( 'nofollow' === $unified ) {
					$out[ $unified ] = in_array( 'nofollow', $rm_robots, true );
					continue;
				}
				$out[ $unified ] = get_post_meta( $post_id, $meta_key, true );
			}
			if ( ! $seen_robots ) {
				$out['noindex']  = false;
				$out['nofollow'] = false;
			}
			return $out;
		}

		foreach ( $map as $unified => $meta_key ) {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( in_array( $unified, array( 'noindex', 'nofollow' ), true ) ) {
				$value = ( '1' === (string) $value || 1 === $value || true === $value );
			}
			$out[ $unified ] = $value;
		}

		return $out;
	}

	/**
	 * Write unified SEO meta. Returns list of updated unified field names.
	 *
	 * @param int   $post_id
	 * @param array $args  Unified field => value pairs.
	 * @return array|WP_Error
	 */
	public static function setMeta( $post_id, $args ) {
		$provider = self::detectProvider();
		if ( 'none' === $provider ) {
			return new WP_Error( 'no_seo_plugin', 'No supported SEO plugin detected (Yoast, Rank Math, AIOSEO).' );
		}
		$post_id = intval( $post_id );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'invalid_post', 'Invalid post_id.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'permission_denied', 'You do not have permission to edit this post.' );
		}

		$url_fields  = array( 'canonical', 'facebook_image', 'twitter_image' );
		$bool_fields = array( 'noindex', 'nofollow' );
		$updated     = array();

		// Rank Math packs noindex/nofollow into a single array meta.
		if ( 'rank-math' === $provider ) {
			$rm_robots          = get_post_meta( $post_id, 'rank_math_robots', true );
			$rm_robots          = is_array( $rm_robots ) ? $rm_robots : array();
			$robots_changed     = false;
			$rm_simple_map      = array(
				'title'                => 'rank_math_title',
				'description'          => 'rank_math_description',
				'focus_keyword'        => 'rank_math_focus_keyword',
				'canonical'            => 'rank_math_canonical_url',
				'facebook_title'       => 'rank_math_facebook_title',
				'facebook_description' => 'rank_math_facebook_description',
				'facebook_image'       => 'rank_math_facebook_image',
				'twitter_title'        => 'rank_math_twitter_title',
				'twitter_description'  => 'rank_math_twitter_description',
				'twitter_image'        => 'rank_math_twitter_image',
			);
			foreach ( $rm_simple_map as $unified => $meta_key ) {
				if ( ! array_key_exists( $unified, $args ) ) {
					continue;
				}
				$value = in_array( $unified, $url_fields, true )
					? esc_url_raw( $args[ $unified ] )
					: sanitize_text_field( (string) $args[ $unified ] );
				update_post_meta( $post_id, $meta_key, $value );
				$updated[] = $unified;
			}
			foreach ( $bool_fields as $bf ) {
				if ( ! array_key_exists( $bf, $args ) ) {
					continue;
				}
				$want = (bool) $args[ $bf ];
				$has  = in_array( $bf, $rm_robots, true );
				if ( $want && ! $has ) {
					$rm_robots[]    = $bf;
					$robots_changed = true;
				} elseif ( ! $want && $has ) {
					$rm_robots      = array_values( array_diff( $rm_robots, array( $bf ) ) );
					$robots_changed = true;
				}
				$updated[] = $bf;
			}
			if ( $robots_changed ) {
				update_post_meta( $post_id, 'rank_math_robots', $rm_robots );
			}
			return $updated;
		}

		$map = self::fieldMap( $provider );
		foreach ( $map as $unified => $meta_key ) {
			if ( ! array_key_exists( $unified, $args ) ) {
				continue;
			}
			if ( in_array( $unified, $bool_fields, true ) ) {
				$value = $args[ $unified ] ? '1' : '0';
			} elseif ( in_array( $unified, $url_fields, true ) ) {
				$value = esc_url_raw( $args[ $unified ] );
			} else {
				$value = sanitize_text_field( (string) $args[ $unified ] );
			}
			update_post_meta( $post_id, $meta_key, $value );
			$updated[] = $unified;
		}

		// AIOSEO: also try to mirror to the custom DB table when available.
		if ( 'aioseo' === $provider && ! empty( $updated ) ) {
			self::aioseoMirrorRow( $post_id, $args );
		}

		return $updated;
	}

	/**
	 * Best-effort read from AIOSEO 4.x custom table.
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	private static function aioseoFetchRow( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		// phpcs:ignore WordPress.DB
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( $exists !== $table ) {
			return null;
		}
		// phpcs:ignore WordPress.DB
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT title, description, keyphrases, canonical_url, robots_noindex, robots_nofollow, og_title, og_description, og_image_custom_url, twitter_title, twitter_description, twitter_image_custom_url FROM `{$table}` WHERE post_id = %d", $post_id ), ARRAY_A );
		if ( ! $row ) {
			return array(
				'title'                => '',
				'description'          => '',
				'focus_keyword'        => '',
				'canonical'            => '',
				'noindex'              => false,
				'nofollow'             => false,
				'facebook_title'       => '',
				'facebook_description' => '',
				'facebook_image'       => '',
				'twitter_title'        => '',
				'twitter_description'  => '',
				'twitter_image'        => '',
			);
		}
		return array(
			'title'                => (string) $row['title'],
			'description'          => (string) $row['description'],
			'focus_keyword'        => (string) $row['keyphrases'],
			'canonical'            => (string) $row['canonical_url'],
			'noindex'              => ! empty( $row['robots_noindex'] ),
			'nofollow'             => ! empty( $row['robots_nofollow'] ),
			'facebook_title'       => (string) $row['og_title'],
			'facebook_description' => (string) $row['og_description'],
			'facebook_image'       => (string) $row['og_image_custom_url'],
			'twitter_title'        => (string) $row['twitter_title'],
			'twitter_description'  => (string) $row['twitter_description'],
			'twitter_image'        => (string) $row['twitter_image_custom_url'],
		);
	}

	/**
	 * Best-effort write into AIOSEO 4.x custom table. Silent if not available.
	 *
	 * @param int   $post_id
	 * @param array $args
	 * @return void
	 */
	private static function aioseoMirrorRow( $post_id, $args ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		// phpcs:ignore WordPress.DB
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( $exists !== $table ) {
			return;
		}
		$col_map = array(
			'title'                => 'title',
			'description'          => 'description',
			'focus_keyword'        => 'keyphrases',
			'canonical'            => 'canonical_url',
			'noindex'              => 'robots_noindex',
			'nofollow'             => 'robots_nofollow',
			'facebook_title'       => 'og_title',
			'facebook_description' => 'og_description',
			'facebook_image'       => 'og_image_custom_url',
			'twitter_title'        => 'twitter_title',
			'twitter_description'  => 'twitter_description',
			'twitter_image'        => 'twitter_image_custom_url',
		);
		$data    = array();
		$formats = array();
		foreach ( $col_map as $unified => $col ) {
			if ( ! array_key_exists( $unified, $args ) ) {
				continue;
			}
			if ( in_array( $unified, array( 'noindex', 'nofollow' ), true ) ) {
				$data[ $col ]   = $args[ $unified ] ? 1 : 0;
				$formats[]      = '%d';
			} elseif ( in_array( $unified, array( 'canonical', 'facebook_image', 'twitter_image' ), true ) ) {
				$data[ $col ]   = esc_url_raw( $args[ $unified ] );
				$formats[]      = '%s';
			} else {
				$data[ $col ]   = sanitize_text_field( (string) $args[ $unified ] );
				$formats[]      = '%s';
			}
		}
		if ( empty( $data ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB
		$exists_row = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE post_id = %d", $post_id ) );
		if ( $exists_row ) {
			// phpcs:ignore WordPress.DB
			$wpdb->update( $table, $data, array( 'post_id' => $post_id ), $formats, array( '%d' ) );
		} else {
			$data['post_id'] = $post_id;
			$formats[]       = '%d';
			// phpcs:ignore WordPress.DB
			$wpdb->insert( $table, $data, $formats );
		}
	}
}
