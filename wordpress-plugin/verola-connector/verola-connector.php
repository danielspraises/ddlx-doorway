<?php
/**
 * Plugin Name: Vendor Hub Connector
 * Description: Connects WooCommerce and Dokan to Vendor Hub (vendor.ddlx.shop). Pulls products approved in Vendor Hub into this store under each seller, keeps stock and prices in step, and reports every sale and refund back to Vendor Hub.
 * Version: 1.9.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * Text Domain: verola-connector
 *
 * Settings can be typed in WooCommerce > Vendor Hub, or fixed in wp-config.php:
 *   define('VEROLA_URL', 'https://your-verola-app.example');
 *   define('VEROLA_API_KEY', '...');
 *   define('VEROLA_WEBHOOK_SECRET', '...');
 */

if (!defined('ABSPATH')) {
	exit;
}

final class Verola_Connector {
	const OPTION = 'verola_settings';
	const STATE = 'verola_state';
	const CRON = 'verola_sync_event';
	const SEND = 'verola_send_order';
	const RATING = 'verola_send_rating';

	public static function init() {
		add_action('before_woocommerce_init', function () {
			if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
			}
		});
		add_filter('cron_schedules', [__CLASS__, 'schedules']);
		add_action(self::CRON, [__CLASS__, 'sync']);
		add_action(self::SEND, [__CLASS__, 'send_order'], 10, 3);
		add_action('plugins_loaded', [__CLASS__, 'boot']);
		add_action('init', [__CLASS__, 'register_statuses']);
		add_filter('wc_order_statuses', [__CLASS__, 'order_statuses']);
		add_filter('woocommerce_reports_order_statuses', [__CLASS__, 'report_statuses']);
		add_filter('woocommerce_order_is_paid_statuses', [__CLASS__, 'paid_statuses']);
		register_activation_hook(__FILE__, [__CLASS__, 'activate']);
		register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
	}

	public static function boot() {
		if (!class_exists('WooCommerce')) {
			return;
		}
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);
		add_action('admin_post_verola_sync_now', [__CLASS__, 'sync_now']);
		add_action('admin_post_verola_test', [__CLASS__, 'test_connection']);
		add_action('admin_post_verola_repair_stock', [__CLASS__, 'repair_stock']);
		add_action('rest_api_init', [__CLASS__, 'register_rest']);
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
			array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=verola-connector')) . '">Settings</a>');
			return $links;
		});
		// A sale is reported once it is paid; a refund is reported when the order is marked refunded.
		add_action('woocommerce_order_status_processing', [__CLASS__, 'order_paid']);
		add_action('woocommerce_order_status_completed', [__CLASS__, 'order_paid']);
		add_action('woocommerce_order_status_refunded', [__CLASS__, 'order_refunded']);
		add_action('woocommerce_order_status_cancelled', [__CLASS__, 'order_cancelled']);
		// Delivery statuses are run by the marketplace; Vendor Hub is told about each change.
		add_action('woocommerce_order_status_changed', [__CLASS__, 'order_status_changed'], 10, 3);
		// Customer reviews stay on the marketplace; Vendor Hub is told each product's average so sellers and levels can use it.
		foreach (['comment_post', 'edit_comment', 'deleted_comment', 'trashed_comment', 'untrashed_comment', 'spammed_comment'] as $hook) {
			add_action($hook, [__CLASS__, 'review_changed']);
		}
		add_action('wp_set_comment_status', [__CLASS__, 'review_changed']);
		add_action(self::RATING, [__CLASS__, 'send_rating']);
		// Verified sellers get a blue tick next to their store name, wherever the store name is shown. No level labels on products.
		add_shortcode('vendor_hub_badge', '__return_empty_string');
		add_action('wp_footer', [__CLASS__, 'store_ticks']);
		add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'return_panel']);
		add_action('admin_post_verola_return', [__CLASS__, 'return_submit']);
		add_action('admin_post_verola_return_ship', [__CLASS__, 'return_ship_submit']);
		add_action('woocommerce_after_checkout_validation', [__CLASS__, 'validate_checkout'], 10, 2);
		add_filter('woocommerce_default_address_fields', [__CLASS__, 'address_fields']);
		add_filter('woocommerce_checkout_fields', [__CLASS__, 'checkout_fields']);
		add_action('add_meta_boxes', [__CLASS__, 'return_metabox']);
		add_action('woocommerce_init', [__CLASS__, 'hook_origin']);
		add_action(self::RETURN_EVENT, [__CLASS__, 'send_return_event'], 10, 3);
		add_action('woocommerce_admin_order_data_after_shipping_address', [__CLASS__, 'handoff_fields']);
		add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'save_handoff_fields']);
		if (!wp_next_scheduled(self::CRON)) {
			wp_schedule_event(time() + 60, 'verola_minute', self::CRON);
		}
	}

	public static function schedules($schedules) {
		$schedules['verola_minute'] = ['interval' => 60, 'display' => 'Every minute (Vendor Hub)'];
		return $schedules;
	}

	public static function activate() {
		if (!get_role('verola_seller')) {
			add_role('verola_seller', 'Vendor Hub seller', ['read' => true]);
		}
		if (!wp_next_scheduled(self::CRON)) {
			wp_schedule_event(time() + 60, 'verola_minute', self::CRON);
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook(self::CRON);
	}

	// ------------------------------------------------------------------ settings

	private static function settings() {
		$saved = get_option(self::OPTION, []);
		$saved = is_array($saved) ? $saved : [];
		return [
			'url' => rtrim(defined('VEROLA_URL') ? VEROLA_URL : ($saved['url'] ?? ''), '/'),
			'api_key' => defined('VEROLA_API_KEY') ? VEROLA_API_KEY : ($saved['api_key'] ?? ''),
			'secret' => defined('VEROLA_WEBHOOK_SECRET') ? VEROLA_WEBHOOK_SECRET : ($saved['secret'] ?? ''),
			// The WordPress role given to each seller's account. With Dokan active this is Dokan's `seller` role.
			'role' => !empty($saved['role']) ? $saved['role'] : (function_exists('dokan') ? 'seller' : 'verola_seller'),
		];
	}

	private static function configured() {
		$s = self::settings();
		return $s['url'] !== '' && $s['api_key'] !== '' && $s['secret'] !== '';
	}

	private static function state() {
		$state = get_option(self::STATE, []);
		return is_array($state) ? $state : [];
	}

	private static function set_state(array $patch) {
		update_option(self::STATE, array_merge(self::state(), $patch), false);
	}

	public static function register_settings() {
		register_setting('verola_group', self::OPTION, [
			'sanitize_callback' => function ($input) {
				$old = get_option(self::OPTION, []);
				$old = is_array($old) ? $old : [];
				$input = is_array($input) ? $input : [];
				$keep = function ($key) use ($input, $old) {
					$value = isset($input[$key]) ? trim(wp_unslash($input[$key])) : '';
					// A blank secret field means "leave it as it is".
					return $value !== '' ? sanitize_text_field($value) : ($old[$key] ?? '');
				};
				return [
					'url' => esc_url_raw(trim($input['url'] ?? '')),
					'api_key' => $keep('api_key'),
					'secret' => $keep('secret'),
					'role' => sanitize_key($input['role'] ?? '') ?: (function_exists('dokan') ? 'seller' : 'verola_seller'),
				];
			},
		]);
	}

	public static function menu() {
		add_submenu_page('woocommerce', 'Vendor Hub', 'Vendor Hub', 'manage_woocommerce', 'verola-connector', [__CLASS__, 'page']);
		add_submenu_page('woocommerce', 'Returns', 'Returns', 'manage_woocommerce', 'verola-returns', [__CLASS__, 'returns_page']);
	}

	public static function page() {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}
		$s = self::settings();
		$state = self::state();
		$mask = function ($v) {
			return $v === '' ? 'not set' : '••••' . substr($v, -4);
		};
		$when = !empty($state['last_sync']) ? wp_date('j M Y H:i:s', (int) $state['last_sync']) : 'never';
		?>
		<div class="wrap">
			<h1>Vendor Hub</h1>
			<?php if (isset($_GET['verola_test'])) : $ok = $_GET['verola_test'] === 'ok'; ?>
				<div class="notice notice-<?php echo $ok ? 'success' : 'error'; ?>"><p><?php echo esc_html(get_transient('verola_test_message') ?: ($ok ? 'Connected.' : 'Could not connect.')); ?></p></div>
			<?php endif; ?>
			<?php if (isset($_GET['verola_synced'])) : ?>
				<div class="notice notice-success"><p>Sync finished. See the status below.</p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields('verola_group'); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="verola-url">Vendor Hub address</label></th><td><input id="verola-url" class="regular-text" type="url" name="<?php echo esc_attr(self::OPTION); ?>[url]" value="<?php echo esc_attr($s['url']); ?>" placeholder="https://vendor.ddlx.shop" <?php disabled(defined('VEROLA_URL')); ?>><p class="description">Where Vendor Hub is hosted, for example https://vendor.ddlx.shop</p></td></tr>
					<tr><th><label for="verola-key">API key</label></th><td><input id="verola-key" class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr(self::OPTION); ?>[api_key]" value="" placeholder="<?php echo esc_attr($mask($s['api_key'])); ?>" <?php disabled(defined('VEROLA_API_KEY')); ?>><p class="description">Same as MARKETPLACE_API_KEY in Vendor Hub's .env. Leave blank to keep the current one.</p></td></tr>
					<tr><th><label for="verola-secret">Webhook secret</label></th><td><input id="verola-secret" class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr(self::OPTION); ?>[secret]" value="" placeholder="<?php echo esc_attr($mask($s['secret'])); ?>" <?php disabled(defined('VEROLA_WEBHOOK_SECRET')); ?>><p class="description">Same as MARKETPLACE_WEBHOOK_SECRET in Vendor Hub's .env. Leave blank to keep the current one.</p></td></tr>
					<tr><th><label for="verola-role">Seller account role</label></th><td><input id="verola-role" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[role]" value="<?php echo esc_attr($s['role']); ?>"><p class="description">Each Vendor Hub seller becomes a user with this role, and their products belong to that user. Leave this as <code>seller</code> for Dokan.</p></td></tr>
				</table>
				<?php submit_button('Save settings'); ?>
			</form>

			<h2>Status</h2>
			<table class="widefat striped" style="max-width:640px"><tbody>
				<tr><td>Connection</td><td><?php echo self::configured() ? 'Configured' : '<strong>Not configured yet</strong>'; ?></td></tr>
				<tr><td>Last sync</td><td><?php echo esc_html($when); ?></td></tr>
				<tr><td>Products updated in last sync</td><td><?php echo esc_html((string) ($state['last_count'] ?? 0)); ?></td></tr>
				<tr><td>Last problem</td><td><?php echo esc_html($state['last_error'] ?? 'None'); ?></td></tr>
			</tbody></table>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1em">
				<input type="hidden" name="action" value="verola_sync_now">
				<?php wp_nonce_field('verola_sync_now'); ?>
				<?php submit_button('Sync products now', 'secondary', 'submit', false, self::configured() ? [] : ['disabled' => 'disabled']); ?>
			</form>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:.5em">
				<input type="hidden" name="action" value="verola_test">
				<?php wp_nonce_field('verola_test'); ?>
				<?php submit_button('Test connection', 'secondary', 'submit', false, self::configured() ? [] : ['disabled' => 'disabled']); ?>
			</form>
			<?php if (isset($_GET['verola_repaired'])) : ?><div class="notice notice-success inline"><p><?php echo esc_html((int) $_GET['verola_repaired']); ?> product(s) or variation(s) with no stock count are available again.</p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:.5em">
				<input type="hidden" name="action" value="verola_repair_stock">
				<?php wp_nonce_field('verola_repair_stock'); ?>
				<?php submit_button('Make products with no stock count available', 'secondary', 'submit', false); ?>
				<p class="description" style="max-width:640px">Vendor Hub products that track stock with a count of 0 are switched to "not tracked, in stock". Run this once after updating if products show as out of stock at checkout though no stock was ever listed. Products you really sold out are also reopened, so set their count again in WooCommerce afterwards.</p>
			</form>
			<h2>Give these to Vendor Hub</h2>
			<p>Nothing to enter on the Vendor Hub side except the two secrets in its <code>.env</code> file (<code>MARKETPLACE_API_KEY</code> and <code>MARKETPLACE_WEBHOOK_SECRET</code>), which must match the values above. Sales are sent from this store to <code><?php echo esc_html($s['url'] !== '' ? $s['url'] : 'your-vendor-hub-address'); ?>/api/marketplace/orders</code>.</p>
		</div>
		<?php
	}

	// ------------------------------------------------------------------ SKUs (Vendor Hub asks this store)

	// ------------------------------------------------------------------ delivery statuses

	const RETURN_EVENT = 'verola_return_event';
	const STATUSES = ['ready-shipping' => 'Ready for shipping', 'shipped' => 'Shipped', 'delivered' => 'Delivered'];

	public static function register_statuses() {
		foreach (self::STATUSES as $slug => $label) {
			register_post_status('wc-' . $slug, [
				'label' => $label,
				'public' => true,
				'exclude_from_search' => false,
				'show_in_admin_all_list' => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count' => _n_noop($label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>'),
			]);
		}
	}

	public static function order_statuses($statuses) {
		$out = [];
		foreach ($statuses as $key => $label) {
			$out[$key] = $label;
			if ($key === 'wc-processing') {
				foreach (self::STATUSES as $slug => $l) {
					$out['wc-' . $slug] = $l;
				}
			}
		}
		return $out;
	}

	public static function report_statuses($statuses) {
		return array_merge((array) $statuses, array_keys(self::STATUSES));
	}

	public static function paid_statuses($statuses) {
		return array_merge((array) $statuses, array_keys(self::STATUSES));
	}

	// Carrier and tracking number, typed by the admin when the parcel is picked up.
	public static function handoff_fields($order) {
		echo '<div class="address"><p><strong>Vendor Hub handoff</strong></p></div><div class="edit_address">';
		woocommerce_wp_text_input(['id' => '_vh_carrier', 'label' => 'Carrier', 'value' => $order->get_meta('_vh_carrier'), 'wrapper_class' => 'form-field-wide']);
		woocommerce_wp_text_input(['id' => '_vh_tracking', 'label' => 'Tracking number', 'value' => $order->get_meta('_vh_tracking'), 'wrapper_class' => 'form-field-wide']);
		echo '</div>';
	}

	public static function save_handoff_fields($order_id) {
		$order = wc_get_order($order_id);
		if (!$order) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verifies the nonce for this hook.
		foreach (['_vh_carrier', '_vh_tracking'] as $key) {
			if (isset($_POST[$key])) {
				$order->update_meta_data($key, sanitize_text_field(wp_unslash($_POST[$key])));
			}
		}
		$order->save_meta_data();
	}

	// A seller told Vendor Hub their part of an order is processing or packed. Mirror it on the order, and once every
	// seller on the order is packed move it to "Ready for shipping" so the admin knows it can be collected.
	public static function rest_order_status($request) {
		$order = wc_get_order((int) $request->get_param('order_id'));
		$status = (string) $request->get_param('status');
		if ($order && in_array($status, ['shipped', 'delivered', 'completed', 'cancelled', 'refunded'], true)) {
			return self::admin_order_status($order, $status, $request);
		}
		if ($order && $status === 'note') {
			// A customer note is emailed to the buyer by WooCommerce and shown in their account; anything else stays private.
			$text = sanitize_text_field((string) $request->get_param('note'));
			$request->get_param('customer') ? $order->add_order_note($text, 1) : $order->add_order_note('Vendor Hub: ' . $text);
			delete_transient('verola_rc_' . $order->get_id());
			return ['ok' => true];
		}
		$seller = sanitize_text_field((string) $request->get_param('seller_id'));
		if (!$order || !in_array($status, ['processing', 'ready_for_shipping'], true) || $seller === '') {
			return new WP_REST_Response(['error' => 'Unknown order or status.'], 404);
		}
		$ready = (array) $order->get_meta('_vh_ready_sellers');
		$name = $seller;
		$users = get_users(['meta_key' => '_verola_seller_id', 'meta_value' => $seller, 'number' => 1]);
		if ($users) {
			$name = $users[0]->display_name ?: $seller;
		}
		if ($status === 'ready_for_shipping' && !in_array($seller, $ready, true)) {
			$ready[] = $seller;
			$order->update_meta_data('_vh_ready_sellers', $ready);
		}
		$order->add_order_note(sprintf('Vendor %s: %s.', $name, $status === 'ready_for_shipping' ? 'packed, ready for shipping' : 'started processing'));
		$order->save();
		if ($status === 'ready_for_shipping' && $order->has_status('processing')) {
			$sellers = [];
			foreach ($order->get_items() as $line) {
				$product = $line->get_product();
				if ($product) {
					$pid = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
					$sid = get_post_meta($pid, '_verola_seller_id', true);
					if ($sid) {
						$sellers[$sid] = true;
					}
				}
			}
			if ($sellers && !array_diff(array_keys($sellers), $ready)) {
				$order->update_status('ready-shipping', 'All sellers have packed their items. ');
			}
		}
		return ['ok' => true];
	}

	// The admin moved the order from Vendor Hub. Apply the same change in WooCommerce.
	private static function admin_order_status($order, $status, $request) {
		$carrier = sanitize_text_field((string) $request->get_param('carrier'));
		$tracking = sanitize_text_field((string) $request->get_param('tracking_number'));
		if ($carrier !== '') {
			$order->update_meta_data('_vh_carrier', $carrier);
			$order->update_meta_data('_vh_tracking', $tracking);
			$order->save_meta_data();
		}
		$note = 'Changed in Vendor Hub. ';
		if ($status === 'refunded') {
			$note .= 'Marked refunded for the sellers; the customer\'s payment must still be refunded here. ';
		}
		if (!$order->has_status($status)) {
			$order->update_status($status, $note);
		}
		return ['ok' => true];
	}

	public static function register_rest() {
		register_rest_route('vendor-hub/v1', '/return-pickup', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_return_pickup'], 'permission_callback' => [__CLASS__, 'rest_auth']]);
		register_rest_route('vendor-hub/v1', '/order-status', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_order_status'], 'permission_callback' => [__CLASS__, 'rest_auth']]);
		register_rest_route('vendor-hub/v1', '/sku/next', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_sku_next'], 'permission_callback' => [__CLASS__, 'rest_auth']]);
		register_rest_route('vendor-hub/v1', '/sku/check', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_sku_check'], 'permission_callback' => [__CLASS__, 'rest_auth']]);
		// Matching Vendor Hub sellers to sellers that already exist in this store
		register_rest_route('vendor-hub/v1', '/vendors', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_vendors'], 'permission_callback' => [__CLASS__, 'rest_auth']]);
		register_rest_route('vendor-hub/v1', '/link', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_link'], 'permission_callback' => [__CLASS__, 'rest_auth']]);
		register_rest_route('vendor-hub/v1', '/unlink', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_unlink'], 'permission_callback' => [__CLASS__, 'rest_auth']]);
		register_rest_route('vendor-hub/v1', '/vendor/products', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_vendor_products'], 'permission_callback' => [__CLASS__, 'rest_auth']]);
		register_rest_route('vendor-hub/v1', '/adopt', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_adopt'], 'permission_callback' => [__CLASS__, 'rest_auth']]);
		register_rest_route('vendor-hub/v1', '/vendor/orders', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_vendor_orders'], 'permission_callback' => [__CLASS__, 'rest_auth']]);
	}

	// Vendor Hub proves who it is with the same API key used everywhere else.
	public static function rest_auth($request) {
		$key = self::settings()['api_key'];
		$header = (string) $request->get_header('authorization');
		return $key !== '' && strncmp($header, 'Bearer ', 7) === 0 && hash_equals($key, substr($header, 7));
	}

	// The next unused number for a prefix, e.g. DEMO-0007 when DEMO-0006 is the highest in the store.
	public static function rest_sku_next($request) {
		global $wpdb;
		$prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->get_param('prefix')));
		$prefix = $prefix !== '' ? substr($prefix, 0, 12) : 'DDLX';
		$like = $wpdb->esc_like($prefix . '-') . '%';
		$skus = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s", $like));
		$highest = 0;
		foreach ($skus as $sku) {
			if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/i', $sku, $m)) {
				$highest = max($highest, (int) $m[1]);
			}
		}
		return ['sku' => $prefix . '-' . str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT)];
	}

	// Which of these SKUs belong to something other than the Vendor Hub product being saved?
	public static function rest_sku_check($request) {
		$items = $request->get_param('items');
		$conflicts = [];
		foreach (array_slice(is_array($items) ? $items : [], 0, 300) as $item) {
			$sku = isset($item['sku']) ? trim((string) $item['sku']) : '';
			if ($sku === '') {
				continue;
			}
			$found = wc_get_product_id_by_sku($sku);
			if (!$found) {
				continue;
			}
			$variation_id = !empty($item['variation_id']) ? (string) $item['variation_id'] : '';
			$product_id = !empty($item['product_id']) ? (string) $item['product_id'] : '';
			$own = $variation_id !== ''
				? get_post_meta($found, '_verola_variation_id', true) === $variation_id
				: ($product_id !== '' && get_post_meta($found, '_verola_product_id', true) === $product_id);
			if (!$own) {
				$conflicts[] = $sku;
			}
		}
		return ['conflicts' => array_values(array_unique($conflicts))];
	}


	// ------------------------------------------------------------------ matching existing sellers

	private static function is_vendor_user($user) {
		if (!$user) {
			return false;
		}
		$role = self::settings()['role'];
		return in_array($role, (array) $user->roles, true) || in_array('seller', (array) $user->roles, true);
	}

	// Sellers already in this store, with a count of their products and whether they are linked.
	public static function rest_vendors($request) {
		$search = trim((string) $request->get_param('search'));
		$page = max(1, (int) $request->get_param('page'));
		global $wpdb;
		$roles = array_values(array_unique([self::settings()['role'], 'seller']));
		// A vendor is anyone with a seller role OR Dokan store data, so stores are found even when the role differs.
		$conds = [$wpdb->prepare('(m.meta_key = %s AND m.meta_value LIKE %s)', $wpdb->get_blog_prefix() . 'capabilities', '%' . $wpdb->esc_like('"seller"') . '%')];
		foreach ($roles as $r) {
			$conds[] = $wpdb->prepare('(m.meta_key = %s AND m.meta_value LIKE %s)', $wpdb->get_blog_prefix() . 'capabilities', '%' . $wpdb->esc_like('"' . $r . '"') . '%');
		}
		$conds[] = "m.meta_key = 'dokan_enable_selling' AND m.meta_value = 'yes'";
		$conds[] = "m.meta_key = 'dokan_profile_settings'";
		$where = '(' . implode(' OR ', $conds) . ')';
		if ($search !== '') {
			$like = '%' . $wpdb->esc_like($search) . '%';
			$where .= $wpdb->prepare(' AND (u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR u.user_nicename LIKE %s OR u.ID IN (SELECT user_id FROM ' . $wpdb->usermeta . " WHERE meta_key = 'dokan_profile_settings' AND meta_value LIKE %s))", $like, $like, $like, $like, $like);
		}
		$base = 'FROM ' . $wpdb->users . ' u JOIN ' . $wpdb->usermeta . ' m ON m.user_id = u.ID WHERE ' . $where;
		$total = (int) $wpdb->get_var('SELECT COUNT(DISTINCT u.ID) ' . $base);
		$ids = array_map('intval', $wpdb->get_col('SELECT DISTINCT u.ID ' . $base . ' ORDER BY u.display_name, u.ID LIMIT 20 OFFSET ' . (($page - 1) * 20)));
		$out = [];
		foreach ($ids as $id) {
			$u = get_userdata($id);
			if (!$u) {
				continue;
			}
			$profile = get_user_meta($id, 'dokan_profile_settings', true);
			$name = is_array($profile) && !empty($profile['store_name']) ? $profile['store_name'] : ($u->display_name ?: $u->user_login);
			$out[] = [
				'id' => (int) $id,
				'email' => $u->user_email,
				'name' => $name,
				'slug' => $u->user_nicename,
				'product_count' => (int) count_user_posts($id, 'product', true),
				'linked' => (string) get_user_meta($id, '_verola_seller_id', true) !== '',
			];
		}
		return ['vendors' => $out, 'has_more' => $total > $page * 20, 'total' => $total, 'version' => '1.9.2'];
	}

	public static function rest_link($request) {
		$uid = (int) $request->get_param('user_id');
		$seller = sanitize_text_field((string) $request->get_param('seller_id'));
		$user = get_userdata($uid);
		if (!self::is_vendor_user($user) || !preg_match('/^[0-9a-f-]{36}$/i', $seller)) {
			return new WP_REST_Response(['ok' => false, 'message' => 'That seller was not found in the store.'], 404);
		}
		$have = (string) get_user_meta($uid, '_verola_seller_id', true);
		if ($have !== '' && $have !== $seller) {
			return new WP_REST_Response(['ok' => false, 'message' => 'That store seller is already linked to another Vendor Hub seller.'], 409);
		}
		update_user_meta($uid, '_verola_seller_id', $seller);
		update_user_meta($uid, '_verola_linked_existing', 1); // keeps their store page, address and profile untouched
		return ['ok' => true];
	}

	public static function rest_unlink($request) {
		$uid = (int) $request->get_param('user_id');
		if ($uid > 0 && get_user_meta($uid, '_verola_linked_existing', true)) {
			delete_user_meta($uid, '_verola_seller_id');
			delete_user_meta($uid, '_verola_linked_existing');
		}
		return ['ok' => true];
	}

	private static function vendor_product_ids($uid, $after, $limit) {
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_verola_product_id'
			 WHERE p.post_type = 'product' AND p.post_author = %d AND p.post_status IN ('publish','draft','pending','private') AND p.ID > %d AND m.meta_id IS NULL
			 ORDER BY p.ID ASC LIMIT %d", $uid, $after, $limit
		));
		return array_map('intval', $ids);
	}

	private static function category_path_of($product) {
		$ids = $product->get_category_ids();
		if (!$ids) {
			return [];
		}
		$best = [];
		foreach ($ids as $cid) {
			$chain = array_reverse(get_ancestors($cid, 'product_cat', 'taxonomy'));
			$chain[] = $cid;
			if (count($chain) > count($best)) {
				$best = $chain;
			}
		}
		$names = [];
		foreach ($best as $cid) {
			$t = get_term($cid, 'product_cat');
			if ($t && !is_wp_error($t)) {
				$names[] = html_entity_decode($t->name);
			}
		}
		return $names;
	}

	private static function attachment_urls($product) {
		$ids = array_merge([$product->get_image_id()], $product->get_gallery_image_ids());
		$urls = [];
		foreach ($ids as $aid) {
			$u = $aid ? wp_get_attachment_url($aid) : '';
			if ($u) {
				$urls[] = $u;
			}
		}
		return $urls;
	}

	private static function export_product($product) {
		$type = $product->get_type();
		$row = [
			'id' => $product->get_id(), 'type' => $type, 'name' => $product->get_name(), 'status' => $product->get_status(), 'sku' => (string) $product->get_sku(),
			'description' => $product->get_description(), 'short_description' => $product->get_short_description(), 'currency' => get_woocommerce_currency(),
			'manage_stock' => (bool) $product->get_manage_stock(), 'stock' => (int) $product->get_stock_quantity(), 'backorders' => $product->get_backorders() !== 'no',
			'low_stock' => (int) $product->get_low_stock_amount(), 'weight' => $product->get_weight(), 'length' => $product->get_length(), 'width' => $product->get_width(), 'height' => $product->get_height(),
			'category_path' => self::category_path_of($product),
			'tags' => wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']),
			'images' => self::attachment_urls($product),
			'regular_price' => $product->get_regular_price(), 'sale_price' => $product->get_sale_price(),
			'attributes' => [], 'variations' => [],
		];
		foreach ($product->get_attributes() as $attr) {
			if (!is_a($attr, 'WC_Product_Attribute')) {
				continue;
			}
			$values = $attr->is_taxonomy() ? wc_get_product_terms($product->get_id(), $attr->get_name(), ['fields' => 'names']) : $attr->get_options();
			$row['attributes'][] = ['name' => wc_attribute_label($attr->get_name()), 'slug' => sanitize_title($attr->get_name()), 'values' => array_values(array_map('strval', $values)), 'variation' => (bool) $attr->get_variation()];
		}
		if ($type === 'variable') {
			foreach ($product->get_children() as $vid) {
				$v = wc_get_product($vid);
				if (!$v) {
					continue;
				}
				$opts = [];
				foreach ($v->get_attributes() as $slug => $val) {
					$label = wc_attribute_label($slug, $product);
					if ($val === '') {
						continue; // "any" option: cannot be expressed as one combination
					}
					if (taxonomy_exists($slug)) {
						$term = get_term_by('slug', $val, $slug);
						$val = $term ? $term->name : $val;
					}
					$opts[$label] = (string) $val;
				}
				$img = $v->get_image_id() ? wp_get_attachment_url($v->get_image_id()) : '';
				$row['variations'][] = ['id' => (int) $vid, 'sku' => (string) $v->get_sku(), 'options' => $opts, 'regular_price' => $v->get_regular_price(), 'sale_price' => $v->get_sale_price(),
					'stock' => (int) $v->get_stock_quantity(), 'manage_stock' => (bool) $v->managing_stock(), 'image' => $img ?: null, 'enabled' => $v->get_status() === 'publish'];
			}
		}
		$from = $product->get_date_on_sale_from();
		$to = $product->get_date_on_sale_to();
		$row['sale_starts_at'] = $from ? gmdate('c', $from->getTimestamp()) : null;
		$row['sale_ends_at'] = $to ? gmdate('c', $to->getTimestamp()) : null;
		return $row;
	}

	// The seller's products that Vendor Hub does not manage yet, a few at a time.
	public static function rest_vendor_products($request) {
		global $wpdb;
		$uid = (int) $request->get_param('user_id');
		if (!self::is_vendor_user(get_userdata($uid))) {
			return new WP_REST_Response(['message' => 'Seller not found.'], 404);
		}
		$after = max(0, (int) $request->get_param('after'));
		$limit = min(10, max(1, (int) $request->get_param('limit')));
		$ids = self::vendor_product_ids($uid, $after, $limit);
		$products = [];
		foreach ($ids as $id) {
			$p = wc_get_product($id);
			if ($p) {
				$products[] = self::export_product($p);
			}
		}
		$last = $ids ? end($ids) : 0;
		$more = $ids ? self::vendor_product_ids($uid, $last, 1) : [];
		$remaining = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_verola_product_id'
			 WHERE p.post_type = 'product' AND p.post_author = %d AND p.post_status IN ('publish','draft','pending','private') AND p.ID > %d AND m.meta_id IS NULL", $uid, $last
		));
		return ['products' => $products, 'next' => $more ? $last : null, 'remaining' => $remaining];
	}

	// Ties existing store products to their new Vendor Hub ids, so the next sync updates them instead of creating copies.
	public static function rest_adopt($request) {
		$uid = (int) $request->get_param('user_id');
		$items = (array) $request->get_param('items');
		if (!self::is_vendor_user(get_userdata($uid))) {
			return new WP_REST_Response(['ok' => false, 'message' => 'Seller not found.'], 404);
		}
		$n = 0;
		foreach ($items as $it) {
			$wc = (int) ($it['wc_id'] ?? 0);
			$vh = sanitize_text_field((string) ($it['vh_id'] ?? ''));
			if ($wc < 1 || !preg_match('/^[0-9a-f-]{36}$/i', $vh) || get_post_type($wc) !== 'product' || (int) get_post_field('post_author', $wc) !== $uid) {
				continue;
			}
			update_post_meta($wc, '_verola_product_id', $vh);
			foreach ((array) ($it['variations'] ?? []) as $v) {
				$vwc = (int) ($v['wc_id'] ?? 0);
				$vvh = sanitize_text_field((string) ($v['vh_id'] ?? ''));
				if ($vwc > 0 && preg_match('/^[0-9a-f-]{36}$/i', $vvh) && (int) wp_get_post_parent_id($vwc) === $wc) {
					update_post_meta($vwc, '_verola_variation_id', $vvh);
				}
			}
			$n++;
		}
		return ['ok' => true, 'adopted' => $n];
	}

	// Past paid orders that contain this seller's products (their own lines only).
	public static function rest_vendor_orders($request) {
		global $wpdb;
		$uid = (int) $request->get_param('user_id');
		if (!self::is_vendor_user(get_userdata($uid))) {
			return new WP_REST_Response(['message' => 'Seller not found.'], 404);
		}
		$after = max(0, (int) $request->get_param('after'));
		$limit = min(30, max(1, (int) $request->get_param('limit')));
		$sql = "SELECT DISTINCT oi.order_id FROM {$wpdb->prefix}woocommerce_order_items oi
			JOIN {$wpdb->prefix}woocommerce_order_itemmeta pm ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'
			JOIN {$wpdb->posts} p ON p.ID = pm.meta_value
			WHERE oi.order_item_type = 'line_item' AND p.post_author = %d AND oi.order_id > %d ORDER BY oi.order_id ASC LIMIT %d";
		$ids = array_map('intval', $wpdb->get_col($wpdb->prepare($sql, $uid, $after, $limit)));
		$allowed = ['processing', 'completed', 'ready-shipping', 'shipped', 'delivered'];
		$orders = [];
		foreach ($ids as $oid) {
			$order = wc_get_order($oid);
			if (!$order || $order->get_parent_id() || !in_array($order->get_status(), $allowed, true)) {
				continue;
			}
			$items = [];
			foreach ($order->get_items() as $line) {
				$product = $line->get_product();
				if (!$product) {
					continue;
				}
				$pid = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
				if ((int) get_post_field('post_author', $pid) !== $uid) {
					continue;
				}
				$qty = max(1, (int) $line->get_quantity());
				$items[] = [
					'wc_product_id' => (int) $pid, 'wc_variation_id' => $product->is_type('variation') ? (int) $product->get_id() : null,
					'name' => $line->get_name(), 'sku' => (string) $product->get_sku(), 'quantity' => $qty,
					'unit_price' => round(((float) $line->get_total()) / $qty, 2),
				];
			}
			if (!$items) {
				continue;
			}
			$country = $order->get_shipping_country() ?: $order->get_billing_country();
			$state = $order->get_shipping_state() ?: $order->get_billing_state();
			$states = $country ? WC()->countries->get_states($country) : [];
			$first = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
			$last = $order->get_shipping_last_name() ?: $order->get_billing_last_name();
			$created = $order->get_date_created();
			$orders[] = [
				'id' => (string) $order->get_id(), 'number' => (string) $order->get_order_number(), 'status' => $order->get_status(), 'currency' => $order->get_currency(),
				'placed_at' => gmdate('c', $created ? $created->getTimestamp() : time()),
				'customer_name' => trim($first . ' ' . ($last !== '' ? mb_substr($last, 0, 1) . '.' : '')),
				'ship_city' => (string) ($order->get_shipping_city() ?: $order->get_billing_city()), 'ship_state' => (string) (($states[$state] ?? $state) ?: ''),
				'payment_ref' => (string) $order->get_transaction_id(), 'payment_method' => (string) $order->get_payment_method(),
				'carrier' => (string) $order->get_meta('_vh_carrier'), 'tracking_number' => (string) $order->get_meta('_vh_tracking'),
				'items' => $items,
			];
		}
		$last = $ids ? end($ids) : 0;
		$more = $ids ? $wpdb->get_var($wpdb->prepare($sql, $uid, $last, 1)) : null;
		$remaining = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT oi.order_id) FROM {$wpdb->prefix}woocommerce_order_items oi
			 JOIN {$wpdb->prefix}woocommerce_order_itemmeta pm ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'
			 JOIN {$wpdb->posts} p ON p.ID = pm.meta_value WHERE oi.order_item_type = 'line_item' AND p.post_author = %d AND oi.order_id > %d", $uid, $last
		));
		return ['orders' => $orders, 'next' => $more ? $last : null, 'remaining' => $remaining];
	}

	public static function test_connection() {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Not allowed.');
		}
		check_admin_referer('verola_test');
		$s = self::settings();
		$ok = false;
		$res = wp_remote_get($s['url'] . '/api/marketplace/products?limit=1', ['timeout' => 20, 'headers' => ['Authorization' => 'Bearer ' . $s['api_key']]]);
		if (is_wp_error($res)) {
			$message = 'Could not reach Vendor Hub: ' . $res->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code($res);
			if ($code === 200) {
				$ok = true;
				$message = 'Connected. Vendor Hub accepted the API key. The webhook secret can only be checked when the first sale is reported.';
			} elseif ($code === 401) {
				$message = 'Vendor Hub is reachable but rejected the API key. Check that it matches MARKETPLACE_API_KEY.';
			} elseif ($code === 503) {
				$message = 'Vendor Hub is reachable but has no MARKETPLACE_API_KEY set yet.';
			} else {
				$message = 'Vendor Hub answered with status ' . $code . '. Check the address.';
			}
		}
		set_transient('verola_test_message', $message, 60);
		wp_safe_redirect(admin_url('admin.php?page=verola-connector&verola_test=' . ($ok ? 'ok' : 'fail')));
		exit;
	}

	// Products and variations that were forced to "track stock" with a count of zero (no count was ever listed) become available again.
	public static function repair_stock() {
		if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
			wp_die('Not allowed.');
		}
		check_admin_referer('verola_repair_stock');
		global $wpdb;
		$ids = $wpdb->get_col("SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_verola_product_id' WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private')");
		$fixed = 0;
		foreach ($ids as $pid) {
			$product = wc_get_product($pid);
			if (!$product) {
				continue;
			}
			$targets = [$product];
			if ($product->is_type('variable')) {
				foreach ($product->get_children() as $cid) {
					$c = wc_get_product($cid);
					if ($c) {
						$targets[] = $c;
					}
				}
			}
			foreach ($targets as $t) {
				// Stuck either way: tracking a count of 0, or not tracking at all yet still flagged "out of stock" (an older sync left that flag behind).
				$stuck = ($t->get_manage_stock() === true && (int) $t->get_stock_quantity() <= 0)
					|| ($t->get_manage_stock() === false && $t->get_stock_status() === 'outofstock');
				if ($stuck) {
					$t->set_manage_stock(false);
					$t->set_stock_quantity(null);
					$t->set_stock_status('instock');
					$t->save();
					$fixed++;
				}
			}
			if ($product->is_type('variable')) {
				WC_Product_Variable::sync($pid);
			}
		}
		wp_safe_redirect(add_query_arg(['page' => 'verola-connector', 'verola_repaired' => $fixed], admin_url('admin.php')));
		exit;
	}

	public static function sync_now() {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Not allowed.');
		}
		check_admin_referer('verola_sync_now');
		self::sync();
		wp_safe_redirect(admin_url('admin.php?page=verola-connector&verola_synced=1'));
		exit;
	}

	// ------------------------------------------------------------------ product sync (Vendor Hub -> store)

	public static function sync() {
		if (!self::configured() || !class_exists('WooCommerce')) {
			return;
		}
		if (get_transient('verola_sync_lock')) {
			return;
		}
		set_transient('verola_sync_lock', 1, 5 * MINUTE_IN_SECONDS);
		$s = self::settings();
		$since = self::state()['since'] ?? '';
		$count = 0;
		try {
			for ($page = 0; $page < 20; $page++) {
				$url = $s['url'] . '/api/marketplace/products?limit=50' . ($since !== '' ? '&updated_since=' . rawurlencode($since) : '');
				$res = wp_remote_get($url, ['timeout' => 30, 'headers' => ['Authorization' => 'Bearer ' . $s['api_key']]]);
				if (is_wp_error($res)) {
					throw new Exception('Could not reach Vendor Hub: ' . $res->get_error_message());
				}
				$code = wp_remote_retrieve_response_code($res);
				if ($code === 401) {
					throw new Exception('Vendor Hub rejected the API key.');
				}
				if ($code !== 200) {
					throw new Exception('Vendor Hub answered with status ' . $code . '.');
				}
				$data = json_decode(wp_remote_retrieve_body($res), true);
				if (!is_array($data) || !isset($data['products'])) {
					throw new Exception('Vendor Hub sent an unreadable answer.');
				}
				foreach ($data['products'] as $item) {
					try {
						self::upsert($item);
						$count++;
					} catch (Throwable $e) {
						// One bad product must not stop the rest. It is retried on the next change.
						self::set_state(['last_error' => 'Product ' . ($item['name'] ?? '?') . ': ' . $e->getMessage()]);
					}
				}
				if (!empty($data['next_since'])) {
					$since = $data['next_since'];
					self::set_state(['since' => $since]);
				}
				if (empty($data['has_more'])) {
					break;
				}
			}
			self::sync_coupons();
			self::sync_sellers();
			self::set_state(['last_sync' => time(), 'last_count' => $count]);
		} catch (Throwable $e) {
			self::set_state(['last_sync' => time(), 'last_count' => $count, 'last_error' => $e->getMessage()]);
		}
		delete_transient('verola_sync_lock');
	}


	// Opens (or refreshes) a marketplace store for every verified seller, even before they have a product, so each
	// verified Vendor Hub seller is listed on the store page with their current logo, banner and details.
	private static function sync_sellers() {
		$s = self::settings();
		for ($page = 1; $page <= 20; $page++) {
			$res = wp_remote_get($s['url'] . '/api/marketplace/sellers?limit=50&page=' . $page, ['timeout' => 30, 'headers' => ['Authorization' => 'Bearer ' . $s['api_key']]]);
			if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
				return; // an older Vendor Hub without this feature, or a short outage: products still sync
			}
			$data = json_decode(wp_remote_retrieve_body($res), true);
			if (!is_array($data) || !isset($data['sellers'])) {
				return;
			}
			foreach ($data['sellers'] as $seller) {
				try {
					$uid = self::vendor_user($seller);
					update_user_meta($uid, '_verola_verified', '1');
					if (!empty($seller['badge']['level'])) {
						update_user_meta($uid, '_verola_level', sanitize_key($seller['badge']['level']));
					}
				} catch (Throwable $e) {
					self::set_state(['last_error' => 'Seller ' . ($seller['store_name'] ?? '?') . ': ' . $e->getMessage()]);
				}
			}
			if (empty($data['has_more'])) {
				break;
			}
		}
		delete_transient('verola_verified_stores');
	}

	// ------------------------------------------------------------------ seller discount codes (Vendor Hub -> store)

	// Each code is funded by one seller and works only on that seller's products. The discount comes off the
	// sale price, so the seller's earnings are worked out on what the customer actually paid.
	private static function sync_coupons() {
		if (!class_exists('WC_Coupon')) {
			return;
		}
		$s = self::settings();
		$res = wp_remote_get($s['url'] . '/api/marketplace/coupons', ['timeout' => 30, 'headers' => ['Authorization' => 'Bearer ' . $s['api_key']]]);
		if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
			return; // an older Vendor Hub without codes, or a hiccup: products still synced
		}
		$data = json_decode(wp_remote_retrieve_body($res), true);
		if (!is_array($data) || !isset($data['coupons'])) {
			return;
		}
		foreach ($data['coupons'] as $c) {
			try {
				self::upsert_coupon($c);
			} catch (Throwable $e) {
				self::set_state(['last_error' => 'Code ' . ($c['code'] ?? '?') . ': ' . $e->getMessage()]);
			}
		}
	}

	private static function upsert_coupon($c) {
		$code = wc_format_coupon_code($c['code']);
		$id = wc_get_coupon_id_by_code($code);
		$coupon = new WC_Coupon($id ?: 0);
		if ($id && $coupon->get_meta('_verola_coupon_id') !== $c['id']) {
			throw new Exception('a different coupon already uses this code in WooCommerce');
		}
		$ids = [];
		foreach ((array) $c['product_ids'] as $vid) {
			$post = self::post_id_by_meta('_verola_product_id', $vid);
			if ($post) {
				$ids[] = (int) $post;
			}
		}
		if ($c['scope'] === 'all') {
			global $wpdb;
			$ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_verola_seller_id' AND meta_value = %s", $c['seller_id'])));
		}
		$enabled = !empty($c['enabled']) && $ids;
		$coupon->set_code($code);
		$coupon->set_discount_type($c['kind'] === 'percent' ? 'percent' : 'fixed_product');
		$coupon->set_amount($c['amount']);
		$coupon->set_product_ids($ids);
		$coupon->set_individual_use(true);
		$coupon->set_exclude_sale_items(false);
		$coupon->set_minimum_amount($c['min_spend'] !== null ? $c['min_spend'] : '');
		$coupon->set_usage_limit($c['usage_limit'] !== null ? (int) $c['usage_limit'] : 0);
		$coupon->set_usage_limit_per_user(!empty($c['one_per_customer']) ? 1 : 0);
		$coupon->set_date_expires($c['ends_at'] ? strtotime($c['ends_at']) : null);
		$coupon->set_description('Funded by seller ' . $c['store_name'] . ' (Vendor Hub)');
		$coupon->set_status($enabled ? 'publish' : 'draft');
		$coupon->update_meta_data('_verola_coupon_id', $c['id']);
		$coupon->save();
	}

	// ------------------------------------------------------------------ ratings (store -> Vendor Hub)

	// Verified sellers: slug => store name. Cached briefly; cleared on every sync.
	private static function verified_stores() {
		$map = get_transient('verola_verified_stores');
		if (is_array($map)) {
			return $map;
		}
		$map = [];
		$users = get_users(['meta_key' => '_verola_verified', 'meta_value' => '1', 'fields' => ['ID', 'user_nicename'], 'number' => 2000]);
		foreach ($users as $u) {
			$profile = get_user_meta($u->ID, 'dokan_profile_settings', true);
			$name = is_array($profile) && !empty($profile['store_name']) ? $profile['store_name'] : (string) get_user_meta($u->ID, 'store_name', true);
			if ($name !== '') {
				$map[$u->user_nicename] = $name;
			}
		}
		set_transient('verola_verified_stores', $map, 10 * MINUTE_IN_SECONDS);
		return $map;
	}

	// Adds the tick after the store name on store pages, store lists and the seller line of product pages.
	public static function store_ticks() {
		if (is_admin()) {
			return;
		}
		$map = self::verified_stores();
		if (!$map) {
			return;
		}
		$json = wp_json_encode($map);
		?>
<style>.vh-name{display:inline-flex;align-items:center;white-space:nowrap;max-width:100%}.vh-tick{display:inline-block;width:1.05em;height:1.05em;min-width:14px;min-height:14px;margin-left:.3em;vertical-align:-.15em;line-height:0}.vh-tick svg{width:100%;height:100%;display:block}</style>
<script>
(function () {
  var stores = <?php echo $json; ?>;
  var svg = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1.6l2.5 1.9 3.1-.2 1.2 2.9 2.7 1.6-.6 3.1 1.3 2.8-2 2.4-.1 3.1-3 .9-1.9 2.5-2.9-1.1-2.9 1.1-1.9-2.5-3-.9-.1-3.1-2-2.4 1.3-2.8-.6-3.1 2.7-1.6 1.2-2.9 3.1.2z" fill="#1d9bf0" stroke="#1d9bf0" stroke-width="1.2" stroke-linejoin="round"/><path d="M7.6 12.4l3 3 5.9-6.2" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  function norm(t) { return (t || '').replace(/\s+/g, ' ').trim().toLowerCase(); }
  function tick(el) {
    if (el.getAttribute('data-vh-tick')) return;
    el.setAttribute('data-vh-tick', '1');
    var s = document.createElement('span'); s.className = 'vh-tick'; s.title = 'Verified seller'; s.setAttribute('role', 'img'); s.setAttribute('aria-label', 'Verified seller'); s.innerHTML = svg;
    // Put the tick on the same line as the name, like an extra letter: wrap the name's text together with the tick.
    var w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT), n, target = null;
    while ((n = w.nextNode())) { if (norm(n.nodeValue) !== '') { target = n; break; } }
    if (target) {
      var wrap = document.createElement('span'); wrap.className = 'vh-name';
      target.parentNode.insertBefore(wrap, target); wrap.appendChild(target); wrap.appendChild(s);
    } else { el.appendChild(s); }
  }
  function slugOf(href) { var m = /\/store\/([^\/?#]+)/.exec(href || ''); return m ? decodeURIComponent(m[1]) : null; }
  function run() {
    // Links to a store whose text is the store name (store lists, "Sold by", vendor info tab).
    document.querySelectorAll('a[href*="/store/"]').forEach(function (a) {
      var slug = slugOf(a.getAttribute('href'));
      if (slug && stores[slug] && norm(a.textContent) === norm(stores[slug])) tick(a);
    });
    // The store's own page heading.
    var here = slugOf(location.pathname);
    if (here && stores[here]) {
      document.querySelectorAll('.store-name, .dokan-store-name, .profile-info .store-name, h1').forEach(function (h) {
        if (!h.querySelector('a') && norm(h.textContent) === norm(stores[here])) tick(h);
      });
    }
  }
  run();
  if (window.MutationObserver) { var t; new MutationObserver(function () { clearTimeout(t); t = setTimeout(run, 150); }).observe(document.body, { childList: true, subtree: true }); }
})();
</script>
		<?php
	}

	public static function review_changed($comment_id) {
		$comment = get_comment($comment_id);
		if (!$comment || get_post_type($comment->comment_post_ID) !== 'product') {
			return;
		}
		if (!get_post_meta($comment->comment_post_ID, '_verola_product_id', true)) {
			return;
		}
		// Wait a little so WooCommerce has recalculated the average first.
		wp_schedule_single_event(time() + 45, self::RATING, [(int) $comment->comment_post_ID]);
	}

	public static function send_rating($post_id) {
		$vid = get_post_meta($post_id, '_verola_product_id', true);
		$product = wc_get_product($post_id);
		if (!$vid || !$product || !self::configured()) {
			return;
		}
		$s = self::settings();
		$body = wp_json_encode(['event' => 'rating.updated', 'product' => ['product_id' => $vid, 'average' => round((float) $product->get_average_rating(), 2), 'count' => (int) $product->get_rating_count()]]);
		$ts = time();
		wp_remote_post($s['url'] . '/api/marketplace/ratings', [
			'timeout' => 20,
			'headers' => ['Content-Type' => 'application/json', 'X-Verola-Timestamp' => (string) $ts, 'X-Verola-Signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $s['secret'])],
			'body' => $body,
		]);
	}

	private static function post_id_by_meta($key, $value) {
		global $wpdb;
		$id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", $key, $value));
		return $id ? (int) $id : 0;
	}

	// Vendor Hub SKUs are unique per seller; WooCommerce wants them unique across the store.
	private static function free_sku($sku, $own_id, $seller) {
		$candidate = $sku;
		$taken = wc_get_product_id_by_sku($candidate);
		if ($taken && (int) $taken !== (int) $own_id) {
			$candidate = substr(sanitize_title($seller['store_slug'] ?? 'seller'), 0, 20) . '-' . $sku;
			$again = wc_get_product_id_by_sku($candidate);
			if ($again && (int) $again !== (int) $own_id) {
				$candidate = $candidate . '-' . substr(md5($seller['id'] . $sku), 0, 6);
			}
		}
		return $candidate;
	}

	private static function vendor_user($seller) {
		$s = self::settings();
		$existing = get_users(['meta_key' => '_verola_seller_id', 'meta_value' => $seller['id'], 'number' => 1, 'fields' => 'ID']);
		$name = $seller['store_name'] ?? 'DDLX seller';
		$linked = false;
		if ($existing) {
			$uid = (int) $existing[0];
			// A seller who already had a store here keeps their name, store address and profile exactly as they are.
			$linked = (bool) get_user_meta($uid, '_verola_linked_existing', true);
			if (!$linked) {
				$update = ['ID' => $uid, 'display_name' => $name];
				if (!empty($seller['store_slug'])) {
					$update['user_nicename'] = sanitize_title($seller['store_slug']);
				}
				wp_update_user($update);
			}
		} else {
			$short = substr(str_replace('-', '', $seller['id']), 0, 8);
			$login = 'verola_' . $short;
			$email = !empty($seller['email']) && !email_exists($seller['email']) ? $seller['email'] : $login . '@users.noreply.invalid';
			$uid = wp_insert_user([
				'user_login' => $login,
				'user_pass' => wp_generate_password(32, true, true),
				'user_email' => $email,
				'display_name' => $name,
				'nickname' => $name,
				// Dokan builds the store address (/store/<nicename>) from this.
				'user_nicename' => sanitize_title($seller['store_slug'] ?? $login),
				'role' => get_role($s['role']) ? $s['role'] : 'verola_seller',
			]);
			if (is_wp_error($uid)) {
				throw new Exception('Could not create the seller account: ' . $uid->get_error_message());
			}
			update_user_meta($uid, '_verola_seller_id', $seller['id']);
		}
		self::save_pickup($uid, $seller);
		if ($linked) {
			return (int) $uid;
		}
		update_user_meta($uid, 'store_name', $name);
		// Best-effort support for the common vendor plugins.
		if (function_exists('dokan')) {
			update_user_meta($uid, 'dokan_enable_selling', 'yes');
			update_user_meta($uid, 'dokan_publishing', 'yes');
			$profile = get_user_meta($uid, 'dokan_profile_settings', true);
			$profile = is_array($profile) ? $profile : [];
			$profile['store_name'] = $name;
			if (!empty($seller['city'])) {
				$profile['address'] = array_merge(is_array($profile['address'] ?? null) ? $profile['address'] : [], ['city' => $seller['city']]);
			}
			$extra = is_array($seller['profile'] ?? null) ? $seller['profile'] : [];
			if ($extra) {
				$profile['phone'] = $extra['phone'] ?? '';
				$profile['vendor_biography'] = trim(($extra['tagline'] ?? '') . "\n\n" . ($extra['description'] ?? ''));
				if (!empty($extra['logo'])) {
					$profile['gravatar'] = self::image($extra['logo']);
				}
				if (!empty($extra['banner'])) {
					$profile['banner'] = self::image($extra['banner']);
				}
				$profile['setting_go_vacation'] = !empty($extra['vacation']) ? 'yes' : 'no';
				$profile['settings_closing_style'] = 'datewise';
				$profile['setting_vacation_message'] = $extra['vacation_message'] ?? '';
				update_user_meta($uid, '_verola_return_policy', $extra['return_policy'] ?? '');
				update_user_meta($uid, '_verola_shipping_policy', $extra['shipping_policy'] ?? '');
			}
			update_user_meta($uid, 'dokan_profile_settings', $profile);
		}
		return (int) $uid;
	}

	private static function term_ids($names, $taxonomy) {
		$ids = [];
		foreach ((array) $names as $name) {
			$name = trim((string) $name);
			if ($name === '') {
				continue;
			}
			$term = term_exists($name, $taxonomy);
			if (!$term) {
				$term = wp_insert_term($name, $taxonomy);
			}
			if (!is_wp_error($term)) {
				$ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
			}
		}
		return $ids;
	}

	// Builds Fashion > Men > Clothing as nested product_cat terms and returns every level, top first.
	// Same-named categories under different parents stay separate because the lookup is scoped to the parent.
	private static function category_chain($path) {
		$ids = [];
		$parent = 0;
		foreach ((array) $path as $name) {
			$name = trim((string) $name);
			if ($name === '') {
				continue;
			}
			$term = term_exists($name, 'product_cat', $parent);
			if (!$term) {
				$term = wp_insert_term($name, 'product_cat', ['parent' => $parent]);
			}
			if (is_wp_error($term)) {
				break;
			}
			$parent = (int) (is_array($term) ? $term['term_id'] : $term);
			$ids[] = $parent;
		}
		return $ids;
	}

	// Downloads a photo once and remembers which URL it came from.
	private static function image($url) {
		if (!$url) {
			return 0;
		}
		global $wpdb;
		$found = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_verola_src' AND meta_value = %s LIMIT 1", $url));
		if ($found) {
			return (int) $found;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url($url, 60);
		if (is_wp_error($tmp)) {
			return 0;
		}
		parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
		$name = basename(isset($query['p']) ? (string) $query['p'] : 'photo.jpg');
		$id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0);
		if (is_wp_error($id)) {
			@unlink($tmp);
			return 0;
		}
		update_post_meta($id, '_verola_src', $url);
		return (int) $id;
	}

	private static function set_prices($product, $item) {
		$product->set_regular_price($item['regular_price'] !== null ? (string) $item['regular_price'] : '');
		$product->set_sale_price($item['sale_price'] !== null ? (string) $item['sale_price'] : '');
		$product->set_date_on_sale_from(!empty($item['sale_starts_at']) ? strtotime($item['sale_starts_at']) : null);
		$product->set_date_on_sale_to(!empty($item['sale_ends_at']) ? strtotime($item['sale_ends_at']) : null);
	}

	private static function upsert($item) {
		$seller = $item['seller'];
		$variable = ($item['type'] ?? 'simple') === 'variable';
		$id = self::post_id_by_meta('_verola_product_id', $item['id']);
		if ($id) {
			$existing = wc_get_product($id);
			if ($existing && $existing->get_type() !== ($variable ? 'variable' : 'simple')) {
				wp_set_object_terms($id, $variable ? 'variable' : 'simple', 'product_type');
			}
			$product = wc_get_product($id);
		} else {
			$product = $variable ? new WC_Product_Variable() : new WC_Product_Simple();
			$product->set_slug($item['slug']);
		}
		if (!$product) {
			throw new Exception('Could not load the product.');
		}

		$product->set_name($item['name']);
		$product->set_description($item['description'] ?? '');
		$product->set_short_description($item['short_description'] ?? '');
		$product->set_status(!empty($item['live']) ? 'publish' : 'draft');
		$product->set_sku(self::free_sku($item['sku'], $id, $seller));
		$product->set_weight($item['weight_kg'] !== null ? (string) $item['weight_kg'] : '');
		$dims = $item['dimensions_cm'] ?? [];
		$product->set_length(isset($dims['length']) && $dims['length'] !== null ? (string) $dims['length'] : '');
		$product->set_width(isset($dims['width']) && $dims['width'] !== null ? (string) $dims['width'] : '');
		$product->set_height(isset($dims['height']) && $dims['height'] !== null ? (string) $dims['height'] : '');
		$product->set_backorders(!empty($item['allow_backorders']) ? 'yes' : 'no');
		$path = !empty($item['category_path']) ? $item['category_path'] : [$item['category'] ?? ''];
		$product->set_category_ids(self::category_chain($path));
		$product->set_tag_ids(self::term_ids($item['tags'] ?? [], 'product_tag'));

		$attributes = [];
		foreach (($item['attributes'] ?? []) as $position => $a) {
			$attr = new WC_Product_Attribute();
			$attr->set_id(0);
			$attr->set_name($a['name']);
			$attr->set_options($a['values']);
			$attr->set_position($position);
			$attr->set_visible(true);
			$attr->set_variation($variable);
			$attributes[] = $attr;
		}
		$product->set_attributes($attributes);

		if ($variable) {
			if (!$id) {
				$product->set_manage_stock(false);
			}
		} else {
			self::set_prices($product, $item);
			// WooCommerce owns stock: only push a real count (more than zero). No count never means out of stock.
			if (!empty($item['manage_stock']) && (int) $item['stock'] > 0) {
				$product->set_manage_stock(true);
				$product->set_stock_quantity((int) $item['stock']);
			} elseif (!$id) {
				$product->set_manage_stock(false);
				$product->set_stock_status('instock');
			}
		}

		$featured = self::image($item['featured_image'] ?? null);
		if ($featured) {
			$product->set_image_id($featured);
		}
		$gallery = [];
		foreach (($item['gallery'] ?? []) as $url) {
			$img = self::image($url);
			if ($img) {
				$gallery[] = $img;
			}
		}
		$product->set_gallery_image_ids($gallery);

		$id = $product->save();
		update_post_meta($id, '_verola_product_id', $item['id']);
		update_post_meta($id, '_verola_seller_id', $seller['id']);
		// Seller level: stored on the product so grids can sort and filter by it. Higher levels get a lower menu order,
		// so "Default sorting" shows Blue sellers first, then Gold, Silver, Bronze and Starter.
		$badge = $seller['badge'] ?? null;
		if ($badge) {
			update_post_meta($id, '_verola_seller_level', $badge['level']);
			update_post_meta($id, '_verola_seller_level_label', $badge['label']);
			update_post_meta($id, '_verola_seller_rank', (int) $badge['rank']);
			wp_update_post(['ID' => $id, 'menu_order' => (4 - (int) $badge['rank']) * 10]);
		}
		update_post_meta($id, '_verola_updated_at', $item['updated_at']);
		$author = self::vendor_user($seller);
		update_user_meta($author, '_verola_verified', '1');
		wp_update_post(['ID' => $id, 'post_author' => $author]);
		if (taxonomy_exists('product_brand') && !empty($item['brand'])) {
			wp_set_object_terms($id, [$item['brand']], 'product_brand');
		} else {
			update_post_meta($id, '_verola_brand', $item['brand'] ?? '');
		}

		if ($variable) {
			self::sync_variations($id, $item, $seller);
		}
		wc_delete_product_transients($id);
	}

	private static function sync_variations($parent_id, $item, $seller) {
		$parent = wc_get_product($parent_id);
		$keep = [];
		foreach ($item['variations'] as $position => $v) {
			$vid = self::post_id_by_meta('_verola_variation_id', $v['id']);
			$variation = $vid ? new WC_Product_Variation($vid) : new WC_Product_Variation();
			$variation->set_parent_id($parent_id);
			$attrs = [];
			foreach ($v['options'] as $name => $value) {
				$attrs[sanitize_title($name)] = $value;
			}
			$variation->set_attributes($attrs);
			$variation->set_sku(self::free_sku($v['sku'], $vid, $seller));
			self::set_prices($variation, $v);
			// WooCommerce owns stock. A count is only pushed when Vendor Hub has a real one (more than zero);
			// "no count" never turns a variation into out of stock.
			if (!empty($item['manage_stock']) && (int) $v['stock'] > 0) {
				$variation->set_manage_stock(true);
				$variation->set_stock_quantity((int) $v['stock']);
			}
			elseif ($vid && !get_post_meta($vid, '_verola_stock_v2', true) && !$variation->managing_stock()) {
				// One-time clean-up of the "out of stock" flag older versions left on variations that have no count.
				$variation->set_stock_status('instock');
			}
			$variation->set_backorders(!empty($item['allow_backorders']) ? 'yes' : 'no');
			$variation->set_menu_order($position);
			$variation->set_status('publish');
			$img = self::image($v['image'] ?? null);
			if ($img) {
				$variation->set_image_id($img);
			}
			$saved = $variation->save();
			update_post_meta($saved, '_verola_variation_id', $v['id']);
			update_post_meta($saved, '_verola_stock_v2', '1');
			$keep[] = (int) $saved;
		}
		foreach ($parent->get_children() as $child) {
			if (!in_array((int) $child, $keep, true)) {
				wp_delete_post($child, true);
			}
		}
		WC_Product_Variable::sync($parent_id);
	}



	// ------------------------------------------------------------------ addresses

	// WooCommerce's Nigerian state codes, keyed by lower-case name.
	private static function ng_state_code($name) {
		static $map = ['abia' => 'AB', 'abuja' => 'FC', 'fct' => 'FC', 'federal capital territory' => 'FC', 'adamawa' => 'AD', 'akwa ibom' => 'AK', 'anambra' => 'AN', 'bauchi' => 'BA', 'bayelsa' => 'BY', 'benue' => 'BE', 'borno' => 'BO',
			'cross river' => 'CR', 'delta' => 'DE', 'ebonyi' => 'EB', 'edo' => 'ED', 'ekiti' => 'EK', 'enugu' => 'EN', 'gombe' => 'GO', 'imo' => 'IM', 'jigawa' => 'JI', 'kaduna' => 'KD', 'kano' => 'KN', 'katsina' => 'KT', 'kebbi' => 'KE',
			'kogi' => 'KO', 'kwara' => 'KW', 'lagos' => 'LA', 'nasarawa' => 'NA', 'niger' => 'NI', 'ogun' => 'OG', 'ondo' => 'ON', 'osun' => 'OS', 'oyo' => 'OY', 'plateau' => 'PL', 'rivers' => 'RI', 'sokoto' => 'SO', 'taraba' => 'TA', 'yobe' => 'YO', 'zamfara' => 'ZA'];
		$k = trim(preg_replace('/\s+state$/i', '', strtolower((string) $name)));
		return $map[$k] ?? '';
	}

	// The seller's verified business address from Vendor Hub. Stored on the vendor and copied onto the Dokan store address,
	// so anything that prices or books delivery from the store address uses the seller's real address, not the marketplace's.
	private static function save_pickup($uid, $seller) {
		$p = is_array($seller['pickup'] ?? null) ? $seller['pickup'] : [];
		if (empty($p['street']) && empty($p['city'])) {
			return;
		}
		$country = stripos((string) ($p['country'] ?? 'Nigeria'), 'nigeria') !== false || empty($p['country']) ? 'NG' : strtoupper(substr((string) $p['country'], 0, 2));
		$state = $country === 'NG' ? self::ng_state_code($p['state'] ?? '') : (string) ($p['state'] ?? '');
		$origin = ['address_1' => (string) $p['street'], 'city' => (string) $p['city'], 'state' => $state, 'state_name' => (string) ($p['state'] ?? ''), 'country' => $country, 'postcode' => '', 'phone' => (string) ($p['phone'] ?? '')];
		update_user_meta($uid, '_verola_pickup', $origin);
		if (function_exists('dokan')) {
			$profile = get_user_meta($uid, 'dokan_profile_settings', true);
			$profile = is_array($profile) ? $profile : [];
			$addr = is_array($profile['address'] ?? null) ? $profile['address'] : [];
			$profile['address'] = array_merge($addr, ['street_1' => $origin['address_1'], 'city' => $origin['city'], 'state' => $origin['state'], 'country' => $origin['country'], 'zip' => $addr['zip'] ?? '']);
			update_user_meta($uid, 'dokan_profile_settings', $profile);
		}
	}

	// Where a vendor's parcels are collected: ['address_1','city','state','country','postcode','phone'] or null when unknown.
	// Delivery code should call this with the package's vendor (Dokan: $package['seller_id']). Filter: verola_vendor_origin.
	public static function vendor_origin($vendor_id) {
		$o = get_user_meta((int) $vendor_id, '_verola_pickup', true);
		return apply_filters('verola_vendor_origin', is_array($o) && !empty($o['address_1']) ? $o : null, (int) $vendor_id);
	}

	public static function address_fields($fields) {
		if (isset($fields['address_1'])) {
			$fields['address_1']['label'] = 'Street address';
			$fields['address_1']['placeholder'] = 'House number, street and area, e.g. 12 Allen Avenue, Ikeja';
			$fields['address_1']['autocomplete'] = 'address-line1';
		}
		if (isset($fields['city'])) {
			$fields['city']['required'] = true;
			$fields['city']['label'] = 'Town / City';
		}
		if (isset($fields['state'])) {
			$fields['state']['required'] = true;
		}
		return $fields;
	}

	public static function checkout_fields($fields) {
		if (isset($fields['billing']['billing_phone'])) {
			$fields['billing']['billing_phone']['required'] = true;
			$fields['billing']['billing_phone']['placeholder'] = '0803 000 0000';
			$fields['billing']['billing_phone']['autocomplete'] = 'tel';
		}
		return $fields;
	}

	// A rider has to find the door, so an address that cannot be found is stopped before payment.
	public static function validate_checkout($data, $errors) {
		if (!WC()->cart || !WC()->cart->needs_shipping()) {
			return;
		}
		$types = ['billing'];
		if (!empty($data['ship_to_different_address'])) {
			$types[] = 'shipping';
		}
		foreach ($types as $t) {
			if (($data[$t . '_country'] ?? '') !== 'NG') {
				continue;
			}
			$label = $t === 'shipping' ? 'Delivery' : 'Billing';
			$street = trim((string) ($data[$t . '_address_1'] ?? ''));
			$city = trim((string) ($data[$t . '_city'] ?? ''));
			if (empty($data[$t . '_state'])) {
				$errors->add('verola_state', $label . ': please choose your state.');
			}
			if (mb_strlen($city) < 3 || !preg_match('/\p{L}{3}/u', $city)) {
				$errors->add('verola_city', $label . ': please enter your town or city.');
			}
			if (mb_strlen($street) < 8 || !preg_match('/\p{L}{3}/u', $street) || strcasecmp($street, $city) === 0) {
				$errors->add('verola_street', $label . ': please enter your full street address, with the house number or name, street and area, so the rider can find you.');
			}
		}
		$phone = preg_replace('/[\s\-\(\)\.]/', '', (string) ($data['billing_phone'] ?? ''));
		if (($data['billing_country'] ?? '') === 'NG' && !preg_match('/^(\+?234|0)[789][01]\d{8}$/', $phone)) {
			$errors->add('verola_phone', 'Please enter a phone number the rider can call, for example 0803 000 0000.');
		}
	}

	// ------------------------------------------------------------------ returns (marketplace admin)

	private static function returns_feed($status) {
		$s = self::settings();
		$res = wp_remote_get($s['url'] . '/api/marketplace/returns/all?status=' . rawurlencode($status) . '&limit=100', ['timeout' => 15, 'headers' => ['Authorization' => 'Bearer ' . $s['api_key']]]);
		if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
			return null;
		}
		$j = json_decode(wp_remote_retrieve_body($res), true);
		return is_array($j['cases'] ?? null) ? $j['cases'] : [];
	}

	public static function returns_page() {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}
		$status = in_array($_GET['status'] ?? 'open', ['open', 'done', 'all'], true) ? $_GET['status'] : 'open';
		$cases = self::configured() ? self::returns_feed($status) : null;
		echo '<div class="wrap"><h1>Returns and refunds</h1><p>Return requests from customers, where each one stands, and who is waiting on whom. Sellers answer and DDLX decides in Vendor Hub.</p><p>';
		foreach (['open' => 'Open', 'done' => 'Closed', 'all' => 'All'] as $k => $l) {
			echo '<a class="button' . ($status === $k ? ' button-primary' : '') . '" style="margin-right:6px" href="' . esc_url(admin_url('admin.php?page=verola-returns&status=' . $k)) . '">' . esc_html($l) . '</a>';
		}
		echo '</p>';
		if ($cases === null) {
			echo '<div class="notice notice-error inline"><p>Could not reach Vendor Hub. Check the connection on the Vendor Hub settings page.</p></div></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Order</th><th>Item</th><th>Seller</th><th>Reason</th><th>Stage</th><th>Waiting on</th><th>Refund</th><th>Opened</th></tr></thead><tbody>';
		foreach ($cases as $c) {
			$o = wc_get_order((int) $c['order_id']);
			$link = $o ? '<a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a>' : esc_html('#' . ($c['order_number'] ?: $c['order_id']));
			$refund = ['to_pay' => 'To pay', 'sending' => 'Sending', 'refunded' => 'Refunded', 'manual' => 'Refunded', 'failed' => 'Failed, needs attention'][$c['refund_status'] ?? ''] ?? '';
			echo '<tr><td>' . $link . '</td><td>' . esc_html($c['item']) . '<br><small>' . esc_html(strip_tags(wc_price($c['amount']))) . '</small></td><td>' . esc_html($c['seller']) . '</td><td>' . esc_html($c['reason']) . '<br><small>' . ($c['kind'] === 'refund_only' ? 'Refund without sending it back' : 'Item comes back first') . '</small></td>'
				. '<td><strong>' . esc_html($c['stage']) . '</strong>' . ($c['tracking'] ? '<br><small>Sent with ' . esc_html($c['tracking']) . '</small>' : '') . '</td><td>' . esc_html($c['waiting_on']) . '</td><td>' . esc_html($refund) . '</td><td>' . esc_html(wp_date(get_option('date_format'), strtotime($c['created_at']))) . '</td></tr>';
		}
		if (!$cases) {
			echo '<tr><td colspan="8">No return requests here.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function return_metabox() {
		$screens = ['shop_order'];
		if (function_exists('wc_get_page_screen_id')) {
			$screens[] = wc_get_page_screen_id('shop-order');
		}
		add_meta_box('verola_returns', 'Returns (Vendor Hub)', [__CLASS__, 'return_metabox_html'], $screens, 'side', 'default');
		add_meta_box('verola_origin', 'Courier pickup address', [__CLASS__, 'origin_metabox_html'], $screens, 'side', 'high');
	}

	public static function return_metabox_html($post) {
		$order = $post instanceof WC_Order ? $post : wc_get_order(is_object($post) ? $post->ID : (int) $post);
		if (!$order || !self::configured()) {
			echo 'Not connected.';
			return;
		}
		$cases = self::return_cases($order);
		if (!$cases) {
			echo '<p>No return requests on this order.</p>';
			return;
		}
		foreach ($cases as $c) {
			echo '<p><strong>' . esc_html($c['label']) . '</strong><br>' . esc_html($c['reason']) . (!empty($c['tracking']) ? '<br>Sent with ' . esc_html($c['tracking']) : '') . '</p>';
		}
		echo '<p><a href="' . esc_url(admin_url('admin.php?page=verola-returns&status=all')) . '">All returns</a></p>';
	}


	// ------------------------------------------------------------------ return pickups and courier origin

	// Vendor Hub asks for a courier pickup of a returned item: customer's delivery address to the seller's store.
	// It becomes a normal order in "Ready for shipping", so the courier is booked the same way as any delivery.
	public static function rest_return_pickup($request) {
		$case = sanitize_text_field((string) $request->get_param('case_id'));
		$orig = wc_get_order((int) $request->get_param('order_id'));
		if (!$orig || $case === '') {
			return new WP_REST_Response(['error' => 'Unknown order.'], 404);
		}
		$existing = wc_get_orders(['limit' => 1, 'meta_key' => '_vh_return_case', 'meta_value' => $case, 'return' => 'ids']);
		if ($existing) {
			return ['ok' => true, 'order_id' => (string) $existing[0]];
		}
		$hub_pid = (string) $request->get_param('product_id');
		$hub_vid = (string) $request->get_param('variation_id');
		$qty = max(1, (int) $request->get_param('quantity'));
		$product = null;
		foreach ($orig->get_items() as $line) {
			$pid = (int) $line->get_product_id();
			$vid = (int) $line->get_variation_id();
			if (get_post_meta($pid, '_verola_product_id', true) === $hub_pid && ($hub_vid === '' || $hub_vid === 'null' || get_post_meta($vid, '_verola_variation_id', true) === $hub_vid)) {
				$product = $line->get_product();
				break;
			}
		}
		$pick = (array) $request->get_param('pickup');
		$country = stripos((string) ($pick['country'] ?? 'Nigeria'), 'nigeria') !== false || empty($pick['country']) ? 'NG' : strtoupper(substr((string) $pick['country'], 0, 2));
		$dest = ['first_name' => sanitize_text_field((string) $request->get_param('seller_name')), 'last_name' => '(return)', 'address_1' => sanitize_text_field((string) ($pick['street'] ?? '')), 'city' => sanitize_text_field((string) ($pick['city'] ?? '')),
			'state' => $country === 'NG' ? self::ng_state_code($pick['state'] ?? '') : sanitize_text_field((string) ($pick['state'] ?? '')), 'country' => $country, 'phone' => sanitize_text_field((string) ($pick['phone'] ?? ''))];
		$from = [];
		foreach (['first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone'] as $f) {
			$v = (string) $orig->{'get_shipping_' . $f}();
			$from[$f] = $v !== '' ? $v : (string) $orig->{'get_billing_' . $f}();
		}
		$ret = wc_create_order(['customer_id' => $orig->get_customer_id(), 'created_via' => 'verola_return', 'status' => 'pending']);
		if (is_wp_error($ret)) {
			return new WP_REST_Response(['error' => $ret->get_error_message()], 500);
		}
		$ret->update_meta_data('_vh_return_case', $case);
		$ret->update_meta_data('_vh_return_of', $orig->get_id());
		$ret->update_meta_data('_vh_pickup_origin', $from);
		$ret->set_address(['first_name' => $orig->get_billing_first_name(), 'last_name' => $orig->get_billing_last_name(), 'email' => $orig->get_billing_email(), 'phone' => $orig->get_billing_phone(),
			'address_1' => $from['address_1'], 'city' => $from['city'], 'state' => $from['state'], 'postcode' => $from['postcode'], 'country' => $from['country']], 'billing');
		$ret->set_address($dest, 'shipping');
		$name = sanitize_text_field((string) $request->get_param('item_name'));
		if ($product) {
			$ret->add_product($product, $qty, ['subtotal' => 0, 'total' => 0]);
		} else {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name('Return: ' . $name);
			$fee->set_total(0);
			$ret->add_item($fee);
		}
		$ret->set_total(0);
		$ret->save();
		// The item is going back to the seller, not being sold again: never touch stock for this order.
		try {
			$ret->get_data_store()->set_stock_reduced($ret->get_id(), true);
		} catch (Throwable $e) {
			$ret->update_meta_data('_order_stock_reduced', 'yes');
		}
		$ret->add_order_note(sprintf('Return pickup for order #%s (%s). Collect from the customer at the shipping origin shown above and deliver to %s, %s. Vendor Hub case %s.', $orig->get_order_number(), $name, $dest['first_name'], trim($dest['address_1'] . ', ' . $dest['city']), $case));
		$ret->set_status('ready-shipping', 'Return pickup requested by Vendor Hub.');
		$ret->save();
		$orig->add_order_note('Return pickup created as order #' . $ret->get_order_number() . ' for ' . $name . '.');
		return ['ok' => true, 'order_id' => (string) $ret->get_id()];
	}

	// Tells Vendor Hub when the courier collected a return or delivered it to the seller. Retries if Vendor Hub is unreachable.
	public static function send_return_event($order_id, $status, $attempt = 1) {
		$order = wc_get_order($order_id);
		$case = $order ? (string) $order->get_meta('_vh_return_case') : '';
		if (!$order || $case === '' || !self::configured() || $order->get_meta('_verola_ret_' . $status)) {
			return;
		}
		$s = self::settings();
		$body = wp_json_encode(['case_id' => $case, 'order_id' => (string) $order_id, 'status' => $status]);
		$ts = time();
		$res = wp_remote_post($s['url'] . '/api/marketplace/returns/pickup-status', [
			'timeout' => 20,
			'headers' => ['Content-Type' => 'application/json', 'X-Verola-Timestamp' => (string) $ts, 'X-Verola-Signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $s['secret'])],
			'body' => $body,
		]);
		$code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
		if ($code >= 200 && $code < 300) {
			$order->update_meta_data('_verola_ret_' . $status, time());
			$order->add_order_note('Reported to Vendor Hub (return ' . $status . ').');
			$order->save();
			return;
		}
		if ($code >= 400 && $code < 500 && $code !== 404 || $attempt >= 6) {
			$order->add_order_note('Could not report the return to Vendor Hub (' . $status . '): ' . (is_wp_error($res) ? $res->get_error_message() : 'status ' . $code) . '. Not retrying.');
			$order->save();
			return;
		}
		wp_schedule_single_event(time() + 300 * $attempt, self::RETURN_EVENT, [(int) $order_id, $status, $attempt + 1]);
	}

	// ---- courier origin: the store address WooCommerce reports follows the seller

	private static $pkg_vendor = null;
	private static $origin_busy = false;

	// Any courier plugin that reads the WooCommerce store address (Settings > General) while pricing or booking a parcel gets the
	// seller's verified pickup address instead, so changing the courier needs no change here. Return pickups use the customer's address.
	public static function hook_origin() {
		if (!apply_filters('verola_use_vendor_origin', !defined('VEROLA_NO_VENDOR_ORIGIN'))) {
			return;
		}
		$map = ['woocommerce_store_address' => 'address_1', 'woocommerce_store_address_2' => 'address_2', 'woocommerce_store_city' => 'city', 'woocommerce_store_postcode' => 'postcode', 'woocommerce_default_country' => 'country'];
		foreach ($map as $option => $key) {
			add_filter('pre_option_' . $option, function ($pre) use ($key) {
				$o = self::current_origin();
				if (!$o) {
					return $pre;
				}
				return $key === 'country' ? ($o['country'] ?: 'NG') . (!empty($o['state']) ? ':' . $o['state'] : '') : (string) ($o[$key] ?? '');
			});
		}
		// At checkout each vendor's package is priced on its own: note whose parcel it is just before the shipping methods run.
		if (WC()->shipping()) {
			foreach (array_keys(WC()->shipping()->get_shipping_method_class_names()) as $id) {
				add_filter('woocommerce_shipping_' . $id . '_is_available', function ($available, $package = null) {
					if (is_array($package)) {
						self::$pkg_vendor = self::package_vendor($package);
					}
					return $available;
				}, 5, 2);
			}
		}
		add_filter('woocommerce_package_rates', function ($rates) {
			self::$pkg_vendor = null;
			return $rates;
		}, 1);
	}

	private static function package_vendor($package) {
		foreach (['seller_id', 'vendor_id'] as $k) {
			if (!empty($package[$k])) {
				return (int) $package[$k];
			}
		}
		$authors = [];
		foreach ((array) ($package['contents'] ?? []) as $item) {
			$authors[(int) get_post_field('post_author', (int) ($item['product_id'] ?? 0))] = true;
		}
		return count($authors) === 1 ? (int) array_key_first($authors) : null;
	}

	private static function order_origin($order) {
		$o = $order->get_meta('_vh_pickup_origin');
		if (is_array($o) && !empty($o['address_1'])) {
			return ['address_1' => $o['address_1'], 'address_2' => $o['address_2'] ?? '', 'city' => $o['city'] ?? '', 'postcode' => $o['postcode'] ?? '', 'state' => $o['state'] ?? '', 'country' => $o['country'] ?? 'NG'];
		}
		$vendors = [];
		$vid = (int) $order->get_meta('_dokan_vendor_id');
		if ($vid) {
			$vendors[$vid] = true;
		} else {
			foreach ($order->get_items() as $line) {
				$vendors[(int) get_post_field('post_author', (int) $line->get_product_id())] = true;
			}
		}
		if (count($vendors) !== 1) {
			return null;
		}
		return self::vendor_origin((int) array_key_first($vendors));
	}

	private static function current_origin() {
		if (self::$origin_busy) {
			return null;
		}
		self::$origin_busy = true;
		try {
			if (self::$pkg_vendor) {
				return self::vendor_origin(self::$pkg_vendor);
			}
			if (is_admin() && (($_GET['page'] ?? '') !== 'wc-settings')) {
				foreach (['order_id', 'post', 'post_ID', 'id', 'order'] as $k) {
					$id = isset($_REQUEST[$k]) && is_scalar($_REQUEST[$k]) ? absint($_REQUEST[$k]) : 0;
					if ($id) {
						$order = wc_get_order($id);
						if ($order && $order->get_type() === 'shop_order') {
							return self::order_origin($order);
						}
					}
				}
			}
			return null;
		} finally {
			self::$origin_busy = false;
		}
	}

	public static function origin_metabox_html($post) {
		$order = $post instanceof WC_Order ? $post : wc_get_order(is_object($post) ? $post->ID : (int) $post);
		$o = $order ? self::order_origin($order) : null;
		if (!$o) {
			echo '<p>No seller address on this order. The courier uses the store address from WooCommerce settings.</p>';
			return;
		}
		echo '<p>The courier plugin collects from:<br><strong>' . esc_html(trim($o['address_1'] . ' ' . ($o['address_2'] ?? ''))) . '<br>' . esc_html($o['city']) . ', ' . esc_html($o['state']) . ' ' . esc_html($o['country']) . '</strong></p>'
			. ($order->get_meta('_vh_return_case') ? '<p><em>Return pickup: collected from the customer.</em></p>' : '<p><em>The seller\'s verified business address.</em></p>');
	}

	// ------------------------------------------------------------------ customer returns (My Account -> Vendor Hub)

	const RETURN_REASONS = ['Damaged or defective', 'Wrong item sent', 'Not as described', 'Never arrived', 'Arrived late', 'Changed mind', 'Other'];

	private static function return_cases($order) {
		$key = 'verola_rc_' . $order->get_id();
		$cached = get_transient($key);
		if (is_array($cached)) {
			return $cached;
		}
		$s = self::settings();
		$cases = [];
		$res = wp_remote_get($s['url'] . '/api/marketplace/returns?order_id=' . rawurlencode((string) $order->get_id()), ['timeout' => 8, 'headers' => ['Authorization' => 'Bearer ' . $s['api_key']]]);
		if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200) {
			$j = json_decode(wp_remote_retrieve_body($res), true);
			$cases = is_array($j['cases'] ?? null) ? $j['cases'] : [];
		}
		set_transient($key, $cases, 60);
		return $cases;
	}

	// "Request a return" for each Vendor Hub item on an order that has shipped or been delivered.
	public static function return_panel($order) {
		if (!($order instanceof WC_Order) || !self::configured() || $order->get_parent_id() || $order->get_meta('_vh_return_case') || !is_user_logged_in() || (int) $order->get_user_id() !== get_current_user_id()) {
			return;
		}
		if (self::hub_rank(self::hub_status($order->get_status())) < 3) {
			return;
		}
		$flash = get_transient('verola_rn_' . get_current_user_id());
		if (is_array($flash)) {
			delete_transient('verola_rn_' . get_current_user_id());
			echo '<div class="woocommerce-' . ($flash[0] === 'error' ? 'error' : 'message') . '" role="alert">' . esc_html($flash[1]) . '</div>';
		}
		$cases = self::return_cases($order);
		$rows = '';
		foreach ($order->get_items() as $item_id => $line) {
			$pid = (int) $line->get_product_id();
			if (!get_post_meta($pid, '_verola_product_id', true)) {
				continue;
			}
			$vid = (int) $line->get_variation_id();
			$hub_vid = $vid ? get_post_meta($vid, '_verola_variation_id', true) : '';
			$hub_pid = get_post_meta($pid, '_verola_product_id', true);
			$mine = null;
			foreach ($cases as $c) {
				if (($c['product_id'] ?? '') === $hub_pid && (($c['variation_id'] ?? '') ?: '') === ($hub_vid ?: '')) {
					$mine = $c;
					break;
				}
			}
			$rows .= '<li class="vh-ret-item"><div class="vh-ret-name">' . esc_html($line->get_name()) . '</div>';
			if ($mine && !in_array($mine['status'], ['rejected'], true)) {
				$rows .= '<div class="vh-ret-status"><strong>' . esc_html($mine['label']) . '</strong>' . (!empty($mine['note']) ? '<span>' . esc_html($mine['note']) . '</span>' : '') . (!empty($mine['tracking']) ? '<span>Sent with ' . esc_html($mine['tracking']) . '</span>' : '') . '</div>';
				if (!empty($mine['can_ship'])) {
					$rows .= '<div class="vh-ret-ship"><p>Send the item back' . (!empty($mine['ship_by']) ? ' by ' . esc_html(wp_date(get_option('date_format'), strtotime($mine['ship_by']))) : '') . ($mine['return_address'] !== '' ? ' to:<br><strong>' . nl2br(esc_html($mine['return_address'])) . '</strong>' : '.') . '</p>'
						. '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="verola_return_ship"><input type="hidden" name="order_id" value="' . (int) $order->get_id() . '"><input type="hidden" name="case_id" value="' . esc_attr($mine['id']) . '">'
						. wp_nonce_field('verola_return_ship_' . $order->get_id(), '_wpnonce', true, false)
						. '<label>Courier<input type="text" name="carrier" required maxlength="80" placeholder="e.g. DHL, GIG Logistics"></label><label>Tracking number<input type="text" name="tracking" required maxlength="100"></label><button type="submit" class="button">I sent it</button></form></div>';
				}
			} else {
				if ($mine) {
					$rows .= '<div class="vh-ret-status"><strong>' . esc_html($mine['label']) . '</strong>' . (!empty($mine['note']) ? '<span>' . esc_html($mine['note']) . '</span>' : '') . '</div>';
				}
				$rows .= '<details class="vh-ret-form"><summary>Request a return</summary>'
					. '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">'
					. '<input type="hidden" name="action" value="verola_return"><input type="hidden" name="order_id" value="' . (int) $order->get_id() . '"><input type="hidden" name="item_id" value="' . (int) $item_id . '">'
					. wp_nonce_field('verola_return_' . $order->get_id(), '_wpnonce', true, false)
					. '<label>Reason<select name="reason" required><option value="">Choose a reason</option>';
				foreach (self::RETURN_REASONS as $r) {
					$rows .= '<option>' . esc_html($r) . '</option>';
				}
				$rows .= '</select></label><label>What happened?<textarea name="details" rows="3" maxlength="2000" placeholder="Tell us what is wrong"></textarea></label>'
					. '<label>Photos (needed for damaged items)<input type="file" name="photos[]" accept="image/*" multiple></label>'
					. '<button type="submit" class="button">Send request</button></form></details>';
			}
			$rows .= '</li>';
		}
		if ($rows === '') {
			return;
		}
		echo '<section class="vh-returns"><h2>Returns</h2>'
			. '<p class="vh-ret-note">You can ask for a return within 7 days of delivery. For a change of mind, you pay the return shipping. Damaged items and items that never arrived are refunded without the item being sent back.</p>'
			. '<ul>' . $rows . '</ul></section>'
			. '<style>.vh-returns{margin:24px 0;padding:20px;border:1px solid #e2e8f0;border-radius:14px;background:#fff}.vh-returns h2{margin:0 0 8px;font-size:1.15rem}.vh-ret-note{margin:0 0 14px;color:#64748b;font-size:.85rem}.vh-returns ul{list-style:none;margin:0;padding:0}.vh-ret-item{padding:14px 0;border-top:1px solid #eef2f6}.vh-ret-name{font-weight:600;margin-bottom:6px}.vh-ret-status strong{display:inline-block;padding:4px 10px;border-radius:999px;background:#eaf4fb;color:#1b88ca;font-size:.8rem}.vh-ret-status span{display:block;margin-top:4px;color:#64748b;font-size:.85rem}.vh-ret-form summary{cursor:pointer;color:#1b88ca;font-weight:700;margin-top:6px}.vh-ret-form form{display:grid;gap:12px;margin-top:12px}.vh-ret-form label,.vh-ret-ship label{display:grid;gap:6px;font-size:.85rem;font-weight:600}.vh-ret-ship{margin-top:10px;padding:12px;border-radius:10px;background:#f8fafc}.vh-ret-ship p{margin:0 0 10px;font-size:.88rem}.vh-ret-ship form{display:grid;gap:10px}</style>';
	}

	// Admin-post requests have no shopping session, so the result waits in a short-lived transient until the order page shows it.
	private static function return_flash($msg, $kind) {
		set_transient('verola_rn_' . get_current_user_id(), [$kind, $msg], 300);
	}

	public static function return_submit() {
		$order_id = (int) ($_POST['order_id'] ?? 0);
		$order = wc_get_order($order_id);
		$back = $order ? $order->get_view_order_url() : wc_get_page_permalink('myaccount');
		if (!is_user_logged_in() || !$order || (int) $order->get_user_id() !== get_current_user_id() || !self::configured()) {
			wp_safe_redirect($back);
			exit;
		}
		check_admin_referer('verola_return_' . $order_id);
		$line = $order->get_item((int) ($_POST['item_id'] ?? 0));
		$pid = $line ? (int) $line->get_product_id() : 0;
		$hub_pid = $pid ? get_post_meta($pid, '_verola_product_id', true) : '';
		if (!$hub_pid) {
			self::return_flash('That item cannot be returned here.', 'error');
			wp_safe_redirect($back);
			exit;
		}
		$vid = (int) $line->get_variation_id();
		$photos = [];
		if (!empty($_FILES['photos']['name'][0])) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$mimes = ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
			foreach (array_slice(array_keys($_FILES['photos']['name']), 0, 4) as $i) {
				if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK || $_FILES['photos']['size'][$i] > 6 * MB_IN_BYTES) {
					continue;
				}
				$up = wp_handle_upload([
					'name' => $_FILES['photos']['name'][$i], 'type' => $_FILES['photos']['type'][$i], 'tmp_name' => $_FILES['photos']['tmp_name'][$i],
					'error' => 0, 'size' => $_FILES['photos']['size'][$i],
				], ['test_form' => false, 'mimes' => $mimes]);
				if (!empty($up['url'])) {
					$photos[] = $up['url'];
				}
			}
		}
		$payload = ['order_id' => (string) $order_id, 'product_id' => $hub_pid, 'variation_id' => $vid ? (get_post_meta($vid, '_verola_variation_id', true) ?: null) : null,
			'reason' => sanitize_text_field(wp_unslash($_POST['reason'] ?? '')), 'details' => sanitize_textarea_field(wp_unslash($_POST['details'] ?? '')), 'photos' => $photos];
		$s = self::settings();
		$body = wp_json_encode($payload);
		$ts = time();
		$res = wp_remote_post($s['url'] . '/api/marketplace/returns', [
			'timeout' => 20,
			'headers' => ['Content-Type' => 'application/json', 'X-Verola-Timestamp' => (string) $ts, 'X-Verola-Signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $s['secret'])],
			'body' => $body,
		]);
		$code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
		$j = is_wp_error($res) ? [] : (json_decode(wp_remote_retrieve_body($res), true) ?: []);
		if ($code >= 200 && $code < 300) {
			delete_transient('verola_rc_' . $order_id);
			$order->add_order_note('Customer asked for a return (' . $payload['reason'] . ') for ' . $line->get_name() . '. Sent to Vendor Hub.');
			self::return_flash('Your return request has been received. We will keep you updated, and you can follow its progress on this page.', 'success');
		} elseif ($code >= 400 && $code < 500 && !empty($j['error'])) {
			self::return_flash(sanitize_text_field($j['error']), 'error');
		} else {
			self::return_flash('We could not send your request right now. Please try again in a few minutes.', 'error');
		}
		wp_safe_redirect($back);
		exit;
	}


	public static function return_ship_submit() {
		$order_id = (int) ($_POST['order_id'] ?? 0);
		$order = wc_get_order($order_id);
		$back = $order ? $order->get_view_order_url() : wc_get_page_permalink('myaccount');
		if (!is_user_logged_in() || !$order || (int) $order->get_user_id() !== get_current_user_id() || !self::configured()) {
			wp_safe_redirect($back);
			exit;
		}
		check_admin_referer('verola_return_ship_' . $order_id);
		$payload = ['order_id' => (string) $order_id, 'case_id' => sanitize_text_field(wp_unslash($_POST['case_id'] ?? '')),
			'carrier' => sanitize_text_field(wp_unslash($_POST['carrier'] ?? '')), 'tracking' => sanitize_text_field(wp_unslash($_POST['tracking'] ?? ''))];
		$s = self::settings();
		$body = wp_json_encode($payload);
		$ts = time();
		$res = wp_remote_post($s['url'] . '/api/marketplace/returns/ship', [
			'timeout' => 20,
			'headers' => ['Content-Type' => 'application/json', 'X-Verola-Timestamp' => (string) $ts, 'X-Verola-Signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $s['secret'])],
			'body' => $body,
		]);
		$code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
		$j = is_wp_error($res) ? [] : (json_decode(wp_remote_retrieve_body($res), true) ?: []);
		delete_transient('verola_rc_' . $order_id);
		if ($code >= 200 && $code < 300) {
			$order->add_order_note('Customer sent a return back (' . $payload['carrier'] . ' ' . $payload['tracking'] . ').');
			self::return_flash('Thank you. Once your item is received, your refund will be initiated.', 'success');
		} elseif ($code >= 400 && $code < 500 && !empty($j['error'])) {
			self::return_flash(sanitize_text_field($j['error']), 'error');
		} else {
			self::return_flash('We could not send that right now. Please try again in a few minutes.', 'error');
		}
		wp_safe_redirect($back);
		exit;
	}

	// ------------------------------------------------------------------ sales (store -> Vendor Hub)

	public static function order_paid($order_id) {
		self::queue($order_id, 'order.paid');
	}

	public static function order_refunded($order_id) {
		self::queue($order_id, 'order.refunded');
	}

	// A cancelled order only matters to Vendor Hub if it had been paid.
	public static function order_cancelled($order_id) {
		$order = wc_get_order($order_id);
		if ($order && $order->get_date_paid()) {
			self::queue($order_id, 'order.refunded');
		}
	}

	// Maps any marketplace status to the step Vendor Hub knows, by its slug or its label. Custom statuses from other plugins
	// (for example "Out for delivery" or "Order Delivered" from a status plugin) are recognised too. Returns null for anything else.
	private static function hub_status($slug) {
		$slug = strtolower(preg_replace('/^wc-/', '', (string) $slug));
		if ($slug === 'completed') {
			return 'completed';
		}
		$text = $slug . ' ' . strtolower((string) wc_get_order_status_name($slug));
		if (preg_match('/ready[\s_-]*(for)?[\s_-]*ship/', $text)) {
			return 'ready_for_shipping';
		}
		if (preg_match('/out[\s_-]*for[\s_-]*deliver/', $text)) {
			return 'shipped';
		}
		if (preg_match('/deliver/', $text) && !preg_match('/awaiting|pending|failed|to[\s_-]*be/', $text)) {
			return 'delivered';
		}
		if (preg_match('/shipped|dispatch|transit/', $text)) {
			return 'shipped';
		}
		return null;
	}

	private static function hub_rank($s) {
		return ['ready_for_shipping' => 2, 'shipped' => 3, 'delivered' => 4, 'completed' => 5][$s] ?? 0;
	}

	// Reports delivery progress. The hub must already know the order, so an order that skipped "processing" is reported as paid first.
	public static function order_status_changed($order_id, $from, $to) {
		$hub = self::hub_status($to);
		$order = wc_get_order($order_id);
		if (!$hub || !$order) {
			return;
		}
		// A return pickup is its own order: the courier collecting or delivering it moves the return case, never the seller's sale.
		if ($order->get_meta('_vh_return_case')) {
			if (self::hub_rank($hub) >= 3) {
				wp_schedule_single_event(time() + 5, self::RETURN_EVENT, [(int) $order_id, $hub === 'shipped' ? 'shipped' : 'delivered', 1]);
			}
			return;
		}
		$target = (int) $order_id;
		// Dokan splits a multi-seller order into one sub-order per seller. Vendor Hub knows the parent order, so a
		// sub-order's change is reported on the parent once every sub-order has reached that step.
		if ($order->get_parent_id()) {
			$target = (int) $order->get_parent_id();
			foreach (wc_get_orders(['parent' => $target, 'limit' => -1, 'return' => 'ids']) as $kid) {
				$k = wc_get_order($kid);
				if (!$k || self::hub_rank(self::hub_status($k->get_status())) < self::hub_rank($hub)) {
					return;
				}
			}
		}
		self::queue($target, 'order.paid');
		self::queue($target, 'order.status:' . $hub, 20);
	}

	private static function queue($order_id, $event, $delay = 5) {
		$order = wc_get_order($order_id);
		// Dokan splits a multi-seller order into one sub-order per seller. The parent order already holds every
		// item, so only parent orders are reported; reporting the sub-orders as well would pay sellers twice.
		if (!$order || $order->get_parent_id() || $order->get_meta('_vh_return_case') || $order->get_meta('_verola_sent_' . $event)) {
			return;
		}
		// Send in the background so checkout is never slowed down or broken by Vendor Hub being offline.
		wp_schedule_single_event(time() + $delay, self::SEND, [(int) $order_id, $event, 1]);
	}

	private static function payload($order, $event) {
		if (strpos($event, 'order.status:') === 0) {
			return [
				'event' => 'order.status',
				'order' => [
					'id' => (string) $order->get_id(),
					'status' => substr($event, strlen('order.status:')),
					'carrier' => (string) $order->get_meta('_vh_carrier'),
					'tracking_number' => (string) $order->get_meta('_vh_tracking'),
				],
			];
		}
		$items = [];
		foreach ($order->get_items() as $line) {
			$product = $line->get_product();
			if (!$product) {
				continue;
			}
			$variation_id = null;
			if ($product->is_type('variation')) {
				$variation_id = get_post_meta($product->get_id(), '_verola_variation_id', true) ?: null;
				$verola_id = get_post_meta($product->get_parent_id(), '_verola_product_id', true);
			} else {
				$verola_id = get_post_meta($product->get_id(), '_verola_product_id', true);
			}
			$qty = max(1, (int) $line->get_quantity());
			if (!$verola_id) {
				continue;
			}
			$row = ['product_id' => $verola_id, 'quantity' => $qty, 'unit_price' => round(((float) $line->get_total()) / $qty, 2), 'discount' => round(max(0, (float) $line->get_subtotal() - (float) $line->get_total()), 2)];
			if ($variation_id) {
				$row['variation_id'] = $variation_id;
			}
			$items[] = $row;
		}
		if (!$items) {
			return null;
		}
		$country = $order->get_shipping_country() ?: $order->get_billing_country();
		$state = $order->get_shipping_state() ?: $order->get_billing_state();
		$states = $country ? WC()->countries->get_states($country) : [];
		$first = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
		$last = $order->get_shipping_last_name() ?: $order->get_billing_last_name();
		$created = $order->get_date_created();
		return [
			'event' => $event,
			'order' => [
				'id' => (string) $order->get_id(),
				'number' => (string) $order->get_order_number(),
				'currency' => $order->get_currency(),
				'placed_at' => gmdate('c', $created ? $created->getTimestamp() : time()),
				// First name and last initial only, so sellers do not receive full customer details.
				'customer_name' => trim($first . ' ' . ($last !== '' ? mb_substr($last, 0, 1) . '.' : '')),
				'ship_city' => (string) ($order->get_shipping_city() ?: $order->get_billing_city()),
				'ship_state' => (string) (($states[$state] ?? $state) ?: ''),
				'restock' => true,
				'coupons' => array_values(array_map('strval', $order->get_coupon_codes())),
				// Lets Vendor Hub refund the customer through Paystack. The Paystack plugin stores its transaction reference as the order's transaction id.
				'payment_ref' => (string) $order->get_transaction_id(),
				'payment_method' => (string) $order->get_payment_method(),
				'items' => $items,
			],
		];
	}

	public static function send_order($order_id, $event, $attempt = 1) {
		$order = wc_get_order($order_id);
		if (!$order || !self::configured() || $order->get_meta('_verola_sent_' . $event)) {
			return;
		}
		$payload = self::payload($order, $event);
		if (!$payload) {
			return; // nothing from Vendor Hub sellers in this order
		}
		$s = self::settings();
		$body = wp_json_encode($payload);
		$ts = time();
		$signature = hash_hmac('sha256', $ts . '.' . $body, $s['secret']);
		$res = wp_remote_post($s['url'] . '/api/marketplace/orders', [
			'timeout' => 20,
			'headers' => ['Content-Type' => 'application/json', 'X-Verola-Timestamp' => (string) $ts, 'X-Verola-Signature' => 'sha256=' . $signature],
			'body' => $body,
		]);
		$code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
		if ($code >= 200 && $code < 300) {
			$order->update_meta_data('_verola_sent_' . $event, time());
			$order->add_order_note('Reported to Vendor Hub (' . $event . ').');
			$order->save();
			return;
		}
		$why = is_wp_error($res) ? $res->get_error_message() : 'status ' . $code . ' ' . wp_remote_retrieve_body($res);
		// Bad data will not fix itself; a wrong key, a downed server or a network error will.
		// A status report can arrive before the hub has recorded the sale, so a 404 is worth retrying for those.
		$permanent = in_array($code, strpos($event, 'order.status:') === 0 ? [400, 422] : [400, 404, 422], true);
		if ($permanent || $attempt >= 8) {
			$order->add_order_note('Could not report to Vendor Hub (' . $event . '): ' . $why . '. Not retrying.');
			$order->save();
			return;
		}
		$order->add_order_note('Vendor Hub did not accept the ' . $event . ' report (attempt ' . $attempt . '): ' . $why . '. Trying again later.');
		$order->save();
		wp_schedule_single_event(time() + 300 * $attempt, self::SEND, [(int) $order_id, $event, $attempt + 1]);
	}
}

Verola_Connector::init();
