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
 * Kept / 残すもの（利用者が作ったコンテンツ・設定した値）:
 * - `acgd_login_name_protection` … the switches of Login Name Protection, set by the user on the settings
 *   screen. Registered with register_setting() and saved by core's options.php, so it never shows up in an
 *   update_option() search. If the plugin is installed again, the user's choices (for example, author pages
 *   turned to 404, or an item turned off because it conflicted with the theme) come back as they were.
 *   「ログイン名の保護」のスイッチ。利用者が設定画面で設定した値。register_setting() で登録し、保存は本体の
 *   options.php が行うため、update_option() を検索しても現れない。入れ直したときに、利用者の選択
 *   （投稿者ページを 404 にした、テーマと衝突した項目を OFF にした、など）がそのまま戻る。
 * - `acgd_access_restriction` (1.1.0) … the per-role modes and the site-wide IP list of Access Restriction,
 *   set by the user on the Access Restriction settings tab. Same reasoning as above: values the user chose.
 *   （1.1.0）「アクセス制限」の権限ごとのモードとサイトの IP 一覧。利用者が「アクセス制限」タブで
 *   設定した値。上と同じ理由（利用者が選んだ値）で残す。
 * - `acgd_access_mode` and `acgd_user_ips` user meta (1.1.0) … one user's own mode and the IP addresses added
 *   for them, set by the user on that user's edit screen. Values the user set, per user; kept for the same
 *   reason. If BASIC authentication credentials are added in a later update (issue #4), they belong in this
 *   same "values the user set" category.
 *   （1.1.0）ユーザーメタ `acgd_access_mode`・`acgd_user_ips`。ユーザー自身のモードと、そのユーザーに
 *   追加した IP アドレス。利用者がそのユーザーの編集画面で設定した、ユーザーごとの値。同じ理由で残す。
 *   将来の更新（issue #4）で BASIC 認証の資格情報を足しても、同じ「利用者が設定した値」に属する。
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
 * - `acgd_access_denial_log` (1.1.0, option) … the denial log (docs/spec.md 5.4), rebuilt from scratch as
 *   denials happen again after reinstalling; nothing here can be reproduced from a past state, but it also
 *   documents nothing the user configured, only what this plugin itself recorded.
 *   （1.1.0・オプション）拒否の記録（docs/spec.md 5.4）。入れ直した後、拒否が起きるたびにまた作られる。
 *   利用者が設定したものではなく、このプラグイン自身が記録した一時的な履歴のため消す。
 * - `acgd_access_restriction_fault` (1.1.0, option) … a recorded fault of Access Restriction (docs/spec.md
 *   5.5), cleared automatically once the settings are saved successfully; nothing to keep across a reinstall.
 *   （1.1.0・オプション）アクセス制限の故障の記録（docs/spec.md 5.5）。設定の保存に成功すると自動で消える
 *   一時状態で、入れ直す間に残す意味が無い。
 * - Two transients exist in 1.1.0 that are not deleted here, on purpose: the "resubmit" transients
 *   (ACGD_Settings::RESUBMIT_TRANSIENT_PREFIX 'acgd_access_resubmit_' and
 *   ACGD_User_Access::RESUBMIT_TRANSIENT_PREFIX 'acgd_user_resubmit_'), which briefly hold a rejected
 *   Access Restriction tab or user-edit-screen submission so the form can be redisplayed with what was
 *   typed (see the classes for why). Each key ends with the submitting admin's user ID (the user-edit one
 *   also the edited user's ID), so there is no single fixed key a delete_transient() call here could
 *   remove; and each one is already deleted the moment it is read (at most one render), with a 60-second
 *   TTL (RESUBMIT_TTL) as a backstop, so any left over from an interrupted request are gone within a
 *   minute regardless of uninstalling. This is exactly the temporary state that policy A already covers
 *   (3.6); it needs no explicit action here. 1.0.0 in isolation has no tables or cron events of its own,
 *   and 1.1.0 adds neither of those either (transients only, as just described).
 *   1.1.0 には、ここでは意図的に消していない transient が2つある。「再表示用」の transient
 *   （ACGD_Settings::RESUBMIT_TRANSIENT_PREFIX 'acgd_access_resubmit_' と
 *   ACGD_User_Access::RESUBMIT_TRANSIENT_PREFIX 'acgd_user_resubmit_'）で、「アクセス制限」タブや
 *   ユーザー編集画面の送信が拒否されたとき、入力した内容でフォームを出し直すために一時的に持つ
 *   （理由は各クラスを参照）。キーの末尾は送信した管理者のユーザー ID（ユーザー編集画面版は編集対象の
 *   ユーザー ID も含む）で、固定のキーが無いため、ここで delete_transient() を1回呼んで消せる形ではない。
 *   また、それぞれ読まれた時点（最大でも1回の描画）で既に消えており、保険として TTL（RESUBMIT_TTL）を
 *   60秒にしているため、途中で終わったリクエストの残りがあっても、アンインストールの有無にかかわらず
 *   1分以内に消える。これはまさに案A（3.6）がすでに扱う一時状態であり、ここでの明示的な対応は不要。
 *   1.0.0 のこのプラグイン自身は、独自のテーブル・cron を持たない。1.1.0 でもそれらは増えない
 *   （増えるのは上記の transient だけ）。
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

// Access Restriction's own temporary state (1.1.0); see the docblock above.
// アクセス制限自身の一時状態（1.1.0。上の docblock を参照）。
delete_option( 'acgd_access_denial_log' );
delete_option( 'acgd_access_restriction_fault' );
