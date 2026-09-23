<?php
/**
 * UserRegistrationContentRestriction Frontend.
 *
 * @class    URCR_Frontend
 * @version  4.0
 * @package  UserRegistrationContentRestriction/Admin
 * @category Admin
 * @author   WPEverest
 */

defined( 'ABSPATH' ) || exit;

/**
 * URCR_Frontend Class
 */
class URCR_Frontend {

	/**
	 * Hook in tabs.
	 */
	public function __construct() {

		$content_restriction_enabled = ur_string_to_bool( get_option( 'user_registration_content_restriction_enable', true ) );

		if ( ! $content_restriction_enabled ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'run_content_restrictions' ) );
		add_filter( 'template_include', array( $this, 'include_run_content_restrictions' ), PHP_INT_MAX );
		add_filter( 'template_include', array( $this, 'restrict_whole_site' ), PHP_INT_MAX );
		add_filter( 'template_include', array( $this, 'restrict_blog_page' ), PHP_INT_MAX );
		add_filter( 'template_include', array( $this, 'restrict_wc_shop_page' ), PHP_INT_MAX );
		add_filter( 'template_include', array( $this, 'restrict_wc_product_post' ), PHP_INT_MAX );
		add_filter( 'woocommerce_product_is_visible', array( $this, 'is_wc_product_visible' ), 99999, 2 );

		if ( UR_PRO_ACTIVE ) {
			// To restrict products page  based on taxonomies.
			add_action( 'woocommerce_product_query', array( $this, 'urcr_woocommerce_product_query' ), 9999, 1 );
		}

		add_filter( 'woocommerce_is_purchasable', array( $this, 'is_wc_purchasable' ), 99999, 2 );

		add_action( 'elementor/frontend/before_render', array( $this, 'urcr_elementor_before_section_render' ) );
		add_action( 'elementor/frontend/after_render', array( $this, 'urcr_elementor_after_section_render' ) );

