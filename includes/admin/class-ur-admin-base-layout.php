<?php
/**
 * Base Page class for pages.
 *
 * @package UserRegistration
 */

use WPEverest\URMembership\Admin\Repositories\MembershipGroupRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base Layout class for pages.
 *
 * @package UserRegistration
 */
class UR_Base_Layout {
	/**
	 * Render a standard list-table page layout for a given WP_List_Table instance.
	 *
	 * @param \WP_List_Table $table Instance of a list table (usually extends UR_List_Table).
	 * @param array          $args  Arguments to control title, add-new action, search id, and page slug.
	 *                              Supported keys: 'page', 'title', 'add_new_label', 'add_new_action', 'search_id', 'skip_query_key', 'form_id'.
	 *
	 * @return void
	 */
	public static function render_layout( $table, $args = array() ) {
		$defaults = array(
			'page'           => '',
			'tab'            => '',
			'section'        => '',
			'title'          => '',
			'add_new_label'  => esc_html__( 'Add New', 'user-registration' ),
			'add_new_action' => '',
			'search_id'      => '',
			'skip_query_key' => '',
			'form_id'        => '',
			'class'          => '',
			'add_page_key'   => '',
			'add_new_class'  => '',
			'add_new_attr'   => '',
		);

		$data        = wp_parse_args( $args, $defaults );
		$show_search = true;

		if ( ! empty( $data['skip_query_key'] ) && isset( $_GET[ $data['skip_query_key'] ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( is_object( $table ) && method_exists( $table, 'prepare_items' ) ) {
			$table->prepare_items();
		}

		if ( is_object( $table ) && method_exists( $table, 'get_pagination_arg' ) ) {
			$total_items = (int) $table->get_pagination_arg( 'total_items' );
		}

		$search_param = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$is_searching = '' !== trim( $search_param );

		$show_search = ( $total_items > 0 ) || $is_searching;

		$is_membership_page = isset( $_GET['page'] ) && 'user-registration-membership' == $_GET['page'] && ! isset( $_GET['action'] ) ? true : false;

		?>
		<div id="user-registration-base-list-table-page" class="<?php echo esc_attr( $data['class'] ); ?>">
			<div class="user-registration-base-list-table-heading" style="<?php echo( ! $show_search ? 'position:relative;' : '' ); ?>">
				<h1>
					<?php echo esc_html( $data['title'] ); ?>
				</h1>
				<?php
				$external_class = '';
				$inline_attr    = '';

				if ( ! empty( $data['add_new_action'] ) ) {
					switch ( $data['add_new_action'] ) {
						case 'manage_tax':
							$external_class = 'urm-manage-tax-region-btn';
							break;

						case 'manage_pricing_zone':
							$external_class = 'ur-local-currency-add-pricing-zone';
							$inline_attr    = 'data-action="add"';
							break;

						default:
							$external_class = '';
							break;
					}
				}

				if ( ! empty( $data['add_page_key'] ) ) :
					$external_class = ! empty( $data['add_new_class'] ) ? $data['add_new_class'] : '';
					$inline_attr    = ! empty( $data['add_new_attr'] ) ? $data['add_new_attr'] : '';
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $data['add_page_key'] ) ); ?>" class="page-title-action <?php echo esc_attr( $external_class ); ?>" <?php echo wp_kses_post( $inline_attr ); ?>>
						<?php echo esc_html( $data['add_new_label'] ); ?>
					</a>
					<?php
				elseif ( ! empty( $data['add_new_action'] ) ) :
					$add_new_url = 'admin.php?page=' . $data['page'] . '&action=' . $data['add_new_action'];
					if ( ! empty( $data['tab'] ) ) {
						$add_new_url .= '&tab=' . $data['tab'];
					}
					if ( ! empty( $data['section'] ) ) {
						$add_new_url .= '&section=' . $data['section'];
					}
					?>
					<a href="<?php echo esc_url( admin_url( $add_new_url ) ); ?>" class="page-title-action <?php echo esc_attr( $external_class ); ?>" <?php echo wp_kses_post( $inline_attr ); ?>>
						<?php echo esc_html( $data['add_new_label'] ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $is_membership_page ) : ?>
					<?php
					$membership_groups_repository = new MembershipGroupRepository();
					$membership_groups            = $membership_groups_repository->get_all_membership_groups();

					if ( empty( $membership_groups ) ) {
						?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=user-registration-membership&action=add_groups' ) ); ?>" class="page-title-action urm-create-group-btn">
							<?php echo esc_html( 'Create Group' ); ?>
						</a>
						<?php
					}
					?>
					<?php
				endif;
				?>
			</div>
			<form id="<?php echo esc_attr( $data['form_id'] ); ?>" method="get" class="user-registration-base-list-table-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( $data['page'] ); ?>"/>
				<?php if ( ! empty( $data['tab'] ) ) : ?>
					<input type="hidden" name="tab" value="<?php echo esc_attr( $data['tab'] ); ?>"/>
				<?php endif; ?>
				<?php if ( ! empty( $data['section'] ) ) : ?>
					<input type="hidden" name="section" value="<?php echo esc_attr( $data['section'] ); ?>"/>
				<?php endif; ?>
				<?php if ( $show_search ) : ?>
					<div id="user-registration-base-list-filters-row">
						<?php
						if ( is_object( $table ) && method_exists( $table, 'display_search_box' ) ) {
							$table->display_search_box( $data['search_id'] );
						}
						?>
					</div>
					<?php
				endif;
				?>
				<?php
				if ( is_object( $table ) && method_exists( $table, 'display' ) ) {
					$table->display();
				}
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Display Search Input with button
	 *
	 * @param $search_id Search id.
	 * @param $placeholder Placeholder for seasrch field.
	 *
	 * @return void
	 */
	public static function display_search_field( $search_id, $placeholder ) {
		$search_value = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		?>
		<input type="search" id="<?php echo esc_attr( $search_id ); ?>" name="s"
				value="<?php echo esc_attr( $search_value ); ?>"
				placeholder="<?php echo esc_attr( $placeholder ); ?>..."
				autocomplete="off">
		<button type="submit" id="search-submit">
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
				<path fill="#000" fill-rule="evenodd"
						d="M4 11a7 7 0 1 1 12.042 4.856 1.012 1.012 0 0 0-.186.186A7 7 0 0 1 4 11Zm12.618 7.032a9 9 0 1 1 1.414-1.414l3.675 3.675a1 1 0 0 1-1.414 1.414l-3.675-3.675Z"
						clip-rule="evenodd"></path>
			</svg>
		</button>
		<?php
	}

	/**
	 * No items found text.
	 */
	public static function no_items( $type ) {
		$image_url    = esc_url( plugin_dir_url( UR_PLUGIN_FILE ) . 'assets/images/empty-table.png' );
		$search_value = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$is_searching = '' !== trim( $search_value );

		if ( $is_searching ) {
			$primary_message   = __( 'Oops, No results found.', 'user-registration' );
			$secondary_message = sprintf(
			/* translators: %s: search term */
				__( 'Sorry no results found for <i>%s</i>.', 'user-registration' ),
				esc_html( $search_value )
			);
		} else {
			$primary_message = sprintf(
			/* translators: %s: type */
				__( 'You don’t have any %s yet.', 'user-registration' ),
				esc_html( $type )
			);

			if ( 'Memberships' === $type ) {
				$secondary_message = sprintf(
				/* translators: %s: type */
					__( 'Need help setting up your %s?', 'user-registration' ),
					esc_html( strtolower( $type ) )
				);
			} else {

				$secondary_message = sprintf(
				/* translators: %s: type */
					__( 'Please add %s and you’re good to go.', 'user-registration' ),
					esc_html( strtolower( $type ) )
				);
			}

			$video_url = 'https://www.youtube.com/playlist?list=PLcrB6drBDePkshUw7r5BNVLRwpr8RaXyy';
		}
		?>
		<div class="empty-list-table-container">
			<img src="<?php echo esc_url( $image_url ); ?>" alt="">
			<h3><?php echo esc_html( $primary_message ); ?></h3>
			<div class="empty-list-table-subtext">
				<p><?php echo wp_kses_post( $secondary_message ); ?></p>
				<?php if ( ! empty( $video_url ) && 'Memberships' === $type ) : ?>
					<a class="empty-video-url" target="_blank" href="<?php echo esc_url( $video_url ); ?>"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-external-link"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
						<?php echo 'Watch Tutorials'; ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
