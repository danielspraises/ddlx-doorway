<?php
/**
 * Plugin Name: DDLX Email Verification
 * Description: Verifies the email of customers who sign up with a password. Social-login (Google) users are never touched, existing customers are never touched, and nobody is logged out after paying.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: DDLX
 */
if (!defined('ABSPATH')) {
	exit;
}

final class DDLX_Email_Verification {
	const PENDING = '_ddlx_ev_pending'; // "1" while an account is unverified
	const TOKEN = '_ddlx_ev_token';     // "sha256(token)|expires"

	public static function init() {
		add_action('woocommerce_created_customer', [__CLASS__, 'created'], 10, 1);
		add_filter('woocommerce_registration_auth_new_customer', [__CLASS__, 'no_auto_login'], 10, 2);
		add_filter('authenticate', [__CLASS__, 'block_login'], 40, 3);
		add_action('template_redirect', [__CLASS__, 'handle_links']);
		add_action('woocommerce_before_account_navigation', [__CLASS__, 'banner']);
		add_action('woocommerce_before_checkout_form', [__CLASS__, 'banner'], 5);
		// Admin
		add_filter('manage_users_columns', function ($c) { $c['ddlx_ev'] = 'Email'; return $c; });
		add_filter('manage_users_custom_column', function ($v, $col, $uid) {
			return $col === 'ddlx_ev' ? (self::pending($uid) ? '<span style="color:#b45309;font-weight:600">Not verified</span>' : 'Verified') : $v;
		}, 10, 3);
		add_filter('user_row_actions', [__CLASS__, 'row_actions'], 10, 2);
		add_action('admin_post_ddlx_ev_mark', [__CLASS__, 'admin_mark']);
		add_action('admin_menu', function () {
			add_options_page('Email Verification', 'Email Verification', 'manage_options', 'ddlx-email-verification', [__CLASS__, 'settings_page']);
		});
		add_action('admin_init', function () {
			register_setting('ddlx_ev', 'ddlx_ev_block_login', ['sanitize_callback' => function ($v) { return $v ? '1' : '0'; }, 'default' => '1']);
			register_setting('ddlx_ev', 'ddlx_ev_hours', ['sanitize_callback' => function ($v) { return max(1, min(720, (int) $v)); }, 'default' => 72]);
		});
	}

	private static function pending($uid) {
		return get_user_meta($uid, self::PENDING, true) === '1';
	}

	private static function sig($uid) {
		return hash_hmac('sha256', 'ddlx-ev-resend|' . (int) $uid, wp_salt('auth'));
	}

	private static function resend_url($uid) {
		return add_query_arg(['ddlx_resend' => (int) $uid, 'sig' => self::sig($uid)], home_url('/'));
	}

	// Only password sign-ups made through the My Account form or at checkout are checked. Social-login plugins
	// create users without those form nonces, so they are left alone, and so is every existing account.
	public static function created($uid) {
		$user = get_userdata($uid);
		if (!$user || array_intersect(['administrator', 'shop_manager'], (array) $user->roles)) {
			return;
		}
		$form = !empty($_POST['woocommerce-register-nonce']) || !empty($_POST['woocommerce-process-checkout-nonce']); // phpcs:ignore WordPress.Security.NonceVerification
		if (!$form) {
			return;
		}
		update_user_meta($uid, self::PENDING, '1');
		self::send($uid);
	}

	// My Account sign-up: do not log in until verified. Checkout sign-up: WooCommerce logs the customer in itself, and that stays.
	public static function no_auto_login($auth, $uid) {
		if (self::pending($uid)) {
			if (function_exists('wc_add_notice')) {
				wc_add_notice('Account created. We sent a verification link to your email. Open it to activate your account.', 'success');
			}
			return false;
		}
		return $auth;
	}

	public static function block_login($user, $login, $password) {
		if (!($user instanceof WP_User) || get_option('ddlx_ev_block_login', '1') !== '1' || !self::pending($user->ID)) {
			return $user;
		}
		return new WP_Error('ddlx_unverified', 'Please verify your email first. Open the link we sent you, or <a href="' . esc_url(self::resend_url($user->ID)) . '">send a new link</a>.');
	}

	public static function send($uid) {
		$user = get_userdata($uid);
		if (!$user) {
			return false;
		}
		$token = bin2hex(random_bytes(20));
		$hours = max(1, (int) get_option('ddlx_ev_hours', 72));
		update_user_meta($uid, self::TOKEN, hash('sha256', $token) . '|' . (time() + $hours * 3600));
		$link = add_query_arg(['ddlx_verify' => (int) $uid, 'k' => $token], home_url('/'));
		$site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
		$name = $user->first_name ?: $user->display_name;
		$body = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;color:#0f172a">'
			. '<h2 style="margin:0 0 12px">Verify your email</h2>'
			. '<p>Hi ' . esc_html($name) . ', confirm your email address to activate your ' . esc_html($site) . ' account.</p>'
			. '<p style="margin:24px 0"><a href="' . esc_url($link) . '" style="background:#1b88ca;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:700">Verify my email</a></p>'
			. '<p style="font-size:13px;color:#64748b">This link works for ' . (int) $hours . ' hours. If you did not create an account, ignore this email.</p></div>';
		$html = static function () { return 'text/html'; };
		add_filter('wp_mail_content_type', $html);
		$ok = wp_mail($user->user_email, 'Verify your email for ' . $site, $body);
		remove_filter('wp_mail_content_type', $html);
		return $ok;
	}

