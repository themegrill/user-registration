<?php
ob_start();

$currency     = get_option( 'user_registration_payment_currency', 'USD' );
$currencies   = ur_payment_integration_get_currencies();
$symbol       = $currencies[ $currency ]['symbol'] ?? '$';
$post_content = isset( $order_detail['post_content'] ) ? json_decode( wp_unslash( $order_detail['post_content'] ), true ) : [];
$trial_status = $order_detail['trial_status'] ?? 'off';
$default_na   = esc_html__( 'N/A', 'user-registration' );
$product_amount = isset($order_detail['plan_details']['amount']) ? $order_detail['plan_details']['amount'] : 0;
//all fields
$fields = [
	'transaction_id'   => 'Transaction ID',
	'full_name'        => 'Full Name',
	'user_name'        => 'User Name',
	'user_email'       => 'Payer Email',
	'membership'       => 'Membership',
	'membership_type'  => 'Membership Type',
	'payment_method'   => 'Payment Gateway',
	'created_at'       => 'Payment Date',
	'notes'            => 'Order Note',
	'status'           => 'Transaction Status',
	'product_amount'   => 'Product Amount',
	'trial_order'      => 'Trial Order',
	'trial_start_date' => 'Trial Start Date',
	'trial_end_date'   => 'Trial End Date',
	'coupon'           => 'Coupon',
	'coupon_discount'  => 'Coupon Discount',
	'total'            => 'Total',
];

foreach ( $fields as $key => $label ):
	?>
	<div class="payment-detail-box">
		<div class="payment-detail-label"><?php echo esc_html__( $label, 'user-registration' ); ?></div>
		<div class="payment-detail-data">
			<?php
			switch ( $key ) {
				case 'transaction_id':
					$value = $order_detail['transaction_id'] ?? ( $order_detail['order_id'] ?? $default_na );
					echo esc_html( $value );
					break;

				case 'full_name':
					echo esc_html( ucwords( str_replace( '-', ' ', $order_detail['user_nicename'] ) ) );
					break;

				case 'user_name':
					echo esc_html( $order_detail['display_name'] );
					break;

				case 'user_email':
					echo esc_html( $order_detail['user_email'] );
					break;

				case 'membership':
					echo isset( $order_detail['order_id'] ) ? esc_html( $order_detail['post_title'] ) : $default_na;
					break;

				case 'membership_type':
					echo isset( $order_detail['order_id'] ) ? esc_html( ucfirst( $post_content['type'] ?? '' ) ) : $default_na;
					break;

				case 'payment_method':
					echo esc_html( ur_get_payment_gateway_label( $order_detail['payment_method'] ) );
					break;

				case 'created_at':
					echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $order_detail['created_at'] ) ) );
					break;

				case 'notes':
					echo '<i>' . esc_html( ucfirst( $order_detail['notes'] ?? '' ) ) . '</i>';
					break;

				case 'status':
					$status = $order_detail['status'];
					echo '<span class="payment-status-btn ' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
					if ( $status === 'pending' && lcfirst( $order_detail['payment_method'] )  === 'bank' ) {
						echo ' <a href="javascript:void(0)" class="approve-payment" data-order-id="' . absint( $order_detail['order_id'] ) . '">' . esc_html__( 'Approve', 'user-registration' ) . '</a>';
					}
					break;

				case 'product_amount':

					echo $symbol . number_format($product_amount, 2);
					break;

				case 'trial_order':
					$completed = ( $trial_status === 'on' );
					echo '<span class="payment-status-btn ' . esc_attr( $completed ? 'completed' : 'pending' ) . '">' .
						 esc_html__( $completed ? '✓' : 'x', 'user-registration' ) . '</span>';
					break;

				case 'trial_start_date':
				case 'trial_end_date':
					$value = $order_detail[ $key ] ?? '';
					echo ! empty( $value ) ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $value ) ) ) : $default_na;
					break;

				case 'coupon':
					echo ! empty( $order_detail['coupon'] ) ? esc_html( $order_detail['coupon'] ) : $default_na;
					break;

				case 'coupon_discount':
					$coupon_discount = $order_detail['coupon_discount'] ?? '';
					$type            = $order_detail['coupon_discount_type'] ?? '';
					if ( $type === 'percent' ) {
						echo esc_html( $coupon_discount . '%' );
					} elseif ( $type === 'fixed' ) {
						echo esc_html( $symbol . $coupon_discount );
					} else {
						echo $default_na;
					}
					break;

				case 'total':
					if ($trial_status === 'on') {
						$total = 0;
					} else {
						$total = $order_detail['total_amount'];
						$amount = $order_detail['product_amount'] ?? $total;

						if (
							$order_detail['payment_method'] !== 'bank' &&
							isset($post_content['type']) &&
							(
								$post_content['type'] === 'paid' ||
								($post_content['type'] === 'subscription' && $trial_status === 'off')
							)
						) {
							$coupon_discount_type = isset( $order_detail[ 'coupon_discount_type' ] ) ? $order_detail[ 'coupon_discount_type' ] : '';
							$discount_amount = ($coupon_discount_type === 'fixed')
								? ($coupon_discount ?: 0)
								: ($coupon_discount ? ($amount * $coupon_discount / 100) : 0);
							$total = $amount - $discount_amount;
						}
					}
					echo $symbol . number_format($total, 2);
					break;

				default:
					echo $default_na;
					break;
			}
			?>
		</div>
	</div>
<?php
endforeach;
?>
