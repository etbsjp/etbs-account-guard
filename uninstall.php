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
 *   reason.
 *   （1.1.0）ユーザーメタ `acgd_access_mode`・`acgd_user_ips`。ユーザー自身のモードと、そのユーザーに
 *   追加した IP アドレス。利用者がそのユーザーの編集画面で設定した、ユーザーごとの値。同じ理由で残す。
 * - `acgd_basic_id` and `acgd_basic_password_hash` user meta (1.1.0, BASIC authentication, issue #4) … one
 *   user's own BASIC authentication ID and password_hash(), set the same way and for the same reason as the
 *   two above: values the user set, per user.
 *   （1.1.0・BASIC 認証・issue #4）ユーザーメタ `acgd_basic_id`・`acgd_basic_password_hash`。
 *   ユーザー自身の BASIC 認証の ID と password_hash()。上の2つと同じ場所・同じ理由で設定される、
 *   利用者が設定した値。
 *
 * Deleted / 消すもの（一時状態）:
 * - `external_updates-etbs-account-guard` (site option) … update check state left behind by
 *   plugin-update-checker, a library that a previous self-distributed build of this plugin bundled (the
 *   wordpress.org build does not; self-updaters are not allowed there). On a site upgraded from that build,
 *   this row can still be present even though nothing writes it anymore, so it is rebuilt by nothing and is
 *   pure leftover state. The name is the library's default for a plugin ('external_updates-' . slug in its
 *   UpdateChecker.php), and the library saved it with update_site_option(), so it is removed with
 *   delete_site_option().
 *   以前の自社配布版が同梱していたライブラリ plugin-update-checker が残した更新確認の状態
 *   （wordpress.org 版は同梱しない。独自の更新機構が認められていないため）。その版から入れ替えた
 *   サイトでは、もう何も書き込まない状態でこの行だけが残りうる。何によっても作り直されない、
 *   純粋な残骸。名前はライブラリがプラグインに付ける既定の名前（UpdateChecker.php の
 *   'external_updates-' . スラッグ）で、ライブラリは update_site_option() で保存していたため、
 *   delete_site_option() で消す。
 * - `puc_manual_check_errors-etbs-account-guard` (site transient) … the same previous self-distributed
 *   build's manual update check errors, kept for 60 seconds by the library (Plugin/Ui.php) and possibly still
 *   present right after the switch. Removed so that nothing of the library is left behind.
 *   同じく以前の自社配布版が同梱していたライブラリが残した、手動の更新確認のエラー。ライブラリの仕様で
 *   60秒だけ保持される（Plugin/Ui.php）ため、切り替え直後にはまだ残っている可能性がある。ライブラリの
 *   ものを何も残さないために消す。
 * - `acgd_access_denial_log` (1.1.0, option) … the denial log (docs/spec.md 5.4), rebuilt from scratch as
 *   denials happen again after reinstalling; nothing here can be reproduced from a past state, but it also
 *   documents nothing the user configured, only what this plugin itself recorded.
 *   （1.1.0・オプション）拒否の記録（docs/spec.md 5.4）。入れ直した後、拒否が起きるたびにまた作られる。
 *   利用者が設定したものではなく、このプラグイン自身が記録した一時的な履歴のため消す。
 * - `acgd_access_restriction_fault` (1.1.0, option) … a recorded fault of Access Restriction (docs/spec.md
 *   5.5), cleared automatically once the settings are saved successfully; nothing to keep across a reinstall.
 *   （1.1.0・オプション）アクセス制限の故障の記録（docs/spec.md 5.5）。設定の保存に成功すると自動で消える
 *   一時状態で、入れ直す間に残す意味が無い。
 * - `acgd_basic_diagnosis` (1.1.0, BASIC authentication, issue #4, option) … the saved result of the receive
 *   diagnosis (docs/spec.md 5.3): whether the server passes the BASIC authentication header through to PHP.
 *   Rebuilt the next time the diagnosis is run, and documents nothing the user configured, only what this
 *   plugin itself measured; nothing to keep across a reinstall.
 *   （1.1.0・BASIC 認証・issue #4・オプション）保存済みの受信診断（docs/spec.md 5.3）の結果。
 *   サーバーが BASIC 認証のヘッダーを PHP まで通すかどうか。次に診断を実行すれば作り直され、
 *   利用者が設定したものではなくこのプラグイン自身が測った結果のため、入れ直す間に残す意味が無い。
 * - `acgd_basic_id_count` (1.1.0, BASIC authentication, issue #4, option) … a cached count of how many users
 *   currently have a non-empty BASIC authentication ID (ACGD_Access_Restriction::BASIC_ID_COUNT_OPTION;
 *   security review, 2026-09-11), kept only so the per-request BASIC authentication check can skip a
 *   get_users() query on sites where nobody uses it. Rebuilt from the real per-user meta the next time anyone
 *   is saved into or out of BASIC mode; documents nothing the user configured, only a derived tally.
 *   （1.1.0・BASIC 認証・issue #4・オプション）BASIC 認証の ID を現在いくつのユーザーが持っているかの
 *   キャッシュ（ACGD_Access_Restriction::BASIC_ID_COUNT_OPTION。セキュリティレビュー、2026-09-11）。
 *   リクエストごとの BASIC 認証判定が、誰も使っていないサイトで get_users() クエリを省けるようにする
 *   ためだけに持つ。誰かが BASIC モードに/から保存されるたびに、実際のユーザーメタから作り直される
 *   派生的な集計であり、利用者が設定したものではない。
 * - `acgd_basic_confirmed_count` (1.1.0, BASIC authentication, issue #9, option) … a cached count of how many
 *   admins currently hold a live "Verify" confirmation (ACGD_Basic_Auth::CONFIRMED_COUNT_OPTION; code review,
 *   Low), kept only so the per-request check for one can skip a get_transient() query on sites where nobody
 *   has confirmed anything. Rebuilt from real confirmations the next time anyone confirms or saves; documents
 *   nothing the user configured, only a derived tally — same shape as acgd_basic_id_count above.
 *   （1.1.0・BASIC 認証・issue #9・オプション）現在「確認」を生きたまま持つ管理者の人数のキャッシュ
 *   （ACGD_Basic_Auth::CONFIRMED_COUNT_OPTION。コードレビュー・Low）。リクエストごとの確認済みチェックが、
 *   誰も確認していないサイトで get_transient() クエリを省けるようにするためだけに持つ。誰かが確認する・
 *   保存するたびに実際の確認の有無から作り直される派生的な集計であり、利用者が設定したものではない
 *   （上の acgd_basic_id_count と同じ性質）。
 * - Several transients exist in 1.1.0 that are not deleted here, on purpose: the "resubmit" transients
 *   (ACGD_Settings::RESUBMIT_TRANSIENT_PREFIX 'acgd_access_resubmit_' and
 *   ACGD_User_Access::RESUBMIT_TRANSIENT_PREFIX 'acgd_user_resubmit_'), which briefly hold a rejected
 *   Access Restriction tab or user-edit-screen submission so the form can be redisplayed with what was
 *   typed (see the classes for why); and, for BASIC authentication (issue #4), the "Verify" round trip's own
 *   transients (ACGD_Basic_Auth::PENDING_TRANSIENT_PREFIX 'acgd_basic_pending_' and
 *   ::VERIFIED_TRANSIENT_PREFIX 'acgd_basic_verified_', both keyed by the confirming admin's user ID) and
 *   the receive diagnosis's one-time probe token (ACGD_Basic_Auth::DIAG_TOKEN_PREFIX 'acgd_basic_diag_token_',
 *   keyed by a random value generated for that one loopback request). None of these has a single fixed key a
 *   delete_transient() call here could remove; each is already deleted the moment it is used (at most once),
 *   with a short TTL as a backstop (RESUBMIT_TTL / ACGD_Basic_Auth::VERIFY_TTL: 5 minutes; the diagnosis
 *   token: 60 seconds), so any left over from an interrupted request are gone on their own regardless of
 *   uninstalling. This is exactly the temporary state that policy A already covers (3.6); it needs no
 *   explicit action here. 1.0.0 in isolation has no tables or cron events of its own, and 1.1.0 adds neither
 *   of those either (transients and the two options above only, as just described).
 *   1.1.0 には、ここでは意図的に消していない transient が複数ある。「再表示用」の transient
 *   （ACGD_Settings::RESUBMIT_TRANSIENT_PREFIX 'acgd_access_resubmit_' と
 *   ACGD_User_Access::RESUBMIT_TRANSIENT_PREFIX 'acgd_user_resubmit_'）で、「アクセス制限」タブや
 *   ユーザー編集画面の送信が拒否されたとき、入力した内容でフォームを出し直すために一時的に持つ
 *   （理由は各クラスを参照）。加えて、BASIC 認証（issue #4）の「確認」の往復自身が持つ transient
 *   （ACGD_Basic_Auth::PENDING_TRANSIENT_PREFIX 'acgd_basic_pending_' と
 *   ::VERIFIED_TRANSIENT_PREFIX 'acgd_basic_verified_'。どちらも確認した管理者のユーザー ID で分ける）と、
 *   受信の診断の使い捨てトークン（ACGD_Basic_Auth::DIAG_TOKEN_PREFIX 'acgd_basic_diag_token_'。
 *   その1回のループバックリクエストのために生成した乱数で分ける）。どれも固定のキーが無いため、
 *   ここで delete_transient() を1回呼んで消せる形ではない。また、それぞれ使われた時点（最大でも1回）で
 *   既に消えており、保険として短い TTL（RESUBMIT_TTL・ACGD_Basic_Auth::VERIFY_TTL は5分、診断トークンは
 *   60秒）を持つため、途中で終わったリクエストの残りがあっても、アンインストールの有無にかかわらず
 *   自然に消える。これはまさに案A（3.6）がすでに扱う一時状態であり、ここでの明示的な対応は不要。
 *   1.0.0 のこのプラグイン自身は、独自のテーブル・cron を持たない。1.1.0 でもそれらは増えない
 *   （増えるのは上記の transient と2つのオプションだけ）。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Temporary state left behind by a previous self-distributed build's bundled plugin-update-checker;
// see the docblock above.
// 以前の自社配布版が同梱していた plugin-update-checker が残した一時状態（上の docblock を参照）。
delete_site_option( 'external_updates-etbs-account-guard' );
delete_site_transient( 'puc_manual_check_errors-etbs-account-guard' );

// Access Restriction's own temporary state (1.1.0); see the docblock above.
// アクセス制限自身の一時状態（1.1.0。上の docblock を参照）。
delete_option( 'acgd_access_denial_log' );
delete_option( 'acgd_access_restriction_fault' );

// BASIC authentication's own temporary state (1.1.0, issue #4); see the docblock above.
// BASIC 認証自身の一時状態（1.1.0・issue #4。上の docblock を参照）。
delete_option( 'acgd_basic_diagnosis' );
delete_option( 'acgd_basic_id_count' );
delete_option( 'acgd_basic_confirmed_count' );
