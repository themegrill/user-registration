<?php
/**
 * Logs list table: one row per log source, grouping rotated files together.
 *
 * @package UserRegistration/Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

require_once __DIR__ . '/class-ur-admin-base-layout.php';

if ( ! class_exists( 'UR_Log_List_Table' ) ) :

	/**
	 * UR_Log_List_Table Class.
	 */
	class UR_Log_List_Table extends WP_List_Table {

		/**
		 * Sources shown per page.
		 *
		 * @var int
		 */
		const PER_PAGE = 10;

		/**
		 * Per-request cache of the scanned sources.
		 *
		 * @var array|null
		 */
		protected static $sources_cache = null;

		/**
		 * Whether the bulk-action column and toolbar are shown.
		 *
		 * @var bool
		 */
		protected $show_bulk = false;

		/**
		 * Whether the column headers are sortable.
		 *
		 * @var bool
		 */
		protected $show_sorting = false;

		/**
		 * Constructor.
		 */
		public function __construct() {
			parent::__construct(
				array(
					'singular' => 'log',
					'plural'   => 'logs',
					'ajax'     => false,
				)
			);
		}

		/**
		 * Category slug => translated label.
		 *
		 * @return array<string, string>
		 */
		public static function get_categories() {
			$categories = array(
				'system'     => __( 'System', 'user-registration' ),
				'forms'      => __( 'Forms', 'user-registration' ),
				'membership' => __( 'Membership', 'user-registration' ),
				'payments'   => __( 'Payments', 'user-registration' ),
				'email'      => __( 'Email', 'user-registration' ),
				'addons'     => __( 'Add-ons', 'user-registration' ),
			);

			/**
			 * Filters available log categories.
			 *
			 * @param array $categories Key-value pairs of category slug => label.
			 */
			return apply_filters( 'user_registration_log_categories', $categories );
		}

		/**
		 * Known log sources across Core, Membership, Payments, and official Add-ons.
		 *
		 * @return array<string, array{category: string, name: string}>
		 */
		public static function get_registered_sources() {
			$sources = array(
				'fatal-errors'                 => array(
					'category' => 'system',
					'name'     => __( 'Fatal Errors', 'user-registration' ),
				),
				'user-registration'            => array(
					'category' => 'system',
					'name'     => __( 'User Registration Core', 'user-registration' ),
				),
				'migration-logger'             => array(
					'category' => 'system',
					'name'     => __( 'Database Migrations', 'user-registration' ),
				),
				'ur-captcha-logs'              => array(
					'category' => 'system',
					'name'     => __( 'Captcha', 'user-registration' ),
				),
				'ur-profile-validation'        => array(
					'category' => 'system',
					'name'     => __( 'Profile Validation', 'user-registration' ),
				),
				'urm-tg-sdk-logs'              => array(
					'category' => 'system',
					'name'     => __( 'ThemeGrill SDK', 'user-registration' ),
				),
				'my-account'                   => array(
					'category' => 'system',
					'name'     => __( 'My Account', 'user-registration' ),
				),
				'form-submission'              => array(
					'category' => 'forms',
					'name'     => __( 'Form Submissions', 'user-registration' ),
				),
				'form-save'                    => array(
					'category' => 'forms',
					'name'     => __( 'Form Saves', 'user-registration' ),
				),
				'builder-fields'               => array(
					'category' => 'forms',
					'name'     => __( 'Builder Fields', 'user-registration' ),
				),
				'form-preview'                 => array(
					'category' => 'forms',
					'name'     => __( 'Form Preview', 'user-registration' ),
				),
				'form-template'                => array(
					'category' => 'forms',
					'name'     => __( 'Form Templates', 'user-registration' ),
				),
				'ur_mail_logs'                 => array(
					'category' => 'email',
					'name'     => __( 'Email Delivery', 'user-registration' ),
				),
				'ur-membership-email-logs'     => array(
					'category' => 'email',
					'name'     => __( 'Membership Emails', 'user-registration' ),
				),
				'user-registration-membership' => array(
					'category' => 'membership',
					'name'     => __( 'Membership', 'user-registration' ),
				),
				'urm-membership-crons'         => array(
					'category' => 'membership',
					'name'     => __( 'Membership Cron Jobs', 'user-registration' ),
				),
				'urm-membership-expiration'    => array(
					'category' => 'membership',
					'name'     => __( 'Membership Expiration', 'user-registration' ),
				),
				'urm-missed-payment-backfill'  => array(
					'category' => 'membership',
					'name'     => __( 'Missed Payment Backfill', 'user-registration' ),
				),
				'urm-reactivation-log'         => array(
					'category' => 'membership',
					'name'     => __( 'Membership Reactivation', 'user-registration' ),
				),
				'ur-membership-create'         => array(
					'category' => 'membership',
					'name'     => __( 'Membership Creation', 'user-registration' ),
				),
				'urm-pg-stripe'                => array(
					'category' => 'payments',
					'name'     => __( 'Payments · Stripe', 'user-registration' ),
				),
				'urm-pg-paypal'                => array(
					'category' => 'payments',
					'name'     => __( 'Payments · PayPal', 'user-registration' ),
				),
				'urm-pg-authorize-net'         => array(
					'category' => 'payments',
					'name'     => __( 'Payments · Authorize.Net', 'user-registration' ),
				),
				'urm-pg-mollie'                => array(
					'category' => 'payments',
					'name'     => __( 'Payments · Mollie', 'user-registration' ),
				),
				'ur-mailchimp'                 => array(
					'category' => 'addons',
					'name'     => __( 'Mailchimp', 'user-registration' ),
				),
				'ur-activecampaign'            => array(
					'category' => 'addons',
					'name'     => __( 'ActiveCampaign', 'user-registration' ),
				),
				'ur-brevo'                     => array(
					'category' => 'addons',
					'name'     => __( 'Brevo', 'user-registration' ),
				),
				'ur-convertkit'                => array(
					'category' => 'addons',
					'name'     => __( 'ConvertKit', 'user-registration' ),
				),
				'ur-klaviyo'                   => array(
					'category' => 'addons',
					'name'     => __( 'Klaviyo', 'user-registration' ),
				),
				'ur-mailerlite'                => array(
					'category' => 'addons',
					'name'     => __( 'MailerLite', 'user-registration' ),
				),
				'ur-mailpoet'                  => array(
					'category' => 'addons',
					'name'     => __( 'MailPoet', 'user-registration' ),
				),
				'ur-salesforce'                => array(
					'category' => 'addons',
					'name'     => __( 'Salesforce', 'user-registration' ),
				),
				'ur-zapier'                    => array(
					'category' => 'addons',
					'name'     => __( 'Zapier', 'user-registration' ),
				),
				'cloud-storage'                => array(
					'category' => 'addons',
					'name'     => __( 'Cloud Storage', 'user-registration' ),
				),
				'dropbox'                      => array(
					'category' => 'addons',
					'name'     => __( 'Dropbox', 'user-registration' ),
				),
				'google-drive'                 => array(
					'category' => 'addons',
					'name'     => __( 'Google Drive', 'user-registration' ),
				),
				'sms-notifications'            => array(
					'category' => 'addons',
					'name'     => __( 'SMS Notifications', 'user-registration' ),
				),
			);

			/**
			 * Filters registered log sources metadata.
			 *
			 * @param array $sources Map of handle => array( 'category' => slug, 'name' => label ).
			 */
			return apply_filters( 'user_registration_log_sources', $sources );
		}

		/**
		 * Friendly name and category for a log handle.
		 *
		 * Resolves registered sources first. `urm-pg-*` is the only prefix
		 * reliable enough to guess a category from — every other prefix in
		 * this codebase (`urm-`, `ur-`, `user-registration-`) is shared across
		 * multiple categories in the registered map above (e.g. `urm-tg-sdk-logs`
		 * is System, `ur-membership-create` is Membership, `ur-mailchimp` is an
		 * add-on), so guessing from them would silently mislabel new handles.
		 * A new handle in any other category is added to `get_registered_sources()`
		 * instead of taught to a pattern.
		 *
		 * @param string $handle Base handle (no rotation suffix, no hash).
		 * @return array {
		 *     @type string $category       Category slug.
		 *     @type string $category_label Translated category label.
		 *     @type string $name           Friendly name.
		 *     @type bool   $known          Whether the handle was recognised.
		 * }
		 */
		public static function describe_handle( $handle ) {
			$categories = self::get_categories();
			$registered = self::get_registered_sources();

			if ( isset( $registered[ $handle ] ) ) {
				$category = $registered[ $handle ]['category'];
				$name     = $registered[ $handle ]['name'];
				$known    = true;
			} elseif ( preg_match( '/^(?:urm-pg-)+(.*)$/', $handle, $matches ) ) {
				$category = 'payments';
				$raw_gw   = ucwords( str_replace( array( '-', '_' ), ' ', $matches[1] ) );
				$gateway  = str_ireplace( array( 'Paypal', 'Authorize Net' ), array( 'PayPal', 'Authorize.Net' ), $raw_gw );
				/* translators: %s: payment gateway name */
				$name  = sprintf( __( 'Payments · %s', 'user-registration' ), $gateway );
				$known = true;
			} else {
				$category = 'system';
				$name     = ucwords( trim( str_replace( array( '-', '_' ), ' ', $handle ) ) );
				$known    = false;
			}

			if ( ! isset( $categories[ $category ] ) ) {
				$category = 'system';
			}

			$info = array(
				'category'       => $category,
				'category_label' => $categories[ $category ],
				'name'           => $name,
				'known'          => $known,
			);

			/**
			 * Filters the description info for a log handle.
			 *
			 * @param array  $info   Log handle info (category, category_label, name, known).
			 * @param string $handle Raw handle name.
			 */
			return apply_filters( 'user_registration_log_handle_info', $info, $handle );
		}

		/**
		 * Scan UR_LOG_DIR and group files by source handle.
		 *
		 * Every log filename is `{handle}{.N?}-{32-char hex hash}.log`. The
		 * hash length is fixed (wp_hash() always returns an md5 HMAC), so the
		 * handle (with any rotation suffix) can be recovered by pattern
		 * alone — no need to know handles in advance or brute-force hashes.
		 * The file handler rotates into .0–.9 only, hence the single digit.
		 *
		 * @param bool $refresh Re-read the directory instead of using this request's cache.
		 * @return array handle => array( 'files' => array( array('filename','part','mtime','size') ), 'mtime' => int, 'size' => int )
		 */
		public static function scan_sources( $refresh = false ) {
			if ( ! $refresh && null !== self::$sources_cache ) {
				return self::$sources_cache;
			}

			$sources = array();
			$files   = @scandir( UR_LOG_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			foreach ( (array) $files as $file ) {
				if ( ! preg_match( '/^(.+)-[0-9a-f]{32}\.log$/', $file, $m ) ) {
					continue;
				}

				$base_handle = $m[1];
				$part        = 'current';

				if ( preg_match( '/^(.*)\.(\d)$/', $base_handle, $mm ) ) {
					$base_handle = $mm[1];
					$part        = (int) $mm[2];
				}

				$path  = trailingslashit( UR_LOG_DIR ) . $file;
				$mtime = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$size  = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				if ( ! isset( $sources[ $base_handle ] ) ) {
					$sources[ $base_handle ] = array(
						'files' => array(),
						'mtime' => 0,
						'size'  => 0,
					);
				}

				$sources[ $base_handle ]['files'][] = array(
					'filename' => $file,
					'part'     => $part,
					'mtime'    => $mtime,
					'size'     => $size,
				);
				$sources[ $base_handle ]['mtime']   = max( $sources[ $base_handle ]['mtime'], $mtime );
				$sources[ $base_handle ]['size']   += $size;
			}

			foreach ( array_keys( $sources ) as $handle ) {
				usort(
					$sources[ $handle ]['files'],
					function ( $a, $b ) {
						return $b['mtime'] <=> $a['mtime'];
					}
				);
			}

			self::$sources_cache = $sources;

			return $sources;
		}

		/**
		 * Table classes: core's, plus a hook for this table's styling.
		 *
		 * @return array
		 */
		protected function get_table_classes() {
			return array_merge( parent::get_table_classes(), array( 'ur-logs-table' ) );
		}

		/**
		 * Column definitions. The 'cb' checkbox column is added when bulk actions are available (2+ sources).
		 *
		 * @return array
		 */
		public function get_columns() {
			$columns = array();

			if ( $this->show_bulk ) {
				$columns['cb'] = '<input type="checkbox" />';
			}

			return $columns + array(
				'log'          => __( 'Log', 'user-registration' ),
				'category'     => __( 'Category', 'user-registration' ),
				'files'        => __( 'Files', 'user-registration' ),
				'size'         => __( 'Size', 'user-registration' ),
				'last_updated' => __( 'Last Updated', 'user-registration' ),
			);
		}

		/**
		 * Sortable columns; none until there are enough rows for sorting to matter.
		 *
		 * @return array
		 */
		public function get_sortable_columns() {
			if ( ! $this->show_sorting ) {
				return array();
			}

			return array(
				'log'          => array( 'log', false ),
				'category'     => array( 'category', false ),
				'size'         => array( 'size', false ),
				'last_updated' => array( 'last_updated', true ),
			);
		}

		/**
		 * Bulk actions; none with fewer than 2 rows.
		 *
		 * @return array
		 */
		public function get_bulk_actions() {
			return $this->show_bulk ? array( 'delete' => __( 'Delete', 'user-registration' ) ) : array();
		}

		/**
		 * Bulk actions dropdown with Title Case label.
		 *
		 * @param string $which 'top' or 'bottom'.
		 */
		protected function bulk_actions( $which = '' ) {
			if ( is_null( $this->_actions ) ) {
				$this->_actions = $this->get_bulk_actions();
				$this->_actions = apply_filters( "bulk_actions-{$this->screen->id}", $this->_actions ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
				$two            = '';
			} else {
				$two = '2';
			}

			if ( empty( $this->_actions ) ) {
				return;
			}

			echo '<label for="bulk-action-selector-' . esc_attr( $which ) . '" class="screen-reader-text">' . esc_html__( 'Select bulk action', 'user-registration' ) . '</label>';
			echo '<select name="action' . esc_attr( $two ) . '" id="bulk-action-selector-' . esc_attr( $which ) . "\">\n";
			echo '<option value="-1">' . esc_html__( 'Bulk Actions', 'user-registration' ) . "</option>\n";

			foreach ( $this->_actions as $name => $title ) {
				$class = 'edit' === $name ? 'hide-if-no-js' : '';

				echo "\t" . '<option value="' . esc_attr( $name ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $title ) . "</option>\n";
			}

			echo "</select>\n";

			submit_button( __( 'Apply', 'user-registration' ), 'action', '', false, array( 'id' => "doaction$two" ) );
			echo "\n";
		}

		/**
		 * Checkbox column.
		 *
		 * @param array $item Row item.
		 * @return string
		 */
		public function column_cb( $item ) {
			return sprintf( '<input type="checkbox" name="sources[]" value="%s" />', esc_attr( $item['handle'] ) );
		}

		/**
		 * Human-readable file size.
		 *
		 * @param int $bytes Bytes.
		 * @return string
		 */
		public static function format_size( $bytes ) {
			if ( $bytes >= 1048576 ) {
				return round( $bytes / 1048576, 1 ) . ' MB';
			}
			if ( $bytes >= 1024 ) {
				return round( $bytes / 1024, 1 ) . ' KB';
			}
			return $bytes . ' B';
		}

		/**
		 * "Log" column: friendly name, raw handle when unmapped, and row actions.
		 *
		 * @param array $item Row item.
		 * @return string
		 */
		public function column_log( $item ) {
			$base = array(
				'page'    => 'user-registration-settings',
				'tab'     => 'tools',
				'section' => 'logs',
			);

			$view_url   = add_query_arg( $base + array( 'log' => $item['handle'] ), admin_url( 'admin.php' ) );
			$delete_url = wp_nonce_url( add_query_arg( $base + array( 'handle' => $item['handle'] ), admin_url( 'admin.php' ) ), 'remove_log' );

			$name = '<a class="row-title" href="' . esc_url( $view_url ) . '">' . esc_html( $item['name'] ) . '</a>';

			if ( ! $item['known'] ) {
				$name .= '<code class="ur-log-handle">' . esc_html( $item['handle'] ) . '</code>';
			}

			$file_count = count( $item['files'] );
			$file_str   = sprintf(
				/* translators: %d: number of files */
				_n( '%d file', '%d files', $file_count, 'user-registration' ),
				$file_count
			);

			$mobile_sub = sprintf(
				'<span class="ur-log-mobile-sub">%s · %s · %s</span>',
				esc_html( wp_date( _x( 'M j, g:i A', 'log mobile date', 'user-registration' ), $item['mtime'] ) ),
				esc_html( $file_str ),
				esc_html( self::format_size( $item['size'] ) )
			);

			$actions = array(
				'view'   => '<a href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'user-registration' ) . '</a>',
				'delete' => '<a class="ur-log-delete-link" href="' . esc_url( $delete_url ) . '" data-name="' . esc_attr( $item['name'] ) . '" data-files="' . esc_attr( $file_count ) . '" data-type="single">' . esc_html__( 'Delete', 'user-registration' ) . '</a>',
			);

			return $name . $mobile_sub . $this->row_actions( $actions );
		}

		/**
		 * Default column renderer.
		 *
		 * @param array  $item        Row item.
		 * @param string $column_name Column key.
		 * @return string
		 */
		public function column_default( $item, $column_name ) {
			switch ( $column_name ) {
				case 'category':
					return esc_html( $item['category_label'] );
				case 'files':
					return (string) count( $item['files'] );
				case 'size':
					return esc_html( self::format_size( $item['size'] ) );
				case 'last_updated':
					return esc_html( wp_date( _x( 'M j, Y, g:i A', 'log list date format', 'user-registration' ), $item['mtime'] ) );
				default:
					return '';
			}
		}

		/**
		 * Category filter, shown once there are enough sources in 2+ categories
		 * (or while a filter is active, so it can be cleared).
		 *
		 * @param string $which 'top' or 'bottom'.
		 */
		public function extra_tablenav( $which ) {
			if ( 'top' !== $which ) {
				return;
			}

			$current = $this->get_requested_category();
			$sources = self::scan_sources();
			$present = array();

			foreach ( array_keys( $sources ) as $handle ) {
				$present[ self::describe_handle( $handle )['category'] ] = true;
			}

			$show = '' !== $current || count( $present ) >= 2;

			if ( ! $show ) {
				return;
			}

			$labels      = self::get_categories();
			$is_filtered = '' !== $current || ! empty( $this->get_requested_search() );
			?>
			<div class="alignleft actions ur-log-filter">
				<label class="screen-reader-text" for="ur-log-category"><?php esc_html_e( 'Filter by category', 'user-registration' ); ?></label>
				<select name="log_category" id="ur-log-category">
					<option value=""><?php esc_html_e( 'All Categories', 'user-registration' ); ?></option>
					<?php foreach ( $labels as $slug => $label ) : ?>
						<?php
						if ( empty( $present[ $slug ] ) && $slug !== $current ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" id="ur-log-filter-reset-btn" class="button button-reset ur-log-filter-reset-btn<?php echo $is_filtered ? '' : ' disabled'; ?>" <?php echo $is_filtered ? '' : 'disabled aria-disabled="true"'; ?> title="<?php esc_attr_e( 'Reset', 'user-registration' ); ?>" aria-label="<?php esc_attr_e( 'Reset', 'user-registration' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path fill="currentColor" fill-rule="evenodd" d="M12 2h-.004a10.75 10.75 0 0 0-7.431 3.021l-.012.012L4 5.586V3a1 1 0 1 0-2 0v5a.997.997 0 0 0 1 1h5a1 1 0 0 0 0-2H5.414l.547-.547A8.75 8.75 0 0 1 12.001 4 8 8 0 1 1 4 12a1 1 0 1 0-2 0A10 10 0 1 0 12 2Z" clip-rule="evenodd"/>
					</svg>
				</button>
			</div>
			<?php
		}

		/**
		 * Requested category slug ('' for all).
		 *
		 * @return string
		 */
		protected function get_requested_category() {
			$category = isset( $_REQUEST['log_category'] ) ? sanitize_key( wp_unslash( $_REQUEST['log_category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

			return isset( self::get_categories()[ $category ] ) ? $category : '';
		}

		/**
		 * Requested search term.
		 *
		 * @return string
		 */
		protected function get_requested_search() {
			return isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		}

		/**
		 * Whether the search field should be shown (enough sources, or a search is active).
		 *
		 * @return bool
		 */
		public function should_show_search() {
			return '' !== $this->get_requested_search() || count( self::scan_sources() ) >= 1;
		}

		/**
		 * Build $this->items from disk, applying search, filter, sort and pagination,
		 * and decide which controls the current result set earns.
		 */
		public function prepare_items() {
			$search   = $this->get_requested_search();
			$category = $this->get_requested_category();
			$order    = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification
			$sort_map = array(
				'log'          => 'name',
				'category'     => 'category_label',
				'size'         => 'size',
				'last_updated' => 'mtime',
			);
			$orderby  = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'last_updated'; // phpcs:ignore WordPress.Security.NonceVerification
			$sort_key = isset( $sort_map[ $orderby ] ) ? $sort_map[ $orderby ] : 'mtime';

			$items = array();

			foreach ( self::scan_sources() as $handle => $data ) {
				$items[] = array_merge(
					self::describe_handle( $handle ),
					array(
						'handle' => $handle,
						'files'  => $data['files'],
						'mtime'  => $data['mtime'],
						'size'   => $data['size'],
					)
				);
			}

			if ( '' !== $search ) {
				$needle = strtolower( $search );
				$items  = array_filter(
					$items,
					function ( $item ) use ( $needle ) {
						return false !== strpos( strtolower( $item['name'] ), $needle )
							|| false !== strpos( strtolower( $item['handle'] ), $needle )
							|| false !== strpos( strtolower( $item['category_label'] ), $needle );
					}
				);
			}

			if ( '' !== $category ) {
				$items = array_filter(
					$items,
					function ( $item ) use ( $category ) {
						return $item['category'] === $category;
					}
				);
			}

			usort(
				$items,
				function ( $a, $b ) use ( $sort_key, $order ) {
					$cmp = is_string( $a[ $sort_key ] ) ? strcasecmp( $a[ $sort_key ], $b[ $sort_key ] ) : $a[ $sort_key ] <=> $b[ $sort_key ];

					return 'asc' === $order ? $cmp : -$cmp;
				}
			);

			$total_items        = count( $items );
			$this->show_bulk    = $total_items >= 2;
			$this->show_sorting = $total_items >= 1;

			$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'log' );

			$this->items = array_slice( $items, ( $this->get_pagenum() - 1 ) * self::PER_PAGE, self::PER_PAGE );

			$this->set_pagination_args(
				array(
					'total_items' => $total_items,
					'per_page'    => self::PER_PAGE,
					'total_pages' => (int) ceil( $total_items / self::PER_PAGE ),
				)
			);
		}

		/**
		 * Toolbar above/below the table.
		 *
		 * Same as core, except the bulk-action nonce is emitted under its own
		 * field name: the Settings page form already carries a `_wpnonce`, and
		 * two fields with the same name would let the wrong one win.
		 *
		 * @param string $which 'top' or 'bottom'.
		 */
		protected function display_tablenav( $which ) {
			if ( 'bottom' === $which && ! $this->has_items() ) {
				return;
			}

			if ( 'top' === $which ) {
				wp_nonce_field( 'bulk-logs', 'ur_logs_nonce', false );
			}
			?>
			<div class="tablenav <?php echo esc_attr( $which ); ?>">
				<?php if ( 'top' === $which ) : ?>
					<div class="alignleft actions bulkactions<?php echo $this->show_bulk ? '' : ' hidden'; ?>">
						<?php $this->bulk_actions( $which ); ?>
					</div>
					<?php $this->extra_tablenav( $which ); ?>
				<?php else : ?>
					<?php $this->pagination( $which ); ?>
				<?php endif; ?>
				<br class="clear" />
			</div>
			<?php
		}

		/**
		 * Display pagination at the bottom toolbar, matching the members list table.
		 *
		 * @param string $which 'top' or 'bottom'.
		 */
		protected function pagination( $which ) {
			if ( 'top' === $which || empty( $this->_pagination_args ) ) {
				return;
			}

			$total_items = (int) $this->_pagination_args['total_items'];
			$total_pages = (int) $this->_pagination_args['total_pages'];

			if ( $total_pages <= 1 ) {
				return;
			}

			$current  = $this->get_pagenum();
			$base_url = remove_query_arg( 'paged' );
			$links    = array();

			if ( $current > 1 ) {
				$links[] = sprintf( '<a class="first-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&laquo;</span></a>', esc_url( add_query_arg( 'paged', 1, $base_url ) ), esc_html__( 'First page', 'user-registration' ) );
				$links[] = sprintf( '<a class="prev-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&lsaquo;</span></a>', esc_url( add_query_arg( 'paged', max( 1, $current - 1 ), $base_url ) ), esc_html__( 'Previous page', 'user-registration' ) );
			} else {
				$links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
				$links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
			}

			$links[] = sprintf(
				'<span class="paging-input"><span class="tablenav-paging-text">%d %s <span class="total-pages">%d</span></span></span>',
				$current,
				esc_html_x( 'of', 'paging', 'user-registration' ),
				$total_pages
			);

			if ( $current < $total_pages ) {
				$links[] = sprintf( '<a class="next-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&rsaquo;</span></a>', esc_url( add_query_arg( 'paged', min( $total_pages, $current + 1 ), $base_url ) ), esc_html__( 'Next page', 'user-registration' ) );
				$links[] = sprintf( '<a class="last-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&raquo;</span></a>', esc_url( add_query_arg( 'paged', $total_pages, $base_url ) ), esc_html__( 'Last page', 'user-registration' ) );
			} else {
				$links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
				$links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
			}

			$output = '<span class="pagination-links">' . implode( '', $links ) . '</span>';

			echo "<div class=\"tablenav-pages\">{$output}</div>"; // phpcs:ignore WordPress.Security.EscapeOutput -- $links are built from esc_url()/esc_html__() above.
		}

		/**
		 * Empty-state message for a search/filter with no matches.
		 */
		public function no_items() {
			UR_Base_Layout::no_items(
				__( 'logs', 'user-registration' ),
				__( 'Logs appear here when an email, payment or fatal error needs your attention.', 'user-registration' )
			);
		}
	}

endif;
