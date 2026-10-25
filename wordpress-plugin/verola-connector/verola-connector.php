<?php
/**
 * Plugin Name: Vendor Hub Connector
 * Description: Connects WooCommerce and Dokan to Vendor Hub (vendor.ddlx.shop). Pulls products approved in Vendor Hub into this store under each seller, keeps stock and prices in step, and reports every sale and refund back to Vendor Hub.
 * Version: 1.0.0
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
		add_action('rest_api_init', [__CLASS__, 'register_rest']);
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
			array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=verola-connector')) . '">Settings</a>');
			return $links;
		});
		// A sale is reported once it is paid; a refund is reported when the order is marked refunded.
		add_action('woocommerce_order_status_processing', [__CLASS__, 'order_paid']);
		add_action('woocommerce_order_status_completed', [__CLASS__, 'order_paid']);
		add_action('woocommerce_order_status_refunded', [__CLASS__, 'order_refunded']);
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
			<h2>Give these to Vendor Hub</h2>
			<p>Nothing to enter on the Vendor Hub side except the two secrets in its <code>.env</code> file (<code>MARKETPLACE_API_KEY</code> and <code>MARKETPLACE_WEBHOOK_SECRET</code>), which must match the values above. Sales are sent from this store to <code><?php echo esc_html($s['url'] !== '' ? $s['url'] : 'your-vendor-hub-address'); ?>/api/marketplace/orders</code>.</p>
		</div>
		<?php
	}

	// ------------------------------------------------------------------ SKUs (Vendor Hub asks this store)

	public static function register_rest() {
		register_rest_route('vendor-hub/v1', '/sku/next', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_sku_next'], 'permission_callback' => [__CLASS__, 'rest_auth']]);
		register_rest_route('vendor-hub/v1', '/sku/check', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_sku_check'], 'permission_callback' => [__CLASS__, 'rest_auth']]);
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
			self::set_state(['last_sync' => time(), 'last_count' => $count]);
		} catch (Throwable $e) {
			self::set_state(['last_sync' => time(), 'last_count' => $count, 'last_error' => $e->getMessage()]);
		}
		delete_transient('verola_sync_lock');
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
		if ($existing) {
			$uid = (int) $existing[0];
			$update = ['ID' => $uid, 'display_name' => $name];
			if (!empty($seller['store_slug'])) {
				$update['user_nicename'] = sanitize_title($seller['store_slug']);
			}
			wp_update_user($update);
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
		$product->set_category_ids(self::term_ids([$item['category'] ?? ''], 'product_cat'));
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
			$product->set_manage_stock(false);
		} else {
			self::set_prices($product, $item);
			$product->set_manage_stock(!empty($item['manage_stock']));
			if (!empty($item['manage_stock'])) {
				$product->set_stock_quantity((int) $item['stock']);
			} else {
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
		update_post_meta($id, '_verola_updated_at', $item['updated_at']);
		wp_update_post(['ID' => $id, 'post_author' => self::vendor_user($seller)]);
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
			$variation->set_manage_stock(true);
			$variation->set_stock_quantity((int) $v['stock']);
			$variation->set_backorders(!empty($item['allow_backorders']) ? 'yes' : 'no');
			$variation->set_menu_order($position);
			$variation->set_status('publish');
			$img = self::image($v['image'] ?? null);
			if ($img) {
				$variation->set_image_id($img);
			}
			$saved = $variation->save();
			update_post_meta($saved, '_verola_variation_id', $v['id']);
			$keep[] = (int) $saved;
		}
		foreach ($parent->get_children() as $child) {
			if (!in_array((int) $child, $keep, true)) {
				wp_delete_post($child, true);
			}
		}
		WC_Product_Variable::sync($parent_id);
	}

	// ------------------------------------------------------------------ sales (store -> Vendor Hub)

	public static function order_paid($order_id) {
		self::queue($order_id, 'order.paid');
	}

	public static function order_refunded($order_id) {
		self::queue($order_id, 'order.refunded');
	}

	private static function queue($order_id, $event) {
		$order = wc_get_order($order_id);
		// Dokan splits a multi-seller order into one sub-order per seller. The parent order already holds every
		// item, so only parent orders are reported; reporting the sub-orders as well would pay sellers twice.
		if (!$order || $order->get_parent_id() || $order->get_meta('_verola_sent_' . $event)) {
			return;
		}
		// Send in the background so checkout is never slowed down or broken by Vendor Hub being offline.
		wp_schedule_single_event(time() + 5, self::SEND, [(int) $order_id, $event, 1]);
	}

	private static function payload($order, $event) {
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
			$row = ['product_id' => $verola_id, 'quantity' => $qty, 'unit_price' => round(((float) $line->get_total()) / $qty, 2)];
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
		$permanent = in_array($code, [400, 404, 422], true);
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
