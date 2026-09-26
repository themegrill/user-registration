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

include_once __DIR__ . '/class-ur-admin-base-layout.php';

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
		const PER_PAGE = 20;

		/**
		 * Search, category filter and sortable headers only appear once there
		 * are at least this many sources/rows; below that the whole list is
		 * visible at a glance and the controls are just noise.
		 *
		 * @var int
		 */
		const MIN_ROWS_FOR_CONTROLS = 10;

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
		 * @return array
		 */
		public static function get_categories() {
			return array(
				'payments'     => __( 'Payments', 'user-registration' ),
				'membership'   => __( 'Membership', 'user-registration' ),
				'email'        => __( 'Email', 'user-registration' ),
				'integrations' => __( 'Integrations', 'user-registration' ),
				'forms'        => __( 'Forms', 'user-registration' ),
				'system'       => __( 'System', 'user-registration' ),
				'other'        => __( 'Other', 'user-registration' ),
			);
		}

		/**
		 * Known log handle => array( category slug, friendly name ).
		 *
		 * @return array
		 */
		protected static function get_handle_map() {
			return array(
				'fatal-errors'                 => array( 'system', __( 'Fatal errors', 'user-registration' ) ),
				'ur_mail_logs'                 => array( 'email', __( 'Email log', 'user-registration' ) ),
				'ur-membership-email-logs'     => array( 'email', __( 'Membership emails', 'user-registration' ) ),
				'form-submission'              => array( 'forms', __( 'Form submissions', 'user-registration' ) ),
				'form-save'                    => array( 'forms', __( 'Form saves', 'user-registration' ) ),
				'builder-fields'               => array( 'forms', __( 'Builder fields', 'user-registration' ) ),
				'user-registration-membership' => array( 'membership', __( 'Membership', 'user-registration' ) ),
				'urm-membership-crons'         => array( 'membership', __( 'Membership cron jobs', 'user-registration' ) ),
				'urm-membership-expiration'    => array( 'membership', __( 'Membership expiration', 'user-registration' ) ),
				'urm-missed-payment-backfill'  => array( 'membership', __( 'Missed payment backfill', 'user-registration' ) ),
				'urm-reactivation-log'         => array( 'membership', __( 'Reactivation log', 'user-registration' ) ),
				'ur-mailchimp'                 => array( 'integrations', __( 'Mailchimp', 'user-registration' ) ),
				'ur-mailerlite'                => array( 'integrations', __( 'MailerLite', 'user-registration' ) ),
				'ur-mailpoet'                  => array( 'integrations', __( 'MailPoet', 'user-registration' ) ),
				'ur-profile-validation'        => array( 'system', __( 'Profile validation', 'user-registration' ) ),
				'ur-captcha-logs'              => array( 'system', __( 'Captcha', 'user-registration' ) ),
				'urm-tg-sdk-logs'              => array( 'system', __( 'ThemeGrill SDK', 'user-registration' ) ),
				'migration-logger'             => array( 'system', __( 'Migration', 'user-registration' ) ),
				'my-account'                   => array( 'system', __( 'My account', 'user-registration' ) ),
				'user-registration'            => array( 'system', __( 'User registration', 'user-registration' ) ),
			);
		}

		/**
		 * Friendly name and category for a log handle.
		 *
		 * Payment-gateway handles (urm-pg-*) are matched dynamically; any
		 * other unrecognised handle is humanised and filed under "Other".
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
			$map        = self::get_handle_map();

			if ( isset( $map[ $handle ] ) ) {
				$category = $map[ $handle ][0];
				$name     = $map[ $handle ][1];
				$known    = true;
			} elseif ( 0 === strpos( $handle, 'urm-pg-' ) ) {
				$category = 'payments';
				$gateway  = ucwords( str_replace( array( '-', '_' ), ' ', substr( $handle, strlen( 'urm-pg-' ) ) ) );
				/* translators: %s: payment gateway name */
				$name  = sprintf( __( 'Payments · %s', 'user-registration' ), $gateway );
				$known = true;
			} else {
				$category = 'other';
				$name     = ucwords( str_replace( array( '-', '_' ), ' ', $handle ) );
				$known    = false;
			}

			return array(
				'category'       => $category,
				'category_label' => $categories[ $category ],
				'name'           => $name,
				'known'          => $known,
			);
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
		 * Column definitions. The checkbox column only exists with 2+ rows.
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
				'last_updated' => __( 'Last updated', 'user-registration' ),
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

			$actions = array(
				'view'   => '<a href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'user-registration' ) . '</a>',
				'delete' => '<a class="ur-log-delete-link" href="' . esc_url( $delete_url ) . '" data-confirm="' . esc_attr__( 'Delete this log permanently?', 'user-registration' ) . '">' . esc_html__( 'Delete', 'user-registration' ) . '</a>',
			);

			return $name . $this->row_actions( $actions );
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

			$show = '' !== $current || ( count( $sources ) >= self::MIN_ROWS_FOR_CONTROLS && count( $present ) >= 2 );

			if ( ! $show ) {
				return;
			}

			$labels = self::get_categories();
			?>
			<div class="alignleft actions ur-log-filter">
				<label class="screen-reader-text" for="ur-log-category"><?php esc_html_e( 'Filter by category', 'user-registration' ); ?></label>
				<select name="log_category" id="ur-log-category">
					<option value=""><?php esc_html_e( 'All categories', 'user-registration' ); ?></option>
					<?php foreach ( $labels as $slug => $label ) : ?>
						<?php
						if ( empty( $present[ $slug ] ) && $slug !== $current ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Filter', 'user-registration' ), 'button-tertiary', 'filter_action', false ); ?>
				<?php if ( '' !== $current || $this->get_requested_search() ) : ?>
					<a class="ur-log-clear" href="<?php echo esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=tools&section=logs' ) ); ?>"><?php esc_html_e( 'Clear', 'user-registration' ); ?></a>
				<?php endif; ?>
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
			return '' !== $this->get_requested_search() || count( self::scan_sources() ) >= self::MIN_ROWS_FOR_CONTROLS;
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
			$this->show_sorting = $total_items >= self::MIN_ROWS_FOR_CONTROLS;

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
				<div class="alignleft actions bulkactions<?php echo $this->show_bulk ? '' : ' hidden'; ?>">
					<?php $this->bulk_actions( $which ); ?>
				</div>
				<?php
				$this->extra_tablenav( $which );
				$this->pagination( $which );
				?>
				<br class="clear" />
			</div>
			<?php
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
