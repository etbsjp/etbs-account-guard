<?php
/**
 * Plugin Name:       ETBS Account Guard
 * Plugin URI:        https://etbs.jp/product-category/wordpress-tools/
 * Description:       Protects the accounts that manage your site. Hides the login names of your users from visitors who are not logged in.
 * Version:           1.1.0
 * Author:            ETBS (DAI)
 * Author URI:        https://etbs.jp
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       etbs-account-guard
 * Domain Path:       /languages
 *
 * @package etbs-account-guard
 */

// Exit if accessed directly. Must come before any executable code, including the define below.
// 直接アクセスされた場合は終了する。下の define も実行されるコードなので、それより前に置く。
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Absolute path of this main file. Used to find this plugin's own row in plugin_row_meta,
 * to register the bundled translations and to give the update checker the plugin file.
 * 本体ファイルの絶対パス。plugin_row_meta で自分の行を見分ける・同梱の翻訳を登録する・
 * 更新チェッカーに本体ファイルを渡す、の3か所で使う。
 */
define( 'ACGD_PLUGIN_FILE', __FILE__ );

// The implementation lives in inc/. This file only loads it.
// 実装は inc/ に置く。このファイルは読み込むだけ。
require_once __DIR__ . '/inc/func.php';

/*
 * The dashboard widget and the update checker are separate files so that a WordPress.org build
 * can drop them by removing these two lines (WordPress.org does not allow self-updaters).
 * ダッシュボードのウィジェットと更新チェッカーは別ファイルにしてあり、wordpress.org 版では
 * この2行を消せば外せる（wordpress.org は独自の更新機構を認めていないため）。
 */
require_once __DIR__ . '/inc/dashboard-widget.php';
require_once __DIR__ . '/inc/update-checker.php';
