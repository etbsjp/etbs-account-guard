<?php
/**
 * Uninstall routine. / アンインストール処理。
 *
 * Follows policy A (task-queue #108): delete temporary state and cron events this plugin scheduled;
 * keep content the user created and values the user set. The reason is the asymmetry of harm:
 * keeping leaves a few rows in the database, deleting cannot be undone.
 * 方針は案A（task-queue #108）。一時状態と自分が仕掛けた cron は消し、利用者が作ったコンテンツと
 * 利用者が設定した値は消さない。理由は害の非対称性で、残す害は DB に少量の行が残るだけだが、
 * 消す害は取り返しがつかない。
 *
 * Kept / 残すもの（利用者が設定した値）:
 * - `acgd_login_name_protection` … the switches of Login Name Protection, set by the user on the settings
 *   screen. Registered with register_setting() and saved by core's options.php, so it never shows up in an
 *   update_option() search. If the plugin is installed again, the user's choices (for example, author pages
 *   turned to 404, or an item turned off because it conflicted with the theme) come back as they were.
 *   「ログイン名の保護」のスイッチ。利用者が設定画面で設定した値。register_setting() で登録し、保存は本体の
 *   options.php が行うため、update_option() を検索しても現れない。入れ直したときに、利用者の選択
 *   （投稿者ページを 404 にした、テーマと衝突した項目を OFF にした、など）がそのまま戻る。
 *
 * Deleted / 消すもの（一時状態）:
 * - `external_updates-etbs-account-guard` (site option) … update check state of the bundled
 *   plugin-update-checker (when it last checked, and the update it found). It is rebuilt by the next check,
 *   so it is temporary state. The name is the library's default for a plugin ('external_updates-' . slug in
 *   Puc/v5p5/UpdateChecker.php, with the slug given in inc/update-checker.php), and the library saves it
 *   with update_site_option() (Puc/v5p5/StateStore.php), so it is removed with delete_site_option().
 *   同梱の plugin-update-checker の更新確認の状態（最後に確認した日時と、見つけた更新）。次の確認で作り直されるので
 *   一時状態に当たる。名前はライブラリがプラグインに付ける既定の名前（Puc/v5p5/UpdateChecker.php の
 *   'external_updates-' . スラッグ。スラッグは inc/update-checker.php で渡している）で、ライブラリは
 *   update_site_option() で保存する（Puc/v5p5/StateStore.php）ので、delete_site_option() で消す。
 * - `puc_manual_check_errors-etbs-account-guard` (site transient) … errors of a manual update check, kept for
 *   60 seconds (Puc/v5p5/Plugin/Ui.php). Removed so that nothing of the library is left behind.
 *   手動の更新確認で出たエラー。60秒だけ保持される（Puc/v5p5/Plugin/Ui.php）。ライブラリのものを何も残さないために消す。
 * - The cron event of plugin-update-checker is removed by the library itself on deactivation
 *   (register_deactivation_hook()), and uninstalling always goes through deactivation.
 *   plugin-update-checker の cron は無効化の時点でライブラリ自身が消し（register_deactivation_hook()）、
 *   削除は必ず無効化を経由する。
 * - This plugin itself has no tables, transients or cron events in 1.0.0.
 *   1.0.0 のこのプラグイン自身は、テーブル・transient・cron を持たない。
 *
 * 1.1.0 will delete the denial log and the result of the receiving diagnosis here (docs/spec.md 3.6).
 * 1.1.0 では、拒否の記録と受信診断の結果をここで消す（docs/spec.md 3.6）。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Temporary state of the bundled plugin-update-checker; see the docblock above.
// 同梱の plugin-update-checker の一時状態（上の docblock を参照）。
delete_site_option( 'external_updates-etbs-account-guard' );
delete_site_transient( 'puc_manual_check_errors-etbs-account-guard' );