	public static function handle_links() {
		if (isset($_GET['ddlx_verify'])) { // phpcs:ignore WordPress.Security.NonceVerification
			self::verify((int) $_GET['ddlx_verify'], (string) ($_GET['k'] ?? ''));
		}
		if (isset($_GET['ddlx_resend'])) { // phpcs:ignore WordPress.Security.NonceVerification
			self::resend((int) $_GET['ddlx_resend'], (string) ($_GET['sig'] ?? ''));
		}
	}

	private static function done($msg, $kind) {
		if (function_exists('wc_add_notice')) {
			wc_add_notice($msg, $kind);
		}
		wp_safe_redirect(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/'));
		exit;
	}

	private static function verify($uid, $token) {
		if (!$uid || !get_userdata($uid)) {
			self::done('That verification link is not valid.', 'error');
		}
		if (!self::pending($uid)) {
			self::done('Your email is already verified.', 'notice');
		}
		$rec = explode('|', (string) get_user_meta($uid, self::TOKEN, true));
		if (count($rec) !== 2 || !hash_equals($rec[0], hash('sha256', $token))) {
			self::done('That verification link is not valid. Log in to get a new one.', 'error');
		}
		if (time() > (int) $rec[1]) {
			self::done('That verification link has expired. Log in to get a new one.', 'error');
		}
		delete_user_meta($uid, self::PENDING);
		delete_user_meta($uid, self::TOKEN);
		if (!is_user_logged_in()) {
			wp_set_current_user($uid);
			wp_set_auth_cookie($uid, true);
		}
		self::done('Email verified. Welcome!', 'success');
	}

	private static function resend($uid, $sig) {
		if (!$uid || !hash_equals(self::sig($uid), $sig) || !self::pending($uid)) {
			self::done('That link is not valid.', 'error');
		}
		$key = 'ddlx_ev_rl_' . $uid;
		if (get_transient($key)) {
			self::done('A link was just sent. Please wait two minutes before asking for another.', 'notice');
		}
		set_transient($key, 1, 120);
		self::send($uid);
		self::done('A new verification link is on its way. Check your inbox and spam folder.', 'success');
	}

	public static function banner() {
		static $shown = false;
		if ($shown || !is_user_logged_in() || !self::pending(get_current_user_id()) || !function_exists('wc_print_notice')) {
			return;
		}
		$shown = true;
		wc_print_notice('Please verify your email. We sent you a link. <a href="' . esc_url(self::resend_url(get_current_user_id())) . '">Send it again</a>', 'notice');
	}

	public static function row_actions($actions, $user) {
		if (self::pending($user->ID) && current_user_can('edit_users')) {
			$url = wp_nonce_url(admin_url('admin-post.php?action=ddlx_ev_mark&user=' . (int) $user->ID), 'ddlx_ev_mark_' . $user->ID);
			$actions['ddlx_ev'] = '<a href="' . esc_url($url) . '">Mark email verified</a>';
		}
		return $actions;
	}

	public static function admin_mark() {
		$uid = (int) ($_GET['user'] ?? 0);
		if (!current_user_can('edit_users')) {
			wp_die('Not allowed.');
		}
		check_admin_referer('ddlx_ev_mark_' . $uid);
		delete_user_meta($uid, self::PENDING);
		delete_user_meta($uid, self::TOKEN);
		wp_safe_redirect(admin_url('users.php'));
		exit;
	}

	public static function settings_page() {
		if (!current_user_can('manage_options')) {
			return;
		}
		echo '<div class="wrap"><h1>Email Verification</h1><form method="post" action="options.php">';
		settings_fields('ddlx_ev');
		echo '<table class="form-table"><tr><th>Require verification to log in</th><td><label><input type="checkbox" name="ddlx_ev_block_login" value="1" ' . checked(get_option('ddlx_ev_block_login', '1'), '1', false) . '> Unverified password sign-ups cannot log in later until they open the email link</label>'
			. '<p class="description">Customers who create an account at checkout stay logged in after paying. Google and other social-login users are never asked to verify.</p></td></tr>'
			. '<tr><th>Link lifetime (hours)</th><td><input type="number" min="1" max="720" name="ddlx_ev_hours" value="' . esc_attr((int) get_option('ddlx_ev_hours', 72)) . '"></td></tr></table>';
		submit_button();
		echo '</form><p>Emails need a working mail setup. Use an SMTP plugin (for example FluentSMTP, free) so verification emails reach inboxes.</p></div>';
	}
}
DDLX_Email_Verification::init();
