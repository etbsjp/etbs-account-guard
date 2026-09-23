<?php
/**
 * PHPUnit bootstrap. / PHPUnit のブートストラップ。
 *
 * This repository has no full WordPress test suite (no wp-env: it is a plain PHP plugin without package.json).
 * Instead, only the few WordPress functions the code under test calls are defined here as minimal stubs, and
 * only logic with thin dependencies is unit-tested. Behaviour inside a real WordPress (the rendered settings
 * screen) is checked by hand on the local test site and recorded in the PR.
 * このリポジトリには WordPress のテストスイート一式が無い（package.json を持たない純 PHP のプラグインで、
 * wp-env を使っていない）。代わりに、テスト対象が呼ぶ WordPress の関数だけを最小限のスタブとして
 * ここで定義し、依存の薄いロジックだけをユニットテストする。実際の WordPress の中での動き（設定画面の表示）は
 * ローカルの検証サイトで手で確かめ、PR に記録する。
 *
 * Run: vendor/bin/phpunit / 実行: vendor/bin/phpunit
 *
 * @package etbs-account-guard
 */

// Plugin files start with `if ( ! defined( 'ABSPATH' ) ) { exit; }`; a dummy value lets them load.
// プラグインのファイルは `if ( ! defined( 'ABSPATH' ) ) { exit; }` で始まるため、通過させるダミー値を定義する。
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// Same value as WordPress (wp-includes/default-constants.php). / WordPress と同じ値（wp-includes/default-constants.php）。
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

/**
 * Clears the option store of the get_option() stub. Call it from each test's setUp().
 * get_option() スタブのオプションストアを空にする。各テストの setUp() から呼ぶ。
 *
 * @return void
 */
function acgd_test_reset_options() {
	$GLOBALS['acgd_test_options'] = array();
}

/**
 * Sets an option for the get_option() stub. / get_option() スタブにオプションを設定する。
 *
 * @param string $name  Option name. / オプション名。
 * @param mixed  $value Value. / 値。
 * @return void
 */
function acgd_test_set_option( $name, $value ) {
	$GLOBALS['acgd_test_options'][ $name ] = $value;
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Minimal stub of get_option(), backed by $GLOBALS['acgd_test_options'].
	 * $GLOBALS['acgd_test_options'] を使う get_option() の最小スタブ。
	 *
	 * @param string $name          Option name. / オプション名。
	 * @param mixed  $default_value Returned when the option is not set. / 未設定のときに返す値。
	 * @return mixed
	 */
	function get_option( $name, $default_value = false ) {
		return isset( $GLOBALS['acgd_test_options'] ) && array_key_exists( $name, $GLOBALS['acgd_test_options'] )
			? $GLOBALS['acgd_test_options'][ $name ]
			: $default_value;
	}
}

if ( ! function_exists( 'date_i18n' ) ) {
	/**
	 * Minimal stub of date_i18n() that keeps its contract for numeric formats: the argument is a "timestamp with
	 * offset", i.e. the local wall-clock time counted as if it were UTC, so gmdate() prints the wall clock.
	 * Translation of month and day names is not reproduced (the tests use numeric formats only).
	 * 数字だけの書式について date_i18n() の約束を守る最小スタブ。引数は「オフセット込みのタイムスタンプ」
	 * （現地の時計の値を UTC とみなして数えたもの）なので、gmdate() で現地の時計の値が出る。
	 * 月名・曜日名の翻訳は再現しない（テストは数字だけの書式を使う）。
	 *
	 * @param string $format                PHP date format. / PHP の日付書式。
	 * @param int    $timestamp_with_offset Timestamp with offset. / オフセット込みのタイムスタンプ。
	 * @return string
	 */
	function date_i18n( $format, $timestamp_with_offset ) {
		return gmdate( $format, (int) $timestamp_with_offset );
	}
}

require_once dirname( __DIR__, 2 ) . '/inc/class-acgd-time.php';
