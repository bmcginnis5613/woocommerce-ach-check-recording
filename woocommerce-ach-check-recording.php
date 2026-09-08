<?php
/**
 * Plugin Name: WooCommerce ACH & Check Recording
 * Description: Adds an order sidebar panel for recording Check or ACH payment details.
 * Version: 1.0.0
 * Author: FirstTracks Marketing
 * Author URI: https://firsttracksmarketing.com
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Declares support for WooCommerce High-Performance Order Storage.
 */
function wac_recording_declare_hpos_compatibility() {
	if ( class_exists( FeaturesUtil::class ) ) {
		FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'wac_recording_declare_hpos_compatibility' );

/**
 * Admin functionality for ACH and check details on orders.
 */
final class WAC_Order_Payment_Recording {
	const PAYMENT_METHOD_META_KEY = '_wac_recorded_payment_method';
	const REFERENCE_META_KEY      = '_wac_payment_reference';
	const NONCE_ACTION            = 'wac_save_payment_details';
	const NONCE_NAME              = 'wac_payment_details_nonce';

	/**
	 * Registers the plugin hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_meta_box' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_styles' ) );
		add_action( 'admin_notices', array( __CLASS__, 'woocommerce_missing_notice' ) );
	}

	/**
	 * Adds the panel to both the HPOS and legacy order-edit screens.
	 */
	public static function add_meta_box() {
		if ( ! function_exists( 'wc_get_page_screen_id' ) ) {
			return;
		}

		$screens = array_unique(
			array(
				'shop_order',
				wc_get_page_screen_id( 'shop-order' ),
			)
		);

		foreach ( $screens as $screen ) {
			add_meta_box(
				'wac-order-payment-details',
				esc_html__( 'ACH / Check Details', 'woocommerce-ach-check-recording' ),
				array( __CLASS__, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renders the order payment details panel.
	 *
	 * @param WP_Post|WC_Order $post_or_order Current post or order object.
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order
			? $post_or_order
			: wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			return;
		}

		$payment_method = (string) $order->get_meta( self::PAYMENT_METHOD_META_KEY, true );
		$reference      = (string) $order->get_meta( self::REFERENCE_META_KEY, true );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="wac-payment-details">
			<p>
				<label for="wac_recorded_payment_method">
					<strong><?php esc_html_e( 'Payment method', 'woocommerce-ach-check-recording' ); ?></strong>
				</label>
				<select id="wac_recorded_payment_method" name="wac_recorded_payment_method" class="widefat">
					<option value="" <?php selected( $payment_method, '' ); ?>><?php esc_html_e( 'Not recorded', 'woocommerce-ach-check-recording' ); ?></option>
					<option value="check" <?php selected( $payment_method, 'check' ); ?>><?php esc_html_e( 'Check', 'woocommerce-ach-check-recording' ); ?></option>
					<option value="ach" <?php selected( $payment_method, 'ach' ); ?>><?php esc_html_e( 'ACH', 'woocommerce-ach-check-recording' ); ?></option>
				</select>
			</p>

			<p>
				<label for="wac_payment_reference">
					<strong><?php esc_html_e( 'Reference / check number', 'woocommerce-ach-check-recording' ); ?></strong>
				</label>
				<input
					type="text"
					id="wac_payment_reference"
					name="wac_payment_reference"
					class="widefat"
					value="<?php echo esc_attr( $reference ); ?>"
					maxlength="100"
					autocomplete="off"
				/>
			</p>
		</div>
		<?php
	}

	/**
	 * Saves the panel fields through the WooCommerce CRUD API.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order object when supplied by WooCommerce.
	 */
	public static function save_meta_box( $order_id, $order = null ) {
		if (
			! isset( $_POST[ self::NONCE_NAME ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ||
			! current_user_can( 'edit_shop_order', $order_id )
		) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$payment_method = isset( $_POST['wac_recorded_payment_method'] )
			? sanitize_key( wp_unslash( $_POST['wac_recorded_payment_method'] ) )
			: '';
		$reference      = isset( $_POST['wac_payment_reference'] )
			? sanitize_text_field( wp_unslash( $_POST['wac_payment_reference'] ) )
			: '';

		if ( ! in_array( $payment_method, array( 'check', 'ach' ), true ) ) {
			$payment_method = '';
		}

		if ( '' === $payment_method ) {
			$order->delete_meta_data( self::PAYMENT_METHOD_META_KEY );
			$order->delete_meta_data( self::REFERENCE_META_KEY );
		} else {
			$order->update_meta_data( self::PAYMENT_METHOD_META_KEY, $payment_method );

			if ( '' === $reference ) {
				$order->delete_meta_data( self::REFERENCE_META_KEY );
			} else {
				$order->update_meta_data( self::REFERENCE_META_KEY, $reference );
			}
		}

		$order->save();
	}

	/**
	 * Loads the small amount of panel-specific admin styling.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_admin_styles( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'wac-order-payment-recording',
			plugin_dir_url( __FILE__ ) . 'assets/admin.css',
			array(),
			'1.0.0'
		);
	}

	/**
	 * Shows a dependency notice if WooCommerce is not active.
	 */
	public static function woocommerce_missing_notice() {
		if ( class_exists( 'WooCommerce' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'WooCommerce ACH & Check Recording requires WooCommerce to be installed and active.', 'woocommerce-ach-check-recording' ); ?></p>
		</div>
		<?php
	}
}

WAC_Order_Payment_Recording::init();
