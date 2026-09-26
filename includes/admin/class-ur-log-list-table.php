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
		 * How many sources to show per page.
		 *
		 * @var int
		 */
		const PER_PAGE = 20;

		/**
		 * Known handle => array( category, friendly name ). Anything else is
		 * humanised from its handle at render time, and payment-gateway
		 * handles (urm-pg-*) are matched dynamically below.
		 *
		 * @var array
		 */
		const HANDLE_MAP = array(
			'fatal-errors'                 => array( 'System', 'Fatal errors' ),
			'ur_mail_logs'                 => array( 'Email', 'Email log' ),
			'ur-membership-email-logs'     => array( 'Email', 'Membership emails' ),
			'form-submission'              => array( 'Forms', 'Form submissions' ),
			'form-save'                    => array( 'Forms', 'Form saves' ),
			'builder-fields'               => array( 'Forms', 'Builder fields' ),
			'user-registration-membership' => array( 'Membership', 'Membership' ),
			'urm-membership-crons'         => array( 'Membership', 'Membership cron jobs' ),
			'urm-membership-expiration'    => array( 'Membership', 'Membership expiration' ),
			'urm-missed-payment-backfill'  => array( 'Membership', 'Missed payment backfill' ),
			'urm-reactivation-log'         => array( 'Membership', 'Reactivation log' ),
			'ur-profile-validation'        => array( 'System', 'Profile validation' ),
			'ur-captcha-logs'              => array( 'System', 'Captcha' ),
			'urm-tg-sdk-logs'              => array( 'System', 'ThemeGrill SDK' ),
			'migration-logger'             => array( 'System', 'Migration' ),
			'my-account'                   => array( 'System', 'My account' ),
			'user-registration'            => array( 'System', 'User registration' ),
		);

		/**
		 * Handle => grouped file info, populated by prepare_log_sources().
		 *
		 * @var array
		 */
		protected $sources = array();

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
		 * Friendly name and category for a log handle.
		 *
		 * @param string $handle Base handle (no rotation suffix, no hash).
		 * @return array {
		 *     @type string $category Category label.
		 *     @type string $name     Friendly name.
		 *     @type bool   $known    Whether the handle was recognised.
		 * }
		 */
		public static function describe_handle( $handle ) {
			if ( isset( self::HANDLE_MAP[ $handle ] ) ) {
				return array(
					'category' => self::HANDLE_MAP[ $handle ][0],
					'name'     => self::HANDLE_MAP[ $handle ][1],
					'known'    => true,
				);
			}

			if ( 0 === strpos( $handle, 'urm-pg-' ) ) {
				$gateway = substr( $handle, strlen( 'urm-pg-' ) );
				$gateway = ucwords( str_replace( array( '-', '_' ), ' ', $gateway ) );

				return array(
					'category' => 'Payments',
					/* translators: %s: payment gateway name */
					'name'     => sprintf( __( 'Payments · %s', 'user-registration' ), $gateway ),
					'known'    => true,
				);
			}

			return array(
				'category' => __( 'Other', 'user-registration' ),
				'name'     => ucwords( str_replace( array( '-', '_' ), ' ', $handle ) ),
				'known'    => false,
			);
		}

		/**
		 * Scan UR_LOG_DIR and group files by source handle.
		 *
		 * Every log filename is `{handle}{.N?}-{32-char hex hash}.log`. The
		 * hash length is fixed (wp_hash() always returns an md5 HMAC), so the
		 * handle (with any rotation suffix) can be recovered by pattern
		 * alone — no need to know handles in advance or brute-force hashes.
		 *
		 * @return array handle => array( 'files' => array( array('filename','part','mtime','size') ), 'mtime' => int, 'size' => int )
		 */
		public static function scan_sources() {
			$sources = array();
			$files   = @scandir( UR_LOG_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( empty( $files ) ) {
				return $sources;
			}

			foreach ( $files as $file ) {
				if ( ! preg_match( '/^(.+)-[0-9a-f]{32}\.log$/', $file, $m ) ) {
					continue;
				}

				$with_suffix = $m[1];
				$base_handle = $with_suffix;
				$part        = 'current';

				if ( preg_match( '/^(.*)\.(\d)$/', $with_suffix, $mm ) ) {
					$base_handle = $mm[1];
					$part        = (int) $mm[2];
				}

				$path = trailingslashit( UR_LOG_DIR ) . $file;
				$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$size  = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

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
					'mtime'    => (int) $mtime,
					'size'     => (int) $size,
				);
				$sources[ $base_handle ]['mtime']    = max( $sources[ $base_handle ]['mtime'], (int) $mtime );
				$sources[ $base_handle ]['size']    += (int) $size;
			}

			foreach ( array_keys( $sources ) as $handle ) {
				usort(
					$sources[ $handle ]['files'],
					function ( $a, $b ) {
						return $b['mtime'] <=> $a['mtime'];
					}
				);
			}

			return $sources;
		}

		/**
		 * Column definitions.
		 *
		 * @return array
		 */
		public function get_columns() {
			return array(
				'cb'           => '<input type="checkbox" />',
				'log'          => __( 'Log', 'user-registration' ),
				'category'     => __( 'Category', 'user-registration' ),
				'files'        => __( 'Files', 'user-registration' ),
				'size'         => __( 'Size', 'user-registration' ),
				'last_updated' => __( 'Last updated', 'user-registration' ),
			);
		}

		/**
		 * Sortable columns.
		 *
		 * @return array
		 */
		public function get_sortable_columns() {
			return array(
				'log'          => array( 'log', false ),
				'category'     => array( 'category', false ),
				'size'         => array( 'size', false ),
				'last_updated' => array( 'last_updated', true ),
			);
		}

		/**
		 * Bulk actions.
		 *
		 * @return array
		 */
		public function get_bulk_actions() {
			return array(
				'delete' => __( 'Delete', 'user-registration' ),
			);
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
		 * "Log" column: friendly name, row actions, raw handle when unmapped.
		 *
		 * @param array $item Row item.
		 * @return string
		 */
		public function column_log( $item ) {
			$view_url = add_query_arg(
				array(
					'page'    => 'user-registration-settings',
					'tab'     => 'tools',
					'section' => 'logs',
					'log'     => rawurlencode( $item['handle'] ),
				),
				admin_url( 'admin.php' )
			);

			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'    => 'user-registration-settings',
						'tab'     => 'tools',
						'section' => 'logs',
						'handle'  => rawurlencode( $item['handle'] ),
					),
					admin_url( 'admin.php' )
				),
				'remove_log'
			);

			$name = '<a class="row-title" href="' . esc_url( $view_url ) . '">' . esc_html( $item['name'] ) . '</a>';

			if ( ! $item['known'] ) {
				$name .= '<br><code class="ur-log-handle">' . esc_html( $item['handle'] ) . '</code>';
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
					return esc_html( $item['category'] );
				case 'files':
					return (int) count( $item['files'] );
				case 'size':
					return esc_html( self::format_size( $item['size'] ) );
				case 'last_updated':
					return esc_html(
						date_i18n(
							get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
							$item['mtime']
						)
					);
				default:
					return '';
			}
		}

		/**
		 * Category filter dropdown, shown in the table's top toolbar.
		 *
		 * @param string $which 'top' or 'bottom'.
		 */
		public function extra_tablenav( $which ) {
			if ( 'top' !== $which ) {
				return;
			}

			$categories = array();
			foreach ( array_keys( self::scan_sources() ) as $handle ) {
				$categories[] = self::describe_handle( $handle )['category'];
			}
			$categories = array_unique( $categories );
			sort( $categories );

			if ( count( $categories ) < 2 ) {
				return;
			}

			$current = isset( $_REQUEST['log_category'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['log_category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			?>
			<div class="alignleft actions">
				<select name="log_category">
					<option value=""><?php esc_html_e( 'All categories', 'user-registration' ); ?></option>
					<?php foreach ( $categories as $category ) : ?>
						<option value="<?php echo esc_attr( $category ); ?>" <?php selected( $current, $category ); ?>><?php echo esc_html( $category ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Filter', 'user-registration' ), '', 'filter_action', false ); ?>
			</div>
			<?php
		}

		/**
		 * Build $this->items from disk, applying search, filter, sort and pagination.
		 */
		public function prepare_items() {
			$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

			$search   = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$category = isset( $_REQUEST['log_category'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['log_category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$orderby  = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'last_updated'; // phpcs:ignore WordPress.Security.NonceVerification
			$order    = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification

			$items = array();

			foreach ( self::scan_sources() as $handle => $data ) {
				$info = self::describe_handle( $handle );

				$items[] = array(
					'handle'   => $handle,
					'name'     => $info['name'],
					'category' => $info['category'],
					'known'    => $info['known'],
					'files'    => $data['files'],
					'mtime'    => $data['mtime'],
					'size'     => $data['size'],
				);
			}

			if ( '' !== $search ) {
				$needle = strtolower( $search );
				$items  = array_filter(
					$items,
					function ( $item ) use ( $needle ) {
						return false !== strpos( strtolower( $item['name'] ), $needle )
							|| false !== strpos( strtolower( $item['handle'] ), $needle )
							|| false !== strpos( strtolower( $item['category'] ), $needle );
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
				function ( $a, $b ) use ( $orderby, $order ) {
					$va = $a[ $orderby ] ?? $a['mtime'];
					$vb = $b[ $orderby ] ?? $b['mtime'];
					if ( is_string( $va ) ) {
						$cmp = strcasecmp( $va, $vb );
					} else {
						$cmp = $va <=> $vb;
					}
					return 'asc' === $order ? $cmp : -$cmp;
				}
			);

			$total_items = count( $items );
			$per_page    = self::PER_PAGE;
			$current     = $this->get_pagenum();
			$items       = array_slice( $items, ( $current - 1 ) * $per_page, $per_page );

			$this->items = $items;

			$this->set_pagination_args(
				array(
					'total_items' => $total_items,
					'per_page'    => $per_page,
					'total_pages' => (int) ceil( $total_items / $per_page ),
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
				<div class="alignleft actions bulkactions<?php echo $this->has_items() ? '' : ' hidden'; ?>">
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
		 * Empty-state message.
		 */
		public function no_items() {
			UR_Base_Layout::no_items(
				'logs',
				__( 'Logs appear here when an email, payment or fatal error needs your attention.', 'user-registration' )
			);
		}
	}

endif;
