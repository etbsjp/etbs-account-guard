<?php
/**
 * Plugin Name:       ETBS Account Guard
 * Plugin URI:        https://etbs.jp/product/etbs-account-guard/
 * Description:       Protects the accounts that manage your site. Hides the login names of your users from visitors who are not logged in.
 * Version:           1.2.1
 * Requires PHP:      7.3
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
 * Absolute path of this main file. Used to find this plugin's own row in plugin_row_meta
 * and to register the bundled translations.
 * 本体ファイルの絶対パス。plugin_row_meta で自分の行を見分ける・同梱の翻訳を登録する、の2か所で使う。
 */
define( 'ACGD_PLUGIN_FILE', __FILE__ );

// The implementation lives in inc/. This file only loads it.
// 実装は inc/ に置く。このファイルは読み込むだけ。
require_once __DIR__ . '/inc/func.php';