		if ( shortcode_exists( 'urcr_restrict' ) ) {
			$this->disable_elementor_element_cache();
		}
	}

	/**
	 * Get all access rules with caching and recursion protection.
	 *
	 * @return array List of access rule posts.
	 */
	private function get_all_access_rules() {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
				'urcr_access_rule',
				'publish'
			)
		);
	}

	/**
	 * Disable Elementor element caching to ensure dynamic content works.
	 * Only writes the option once — skips the DB write if already disabled.
	 */
	public function disable_elementor_element_cache() {
		if ( 'disable' !== get_option( 'elementor_element_cache_ttl' ) ) {
			update_option( 'elementor_element_cache_ttl', 'disable' );
		}

		if ( class_exists( '\Elementor\Plugin' ) &&
			isset( \Elementor\Plugin::$instance->files_manager ) &&
			method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}
	/**
	 * Perform content restriction task.
	 */
	public function include_run_content_restrictions( $template ) {

		if ( is_embed() ) {
			return $template;
		}

		global $post;

		if ( is_object( $post ) ) {
			$post_id = absint( $post->ID );
		} elseif ( is_array( $post ) && isset( $post['ID'] ) ) {
			$post_id = absint( $post['ID'] );
		} else {
			$post_id = null;
		}

		if ( null !== $post_id ) {
			// Check if this page should be excluded from whole site restriction
			if ( function_exists( 'urcr_is_page_excluded' ) && urcr_is_page_excluded( $post_id ) ) {
				return $template;
			}

			$whole_site_access_restricted = ur_string_to_bool( get_option( 'user_registration_content_restriction_whole_site_access', false ) );

			if ( $whole_site_access_restricted ) {
				$template = $this->basic_restrictions_templates( $template, $post );
			}
		}

		return $template;
	}

	/**
	 * Check option is restriction enabled then show global message.
	 *
	 * @param mixed $element Section Element.
	 */
	public function urcr_elementor_before_section_render( $element ) {
		if ( ur_string_to_bool( $element->get_settings( 'urcr_restrict_section' ) ) ) {
			ob_start();
			echo '[urcr_restrict]';
		}
	}
	/**
	 * Check option is restriction enabled then show global message.
	 *
	 * @param mixed $element Section Element.
	 */
	public function urcr_elementor_after_section_render( $element ) {
		if ( ur_string_to_bool( $element->get_settings( 'urcr_restrict_section' ) ) ) {
			echo '[/urcr_restrict]';
			$content_ur = ob_get_clean();
			echo do_shortcode( $content_ur );
		}
	}
	/**
	 * Access Rule for whole site restriction.
	 *
	 * @param mixed $template Template.
	 */
	public function restrict_whole_site( $template ) {
		if ( is_embed() ) {
			return $template;
		}

		global $wp_query, $post;
		$current_post_id = get_queried_object_id();

		// Get the current post object properly
		if ( ( empty( $post ) || ! is_object( $post ) ) && ! ( is_plugin_active( 'learning-management-system/lms.php' )
				|| is_plugin_active( 'learning-management-system-pro/lms.php' ) ) ) {
			$post = get_queried_object();
			if ( ! is_object( $post ) && $current_post_id ) {
				$post = get_post( $current_post_id );
			}
		}

		// Check if this page should be excluded from whole site restriction
		if ( function_exists( 'urcr_is_page_excluded' ) && urcr_is_page_excluded( $current_post_id ) ) {
			return $template;
		}

		$urcr_meta_override_global_settings = get_post_meta( $current_post_id, 'urcr_meta_override_global_settings', true );

		if ( ! ur_string_to_bool( $urcr_meta_override_global_settings ) ) {

			$access_rule_posts         = $this->get_all_access_rules();
			$is_whole_site_restriction = false;

			foreach ( $access_rule_posts as $access_rule_post ) {
				$access_rule = json_decode( $access_rule_post->post_content, true );

				// Verify if required params are available.
				if ( ur_string_to_bool( $access_rule['enabled'] ) && ! empty( $access_rule['target_contents'] ) ) {
					$types = wp_list_pluck( $access_rule['target_contents'], 'type' );
					if ( in_array( 'whole_site', $types, true ) ) {
						$is_whole_site_restriction = true;
					}
				}
			}

			$whole_site_access_restricted = ur_string_to_bool( get_option( 'user_registration_content_restriction_whole_site_access', false ) );

			if ( $is_whole_site_restriction ) {
				// Ensure we have a valid post object
				if ( empty( $post ) || ! is_object( $post ) ) {
					$post = get_queried_object();
					if ( ! is_object( $post ) && $current_post_id ) {
						$post = get_post( $current_post_id );
					}
				}

				$access_granted   = false;
				$restriction_rule = null;

				foreach ( $access_rule_posts as $access_rule_post ) {

					$access_rule = json_decode( $access_rule_post->post_content, true );

					// Verify if required params are available.
					if ( empty( $access_rule['logic_map'] ) || empty( $access_rule['target_contents'] ) || empty( $access_rule['actions'] ) ) {
						continue;
					}
					// Check if the logic map data is in array format.
					if ( ! is_array( $access_rule['logic_map'] ) ) {
						continue;
					}
					// Validate against empty variables.
					if ( empty( $access_rule['logic_map']['conditions'] ) || empty( $access_rule['logic_map']['conditions'] ) ) {
						continue;
					}

					$types = wp_list_pluck( $access_rule['target_contents'], 'type' );
					if ( ! in_array( 'whole_site', $types, true ) ) {
						continue;
					}

					if ( urcr_is_access_rule_enabled( $access_rule ) && urcr_is_action_specified( $access_rule ) ) {

						$should_allow_access = urcr_is_allow_access( $access_rule['logic_map'], $post );

						$access_control = isset( $access_rule['actions'][0]['access_control'] ) && ! empty( $access_rule['actions'][0]['access_control'] ) ? $access_rule['actions'][0]['access_control'] : 'access';

						if ( ( true === $should_allow_access && 'access' === $access_control ) || ( false == $should_allow_access && 'restrict' === $access_control ) ) {
							$access_granted = true;
						} elseif ( ( true === $should_allow_access && 'restrict' === $access_control ) || ( false == $should_allow_access && 'access' === $access_control ) ) {

							$restriction_rule = $access_rule;
						}
					}
				}

				if ( $access_granted ) {
					return $template;
				}

				foreach ( $access_rule_posts as $access_rule_post ) {
					$access_rule = json_decode( $access_rule_post->post_content, true );

					// Verify if required params are available.
					if ( empty( $access_rule['logic_map'] ) || empty( $access_rule['target_contents'] ) || empty( $access_rule['actions'] ) ) {
						continue;
					}
					// Check if the logic map data is in array format.
					if ( ! is_array( $access_rule['logic_map'] ) ) {
						continue;
					}
					// Validate against empty variables.
					if ( empty( $access_rule['logic_map']['conditions'] ) || empty( $access_rule['logic_map']['conditions'] ) ) {
						continue;
					}

					$types = wp_list_pluck( $access_rule['target_contents'], 'type' );
					if ( in_array( 'whole_site', $types, true ) ) {
						continue;
					}

					$is_target = urcr_is_target_post( $access_rule['target_contents'], $post );
					if ( true !== $is_target ) {
						continue;
					}

					if ( urcr_is_access_rule_enabled( $access_rule ) && urcr_is_action_specified( $access_rule ) ) {
						$should_allow_access = urcr_is_allow_access( $access_rule['logic_map'], $post );

						$access_control = isset( $access_rule['actions'][0]['access_control'] ) && ! empty( $access_rule['actions'][0]['access_control'] ) ? $access_rule['actions'][0]['access_control'] : 'access';

						if ( ( true === $should_allow_access && 'access' === $access_control ) || ( false == $should_allow_access && 'restrict' === $access_control ) ) {
							return $template;
						}
					}
				}

				// If no rule granted access and we have a restriction rule, apply it
				if ( null !== $restriction_rule && ! is_super_admin() ) {
					do_action( 'urcr_pre_content_restriction_applied', $restriction_rule, $post );

					if ( ! is_singular() ) {
						$this->apply_loop_content_restriction( $restriction_rule );
						do_action( 'urcr_post_content_restriction_applied', $restriction_rule, $post );
						return $template;
					}

					urcr_apply_content_restriction( $restriction_rule['actions'], $post );

					do_action( 'urcr_post_content_restriction_applied', $restriction_rule, $post );
				}
			} else {
				$access_given = $this->check_access_with_access_rules();

				if ( false === $access_given && $whole_site_access_restricted && ! is_super_admin() ) {
					$template = $this->basic_restrictions_templates( $template, $post );
				}
			}
		}

		return $template;
	}

	/**
	 * Render the restriction message for non-singular views (home page, blog index, archives,
	 * search results, etc.) where there is no single queried post for the standard
	 * urcr_apply_content_restriction() flow to mutate.
	 *
	 * @param array $restriction_rule The matched restriction rule.
	 */
	/**
	 * Replace post content/excerpt with the restriction message on non-singular loops.
	 */
	private function apply_loop_content_restriction( $restriction_rule ) {
		$placeholder = (object) array(
			'ID'             => -1,
			'post_title'     => '',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'filter'         => 'raw',
		);

		urcr_apply_content_restriction( $restriction_rule['actions'], $placeholder );

		$styled_content = $placeholder->post_content;
		if ( '' === $styled_content ) {
			return;
		}

		$replace_with_message = function () use ( $styled_content ) {
			return $styled_content;
		};

		add_filter( 'the_content', $replace_with_message, PHP_INT_MAX );
		add_filter( 'the_excerpt', $replace_with_message, PHP_INT_MAX );
		add_filter( 'get_the_excerpt', $replace_with_message, PHP_INT_MAX );
	}

	/**
	 * Restrict Woocommerce Product Post_type
	 *
	 * @param mixed $template Template.
	 */
	public function restrict_wc_product_post( $template ) {

		if ( is_embed() ) {
			return $template;
		}
		$content_restriction_enabled = ur_string_to_bool( get_option( 'user_registration_content_restriction_enable', true ) );

		if ( ! $content_restriction_enabled ) {
			return $template;
		}
		if ( function_exists( 'is_singular' ) && is_singular( 'product' ) ) {
			global $wp_query;
			$posts = $wp_query->posts;

			if ( is_object( $posts ) ) {
				$post_id = absint( $posts->ID );
			} elseif ( is_array( $posts ) && isset( $posts['ID'] ) ) {
				$post_id = absint( $posts['ID'] );
			} else {
				$post_id = null;
			}

			if ( null !== $post_id ) {
				// Check if this page should be excluded from restriction
				if ( function_exists( 'urcr_is_page_excluded' ) && urcr_is_page_excluded( $post_id ) ) {
					return $template;
				}

				$urcr_meta_override_global_settings = get_post_meta( $post_id, 'urcr_meta_override_global_settings', true );

				if ( ! ur_string_to_bool( $urcr_meta_override_global_settings ) ) {
					$template = $this->advanced_restriction_wc_with_access_rule( $template, $posts );
					return $template;
				}
				$template = $this->basic_restrictions_templates( $template, $posts );
			}
		}
		return $template;
	}

	/**
	 * Restrict Blog Page.
	 */
	public function restrict_blog_page( $template ) {
		if ( is_embed() ) {
			return $template;
		}
		$content_restriction_enabled = ur_string_to_bool( get_option( 'user_registration_content_restriction_enable', true ) );

		if ( ! $content_restriction_enabled ) {
			return $template;
		}

		$page_for_posts_id = get_option( 'page_for_posts' );
		$blog_page         = $page_for_posts_id != 0 ? get_post( $page_for_posts_id ) : array();

		if ( empty( $blog_page ) ) {
			return $template;
		}

		// Check if this page should be excluded from restriction
		if ( function_exists( 'urcr_is_page_excluded' ) && urcr_is_page_excluded( $blog_page->ID ) ) {
			return $template;
		}

		$body_classes = get_body_class();

		// Check if "blog" class exists in the array
		if ( in_array( 'blog', $body_classes, true ) ) {

			$urcr_meta_override_global_settings = get_post_meta( $blog_page->ID, 'urcr_meta_override_global_settings', true );

			if ( ! ur_string_to_bool( $urcr_meta_override_global_settings ) ) {
				$template = $this->advanced_restriction_wc_with_access_rule( $template, $blog_page );
				return $template;
			}

			$template = $this->basic_restrictions_templates( $template, $blog_page );

			return $template;
		} else {
			$template_class   = array( 'page-id-' . $blog_page->ID );
			$custom_templates = apply_filters( 'user_registration_restrict_custom_templates', $template_class );

			if ( ! empty( $custom_templates ) ) {
				foreach ( $custom_templates as $key => $body_class ) {
					if ( in_array( $body_class, $body_classes, true ) ) {
						$urcr_meta_override_global_settings = get_post_meta( $blog_page->ID, 'urcr_meta_override_global_settings', true );

						if ( ! ur_string_to_bool( $urcr_meta_override_global_settings ) ) {
							$template = $this->advanced_restriction_wc_with_access_rule( $template, $blog_page );
							return $template;
						}
						$template = $this->basic_restrictions_templates( $template, $blog_page );

						return $template;
					}
				}
			}
		}
		return $template;
	}

	/**
	 * Restrict Woocommerce Shop Page.
	 *
	 * @param mixed $template Template.
	 */
	public function restrict_wc_shop_page( $template ) {
		if ( is_embed() ) {
			return $template;
		}
		$content_restriction_enabled = ur_string_to_bool( get_option( 'user_registration_content_restriction_enable', true ) );

		if ( ! $content_restriction_enabled ) {
			return $template;
		}
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return $template;
		}
		$shop_page_id = wc_get_page_id( 'shop' );
		$shop_page    = get_post( $shop_page_id );

		if ( empty( $shop_page ) ) {
			return $template;
		}

		if ( ( is_post_type_archive( 'product' ) || is_page( $shop_page_id ) ) ) {
			// Check if this page should be excluded from restriction
			if ( function_exists( 'urcr_is_page_excluded' ) && urcr_is_page_excluded( $shop_page->ID ) ) {
				return $template;
			}

			$urcr_meta_override_global_settings = get_post_meta( $shop_page->ID, 'urcr_meta_override_global_settings', true );

			if ( ! ur_string_to_bool( $urcr_meta_override_global_settings ) ) {
				$template = $this->advanced_restriction_wc_with_access_rule( $template, $shop_page );
				return $template;
			}

			$template = $this->basic_restrictions_templates( $template, $shop_page );
		}
		return $template;
	}

	/**
	 * Access Rules for woocommerce content restriction.
	 *
	 * @param mixed $template Template.
	 * @param mixed $post Post Data.
	 */
	public function advanced_restriction_wc_with_access_rule( $template, $post ) {

		if ( is_super_admin() ) {
			return $template;
		}

		// Get post ID from post object
		$post_id = null;
		if ( is_object( $post ) && isset( $post->ID ) ) {
			$post_id = absint( $post->ID );
		} elseif ( is_array( $post ) && isset( $post['ID'] ) ) {
			$post_id = absint( $post['ID'] );
		}

		$access_rule_posts         = $this->get_all_access_rules();
		$is_whole_site_restriction = false;

		foreach ( $access_rule_posts as $access_rule_post ) {
			$access_rule = json_decode( $access_rule_post->post_content, true );

			if ( ! $access_rule['enabled'] ) {
				continue;
			}
			// Verify if required params are available.
			if ( ! empty( $access_rule['target_contents'] ) ) {
				$types = wp_list_pluck( $access_rule['target_contents'], 'type' );
				if ( in_array( 'whole_site', $types, true ) ) {
					$is_whole_site_restriction = true;
				}
			}
		}

		$whole_site_access_restricted = ur_string_to_bool( get_option( 'user_registration_content_restriction_whole_site_access', false ) );

		// Check if this page should be excluded from whole site restriction
		if ( ! empty( $post_id ) && function_exists( 'urcr_is_page_excluded' ) && urcr_is_page_excluded( $post_id ) ) {
			// If whole site restriction is active (via access rules or old option), skip restriction for excluded pages
			if ( $is_whole_site_restriction || $whole_site_access_restricted ) {
				return $template;
			}
		}

		if ( ! $is_whole_site_restriction ) {
			foreach ( $access_rule_posts as $access_rule_post ) {
				$access_rule = json_decode( $access_rule_post->post_content, true );

				// Verify if required params are available.
				if ( empty( $access_rule['logic_map'] ) || empty( $access_rule['target_contents'] ) || empty( $access_rule['actions'] ) ) {
					continue;
				}

				// Check if the logic map data is in array format.
				if ( ! is_array( $access_rule['logic_map'] ) ) {
					continue;
				}

				// Validate against empty variables.
				if ( empty( $access_rule['logic_map']['conditions'] ) || empty( $access_rule['logic_map']['conditions'] ) ) {
					continue;
				}

				if ( urcr_is_access_rule_enabled( $access_rule ) && urcr_is_action_specified( $access_rule ) ) {
					$is_target = urcr_is_target_post( $access_rule['target_contents'], $post );

					if ( true === $is_target ) {
						// Check if this page should be excluded from restriction
						if ( ! empty( $post_id ) && function_exists( 'urcr_is_page_excluded' ) && urcr_is_page_excluded( $post_id ) ) {
							continue;
						}

						$should_allow_access = urcr_is_allow_access( $access_rule['logic_map'], $post );
						$access_control      = isset( $access_rule['actions'][0]['access_control'] ) && ! empty( $access_rule['actions'][0]['access_control'] ) ? $access_rule['actions'][0]['access_control'] : 'access';
						if ( ( true === $should_allow_access && 'restrict' === $access_control ) || ( false == $should_allow_access && 'access' === $access_control ) ) {
							do_action( 'urcr_pre_content_restriction_applied', $access_rule, $post );

							/**
							 * Allows integration plugins to handle restriction for pages that don't use
							 * $post->post_content (e.g. WooCommerce archives). Return true to skip the
							 * default urcr_apply_content_restriction() call.
							 *
							 * @since x.x.x
							 * @param bool  $handled     Whether restriction was already handled. Default false.
							 * @param array $access_rule The current access rule array.
							 * @param mixed $post        The target post/page object.
							 */
							$restriction_handled = apply_filters( 'urcr_restrict_page', false, $access_rule, $post );

							if ( ! $restriction_handled ) {
								urcr_apply_content_restriction( $access_rule['actions'], $post );
							}

							do_action( 'urcr_post_content_restriction_applied', $access_rule, $post );

							// Return the original template so normal theme structure is used
							return $template;
						}
					}
				}
			}
		}
		return $template;
	}

	/**
	 * Restrict the WooCommerce Product Query by excluding a specific product category.
	 *
	 * @param WP_Query $q The WP_Query object.
	 * @return void
	 */
	public function urcr_woocommerce_product_query( $q ) {

		if ( is_super_admin() ) {
			return;
		}

		global $post;

		if ( function_exists( 'is_shop' ) ) {
			$shop_id = is_shop() ? wc_get_page_id( 'shop' ) : 0;
		}
		$access_rule_posts = $this->get_all_access_rules();
		$post_content      = isset( $access_rule_posts[0]->post_content ) ? json_decode( $access_rule_posts[0]->post_content ) : '';
		if ( ! $post_content ) {
			return;
		}
		$action = isset( $post_content->actions[0]->access_control ) ? $post_content->actions[0]->access_control : 'access';
		if ( $action !== 'restrict' ) {
			return;
		}

		$is_whole_site_restriction = false;

		foreach ( $access_rule_posts as $access_rule_post ) {
			$access_rule = json_decode( $access_rule_post->post_content, true );

			// Verify if required params are available.
			if ( ! empty( $access_rule['target_contents'] ) ) {
				$types = wp_list_pluck( $access_rule['target_contents'], 'type' );
				if ( in_array( 'whole_site', $types, true ) ) {
					$is_whole_site_restriction = true;
				}
			}
		}

		if ( ! $is_whole_site_restriction ) {
			foreach ( $access_rule_posts as $access_rule_post ) {
				$access_rule = json_decode( $access_rule_post->post_content, true );

				$target_contents        = isset( $post_content->target_contents ) ? $post_content->target_contents : array();
				$cat_values             = array();
				$tag_value              = array();
				$product_shipping_class = array();
				if ( urcr_is_access_rule_enabled( $access_rule ) && urcr_is_action_specified( $access_rule ) ) {
					$is_target = urcr_is_target_post( $access_rule['target_contents'], $shop_id );
					if ( true === $is_target ) {
						$should_allow_access = urcr_is_allow_access( $access_rule['logic_map'], $post );
						$access_control      = isset( $access_rule['actions'][0]['access_control'] ) && ! empty( $access_rule['actions'][0]['access_control'] ) ? $access_rule['actions'][0]['access_control'] : 'access';
						if ( ( true === $should_allow_access && 'restrict' === $access_control ) || ( false == $should_allow_access && 'access' === $access_control ) ) {
							foreach ( $target_contents as $target_content ) {
								if ( isset( $target_content->taxonomy ) && $target_content->taxonomy === 'product_cat' ) {
									$values = isset( $target_content->value ) ? $target_content->value : array();
									foreach ( $values as $value ) {
										$cat_values[] = $value;

									}
								}
								if ( isset( $target_content->taxonomy ) && $target_content->taxonomy === 'product_tag' ) {
									$values = isset( $target_content->value ) ? $target_content->value : array();
									foreach ( $values as $value ) {
										$tag_value[] = $value;
									}
								}
								if ( isset( $target_content->taxonomy ) && $target_content->taxonomy === 'product_shipping_class' ) {
									$values = isset( $target_content->value ) ? $target_content->value : array();
									foreach ( $values as $value ) {
										$product_shipping_class[] = $value;
									}
								}
							}
						}
					}
				}
			}
		}
		$tax_query = (array) $q->get( 'tax_query' );

		if ( ! empty( $cat_values ) ) {
			$tax_query[] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $cat_values,
				'operator' => 'NOT IN',
			);
		}
		if ( ! empty( $tag_value ) ) {
			$tax_query[] = array(
				'taxonomy' => 'product_tag',
				'field'    => 'term_id',
				'terms'    => $tag_value,
				'operator' => 'NOT IN',
			);
		}
		if ( ! empty( $product_shipping_class ) ) {
			$tax_query[] = array(
				'taxonomy' => 'product_shipping_class',
				'field'    => 'term_id',
				'terms'    => $product_shipping_class,
				'operator' => 'NOT IN',
			);
		}
		$q->set( 'tax_query', $tax_query );
	}
	/**
	 * Restrict the WooCommerce Product Visibility
	 *
	 * @param bool $ret
	 * @param int  $product_id
	 *
	 * @access  public
	 * @since   4.0
	 * @return  bool
	 */
	public function is_wc_product_visible( $ret, $product_id ) {
		if ( ! $ret ) {
			return $ret;
		}

		if ( is_super_admin() ) {
			return true;
		}

		if ( current_user_can( 'edit_post', $product_id ) ) {
			return true;
		}

		return $this->ur_user_can_view_woocommerce_product( $product_id );
	}

	/**
	 * Restrict the ability to purchase WooCommerce Product
	 *
	 * @param bool       $ret
	 * @param WC_Product $product
	 *
	 * @access  public
	 * @since  4.0
	 * @return  bool
	 */
	public function is_wc_purchasable( $ret, $product ) {
		if ( ! $ret ) {
			return $ret;
		}

		return $this->ur_user_can_purchase_woocommerce_product( $product->get_id() );
	}

	/**
	 * Determines whether or not a user is allowed to view a WooCommerce product.
	 *
	 * @param int $product_id ID of the WooCommerce product.
	 *
	 * @since 4.0
	 * @return bool
	 */
	public function ur_user_can_view_woocommerce_product( $product_id ) {
		$can_view = true;

		if ( is_super_admin() ) {
			return $can_view;
		}
		$content_restriction_enabled = ur_string_to_bool( get_option( 'user_registration_content_restriction_enable', true ) );

		if ( ! $content_restriction_enabled ) {
			return $can_view;
		}

		if ( null !== $product_id ) {
			$urcr_meta_override_global_settings = get_post_meta( $product_id, 'urcr_meta_override_global_settings', true );

			if ( ! ur_string_to_bool( $urcr_meta_override_global_settings ) ) {
				$can_view = $this->wc_advanced_restriction_with_access_rule( $product_id );

				return $can_view;
			}

			$can_view = $this->ur_basic_wc_product_restriction( $product_id );
		}

		return $can_view;
	}

	/**
	 * Determines whether or not a user is allowed to purchase a WooCommerce product.
	 *
	 * @param int $product_id ID of the WooCommerce product.
	 *
	 * @since 4.0
	 * @return bool
	 */
	public function ur_user_can_purchase_woocommerce_product( $product_id ) {
		$can_purchase = true;

		if ( is_super_admin() ) {
			return $can_purchase;
		}

		$content_restriction_enabled = ur_string_to_bool( get_option( 'user_registration_content_restriction_enable', true ) );

		if ( ! $content_restriction_enabled ) {
			return $can_purchase;
		}

		if ( null !== $product_id ) {
			$urcr_meta_override_global_settings = get_post_meta( $product_id, 'urcr_meta_override_global_settings', true );

			if ( ! ur_string_to_bool( $urcr_meta_override_global_settings ) ) {
				$can_purchase = $this->wc_advanced_restriction_with_access_rule( $product_id );

				return $can_purchase;
			}

			$can_purchase = $this->ur_basic_wc_product_restriction( $product_id );
		}

		return $can_purchase;
	}

	/**
	 * Basic WooCommerce Product Restriction.
	 *
	 * @since 4.0
	 */
	public function ur_basic_wc_product_restriction( $product_id ) {

		if ( is_super_admin() ) {
			return true;
		}

		$allowed_roles = get_option( 'user_registration_content_restriction_allow_to_roles', 'administrator' );

		$current_user_roles = ( is_user_logged_in() && ! empty( wp_get_current_user()->roles ) ) ? (array) wp_get_current_user()->roles : array();

		$get_meta_data_roles = get_post_meta( $product_id, 'urcr_meta_roles', $single = true );

		$get_meta_data_allow_to = get_post_meta( $product_id, 'urcr_allow_to', $single = true );

		$get_meta_data_checkbox = get_post_meta( $product_id, 'urcr_meta_checkbox', $single = true );

		$taxonomies = get_post_taxonomies( $product_id );

		$terms = array();

		foreach ( $taxonomies as $taxonomy ) {
			$term = wp_get_object_terms( $product_id, $taxonomy );
			if ( ! is_wp_error( $term ) && ! empty( $term ) ) {
				$term_ids             = wp_list_pluck( $term, 'term_id' );
				$terms[ $product_id ] = $term_ids;
			}
		}

		$args = array(
			'post_type' => 'product',
		);

		$products = wp_list_pluck( get_posts( $args ), 'ID' );

		$product_terms = array();

		foreach ( $products as $product ) {
			$product_taxonomies = get_post_taxonomies( $product );
			foreach ( $product_taxonomies  as $taxonomy ) {
				$product_term = wp_get_object_terms( $product, $taxonomy );
				if ( ! is_wp_error( $product_term ) && ! empty( $product_term ) ) {
					$term_ids                  = wp_list_pluck( $product_term, 'term_id' );
					$product_terms[ $product ] = $term_ids;
				}
			}
		}

		$override_global_settings = get_post_meta( $product_id, 'urcr_meta_override_global_settings', $single = true );

		if ( ur_string_to_bool( $get_meta_data_checkbox ) ) {

			if ( ur_string_to_bool( $override_global_settings ) ) {
				if ( '0' == $get_meta_data_allow_to ) {
					if ( is_user_logged_in() ) {
						return $result = $this->compareTaxonomy( $product_terms, $terms );
					}
				} elseif ( '1' === $get_meta_data_allow_to ) {
					if ( is_array( $allowed_roles ) && array_intersect( $current_user_roles, (array) $get_meta_data_roles ) ) {
						return $result = $this->compareTaxonomy( $product_terms, $terms );
					}
				} elseif ( '2' === $get_meta_data_allow_to ) {
					if ( ! is_user_logged_in() ) {
						return $result = $this->compareTaxonomy( $product_terms, $terms );
					}
				}
			}
		} else {
			return true;
		}
	}

	public function compareTaxonomy( $array1, $array2 ) {
		$result = array();

		foreach ( $array1 as $key => $value ) {
			if ( array_key_exists( $key, $array2 ) ) {
				if ( is_array( $value ) && is_array( $array2[ $key ] ) ) {
					$nestedResult = $this->compareTaxonomy( $value, $array2[ $key ] );

					if ( ! empty( $nestedResult ) ) {
						$result[ $key ] = $nestedResult;
					}
				} elseif ( $value === $array2[ $key ] ) {
					$result[ $key ] = $value;
				}
			}
		}

		return $result;
	}
	/**
	 * Basic WooCommerce Product Restriction.
	 *
	 * @since 4.0
	 */
	function wc_advanced_restriction_with_access_rule( $product_id ) {
		$can_view_purchase = true;

		if ( is_super_admin() ) {
			return $can_view_purchase;
		}

		$access_rule_posts = $this->get_all_access_rules();

		$is_whole_site_restriction = false;

		foreach ( $access_rule_posts as $access_rule_post ) {
			$access_rule = json_decode( $access_rule_post->post_content, true );

			// Verify if required params are available.
			if ( ! empty( $access_rule['target_contents'] ) ) {
				$types = wp_list_pluck( $access_rule['target_contents'], 'type' );
				if ( in_array( 'whole_site', $types, true ) ) {
					$is_whole_site_restriction = true;
				}
			}
		}

		if ( ! $is_whole_site_restriction ) {
			$access_granted = false;

			// First, check all rules to see if any grant access
			foreach ( $access_rule_posts as $access_rule_post ) {
				$access_rule = json_decode( $access_rule_post->post_content, true );

				// Verify if required params are available.
				if ( empty( $access_rule['logic_map'] ) || empty( $access_rule['target_contents'] ) || empty( $access_rule['actions'] ) ) {
					continue;
				}
				// Check if the logic map data is in array format.
				if ( ! is_array( $access_rule['logic_map'] ) ) {
					continue;
				}
				// Validate against empty variables.
				if ( empty( $access_rule['logic_map']['conditions'] ) || empty( $access_rule['logic_map']['conditions'] ) ) {
					continue;
				}

				if ( urcr_is_access_rule_enabled( $access_rule ) && urcr_is_action_specified( $access_rule ) ) {

					$is_target = urcr_is_target_post( $access_rule['target_contents'], $product_id );
					if ( true === $is_target ) {
						$should_allow_access = urcr_is_allow_access( $access_rule['logic_map'], $product_id );
						$access_control      = isset( $access_rule['actions'][0]['access_control'] ) && ! empty( $access_rule['actions'][0]['access_control'] ) ? $access_rule['actions'][0]['access_control'] : 'access';

						// If any rule grants access, allow it
						if ( ( true === $should_allow_access && 'access' === $access_control ) || ( false == $should_allow_access && 'restrict' === $access_control ) ) {
							$access_granted = true;
							break;
						}
					}
				}
			}

			// Only restrict if no rule granted access
			if ( ! $access_granted ) {
				foreach ( $access_rule_posts as $access_rule_post ) {
					$access_rule = json_decode( $access_rule_post->post_content, true );

					// Verify if required params are available.
					if ( empty( $access_rule['logic_map'] ) || empty( $access_rule['target_contents'] ) || empty( $access_rule['actions'] ) ) {
						continue;
					}
					// Check if the logic map data is in array format.
					if ( ! is_array( $access_rule['logic_map'] ) ) {
						continue;
					}
					// Validate against empty variables.
					if ( empty( $access_rule['logic_map']['conditions'] ) || empty( $access_rule['logic_map']['conditions'] ) ) {
						continue;
					}

					if ( urcr_is_access_rule_enabled( $access_rule ) && urcr_is_action_specified( $access_rule ) ) {

						$is_target = urcr_is_target_post( $access_rule['target_contents'], $product_id );
						if ( true === $is_target ) {
							$should_allow_access = urcr_is_allow_access( $access_rule['logic_map'], $product_id );
							$access_control      = isset( $access_rule['actions'][0]['access_control'] ) && ! empty( $access_rule['actions'][0]['access_control'] ) ? $access_rule['actions'][0]['access_control'] : 'access';

							if ( ( true === $should_allow_access && 'restrict' === $access_control ) || ( false == $should_allow_access && 'access' === $access_control ) ) {
								$can_view_purchase = false;
								break;
							}
						}
					}
				}
			}
		}
		return $can_view_purchase;
	}
	/**
	 * Perform content restriction task.
	 */
	public function run_content_restrictions() {
		global $post;

		if ( is_super_admin() ) {
			return;
		}

		$content_restriction_enabled = ur_string_to_bool( get_option( 'user_registration_content_restriction_enable', true ) );

		if ( ! $content_restriction_enabled ) {
			return;
		}
		$restriction_applied = false;

		$post_id = 0;
		if ( isset( $post->ID ) ) {
			$post_id = $post->ID;
		}

		// Check if this page should be excluded from restriction
		if ( function_exists( 'urcr_is_page_excluded' ) && urcr_is_page_excluded( $post_id ) ) {
			return;
		}
		$urcr_meta_override_global_settings = get_post_meta( $post_id, 'urcr_meta_override_global_settings', true );
		if ( $urcr_meta_override_global_settings ) {
			$this->basic_restrictions();
		} elseif ( ! ur_string_to_bool( $urcr_meta_override_global_settings ) ) {
			$this->advanced_restriction_with_access_rules();
		}
	}

	/**
	 * Restrict contents with Access Rules.
	 */
	public function advanced_restriction_with_access_rules() {

		global $wp_query;
		$access_rule_posts = $this->get_all_access_rules();

		$posts                  = $wp_query->posts;
		$posts_length           = empty( $posts ) ? 0 : count( $posts );
		$is_restriction_applied = false;

		for ( $i = 0; $i < $posts_length; $i++ ) {
			$post    = $posts[ $i ];
			$post_id = isset( $post->ID ) ? absint( $post->ID ) : 0;

			if ( function_exists( 'urcr_is_page_excluded' ) && urcr_is_page_excluded( $post_id ) ) {
				continue;
			}

			$access_granted   = false;
			$restriction_rule = null;

			foreach ( $access_rule_posts as $access_rule_post ) {
				$access_rule = json_decode( $access_rule_post->post_content, true );

				// Verify if required params are available.
				if ( empty( $access_rule['logic_map'] ) || empty( $access_rule['target_contents'] ) || empty( $access_rule['actions'] ) ) {
					continue;
				}
				// Check if the logic map data is in array format.
				if ( ! is_array( $access_rule['logic_map'] ) ) {
					continue;
				}
				// Validate against empty variables.
				if ( empty( $access_rule['logic_map']['conditions'] ) || empty( $access_rule['logic_map']['conditions'] ) ) {
					continue;
				}

				if ( urcr_is_access_rule_enabled( $access_rule ) && urcr_is_action_specified( $access_rule ) ) {

					$is_target = urcr_is_target_post( $access_rule['target_contents'], $post );

					if ( true === $is_target ) {
						$should_allow_access = urcr_is_allow_access( $access_rule['logic_map'], $post );

						$access_control = isset( $access_rule['actions'][0]['access_control'] ) && ! empty( $access_rule['actions'][0]['access_control'] ) ? $access_rule['actions'][0]['access_control'] : 'access';

						if ( ( true === $should_allow_access && 'access' === $access_control ) || ( false == $should_allow_access && 'restrict' === $access_control ) ) {
							$access_granted = true;
						} elseif ( ( true === $should_allow_access && 'restrict' === $access_control ) || ( false == $should_allow_access && 'access' === $access_control ) ) {
							$restriction_rule = $access_rule;
						}
					}
				}
			}
			if ( $access_granted ) {
				continue;
			}

			if ( null !== $restriction_rule ) {
				do_action( 'urcr_pre_content_restriction_applied', $restriction_rule, $post );

				$is_applied = urcr_apply_content_restriction( $restriction_rule['actions'], $post );

				// In case there are multiple posts and 'true' occurred at least once, never change it to false.
				$is_restriction_applied = $posts_length > 1 && $is_restriction_applied ? true : $is_applied;

				do_action( 'urcr_post_content_restriction_applied', $restriction_rule, $post );
			}
		}

		if ( ! $is_restriction_applied && UR_PRO_ACTIVE && ur_check_module_activation( 'content-drip' ) ) {

			// apply content dripping.
			$frontend = new WPEverest\URM\ContentDrip\Frontend();
			return $frontend->apply_content_drip();
		}

		return $is_restriction_applied;
	}

	/**
	 * Check access with Access Rules.
	 */
	public function check_access_with_access_rules() {
		global $wp_query;
		$access_rule_posts = $this->get_all_access_rules();

		$posts           = $wp_query->posts;
		$posts_length    = empty( $posts ) ? 0 : count( $posts );
		$is_access_given = false;

		foreach ( $access_rule_posts as $access_rule_post ) {
			$access_rule = json_decode( $access_rule_post->post_content, true );

			// Verify if required params are available.
			if ( empty( $access_rule['logic_map'] ) || empty( $access_rule['target_contents'] ) || empty( $access_rule['actions'] ) ) {
				continue;
			}
			// Check if the logic map data is in array format.
			if ( ! is_array( $access_rule['logic_map'] ) ) {
				continue;
			}
			// Validate against empty variables.
			if ( empty( $access_rule['logic_map']['conditions'] ) || empty( $access_rule['logic_map']['conditions'] ) ) {
				continue;
			}

			if ( urcr_is_access_rule_enabled( $access_rule ) && urcr_is_action_specified( $access_rule ) ) {
				for ( $i = 0; $i < $posts_length; $i++ ) {
					$post      = $posts[ $i ];
					$is_target = urcr_is_target_post( $access_rule['target_contents'], $post );

					if ( true === $is_target ) {
						$should_allow_access = urcr_is_allow_access( $access_rule['logic_map'], $post );
						$access_control      = isset( $access_rule['actions'][0]['access_control'] ) && ! empty( $access_rule['actions'][0]['access_control'] ) ? $access_rule['actions'][0]['access_control'] : 'access';

						if ( $should_allow_access && 'access' === $access_control ) {
							$is_access_given = true;
						}
					}
				}
			}
		}
		return $is_access_given;
	}

	/**
	 * Perform basic restriction task for blogs.
	 */
	public function basic_restrictions_templates( $template, $post ) {

		if ( is_super_admin() ) {
			return $template;
		}

		if ( is_object( $post ) ) {
			$post_id = absint( $post->ID );
		} elseif ( is_array( $post ) && isset( $post['ID'] ) ) {
			$post_id = absint( $post['ID'] );
		} else {
			// Handle other cases where $post doesn't contain the expected data structure.
			$post_id = 0; // Or assign a default value as needed.
		}

		// Check shop page and get it's page id.
		if ( function_exists( 'is_shop' ) ) {
			$post_id = is_shop() ? wc_get_page_id( 'shop' ) : $post_id;
		}

		// Check if this page should be excluded from restriction
		if ( function_exists( 'urcr_is_page_excluded' ) && urcr_is_page_excluded( $post_id ) ) {
			return $template;
		}

		$allowed_roles       = get_option( 'user_registration_content_restriction_allow_to_roles', 'administrator' );
		$allowed_memberships = get_option( 'user_registration_content_restriction_allow_to_memberships' );

		$current_user_roles = ( is_user_logged_in() && ! empty( wp_get_current_user()->roles ) ) ? (array) wp_get_current_user()->roles : array();

		$get_meta_data_roles = get_post_meta( $post_id, 'urcr_meta_roles', $single = true );

		$get_meta_data_allow_to = get_post_meta( $post_id, 'urcr_allow_to', $single = true );

		$get_meta_data_checkbox = get_post_meta( $post_id, 'urcr_meta_checkbox', $single = true );

		$override_global_settings = get_post_meta( $post_id, 'urcr_meta_override_global_settings', $single = true );

		$is_membership_active         = ur_check_module_activation( 'membership' );
		$whole_site_access_restricted = ur_string_to_bool( get_option( 'user_registration_content_restriction_whole_site_access', false ) );

		if ( $whole_site_access_restricted || $get_meta_data_checkbox ) {

			if ( ! ur_string_to_bool( $override_global_settings ) ) {
				if ( '0' == get_option( 'user_registration_content_restriction_allow_access_to', '0' ) ) {
					if ( ! is_user_logged_in() ) {
						$template = $this->urcr_restrict_contents_template( $template, $post );
					}
				} elseif ( '1' == get_option( 'user_registration_content_restriction_allow_access_to' ) ) {
					if ( is_array( $allowed_roles ) && array_intersect( $current_user_roles, (array) $allowed_roles ) ) {
						return $template;
					}
					$template = $this->urcr_restrict_contents_template( $template, $post );

				} elseif ( '2' === get_option( 'user_registration_content_restriction_allow_access_to' ) ) {
					if ( is_user_logged_in() ) {
						$template = $this->urcr_restrict_contents_template( $template, $post );
					}
				} elseif ( '3' === get_option( 'user_registration_content_restriction_allow_access_to' ) ) {
					if ( $is_membership_active && is_array( $allowed_memberships ) && urm_check_user_membership_has_access( $allowed_memberships ) ) {
						return $template;
					}
					$template = $this->urcr_restrict_contents_template( $template, $post );
				}
			} elseif ( $get_meta_data_allow_to == '0' ) {

				if ( ! is_user_logged_in() ) {
					$template = $this->urcr_restrict_contents_template( $template, $post );
				}
			} elseif ( $get_meta_data_allow_to == '1' ) {
				if ( isset( $get_meta_data_roles ) && ! empty( $get_meta_data_roles ) ) {
					if ( is_array( $get_meta_data_roles ) && array_intersect( $current_user_roles, (array) $get_meta_data_roles ) ) {
						return $template;
					}
					$template = $this->urcr_restrict_contents_template( $template, $post );
				}
			} elseif ( $get_meta_data_allow_to === '2' ) {
				if ( is_user_logged_in() ) {
					$template = $this->urcr_restrict_contents_template( $template, $post );
				}
			} elseif ( $get_meta_data_allow_to === '3' ) {
				if ( $is_membership_active && is_array( $allowed_memberships ) && urm_check_user_membership_has_access( $allowed_memberships ) ) {
					return $template;
				}
				return $this->urcr_restrict_contents_template( $template, $post );
			}
		} elseif ( $get_meta_data_checkbox ) {
			$this->basic_restrictions();
		}
		return $template;
	}


	/**
	 * Perform content restriction task.
	 */
	public function basic_restrictions() {
		global $post;

		if ( is_super_admin() ) {
			return;
		}

		$post_id = isset( $post->ID ) ? absint( $post->ID ) : 0;

		// Check shop page and get it's page id.
		if ( function_exists( 'is_shop' ) ) {
			$post_id = is_shop() ? wc_get_page_id( 'shop' ) : $post_id;
		}

		// Check if this page should be excluded from restriction
		if ( function_exists( 'urcr_is_page_excluded' ) && urcr_is_page_excluded( $post_id ) ) {
			return;
		}
		$allowed_memberships = get_option( 'user_registration_content_restriction_allow_to_memberships' );

		$allowed_roles = get_option( 'user_registration_content_restriction_allow_to_roles', 'administrator' );

		$current_user_roles = ( is_user_logged_in() && ! empty( wp_get_current_user()->roles ) ) ? (array) wp_get_current_user()->roles : array();

		$get_meta_data_roles = get_post_meta( $post_id, 'urcr_meta_roles', $single = true );

		$get_meta_data_allow_to = get_post_meta( $post_id, 'urcr_allow_to', $single = true );

		$override_global_settings  = get_post_meta( $post_id, 'urcr_meta_override_global_settings', $single = true );
		$is_membership_active      = ur_check_module_activation( 'membership' );
		$get_meta_data_memberships = get_post_meta( $post_id, 'urcr_meta_memberships', $single = true );

		if ( ! ur_string_to_bool( $override_global_settings ) ) {
			if ( '0' == get_option( 'user_registration_content_restriction_allow_access_to', '0' ) ) {

				if ( ! is_user_logged_in() ) {
					$this->urcr_apply_basic_restriction_template();
				}
				return $post;
			} elseif ( '1' == get_option( 'user_registration_content_restriction_allow_access_to' ) ) {
				if ( is_array( $allowed_roles ) && array_intersect( $current_user_roles, (array) $allowed_roles ) ) {
					return;
				}
				$this->urcr_apply_basic_restriction_template();
			} elseif ( '2' === get_option( 'user_registration_content_restriction_allow_access_to' ) ) {
				if ( is_user_logged_in() ) {
					$this->urcr_apply_basic_restriction_template();
				}
				return $post;
			} elseif ( '3' === get_option( 'user_registration_content_restriction_allow_access_to' ) ) {
				if ( $is_membership_active && is_array( $allowed_memberships ) && urm_check_user_membership_has_access( $allowed_memberships ) ) {
					return;
				}
				$this->urcr_apply_basic_restriction_template();
			}
		} elseif ( $get_meta_data_allow_to == '0' ) {
			if ( ! is_user_logged_in() ) {
				$this->urcr_apply_basic_restriction_template();
			}
			return $post;
		} elseif ( $get_meta_data_allow_to == '1' ) {
			if ( isset( $get_meta_data_roles ) && ! empty( $get_meta_data_roles ) ) {
				if ( is_array( $get_meta_data_roles ) && array_intersect( $current_user_roles, (array) $get_meta_data_roles ) ) {
					return;
				}
				$this->urcr_apply_basic_restriction_template();
			}
		} elseif ( $get_meta_data_allow_to === '2' ) {
			if ( is_user_logged_in() ) {
				$this->urcr_apply_basic_restriction_template();
			}

			return $post;
		} elseif ( $get_meta_data_allow_to === '3' ) {
			if ( $is_membership_active && is_array( $get_meta_data_memberships ) && urm_check_user_membership_has_access( $get_meta_data_memberships ) ) {
				return $post;
			}
			$this->urcr_apply_basic_restriction_template();
		}
	}

	/**
	 * Apply basic restriction using base template (similar to urcr_apply_content_restriction).
	 */
	private function urcr_apply_basic_restriction_template() {
		global $post;

		// Check if this is a product page.
		if ( get_post_type() == 'product' ) {
			$this->restrict_products();
		}

		// Get message
		$restricted_message      = get_post_meta( $post->ID, 'urcr_meta_content', true );
		$override_global_message = get_post_meta( $post->ID, 'urcr_meta_override_global_settings', true );
		$message                 = ! empty( $restricted_message ) && $override_global_message ? wp_kses_post( $restricted_message ) : get_option( 'user_registration_content_restriction_message', '' );
		$message                 = ( false === $message || empty( $message ) ) ? esc_html__( 'This content is restricted!', 'user-registration' ) : $message;
		$message                 = apply_filters( 'user_registration_process_smart_tags', $message );
		if ( function_exists( 'apply_shortcodes' ) ) {
			$message = apply_shortcodes( $message );
		} else {
			$message = do_shortcode( $message );
		}

		$this->urcr_apply_restriction_template_to_post( $post, $message );
	}

	private function urcr_apply_restriction_template_to_post( $post, $message ) {
		$login_page_id        = get_option( 'user_registration_login_page_id' );
		$registration_page_id = get_option( 'user_registration_member_registration_page_id' );

		$login_url  = $login_page_id ? get_permalink( $login_page_id ) : wp_login_url();
		$signup_url = $registration_page_id ? get_permalink( $registration_page_id ) : ( $login_page_id ? get_permalink( $login_page_id ) : wp_registration_url() );

		if ( ! $registration_page_id ) {
			$default_form_page_id = get_option( 'user_registration_default_form_page_id' );
			if ( $default_form_page_id ) {
				$signup_url = get_permalink( $default_form_page_id );
			}
		}

		// Check if this is a whole site restriction
		$is_whole_site_restriction    = false;
		$whole_site_access_restricted = ur_string_to_bool( get_option( 'user_registration_content_restriction_whole_site_access', false ) );

		if ( $whole_site_access_restricted ) {
			$is_whole_site_restriction = true;
		} else {
			$access_rule_posts = get_posts(
				array(
					'numberposts' => -1,
					'post_status' => 'publish',
					'post_type'   => 'urcr_access_rule',
				)
			);

			foreach ( $access_rule_posts as $access_rule_post ) {
				$access_rule = json_decode( $access_rule_post->post_content, true );
				if ( ur_string_to_bool( $access_rule['enabled'] ) && ! empty( $access_rule['target_contents'] ) ) {
					$types = wp_list_pluck( $access_rule['target_contents'], 'type' );
					if ( in_array( 'whole_site', $types, true ) ) {
						$is_whole_site_restriction = true;
						break;
					}
				}
			}
		}

		// Use base template to generate styled content
		ob_start();
		urcr_get_template(
			'base-restriction-template.php',
			array(
				'message'    => $message,
				'login_url'  => $login_url,
				'signup_url' => $signup_url,
			)
		);
		$styled_content = ob_get_clean();

		if ( is_object( $post ) && isset( $post->ID ) ) {
			$post->post_content = $styled_content;

			add_filter( 'comments_open', '__return_false', PHP_INT_MAX );
			add_filter( 'pings_open', '__return_false', PHP_INT_MAX );
			add_filter( 'get_comments_number', '__return_zero', PHP_INT_MAX );
			add_filter( 'the_post_navigation', '__return_empty_string', PHP_INT_MAX );
			add_filter( 'get_next_post', '__return_null', PHP_INT_MAX );
			add_filter( 'get_previous_post', '__return_null', PHP_INT_MAX );

			// Add filter for elementor content.
			add_filter(
				'elementor/frontend/the_content',
				function () use ( $styled_content ) {
					if ( ! urcr_is_elementor_content_restricted() ) {
						urcr_set_elementor_content_restricted();
						return $styled_content;
					}
					return '';
				}
			);
		}
	}

	/**
	 * Basic Restriction Message Template
	 *
	 * @since 4.0
	 *
	 * @param  mixed $template Post Template.
	 * @param  mixed $post Post. d
	 */
	public function urcr_restrict_contents_template( $template, $post ) {
		$message = $this->message();

		$this->urcr_apply_restriction_template_to_post( $post, $message );

		// Return the original template so theme header/footer are preserved
		return $template;
	}

	public function urcr_restrict_contents() {

		global $post;

		// Check if this is a product page.
		if ( get_post_type() == 'product' ) {
			$this->restrict_products();
		}

		// Check if this is a whole site restriction
		$whole_site_access_restricted = ur_string_to_bool( get_option( 'user_registration_content_restriction_whole_site_access', false ) );
		$is_whole_site_restriction    = $whole_site_access_restricted;

		// Also check access rules for whole site restriction
		if ( ! $is_whole_site_restriction ) {
			$access_rule_posts = get_posts(
				array(
					'numberposts' => -1,
					'post_status' => 'publish',
					'post_type'   => 'urcr_access_rule',
				)
			);

			foreach ( $access_rule_posts as $access_rule_post ) {
				$access_rule = json_decode( $access_rule_post->post_content, true );
				if ( ! empty( $access_rule['target_contents'] ) && is_array( $access_rule['target_contents'] ) ) {
					$types = wp_list_pluck( $access_rule['target_contents'], 'type' );
					if ( in_array( 'whole_site', $types, true ) ) {
						$is_whole_site_restriction = true;
						break;
					}
				}
			}
		}

		// Display restriction message instead of post content.
		$restricted_message      = get_post_meta( $post->ID, 'urcr_meta_content', true );
		$override_global_message = get_post_meta( $post->ID, 'urcr_meta_override_global_settings', true );
		$message_content         = ! empty( $restricted_message ) && $override_global_message ? wp_kses_post( $restricted_message ) : $this->message();

		$post->post_content = $message_content;

		add_filter( 'comments_open', '__return_false', PHP_INT_MAX );
		add_filter( 'pings_open', '__return_false', PHP_INT_MAX );
		add_filter( 'get_comments_number', '__return_zero', PHP_INT_MAX );
		add_filter( 'the_post_navigation', '__return_empty_string', PHP_INT_MAX );
		add_filter( 'get_next_post', '__return_null', PHP_INT_MAX );
		add_filter( 'get_previous_post', '__return_null', PHP_INT_MAX );

		// Add filter for elementor content.
		add_filter( 'elementor/frontend/the_content', array( $this, 'elementor_restrict' ) );

		$get_site_origin_data = get_post_meta( $post->ID, 'panels_data' );

		$get_beaver_data = get_post_meta( $post->ID, '_fl_builder_data' );

		if ( isset( $get_site_origin_data ) && ! empty( $get_site_origin_data ) ) {
			update_post_meta( $post->ID, 'panels_data', '' );
		}

		if ( isset( $get_beaver_data ) && ! empty( $get_beaver_data ) ) {
			remove_filter( 'the_content', 'FLBuilder::render_content' );
		}
	}

	/**
	 * Get content restriction message.
	 *
	 * @return string Content restriction message.
	 */
	public function message() {

		$message = get_option( 'user_registration_content_restriction_message' );

		if ( false === $message || empty( $message ) ) {
			if ( class_exists( 'URCR_Admin_Assets' ) ) {
				$message = URCR_Admin_Assets::get_default_message();
			} else {
				$message = '<h3>' . __( 'Membership Required', 'user-registration' ) . '</h3>
<p>' . __( 'This content is available to members only.', 'user-registration' ) . '</p>
<p>' . __( 'Sign up to unlock access or log in if you already have an account.', 'user-registration' ) . '</p>
<p>{{sign_up}} {{log_in}}</p>';
			}
		}

		$message = apply_filters( 'user_registration_process_smart_tags', $message );

		return '<span class="urcr-restrict-msg">' . $message . '</span>';
	}

	/**
	 * Add and remove actions for WooCommerce pages and posts.
	 *
	 * @return void
	 */
	public function restrict_products() {

		// Add restritction notice on products page.
		add_action( 'woocommerce_after_single_product', array( $this, 'products_restriction_message' ), 10, 1 );

		// Remove all actions before shop contents.
		remove_all_actions( 'woocommerce_archive_description' );
		remove_all_actions( 'woocommerce_before_shop_loop' );
		remove_all_actions( 'woocommerce_before_shop_loop_item_title' );
		remove_all_actions( 'woocommerce_before_shop_loop_item' );

		// Add restriction notice on shop page.
		add_action( 'woocommerce_before_shop_loop', array( $this, 'products_restriction_message' ), 10, 1 );

		// Remove all actions after shop contents.
		remove_all_actions( 'woocommerce_shop_loop_item_title' );
		remove_all_actions( 'woocommerce_after_shop_loop_item_title' );
		remove_all_actions( 'woocommerce_after_shop_loop_item' );

		// Remove all
		remove_all_actions( 'woocommerce_before_single_product_summary' );
		remove_all_actions( 'woocommerce_single_product_summary' );
		remove_all_actions( 'woocommerce_after_single_product_summary' );
	}

	public function products_restriction_message() {
		echo $this->message();
	}

	/**
	 * Display restriction message for elementor content.
	 *
	 * @param  $content actual content
	 * @return string restricted content
	 */
	public function elementor_restrict( $content ) {
		return $this->message();
	}
}

return new URCR_Frontend();
