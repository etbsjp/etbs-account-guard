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
 * Kept / 残すもの:
 * - `acgd_login_name_protection` … the switches of Login Name Protection, set by the user on the settings
 *   screen. Registered with register_setting() and saved by core's options.php, so it never shows up in an
 *   update_option() search. If the plugin is installed again, the user's choices (for example, author pages
 *   turned to 404, or an item turned off because it conflicted with the theme) come back as they were.
 *   「ログイン名の保護」のスイッチ。利用者が設定画面で設定した値。register_setting() で登録し、保存は本体の
 *   options.php が行うため、update_option() を検索しても現れない。入れ直したときに、利用者の選択
 *   （投稿者ページを 404 にした、テーマと衝突した項目を OFF にした、など）がそのまま戻る。
 * - `external_updates-etbs-account-guard` (site option) … update check state of the bundled
 *   plugin-update-checker. It belongs to the library, not to this plugin, and the other etbs plugins that
 *   bundle it leave it as well; whether to delete it is a decision for all of them together, not for one.
 *   同梱の plugin-update-checker の更新確認の状態（サイトオプション）。このプラグインではなくライブラリの
 *   ものであり、同じライブラリを同梱する他の etbs プラグインも残している。消すかどうかは1本ではなく全体で決める。
 *
 * Deleted / 消すもの:
 * - Nothing in 1.0.0. This version has no tables, no transients and no cron events of its own.
 *   The cron event of plugin-update-checker is removed by the library itself on deactivation
 *   (register_deactivation_hook()), and uninstalling always goes through deactivation.
 *   1.0.0 では無い。この版は独自のテーブル・transient・cron を持たない。plugin-update-checker の cron は
 *   無効化の時点でライブラリ自身が消し（register_deactivation_hook()）、削除は必ず無効化を経由する。
 *
 * 1.1.0 will delete the denial log and the result of the receiving diagnosis here (docs/spec.md 3.6).
 * 1.1.0 では、拒否の記録と受信診断の結果をここで消す（docs/spec.md 3.6）。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Nothing to delete in 1.0.0; see the docblock above. / 1.0.0 では消すものが無い（上の docblock を参照）。
