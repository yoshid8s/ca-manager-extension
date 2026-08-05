<?php
/**
 * CA Manager (Extended)
 *
 * Plugin Name: CA Manager Extended Edition (Unofficial)
 * Description: WordPress での記事公開時の Content Attestation (CA) 発行を支援するプラグインです。投稿編集画面で記事CA・広告CA・埋め込みコンテンツCAを一元管理できます。本バージョンでの広告CAおよび埋め込みコンテンツCAは、発信者による自己申告に基づくものであり、第三者コンテンツの真正性を保証するものではありません。
 * Version: 0.4.9-x-context-ad
 * Author: Originator Profile Collaborative Innovation Partnership
 * Author URI: https://originator-profile.org/
 * License: MIT
 *
 * Extended features:
 * - 投稿編集画面でのCAマネージャーUI追加（記事CA / 広告CA / 埋め込みコンテンツ）
 * - 広告CA（OnlineAd）の発行対応
 * - 埋め込み画像・テキストのCA発行
 * - XへのCAブロックのシェア機能
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/includes/admin.php';
\Profile\Admin\init();
\add_filter( 'plugin_action_links_' . \plugin_basename( __FILE__ ), '\Profile\Admin\add_action_links' );

require_once __DIR__ . '/includes/issue.php';
\Profile\Issue\init();

require_once __DIR__ . '/includes/post.php';
\Profile\Post\init();

require_once __DIR__ . '/includes/class-cam-ad-db.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/admin-ad-content.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/context-ads.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/rest-context-ads.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/front-ad-insert.php';

require_once __DIR__ . '/includes/front-assigned-ad.php';
\Profile\FrontAssignedAd\init();

require_once __DIR__ . '/includes/helpers-ui.php';
require_once __DIR__ . '/includes/admin-embedded-content.php';
require_once __DIR__ . '/includes/admin-assets.php';
require_once __DIR__ . '/includes/admin-ui.php';

require_once __DIR__ . '/includes/activator.php';
register_activation_hook( __FILE__, 'Profile\\Activator\\ca_manager_activate' );

// srcset を無効化（CAの画像検証ズレ防止）
add_filter( 'wp_calculate_image_srcset', '__return_false' );
