<?php
/**
 * Plugin Name: DDLX Install App
 * Description: Shows an "Install app" pop-up to visitors who have not installed the app, adds install buttons (shortcode or any link to #install-app), and gives iPhone users step-by-step instructions. Works alongside Super Progressive Web Apps.
 * Version: 1.0.0
 * Author: DDLX
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

const DDLX_INSTALL_VERSION = '1.0.0';

function ddlx_install_defaults(): array
{
    return [
        'popup'      => '1',
        'title'      => 'Get the DDLX app',
        'text'       => 'Shop faster, get order updates and open DDLX straight from your home screen.',
        'button'     => 'Install app',
        'later'      => 'Not now',
        'delay'      => 6,
        'snooze'     => 7,
        'icon'       => '',
        'color'      => '#14233A',
        'accent'     => '#D5E0EB',
        'ios_steps'  => "Tap the Share button (the square with an arrow) at the bottom of Safari.\nScroll down and tap “Add to Home Screen”.\nTap “Add” in the top right corner.",
    ];
}
function ddlx_install_opt(string $k)
{
    $o = get_option('ddlx_install', []);
    $d = ddlx_install_defaults();
    return (is_array($o) && isset($o[$k]) && $o[$k] !== '') ? $o[$k] : $d[$k];
}

add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;
    $u = plugin_dir_url(__FILE__) . 'assets/';
    wp_enqueue_style('ddlx-install', $u . 'install.css', [], DDLX_INSTALL_VERSION);
    wp_enqueue_script('ddlx-install', $u . 'install.js', [], DDLX_INSTALL_VERSION, true);
    wp_localize_script('ddlx-install', 'DDLX_INSTALL', [
        'popup'  => ddlx_install_opt('popup') === '1',
        'title'  => (string) ddlx_install_opt('title'),
        'text'   => (string) ddlx_install_opt('text'),
        'button' => (string) ddlx_install_opt('button'),
        'later'  => (string) ddlx_install_opt('later'),
        'delay'  => (int) ddlx_install_opt('delay'),
        'snooze' => (int) ddlx_install_opt('snooze'),
        'icon'   => (string) (ddlx_install_opt('icon') ?: get_site_icon_url(192)),
        'steps'  => array_values(array_filter(array_map('trim', explode("\n", (string) ddlx_install_opt('ios_steps'))))),
    ]);
    $c = sanitize_hex_color((string) ddlx_install_opt('color')) ?: '#14233A';
    $a = sanitize_hex_color((string) ddlx_install_opt('accent')) ?: '#D5E0EB';
    wp_add_inline_style('ddlx-install', ":root{--ddlx-i-main:$c;--ddlx-i-soft:$a}");
});

// [ddlx_install text="Install app"]  → a button that installs the app (or shows iPhone steps)
add_shortcode('ddlx_install', function ($atts) {
    $a = shortcode_atts(['text' => 'Install app', 'class' => ''], $atts);
    return '<a href="#install-app" class="ddlx-install-btn ' . esc_attr($a['class']) . '" data-ddlx-install>' . esc_html($a['text']) . '</a>';
});

add_action('admin_menu', function () {
    add_options_page('DDLX Install App', 'DDLX Install App', 'manage_options', 'ddlx-install', 'ddlx_install_page');
});
add_action('admin_init', function () {
    register_setting('ddlx_install', 'ddlx_install', ['sanitize_callback' => function ($in) {
        $in = is_array($in) ? $in : [];
        $d = ddlx_install_defaults();
        return [
            'popup'     => !empty($in['popup']) ? '1' : '0',
            'title'     => sanitize_text_field($in['title'] ?? $d['title']),
            'text'      => sanitize_textarea_field($in['text'] ?? $d['text']),
            'button'    => sanitize_text_field($in['button'] ?? $d['button']),
            'later'     => sanitize_text_field($in['later'] ?? $d['later']),
            'delay'     => max(0, min(120, (int) ($in['delay'] ?? 6))),
            'snooze'    => max(1, min(365, (int) ($in['snooze'] ?? 7))),
            'icon'      => esc_url_raw($in['icon'] ?? ''),
            'color'     => sanitize_hex_color($in['color'] ?? '') ?: $d['color'],
            'accent'    => sanitize_hex_color($in['accent'] ?? '') ?: $d['accent'],
            'ios_steps' => sanitize_textarea_field($in['ios_steps'] ?? $d['ios_steps']),
        ];
    }]);
});
function ddlx_install_page(): void
{
    if (!current_user_can('manage_options')) return;
    $f = function (string $k, string $label, string $type = 'text', string $hint = '') {
        $v = ddlx_install_opt($k);
        echo '<tr><th><label for="di_' . esc_attr($k) . '">' . esc_html($label) . '</label></th><td>';
        if ($type === 'area') echo '<textarea id="di_' . esc_attr($k) . '" name="ddlx_install[' . esc_attr($k) . ']" rows="4" class="large-text">' . esc_textarea((string) $v) . '</textarea>';
        else echo '<input id="di_' . esc_attr($k) . '" type="' . esc_attr($type) . '" name="ddlx_install[' . esc_attr($k) . ']" value="' . esc_attr((string) $v) . '" class="regular-text">';
        if ($hint) echo '<p class="description">' . esc_html($hint) . '</p>';
        echo '</td></tr>';
    };
    echo '<div class="wrap"><h1>DDLX Install App</h1>';
    echo '<p>This plugin needs your site to be installable first. Keep <strong>Super Progressive Web Apps</strong> active and check that <em>SuperPWA → Status</em> is all green.</p>';
    echo '<h2>Install buttons</h2><ul style="list-style:disc;margin-left:22px"><li>Shortcode: <code>[ddlx_install text="Install app"]</code></li><li>Any button or menu link: set its link to <code>#install-app</code>. In Elementor, set the button link to <code>#install-app</code>, or give the button the CSS class <code>ddlx-install</code>.</li></ul>';
    echo '<form method="post" action="options.php">'; settings_fields('ddlx_install'); echo '<table class="form-table">';
    echo '<tr><th>Pop-up</th><td><label><input type="checkbox" name="ddlx_install[popup]" value="1" ' . checked(ddlx_install_opt('popup'), '1', false) . '> Show the install pop-up to visitors who have not installed the app</label></td></tr>';
    $f('title', 'Pop-up title'); $f('text', 'Pop-up message', 'area'); $f('button', 'Install button text'); $f('later', 'Dismiss button text');
    $f('delay', 'Show after (seconds)', 'number'); $f('snooze', 'After “Not now”, wait (days)', 'number');
    $f('icon', 'App icon address (URL)', 'url', 'Leave blank to use the site icon.');
    $f('color', 'Main colour', 'text', 'Hex, for example #14233A'); $f('accent', 'Soft colour', 'text', 'Hex, for example #D5E0EB');
    $f('ios_steps', 'iPhone steps (one per line)', 'area');
    echo '</table>'; submit_button(); echo '</form></div>';
}
