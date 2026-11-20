<?php
/**
 * Plugin Name: GenZ Customer Hub
 * Description: On-site order tracking (Cainiao), tracking update emails, email-link login, newsletter subscribers and a customer inbox for GenZ Gadgets Store.
 * Version: 1.5.0
 * Author: GenZ Gadgets Store
 * Requires Plugins: woocommerce
 * Text Domain: genz-hub
 */
if (!defined('ABSPATH')) exit;

final class GenZ_Hub {
	const V = '1.5.0';
	const NS = 'genz-hub/v1';

	/* ------------------------------------------------------------------ boot */
	static function init() {
		register_activation_hook(__FILE__, [__CLASS__, 'activate']);
		register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
		add_action('plugins_loaded', [__CLASS__, 'maybe_upgrade']);
		add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
		add_action('init', [__CLASS__, 'on_init']);
		add_action('template_redirect', [__CLASS__, 'handle_links'], 1);
		add_action('template_redirect', [__CLASS__, 'preview_page'], 0);
		add_action('rest_api_init', [__CLASS__, 'routes']);
		// Smart product search.
		add_action('save_post_product', function () { delete_transient('genz_search_index'); });
		add_action('woocommerce_update_product', function () { delete_transient('genz_search_index'); });
		add_action('pre_get_posts', [__CLASS__, 'search_query']);
		// Installable dashboard (desktop + phone).
		add_action('admin_head', [__CLASS__, 'admin_manifest_tags']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
		add_action('wp_footer', [__CLASS__, 'chat_widget']);
		add_shortcode('genz_track_order', [__CLASS__, 'sc_track']);
		add_shortcode('genz_subscribe', [__CLASS__, 'sc_subscribe']);
		add_shortcode('genz_home', [__CLASS__, 'sc_home']);
		add_filter('render_block', [__CLASS__, 'cat_banner_block'], 10, 2);
		add_filter('wpseo_title', [__CLASS__, 'cat_seo_title'], 20);
		add_filter('wpseo_metadesc', [__CLASS__, 'cat_seo_desc'], 20);
		add_filter('wpseo_opengraph_title', [__CLASS__, 'cat_seo_title'], 20);
		add_filter('wpseo_opengraph_desc', [__CLASS__, 'cat_seo_desc'], 20);
		add_action('woocommerce_archive_description', [__CLASS__, 'cat_banner_classic'], 5);
		add_action('wp_head', [__CLASS__, 'skin_head'], 9999);
		add_action('admin_post_genz_hub_skin', [__CLASS__, 'admin_skin']);
		add_action('save_post_product', [__CLASS__, 'clear_home_cache']);
		add_action('woocommerce_update_product', [__CLASS__, 'clear_home_cache']);
		add_filter('render_block', [__CLASS__, 'footer_band'], 10, 2);

		// Checkout: phone required (classic + blocks) and newsletter opt-in.
		add_filter('woocommerce_billing_fields', function ($f) { if (isset($f['billing_phone'])) $f['billing_phone']['required'] = true; return $f; }, 20);
		add_action('woocommerce_init', [__CLASS__, 'checkout_fields']);
		add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'checkout_optin']);
		add_action('woocommerce_checkout_order_processed', [__CLASS__, 'checkout_optin_classic'], 10, 3);

