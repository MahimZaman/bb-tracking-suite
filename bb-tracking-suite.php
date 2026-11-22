<?php

/**
 * Plugin Name: BioBalkan Tracking Core
 * Plugin URI: https://condixia.com
 * Author: Mahim Zaman (Condixia)
 * Author URI: https://mahimzaman.com
 * Description: Clean Meta Pixel + CAPI + GA4 tracking (multi-domain, consent-ready, WooCommerce events).
 * Version: 1.0.0
 * Text Domain: biobalkan-tracking
 */

if (!defined('ABSPATH')) exit;

class BB_Tracking_Core
{

    private $opts;

    public function __construct()
    {
        $this->opts = get_option('bb_tracking_settings');

        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('wp_footer', [$this, 'script']);

        // Woo events
        add_action('woocommerce_thankyou', [$this, 'purchase']);

        // CAPI
        add_action('rest_api_init', function () {
            register_rest_route('bb/v1', '/capi', [
                'methods' => 'POST',
                'callback' => [$this, 'capi'],
                'permission_callback' => '__return_true'
            ]);
        });
    }

    public function menu()
    {
        add_menu_page('Tracking Core', 'Tracking Core', 'manage_options', 'bb-track', [$this, 'page'], 'dashicons-chart-area');
    }

    public function settings()
    {
        register_setting('bb_group', 'bb_tracking_settings');
    }

    public function page()
    {
        $o = get_option('bb_tracking_settings'); ?>
        <div class="wrap">
            <h1>Tracking Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('bb_group'); ?>
                <table class="form-table">
                    <tr>
                        <th>Meta Pixel ID</th>
                        <td><input name="bb_tracking_settings[pixel]" value="<?php echo esc_attr($o['pixel'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th>Meta Access Token</th>
                        <td><input name="bb_tracking_settings[token]" value="<?php echo esc_attr($o['token'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th>GA4 ID</th>
                        <td><input name="bb_tracking_settings[ga4]" value="<?php echo esc_attr($o['ga4'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th>Debug</th>
                        <td><input type="checkbox" name="bb_tracking_settings[debug]" value="1" <?php checked(1, $o['debug'] ?? 0); ?>></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Debug Monitor</h2>
            <p>Open browser console to verify events.</p>
            <p>CAPI Endpoint: <code>/wp-json/bb/v1/capi</code></p>
        </div>
    <?php
    }

    // =====================
    // PURCHASE (DEDUP SAFE)
    // =====================

    public function purchase($order_id)
    {
        if (get_post_meta($order_id, '_bb_done', true)) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        update_post_meta($order_id, '_bb_done', 1);

        $total = $order->get_total();
        $currency = $order->get_currency(); ?>

        <script>
            (function() {
                var id = '<?php echo esc_js($order_id); ?>';
                if (sessionStorage.getItem('bb_' + id)) return;
                sessionStorage.setItem('bb_' + id, 1);

                if (window.bbTrack) {
                    bbTrack('Purchase', {
                        value: <?php echo esc_js($total); ?>,
                        currency: '<?php echo esc_js($currency); ?>'
                    });
                }
            })();
        </script>

    <?php
    }

    // =====================
    // CAPI
    // =====================

    public function capi($req)
    {
        $o = get_option('bb_tracking_settings');
        if (empty($o['pixel']) || empty($o['token'])) return ['err'];

        $body = $req->get_json_params();

        wp_remote_post("https://graph.facebook.com/v18.0/{$o['pixel']}/events?access_token={$o['token']}", [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => [$body]])
        ]);

        return ['ok'];
    }

    // =====================
    // SCRIPT
    // =====================

    public function script()
    {
        $o = $this->opts;
        $debug = !empty($o['debug']) ? 'true' : 'false'; ?>

        <script>
            (function() {

                var CFG = {
                    pixel: '<?php echo esc_js($o['pixel'] ?? ''); ?>',
                    ga4: '<?php echo esc_js($o['ga4'] ?? ''); ?>',
                    debug: <?php echo $debug; ?>
                };

                function log() {
                    if (CFG.debug) console.log('[BB]', ...arguments);
                }

                function id() {
                    return 'e' + Date.now() + Math.random().toString(36).slice(2);
                }

                function capi(name, data, eid) {
                    fetch('/wp-json/bb/v1/capi', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            event_name: name,
                            event_id: eid,
                            custom_data: data,
                            action_source: 'website'
                        })
                    });
                }

                function loadPixel() {
                    if (!CFG.pixel) return;
                    !(function(f, b, e, v, n, t, s) {
                        if (f.fbq) return;
                        n = f.fbq = function() {
                            n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments)
                        };
                        if (!f._fbq) f._fbq = n;
                        n.push = n;
                        n.loaded = !0;
                        n.version = '2.0';
                        n.queue = [];
                        t = b.createElement(e);
                        t.async = !0;
                        t.src = v;
                        s = b.getElementsByTagName(e)[0];
                        s.parentNode.insertBefore(t, s)
                    })(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
                    fbq('init', CFG.pixel);
                    fbq('track', 'PageView');
                }

                function loadGA() {
                    if (!CFG.ga4) return;
                    var s = document.createElement('script');
                    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + CFG.ga4;
                    s.async = true;
                    document.head.appendChild(s);
                    window.dataLayer = window.dataLayer || [];

                    function gtag() {
                        dataLayer.push(arguments);
                    }
                    window.gtag = gtag;
                    gtag('consent', 'default', {
                        ad_storage: 'denied',
                        analytics_storage: 'denied'
                    });
                    gtag('js', new Date());
                    gtag('config', CFG.ga4);
                }

                window.bbTrack = function(name, data = {}) {
                    var eid = id();
                    log(name, data, eid);

                    if (window.fbq) fbq('track', name, data, {
                        eventID: eid
                    });
                    if (window.gtag) gtag('event', name.toLowerCase(), data);

                    capi(name, data, eid);
                };

                // ViewContent
                window.addEventListener('DOMContentLoaded', function() {
                    bbTrack('ViewContent', {
                        content_name: document.title
                    });
                });

                // Lead
                window.addEventListener('submit', function(e) {
                    if (e.target.matches('.track-lead')) {
                        setTimeout(() => bbTrack('Lead', {}), 300);
                    }
                });

                // Consent (Borlabs)
                window.addEventListener('borlabsCookieOptIn', function(e) {
                    if (e.detail && e.detail.includes('marketing')) {
                        loadPixel();
                        loadGA();
                        if (window.gtag) {
                            gtag('consent', 'update', {
                                ad_storage: 'granted',
                                analytics_storage: 'granted'
                            });
                        }
                    }
                });

            })();
        </script>

<?php
    }
}

new BB_Tracking_Core();
