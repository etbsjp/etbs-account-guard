<?php
/**
 * Entry point of the implementation: modules, translations and support links.
 * 実装本体の入口。モジュールの読み込み・翻訳の登録・支援リンクを扱う。
 *
 * The protections themselves are in class-acgd-login-name.php and the settings screen is in
 * class-acgd-settings.php. The dashboard widget and the update checker are loaded from the main file.
 * 保護の本体は class-acgd-login-name.php、設定画面は class-acgd-settings.php にある。
 * ダッシュボードのウィジェットと更新チェッカーは本体ファイルから読み込む。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-acgd-invalid-credentials.php';
require_once __DIR__ . '/class-acgd-login-name.php';
require_once __DIR__ . '/class-acgd-settings.php';

/*-------------------------------------------*/
/* Translations / 翻訳
/*-------------------------------------------*/

/**
 * Registers the translations bundled with this plugin.
 * このプラグインに同梱した翻訳を登録する。
 *
 * The Domain Path header alone is not enough on WordPress 7.1: the path of bundled translations is
 * registered only through load_plugin_textdomain(). On 7.1 this call does not read the file either;
 * it registers the path for the first __() to use, so is_textdomain_loaded() is still false right
 * after it. Check the result with switch_to_locale() and __() instead.
 * It is hooked to init because that is where WordPress expects translations to be set up
 * (from 6.7 on, a __() that runs before after_setup_theme triggers a _doing_it_wrong() notice).
 *
 * WordPress 7.1 では Domain Path ヘッダだけでは同梱の訳は読まれない。同梱訳のパスは
 * load_plugin_textdomain() でしか登録されない。7.1 ではこの呼び出しもファイルを読まず、
 * 最初の __() が使うパスを登録するだけなので、直後の is_textdomain_loaded() は false のまま。
 * 効いているかは switch_to_locale() と __() の戻り値で確かめる。
 * init に掛けるのは、WordPress が翻訳の準備を想定している場所がそこだから
 * （6.7 以降、after_setup_theme より前の __() は _doing_it_wrong() の通知になる）。
 *
 * @return void
 */
function acgd_load_textdomain() {
	load_plugin_textdomain(
		'etbs-account-guard',
		false,
		dirname( plugin_basename( ACGD_PLUGIN_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'acgd_load_textdomain' );

/*-------------------------------------------*/
/* Modules / モジュール
/*-------------------------------------------*/

ACGD_Invalid_Credentials::init();
ACGD_Login_Name::init();
ACGD_Settings::init();

/*-------------------------------------------*/
/* Support links / 支援・依頼リンク
/*-------------------------------------------*/

/**
 * Returns the URL of a support page, with the UTM parameters of this plugin.
 * このプラグインの UTM パラメータ付きで、支援ページの URL を返す。
 *
 * @param string $page Which page: 'donate' (support development) or 'request' (request development).
 *                     どのページか。'donate'（開発を支援）または 'request'（開発のご依頼）。
 * @return string The URL, not escaped. / URL（未エスケープ）。
 */
function acgd_get_support_url( $page ) {
	$urls = array(
		'donate'  => 'https://etbs.jp/product/donate/',
		'request' => 'https://etbs.jp/product-category/wordpress-tools/',
	);

	// Fall back to the request page for an unknown key rather than returning an empty link.
	// 未知のキーは空のリンクにせず、依頼ページへ倒す。
	$url = isset( $urls[ $page ] ) ? $urls[ $page ] : $urls['request'];

	return add_query_arg(
		array(
			'utm_source' => 'etbs-account-guard',
			'utm_medium' => 'plugin',
		),
		$url
	);
}

/**
 * Returns a support link that opens in a new tab.
 * 新しいタブで開く支援リンクを返す。
 *
 * @param string $page Which page: 'donate' or 'request'. / どのページか（'donate' または 'request'）。
 * @param string $text Link text, not escaped. / リンク文言（未エスケープ）。
 * @return string The escaped HTML of the link. / エスケープ済みのリンク HTML。
 */
function acgd_get_support_link( $page, $text ) {
	return sprintf(
		'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
		esc_url( acgd_get_support_url( $page ) ),
		esc_html( $text )
	);
}

/**
 * Returns the two support sentences shared by the settings screen footer and the dashboard widget.
 * 設定画面のフッターとダッシュボードのウィジェットで共通に使う、支援の案内2文を返す。
 *
 * Each sentence is a separate translation, as the translation functions take one sentence each.
 * 翻訳関数には1文ずつ入れる決まりなので、2文は別々の翻訳にしている。
 *
 * @return string The escaped HTML of the two sentences. / エスケープ済みの2文の HTML。
 */
function acgd_get_support_sentences() {
	$donate = sprintf(
		/* translators: %s: link inviting the reader to support the development of this plugin */
		esc_html__( 'If ETBS Account Guard is useful to you, please %s.', 'etbs-account-guard' ),
		acgd_get_support_link( 'donate', __( 'consider supporting its development', 'etbs-account-guard' ) )
	);
	$request = sprintf(
		/* translators: %s: link for requesting custom development */
		esc_html__( 'For custom development, please %s.', 'etbs-account-guard' ),
		acgd_get_support_link( 'request', __( 'send us a request', 'etbs-account-guard' ) )
	);

	return $donate . ' ' . $request;
}

/**
 * Adds the support links to this plugin's row on the Plugins screen.
 * プラグイン一覧の、このプラグインの行に支援・依頼リンクを足す。
 *
 * Accepts all four arguments of the filter. Registering it with fewer makes callbacks of other
 * plugins that expect four fail with ArgumentCountError (a known pitfall).
 * フィルタの4引数をすべて受ける。少ない引数で登録すると、4引数を期待する他プラグインの
 * コールバックが ArgumentCountError で落ちる（既知の罠）。
 *
 * @param string[] $links       Links shown in the row. / 行に表示されるリンク。
 * @param string   $file        Plugin file relative to the plugins directory. / プラグインのファイル（plugins からの相対パス）。
 * @param array    $plugin_data Plugin header data. Unused. / プラグインヘッダの情報（未使用）。
 * @param string   $status      Plugin status. Unused. / プラグインの状態（未使用）。
 * @return string[] Links. / リンク。
 */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- All four arguments are accepted on purpose; see the docblock.
function acgd_plugin_row_meta( $links, $file, $plugin_data = array(), $status = '' ) {
	// Only this plugin's own row. / 自分の行だけに足す。
	if ( plugin_basename( ACGD_PLUGIN_FILE ) !== $file ) {
		return $links;
	}

	$links[] = acgd_get_support_link( 'donate', __( 'Support development', 'etbs-account-guard' ) );
	$links[] = acgd_get_support_link( 'request', __( 'Request development', 'etbs-account-guard' ) );

	return $links;
}
add_filter( 'plugin_row_meta', 'acgd_plugin_row_meta', 10, 4 );

/**
 * Replaces the admin footer text on this plugin's settings screen with the support sentences.
 * このプラグインの設定画面でだけ、管理画面フッターの文言を支援の案内に差し替える。
 *
 * Limited by the screen ID. Without the check it would take over the footer of every admin screen.
 * 画面IDで限定する。限定しないと、すべての管理画面のフッターを乗っ取ってしまう。
 *
 * @param string $text Default footer text. / 既定のフッター文言。
 * @return string Footer text. / フッター文言。
 */
function acgd_admin_footer_text( $text ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ACGD_Settings::SCREEN_ID !== $screen->id ) {
		return $text;
	}

	return acgd_get_support_sentences();
}
add_filter( 'admin_footer_text', 'acgd_admin_footer_text' );