		// Tracking.
		add_action('add_meta_boxes', [__CLASS__, 'metabox']);
		add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'save_metabox'], 50);
		add_action('genz_hub_track_cron', [__CLASS__, 'track_cron']);
		add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'order_view_tracking'], 5);
		add_filter('woocommerce_my_account_my_orders_actions', [__CLASS__, 'orders_actions'], 10, 2);

		// Email login.
		add_action('woocommerce_before_customer_login_form', [__CLASS__, 'magic_form']);

		// My account messages.
		add_filter('woocommerce_account_menu_items', [__CLASS__, 'account_menu']);
		add_action('woocommerce_account_messages_endpoint', [__CLASS__, 'account_messages']);
		add_filter('woocommerce_get_query_vars', function ($v) { $v['messages'] = 'messages'; return $v; });

		// Subscribers broadcast.
		add_action('genz_hub_send_batch', [__CLASS__, 'send_batch']);

		// Admin.
		add_action('admin_menu', [__CLASS__, 'admin_menu']);
		add_action('admin_post_genz_hub_reply', [__CLASS__, 'admin_reply']);
		add_action('admin_post_genz_hub_thread_status', [__CLASS__, 'admin_thread_status']);
		add_action('admin_post_genz_hub_export', [__CLASS__, 'admin_export']);
		add_action('admin_post_genz_hub_broadcast', [__CLASS__, 'admin_broadcast']);
		add_action('admin_post_genz_hub_sub_delete', [__CLASS__, 'admin_sub_delete']);
		add_action('admin_post_genz_hub_quick_save', [__CLASS__, 'admin_quick_save']);
		add_action('admin_post_genz_hub_preview', [__CLASS__, 'admin_preview']);
		add_action('admin_post_genz_hub_smtp_save', [__CLASS__, 'admin_smtp_save']);
		add_action('admin_post_genz_hub_smtp_test', [__CLASS__, 'admin_smtp_test']);
		add_action('admin_post_genz_hub_quick_track', [__CLASS__, 'admin_quick_track']);
		add_action('admin_post_genz_hub_check_now', [__CLASS__, 'admin_check_now']);
		// Email delivery (SMTP) + log.
		add_action('phpmailer_init', [__CLASS__, 'phpmailer'], 99);
		add_filter('wp_mail_from', [__CLASS__, 'mail_from'], 99);
		add_filter('wp_mail_from_name', [__CLASS__, 'mail_from_name'], 99);
		add_filter('woocommerce_email_from_address', [__CLASS__, 'mail_from'], 99);
		add_filter('woocommerce_email_from_name', [__CLASS__, 'mail_from_name'], 99);
		add_filter('wp_mail', [__CLASS__, 'mail_reply_to'], 99);
		add_action('wp_mail_succeeded', function ($d) { GenZ_Hub::log_mail($d['to'] ?? [], $d['subject'] ?? '', 'sent', ''); });
		add_action('wp_mail_failed', function ($e) { $d = $e->get_error_data(); GenZ_Hub::log_mail($d['to'] ?? [], $d['subject'] ?? '', 'failed', $e->get_error_message()); });
		add_action('admin_head', [__CLASS__, 'admin_css']);
	}

	/* ------------------------------------------------------------ install */
	static function activate() {
		self::install();
		self::on_init();
		flush_rewrite_rules();
	}
	static function deactivate() {
		wp_clear_scheduled_hook('genz_hub_track_cron');
		flush_rewrite_rules();
	}
	static function maybe_upgrade() {
		if (get_option('genz_hub_v') !== self::V) self::install();
	}
	static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta("CREATE TABLE {$wpdb->prefix}genz_subscribers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			name varchar(120) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'subscribed',
			source varchar(40) NOT NULL DEFAULT '',
			token varchar(40) NOT NULL DEFAULT '',
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email)
		) $c;");
		dbDelta("CREATE TABLE {$wpdb->prefix}genz_threads (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token varchar(40) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(120) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			order_ref varchar(40) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'open',
			unread_admin int(11) NOT NULL DEFAULT 0,
			unread_customer int(11) NOT NULL DEFAULT 0,
			last_preview varchar(200) NOT NULL DEFAULT '',
			last_at datetime NOT NULL,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY token (token),
			KEY user_id (user_id),
			KEY last_at (last_at)
		) $c;");
		dbDelta("CREATE TABLE {$wpdb->prefix}genz_messages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			thread_id bigint(20) unsigned NOT NULL,
			sender varchar(20) NOT NULL,
			body text NOT NULL,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY thread_id (thread_id)
		) $c;");
		update_option('woocommerce_checkout_phone_field', 'required');
		self::ensure_email_logo();
		if (!wp_next_scheduled('genz_hub_track_cron')) wp_schedule_event(time() + 300, 'genz_3h', 'genz_hub_track_cron');
		update_option('genz_hub_v', self::V);
	}
	static function logo_url() {
		$id = (int) get_option('site_logo') ?: (int) get_theme_mod('custom_logo');
		$u = $id ? wp_get_attachment_image_url($id, 'medium') : '';
		if (!$u) {
			$a = get_posts(['post_type' => 'attachment', 'name' => 'genz-gadgets-logo', 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'inherit']);
			if ($a) $u = wp_get_attachment_url($a[0]);
		}
		return $u ?: get_site_icon_url(256);
	}
	static function ensure_email_logo() {
		if (!get_option('woocommerce_email_header_image') && ($u = self::logo_url())) update_option('woocommerce_email_header_image', $u);
	}
	static function cron_schedules($s) {
		$s['genz_3h'] = ['interval' => 3 * HOUR_IN_SECONDS, 'display' => 'Every 3 hours (GenZ)'];
		return $s;
	}
	static function on_init() {
		add_rewrite_endpoint('messages', EP_ROOT | EP_PAGES);
		if (get_option('woocommerce_checkout_phone_field') !== 'required') update_option('woocommerce_checkout_phone_field', 'required');
		if (!wp_next_scheduled('genz_hub_track_cron')) wp_schedule_event(time() + 300, 'genz_3h', 'genz_hub_track_cron');
	}

	/* ------------------------------------------------------------ helpers */
	static function now() { return current_time('mysql', true); }
	static function ip() { return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : ''; }
	static function limit($key, $max, $window) {
		$k = 'genz_rl_' . md5($key);
		$n = (int) get_transient($k);
		if ($n >= $max) return false;
		set_transient($k, $n + 1, $window);
		return true;
	}
	static function track_url() {
		$id = (int) get_option('genz_hub_track_page');
		if (!$id) {
			$p = get_posts(['post_type' => 'page', 'name' => 'track-order', 'numberposts' => 1, 'fields' => 'ids']);
			if ($p) { $id = (int) $p[0]; update_option('genz_hub_track_page', $id); }
		}
		return $id ? get_permalink($id) : home_url('/');
	}
	static function mail($to, $subject, $heading, $html) {
		if (!function_exists('WC')) return wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
		$m = WC()->mailer();
		$body = $m->wrap_message($heading, $html);
		$e = new WC_Email();
		$body = apply_filters('woocommerce_mail_content', $e->style_inline($body));
		return $m->send($to, $subject, $body);
	}
	static function btn($url, $label) {
		return '<p style="margin:22px 0"><a href="' . esc_url($url) . '" style="background:#111;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block">' . esc_html($label) . '</a></p>';
	}

	/* ------------------------------------------------------------ Cainiao */
	static function clean_no($no) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $no)); }

	static function cainiao($no, $force = false) {
		$no = self::clean_no($no);
		if ($no === '' || strlen($no) < 6 || strlen($no) > 40) return ['ok' => false, 'error' => 'invalid'];
		$ck = 'genz_cn_' . md5($no);
		if (!$force) { $c = get_transient($ck); if (is_array($c)) return $c; }
		$url = 'https://global.cainiao.com/global/detail.json?mailNos=' . rawurlencode($no) . '&lang=en-US&language=en-US';
		$r = wp_remote_get($url, [
			'timeout' => 15,
			'headers' => [
				'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
				'Accept'          => 'application/json, text/plain, */*',
				'Accept-Language' => 'en-GB,en;q=0.9',
				'Referer'         => 'https://global.cainiao.com/newDetail.htm?mailNoList=' . rawurlencode($no),
			],
		]);
		if (is_wp_error($r)) return ['ok' => false, 'error' => $r->get_error_message()];
		$j = json_decode(wp_remote_retrieve_body($r), true);
		if (!is_array($j) || empty($j['module'][0]) || !is_array($j['module'][0])) {
			return ['ok' => false, 'error' => 'bad_response', 'code' => wp_remote_retrieve_response_code($r)];
		}
		$m = $j['module'][0];
		$ev = [];
		foreach ((array) ($m['detailList'] ?? []) as $d) {
			if (!is_array($d)) continue;
			$desc = '';
			foreach (['standerdDesc', 'standardDesc', 'desc', 'actionDesc'] as $k) { if (!empty($d[$k]) && is_string($d[$k])) { $desc = $d[$k]; break; } }
			if ($desc === '') continue;
			$t = 0;
			if (isset($d['time']) && is_numeric($d['time'])) $t = (int) floor($d['time'] / 1000);
			elseif (!empty($d['timeStr'])) $t = (int) strtotime($d['timeStr']);
			$node = '';
			if (!empty($d['group']['nodeDesc'])) $node = $d['group']['nodeDesc'];
			$desc = trim(preg_replace('/^\s*\[[^\]]{1,60}\]\s*/u', '', $desc));
			if (stripos($node, 'store processing') !== false) $node = '';
			$ev[] = [
				't'    => $t,
				'ts'   => $t ? wp_date('D j M Y, H:i', $t) : (isset($d['timeStr']) ? sanitize_text_field($d['timeStr']) : ''),
				'd'    => sanitize_text_field(wp_strip_all_tags($desc)),
				'node' => sanitize_text_field((string) $node),
				'code' => sanitize_text_field((string) ($d['actionCode'] ?? '')),
			];
		}
		usort($ev, function ($a, $b) { return $b['t'] <=> $a['t']; });
		$status = '';
		foreach (['statusDesc', 'status'] as $k) { if (!empty($m[$k]) && is_string($m[$k])) { $status = $m[$k]; break; } }
		$out = [
			'ok'      => true,
			'no'      => $no,
			'status'  => sanitize_text_field($status),
			'origin'  => sanitize_text_field((string) ($m['originCountry'] ?? '')),
			'dest'    => sanitize_text_field((string) ($m['destCountry'] ?? '')),
			'events'  => $ev,
			'fetched' => time(),
		];
		set_transient($ck, $out, 30 * MINUTE_IN_SECONDS);
		return $out;
	}

	/** 0 placed, 1 processing, 2 shipped, 3 in transit, 4 out for delivery, 5 delivered */
	static function stage($order, $data) {
		$s = 0;
		if ($order) {
			$st = $order->get_status();
			if (in_array($st, ['processing', 'on-hold', 'completed'], true)) $s = 1;
		}
		if ($data && !empty($data['ok'])) {
			$latest = !empty($data['events'][0]) ? strtolower($data['events'][0]['d'] . ' ' . $data['events'][0]['code']) : '';
			$txt = $latest;
			if (!empty($data['events'])) $s = max($s, 2);
			$st = strtolower($data['status'] ?? '');
			if ($st === 'delivered' || $st === 'signed' || (preg_match('/delivered|signed for|_sign|collected by|picked up by recipient/', $latest) && !preg_match('/out for deliver|attempt|fail|undeliver|not delivered/', $latest))) return 5;
			if (preg_match('/out for deliver|with (the )?courier for delivery|on vehicle|_do_depart|delivery in progress/', $latest)) return 4;
			if (count($data['events'] ?? []) > 1 || preg_match('/transit|depart|arriv|flight|airline|customs|hub|sort|export|import|line ?haul|destination/', $txt)) $s = max($s, 3);
		}
		return $s;
	}

	static function order_tracking($order) {
		$t = $order->get_meta('_genz_tracking');
		return is_array($t) ? $t : [];
	}

	/* -------------------------------------------------------- render track */
	static function render_tracking_html($order, $numbers, $show_order = true) {
		$labels = ['Order placed', 'Processing', 'Shipped', 'In transit', 'Out for delivery', 'Delivered'];
		ob_start();
		echo '<div class="genz-trk">';
		if ($order && $show_order) {
			echo '<div class="genz-trk-head"><div><span class="genz-muted">Order</span><strong>#' . esc_html($order->get_order_number()) . '</strong></div>';
			echo '<div><span class="genz-muted">Placed</span><strong>' . esc_html(wc_format_datetime($order->get_date_created())) . '</strong></div>';
			echo '<div><span class="genz-muted">Status</span><strong>' . esc_html(wc_get_order_status_name($order->get_status())) . '</strong></div></div>';
			echo '<ul class="genz-trk-items">';
			foreach ($order->get_items() as $it) {
				$p = $it->get_product();
				$img = $p ? $p->get_image([56, 56]) : '';
				echo '<li>' . $img . '<span>' . esc_html($it->get_name()) . ' &times; ' . (int) $it->get_quantity() . '</span></li>';
			}
			echo '</ul>';
		}
		if (!$numbers) {
			$stage = self::stage($order, null);
			self::progress($stage, $labels);
			echo '<p class="genz-trk-note">Your order is being prepared. As soon as it ships we will email you the tracking number and every update after that.</p>';
		}
		foreach ($numbers as $n) {
			$data = self::cainiao($n);
			$stage = self::stage($order, $data);
			echo '<div class="genz-trk-parcel">';
			echo '<div class="genz-trk-no"><span class="genz-muted">Tracking number</span><strong>' . esc_html($n) . '</strong>';
			echo '<span class="genz-pill">' . esc_html($labels[$stage]) . '</span>';
			echo '</div>';
			self::progress($stage, $labels);
			if (!empty($data['ok']) && !empty($data['events'])) {
				echo '<ol class="genz-trk-events">';
				foreach ($data['events'] as $i => $e) {
					echo '<li' . ($i === 0 ? ' class="is-latest"' : '') . '><span class="genz-trk-time">' . esc_html($e['ts']) . '</span><span class="genz-trk-desc">' . esc_html($e['d']) . ($e['node'] ? ' <em>(' . esc_html($e['node']) . ')</em>' : '') . '</span></li>';
				}
				echo '</ol>';
			} elseif (!empty($data['ok'])) {
				echo '<p class="genz-trk-note">Your parcel has been registered with the courier. Tracking events usually appear within 24&ndash;72 hours of dispatch, so please check back soon.</p>';
			} else {
				echo '<p class="genz-trk-note">Live tracking is temporarily unavailable. Please try again in a few minutes &ndash; your parcel is still on its way.</p>';
			}
			echo '</div>';
		}
		echo '</div>';
		return ob_get_clean();
	}
	static function progress($stage, $labels) {
		echo '<ol class="genz-steps">';
		foreach ($labels as $i => $l) {
			$cls = $i < $stage ? 'done' : ($i === $stage ? 'current' : '');
			echo '<li class="' . esc_attr($cls) . '"><span class="genz-dot"></span><span class="genz-lbl">' . esc_html($l) . '</span></li>';
		}
		echo '</ol>';
	}

	/* ---------------------------------------------------------- shortcodes */
	static function sc_track() {
		ob_start(); ?>
		<div class="genz-card genz-track-card" id="genz-track">
			<h2 class="genz-h">Track your order</h2>
			<p class="genz-muted">Enter your order number and the email you used at checkout, or just your tracking number.</p>
			<div class="genz-tabs" role="tablist">
				<button type="button" class="genz-tab is-active" data-tab="order">Order number</button>
				<button type="button" class="genz-tab" data-tab="tn">Tracking number</button>
			</div>
			<form class="genz-track-form" data-mode="order" novalidate>
				<div class="genz-row genz-mode-order">
					<label>Order number<input type="text" name="order" inputmode="numeric" placeholder="e.g. 2244" autocomplete="off"></label>
					<label>Email<input type="email" name="email" placeholder="you@example.com" autocomplete="email"></label>
				</div>
				<div class="genz-row genz-mode-tn" hidden>
					<label>Tracking number<input type="text" name="tn" placeholder="e.g. LP00..." autocomplete="off"></label>
				</div>
				<button type="submit" class="genz-btn">Track order</button>
				<p class="genz-msg" role="status" aria-live="polite"></p>
			</form>
			<div class="genz-track-result"></div>
		</div>
		<?php return ob_get_clean();
	}
	static function sc_subscribe($atts = []) {
		$a = shortcode_atts(['title' => 'Get deals & new gadget drops first', 'text' => 'Join our list for exclusive offers, new arrivals and gift ideas. No spam, unsubscribe any time.'], $atts);
		ob_start(); ?>
		<div class="genz-sub">
			<div class="genz-sub-copy"><h3><?php echo esc_html($a['title']); ?></h3><p><?php echo esc_html($a['text']); ?></p></div>
			<form class="genz-sub-form" novalidate>
				<input type="email" name="email" placeholder="Your email address" aria-label="Email address" required autocomplete="email">
				<input type="text" name="website" class="genz-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
				<button type="submit" class="genz-btn genz-btn-accent">Subscribe</button>
				<p class="genz-msg" role="status" aria-live="polite"></p>
			</form>
		</div>
		<?php return ob_get_clean();
	}
	static function footer_band($html, $block) {
		if (is_admin() || ($block['blockName'] ?? '') !== 'core/template-part') return $html;
		$slug = $block['attrs']['slug'] ?? '';
		if ($slug !== 'footer') return $html;
		static $done = false;
		if ($done) return $html;
		$done = true;
		return '<section class="genz-sub-band">' . self::sc_subscribe() . '</section>' . $html;
	}

	/* -------------------------------------------------------------- assets */
	static function assets() {
		wp_register_style('genz-hub', false, [], self::V);
		wp_enqueue_style('genz-hub');
		wp_add_inline_style('genz-hub', self::css());
		wp_register_script('genz-hub', false, [], self::V, true);
		wp_enqueue_script('genz-hub');
		$cfg = [
			'api'      => esc_url_raw(rest_url(self::NS . '/')),
			'nonce'    => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
			'loggedIn' => is_user_logged_in(),
			'chat'     => !is_admin(),
		];
		wp_add_inline_script('genz-hub', 'window.GENZHUB=' . wp_json_encode($cfg) . ';' . self::js());
	}

	static function css() {
		return <<<'CSS'
.genz-card,.genz-card p,.genz-card li,.genz-card label,.genz-card strong,.genz-card h2,.genz-card h3,.genz-chat-panel,.genz-chat-panel p,.genz-chat-panel label{color:#111}
.genz-card a{color:#111;text-decoration:underline}
.genz-card .genz-muted,.genz-card .genz-trk-time{color:#6b6b6b}
.genz-chat-root [hidden],.genz-track-card [hidden],.genz-chat-panel[hidden],.genz-chat-foot[hidden],.genz-row[hidden]{display:none!important}
.genz-chat-root label,.genz-card label{display:block!important;margin:0 0 12px!important;font-weight:600}
.genz-chat-root input:not([type=checkbox]),.genz-chat-root textarea,.genz-card input:not([type=checkbox]),.genz-sub-form input[type=email]{display:block!important;width:100%!important;box-sizing:border-box!important;margin-top:6px!important;padding:11px 13px!important;border:1px solid #cfcfcf!important;border-radius:8px!important;background:#fff!important;color:#111!important;font:inherit!important;font-weight:400!important;box-shadow:none!important}
.genz-chat-root input:focus,.genz-chat-root textarea:focus,.genz-card input:focus,.genz-sub-form input:focus{outline:2px solid #ffcc00!important;border-color:#111!important}
.genz-chat-foot textarea{margin:0!important}
.genz-sub-form input[type=email]{margin:0!important;flex:1 1 220px;width:auto!important}
.genz-card{background:#fff;border:1px solid #e7e7e7;border-radius:14px;padding:24px;margin:24px 0;box-shadow:0 2px 12px rgba(0,0,0,.04)}
.genz-h{margin:0 0 6px}
.genz-muted{color:#6b6b6b;font-size:.9em;display:block}
.genz-tabs{display:flex;gap:8px;margin:16px 0}
.genz-tab{border:1px solid #d9d9d9;background:#f6f6f6;border-radius:999px;padding:8px 16px;cursor:pointer;font:inherit;color:#111}
.genz-tab.is-active{background:#111;color:#fff;border-color:#111}
.genz-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.genz-row[hidden]{display:none}
.genz-mode-tn{grid-template-columns:1fr}
.genz-track-form label,.genz-chat label{display:block;font-weight:600;font-size:.92em;color:#111}
.genz-track-form input,.genz-chat input,.genz-chat textarea,.genz-sub-form input[type=email],.genz-magic input{display:block;width:100%;box-sizing:border-box;margin-top:6px;padding:12px 14px;border:1px solid #cfcfcf;border-radius:8px;font:inherit;background:#fff;color:#111}
.genz-btn{display:inline-block;margin-top:14px;background:#111;color:#fff;border:0;border-radius:8px;padding:12px 24px;font:inherit;font-weight:700;cursor:pointer}
.genz-btn:hover{opacity:.88}
.genz-btn[disabled]{opacity:.5;cursor:wait}
.genz-btn-accent{background:#ffcc00;color:#111}
.genz-msg{margin:10px 0 0;font-size:.92em;min-height:1em}
.genz-msg.err{color:#b00020}.genz-msg.ok{color:#0a7a2f}
.genz-trk{margin-top:20px}
.genz-trk-head{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;background:#f7f7f7;border-radius:10px;padding:14px}
.genz-trk-items{list-style:none;padding:0;margin:14px 0;display:grid;gap:8px}
.genz-trk-items li{display:flex;align-items:center;gap:10px}
.genz-trk-items img{width:48px;height:48px;object-fit:cover;border-radius:6px}
.genz-trk-parcel{border:1px solid #ececec;border-radius:12px;padding:16px;margin-top:14px}
.genz-trk-no{display:flex;flex-wrap:wrap;align-items:center;gap:10px}
.genz-trk-no .genz-muted{width:100%}
.genz-pill{background:#fff6cc;color:#5a4500;border-radius:999px;padding:3px 10px;font-size:.82em;font-weight:600}
.genz-steps{list-style:none;display:flex;padding:0;margin:20px 0 8px;counter-reset:s}
.genz-steps li{flex:1;position:relative;text-align:center;font-size:.78em;color:#8a8a8a}
.genz-steps li:before{content:"";position:absolute;top:8px;left:-50%;width:100%;height:3px;background:#e3e3e3;z-index:0}
.genz-steps li:first-child:before{display:none}
.genz-steps li.done:before,.genz-steps li.current:before{background:#111}
.genz-dot{position:relative;z-index:1;display:block;width:18px;height:18px;margin:0 auto 6px;border-radius:50%;background:#e3e3e3;border:3px solid #fff;box-shadow:0 0 0 1px #e3e3e3}
.genz-steps li.done .genz-dot{background:#111;box-shadow:0 0 0 1px #111}
.genz-steps li.current .genz-dot{background:#ffcc00;box-shadow:0 0 0 2px #111}
.genz-steps li.done,.genz-steps li.current{color:#111;font-weight:600}
.genz-trk-events{list-style:none;padding:0 0 0 18px;margin:14px 0 0;border-left:2px solid #ececec}
.genz-trk-events li{position:relative;padding:0 0 14px 14px}
.genz-trk-events li:before{content:"";position:absolute;left:-25px;top:5px;width:10px;height:10px;border-radius:50%;background:#cfcfcf}
.genz-trk-events li.is-latest:before{background:#ffcc00;box-shadow:0 0 0 2px #111}
.genz-trk-time{display:block;font-size:.8em;color:#777}
.genz-trk-desc{display:block;color:#222}
.genz-trk-desc em{color:#777}
.genz-trk-no strong{font-size:1.15em;letter-spacing:.5px;color:#111}
.genz-trk-note{background:#f7f7f7;border-radius:8px;padding:12px;margin-top:12px}
.genz-sub-band{background:#111;color:#fff;padding:36px 20px}
.genz-sub{max-width:1100px;margin:0 auto;display:flex;flex-wrap:wrap;gap:20px;align-items:center;justify-content:space-between}
.genz-sub-copy h3{margin:0 0 4px;color:inherit;font-size:1.35em}
.genz-sub-copy p{margin:0;opacity:.8}
.genz-sub-form{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start;flex:1 1 360px;max-width:520px}
.genz-sub-form input[type=email]{flex:1 1 220px;margin:0}
.genz-sub-form .genz-btn{margin:0}
.genz-sub-form .genz-msg{width:100%;margin:0}
.genz-sub-band .genz-msg.ok{color:#7ee2a1}.genz-sub-band .genz-msg.err{color:#ff9aa8}
.genz-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;opacity:0}
.genz-magic{margin-bottom:28px}
.genz-magic form{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
.genz-magic label{flex:1 1 260px;font-weight:600}
.genz-magic .genz-btn{margin:0}
.genz-magic .genz-msg{width:100%}
.genz-chat-fab{position:fixed;right:18px;bottom:18px;z-index:9990;background:#111;color:#fff;border:0;border-radius:999px;padding:13px 18px;font:inherit;font-weight:700;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25);display:flex;align-items:center;gap:8px}
.genz-chat-fab svg{width:20px;height:20px;fill:#ffcc00}
.genz-chat-fab .genz-badge{background:#ffcc00;color:#111;border-radius:999px;font-size:.75em;padding:1px 7px}
.genz-chat-panel{position:fixed;right:18px;bottom:80px;z-index:9991;width:360px;max-width:calc(100vw - 24px);height:520px;max-height:calc(100vh - 110px);background:#fff;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden}
.genz-chat-panel[hidden]{display:none}
.genz-chat-top{background:#111;color:#fff;padding:14px 16px;display:flex;justify-content:space-between;align-items:center}
.genz-chat-top strong{display:block}
.genz-chat-top small{opacity:.75}
.genz-chat-x{background:none;border:0;color:#fff;font-size:22px;cursor:pointer;line-height:1}
.genz-chat-body{flex:1;overflow:auto;padding:14px;background:#f6f6f6}
.genz-chat-start label{margin-bottom:10px}
.genz-chat-start textarea{min-height:90px;resize:vertical}
.genz-bubble{max-width:82%;margin:0 0 10px;padding:10px 12px;border-radius:12px;white-space:pre-wrap;word-wrap:break-word;font-size:.95em}
.genz-bubble small{display:block;font-size:.72em;opacity:.6;margin-top:4px}
.genz-bubble.c{margin-left:auto;background:#111;color:#fff;border-bottom-right-radius:4px}
.genz-bubble.a{background:#fff;border:1px solid #e5e5e5;border-bottom-left-radius:4px}
.genz-chat-foot{display:flex;gap:8px;padding:10px;border-top:1px solid #eee;background:#fff}
.genz-chat-foot textarea{flex:1;margin:0;min-height:44px;max-height:120px;resize:none}
.genz-chat-foot .genz-btn{margin:0;padding:10px 16px}
.genz-chat-embed .genz-chat-panel{position:static;width:auto;max-width:none;height:560px;box-shadow:none;border:1px solid #e5e5e5}
.genz-ss{z-index:99999;background:#fff;color:#111;border-radius:12px;box-shadow:0 14px 40px rgba(0,0,0,.28);max-height:min(72vh,620px);overflow:auto;padding:8px;font-size:15px;text-align:left}
.genz-ss[hidden]{display:none!important}
.genz-ss-h{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#777;padding:10px 10px 6px}
.genz-ss a{color:#111!important;text-decoration:none!important}
.genz-ss-p{display:grid;grid-template-columns:52px 1fr auto;gap:12px;align-items:center;padding:8px 10px;border-radius:10px}
.genz-ss-p:hover,.genz-ss-p:focus,.genz-ss-cat:hover,.genz-ss-cat:focus{background:#f5f2ea;outline:none}
.genz-ss-p img{width:52px;height:52px;object-fit:cover;border-radius:8px;background:#f3f3f3}
.genz-ss-n{line-height:1.3;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.genz-ss-n em{display:block;font-style:normal;color:#b00020;font-size:12px;font-weight:500}
.genz-ss-st{display:block;color:#f5b301;font-size:12px;letter-spacing:1px;font-weight:400}
.genz-ss-pr{font-weight:700;white-space:nowrap}
.genz-ss-pr s{color:#999;font-weight:400;font-size:12px}
.genz-ss-cat{display:block;padding:8px 10px;border-radius:8px;font-weight:600}
.genz-ss-cat span{color:#888;font-weight:400}
.genz-ss-chips{display:flex;flex-wrap:wrap;gap:6px;padding:2px 10px 8px}
.genz-ss-chip{border:1px solid #ddd;background:#fafafa;border-radius:999px;padding:6px 12px;font:inherit;font-size:13px;color:#111;cursor:pointer}
.genz-ss-chip:hover{background:#111;color:#fff;border-color:#111}
.genz-ss-none{padding:14px 10px;color:#555}
.genz-ss-all{display:block;margin:6px 4px 2px;padding:12px;text-align:center;background:#111;color:#fff!important;border-radius:10px;font-weight:700}
@media (max-width:640px){.genz-row,.genz-trk-head{grid-template-columns:1fr}.genz-steps li .genz-lbl{font-size:.85em}.genz-chat-fab{padding:12px 14px;bottom:84px}.genz-chat-fab .genz-fab-txt{display:none}.genz-chat-panel{right:12px;bottom:140px;height:70vh}}
CSS;
	}

	static function js() {
		return <<<'JS'
(function(){
var C=window.GENZHUB||{};
function api(path,opts){opts=opts||{};var h={'Content-Type':'application/json'};if(C.nonce)h['X-WP-Nonce']=C.nonce;
 return fetch(C.api+path,{method:opts.method||'GET',headers:h,credentials:'same-origin',body:opts.body?JSON.stringify(opts.body):undefined}).then(function(r){return r.json().then(function(j){if(!r.ok)throw j;return j;});});}
function msg(el,t,ok){if(!el)return;el.textContent=t||'';el.className='genz-msg '+(t?(ok?'ok':'err'):'');}
function esc(s){var d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}
function err(e){return (e&&e.message)?e.message:'Something went wrong. Please try again.';}

/* tracking */
document.querySelectorAll('.genz-track-card').forEach(function(card){
 var form=card.querySelector('form'),res=card.querySelector('.genz-track-result'),m=form.querySelector('.genz-msg');
 card.querySelectorAll('.genz-tab').forEach(function(b){b.addEventListener('click',function(){
  card.querySelectorAll('.genz-tab').forEach(function(x){x.classList.toggle('is-active',x===b);});
  var t=b.getAttribute('data-tab');form.setAttribute('data-mode',t);
  form.querySelector('.genz-mode-order').hidden=(t!=='order');form.querySelector('.genz-mode-tn').hidden=(t!=='tn');msg(m,'');});});
 var q=new URLSearchParams(location.search);
 if(q.get('tn')){card.querySelector('[data-tab=tn]').click();form.tn.value=q.get('tn');setTimeout(function(){form.requestSubmit?form.requestSubmit():form.dispatchEvent(new Event('submit'));},50);}
 else if(q.get('order')){form.order.value=q.get('order');}
 form.addEventListener('submit',function(e){e.preventDefault();
  var mode=form.getAttribute('data-mode'),body={};
  if(mode==='tn'){body.tn=form.tn.value.trim();if(!body.tn)return msg(m,'Please enter your tracking number.');}
  else{body.order=form.order.value.trim();body.email=form.email.value.trim();if(!body.order||!body.email)return msg(m,'Please enter your order number and email.');}
  var btn=form.querySelector('button[type=submit]');btn.disabled=true;msg(m,'Checking the latest updates...',true);res.innerHTML='';
  api('track',{method:'POST',body:body}).then(function(j){msg(m,'');res.innerHTML=j.html;res.scrollIntoView({behavior:'smooth',block:'start'});})
  .catch(function(e2){msg(m,err(e2));}).then(function(){btn.disabled=false;});});
});

/* subscribe */
document.querySelectorAll('.genz-sub-form').forEach(function(f){var m=f.querySelector('.genz-msg');
 f.addEventListener('submit',function(e){e.preventDefault();var em=f.email.value.trim();if(!/^\S+@\S+\.\S+$/.test(em))return msg(m,'Please enter a valid email address.');
  var b=f.querySelector('button');b.disabled=true;
  api('subscribe',{method:'POST',body:{email:em,website:f.website.value}}).then(function(j){msg(m,j.message,true);f.email.value='';}).catch(function(e2){msg(m,err(e2));}).then(function(){b.disabled=false;});});});

/* magic link */
document.querySelectorAll('.genz-magic form').forEach(function(f){var m=f.querySelector('.genz-msg');
 f.addEventListener('submit',function(e){e.preventDefault();var em=f.email.value.trim();if(!/^\S+@\S+\.\S+$/.test(em))return msg(m,'Please enter a valid email address.');
  var b=f.querySelector('button');b.disabled=true;
  api('magic',{method:'POST',body:{email:em}}).then(function(j){msg(m,j.message,true);}).catch(function(e2){msg(m,err(e2));}).then(function(){b.disabled=false;});});});

/* chat */
function Chat(root,embedded){
 var panel=root.querySelector('.genz-chat-panel'),body=root.querySelector('.genz-chat-body'),foot=root.querySelector('.genz-chat-foot'),fab=root.querySelector('.genz-chat-fab'),badge=root.querySelector('.genz-badge');
 var timer=null,hasThread=false,last='';
 function render(j){
  if(!j.thread){hasThread=false;foot.hidden=true;
   if(!body.querySelector('.genz-chat-start')){body.innerHTML='<form class="genz-chat-start"><p style="margin-top:0">Hi! Ask us anything about products, delivery or your order. We usually reply within a few hours.</p>'+
   '<label>Your name<input name="name" required autocomplete="name" value="'+esc(j.prefill&&j.prefill.name)+'"></label>'+
   '<label>Email<input name="email" type="email" required autocomplete="email" value="'+esc(j.prefill&&j.prefill.email)+'"></label>'+
   '<label>Order number (optional)<input name="order" inputmode="numeric"></label>'+
   '<label>Message<textarea name="message" required></textarea></label><input type="text" name="website" class="genz-hp" tabindex="-1" autocomplete="off">'+
   '<button class="genz-btn" type="submit">Start chat</button><p class="genz-msg"></p></form>';
   var f=body.querySelector('form');f.addEventListener('submit',function(e){e.preventDefault();var mm=f.querySelector('.genz-msg');
    var E=f.elements;var d={name:E['name'].value.trim(),email:E['email'].value.trim(),order:E['order'].value.trim(),message:E['message'].value.trim(),website:E['website'].value};
    if(!d.name||!/^\S+@\S+\.\S+$/.test(d.email)||!d.message)return msg(mm,'Please add your name, a valid email and a message.');
    var b=f.querySelector('button');b.disabled=true;api('chat',{method:'POST',body:d}).then(render).catch(function(e2){msg(mm,err(e2));b.disabled=false;});});}
   return;}
  hasThread=true;foot.hidden=false;
  var key=JSON.stringify(j.messages);if(key===last)return;last=key;
  var h='';j.messages.forEach(function(x){h+='<div class="genz-bubble '+(x.s==='admin'?'a':'c')+'">'+esc(x.b)+'<small>'+(x.s==='admin'?'GenZ Gadgets':'You')+' &middot; '+esc(x.t)+'</small></div>';});
  if(j.thread.status==='closed')h+='<p class="genz-muted" style="text-align:center">This conversation was marked as resolved. Send a message to reopen it.</p>';
  body.innerHTML=h;body.scrollTop=body.scrollHeight;
 }
 function load(){return api('chat').then(function(j){render(j);if(badge){badge.hidden=!(j.unread>0);badge.textContent=j.unread||'';}}).catch(function(){});}
 function open(){panel.hidden=false;if(fab)fab.setAttribute('aria-expanded','true');load();clearInterval(timer);timer=setInterval(load,12000);}
 function close(){panel.hidden=true;if(fab)fab.setAttribute('aria-expanded','false');clearInterval(timer);}
 if(fab)fab.addEventListener('click',function(){panel.hidden?open():close();});
 var x=root.querySelector('.genz-chat-x');if(x)x.addEventListener('click',close);
 var ta=foot.querySelector('textarea'),sb=foot.querySelector('button');
 function send(){var t=ta.value.trim();if(!t)return;sb.disabled=true;api('chat',{method:'POST',body:{message:t}}).then(function(j){ta.value='';render(j);}).catch(function(e2){alertless(e2);}).then(function(){sb.disabled=false;ta.focus();});}
 function alertless(e2){var p=document.createElement('p');p.className='genz-msg err';p.textContent=err(e2);body.appendChild(p);}
 sb.addEventListener('click',send);ta.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}});
 if(embedded){open();}else{ if(location.hash==='#genz-chat'||location.hash==='#genz-chat-open')open(); else if(C.loggedIn||document.cookie.indexOf('genz_chat_on=1')>-1) load(); }
}
document.querySelectorAll('.genz-chat-root').forEach(function(r){Chat(r,r.classList.contains('genz-chat-embed'));});

/* smart search */
(function(){
 var box=document.createElement('div');box.className='genz-ss';box.hidden=true;box.setAttribute('role','listbox');document.body.appendChild(box);
 var cur=null,timer=null,seq=0,cache={};
 function place(){if(!cur)return;var r=cur.getBoundingClientRect();if(window.innerWidth<700){box.style.left='8px';box.style.right='8px';box.style.width='auto';box.style.top=(r.bottom+6)+'px';box.style.position='fixed';}else{var w=Math.max(r.width,420);var l=Math.min(r.left,window.innerWidth-w-12);box.style.position='fixed';box.style.left=Math.max(8,l)+'px';box.style.right='auto';box.style.width=w+'px';box.style.top=(r.bottom+6)+'px';}}
 function stars(n){n=Math.round(n||0);if(!n)return '';var s='';for(var i=0;i<5;i++)s+=i<n?'\u2605':'\u2606';return '<span class="genz-ss-st">'+s+'</span>';}
 function render(j){
  var h='';
  if(j.popular&&!j.q){h+='<div class="genz-ss-h">Popular searches</div><div class="genz-ss-chips">';j.popular.forEach(function(p){h+='<button type="button" class="genz-ss-chip" data-q="'+esc(p)+'">'+esc(p)+'</button>';});h+='</div>';}
  if(j.cats&&j.cats.length){h+='<div class="genz-ss-h">Categories</div>';j.cats.forEach(function(c){h+='<a class="genz-ss-cat" href="'+esc(c.u)+'">'+esc(c.n)+' <span>('+c.c+')</span></a>';});}
  if(j.products&&j.products.length){h+='<div class="genz-ss-h">'+(j.q?'Products':'Bestsellers')+'</div>';j.products.forEach(function(p){h+='<a class="genz-ss-p" href="'+esc(p.u)+'"><img src="'+esc(p.i)+'" alt="" loading="lazy"><span class="genz-ss-n">'+esc(p.n)+stars(p.rt)+(p.st?'':'<em>Out of stock</em>')+'</span><span class="genz-ss-pr">'+(p.r?'<s>'+esc(p.r)+'</s> ':'')+esc(p.p)+'</span></a>';});}
  else if(j.q){h+='<div class="genz-ss-none">No exact match for &ldquo;'+esc(j.q)+'&rdquo;. Try &ldquo;gifts for him&rdquo;, &ldquo;heated&rdquo; or &ldquo;christmas&rdquo;.</div>';}
  if(j.all)h+='<a class="genz-ss-all" href="'+esc(j.all)+'">See all results for &ldquo;'+esc(j.q)+'&rdquo; &rarr;</a>';
  box.innerHTML=h;box.hidden=false;place();
  box.querySelectorAll('.genz-ss-chip').forEach(function(b){b.addEventListener('mousedown',function(e){e.preventDefault();cur.value=b.getAttribute('data-q');go();});});
 }
 function go(){var q=cur.value.trim();var my=++seq;if(cache[q]){render(cache[q]);return;}
  fetch(C.api+'suggest?q='+encodeURIComponent(q)).then(function(r){return r.json();}).then(function(j){cache[q]=j;if(my===seq)render(j);}).catch(function(){});}
 function attach(inp){if(inp.dataset.genzSs)return;inp.dataset.genzSs='1';inp.setAttribute('autocomplete','off');
  if(!inp.placeholder||/search/i.test(inp.placeholder))inp.placeholder='Search gadgets & gifts...';
  var f=inp.form;if(f&&!f.querySelector('input[name=post_type]')){var h=document.createElement('input');h.type='hidden';h.name='post_type';h.value='product';f.appendChild(h);}
  inp.addEventListener('focus',function(){cur=inp;go();});
  inp.addEventListener('input',function(){cur=inp;clearTimeout(timer);timer=setTimeout(go,160);});
  inp.addEventListener('keydown',function(e){var items=[].slice.call(box.querySelectorAll('a'));var i=items.indexOf(document.activeElement);
   if(e.key==='ArrowDown'&&items.length){e.preventDefault();(items[0]).focus();}
   if(e.key==='Escape'){box.hidden=true;}});
  inp.addEventListener('blur',function(){setTimeout(function(){if(!box.contains(document.activeElement))box.hidden=true;},180);});}
 box.addEventListener('keydown',function(e){var items=[].slice.call(box.querySelectorAll('a'));var i=items.indexOf(document.activeElement);
  if(e.key==='ArrowDown'){e.preventDefault();if(items[i+1])items[i+1].focus();}
  if(e.key==='ArrowUp'){e.preventDefault();if(i>0)items[i-1].focus();else if(cur)cur.focus();}
  if(e.key==='Escape'){box.hidden=true;if(cur)cur.focus();}});
 document.addEventListener('click',function(e){if(!box.contains(e.target)&&e.target!==cur)box.hidden=true;});
 window.addEventListener('resize',place);window.addEventListener('scroll',place,{passive:true});
 function scan(){document.querySelectorAll('input[name=s]:not(#adminbar-search)').forEach(attach);}
 scan();new MutationObserver(scan).observe(document.body,{childList:true,subtree:true});
})();
document.querySelectorAll('a[href$="#genz-chat"]').forEach(function(a){a.addEventListener('click',function(e){var f=document.querySelector('.genz-chat-root:not(.genz-chat-embed) .genz-chat-fab');if(f){e.preventDefault();f.click();}});});
})();
JS;
	}

	static function chat_markup($embedded = false) {
		$fab = $embedded ? '' : '<button type="button" class="genz-chat-fab" aria-expanded="false" aria-label="Chat with us"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 3h16a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2z"/></svg><span class="genz-fab-txt">Chat with us</span><span class="genz-badge" hidden></span></button>';
		return '<div class="genz-chat-root' . ($embedded ? ' genz-chat-embed' : '') . '">' . $fab .
			'<div class="genz-chat-panel"' . ($embedded ? '' : ' hidden') . ' role="dialog" aria-label="Chat with GenZ Gadgets">' .
			'<div class="genz-chat-top"><div><strong>GenZ Gadgets Support</strong><small>UK team &middot; we reply by email too</small></div>' . ($embedded ? '' : '<button type="button" class="genz-chat-x" aria-label="Close chat">&times;</button>') . '</div>' .
			'<div class="genz-chat-body"></div>' .
			'<div class="genz-chat-foot" hidden><textarea rows="1" placeholder="Type your message..." aria-label="Message" maxlength="2000"></textarea><button type="button" class="genz-btn">Send</button></div>' .
			'</div></div>';
	}
	static function chat_widget() {
		if (is_admin()) return;
		if (function_exists('is_account_page') && is_account_page() && get_query_var('messages', null) !== null) return;
		if (function_exists('is_checkout') && is_checkout()) return;
		echo self::chat_markup(false); // phpcs:ignore
	}

	/* ------------------------------------------------------- smart search */
	static function synonyms() {
		return [
			'xmas' => 'christmas', 'chrimbo' => 'christmas', 'santa' => 'christmas', 'stocking' => 'christmas',
			'dad' => 'him', 'father' => 'him', 'husband' => 'him', 'boyfriend' => 'him', 'men' => 'him', 'mens' => 'him', 'man' => 'him', 'grandad' => 'him', 'brother' => 'him',
			'mum' => 'her', 'mom' => 'her', 'mother' => 'her', 'wife' => 'her', 'girlfriend' => 'her', 'women' => 'her', 'womens' => 'her', 'woman' => 'her', 'nan' => 'her', 'sister' => 'her', 'ladies' => 'her',
			'teen' => 'teens', 'teenager' => 'teens', 'kid' => 'kids', 'children' => 'kids', 'child' => 'kids', 'boys' => 'kids', 'girls' => 'kids',
			'present' => 'gift', 'presents' => 'gift', 'gifts' => 'gift',
			'warm' => 'heated', 'warmer' => 'heated', 'heating' => 'heated', 'thermal' => 'heated', 'cold' => 'heated', 'winter' => 'heated',
			'lamp' => 'light', 'lights' => 'light', 'led' => 'light', 'projector' => 'light', 'night' => 'light',
			'phone' => 'phone', 'mobile' => 'phone', 'iphone' => 'phone', 'samsung' => 'phone', 'charger' => 'charging', 'charge' => 'charging', 'cable' => 'charging', 'powerbank' => 'power',
			'car' => 'car', 'vehicle' => 'car', 'driving' => 'car',
			'skin' => 'beauty', 'skincare' => 'beauty', 'face' => 'beauty', 'facial' => 'beauty', 'spots' => 'pimple', 'acne' => 'pimple', 'spot' => 'pimple', 'hair' => 'beauty',
			'kitchen' => 'kitchen', 'cooking' => 'kitchen', 'cook' => 'kitchen', 'blend' => 'blender', 'smoothie' => 'blender', 'coffee' => 'kitchen', 'frother' => 'milk',
			'pet' => 'pet', 'dog' => 'pet', 'cat' => 'pet', 'puppy' => 'pet', 'bird' => 'bird', 'birds' => 'bird', 'garden' => 'outdoor',
			'cleaning' => 'clean', 'cleaner' => 'clean', 'hoover' => 'vacuum', 'organiser' => 'storage', 'organizer' => 'storage', 'tidy' => 'storage',
			'cheap' => 'budget', 'under' => 'budget', 'deal' => 'budget', 'deals' => 'budget', 'offer' => 'budget', 'sale' => 'budget',
			'game' => 'gaming', 'games' => 'gaming', 'console' => 'gaming', 'retro' => 'gaming',
			'travel' => 'travel', 'holiday' => 'travel', 'trip' => 'travel', 'gym' => 'fitness', 'workout' => 'fitness', 'sport' => 'fitness',
			'colour' => 'color', 'grey' => 'gray', 'organise' => 'storage',
		];
	}
	static function tokens($text) {
		$text = strtolower(html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8'));
		$text = preg_replace('/[^a-z0-9 ]+/', ' ', $text);
		$stop = ['the', 'and', 'for', 'with', 'a', 'an', 'of', 'to', 'in', 'on', 'uk', 'best', 'buy', 'new', 'my', 'your', 'is', 'it', 'by', 'from', 'at', 'or'];
		$out = [];
		foreach (preg_split('/\s+/', $text) as $w) {
			if ($w === '' || in_array($w, $stop, true)) continue;
			if (strlen($w) > 4 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') $w = substr($w, 0, -1);
			$out[] = $w;
		}
		return $out;
	}
	static function search_index() {
		$idx = get_transient('genz_search_index');
		if (is_array($idx)) return $idx;
		$idx = [];
		$ids = wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'ids', 'visibility' => 'search']);
		foreach ($ids as $id) {
			$p = wc_get_product($id);
			if (!$p) continue;
			$cats = wp_get_post_terms($id, 'product_cat', ['fields' => 'names']);
			$tags = wp_get_post_terms($id, 'product_tag', ['fields' => 'names']);
			$focus = get_post_meta($id, '_yoast_wpseo_focuskw', true);
			$title_t = self::tokens($p->get_name());
			$meta_t = self::tokens(implode(' ', (array) $cats) . ' ' . implode(' ', (array) $tags) . ' ' . $focus . ' ' . $p->get_sku());
			$body_t = array_slice(array_unique(self::tokens($p->get_short_description() . ' ' . wp_trim_words($p->get_description(), 120, ''))), 0, 160);
			$img = $p->get_image_id() ? wp_get_attachment_image_url($p->get_image_id(), 'thumbnail') : wc_placeholder_img_src('thumbnail');
			$idx[] = [
				'id' => $id, 'n' => html_entity_decode($p->get_name(), ENT_QUOTES, 'UTF-8'), 'u' => get_permalink($id), 'i' => $img,
				'p' => wp_strip_all_tags(html_entity_decode(wc_price(wc_get_price_to_display($p)), ENT_QUOTES, 'UTF-8')),
				'r' => $p->is_on_sale() && $p->get_regular_price() ? wp_strip_all_tags(html_entity_decode(wc_price($p->get_regular_price()), ENT_QUOTES, 'UTF-8')) : '',
				'st' => $p->is_in_stock() ? 1 : 0, 'sales' => (int) $p->get_total_sales(), 'rt' => (float) $p->get_average_rating(),
				'tt' => array_values(array_unique($title_t)), 'mt' => array_values(array_unique($meta_t)), 'bt' => array_values($body_t),
				'c' => array_values((array) $cats),
			];
		}
		set_transient('genz_search_index', $idx, 12 * HOUR_IN_SECONDS);
		return $idx;
	}
	static function match_score($q, $words) {
		$best = 0;
		foreach ($words as $w) {
			if ($w === $q) return 1.0;
			if (strlen($q) >= 2 && strpos($w, $q) === 0) $best = max($best, 0.8);
			elseif (strlen($q) >= 4 && strpos($w, $q) !== false) $best = max($best, 0.6);
			elseif (strlen($q) >= 4 && strlen($w) >= 4 && abs(strlen($w) - strlen($q)) <= 2 && levenshtein($q, $w) <= (strlen($q) >= 6 ? 2 : 1)) $best = max($best, 0.55);
		}
		return $best;
	}
	static function search_products($query, $limit = 8) {
		$qt = self::tokens($query);
		if (!$qt) return [];
		$syn = self::synonyms();
		$res = [];
		foreach (self::search_index() as $p) {
			$score = 0; $hit = 0;
			foreach ($qt as $t) {
				$alts = [$t];
				if (isset($syn[$t]) && $syn[$t] !== $t) { $st = self::tokens($syn[$t]); if ($st) $alts[] = $st[0]; }
				$ts = 0;
				foreach ($alts as $k => $a) {
					$f = $k ? 0.7 : 1.0;
					$ts = max($ts, $f * 3 * self::match_score($a, $p['tt']), $f * 2 * self::match_score($a, $p['mt']), $f * 0.8 * self::match_score($a, $p['bt']));
				}
				if ($ts > 0) $hit++;
				$score += $ts;
			}
			if (!$hit) continue;
			// Need most query words to match (all for 1-2 words).
			$need = count($qt) <= 2 ? count($qt) : (int) ceil(count($qt) * 0.6);
			if ($hit < $need) continue;
			if (stripos($p['n'], trim($query)) !== false) $score += 3;
			$score += min(1.5, log(1 + $p['sales']) / 3) + ($p['rt'] / 10) + ($p['st'] ? 0.5 : -2);
			$res[] = [$score, $p];
		}
		usort($res, function ($a, $b) { return $b[0] <=> $a[0]; });
		return array_map(function ($r) { return $r[1]; }, array_slice($res, 0, $limit));
	}
	static function r_suggest(WP_REST_Request $r) {
		$q = trim(sanitize_text_field((string) $r->get_param('q')));
		$q = mb_substr($q, 0, 80);
		if (mb_strlen($q) < 2) {
			// Popular picks for an empty box.
			$idx = self::search_index();
			usort($idx, function ($a, $b) { return $b['sales'] <=> $a['sales']; });
			$pop = array_slice(array_filter($idx, function ($p) { return $p['st']; }), 0, 4);
			return ['q' => $q, 'products' => array_map([__CLASS__, 'pub'], array_values($pop)), 'cats' => [], 'popular' => ['heated gilet', 'christmas gifts', 'gifts for him', 'gifts for her', 'pimple patches', 'galaxy projector'], 'all' => ''];
		}
		$found = self::search_products($q, 8);
		$cats = [];
		$terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 200]);
		$qt = self::tokens($q);
		$weak = ['gift', 'present', 'gifts', 'presents'];
		$strong = array_values(array_filter($qt, function ($w) use ($weak) { return !in_array($w, $weak, true); }));
		if ($strong) $qt = $strong;
		$syn = self::synonyms();
		foreach ((array) $terms as $t) {
			if ($t->slug === 'uncategorized') continue;
			$nt = self::tokens($t->name . ' ' . $t->slug);
			$ok = false;
			foreach ($qt as $w) { if (self::match_score($w, $nt) >= 0.6 || (isset($syn[$w]) && ($sw = self::tokens($syn[$w])) && self::match_score($sw[0], $nt) >= 0.8)) $ok = true; }
			if ($ok) $cats[] = ['n' => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'), 'u' => get_term_link($t), 'c' => (int) $t->count];
			if (count($cats) >= 3) break;
		}
		return ['q' => $q, 'products' => array_map([__CLASS__, 'pub'], $found), 'cats' => $cats, 'all' => add_query_arg(['s' => $q, 'post_type' => 'product'], home_url('/'))];
	}
	static function pub($p) { return ['n' => $p['n'], 'u' => $p['u'], 'i' => $p['i'], 'p' => $p['p'], 'r' => $p['r'], 'st' => $p['st'], 'rt' => $p['rt']]; }
	static function search_query($q) {
		if (is_admin() || !$q->is_main_query() || !$q->is_search()) return;
		$s = $q->get('s');
		if (!$s) return;
		$found = self::search_products($s, 60);
		$q->set('post_type', 'product');
		if ($found) {
			$q->set('post__in', array_column($found, 'id'));
			$q->set('orderby', 'post__in');
			$q->set('s', '');
			$q->set('genz_s', $s);
			add_filter('get_search_query', function ($v) use ($s) { return $v === '' ? $s : $v; });
			$q->is_search = true;
		}
	}

	/* --------------------------------------------------- installable admin */
	static function r_manifest() {
		$icon = self::logo_url() ?: get_site_icon_url(512);
		$r = new WP_REST_Response([
			'name' => 'GenZ Hub - GenZ Gadgets Store',
			'short_name' => 'GenZ Hub',
			'start_url' => admin_url('admin.php?page=genz-hub'),
			'scope' => admin_url(),
			'display' => 'standalone',
			'background_color' => '#111111',
			'theme_color' => '#111111',
			'icons' => array_values(array_filter([
				get_site_icon_url(192) ? ['src' => get_site_icon_url(192), 'sizes' => '192x192', 'type' => 'image/png'] : null,
				get_site_icon_url(512) ? ['src' => get_site_icon_url(512), 'sizes' => '512x512', 'type' => 'image/png'] : null,
				$icon ? ['src' => $icon, 'sizes' => 'any', 'type' => 'image/png'] : null,
			])),
		]);
		$r->header('Content-Type', 'application/manifest+json');
		return $r;
	}
	static function admin_manifest_tags() {
		if (!current_user_can('manage_woocommerce')) return;
		echo '<link rel="manifest" href="' . esc_url(rest_url(self::NS . '/manifest')) . '"><meta name="theme-color" content="#111111"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="GenZ Hub">';
		if ($i = get_site_icon_url(180)) echo '<link rel="apple-touch-icon" href="' . esc_url($i) . '">';
	}

	/* ------------------------------------------------------------- routes */
	static function routes() {
		$pub = '__return_true';
		register_rest_route(self::NS, '/track', ['methods' => 'POST', 'callback' => [__CLASS__, 'r_track'], 'permission_callback' => $pub]);
		register_rest_route(self::NS, '/subscribe', ['methods' => 'POST', 'callback' => [__CLASS__, 'r_subscribe'], 'permission_callback' => $pub]);
		register_rest_route(self::NS, '/magic', ['methods' => 'POST', 'callback' => [__CLASS__, 'r_magic'], 'permission_callback' => $pub]);
		register_rest_route(self::NS, '/chat', [
			['methods' => 'GET', 'callback' => [__CLASS__, 'r_chat_get'], 'permission_callback' => $pub],
			['methods' => 'POST', 'callback' => [__CLASS__, 'r_chat_post'], 'permission_callback' => $pub],
		]);
		register_rest_route(self::NS, '/suggest', ['methods' => 'GET', 'callback' => [__CLASS__, 'r_suggest'], 'permission_callback' => '__return_true']);
		register_rest_route(self::NS, '/manifest', ['methods' => 'GET', 'callback' => [__CLASS__, 'r_manifest'], 'permission_callback' => '__return_true']);
		register_rest_route(self::NS, '/cainiao-test', ['methods' => 'GET', 'callback' => function ($r) {
			return self::cainiao($r->get_param('n'), true);
		}, 'permission_callback' => function () { return current_user_can('manage_woocommerce'); }]);
		register_rest_route(self::NS, '/status', ['methods' => 'GET', 'callback' => function () {
			global $wpdb;
			return [
				'v' => self::V,
				'phone' => get_option('woocommerce_checkout_phone_field'),
				'subs' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}genz_subscribers WHERE status='subscribed'"),
				'threads' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}genz_threads"),
				'active_tracking' => array_keys((array) get_option('genz_hub_active', [])),
				'cron' => wp_next_scheduled('genz_hub_track_cron'),
				'track_url' => self::track_url(),
			];
		}, 'permission_callback' => function () { return current_user_can('manage_woocommerce'); }]);
	}
	static function fail($m, $code = 400) { return new WP_Error('genz', $m, ['status' => $code]); }

	static function r_track(WP_REST_Request $r) {
		if (!self::limit('trk' . self::ip(), 30, 10 * MINUTE_IN_SECONDS)) return self::fail('Too many attempts. Please wait a few minutes and try again.', 429);
		$tn = self::clean_no($r->get_param('tn'));
		if ($tn !== '') {
			if (strlen($tn) < 6) return self::fail('That tracking number looks too short. Please check and try again.');
			return ['html' => self::render_tracking_html(null, [$tn], false)];
		}
		$num = preg_replace('/[^0-9]/', '', (string) $r->get_param('order'));
		$email = sanitize_email((string) $r->get_param('email'));
		if (!$num || !is_email($email)) return self::fail('Please enter your order number and a valid email address.');
		$order = wc_get_order((int) $num);
		if (!$order || $order instanceof WC_Order_Refund || strtolower($order->get_billing_email()) !== strtolower($email)) {
			return self::fail('We could not find an order with that number and email. Please check both and try again, or message us using the chat button.', 404);
		}
		return ['html' => self::render_tracking_html($order, array_column(self::order_tracking($order), 'no'), true)];
	}

	static function add_subscriber($email, $name = '', $source = 'form') {
		global $wpdb;
		$email = sanitize_email($email);
		if (!is_email($email)) return false;
		$t = $wpdb->prefix . 'genz_subscribers';
		$row = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM $t WHERE email=%s", $email));
		if ($row) {
			if ($row->status !== 'subscribed') $wpdb->update($t, ['status' => 'subscribed', 'source' => $source], ['id' => $row->id]);
			return 'exists';
		}
		$wpdb->insert($t, ['email' => $email, 'name' => sanitize_text_field($name), 'status' => 'subscribed', 'source' => $source, 'token' => wp_generate_password(32, false), 'created' => self::now()]);
		return 'new';
	}
	static function r_subscribe(WP_REST_Request $r) {
		if ($r->get_param('website')) return ['message' => 'Thanks for subscribing!'];
		if (!self::limit('sub' . self::ip(), 8, HOUR_IN_SECONDS)) return self::fail('Too many attempts. Please try again later.', 429);
		$email = sanitize_email((string) $r->get_param('email'));
		if (!is_email($email)) return self::fail('Please enter a valid email address.');
		$res = self::add_subscriber($email, '', 'website');
		if ($res === 'new') {
			self::mail($email, 'Welcome to GenZ Gadgets Store', 'You are on the list!', '<p>Thanks for subscribing. You will be the first to hear about new gadgets, gift ideas and exclusive deals.</p>' . self::btn(home_url('/shop/'), 'Shop the latest gadgets') . '<p style="font-size:12px;color:#777">Free UK delivery &middot; 30-day returns</p>');
		}
		return ['message' => $res === 'exists' ? 'You are already subscribed - thank you!' : 'Thanks for subscribing! Check your inbox for a welcome email.'];
	}

	/* ------------------------------------------------------------- magic */
	static function magic_form() {
		if (is_user_logged_in()) return;
		echo '<div class="genz-card genz-magic"><h2 class="genz-h">Sign in with your email</h2><p class="genz-muted">No password needed. Enter the email you used to order and we will send you a secure sign-in link to see your orders and tracking.</p><form novalidate><label>Email address<input type="email" name="email" autocomplete="email" required></label><button class="genz-btn" type="submit">Email me a sign-in link</button><p class="genz-msg" role="status" aria-live="polite"></p></form></div>';
	}
	static function r_magic(WP_REST_Request $r) {
		$email = sanitize_email((string) $r->get_param('email'));
		if (!is_email($email)) return self::fail('Please enter a valid email address.');
		if (!self::limit('ml' . self::ip(), 6, 15 * MINUTE_IN_SECONDS) || !self::limit('mle' . strtolower($email), 3, 15 * MINUTE_IN_SECONDS)) {
			return self::fail('Too many requests. Please wait a few minutes and try again.', 429);
		}
		$generic = ['message' => 'If we have orders or an account for that email, a sign-in link is on its way. It is valid for 20 minutes - please check your spam folder too.'];
		$user = get_user_by('email', $email);
		if ($user && user_can($user, 'edit_posts')) return $generic; // staff must use their password
		$has = $user ? true : (bool) wc_get_orders(['billing_email' => $email, 'limit' => 1, 'return' => 'ids', 'type' => 'shop_order']);
		if (!$has) return $generic;
		$tok = wp_generate_password(40, false);
		set_transient('genz_ml_' . hash('sha256', $tok), strtolower($email), 20 * MINUTE_IN_SECONDS);
		$url = add_query_arg('genz_ml', $tok, wc_get_page_permalink('myaccount'));
		self::mail($email, 'Your GenZ Gadgets sign-in link', 'Sign in to your account', '<p>Hi,</p><p>Tap the button below to sign in and see your orders, tracking updates and messages. This link works once and expires in 20 minutes.</p>' . self::btn($url, 'Sign in to my account') . '<p style="font-size:12px;color:#777">If you did not ask for this, you can ignore this email.</p>');
		return $generic;
	}

	/* ------------------------------------------------ Luxe Light skin + home */
	static function skin() { return get_option('genz_skin', 'classic'); }
	static function skin_css() {
		return <<<'CSS'
:root,body{--wp--preset--color--base:#FBF8F4!important;--wp--preset--color--contrast:#1E1A24!important;--wp--preset--color--accent-1:#5B2A86!important;--wp--preset--color--accent-2:#7B45B5!important;--wp--preset--color--accent-3:#C9A35B!important;--wp--preset--color--accent-4:#5E566A!important;--wp--preset--color--accent-5:#FFFFFF!important;--wp--preset--color--accent-6:#EFE7F7!important}
html body{background:#FBF8F4!important;color:#1E1A24!important;font-family:'Plus Jakarta Sans','Manrope',system-ui,sans-serif}
html body .wp-site-blocks{background:radial-gradient(60% 30% at 90% 0%,#EFE3FB 0%,rgba(251,248,244,0) 60%)}
html body h1,html body h2,html body h3,html body h4,html body .wp-block-post-title,html body .wp-block-query-title{color:#1E1A24!important}
html body h1,html body h2,html body .wp-block-query-title{font-family:'Bodoni Moda',Georgia,serif!important;font-weight:600!important;letter-spacing:-.01em}
html body a{color:inherit}html body a:hover{color:#5B2A86}
html body main p,html body main li{color:#3B3546}
html body .wp-element-button,html body .wp-block-button__link,html body button.single_add_to_cart_button,html body .add_to_cart_button,html body .checkout-button,html body .wc-block-components-button:not(.is-link){background:linear-gradient(180deg,#7B45B5,#5B2A86)!important;color:#fff!important;border:0!important;border-radius:12px!important;box-shadow:0 10px 24px rgba(91,42,134,.25)}
html body ins .woocommerce-Price-amount,html body .price ins,html body .wc-block-components-product-price ins,html body .wc-block-components-product-price__value.is-discounted{color:#5B2A86!important}
html body del,html body .wc-block-components-product-price del{color:#9A93A3!important}
html body .genz-marquee{background:linear-gradient(90deg,#F6E3B8,#F1D9A7)!important;color:#1A1206!important}
html body .genz-marquee *{color:#1A1206!important}
html body header.wp-block-template-part{position:relative;z-index:50;background:rgba(255,255,255,.92);backdrop-filter:blur(12px);border-bottom:1px solid #EDE5F3;box-shadow:0 8px 30px rgba(70,30,110,.06)}
html body header.wp-block-template-part *{color:#1E1A24}
html body .genz-header,html body header .genz-header{background:rgba(255,255,255,.94)!important;backdrop-filter:blur(12px)}
html body .genz-header .wp-block-navigation-item__content,html body .genz-header .wp-block-navigation-item__label,html body .genz-header .wp-block-navigation__submenu-icon,html body .genz-header svg{color:#1E1A24!important;fill:currentColor}
html body .genz-header .wp-block-navigation-item__content:hover{color:#5B2A86!important}
html body .genz-header .wp-block-navigation__submenu-container .wp-block-navigation-item__content{color:#1E1A24!important}
html body .genz-header .wp-block-navigation__responsive-container-open{color:#1E1A24!important}
html body header.wp-block-template-part .wp-block-navigation__submenu-container{background:#fff!important;border:1px solid #EDE5F3!important;border-radius:14px!important;box-shadow:0 20px 50px rgba(70,30,110,.15)!important}
html body header.wp-block-template-part .wp-block-navigation__submenu-container a:hover{background:#F4EEF9!important;color:#5B2A86!important}
html body header.wp-block-template-part .wp-block-site-logo{background:linear-gradient(135deg,#2A1840,#120A1E);border-radius:14px;padding:6px 12px;box-shadow:0 8px 20px rgba(42,24,64,.25)}
html body header.wp-block-template-part .wp-block-navigation__responsive-container.is-menu-open{background:#FBF8F4!important}
html body .wp-block-search__input{background:#fff!important;color:#1E1A24!important;border:1px solid rgba(91,42,134,.2)!important;border-radius:12px!important}
html body .wp-block-search__button{background:#5B2A86!important;color:#fff!important;border-radius:12px!important}
html body .wp-block-search__button svg{fill:#fff!important}
html body .wc-block-mini-cart__badge{background:#C9A35B!important;color:#1A1206!important}
html body main .wc-block-product,html body main li.product,html body main .wc-block-grid__product{background:#fff;border:1px solid rgba(91,42,134,.10);border-radius:20px;padding:12px;box-shadow:0 14px 34px rgba(70,30,110,.08);transition:transform .45s cubic-bezier(.2,.8,.2,1),box-shadow .45s}
html body main .wc-block-product:hover,html body main li.product:hover,html body main .wc-block-grid__product:hover{transform:perspective(900px) rotateX(3deg) rotateY(-5deg) translateY(-6px);box-shadow:0 26px 54px rgba(70,30,110,.16),0 0 0 1px rgba(201,163,91,.5)}
html body main .wc-block-components-product-image img,html body main li.product img{border-radius:14px}
html body .wc-block-components-product-sale-badge,html body .onsale{background:#F1D9A7!important;color:#1A1206!important;border:0!important;border-radius:999px!important;font-weight:800!important}
html body .woocommerce-breadcrumb,html body .wc-block-breadcrumbs{color:#5E566A!important}
html body .wc-block-components-panel,html body .wp-block-woocommerce-cart,html body .wp-block-woocommerce-checkout{color:#1E1A24}
html body input:not([type=checkbox]):not([type=radio]),html body select,html body textarea{background:#fff;color:#1E1A24}
html body .genz-sub-band{background:linear-gradient(135deg,#2A1840,#120A1E)!important}
html body footer.wp-block-template-part{background:linear-gradient(180deg,#2A1840,#140B22)!important}
html body footer.wp-block-template-part,html body footer.wp-block-template-part *{color:#EDE6F6}
html body footer.wp-block-template-part a:hover{color:#F1D9A7!important}
html body footer.wp-block-template-part h2,html body footer.wp-block-template-part h3,html body footer.wp-block-template-part h4{color:#F1D9A7!important;font-family:'Plus Jakarta Sans',sans-serif!important;font-size:13px!important;letter-spacing:.14em!important;text-transform:uppercase!important}
html body .genz-guides-home a.gg-all{color:#5B2A86!important;font-weight:700}
@media(max-width:640px){html body #genz-proof{left:10px!important;right:auto!important;bottom:86px!important;max-width:230px!important;padding:8px 10px!important;font-size:11px!important;transform:scale(.92);transform-origin:bottom left}html body #genz-proof img{display:none!important}}
html body .genz-guides-home > p,html body .genz-guides-home > .wp-block-group > p,html body .genz-guides-home p:not([class]){color:#5E566A!important}
html body .genz-card,html body .genz-chat-panel{color:#1E1A24}
CSS;
	}
	static function skin_head() {
		if (is_admin() || self::skin() !== 'luxe') return;
		echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
		echo '<style id="genz-luxe-skin">' . self::skin_css() . '</style>'; // phpcs:ignore
	}
	static function cat_products($slug, $limit = 8) {
		$args = ['status' => 'publish', 'limit' => $limit, 'stock_status' => 'instock', 'visibility' => 'catalog', 'orderby' => 'meta_value_num', 'meta_key' => 'total_sales', 'order' => 'DESC'];
		if ($slug) $args['category'] = [$slug];
		$out = [];
		foreach (wc_get_products($args) as $p) {
			if (!$p->get_image_id()) continue;
			$reg = (float) $p->get_regular_price(); $now = (float) wc_get_price_to_display($p);
			$out[] = ['id' => $p->get_id(), 'n' => $p->get_name(), 'u' => get_permalink($p->get_id()),
				'img' => wp_get_attachment_image_url($p->get_image_id(), 'woocommerce_thumbnail') ?: wp_get_attachment_image_url($p->get_image_id(), 'medium'),
				'big' => wp_get_attachment_image_url($p->get_image_id(), 'woocommerce_single'),
				'num' => $now, 'now' => wc_price($now), 'was' => ($p->is_on_sale() && $reg > $now) ? wc_price($reg) : '',
				'off' => ($p->is_on_sale() && $reg > $now) ? (int) round(100 - $now / $reg * 100) : 0,
				'rt' => (float) $p->get_average_rating(), 'rc' => (int) $p->get_review_count(),
				'cart' => $p->is_type('simple') ? add_query_arg('add-to-cart', $p->get_id(), wc_get_cart_url()) : get_permalink($p->get_id())];
		}
		return $out;
	}
	static function fan_html($ps, $cls = 'fan') {
		$h = '<span class="' . $cls . '" aria-hidden="true">';
		foreach (array_slice($ps, 0, 3) as $i => $p) $h .= '<img class="f' . $i . '" src="' . esc_url($p['img']) . '" alt="" loading="lazy">';
		return $h . '</span>';
	}
	static function card_html($p) {
		$h = '<article class="pc"><a class="im" href="' . esc_url($p['u']) . '"><img src="' . esc_url($p['img']) . '" alt="' . esc_attr($p['n']) . '" loading="lazy">' . ($p['off'] ? '<span class="off">−' . (int) $p['off'] . '%</span>' : '') . '</a>';
		$h .= '<a class="nm" href="' . esc_url($p['u']) . '">' . esc_html($p['n']) . '</a>';
		if ($p['rt']) $h .= '<div class="st">' . esc_html(self::preview_stars($p['rt'])) . ' <span>(' . (int) $p['rc'] . ')</span></div>';
		$h .= '<div class="row"><span class="price">' . wp_kses_post($p['now']) . ($p['was'] ? '<del>' . wp_kses_post($p['was']) . '</del>' : '') . '</span><a class="add" href="' . esc_url($p['cart']) . '" rel="nofollow">Add to bag</a></div></article>';
		return $h;
	}
	static function cat_banners() {
		return [
			'christmas-gifts' => ['Christmas 2026', 'The Christmas Gift Shop', 'Gifts they will actually use. Stocking fillers from £5, free UK delivery and 30-day returns.', '#0F3B2E', '#1D6B4F'],
			'christmas-new-year-sale' => ['Festive sale', 'Christmas & New Year Sale', 'Festive prices on gadgets and gifts, delivered free across the UK.', '#5B2A86', '#0F3B2E'],
			'stocking-fillers' => ['Under £10', 'Stocking Fillers', 'Small, clever gifts that fit any stocking. Free UK delivery.', '#7A1F2B', '#B23A48'],
			'black-friday-deals' => ['Black Friday', 'Black Friday Deals', 'Our biggest savings of the year, for a limited time.', '#1E1A24', '#5B2A86'],
			'clearance-sale' => ['Clearance', 'Clearance Sale', '30% off and more while stock lasts. When it is gone, it is gone.', '#7A1F2B', '#C9A35B'],
			'new-year-sale' => ['New Year 2027', 'New Year, New You Sale', 'Get organised, get fit, sleep better. Fresh-start deals for January.', '#C9A35B', '#5B2A86'],
		];
	}
	static function cat_banner_html() {
		if (!function_exists('is_product_category') || !is_product_category()) return '';
		$t = get_queried_object(); $m = self::cat_banners();
		if (!$t || empty($m[$t->slug])) return '';
		list($k, $h, $d, $c1, $c2) = $m[$t->slug];
		$ps = self::cat_products($t->slug, 3);
		$bg = $ps ? ', url(' . esc_url($ps[0]['big'] ?: $ps[0]['img']) . ') right center/cover' : '';
		return '<div class="genz-cat-hero" style="background:linear-gradient(100deg,' . $c1 . ' 40%,' . $c2 . 'E0 65%,' . $c2 . '80)' . $bg . '">' . self::fan_html($ps, 'gch-fan') . '<span class="gch-k">' . esc_html($k) . '</span><div class="gch-h">' . esc_html($h) . '</div><p>' . esc_html($d) . '</p><span class="gch-s">Free UK delivery · 30-day returns · 20% off 2+ with BUNDLE20</span></div>'
			. '<style>.wp-block-term-description:has(.genz-cat-hero){max-width:none!important;margin-left:0!important;margin-right:0!important}.genz-cat-hero{max-width:none!important;width:100%;box-sizing:border-box;border-radius:24px;padding:34px 38px;margin:6px 0 22px;color:#fff;box-shadow:0 24px 50px -28px rgba(30,26,36,.55);position:relative;overflow:hidden}.genz-cat-hero:after{content:"";position:absolute;right:-60px;top:-60px;width:240px;height:240px;border-radius:50%;background:radial-gradient(circle,rgba(241,217,167,.35),transparent 70%)}.genz-cat-hero .gch-k{font:700 12px/1 "Plus Jakarta Sans",sans-serif;letter-spacing:.18em;text-transform:uppercase;color:#F1D9A7}.genz-cat-hero .gch-h{font-family:"Bodoni Moda",serif;font-size:clamp(28px,4vw,44px);line-height:1.1;margin:10px 0 8px}.genz-cat-hero p{margin:0 0 12px;max-width:620px;opacity:.92;color:#fff!important}.genz-cat-hero .gch-s{font-size:13px;opacity:.85}.genz-cat-hero>*:not(.gch-fan){position:relative;z-index:2}.genz-cat-hero{min-height:230px}.gch-fan{position:absolute;right:30px;top:18px;bottom:18px;width:38%;z-index:1}.gch-fan img{position:absolute;height:82%;aspect-ratio:1;object-fit:cover;border-radius:18px;border:3px solid rgba(255,255,255,.92);box-shadow:0 20px 36px rgba(0,0,0,.35);transition:transform .6s cubic-bezier(.2,.8,.2,1)}.gch-fan .f0{right:24%;top:0;z-index:3;transform:rotate(-4deg)}.gch-fan .f1{right:52%;top:14%;height:70%;transform:rotate(-12deg)}.gch-fan .f2{right:0;top:18%;height:70%;transform:rotate(11deg)}.genz-cat-hero:hover .gch-fan .f0{transform:rotate(0) translateY(-6px) scale(1.04)}@media(max-width:640px){.genz-cat-hero{padding:24px 22px;min-height:0}.gch-fan{display:none}}</style>';
	}
	static function cat_seo() {
		return [
			'black-friday-deals' => ['Black Friday Gadget Deals UK 2026 – Up to 40% Off | GenZ Gadgets', 'Black Friday 2026 gadget and gift deals in the UK: up to 40% off smart tech, kitchen helpers and winter warmers. Free UK delivery and 30-day returns.'],
			'clearance-sale' => ['Clearance Sale – Gadgets & Gifts 30% Off+ | GenZ Gadgets UK', 'Clearance gadgets and gifts at 30% off or more while stock lasts. Free UK delivery from Nottingham and easy 30-day returns.'],
			'new-year-sale' => ['New Year Sale 2027 – Home, Fitness & Organising Gadgets | GenZ Gadgets', 'New Year sale on storage, cleaning, kitchen, fitness and travel gadgets. Buy 2 and save 20% with BUNDLE20. Free UK delivery.'],
		];
	}
	static function cat_seo_pick($i, $v) {
		if (!function_exists('is_product_category') || !is_product_category()) return $v;
		$t = get_queried_object(); $m = self::cat_seo();
		return ($t && isset($m[$t->slug])) ? $m[$t->slug][$i] : $v;
	}
	static function cat_seo_title($v) { return self::cat_seo_pick(0, $v); }
	static function cat_seo_desc($v) { return self::cat_seo_pick(1, $v); }
	static function cat_banner_block($html, $block) {
		static $done = false;
		if ($done || ($block['blockName'] ?? '') !== 'core/term-description') return $html;
		$b = self::cat_banner_html(); if (!$b) return $html;
		$done = true; return $b . $html;
	}
	static function cat_banner_classic() { if (!wp_is_block_theme()) echo self::cat_banner_html(); }
	static function term_url($slug) { $t = get_term_by('slug', $slug, 'product_cat'); return $t && !is_wp_error(get_term_link($t)) ? get_term_link($t) : home_url('/shop/'); }
	static function sc_home() {
		$t0 = microtime(true);
		$cached = get_transient('genz_home_html');
		if ($cached && !current_user_can('manage_woocommerce')) return $cached;
		$all = self::cat_products('', 40);
		$hero = [];
		foreach ($all as $p) { if ($p['num'] >= 25) $hero[] = $p; if (count($hero) >= 3) break; }
		if (count($hero) < 3) $hero = array_slice($all, 0, 3);
		$top_off = 0; foreach ($all as $p) $top_off = max($top_off, $p['off']);
		global $wpdb;
		$rev_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} c JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE p.post_type='product' AND c.comment_approved='1' AND c.comment_type='review'");
		$end = strtotime('2026-12-01 00:00:00 Europe/London');
		ob_start(); ?>
<div class="gx gx-home">
<section class="hero">
<div>
<span class="chip">TRENDING GADGETS &amp; GIFTS · UK</span>
<h1>Gadgets &amp; gifts that <span class="grad">wow</span> this Christmas.</h1>
<p class="lead">Hand-picked tech and clever gadgets, gift-ready from Nottingham and delivered free across the UK.</p>
<a class="btn btn-p" href="<?php echo esc_url(self::term_url('black-friday-deals')); ?>">Shop Black Friday</a> <a class="btn btn-o" href="<?php echo esc_url(self::term_url('christmas-gifts')); ?>">Christmas gift shop</a>
<div class="proof"><span><b><?php echo esc_html(number_format($rev_count)); ?>+</b> customer reviews</span><span><b>Free</b> UK delivery</span><span><b>30-day</b> returns</span></div>
</div>
<div class="stage" aria-label="Featured products">
<div class="glow"></div><div class="pod"></div>
<?php $pos = [['c1', 'left:28%;top:20px;width:290px;z-index:2;transform:perspective(900px) rotateY(-12deg) rotateX(5deg)'], ['c2', 'left:2%;top:190px;width:200px;transform:perspective(900px) rotateY(16deg) rotateX(4deg)'], ['c3', 'right:0;top:210px;width:200px;transform:perspective(900px) rotateY(-16deg) rotateX(4deg)']];
foreach ($hero as $i => $p) { if (!isset($pos[$i])) break; echo '<a class="card3 ' . $pos[$i][0] . '" href="' . esc_url($p['u']) . '" style="' . esc_attr($pos[$i][1]) . '"><img src="' . esc_url($p['big'] ?: $p['img']) . '" alt="' . esc_attr($p['n']) . '"><div class="cap"><b>' . esc_html($p['n']) . '</b><span class="price">' . wp_kses_post($p['now']) . ($p['was'] ? '<del>' . wp_kses_post($p['was']) . '</del>' : '') . '</span></div></a>'; }
if ($top_off) echo '<div class="seal"><small>UP TO</small><span>' . (int) $top_off . '%</span><small>OFF</small></div>'; ?>
</div>
</section>
<section class="cd" aria-label="Countdown"><div><b style="font-size:18px">Black Friday prices end Cyber Monday</b><div class="mut" style="font-size:14px">Order today, dispatched from Nottingham</div></div>
<div class="t" data-end="<?php echo (int) $end; ?>"><div><b data-u="d">--</b><small>DAYS</small></div><div><b data-u="h">--</b><small>HRS</small></div><div><b data-u="m">--</b><small>MINS</small></div><div><b data-u="s">--</b><small>SECS</small></div></div>
<a class="btn btn-p" href="<?php echo esc_url(self::term_url('black-friday-deals')); ?>">Grab the deals</a></section>
<section class="sales rv" aria-label="Sales"><?php
		$sales = [
			['black-friday-deals', 'BLACK FRIDAY', 'Up to ' . (int) max(30, $top_off) . '% off', 'Shop deals →', '#2A1840', '#120A1E', '#F1D9A7', '#fff'],
			['christmas-gifts', 'CHRISTMAS SALE', 'Gifts from £5', 'Christmas gift shop →', '#1E4A36', '#0B2318', '#BFE7D0', '#F2FAF5'],
			['new-year-sale', 'NEW YEAR SALE', '20% off 2+ items', 'Code BUNDLE20 →', '#E9C97F', '#B8862F', '#1A1206', '#1A1206'],
			['clearance-sale', 'CLEARANCE', 'Final stock', '30% off and more →', '#6E1A28', '#2B070E', '#FFC2CB', '#FFEFF1'],
		];
		foreach ($sales as $sl) {
			$ps = self::cat_products($sl[0], 3);
			$bg = $ps ? ', url(' . esc_url($ps[0]['big'] ?: $ps[0]['img']) . ') right center/cover' : '';
			echo '<a class="sale" href="' . esc_url(self::term_url($sl[0])) . '" style="color:' . esc_attr($sl[7]) . ';background:linear-gradient(100deg,' . $sl[4] . ' 38%,' . $sl[5] . 'E6 62%,' . $sl[5] . '99)' . $bg . '">' . self::fan_html($ps) . '<small style="color:' . esc_attr($sl[6]) . '">' . esc_html($sl[1]) . '</small><strong>' . esc_html($sl[2]) . '</strong><span>' . esc_html($sl[3]) . '</span></a>';
		} ?>
</section>
<section class="tick rv" aria-label="Trending now"><div class="row" style="margin-bottom:12px"><h2>Trending in the UK <em>right now</em></h2><a class="more" href="<?php echo esc_url(home_url('/shop/')); ?>">See all →</a></div>
<div class="tk"><div class="tkr"><?php $tr = array_slice($all, 3, 14); foreach (array_merge($tr, $tr) as $p) echo '<a class="ti" href="' . esc_url($p['u']) . '"><img src="' . esc_url($p['img']) . '" alt="' . esc_attr($p['n']) . '" loading="lazy"><span><b>' . esc_html(wp_trim_words($p['n'], 5, '…')) . '</b><i>' . wp_kses_post($p['now']) . '</i></span></a>'; ?></div></div></section>
<section class="rv" style="margin:34px 0 10px"><div class="row" style="align-items:end;margin-bottom:16px"><h2>Shop by <em>category</em></h2><a class="more" href="<?php echo esc_url(home_url('/shop/')); ?>">All products →</a></div>
<div class="cats"><?php
		$cats = [['christmas-gifts', 'Christmas Gift Shop'], ['black-friday-deals', 'Black Friday Deals'], ['gifts-for-him', 'Gifts for Him'], ['gifts-for-her', 'Gifts for Her'], ['gifts-for-teens', 'Gifts for Teens'], ['stocking-fillers', 'Stocking Fillers'], ['tech-gadgets', 'Smart Tech'], ['tech-gaming-picks', 'Gaming'], ['winter-warmers-cosy-gifts', 'Winter Warmers'], ['home-kitchen', 'Home &amp; Kitchen'], ['kitchen-tools-utensils', 'Kitchen Tools'], ['home-living', 'Home &amp; Living'], ['lights-cosy-home', 'Lights &amp; Cosy'], ['health-beauty', 'Beauty &amp; Skincare'], ['health-wellness', 'Health &amp; Wellness'], ['mens-grooming', "Men's Grooming"], ['car-accessories', 'Car Accessories'], ['bags-travel', 'Bags &amp; Travel'], ['fashion-accessories', 'Fashion'], ['pet-essentials', 'Pets'], ['new-year-sale', 'New Year Sale'], ['clearance-sale', 'Clearance'], ['gifts-under-10', 'Gifts under £10'], ['gifts-under-25', 'Gifts under £25']];
		foreach ($cats as $c) {
			$t = get_term_by('slug', $c[0], 'product_cat');
			if (!$t || !$t->count) continue;
			$img = '';
			$tid = (int) get_term_meta($t->term_id, 'thumbnail_id', true);
			if ($tid) $img = wp_get_attachment_image_url($tid, 'woocommerce_thumbnail');
			if (!$img) { $pp = self::cat_products($c[0], 1); if ($pp) $img = $pp[0]['img']; }
			echo '<a class="cat" href="' . esc_url(get_term_link($t)) . '"><span class="ci">' . ($img ? '<img src="' . esc_url($img) . '" alt="" loading="lazy">' : '') . '</span><b>' . wp_kses_post($c[1]) . '</b><small>' . (int) $t->count . ' products</small></a>';
		} ?></div></section>
<?php
		$rails = [
			['christmas-gifts', 'The Christmas Gift Shop', 'Gifts they will actually use, delivered free across the UK.', 'linear-gradient(135deg,#1E4A36,#0B2318)', '#BFE7D0', 'CHRISTMAS'],
			['black-friday-deals', 'Black Friday deals', 'Our biggest savings of the year, for a limited time.', 'linear-gradient(135deg,#2A1840,#120A1E)', '#F1D9A7', 'BLACK FRIDAY'],
			['gifts-for-him', 'Gifts for him', 'Dads, brothers, boyfriends and the man who has everything.', 'linear-gradient(135deg,#14213D,#0A1226)', '#D8C08A', 'FOR HIM'],
			['gifts-for-her', 'Gifts for her', 'Thoughtful beauty, cosy and clever picks for her.', 'linear-gradient(135deg,#7A2E58,#3B1029)', '#F6C9DE', 'FOR HER'],
			['stocking-fillers', 'Stocking fillers', 'Little gifts, big smiles, from £5.', 'linear-gradient(135deg,#8E2F22,#4A1410)', '#F6C9BE', 'STOCKING FILLERS'],
			['tech-gadgets', 'Smart tech &amp; gadgets', 'Projectors, consoles and clever tech picks.', 'linear-gradient(135deg,#2A1840,#0E2A3B)', '#B9E3F5', 'TECH'],
			['winter-warmers-cosy-gifts', 'Winter warmers', 'Heated gilets, slippers and hand warmers for UK winters.', 'linear-gradient(135deg,#7A3B12,#3A1606)', '#F8D6B5', 'STAY WARM'],
			['new-year-sale', 'New Year Sale', 'Get organised, get fit, travel lighter. Extra 20% off 2+ items with BUNDLE20.', 'linear-gradient(135deg,#C89B4E,#8A6420)', '#1A1206', 'NEW YEAR'],
			['clearance-sale', 'Clearance', 'Final stock at 30% off and more. When it is gone, it is gone.', 'linear-gradient(135deg,#6E1A28,#2B070E)', '#FFC2CB', 'CLEARANCE'],
		];
		foreach ($rails as $r) {
			$ps = self::cat_products($r[0], 12);
			if (count($ps) < 2) continue;
			$mos = '<span class="mos" aria-hidden="true">'; foreach (array_slice($ps, 0, 4) as $p) $mos .= '<img src="' . esc_url($p['img']) . '" alt="" loading="lazy">'; $mos .= '</span>';
			echo '<section class="rail rv"><a class="railhead" href="' . esc_url(self::term_url($r[0])) . '" style="background:' . esc_attr($r[3]) . '">' . $mos . '<span class="rh-t"><small style="color:' . esc_attr($r[4]) . '">' . esc_html($r[5]) . '</small><strong>' . wp_kses_post($r[1]) . '</strong><span>' . esc_html($r[2]) . '</span><em>Shop the collection →</em></span></a><div class="swipe"><button class="sw sw-l" type="button" aria-label="Previous">‹</button><div class="track">';
			foreach ($ps as $p) echo self::card_html($p); // phpcs:ignore
			echo '</div><button class="sw sw-r" type="button" aria-label="Next">›</button></div></section>';
		}
		?>
<section class="trust rv"><div><b>Free UK delivery</b>Every order, no minimum</div><div><b>30-day returns</b>Free and easy</div><div><b>Gift-ready</b>Packed in Nottingham</div><div><b>Track &amp; chat</b>Live tracking and UK support</div></section>
</div>
<script>(function(){var t=document.querySelector('.gx-home .cd .t');if(!t)return;var end=+t.getAttribute('data-end')*1000;function u(){var d=Math.max(0,end-Date.now());var s=Math.floor(d/1000);var v={d:Math.floor(s/86400),h:Math.floor(s%86400/3600),m:Math.floor(s%3600/60),s:s%60};for(var k in v){var e=t.querySelector('[data-u='+k+']');if(e)e.textContent=String(v[k]).padStart(2,'0');}}u();setInterval(u,1000);})();
(function(){var h=document.querySelector('.gx-home');if(!h)return;var io='IntersectionObserver' in window?new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target);}});},{rootMargin:'0px 0px -60px 0px'}):null;h.querySelectorAll('.rv').forEach(function(el){io?io.observe(el):el.classList.add('in');});
h.querySelectorAll('.swipe').forEach(function(w){var t=w.querySelector('.track');function st(){w.classList.toggle('at-s',t.scrollLeft<8);w.classList.toggle('at-e',t.scrollLeft+t.clientWidth>=t.scrollWidth-8);}w.querySelector('.sw-l').onclick=function(){t.scrollBy({left:-t.clientWidth*.9,behavior:'smooth'});};w.querySelector('.sw-r').onclick=function(){t.scrollBy({left:t.clientWidth*.9,behavior:'smooth'});};t.addEventListener('scroll',st,{passive:true});st();
var d=false,mv=false,x0,s0;t.addEventListener('pointerdown',function(e){if(e.pointerType!=='mouse')return;d=true;mv=false;x0=e.clientX;s0=t.scrollLeft;});window.addEventListener('pointerup',function(){d=false;setTimeout(function(){t.classList.remove('drag');},0);});t.addEventListener('pointermove',function(e){if(d&&Math.abs(e.clientX-x0)>6){mv=true;t.classList.add('drag');t.scrollLeft=s0-(e.clientX-x0);}});t.addEventListener('click',function(e){if(mv){e.preventDefault();mv=false;}},true);});
var c=h.querySelector('.hero .stage');if(c&&matchMedia('(pointer:fine)').matches){c.addEventListener('mousemove',function(e){var r=c.getBoundingClientRect(),x=(e.clientX-r.left)/r.width-.5,y=(e.clientY-r.top)/r.height-.5;c.style.transform='rotateY('+(x*6)+'deg) rotateX('+(-y*5)+'deg)';});c.addEventListener('mouseleave',function(){c.style.transform='';});}})();</script>
<?php
		$html = '<style>' . self::home_css() . '</style>' . ob_get_clean();
		set_transient('genz_home_html', $html, 30 * MINUTE_IN_SECONDS);
		return $html;
	}
	static function home_css() {
		return <<<'CSS'
.gx{--ink:#1E1A24;--mut:#5E566A;--plum:#5B2A86;--plum2:#7B45B5;--gold2:#F1D9A7;font-family:'Plus Jakarta Sans',system-ui,sans-serif;color:var(--ink)}
.gx-home{width:min(1320px,calc(100vw - 32px))!important;max-width:none!important;margin-left:calc(50% - min(660px,calc(50vw - 16px)))!important;margin-right:0!important}
.gx *{box-sizing:border-box}.gx a{text-decoration:none!important}.gx .mut{color:var(--mut)}
.gx .hero{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,500px),1fr));gap:30px;align-items:center;padding:18px 0 6px}
.gx .chip{display:inline-block;background:#FFF6E2;border:1px solid rgba(201,163,91,.55);color:#8A5A12;font-size:12px;font-weight:800;letter-spacing:.16em;padding:8px 14px;border-radius:999px}
.gx h1{font-family:'Bodoni Moda',Georgia,serif!important;font-size:clamp(42px,5.6vw,74px)!important;line-height:1!important;font-weight:600!important;margin:16px 0!important;color:var(--ink)!important}
.gx h2{font-family:'Bodoni Moda',Georgia,serif!important;font-size:clamp(28px,3.6vw,42px)!important;font-weight:600!important;margin:0!important;color:var(--ink)!important}
.gx .grad{background:linear-gradient(90deg,#5B2A86,#9B59D0,#B8862F);-webkit-background-clip:text;background-clip:text;color:transparent}
.gx .lead{font-size:18px;line-height:1.6;color:var(--mut)!important;max-width:500px;margin:0 0 22px}
.gx .btn{display:inline-flex;align-items:center;justify-content:center;padding:16px 26px;border-radius:14px;font-weight:800;transition:transform .2s,filter .2s;margin:0 6px 8px 0}
.gx .btn:hover{transform:translateY(-2px);filter:brightness(1.06)}
.gx .btn-p{background:linear-gradient(180deg,var(--plum2),var(--plum));color:#fff!important;box-shadow:0 14px 30px rgba(91,42,134,.3)}
.gx .btn-o{background:#fff;border:1px solid rgba(91,42,134,.2);color:var(--ink)!important}
.gx .proof{display:flex;flex-wrap:wrap;gap:20px;font-size:14px;color:var(--mut);margin-top:14px}.gx .proof b{color:var(--plum)}
.gx .stage{position:relative;height:520px;perspective:1300px}
.gx .glow{position:absolute;left:12%;right:12%;bottom:30px;height:170px;border-radius:50%;background:radial-gradient(closest-side,rgba(201,163,91,.35),transparent);filter:blur(8px)}
.gx .pod{position:absolute;left:16%;right:16%;bottom:62px;height:220px;border-radius:50%;background:linear-gradient(180deg,#fff,#E9DEF5);box-shadow:inset 0 2px 0 #fff,0 30px 60px rgba(70,30,110,.2);transform:rotateX(60deg);animation:gzfl 6s ease-in-out infinite}
@keyframes gzfl{0%,100%{transform:rotateX(60deg) translateY(0)}50%{transform:rotateX(60deg) translateY(-12px)}}
.gx .card3{position:absolute;border-radius:24px;background:#fff;border:1px solid rgba(201,163,91,.45);box-shadow:0 30px 70px rgba(70,30,110,.18);overflow:hidden;transition:transform .5s cubic-bezier(.2,.8,.2,1),box-shadow .5s;display:block;color:var(--ink)!important}
.gx .card3:hover{transform:perspective(900px) translateY(-10px) scale(1.03)!important;box-shadow:0 40px 90px rgba(70,30,110,.25)}
.gx .card3 img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block}
.gx .card3 .cap{padding:12px 14px}.gx .card3 .cap b{display:block;font-size:14px;line-height:1.3;max-height:2.6em;overflow:hidden}
.gx .price{font-weight:800;color:var(--plum)}.gx .price del{color:#9A93A3;font-weight:500;font-size:.8em;margin-left:6px}
.gx .seal{position:absolute;right:6%;top:24px;width:112px;height:112px;border-radius:50%;background:var(--gold2);color:#1A1206;display:flex;flex-direction:column;align-items:center;justify-content:center;font-weight:800;box-shadow:0 20px 40px rgba(201,163,91,.35);transform:rotate(-10deg);z-index:3}
.gx .seal span{font-size:30px;line-height:1}.gx .seal small{font-size:11px}
.gx .cd{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:18px;border-radius:22px;padding:22px 28px;background:linear-gradient(90deg,#FFF6E2,#fff);border:1px solid rgba(201,163,91,.45);margin:14px 0}
.gx .cd .t{display:flex;gap:10px}.gx .cd .t div{background:#fff;border:1px solid #E6DDF0;border-radius:12px;padding:10px 14px;text-align:center;min-width:64px}
.gx .cd .t b{display:block;font-size:26px;color:var(--plum)}.gx .cd .t small{font-size:11px;color:var(--mut);font-weight:700}
.gx .sales{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:16px;margin:16px 0}
.gx .sale{position:relative;overflow:hidden;border-radius:22px;padding:26px;min-height:180px;display:flex;flex-direction:column;justify-content:flex-end;gap:6px;box-shadow:0 20px 40px rgba(70,30,110,.12);transition:transform .4s}
.gx .sale:hover{transform:perspective(900px) rotateX(4deg) rotateY(-6deg) translateY(-6px)}
.gx .sale small{font-size:12px;font-weight:800;letter-spacing:.18em}.gx .sale strong{font-family:'Bodoni Moda',serif;font-size:30px;font-weight:600;line-height:1.1}
.gx .row{display:flex;justify-content:space-between;align-items:center;gap:8px}
.gx .more{color:var(--plum)!important;font-weight:700}
.gx .cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
.gx .cat{display:flex;flex-direction:column;align-items:center;text-align:center;gap:6px;background:#fff;border:1px solid rgba(91,42,134,.1);border-radius:18px;padding:12px 10px 14px;color:var(--ink)!important;transition:transform .35s,box-shadow .35s}
.gx .cat:hover{transform:translateY(-4px);box-shadow:0 18px 40px rgba(70,30,110,.14);border-color:rgba(201,163,91,.55)}
.gx .cat .ci{width:84px;height:84px;border-radius:50%;overflow:hidden;background:#F4EEF9;box-shadow:0 0 0 3px #fff,0 0 0 4px rgba(201,163,91,.5)}
.gx .cat img{width:100%;height:100%;object-fit:cover}.gx .cat b{font-size:13px;line-height:1.25}.gx .cat small{font-size:11px;color:var(--mut)}
.gx .rail{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,3fr);gap:16px;margin:30px 0}
.gx .railhead{border-radius:22px;padding:26px;display:flex;flex-direction:column;justify-content:flex-end;gap:8px;color:#fff!important;min-height:260px;box-shadow:0 20px 40px rgba(70,30,110,.14)}
.gx .railhead small{font-size:12px;font-weight:800;letter-spacing:.18em}.gx .railhead strong{font-family:'Bodoni Moda',serif;font-size:30px;font-weight:600;line-height:1.1}
.gx .railhead span{opacity:.88;font-size:14px}.gx .railhead em{font-style:normal;font-weight:800;margin-top:6px}
.gx .grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.gx .pc{border-radius:20px;padding:12px;background:#fff;border:1px solid rgba(91,42,134,.1);box-shadow:0 14px 32px rgba(70,30,110,.08);display:flex;flex-direction:column;gap:8px;transition:transform .5s cubic-bezier(.2,.8,.2,1),box-shadow .5s}
.gx .pc:hover{transform:perspective(900px) rotateX(4deg) rotateY(-6deg) translateY(-8px);box-shadow:0 30px 60px rgba(70,30,110,.18),0 0 0 1px rgba(201,163,91,.5)}
.gx .pc .im{position:relative;border-radius:14px;overflow:hidden;background:#F4EEF9;display:block}
.gx .pc img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;transition:transform .6s}.gx .pc:hover img{transform:scale(1.06)}
.gx .off{position:absolute;top:10px;left:10px;background:var(--gold2);color:#1A1206;font-size:11px;font-weight:800;padding:5px 10px;border-radius:999px}
.gx .pc .nm{font-weight:700;line-height:1.35;font-size:14px;color:var(--ink)!important;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.7em}
.gx .st{color:#B8862F;font-size:12px}.gx .st span{color:var(--mut)}
.gx .add{background:linear-gradient(180deg,var(--plum2),var(--plum));color:#fff!important;font-weight:800;font-size:12px;padding:10px 12px;border-radius:12px;white-space:nowrap}
.gx .trust{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:14px;margin:20px 0 30px}
.gx .trust div{border-radius:18px;padding:18px;background:#fff;border:1px solid rgba(91,42,134,.08);color:var(--mut);font-size:14px!important;line-height:1.5}.gx .trust b{font-size:16px}
.gx .pc .row{flex-direction:column;align-items:stretch;gap:8px}.gx .add{text-align:center}.gx .pc .price{font-size:16px}.gx .trust b{color:var(--plum);display:block}
@media(max-width:900px){.gx .rail{grid-template-columns:1fr}.gx .railhead{min-height:150px}.gx .grid4{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:760px){
.gx .hero{gap:10px;padding-top:6px}.gx h1{font-size:40px!important}.gx .lead{font-size:16px}.gx .btn{padding:14px 18px}
.gx .stage{height:380px}
.gx .card3.c1{left:calc(50% - 100px)!important;top:0!important;width:200px!important}
.gx .card3.c2{left:0!important;top:150px!important;width:130px!important}
.gx .card3.c3{right:0!important;left:auto!important;top:170px!important;width:130px!important}
.gx .card3 .cap{padding:8px 10px}.gx .card3 .cap b{font-size:12px}
.gx .seal{width:78px;height:78px;right:0;top:0}.gx .seal span{font-size:22px}
.gx .pod{left:6%;right:6%;height:150px;bottom:40px}
.gx .cd{padding:16px;gap:12px}.gx .cd .t div{min-width:0;padding:8px 10px}.gx .cd .t b{font-size:20px}.gx .cd .btn{width:100%}
.gx .sales{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.gx .sale{min-height:130px;padding:16px;border-radius:18px}.gx .sale strong{font-size:21px}.gx .sale small{font-size:10px}.gx .sale span{font-size:12px}
.gx .cats{grid-template-columns:none;grid-auto-flow:column;grid-auto-columns:110px;overflow-x:auto;padding-bottom:8px;scrollbar-width:none}
.gx .cats::-webkit-scrollbar{display:none}.gx .cat .ci{width:70px;height:70px}
.gx .pc{padding:8px;border-radius:16px}.gx .pc .row{flex-direction:column;align-items:stretch}.gx .add{text-align:center}
.gx .railhead strong{font-size:24px}
}
.gx h2 em,.gx h1 em{font-style:italic;color:var(--plum)}
.gx .stage{transition:transform .4s ease-out;transform-style:preserve-3d}
.gx .rv{opacity:0;transform:translateY(28px);transition:opacity .8s ease,transform .8s cubic-bezier(.2,.8,.2,1)}.gx .rv.in{opacity:1;transform:none}
@media(prefers-reduced-motion:reduce){.gx .rv{opacity:1;transform:none}.gx .tkr{animation:none!important}}
.gx .sale{isolation:isolate;min-height:220px}
.gx .sale>small,.gx .sale>strong,.gx .sale>span:not(.fan){position:relative;z-index:2;text-shadow:0 2px 12px rgba(0,0,0,.25)}
.gx .fan{position:absolute;right:8px;top:14px;width:52%;height:72%;z-index:1;pointer-events:none}
.gx .fan img{position:absolute;width:58%;aspect-ratio:1;object-fit:cover;border-radius:16px;border:3px solid rgba(255,255,255,.9);box-shadow:0 18px 30px rgba(0,0,0,.35);transition:transform .5s cubic-bezier(.2,.8,.2,1)}
.gx .fan .f0{right:18%;top:0;z-index:3;transform:rotate(-4deg)}.gx .fan .f1{right:48%;top:22%;width:46%;z-index:2;transform:rotate(-14deg)}.gx .fan .f2{right:0;top:30%;width:46%;z-index:1;transform:rotate(12deg)}
.gx .sale:hover .f0{transform:rotate(0) translateY(-8px) scale(1.05)}.gx .sale:hover .f1{transform:rotate(-20deg) translateX(-10px)}.gx .sale:hover .f2{transform:rotate(18deg) translateX(10px)}
.gx .tick{margin:26px 0}
.gx .tk{overflow:hidden;-webkit-mask:linear-gradient(90deg,transparent,#000 6%,#000 94%,transparent);mask:linear-gradient(90deg,transparent,#000 6%,#000 94%,transparent)}
.gx .tkr{display:flex;gap:14px;width:max-content;padding:6px 0 14px;animation:gztk 55s linear infinite}.gx .tk:hover .tkr{animation-play-state:paused}
@keyframes gztk{to{transform:translateX(-50%)}}
.gx .ti{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid rgba(91,42,134,.1);border-radius:18px;padding:8px 16px 8px 8px;color:var(--ink)!important;box-shadow:0 10px 24px rgba(70,30,110,.08);transition:transform .3s}
.gx .ti:hover{transform:translateY(-4px)}.gx .ti img{width:64px;height:64px;border-radius:12px;object-fit:cover}
.gx .ti span{display:flex;flex-direction:column;font-size:13px;max-width:170px}.gx .ti i{font-style:normal;font-weight:800;color:var(--plum)}
.gx .railhead{position:relative;overflow:hidden;justify-content:flex-end!important;isolation:isolate;min-height:360px!important}
.gx .mos{position:absolute;inset:0;display:grid;grid-template-columns:1fr 1fr;grid-auto-rows:1fr;gap:3px;z-index:-1}
.gx .mos img{width:100%;height:100%;object-fit:cover;transition:transform 1.2s cubic-bezier(.2,.8,.2,1)}.gx .railhead:hover .mos img{transform:scale(1.08)}
.gx .railhead:before{content:"";position:absolute;inset:0;z-index:0;background:inherit;-webkit-mask:linear-gradient(180deg,transparent 0%,rgba(0,0,0,.5) 32%,#000 60%);mask:linear-gradient(180deg,transparent 0%,rgba(0,0,0,.5) 32%,#000 60%)}
.gx .rh-t{position:relative;z-index:1;display:flex;flex-direction:column;gap:8px}
.gx .swipe{position:relative;min-width:0}
.gx .track{display:grid;grid-auto-flow:column;grid-auto-columns:calc((100% - 42px)/4);gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;padding:6px 2px 18px;scrollbar-width:none;cursor:grab}
.gx .track::-webkit-scrollbar{display:none}.gx .track.drag{cursor:grabbing;scroll-snap-type:none;scroll-behavior:auto}
.gx .track>.pc{scroll-snap-align:start}
.gx .sw{position:absolute;top:38%;z-index:3;width:46px;height:46px;border-radius:50%;border:1px solid rgba(201,163,91,.6);background:rgba(255,255,255,.95);color:var(--plum);font-size:28px;line-height:1;cursor:pointer;box-shadow:0 12px 26px rgba(70,30,110,.18);transition:opacity .3s,transform .3s;display:flex;align-items:center;justify-content:center;padding:0 0 4px}
.gx .sw:hover{transform:scale(1.08)}.gx .sw-l{left:-14px}.gx .sw-r{right:-14px}.gx .at-s .sw-l,.gx .at-e .sw-r{opacity:0;pointer-events:none}
@media(max-width:900px){.gx .track{grid-auto-columns:calc((100% - 14px)/2.3)}.gx .railhead{min-height:220px!important}.gx .sw{display:none}}
@media(max-width:760px){.gx .sale{min-height:170px}.gx .fan{width:60%;height:62%;top:8px;right:4px}.gx .fan img{border-width:2px;border-radius:10px}.gx .ti img{width:52px;height:52px}.gx .track{grid-auto-columns:46%}}
CSS;
	}
	static function clear_home_cache() { delete_transient('genz_home_html'); }
	static function admin_skin() {
		if (!current_user_can('manage_options')) wp_die('No access');
		check_admin_referer('genz_hub_skin');
		$s = ($_POST['skin'] ?? '') === 'luxe' ? 'luxe' : 'classic';
		update_option('genz_skin', $s);
		$home = (int) get_option('page_on_front');
		$bk = get_option('genz_home_backup');
		if ($home) {
			if ($s === 'luxe' && !$bk) update_option('genz_home_backup', get_post_field('post_content', $home), false);
			if ($s === 'luxe') {
				$old = $bk ?: get_post_field('post_content', $home);
				$keep = '';
				$pos = strpos($old, '"genz-section genz-guides-home"');
				if ($pos !== false) { $start = strrpos(substr($old, 0, $pos), '<!-- wp:group'); if ($start !== false) $keep = substr($old, $start); }
				wp_update_post(['ID' => $home, 'post_content' => "<!-- wp:shortcode -->\n[genz_home]\n<!-- /wp:shortcode -->\n\n" . $keep]);
			} elseif ($bk) {
				wp_update_post(['ID' => $home, 'post_content' => $bk]);
			}
		}
		self::clear_home_cache();
		wp_safe_redirect(admin_url('admin.php?page=genz-hub&skin=' . $s));
		exit;
	}

	/* ------------------------------------------------ theme preview (admin) */
	static function preview_products($limit, $orderby = 'popularity') {
		$args = ['status' => 'publish', 'limit' => $limit, 'stock_status' => 'instock', 'visibility' => 'catalog'];
		if ($orderby === 'popularity') { $args['orderby'] = 'meta_value_num'; $args['meta_key'] = 'total_sales'; $args['order'] = 'DESC'; }
		$out = [];
		foreach (wc_get_products($args) as $p) {
			if (!$p->get_image_id()) continue;
			$reg = (float) $p->get_regular_price(); $now = (float) wc_get_price_to_display($p);
			$out[] = [
				'id' => $p->get_id(), 'n' => $p->get_name(), 'u' => get_permalink($p->get_id()),
				'img' => wp_get_attachment_image_url($p->get_image_id(), 'woocommerce_single') ?: wp_get_attachment_image_url($p->get_image_id(), 'large'),
				'now' => wc_price($now), 'was' => ($p->is_on_sale() && $reg > $now) ? wc_price($reg) : '',
				'num' => $now, 'off' => ($p->is_on_sale() && $reg > $now) ? (int) round(100 - $now / $reg * 100) : 0,
				'rt' => (float) $p->get_average_rating(), 'rc' => (int) $p->get_review_count(),
				'cart' => $p->is_type('simple') ? add_query_arg('add-to-cart', $p->get_id(), home_url('/cart/')) : get_permalink($p->get_id()),
			];
		}
		return $out;
	}
	static function preview_stars($r) { $r = (int) round($r); return $r ? str_repeat('★', $r) . str_repeat('☆', 5 - $r) : ''; }
	static function preview_page() {
		if (empty($_GET['genz_preview'])) return;
		if (!current_user_can('manage_woocommerce')) { auth_redirect(); exit; }
		nocache_headers();
		header('X-Robots-Tag: noindex, nofollow');
		$prods = self::preview_products(12);
		$hero = [];
		foreach (self::preview_products(40) as $p) { if ($p['num'] >= 25) $hero[] = $p; if (count($hero) >= 3) break; }
		if (count($hero) < 3) $hero = array_slice($prods, 0, 3);
		$grid = array_slice($prods, 0, 8);
		$logo = self::logo_url();
		$cat = function ($slug) { $t = get_term_by('slug', $slug, 'product_cat'); return $t ? get_term_link($t) : home_url('/shop/'); };
		$navs = [];
		foreach (['christmas-gifts', 'gifts-for-him', 'gifts-for-her', 'tech-gadgets', 'winter-warmers-cosy-gifts', 'health-beauty'] as $sl) { $t = get_term_by('slug', $sl, 'product_cat'); if ($t) $navs[] = $t; }
		global $wpdb;
		$rev_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} c JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE p.post_type='product' AND c.comment_approved='1' AND c.comment_type='review'");
		$reviews = get_comments(['post_type' => 'product', 'status' => 'approve', 'type' => 'review', 'number' => 200, 'meta_query' => [['key' => 'rating', 'value' => 5]]]);
		$picked = []; $seen = [];
		foreach ($reviews as $c) {
			$txt = trim(wp_strip_all_tags($c->comment_content));
			if (mb_strlen($txt) < 40 || mb_strlen($txt) > 220) continue;
			$ptitle = get_the_title($c->comment_post_ID);
			similar_text(strtolower($txt), strtolower($ptitle), $pct);
			if ($pct > 45 || stripos($txt, mb_substr($ptitle, 0, 18)) !== false) continue;
			$key = strtolower(preg_replace('/\W+/', '', $txt));
			if (isset($seen[$key])) continue;
			$seen[$key] = 1;
			$picked[] = ['t' => $txt, 'n' => strtok($c->comment_author, ' '), 'p' => get_the_title($c->comment_post_ID), 'v' => (bool) get_comment_meta($c->comment_ID, 'verified', true)];
			if (count($picked) >= 3) break;
		}
		$cart_count = function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
		$end = strtotime('2026-12-01 00:00:00 Europe/London');
		$sale_url = $cat('christmas-new-year-sale');
		?><!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Preview · 3D Luxe Light · GenZ Gadgets Store</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php wp_head(); ?>
<style>
html,body{margin:0!important;padding:0;background:#FBF8F4!important}
.gx{--ink:#1E1A24;--mut:#5E566A;--plum:#5B2A86;--plum2:#7B45B5;--gold:#C9A35B;--gold2:#F1D9A7;font-family:'Plus Jakarta Sans',system-ui,sans-serif;color:var(--ink);background:radial-gradient(60% 40% at 85% 0%,#EBDDFB 0%,#FBF8F4 55%) #FBF8F4;overflow-x:hidden;-webkit-font-smoothing:antialiased}
.gx *{box-sizing:border-box}.gx a{color:inherit;text-decoration:none}
.gx .w{max-width:1320px;margin:0 auto;padding:0 24px}
.gx .serif{font-family:'Bodoni Moda',Georgia,serif}
.gx .bar span{display:inline-block}
.gx .bar{background:var(--gold2)!important;color:#1A1206;font-size:13px;font-weight:800;letter-spacing:.05em;padding:10px 16px;display:flex;flex-wrap:wrap;justify-content:center;gap:24px}
.gx .pv{position:fixed;right:16px;top:44px;z-index:99999;background:#1E1A24;color:#fff;font-size:12px;font-weight:700;padding:8px 12px;border-radius:999px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
.gx header{display:flex;flex-wrap:wrap;align-items:center;gap:22px;padding:18px 0}
.gx header img{height:52px;width:auto}
.gx nav{display:flex;flex-wrap:wrap;gap:22px;font-size:14px;font-weight:600;flex:1 1 360px;justify-content:center}
.gx nav a:hover{color:var(--plum)}
.gx .srch{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid rgba(91,42,134,.18);border-radius:14px;padding:4px 6px 4px 14px;min-width:280px;box-shadow:0 8px 24px rgba(70,30,110,.08)}
.gx .srch input{border:0!important;outline:none;background:transparent!important;font:inherit;color:var(--ink);width:100%;padding:8px 0!important;box-shadow:none!important}
.gx .srch button{border:0;background:var(--plum);color:#fff;border-radius:10px;padding:9px 14px;font:inherit;font-weight:700;cursor:pointer}
.gx .bag{background:var(--plum);color:#fff!important;border-radius:12px;padding:10px 14px;font-weight:800;font-size:14px}
.gx .hero{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,520px),1fr));gap:30px;align-items:center;padding:24px 0 10px}
.gx .chip{display:inline-block;background:#FFF6E2;border:1px solid rgba(201,163,91,.55);color:#8A5A12;font-size:12px;font-weight:800;letter-spacing:.16em;padding:8px 14px;border-radius:999px}
.gx h1,.gx h2,.gx h3{color:var(--ink)!important;letter-spacing:normal!important;text-transform:none!important}
.gx h1{font-family:'Bodoni Moda',serif;font-size:clamp(44px,6vw,78px);line-height:.98;font-weight:600;margin:18px 0}
.gx .grad{background:linear-gradient(90deg,#5B2A86,#9B59D0,#B8862F);-webkit-background-clip:text;background-clip:text;color:transparent}
.gx .lead{font-size:18px;line-height:1.6;color:var(--mut);max-width:500px;margin:0 0 22px}
.gx .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:16px 28px;border-radius:14px;font-weight:800;transition:transform .2s,box-shadow .2s,filter .2s}
.gx .btn:hover{transform:translateY(-2px);filter:brightness(1.06)}
.gx .btn-p{background:linear-gradient(180deg,var(--plum2),var(--plum));color:#fff!important;box-shadow:0 14px 30px rgba(91,42,134,.3)}
.gx .btn-o{background:#fff;border:1px solid rgba(91,42,134,.2);color:var(--ink)}
.gx .proof{display:flex;flex-wrap:wrap;gap:22px;font-size:14px;color:var(--mut);margin-top:18px}.gx .proof b{color:var(--plum)}
.gx .stage{position:relative;height:540px;perspective:1300px}
.gx .glow{position:absolute;left:12%;right:12%;bottom:30px;height:170px;border-radius:50%;background:radial-gradient(closest-side,rgba(201,163,91,.35),transparent);filter:blur(8px)}
.gx .pod{position:absolute;left:16%;right:16%;bottom:62px;height:220px;border-radius:50%;background:linear-gradient(180deg,#fff,#E9DEF5);box-shadow:inset 0 2px 0 #fff,0 30px 60px rgba(70,30,110,.2);transform:rotateX(60deg);animation:fl 6s ease-in-out infinite}
@keyframes fl{0%,100%{transform:rotateX(60deg) translateY(0)}50%{transform:rotateX(60deg) translateY(-12px)}}
.gx .card3{position:absolute;border-radius:24px;background:#fff;border:1px solid rgba(201,163,91,.45);box-shadow:0 30px 70px rgba(70,30,110,.18);overflow:hidden;transition:transform .5s cubic-bezier(.2,.8,.2,1),box-shadow .5s;display:block}
.gx .card3:hover{transform:perspective(900px) rotateY(0) rotateX(0) translateY(-10px) scale(1.03)!important;box-shadow:0 40px 90px rgba(70,30,110,.25)}
.gx .card3 img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block}
.gx .card3 .cap{padding:12px 14px}.gx .card3 .cap b{display:block;font-size:14px;line-height:1.3;max-height:2.6em;overflow:hidden}
.gx .price{font-weight:800;color:var(--plum)}.gx .price del{color:#9A93A3;font-weight:500;font-size:.8em;margin-left:6px}
.gx .seal{position:absolute;right:6%;top:24px;width:112px;height:112px;border-radius:50%;background:var(--gold2);color:#1A1206;display:flex;flex-direction:column;align-items:center;justify-content:center;font-weight:800;box-shadow:0 20px 40px rgba(201,163,91,.35);transform:rotate(-10deg);z-index:3}
.gx .seal span{font-size:30px;line-height:1}
.gx .cd{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:18px;border-radius:22px;padding:22px 28px;background:linear-gradient(90deg,#FFF6E2,#fff);border:1px solid rgba(201,163,91,.45);margin:14px 0}
.gx .cd .t{display:flex;gap:10px}.gx .cd .t div{background:#fff;border:1px solid #E6DDF0;border-radius:12px;padding:10px 14px;text-align:center;min-width:64px}
.gx .cd .t b{display:block;font-size:26px;color:var(--plum)}.gx .cd .t small{font-size:11px;color:var(--mut);font-weight:700}
.gx .sales{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:16px;margin:16px 0}
.gx .sale{position:relative;overflow:hidden;border-radius:22px;padding:28px;min-height:190px;display:flex;flex-direction:column;justify-content:flex-end;gap:6px;box-shadow:0 20px 40px rgba(70,30,110,.12);transition:transform .4s}
.gx .sale:hover{transform:perspective(900px) rotateX(4deg) rotateY(-6deg) translateY(-6px)}
.gx .sale small{font-size:12px;font-weight:800;letter-spacing:.18em}.gx .sale strong{font-family:'Bodoni Moda',serif;font-size:32px;font-weight:600}
.gx h2{font-family:'Bodoni Moda',serif;font-size:clamp(32px,4vw,44px);font-weight:600;margin:0}
.gx .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr));gap:18px}
.gx .pc{border-radius:22px;padding:14px;background:#fff;border:1px solid rgba(91,42,134,.1);box-shadow:0 16px 36px rgba(70,30,110,.08);display:flex;flex-direction:column;gap:10px;transition:transform .5s cubic-bezier(.2,.8,.2,1),box-shadow .5s}
.gx .pc:hover{transform:perspective(900px) rotateX(4deg) rotateY(-6deg) translateY(-8px);box-shadow:0 30px 60px rgba(70,30,110,.18),0 0 0 1px rgba(201,163,91,.5)}
.gx .pc .im{position:relative;border-radius:16px;overflow:hidden;background:#F4EEF9}
.gx .pc img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;transition:transform .6s}.gx .pc:hover img{transform:scale(1.06)}
.gx .off{position:absolute;top:10px;left:10px;background:var(--gold2);color:#1A1206;font-size:11px;font-weight:800;padding:5px 10px;border-radius:999px}
.gx .pc .nm{font-weight:700;line-height:1.35;min-height:2.7em}
.gx .st{color:#B8862F;font-size:13px}.gx .st span{color:var(--mut)}
.gx .row{display:flex;justify-content:space-between;align-items:center;gap:8px}
.gx .add{background:linear-gradient(180deg,var(--plum2),var(--plum));color:#fff!important;font-weight:800;font-size:13px;padding:11px 14px;border-radius:12px;white-space:nowrap}
.gx .revs{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px;margin:30px 0}
.gx .rv{border-radius:22px;padding:24px;background:#fff;border:1px solid rgba(91,42,134,.1)}
.gx .rv p{font-family:'Bodoni Moda',serif;font-size:18px;line-height:1.45;margin:10px 0}
.gx .trust{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:14px;margin:10px 0 40px}
.gx .trust div{border-radius:18px;padding:18px;background:#fff;border:1px solid rgba(91,42,134,.08)}.gx .trust b{color:var(--plum);display:block}
.gx footer{border-top:1px solid #E8E0EF;padding:28px 24px;text-align:center;color:#6E6779;font-size:13px}
@media(max-width:760px){
.gx .w{padding:0 14px}
.gx .bar{font-size:11px;padding:8px 10px}.gx .bar span:not(:first-child){display:none!important}
.gx header{display:grid;grid-template-columns:1fr auto;gap:12px;padding:12px 0}
.gx header img{height:40px}
.gx .bag{grid-column:2;grid-row:1;justify-self:end}
.gx .srch{grid-column:1/-1;min-width:0}
.gx nav{grid-column:1/-1;flex-wrap:nowrap;overflow-x:auto;justify-content:flex-start;gap:8px;padding-bottom:4px;scrollbar-width:none}
.gx nav::-webkit-scrollbar{display:none}
.gx nav a{flex:0 0 auto;background:#fff;border:1px solid rgba(91,42,134,.15);border-radius:999px;padding:8px 14px;font-size:13px}
.gx .hero{gap:10px;padding-top:6px}
.gx h1{font-size:44px;margin:12px 0}
.gx .lead{font-size:16px;margin-bottom:16px}
.gx .btn{padding:14px 20px}
.gx .stage{height:380px}
.gx .card3.c1{left:calc(50% - 100px)!important;top:0!important;width:200px!important}
.gx .card3.c2{left:0!important;top:150px!important;width:130px!important}
.gx .card3.c3{right:0!important;left:auto!important;top:170px!important;width:130px!important}
.gx .card3 .cap{padding:8px 10px}.gx .card3 .cap b{font-size:12px}
.gx .seal{width:78px;height:78px;right:0;top:0}.gx .seal span{font-size:22px}
.gx .pod{left:6%;right:6%;height:150px;bottom:40px}
.gx .cd{padding:16px;gap:12px}.gx .cd .t div{min-width:0;padding:8px 10px}.gx .cd .t b{font-size:20px}.gx .cd .btn{width:100%}
.gx .sales{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.gx .sale{min-height:130px;padding:16px;border-radius:18px}.gx .sale strong{font-size:21px}.gx .sale small{font-size:10px}.gx .sale span{font-size:12px}
.gx .grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.gx .pc{padding:8px;border-radius:16px}.gx .pc .nm{font-size:13px}.gx .pc .row{flex-direction:column;align-items:stretch}.gx .add{text-align:center}
.gx .price{font-size:15px}
.gx .pv{top:auto;bottom:90px;right:10px}
}
</style></head><body class="genz-preview"><div class="gx">
<div class="pv">Preview only · visible to you</div>
<div class="bar"><span>BLACK FRIDAY · UP TO 40% OFF</span><span>FREE UK DELIVERY ON EVERY ORDER</span><span>EXTRA 20% OFF 2+ ITEMS · BUNDLE20</span></div>
<div class="w">
<header>
<a href="<?php echo esc_url(home_url('/')); ?>"><?php if ($logo) : ?><img src="<?php echo esc_url($logo); ?>" alt="GenZ Gadgets Store"><?php else : ?><b class="serif" style="font-size:28px">GenZ Gadgets</b><?php endif; ?></a>
<nav aria-label="Main"><?php foreach ($navs as $t) echo '<a href="' . esc_url(get_term_link($t)) . '">' . esc_html(html_entity_decode($t->name)) . '</a>'; ?><a href="<?php echo esc_url($sale_url); ?>" style="color:#5B2A86">Sale</a></nav>
<form class="srch" role="search" action="<?php echo esc_url(home_url('/')); ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#5E566A" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg><input type="search" name="s" aria-label="Search" placeholder="Try “gifts for dad”"><input type="hidden" name="post_type" value="product"><button type="submit">Search</button></form>
<a class="bag" href="<?php echo esc_url(wc_get_cart_url()); ?>">Bag (<?php echo (int) $cart_count; ?>)</a>
</header>
<section class="hero">
<div>
<span class="chip">THE BLACK FRIDAY EDIT</span>
<h1>Gifts that <span class="grad">wow</span><br>on Christmas<br>morning.</h1>
<p class="lead">Hand-picked tech and clever gadgets, gift-ready from Nottingham and delivered free across the UK.</p>
<a class="btn btn-p" href="<?php echo esc_url($sale_url); ?>">Shop the sale</a> <a class="btn btn-o" href="<?php echo esc_url($cat('christmas-gifts')); ?>">Find a gift</a>
<div class="proof"><span><b><?php echo esc_html(number_format($rev_count)); ?>+</b> customer reviews</span><span><b>Free</b> UK delivery</span><span><b>30-day</b> returns</span></div>
</div>
<div class="stage">
<div class="glow"></div><div class="pod"></div>
<?php $pos = [['c1', 'left:28%;top:20px;width:290px;z-index:2;transform:perspective(900px) rotateY(-12deg) rotateX(5deg)'], ['c2', 'left:2%;top:190px;width:200px;transform:perspective(900px) rotateY(16deg) rotateX(4deg)'], ['c3', 'right:0;top:210px;width:200px;transform:perspective(900px) rotateY(-16deg) rotateX(4deg)']];
foreach ($hero as $i => $p) { if (!isset($pos[$i])) break; echo '<a class="card3 ' . $pos[$i][0] . '" href="' . esc_url($p['u']) . '" style="' . esc_attr($pos[$i][1]) . '"><img src="' . esc_url($p['img']) . '" alt="' . esc_attr($p['n']) . '"><div class="cap"><b>' . esc_html($p['n']) . '</b><span class="price">' . wp_kses_post($p['now']) . ($p['was'] ? '<del>' . wp_kses_post($p['was']) . '</del>' : '') . '</span></div></a>'; }
$top_off = 0; foreach ($prods as $p) $top_off = max($top_off, $p['off']);
if ($top_off) echo '<div class="seal"><small>UP TO</small><span>' . (int) $top_off . '%</span><small>OFF</small></div>'; ?>
</div>
</section>
<section class="cd" aria-label="Countdown"><div><b style="font-size:18px">Black Friday prices end Cyber Monday</b><div style="color:#5E566A;font-size:14px">Order today, dispatched from Nottingham</div></div>
<div class="t" data-end="<?php echo (int) $end; ?>"><div><b data-u="d">0</b><small>DAYS</small></div><div><b data-u="h">0</b><small>HRS</small></div><div><b data-u="m">0</b><small>MINS</small></div><div><b data-u="s">0</b><small>SECS</small></div></div>
<a class="btn btn-p" href="<?php echo esc_url($sale_url); ?>">Grab the deals</a></section>
<section class="sales" aria-label="Sales">
<a class="sale" href="<?php echo esc_url($sale_url); ?>" style="background:linear-gradient(150deg,#2A1840,#120A1E);color:#fff"><small style="color:#F1D9A7">BLACK FRIDAY</small><strong>Up to <?php echo (int) max(30, $top_off); ?>% off</strong><span style="color:#D9CDE8">Shop now →</span></a>
<a class="sale" href="<?php echo esc_url($cat('christmas-gifts')); ?>" style="background:linear-gradient(150deg,#1E4A36,#0B2318);color:#F2FAF5"><small style="color:#BFE7D0">CHRISTMAS SALE</small><strong>Gifts from £5</strong><span style="color:#CFE6DA">Stocking fillers →</span></a>
<a class="sale" href="<?php echo esc_url(home_url('/shop/')); ?>" style="background:linear-gradient(150deg,#F6E3B8,#C89B4E);color:#1A1206"><small>NEW YEAR SALE</small><strong>20% off 2+ items</strong><span style="font-weight:700">Code BUNDLE20 →</span></a>
<a class="sale" href="<?php echo esc_url($sale_url); ?>" style="background:linear-gradient(150deg,#6E1A28,#2B070E);color:#FFEFF1"><small style="color:#FFC2CB">CLEARANCE</small><strong>Final stock</strong><span style="color:#F4C9CF">When it's gone, it's gone →</span></a>
</section>
<section style="margin:34px 0">
<div class="row" style="margin-bottom:20px;align-items:end"><h2>Most wanted this week</h2><a href="<?php echo esc_url(home_url('/shop/')); ?>" style="color:#5B2A86;font-weight:700">Shop all →</a></div>
<div class="grid"><?php foreach ($grid as $p) { ?>
<article class="pc"><a class="im" href="<?php echo esc_url($p['u']); ?>"><img src="<?php echo esc_url($p['img']); ?>" alt="<?php echo esc_attr($p['n']); ?>" loading="lazy"><?php if ($p['off']) echo '<span class="off">−' . (int) $p['off'] . '%</span>'; ?></a>
<a class="nm" href="<?php echo esc_url($p['u']); ?>"><?php echo esc_html($p['n']); ?></a>
<?php if ($p['rt']) echo '<div class="st">' . esc_html(self::preview_stars($p['rt'])) . ' <span>(' . (int) $p['rc'] . ')</span></div>'; ?>
<div class="row"><span class="price"><?php echo wp_kses_post($p['now']); if ($p['was']) echo '<del>' . wp_kses_post($p['was']) . '</del>'; ?></span><a class="add" href="<?php echo esc_url($p['cart']); ?>">Add to bag</a></div></article>
<?php } ?></div></section>
<?php if ($picked) { ?><section class="revs"><?php foreach ($picked as $r) { ?><div class="rv"><div class="st">★★★★★</div><p>“<?php echo esc_html($r['t']); ?>”</p><div style="color:#5E566A;font-size:13px"><?php echo esc_html($r['n']); ?><?php echo $r['v'] ? ', verified buyer' : ''; ?> · <?php echo esc_html($r['p']); ?></div></div><?php } ?></section><?php } ?>
<section class="trust"><div><b>Free UK delivery</b>Every order, no minimum</div><div><b>30-day returns</b>Free and easy</div><div><b>Gift-ready</b>Packed in Nottingham</div><div><b>Track &amp; chat</b>Live tracking and UK support</div></section>
</div>
<footer>GenZ Gadgets Store · Noel Street, Nottingham · genzgadget.uk</footer>
</div>
<script>(function(){var t=document.querySelector('.gx .cd .t');if(!t)return;var end=+t.getAttribute('data-end')*1000;function u(){var d=Math.max(0,end-Date.now());var s=Math.floor(d/1000);var v={d:Math.floor(s/86400),h:Math.floor(s%86400/3600),m:Math.floor(s%3600/60),s:s%60};for(var k in v){var e=t.querySelector('[data-u='+k+']');if(e)e.textContent=String(v[k]).padStart(2,'0');}}u();setInterval(u,1000);})();</script>
<?php wp_footer(); ?>
</body></html><?php
		exit;
	}

	static function handle_links() {
		// Magic login.
		if (!empty($_GET['genz_ml'])) {
			$tok = sanitize_text_field(wp_unslash($_GET['genz_ml']));
			$k = 'genz_ml_' . hash('sha256', $tok);
			$email = get_transient($k);
			$acct = wc_get_page_permalink('myaccount');
			if (!$email) { wp_safe_redirect(add_query_arg('genz_ml_expired', '1', $acct)); exit; }
			delete_transient($k);
			$user = get_user_by('email', $email);
			if ($user && user_can($user, 'edit_posts')) { wp_safe_redirect($acct); exit; }
			if (!$user) {
				$uid = wc_create_new_customer($email, wc_create_new_customer_username($email), wp_generate_password(24));
				if (is_wp_error($uid)) { wp_safe_redirect($acct); exit; }
				$orders = wc_get_orders(['billing_email' => $email, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order']);
				if ($orders) {
					$o = $orders[0];
					$c = new WC_Customer($uid);
					$c->set_first_name($o->get_billing_first_name());
					$c->set_last_name($o->get_billing_last_name());
					foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone'] as $f) {
						$g = 'get_billing_' . $f; $s = 'set_billing_' . $f;
						if (is_callable([$o, $g]) && is_callable([$c, $s])) $c->$s($o->$g());
					}
					$c->set_billing_email($email);
					$c->save();
				}
				$user = get_user_by('id', $uid);
			}
			if (function_exists('wc_update_new_customer_past_orders')) wc_update_new_customer_past_orders($user->ID);
			wp_set_current_user($user->ID);
			wp_set_auth_cookie($user->ID, true);
			wp_safe_redirect(wc_get_account_endpoint_url('orders'));
			exit;
		}
		if (!empty($_GET['genz_ml_expired']) && function_exists('wc_add_notice') && is_account_page() && !is_user_logged_in()) {
			wc_add_notice('That sign-in link has expired or was already used. Please request a new one below.', 'error');
		}
		// Chat deep link from reply email.
		if (!empty($_GET['genz_chat'])) {
			$tok = sanitize_text_field(wp_unslash($_GET['genz_chat']));
			if (self::thread_by_token($tok)) self::set_chat_cookie($tok);
			wp_safe_redirect(home_url('/#genz-chat'));
			exit;
		}
		// Unsubscribe.
		if (!empty($_GET['genz_unsub'])) {
			global $wpdb;
			$tok = sanitize_text_field(wp_unslash($_GET['genz_unsub']));
			if (strlen($tok) >= 20) $wpdb->update($wpdb->prefix . 'genz_subscribers', ['status' => 'unsubscribed'], ['token' => $tok]);
			wp_die('<h2>You have been unsubscribed</h2><p>You will no longer receive marketing emails from GenZ Gadgets Store. Order and delivery emails are not affected.</p><p><a href="' . esc_url(home_url('/')) . '">Back to the shop</a></p>', 'Unsubscribed', ['response' => 200]);
		}
	}

	/* ------------------------------------------------------------- chat */
	static function set_chat_cookie($tok) {
		$args = ['expires' => time() + YEAR_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax'];
		setcookie('genz_chat', $tok, $args);
		$args['httponly'] = false;
		setcookie('genz_chat_on', '1', $args);
		$_COOKIE['genz_chat'] = $tok;
	}
	static function thread_by_token($tok) {
		global $wpdb;
		if (!is_string($tok) || strlen($tok) < 20) return null;
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}genz_threads WHERE token=%s", $tok));
	}
	static function current_thread() {
		global $wpdb;
		$t = null;
		if (!empty($_COOKIE['genz_chat'])) $t = self::thread_by_token(sanitize_text_field(wp_unslash($_COOKIE['genz_chat'])));
		$uid = get_current_user_id();
		if (!$t && $uid) {
			$t = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}genz_threads WHERE user_id=%d ORDER BY last_at DESC LIMIT 1", $uid));
		}
		if ($t && $uid && !(int) $t->user_id) $wpdb->update($wpdb->prefix . 'genz_threads', ['user_id' => $uid], ['id' => $t->id]);
		return $t;
	}
	static function thread_payload($t) {
		global $wpdb;
		if (!$t) {
			$pre = [];
			if (is_user_logged_in()) { $u = wp_get_current_user(); $pre = ['name' => trim($u->first_name . ' ' . $u->last_name) ?: $u->display_name, 'email' => $u->user_email]; }
			return ['thread' => null, 'messages' => [], 'unread' => 0, 'prefill' => $pre];
		}
		$rows = $wpdb->get_results($wpdb->prepare("SELECT sender,body,created FROM {$wpdb->prefix}genz_messages WHERE thread_id=%d ORDER BY id ASC LIMIT 300", $t->id));
		$msgs = [];
		foreach ($rows as $m) $msgs[] = ['s' => $m->sender, 'b' => $m->body, 't' => wp_date('j M, H:i', strtotime($m->created . ' UTC'))];
		return ['thread' => ['name' => $t->name, 'status' => $t->status], 'messages' => $msgs, 'unread' => (int) $t->unread_customer];
	}
	static function r_chat_get(WP_REST_Request $r) {
		global $wpdb;
		$t = self::current_thread();
		$p = self::thread_payload($t);
		if ($t && $t->unread_customer) $wpdb->update($wpdb->prefix . 'genz_threads', ['unread_customer' => 0], ['id' => $t->id]);
		return $p;
	}
	static function r_chat_post(WP_REST_Request $r) {
		global $wpdb;
		if ($r->get_param('website')) return self::fail('Message could not be sent.');
		if (!self::limit('chat' . self::ip(), 12, 5 * MINUTE_IN_SECONDS)) return self::fail('You are sending messages too quickly. Please wait a moment.', 429);
		$body = trim(sanitize_textarea_field((string) $r->get_param('message')));
		if ($body === '') return self::fail('Please type a message.');
		if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);
		$tt = $wpdb->prefix . 'genz_threads';
		$t = self::current_thread();
		$new = false;
		if (!$t) {
			$name = sanitize_text_field((string) $r->get_param('name'));
			$email = sanitize_email((string) $r->get_param('email'));
			if ($name === '' || !is_email($email)) return self::fail('Please add your name and a valid email.');
			$tok = wp_generate_password(40, false);
			$wpdb->insert($tt, [
				'token' => $tok, 'user_id' => get_current_user_id(), 'name' => mb_substr($name, 0, 120), 'email' => $email,
				'order_ref' => substr(preg_replace('/[^0-9A-Za-z-]/', '', (string) $r->get_param('order')), 0, 40),
				'status' => 'open', 'last_at' => self::now(), 'created' => self::now(),
			]);
			$t = self::thread_by_token($tok);
			self::set_chat_cookie($tok);
			$new = true;
		}
		$wpdb->insert($wpdb->prefix . 'genz_messages', ['thread_id' => $t->id, 'sender' => 'customer', 'body' => $body, 'created' => self::now()]);
		$wpdb->query($wpdb->prepare("UPDATE $tt SET unread_admin=unread_admin+1, status='open', last_at=%s, last_preview=%s WHERE id=%d", self::now(), mb_substr($body, 0, 190), $t->id));
		// Notify the shop (throttled per thread).
		if ($new || !get_transient('genz_chat_n_' . $t->id)) {
			set_transient('genz_chat_n_' . $t->id, 1, 10 * MINUTE_IN_SECONDS);
			$link = admin_url('admin.php?page=genz-inbox&thread=' . (int) $t->id);
			self::mail(get_option('admin_email'), 'New customer message from ' . $t->name, 'New message in your inbox', '<p><strong>' . esc_html($t->name) . '</strong> (' . esc_html($t->email) . ')' . ($t->order_ref ? ' &middot; Order #' . esc_html($t->order_ref) : '') . ' wrote:</p><blockquote style="border-left:3px solid #ffcc00;margin:0;padding:8px 14px;background:#f7f7f7">' . nl2br(esc_html($body)) . '</blockquote>' . self::btn($link, 'Reply in dashboard'));
		}
		if ($new) {
			self::mail($t->email, 'We got your message - GenZ Gadgets Store', 'Thanks, ' . $t->name . '!', '<p>We have received your message and a member of our UK team will reply shortly. You will get our reply by email, and you can continue the chat on our website any time.</p><blockquote style="border-left:3px solid #ffcc00;margin:0;padding:8px 14px;background:#f7f7f7">' . nl2br(esc_html($body)) . '</blockquote>' . self::btn(add_query_arg('genz_chat', $t->token, home_url('/')), 'Open the conversation'));
		}
		$t = self::thread_by_token($t->token);
		return self::thread_payload($t);
	}

	/* ----------------------------------------------------- my account */
	static function account_menu($items) {
		$out = [];
		foreach ($items as $k => $v) {
			$out[$k] = $v;
			if ($k === 'orders') $out['messages'] = 'Messages';
		}
		if (!isset($out['messages'])) $out['messages'] = 'Messages';
		return $out;
	}
	static function account_messages() {
		echo '<h2>Messages</h2><p>Chat with our UK support team about any order or product. We reply here and by email.</p>';
		echo self::chat_markup(true); // phpcs:ignore
	}
	static function order_view_tracking($order) {
		if (!$order instanceof WC_Order) return;
		if (!is_account_page() && !is_wc_endpoint_url('view-order')) return;
		echo '<section class="genz-card" id="genz-track-order"><h2 class="genz-h">Delivery tracking</h2>' . self::render_tracking_html($order, array_column(self::order_tracking($order), 'no'), false) . '<p class="genz-muted" style="margin-top:14px">Questions about this order? <a href="' . esc_url(wc_get_account_endpoint_url('messages')) . '">Send us a message</a>.</p></section>'; // phpcs:ignore
	}
	static function orders_actions($actions, $order) {
		$actions['genz_track'] = ['url' => $order->get_view_order_url() . '#genz-track-order', 'name' => 'Track'];
		return $actions;
	}

	/* ------------------------------------------------------ checkout */
	static function checkout_fields() {
		if (!function_exists('woocommerce_register_additional_checkout_field')) return;
		try {
			woocommerce_register_additional_checkout_field([
				'id' => 'genz/newsletter',
				'label' => 'Email me exclusive deals and new gadget drops (optional)',
				'location' => 'contact',
				'type' => 'checkbox',
				'required' => false,
			]);
		} catch (\Throwable $e) {}
	}
	static function checkout_optin($order) {
		if (!$order instanceof WC_Order) return;
		$yes = false;
		foreach ($order->get_meta_data() as $m) {
			$d = $m->get_data();
			if (substr($d['key'], -15) === 'genz/newsletter' && ($d['value'] === true || $d['value'] === '1' || $d['value'] === 1 || $d['value'] === 'true')) $yes = true;
		}
		if ($yes) self::add_subscriber($order->get_billing_email(), trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()), 'checkout');
	}
	static function checkout_optin_classic($order_id, $data, $order) { self::checkout_optin($order); }

	/* ------------------------------------------------- tracking admin */
	static function metabox() {
		$screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
		add_meta_box('genz-tracking', 'Tracking & customer updates', [__CLASS__, 'metabox_html'], $screen, 'side', 'high');
	}
	static function metabox_html($post_or_order) {
		$order = ($post_or_order instanceof WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);
		if (!$order) return;
		wp_nonce_field('genz_trk_save', 'genz_trk_nonce');
		$list = self::order_tracking($order);
		if ($list) {
			echo '<ul style="margin:0 0 10px">';
			foreach ($list as $i => $t) {
				$d = get_transient('genz_cn_' . md5($t['no']));
				$st = is_array($d) && !empty($d['events'][0]) ? $d['events'][0]['d'] : 'Waiting for first scan';
				echo '<li style="border-bottom:1px solid #eee;padding:6px 0"><strong>' . esc_html($t['no']) . '</strong><br><small>' . esc_html($st) . '</small><br><label><input type="checkbox" name="genz_trk_remove[]" value="' . (int) $i . '"> Remove</label></li>';
			}
			echo '</ul>';
		}
		echo '<p><label for="genz_trk_new"><strong>Add Cainiao tracking number</strong></label><input type="text" id="genz_trk_new" name="genz_trk_new" style="width:100%" placeholder="e.g. LP00..."></p>';
		echo '<p><label><input type="checkbox" name="genz_trk_notify" value="1" checked> Email the customer now</label></p>';
		echo '<p><label><input type="checkbox" name="genz_trk_complete" value="1"> Also mark order as Completed</label></p>';
		echo '<p class="description">Customers get an email whenever the parcel status changes (checked every 3 hours) and can follow it on <a href="' . esc_url(self::track_url()) . '" target="_blank">your tracking page</a>.</p>';
	}
	static function save_metabox($order_id) {
		if (empty($_POST['genz_trk_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['genz_trk_nonce'])), 'genz_trk_save')) return;
		if (!current_user_can('edit_shop_orders')) return;
		$order = wc_get_order($order_id);
		if (!$order) return;
		$list = self::order_tracking($order);
		if (!empty($_POST['genz_trk_remove'])) {
			foreach ((array) $_POST['genz_trk_remove'] as $i) unset($list[(int) $i]);
			$list = array_values($list);
		}
		$new = self::clean_no(isset($_POST['genz_trk_new']) ? wp_unslash($_POST['genz_trk_new']) : '');
		$added = false;
		if ($new && strlen($new) >= 6 && !in_array($new, array_column($list, 'no'), true)) {
			$list[] = ['no' => $new, 'carrier' => 'cainiao', 'added' => time()];
			$added = true;
		}
		$order->update_meta_data('_genz_tracking', $list);
		$order->save();
		$active = (array) get_option('genz_hub_active', []);
		if ($list) $active[$order->get_id()] = 1; else unset($active[$order->get_id()]);
		update_option('genz_hub_active', $active, false);
		if ($added) {
			$order->add_order_note('Tracking number ' . $new . ' added.');
			if (!empty($_POST['genz_trk_notify'])) self::email_shipped($order, $new);
			$d = self::cainiao($new, true);
			if (!empty($d['events'][0])) $order->update_meta_data('_genz_trk_last_' . $new, md5($d['events'][0]['d'] . $d['events'][0]['t']));
			$order->save();
			if (!empty($_POST['genz_trk_complete']) && $order->get_status() !== 'completed') $order->update_status('completed', 'Marked as shipped with tracking.');
		}
	}
	static function track_link($order, $no = '') {
		return $no ? add_query_arg('tn', rawurlencode($no), self::track_url()) : self::track_url();
	}
	static function items_html($order) {
		$h = '<h3 style="margin:22px 0 8px">Your items</h3><table cellpadding="8" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 12px;border:1px solid #eee">';
		foreach ($order->get_items() as $it) {
			$p = $it->get_product();
			$img = '';
			if ($p && $p->get_image_id()) { $src = wp_get_attachment_image_url($p->get_image_id(), 'thumbnail'); if ($src) $img = '<img src="' . esc_url($src) . '" width="64" height="64" alt="" style="border-radius:6px;display:block;object-fit:cover">'; }
			$link = $p ? get_permalink($p->get_parent_id() ?: $p->get_id()) : '';
			$h .= '<tr><td style="border-bottom:1px solid #eee;width:72px">' . $img . '</td><td style="border-bottom:1px solid #eee">' . ($link ? '<a href="' . esc_url($link) . '" style="color:#111;font-weight:bold;text-decoration:none">' . esc_html($it->get_name()) . '</a>' : '<strong>' . esc_html($it->get_name()) . '</strong>') . '<br><span style="color:#777">Qty ' . (int) $it->get_quantity() . '</span></td><td style="border-bottom:1px solid #eee;text-align:right;white-space:nowrap">' . wp_kses_post($order->get_formatted_line_subtotal($it)) . '</td></tr>';
		}
		$h .= '<tr><td colspan="2" style="text-align:right"><strong>Total</strong></td><td style="text-align:right;white-space:nowrap"><strong>' . wp_kses_post($order->get_formatted_order_total()) . '</strong></td></tr>';
		return $h . '</table>';
	}
	static function progress_email($stage) {
		$labels = ['Ordered', 'Processing', 'Shipped', 'In transit', 'Out for delivery', 'Delivered'];
		$h = '<table cellpadding="0" cellspacing="0" style="width:100%;margin:16px 0"><tr>';
		foreach ($labels as $i => $l) {
			$on = $i <= $stage;
			$h .= '<td style="text-align:center;font-size:11px;color:' . ($on ? '#111' : '#aaa') . ';font-weight:' . ($on ? 'bold' : 'normal') . '"><div style="height:6px;margin:0 2px 6px;border-radius:3px;background:' . ($i === $stage ? '#ffcc00' : ($on ? '#111' : '#e3e3e3')) . '"></div>' . esc_html($l) . '</td>';
		}
		return $h . '</tr></table>';
	}
	static function events_email($d, $max = 4) {
		if (empty($d['events'])) return '<p style="color:#777">The first courier scan usually appears within 24&ndash;72 hours of dispatch.</p>';
		$h = '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;margin:8px 0">';
		foreach (array_slice($d['events'], 0, $max) as $i => $e) {
			$h .= '<tr><td style="vertical-align:top;width:14px"><div style="width:10px;height:10px;border-radius:50%;margin-top:4px;background:' . ($i === 0 ? '#ffcc00' : '#cfcfcf') . '"></div></td><td style="border-bottom:1px solid #f0f0f0"><span style="font-weight:' . ($i === 0 ? 'bold' : 'normal') . '">' . esc_html($e['d']) . '</span><br><span style="font-size:12px;color:#888">' . esc_html($e['ts']) . '</span></td></tr>';
		}
		return $h . '</table>';
	}
	static function admin_preview() {
		if (!current_user_can('manage_woocommerce')) wp_die('No access');
		check_admin_referer('genz_hub_preview');
		$order = wc_get_order((int) ($_POST['order'] ?? 0));
		$no = self::clean_no(wp_unslash($_POST['tn'] ?? ''));
		if ($order && $no) self::email_shipped($order, $no, get_option('admin_email'));
		wp_safe_redirect(admin_url('admin.php?page=genz-tracking&preview=' . ($order && $no ? 1 : 0)));
		exit;
	}
	static function email_shipped($order, $no, $to = '') {
		$name = $order->get_billing_first_name() ?: 'there';
		$d = self::cainiao($no);
		$stage = max(2, self::stage($order, $d));
		$html = '<p>Hi ' . esc_html($name) . ',</p><p>Great news &ndash; your order <strong>#' . esc_html($order->get_order_number()) . '</strong> has been dispatched and is on its way to you!</p>' .
			'<p style="background:#f7f7f7;padding:14px;border-radius:8px;margin:16px 0">Tracking number<br><strong style="font-size:18px;letter-spacing:.5px">' . esc_html($no) . '</strong></p>' .
			self::progress_email($stage) .
			'<h3 style="margin:18px 0 6px">Latest tracking</h3>' . self::events_email($d) .
			self::btn(self::track_link($order, $no), 'Track my parcel') .
			self::items_html($order) .
			'<p>We will email you every time your parcel moves, so you always know where it is.</p><p>Any questions? Just reply to this email or tap &quot;Chat with us&quot; on our website &ndash; our UK team is happy to help.</p><p>Thank you for shopping with GenZ Gadgets Store!</p>';
		self::mail($to ?: $order->get_billing_email(), ($to ? '[PREVIEW] ' : '') . 'Your order #' . $order->get_order_number() . ' has shipped', 'Your order is on its way', $html);
	}
	static function track_cron() {
		$active = (array) get_option('genz_hub_active', []);
		$n = 0;
		foreach (array_keys($active) as $oid) {
			if (++$n > 60) break;
			$order = wc_get_order($oid);
			if (!$order) { unset($active[$oid]); continue; }
			$list = self::order_tracking($order);
			if (!$list) { unset($active[$oid]); continue; }
			$all_done = true;
			foreach ($list as $t) {
				$no = $t['no'];
				if ($order->get_meta('_genz_trk_done_' . $no)) continue;
				$d = self::cainiao($no, true);
				if (empty($d['ok'])) { $all_done = false; continue; }
				if (!empty($t['added']) && $t['added'] < time() - 90 * DAY_IN_SECONDS) { $order->update_meta_data('_genz_trk_done_' . $no, 1); continue; }
				if (empty($d['events'][0])) { $all_done = false; continue; }
				$latest = $d['events'][0];
				$h = md5($latest['d'] . $latest['t']);
				$stage = self::stage($order, $d);
				if ($order->get_meta('_genz_trk_last_' . $no) !== $h) {
					$order->update_meta_data('_genz_trk_last_' . $no, $h);
					$last_mail = (int) $order->get_meta('_genz_trk_mailed_' . $no);
					if ($stage >= 4 || $last_mail < time() - 6 * HOUR_IN_SECONDS) {
						self::email_update($order, $no, $latest, $stage);
						$order->update_meta_data('_genz_trk_mailed_' . $no, time());
					}
					$order->add_order_note('Tracking update (' . $no . '): ' . $latest['d']);
				}
				if ($stage >= 5) $order->update_meta_data('_genz_trk_done_' . $no, 1); else $all_done = false;
			}
			$order->save();
			if ($all_done) unset($active[$oid]);
		}
		update_option('genz_hub_active', $active, false);
	}
	static function email_update($order, $no, $ev, $stage) {
		$labels = ['Order placed', 'Processing', 'Shipped', 'In transit', 'Out for delivery', 'Delivered'];
		$name = $order->get_billing_first_name() ?: 'there';
		$subj = $stage >= 5 ? 'Delivered: your order #' . $order->get_order_number() : ($stage == 4 ? 'Out for delivery today: order #' . $order->get_order_number() : 'Delivery update for order #' . $order->get_order_number());
		$html = '<p>Hi ' . esc_html($name) . ',</p><p>There is a new update on your parcel <strong>' . esc_html($no) . '</strong>:</p>' .
			'<p style="background:#fff8d6;border-left:4px solid #ffcc00;padding:12px;border-radius:4px"><strong>' . esc_html($labels[$stage]) . '</strong><br>' . esc_html($ev['d']) . '<br><small>' . esc_html($ev['ts']) . '</small></p>' .
			self::progress_email($stage) .
			self::btn(self::track_link($order, $no), 'See full tracking') .
			self::items_html($order) .
			($stage >= 5 ? '<p>We hope you love it! If anything is not right, our 30-day returns have you covered &ndash; just reply to this email.</p>' : '<p>We will keep you posted as your parcel moves.</p>');
		self::mail($order->get_billing_email(), $subj, $stage >= 5 ? 'Your parcel has been delivered' : 'Your parcel is moving', $html);
	}

	/* --------------------------------------------------------- broadcast */
	static function send_batch() {
		global $wpdb;
		$job = get_option('genz_hub_job');
		if (!is_array($job)) return;
		$t = $wpdb->prefix . 'genz_subscribers';
		$rows = $wpdb->get_results($wpdb->prepare("SELECT id,email,name,token FROM $t WHERE status='subscribed' AND id>%d ORDER BY id ASC LIMIT 40", (int) $job['last_id']));
		foreach ($rows as $s) {
			$unsub = add_query_arg('genz_unsub', $s->token, home_url('/'));
			$html = $job['html'] . '<p style="font-size:12px;color:#888;margin-top:30px">You are receiving this because you subscribed at genzgadget.uk. <a href="' . esc_url($unsub) . '">Unsubscribe</a></p>';
			self::mail($s->email, $job['subject'], $job['heading'], $html);
			$job['last_id'] = (int) $s->id;
			$job['sent']++;
		}
		if (count($rows) < 40) {
			$job['done'] = time();
			update_option('genz_hub_last_job', $job, false);
			delete_option('genz_hub_job');
			return;
		}
		update_option('genz_hub_job', $job, false);
		wp_schedule_single_event(time() + 60, 'genz_hub_send_batch');
	}

	/* ------------------------------------------------------- email delivery */
	static function smtp() {
		$o = get_option('genz_hub_smtp');
		return wp_parse_args(is_array($o) ? $o : [], ['on' => 0, 'host' => 'smtp-relay.brevo.com', 'port' => 2525, 'user' => '', 'pass' => '', 'from' => 'orders@genzgadget.uk', 'name' => 'GenZ Gadgets Store', 'reply' => get_option('admin_email')]);
	}
	static function smtp_ready() { $o = self::smtp(); return $o['on'] && $o['user'] && $o['pass'] && is_email($o['from']); }
	static function phpmailer($m) {
		if (!self::smtp_ready()) return;
		$o = self::smtp();
		$m->isSMTP();
		$m->Host = $o['host'];
		$m->Port = (int) $o['port'];
		$m->SMTPAuth = true;
		$m->Username = $o['user'];
		$m->Password = $o['pass'];
		$m->SMTPSecure = ((int) $o['port'] === 465) ? 'ssl' : 'tls';
		$m->Timeout = 20;
		$m->Sender = $o['from'];
	}
	static function mail_from($f) { return self::smtp_ready() ? self::smtp()['from'] : $f; }
	static function mail_from_name($n) { return self::smtp_ready() ? self::smtp()['name'] : $n; }
	static function mail_reply_to($args) {
		if (!self::smtp_ready()) return $args;
		$o = self::smtp();
		if (!is_email($o['reply'])) return $args;
		$h = $args['headers'] ?? '';
		$flat = is_array($h) ? implode("\n", $h) : (string) $h;
		if (stripos($flat, 'reply-to:') === false) {
			if (is_array($h)) $h[] = 'Reply-To: ' . $o['reply'];
			else $h = rtrim($h) . ($h ? "\r\n" : '') . 'Reply-To: ' . $o['reply'];
			$args['headers'] = $h;
		}
		return $args;
	}
	static function log_mail($to, $subject, $status, $err) {
		$log = get_option('genz_hub_mail_log');
		if (!is_array($log)) $log = [];
		array_unshift($log, ['t' => time(), 'to' => is_array($to) ? implode(', ', $to) : (string) $to, 's' => mb_substr((string) $subject, 0, 140), 'st' => $status, 'e' => mb_substr((string) $err, 0, 200)]);
		update_option('genz_hub_mail_log', array_slice($log, 0, 60), false);
	}
	static function admin_smtp_save() {
		if (!current_user_can('manage_options')) wp_die('No access');
		check_admin_referer('genz_hub_smtp_save');
		$o = self::smtp();
		$o['on'] = empty($_POST['on']) ? 0 : 1;
		$o['host'] = sanitize_text_field(wp_unslash($_POST['host'] ?? $o['host']));
		$o['port'] = (int) ($_POST['port'] ?? 587);
		$o['user'] = sanitize_text_field(wp_unslash($_POST['user'] ?? ''));
		$pass = trim((string) wp_unslash($_POST['pass'] ?? ''));
		if ($pass !== '') $o['pass'] = $pass;
		$o['from'] = sanitize_email(wp_unslash($_POST['from'] ?? $o['from']));
		$o['name'] = sanitize_text_field(wp_unslash($_POST['name'] ?? $o['name']));
		$o['reply'] = sanitize_email(wp_unslash($_POST['reply'] ?? $o['reply']));
		update_option('genz_hub_smtp', $o, false);
		wp_safe_redirect(admin_url('admin.php?page=genz-email&saved=1'));
		exit;
	}
	static function admin_smtp_test() {
		if (!current_user_can('manage_options')) wp_die('No access');
		check_admin_referer('genz_hub_smtp_test');
		$to = sanitize_email(wp_unslash($_POST['to'] ?? '')) ?: get_option('admin_email');
		$ok = self::mail($to, 'Test email from GenZ Gadgets Store', 'Email delivery is working', '<p>If you are reading this in your inbox (not spam), your store emails are set up correctly.</p>' . self::btn(home_url('/'), 'Visit the shop'));
		wp_safe_redirect(admin_url('admin.php?page=genz-email&test=' . ($ok ? 'ok' : 'fail')));
		exit;
	}
	static function page_email() {
		$o = self::smtp();
		echo '<div class="wrap genz-a-wrap"><h1>Email Delivery</h1>';
		if (!empty($_GET['saved'])) echo '<div class="notice notice-success"><p>Settings saved. Now press <strong>Send test email</strong>.</p></div>';
		if (!empty($_GET['test'])) echo $_GET['test'] === 'ok' ? '<div class="notice notice-success"><p>Test email sent. Check your inbox &ndash; it should arrive in the Inbox, not Spam.</p></div>' : '<div class="notice notice-error"><p>The test email failed. See the error in the log below.</p></div>';
		$ready = self::smtp_ready();
		echo '<div class="genz-a-cards"><div><strong style="color:' . ($ready ? '#0a7a2f' : '#b00020') . '">' . ($ready ? 'ON' : 'OFF') . '</strong>Brevo email delivery</div><div><strong>' . esc_html($o['from']) . '</strong>Emails are sent from</div></div>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 20px;max-width:720px">';
		wp_nonce_field('genz_hub_smtp_save');
		echo '<input type="hidden" name="action" value="genz_hub_smtp_save"><table class="form-table" role="presentation">';
		echo '<tr><th>Use Brevo for all store emails</th><td><label><input type="checkbox" name="on" value="1"' . checked($o['on'], 1, false) . '> On</label></td></tr>';
		echo '<tr><th>SMTP server</th><td><input type="text" name="host" value="' . esc_attr($o['host']) . '" class="regular-text"> Port <input type="number" name="port" value="' . (int) $o['port'] . '" style="width:80px"></td></tr>';
		echo '<tr><th>SMTP login</th><td><input type="text" name="user" value="' . esc_attr($o['user']) . '" class="regular-text" autocomplete="off"><p class="description">Brevo &rarr; Settings &rarr; SMTP &amp; API &rarr; SMTP tab (looks like xxxx@smtp-brevo.com).</p></td></tr>';
		echo '<tr><th>SMTP key</th><td><input type="password" name="pass" value="" class="regular-text" autocomplete="new-password" placeholder="' . ($o['pass'] ? 'Saved &ndash; leave empty to keep' : 'Paste your Brevo SMTP key') . '"></td></tr>';
		echo '<tr><th>From email</th><td><input type="email" name="from" value="' . esc_attr($o['from']) . '" class="regular-text"></td></tr>';
		echo '<tr><th>From name</th><td><input type="text" name="name" value="' . esc_attr($o['name']) . '" class="regular-text"></td></tr>';
		echo '<tr><th>Customer replies go to</th><td><input type="email" name="reply" value="' . esc_attr($o['reply']) . '" class="regular-text"></td></tr>';
		echo '</table>';
		submit_button('Save settings');
		echo '</form>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:16px 0">';
		wp_nonce_field('genz_hub_smtp_test');
		echo '<input type="hidden" name="action" value="genz_hub_smtp_test">Send a test to <input type="email" name="to" value="' . esc_attr(get_option('admin_email')) . '"> ';
		submit_button('Send test email', 'secondary', 'submit', false);
		echo '</form>';
		self::mail_log_table(30);
		echo '</div>';
	}
	static function mail_log_table($n) {
		$log = get_option('genz_hub_mail_log');
		if (!is_array($log)) $log = [];
		echo '<h2>Recent emails</h2><table class="genz-a-list"><thead><tr><th>When</th><th>To</th><th>Subject</th><th>Status</th></tr></thead><tbody>';
		if (!$log) echo '<tr><td colspan="4">No emails logged yet.</td></tr>';
		foreach (array_slice((array) $log, 0, $n) as $l) {
			echo '<tr><td>' . esc_html(human_time_diff($l['t']) . ' ago') . '</td><td>' . esc_html($l['to']) . '</td><td>' . esc_html($l['s']) . '</td><td>' . ($l['st'] === 'sent' ? '<span style="color:#0a7a2f">Sent</span>' : '<span style="color:#b00020">Failed</span> ' . esc_html($l['e'])) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ------------------------------------------------------ overview hub */
	static function admin_quick_track() {
		if (!current_user_can('edit_shop_orders')) wp_die('No access');
		check_admin_referer('genz_hub_quick_track');
		$order = wc_get_order((int) preg_replace('/[^0-9]/', '', (string) ($_POST['order'] ?? '')));
		$no = self::clean_no(wp_unslash($_POST['tn'] ?? ''));
		$msg = 'bad';
		if ($order && strlen($no) >= 6) {
			$list = self::order_tracking($order);
			if (!in_array($no, array_column($list, 'no'), true)) {
				$list[] = ['no' => $no, 'carrier' => 'cainiao', 'added' => time()];
				$order->update_meta_data('_genz_tracking', $list);
				$order->add_order_note('Tracking number ' . $no . ' added from GenZ Hub.');
				$d = self::cainiao($no, true);
				if (!empty($d['events'][0])) $order->update_meta_data('_genz_trk_last_' . $no, md5($d['events'][0]['d'] . $d['events'][0]['t']));
				$order->save();
				$active = (array) get_option('genz_hub_active', []);
				$active[$order->get_id()] = 1;
				update_option('genz_hub_active', $active, false);
				if (!empty($_POST['notify'])) self::email_shipped($order, $no);
				if (!empty($_POST['complete']) && $order->get_status() !== 'completed') $order->update_status('completed', 'Marked as shipped with tracking.');
				$msg = 'ok';
			} else $msg = 'dup';
		}
		wp_safe_redirect(admin_url('admin.php?page=genz-hub&qt=' . $msg));
		exit;
	}
	static function admin_check_now() {
		if (!current_user_can('manage_woocommerce')) wp_die('No access');
		check_admin_referer('genz_hub_check_now');
		self::track_cron();
		wp_safe_redirect(admin_url('admin.php?page=genz-hub&checked=1'));
		exit;
	}
	static function page_hub() {
		global $wpdb;
		$active = (array) get_option('genz_hub_active', []);
		$subs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}genz_subscribers WHERE status='subscribed'");
		$unread = self::unread_count();
		$ready = self::smtp_ready();
		$next = wp_next_scheduled('genz_hub_track_cron');
		echo '<div class="wrap genz-a-wrap"><h1>GenZ Hub</h1><p>Everything for your customers in one place: tracking, messages, subscribers and emails.</p>';
		$qt = sanitize_key($_GET['qt'] ?? '');
		if ($qt === 'ok') echo '<div class="notice notice-success"><p>Tracking added. The customer has been emailed and will get automatic updates.</p></div>';
		if ($qt === 'dup') echo '<div class="notice notice-warning"><p>That tracking number is already on this order.</p></div>';
		if ($qt === 'bad') echo '<div class="notice notice-error"><p>Order not found or tracking number too short.</p></div>';
		if (!empty($_GET['checked'])) echo '<div class="notice notice-success"><p>All parcels checked. Customers were emailed where there was a new update.</p></div>';
		echo '<div class="genz-a-cards">';
		echo '<div><a href="' . esc_url(admin_url('admin.php?page=genz-inbox')) . '" style="text-decoration:none;color:inherit"><strong>' . $unread . '</strong>Messages to reply</a></div>';
		echo '<div><a href="' . esc_url(admin_url('admin.php?page=genz-tracking')) . '" style="text-decoration:none;color:inherit"><strong>' . count($active) . '</strong>Parcels being tracked</a></div>';
		echo '<div><a href="' . esc_url(admin_url('admin.php?page=genz-subscribers')) . '" style="text-decoration:none;color:inherit"><strong>' . $subs . '</strong>Subscribers</a></div>';
		echo '<div><a href="' . esc_url(admin_url('admin.php?page=genz-email')) . '" style="text-decoration:none;color:inherit"><strong style="color:' . ($ready ? '#0a7a2f' : '#b00020') . '">' . ($ready ? 'Inbox-ready' : 'Set up') . '</strong>Email delivery</a></div>';
		echo '</div>';
		$sk = self::skin();
		echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 20px;margin:16px 0;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between"><div><h2 style="margin:0 0 4px">Website design</h2>Current: <strong>' . ($sk === 'luxe' ? '3D Luxe Light' : 'Classic Dark Purple') . '</strong>. Switching back restores your old homepage and colours (backup kept).</div><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('genz_hub_skin');
		echo '<input type="hidden" name="action" value="genz_hub_skin"><input type="hidden" name="skin" value="' . ($sk === 'luxe' ? 'classic' : 'luxe') . '">';
		submit_button($sk === 'luxe' ? 'Switch back to Classic Dark' : 'Switch to 3D Luxe Light', $sk === 'luxe' ? 'secondary' : 'primary', 'submit', false);
		echo ' <a class="button" href="' . esc_url(home_url('/?genz_preview=luxe')) . '" target="_blank">Preview</a></form></div>';
		// Quick add tracking.
		$orders = wc_get_orders(['limit' => 30, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order', 'status' => ['processing', 'on-hold', 'completed']]);
		echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 20px;margin:16px 0"><h2 style="margin-top:0">Add tracking &amp; notify customer (one click)</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('genz_hub_quick_track');
		echo '<input type="hidden" name="action" value="genz_hub_quick_track"><select name="order" required style="min-width:320px"><option value="">Choose order...</option>';
		foreach ($orders as $o) {
			$items = [];
			foreach ($o->get_items() as $it) $items[] = $it->get_name() . ' x' . $it->get_quantity();
			$has = self::order_tracking($o) ? ' [tracked]' : '';
			echo '<option value="' . (int) $o->get_id() . '">#' . esc_html($o->get_order_number() . ' ' . $o->get_formatted_billing_full_name() . ' - ' . mb_substr(implode(', ', $items), 0, 60) . $has) . '</option>';
		}
		echo '</select> <input type="text" name="tn" placeholder="Tracking number" required style="min-width:240px"> ';
		echo '<label><input type="checkbox" name="notify" value="1" checked> Email customer</label> <label><input type="checkbox" name="complete" value="1"> Mark completed</label> ';
		submit_button('Add & send', 'primary', 'submit', false);
		echo '</form></div>';
		// Parcels.
		echo '<div style="display:flex;justify-content:space-between;align-items:center"><h2>Parcels</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('genz_hub_check_now');
		echo '<input type="hidden" name="action" value="genz_hub_check_now">';
		submit_button('Check all parcels now', 'secondary', 'submit', false);
		echo '</form></div><p class="description">Automatic check every 3 hours &middot; next: ' . ($next ? esc_html(wp_date('j M, H:i', $next)) : '-') . '</p>';
		echo '<table class="genz-a-list"><thead><tr><th>Order</th><th>Customer</th><th>Tracking</th><th>Status</th><th>Latest update</th></tr></thead><tbody>';
		if (!$active) echo '<tr><td colspan="5">No parcels being tracked. Add one above.</td></tr>';
		$labels = ['Order placed', 'Processing', 'Shipped', 'In transit', 'Out for delivery', 'Delivered'];
		foreach (array_keys($active) as $oid) {
			$o = wc_get_order($oid);
			if (!$o) continue;
			foreach (self::order_tracking($o) as $t) {
				$d = self::cainiao($t['no']);
				$stage = self::stage($o, $d);
				$ev = !empty($d['events'][0]) ? esc_html($d['events'][0]['d']) . '<br><small>' . esc_html($d['events'][0]['ts']) . '</small>' : 'Waiting for first scan';
				echo '<tr><td><a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a></td><td>' . esc_html($o->get_formatted_billing_full_name()) . '</td><td><a href="' . esc_url(self::track_link($o, $t['no'])) . '" target="_blank">' . esc_html($t['no']) . '</a></td><td><strong>' . esc_html($labels[$stage]) . '</strong></td><td>' . $ev . '</td></tr>'; // phpcs:ignore
			}
		}
		echo '</tbody></table>';
		self::mail_log_table(10);
		echo '<h2>Shortcuts</h2><p><a class="button" href="' . esc_url(admin_url('admin.php?page=genz-inbox')) . '">Inbox</a> <a class="button" href="' . esc_url(admin_url('admin.php?page=genz-subscribers')) . '">Subscribers &amp; send update</a> <a class="button" href="' . esc_url(admin_url('admin.php?page=genz-email')) . '">Email delivery</a> <a class="button" href="' . esc_url(self::track_url()) . '" target="_blank">Customer tracking page</a></p>';
		echo '</div>';
	}

	/* ------------------------------------------------------------ admin */
	static function unread_count() {
		global $wpdb;
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}genz_threads WHERE unread_admin>0");
	}
	static function admin_menu() {
		$n = self::unread_count();
		$bubble = $n ? ' <span class="awaiting-mod count-' . $n . '"><span class="pending-count">' . $n . '</span></span>' : '';
		add_menu_page('GenZ Hub', 'GenZ Hub' . $bubble, 'manage_woocommerce', 'genz-hub', [__CLASS__, 'page_hub'], 'dashicons-store', 3);
		add_submenu_page('genz-hub', 'GenZ Hub', 'Overview', 'manage_woocommerce', 'genz-hub', [__CLASS__, 'page_hub']);
		add_submenu_page('genz-hub', 'Customer Inbox', 'Inbox' . $bubble, 'manage_woocommerce', 'genz-inbox', [__CLASS__, 'page_inbox']);
		add_submenu_page('genz-hub', 'Order Tracking', 'Order Tracking', 'manage_woocommerce', 'genz-tracking', [__CLASS__, 'page_tracking']);
		add_submenu_page('genz-hub', 'Subscribers', 'Subscribers', 'manage_woocommerce', 'genz-subscribers', [__CLASS__, 'page_subscribers']);
		add_submenu_page('genz-hub', 'Email Delivery', 'Email Delivery', 'manage_options', 'genz-email', [__CLASS__, 'page_email']);
	}
	static function admin_css() {
		$s = get_current_screen();
		if (!$s || strpos($s->id, 'genz-') === false) return;
		echo '<style>.genz-a-wrap{max-width:1100px}.genz-a-list{width:100%;border-collapse:collapse;background:#fff}.genz-a-list td,.genz-a-list th{padding:10px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}.genz-a-list tr.unread td{background:#fffbe6;font-weight:600}.genz-a-thread{background:#f6f7f7;border:1px solid #ddd;border-radius:8px;padding:16px;max-height:520px;overflow:auto}.genz-a-b{max-width:75%;padding:10px 12px;border-radius:10px;margin:0 0 10px;white-space:pre-wrap}.genz-a-b.customer{background:#fff;border:1px solid #e2e2e2}.genz-a-b.admin{background:#1d2327;color:#fff;margin-left:auto}.genz-a-b small{display:block;opacity:.65;font-size:11px;margin-top:4px}.genz-a-cards{display:flex;gap:12px;margin:16px 0}.genz-a-cards div{background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 18px;min-width:150px}.genz-a-cards strong{display:block;font-size:22px}.genz-a-cards{flex-wrap:wrap}@media (max-width:782px){.genz-a-cards div{flex:1 1 40%;min-width:0}.genz-a-list{display:block;overflow-x:auto;white-space:normal}.genz-a-wrap select,.genz-a-wrap input[type=text],.genz-a-wrap input[type=email],.genz-a-wrap input[type=number]{width:100%!important;max-width:none!important;margin:4px 0}.genz-a-b{max-width:92%}.genz-a-wrap form .button{margin-top:6px}}</style>';
	}
	static function page_inbox() {
		global $wpdb;
		$tt = $wpdb->prefix . 'genz_threads';
		$tid = isset($_GET['thread']) ? (int) $_GET['thread'] : 0;
		echo '<div class="wrap genz-a-wrap">';
		if ($tid) {
			$t = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tt WHERE id=%d", $tid));
			if (!$t) { echo '<p>Conversation not found.</p></div>'; return; }
			$wpdb->update($tt, ['unread_admin' => 0], ['id' => $t->id]);
			$msgs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}genz_messages WHERE thread_id=%d ORDER BY id ASC", $t->id));
			echo '<p><a href="' . esc_url(admin_url('admin.php?page=genz-inbox')) . '">&larr; All conversations</a></p>';
			echo '<h1>' . esc_html($t->name) . ' <small style="font-size:14px;font-weight:normal">&lt;' . esc_html($t->email) . '&gt;</small></h1>';
			$meta = [];
			if ($t->order_ref) {
				$o = wc_get_order((int) $t->order_ref);
				$meta[] = $o ? 'Order <a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a> (' . esc_html(wc_get_order_status_name($o->get_status())) . ')' : 'Order ref: ' . esc_html($t->order_ref);
			}
			$orders = wc_get_orders(['billing_email' => $t->email, 'limit' => 5, 'return' => 'objects', 'type' => 'shop_order']);
			if ($orders) {
				$l = [];
				foreach ($orders as $o) $l[] = '<a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a> ' . esc_html(wc_get_order_status_name($o->get_status()));
				$meta[] = 'Orders with this email: ' . implode(', ', $l);
			}
			$meta[] = 'Status: <strong>' . esc_html(ucfirst($t->status)) . '</strong>';
			echo '<p>' . implode(' &middot; ', $meta) . '</p>'; // phpcs:ignore
			echo '<div class="genz-a-thread" id="genz-a-thread">';
			foreach ($msgs as $m) echo '<div class="genz-a-b ' . esc_attr($m->sender) . '">' . esc_html($m->body) . '<small>' . ($m->sender === 'admin' ? 'You' : esc_html($t->name)) . ' &middot; ' . esc_html(wp_date('j M Y, H:i', strtotime($m->created . ' UTC'))) . '</small></div>';
			echo '</div><script>var g=document.getElementById("genz-a-thread");g.scrollTop=g.scrollHeight;</script>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:14px">';
			wp_nonce_field('genz_hub_reply');
			echo '<input type="hidden" name="action" value="genz_hub_reply"><input type="hidden" name="thread" value="' . (int) $t->id . '">';
			$vars = self::quick_vars($t);
			echo '<div class="genz-a-quick"><strong>Quick replies:</strong> ';
			foreach (self::quick_replies() as $q) echo '<button type="button" class="button button-small" data-q="' . esc_attr(strtr($q['b'], $vars)) . '">' . esc_html($q['t']) . '</button> ';
			echo '<a href="' . esc_url(admin_url('admin.php?page=genz-inbox#genz-quick')) . '" style="margin-left:6px">Edit</a></div>';
			echo '<textarea id="genz-a-reply" name="reply" rows="7" style="width:100%;margin-top:8px" placeholder="Write your reply... (the customer gets it by email and in the chat)" required></textarea>';
			echo '<script>document.querySelectorAll(".genz-a-quick [data-q]").forEach(function(b){b.addEventListener("click",function(){var t=document.getElementById("genz-a-reply");t.value=t.value?t.value+"\n\n"+b.getAttribute("data-q"):b.getAttribute("data-q");t.focus();});});</script>';
			echo '<p><label><input type="checkbox" name="close" value="1"> Mark as resolved after sending</label></p>';
			submit_button('Send reply', 'primary', 'submit', false);
			echo '</form>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
			wp_nonce_field('genz_hub_thread_status');
			echo '<input type="hidden" name="action" value="genz_hub_thread_status"><input type="hidden" name="thread" value="' . (int) $t->id . '"><input type="hidden" name="status" value="' . ($t->status === 'open' ? 'closed' : 'open') . '">';
			submit_button($t->status === 'open' ? 'Mark as resolved' : 'Reopen', 'secondary', 'submit', false);
			echo '</form></div>';
			return;
		}
		$f = isset($_GET['f']) ? sanitize_key($_GET['f']) : 'open';
		$where = $f === 'closed' ? "status='closed'" : ($f === 'all' ? '1=1' : "status='open'");
		$rows = $wpdb->get_results("SELECT * FROM $tt WHERE $where ORDER BY last_at DESC LIMIT 200");
		$open = (int) $wpdb->get_var("SELECT COUNT(*) FROM $tt WHERE status='open'");
		echo '<h1>Customer Inbox</h1><div class="genz-a-cards"><div><strong>' . self::unread_count() . '</strong>Need a reply</div><div><strong>' . $open . '</strong>Open</div></div>';
		echo '<ul class="subsubsub">';
		foreach (['open' => 'Open', 'closed' => 'Resolved', 'all' => 'All'] as $k => $l) echo '<li><a href="' . esc_url(admin_url('admin.php?page=genz-inbox&f=' . $k)) . '"' . ($f === $k ? ' class="current"' : '') . '>' . $l . '</a> | </li>';
		echo '</ul><br class="clear">';
		echo '<table class="genz-a-list"><thead><tr><th>Customer</th><th>Last message</th><th>Order</th><th>When</th></tr></thead><tbody>';
		if (!$rows) echo '<tr><td colspan="4">No conversations yet. Messages from the website chat will appear here.</td></tr>';
		foreach ($rows as $r) {
			$url = admin_url('admin.php?page=genz-inbox&thread=' . (int) $r->id);
			echo '<tr class="' . ($r->unread_admin ? 'unread' : '') . '"><td><a href="' . esc_url($url) . '"><strong>' . esc_html($r->name) . '</strong></a><br><small>' . esc_html($r->email) . '</small></td><td><a href="' . esc_url($url) . '" style="color:inherit;text-decoration:none">' . esc_html($r->last_preview) . '</a>' . ($r->unread_admin ? ' <span class="awaiting-mod">' . (int) $r->unread_admin . ' new</span>' : '') . '</td><td>' . ($r->order_ref ? '#' . esc_html($r->order_ref) : '&ndash;') . '</td><td>' . esc_html(human_time_diff(strtotime($r->last_at . ' UTC')) . ' ago') . '</td></tr>';
		}
		echo '</tbody></table>';
		if (!empty($_GET['quick'])) echo '<div class="notice notice-success"><p>Quick replies saved.</p></div>';
		echo '<h2 id="genz-quick" style="margin-top:36px">Quick replies</h2><p>Use <code>{name}</code>, <code>{order}</code>, <code>{tracking}</code>, <code>{track_link}</code> and <code>{shop}</code> &ndash; they are filled in automatically from the customer&#39;s latest order. Leave a title empty to delete a reply.</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('genz_hub_quick_save');
		echo '<input type="hidden" name="action" value="genz_hub_quick_save"><table class="genz-a-list">';
		$qs = self::quick_replies();
		for ($i = 0; $i < count($qs) + 3; $i++) {
			$q = $qs[$i] ?? ['t' => '', 'b' => ''];
			echo '<tr><td style="width:200px"><input type="text" name="qt[]" value="' . esc_attr($q['t']) . '" placeholder="Title" style="width:100%"></td><td><textarea name="qb[]" rows="3" style="width:100%">' . esc_textarea($q['b']) . '</textarea></td></tr>';
		}
		echo '</table>';
		submit_button('Save quick replies');
		echo '</form></div>';
	}
	static function quick_defaults() {
		return [
			['t' => 'Greeting', 'b' => "Hi {name},\n\nThanks for getting in touch with GenZ Gadgets Store! I'm looking into this for you now and will update you shortly."],
			['t' => 'Tracking details', 'b' => "Hi {name},\n\nGood news - your order #{order} is on its way.\nTracking number: {tracking}\nLive tracking: {track_link}\n\nYou'll also get an email every time your parcel moves. Delivery usually takes 3-7 working days."],
			['t' => 'Not shipped yet', 'b' => "Hi {name},\n\nYour order #{order} is being prepared for dispatch. As soon as it ships we'll email you the tracking number, and you can follow it here: {track_link}"],
			['t' => 'Delay apology', 'b' => "Hi {name},\n\nI'm sorry your order #{order} is taking longer than expected. It is moving through the courier network and the latest update is on {track_link}. We're keeping a close eye on it and will update you as soon as it moves."],
			['t' => 'Returns', 'b' => "Hi {name},\n\nNo problem at all - you have 30 days to return your item. Please reply with your order number and the reason for the return, and we'll send you the return instructions straight away."],
			['t' => 'Refund done', 'b' => "Hi {name},\n\nYour refund for order #{order} has been processed. It usually shows on your account within 3-5 working days depending on your bank."],
			['t' => 'Thanks / close', 'b' => "Thanks {name}! If there's anything else you need, just message us here any time. Have a lovely day - GenZ Gadgets Store"],
		];
	}
	static function quick_replies() {
		$q = get_option('genz_hub_quick');
		return is_array($q) && $q ? $q : self::quick_defaults();
	}
	static function admin_quick_save() {
		if (!current_user_can('manage_woocommerce')) wp_die('No access');
		check_admin_referer('genz_hub_quick_save');
		$out = [];
		$titles = (array) ($_POST['qt'] ?? []);
		$bodies = (array) ($_POST['qb'] ?? []);
		foreach ($titles as $i => $t) {
			$t = sanitize_text_field(wp_unslash($t));
			$b = sanitize_textarea_field(wp_unslash($bodies[$i] ?? ''));
			if ($t !== '' && $b !== '') $out[] = ['t' => $t, 'b' => $b];
		}
		update_option('genz_hub_quick', $out ?: self::quick_defaults(), false);
		wp_safe_redirect(admin_url('admin.php?page=genz-inbox&quick=1#genz-quick'));
		exit;
	}
	static function quick_vars($t) {
		$order = null;
		if ($t->order_ref) { $o = wc_get_order((int) $t->order_ref); if ($o && strtolower($o->get_billing_email()) === strtolower($t->email)) $order = $o; }
		if (!$order) { $os = wc_get_orders(['billing_email' => $t->email, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order']); if ($os) $order = $os[0]; }
		$nos = $order ? array_column(self::order_tracking($order), 'no') : [];
		$first = trim(strtok($t->name, ' ')) ?: $t->name;
		return [
			'{name}' => $first,
			'{order}' => $order ? $order->get_order_number() : ($t->order_ref ?: '(order number)'),
			'{tracking}' => $nos ? implode(', ', $nos) : '(not shipped yet)',
			'{track_link}' => $nos ? self::track_link($order, $nos[0]) : self::track_url(),
			'{shop}' => home_url('/shop/'),
		];
	}
	static function admin_reply() {
		if (!current_user_can('manage_woocommerce')) wp_die('No access');
		check_admin_referer('genz_hub_reply');
		global $wpdb;
		$tid = (int) ($_POST['thread'] ?? 0);
		$body = trim(sanitize_textarea_field(wp_unslash($_POST['reply'] ?? '')));
		$t = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}genz_threads WHERE id=%d", $tid));
		if ($t && $body !== '') {
			$wpdb->insert($wpdb->prefix . 'genz_messages', ['thread_id' => $t->id, 'sender' => 'admin', 'body' => $body, 'created' => self::now()]);
			$status = !empty($_POST['close']) ? 'closed' : 'open';
			$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}genz_threads SET unread_customer=unread_customer+1, unread_admin=0, status=%s, last_at=%s, last_preview=%s WHERE id=%d", $status, self::now(), mb_substr('You: ' . $body, 0, 190), $t->id));
			$link = $t->user_id ? wc_get_account_endpoint_url('messages') : add_query_arg('genz_chat', $t->token, home_url('/'));
			self::mail($t->email, 'Reply from GenZ Gadgets Store', 'We have replied to your message', '<p>Hi ' . esc_html($t->name) . ',</p><blockquote style="border-left:3px solid #ffcc00;margin:0;padding:10px 14px;background:#f7f7f7">' . nl2br(esc_html($body)) . '</blockquote>' . self::btn($link, 'Reply in chat') . '<p style="font-size:12px;color:#777">You can also simply reply to this email.</p>');
		}
		wp_safe_redirect(admin_url('admin.php?page=genz-inbox&thread=' . $tid . '&sent=1'));
		exit;
	}
	static function admin_thread_status() {
		if (!current_user_can('manage_woocommerce')) wp_die('No access');
		check_admin_referer('genz_hub_thread_status');
		global $wpdb;
		$s = ($_POST['status'] ?? '') === 'closed' ? 'closed' : 'open';
		$wpdb->update($wpdb->prefix . 'genz_threads', ['status' => $s, 'unread_admin' => 0], ['id' => (int) ($_POST['thread'] ?? 0)]);
		wp_safe_redirect(admin_url('admin.php?page=genz-inbox' . ($s === 'closed' ? '' : '&thread=' . (int) $_POST['thread'])));
		exit;
	}
	static function page_subscribers() {
		global $wpdb;
		$t = $wpdb->prefix . 'genz_subscribers';
		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='subscribed'");
		$unsub = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status<>'subscribed'");
		$rows = $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 300");
		$job = get_option('genz_hub_job');
		$last = get_option('genz_hub_last_job');
		echo '<div class="wrap genz-a-wrap"><h1>Subscribers</h1>';
		if (!empty($_GET['queued'])) echo '<div class="notice notice-success"><p>Your email is being sent in the background (40 emails per minute).</p></div>';
		echo '<div class="genz-a-cards"><div><strong>' . $total . '</strong>Subscribed</div><div><strong>' . $unsub . '</strong>Unsubscribed</div>';
		if (is_array($job)) echo '<div><strong>' . (int) $job['sent'] . '</strong>Sending now: ' . esc_html($job['subject']) . '</div>';
		elseif (is_array($last)) echo '<div><strong>' . (int) $last['sent'] . '</strong>Last email: ' . esc_html($last['subject']) . '</div>';
		echo '</div>';
		echo '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=genz_hub_export'), 'genz_hub_export')) . '">Export CSV</a></p>';
		echo '<h2>Send an update to all subscribers</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('genz_hub_broadcast');
		echo '<input type="hidden" name="action" value="genz_hub_broadcast">';
		echo '<p><label>Subject<br><input type="text" name="subject" class="large-text" required placeholder="e.g. New in: heated gilets + free UK delivery"></label></p>';
		echo '<p><label>Heading<br><input type="text" name="heading" class="large-text" placeholder="e.g. Fresh gadgets just landed"></label></p>';
		wp_editor('', 'genz_body', ['textarea_name' => 'body', 'textarea_rows' => 10, 'media_buttons' => true]);
		echo '<p><label><input type="checkbox" name="test" value="1" checked> Send a test to ' . esc_html(get_option('admin_email')) . ' only (untick to send to all ' . $total . ' subscribers)</label></p>';
		submit_button('Send email');
		echo '</form>';
		echo '<h2>Latest subscribers</h2><table class="genz-a-list"><thead><tr><th>Email</th><th>Name</th><th>Source</th><th>Status</th><th>Joined</th><th></th></tr></thead><tbody>';
		if (!$rows) echo '<tr><td colspan="6">No subscribers yet. The sign-up form is shown above your site footer and as an option at checkout.</td></tr>';
		foreach ($rows as $r) {
			$del = wp_nonce_url(admin_url('admin-post.php?action=genz_hub_sub_delete&id=' . (int) $r->id), 'genz_hub_sub_delete');
			echo '<tr><td>' . esc_html($r->email) . '</td><td>' . esc_html($r->name) . '</td><td>' . esc_html($r->source) . '</td><td>' . esc_html($r->status) . '</td><td>' . esc_html(wp_date('j M Y', strtotime($r->created . ' UTC'))) . '</td><td><a href="' . esc_url($del) . '" onclick="return confirm(\'Delete this subscriber?\')">Delete</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}
	static function admin_export() {
		if (!current_user_can('manage_woocommerce')) wp_die('No access');
		check_admin_referer('genz_hub_export');
		global $wpdb;
		$rows = $wpdb->get_results("SELECT email,name,status,source,created FROM {$wpdb->prefix}genz_subscribers ORDER BY id ASC", ARRAY_A);
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=genz-subscribers-' . gmdate('Y-m-d') . '.csv');
		$o = fopen('php://output', 'w');
		fputcsv($o, ['email', 'name', 'status', 'source', 'created']);
		foreach ($rows as $r) fputcsv($o, $r);
		fclose($o);
		exit;
	}
	static function admin_sub_delete() {
		if (!current_user_can('manage_woocommerce')) wp_die('No access');
		check_admin_referer('genz_hub_sub_delete');
		global $wpdb;
		$wpdb->delete($wpdb->prefix . 'genz_subscribers', ['id' => (int) ($_GET['id'] ?? 0)]);
		wp_safe_redirect(admin_url('admin.php?page=genz-subscribers'));
		exit;
	}
	static function admin_broadcast() {
		if (!current_user_can('manage_woocommerce')) wp_die('No access');
		check_admin_referer('genz_hub_broadcast');
		$subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
		$heading = sanitize_text_field(wp_unslash($_POST['heading'] ?? '')) ?: $subject;
		$html = wpautop(wp_kses_post(wp_unslash($_POST['body'] ?? '')));
		if ($subject === '' || trim(wp_strip_all_tags($html)) === '') wp_die('Please add a subject and a message. <a href="javascript:history.back()">Back</a>');
		if (!empty($_POST['test'])) {
			self::mail(get_option('admin_email'), '[TEST] ' . $subject, $heading, $html . '<p style="font-size:12px;color:#888">Unsubscribe link appears here for subscribers.</p>');
			wp_safe_redirect(admin_url('admin.php?page=genz-subscribers&tested=1'));
			exit;
		}
		if (get_option('genz_hub_job')) wp_die('An email is already being sent. Please wait for it to finish. <a href="javascript:history.back()">Back</a>');
		update_option('genz_hub_job', ['subject' => $subject, 'heading' => $heading, 'html' => $html, 'last_id' => 0, 'sent' => 0, 'started' => time()], false);
		wp_schedule_single_event(time(), 'genz_hub_send_batch');
		wp_safe_redirect(admin_url('admin.php?page=genz-subscribers&queued=1'));
		exit;
	}
	static function page_tracking() {
		$active = (array) get_option('genz_hub_active', []);
		echo '<div class="wrap genz-a-wrap"><h1>Order Tracking</h1>';
		echo '<p>Add a Cainiao tracking number from the <strong>Tracking &amp; customer updates</strong> box on any order. The customer is emailed straight away, the parcel is checked every 3 hours, and the customer gets an email each time it moves (at most every 6 hours, plus out-for-delivery and delivered).</p>';
		echo '<p>Customer tracking page: <a href="' . esc_url(self::track_url()) . '" target="_blank">' . esc_html(self::track_url()) . '</a></p>';
		$next = wp_next_scheduled('genz_hub_track_cron');
		echo '<p>Next automatic check: ' . ($next ? esc_html(wp_date('j M Y, H:i', $next)) : 'not scheduled') . '</p>';
		if (isset($_GET['preview'])) echo '<div class="notice notice-' . ($_GET['preview'] ? 'success' : 'error') . '"><p>' . ($_GET['preview'] ? 'Preview sent to ' . esc_html(get_option('admin_email')) . '.' : 'Order not found.') . '</p></div>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 16px;margin:16px 0">';
		wp_nonce_field('genz_hub_preview');
		echo '<input type="hidden" name="action" value="genz_hub_preview"><strong>Preview the &quot;order shipped&quot; email</strong> (sent to you only): Order ID <input type="number" name="order" style="width:90px" required> Tracking no. <input type="text" name="tn" required> ';
		submit_button('Send preview to me', 'secondary', 'submit', false);
		echo '</form>';
		echo '<table class="genz-a-list"><thead><tr><th>Order</th><th>Tracking</th><th>Latest update</th></tr></thead><tbody>';
		if (!$active) echo '<tr><td colspan="3">No parcels being tracked right now.</td></tr>';
		foreach (array_keys($active) as $oid) {
			$o = wc_get_order($oid);
			if (!$o) continue;
			foreach (self::order_tracking($o) as $t) {
				$d = get_transient('genz_cn_' . md5($t['no']));
				$st = is_array($d) && !empty($d['events'][0]) ? $d['events'][0]['ts'] . ' &ndash; ' . esc_html($d['events'][0]['d']) : 'Waiting for first scan';
				echo '<tr><td><a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a> ' . esc_html($o->get_formatted_billing_full_name()) . '</td><td>' . esc_html($t['no']) . '</td><td>' . $st . '</td></tr>'; // phpcs:ignore
			}
		}
		echo '</tbody></table></div>';
	}
}
GenZ_Hub::init();
