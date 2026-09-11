<?php
/**
 * Update checker (plugin-update-checker) that follows the dist branch on GitHub.
 * GitHub の dist ブランチを見る更新チェッカー（plugin-update-checker）。
 *
 * Kept in its own file so that a WordPress.org build can drop it together with the bundled library.
 * wordpress.org 版で同梱ライブラリごと外せるよう、独立したファイルにしている。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

/**
 * Builds the update checker for this plugin.
 * このプラグインの更新チェッカーを作る。
 *
 * The checker registers its own hooks, so the returned object does not need to be stored.
 * チェッカーは自分でフックを登録するので、戻り値を保持しておく必要は無い。
 *
 * @return object The update checker instance. / 更新チェッカーのインスタンス。
 */
function acgd_build_update_checker() {
	$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/etbsjp/etbs-account-guard/',
		ACGD_PLUGIN_FILE,
		'etbs-account-guard'
	);

	// The repository has a single branch, dist, which is also the delivery source.
	// リポジトリは dist ブランチ一本で運用しており、配信元も dist。
	$update_checker->setBranch( 'dist' );

	return $update_checker;
}
acgd_build_update_checker();
