<?php
/**
 * Entry point of the implementation: modules, translations and support links.
 * 実装本体の入口。モジュールの読み込み・翻訳の登録・支援リンクを扱う。
 *
 * Login Name Protection (1.0.0) lives in class-acgd-login-name.php. Access Restriction (1.1.0) lives in
 * class-acgd-access-restriction.php (IP restriction, the shared foundation and BASIC credential storage),
 * class-acgd-basic-auth.php (BASIC authentication itself: matching, the confirmation screen and the receive
 * diagnosis) and class-acgd-user-access.php (the per-user screens). The settings screen is in
 * class-acgd-settings.php. The Access Restriction fault notice (admin_notices) and the one-time cleanup of
 * a previous self-distributed build's leftover cron event (admin_init) are defined further down in this
 * file. Neither a dashboard widget nor an update checker is bundled in this wordpress.org build (issue #13).
 * 「ログイン名の保護」（1.0.0）は class-acgd-login-name.php にある。「アクセス制限」（1.1.0）は
 * class-acgd-access-restriction.php（IP 制限・共通の土台・BASIC 資格情報の保存）、
 * class-acgd-basic-auth.php（BASIC 認証そのもの：照合・確認画面・受信の診断）、class-acgd-user-access.php
 * （ユーザーごとの画面）にある。設定画面は class-acgd-settings.php にある。アクセス制限の故障通知
 * （admin_notices）と、以前の自社配布版が残した cron イベントの一度きりの掃除（admin_init）は
 * このファイルの後半で定義している。この wordpress.org 版はダッシュボードのウィジェットも
 * 更新チェッカーも同梱しない（issue #13）。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-acgd-time.php';
require_once __DIR__ . '/class-acgd-invalid-credentials.php';
require_once __DIR__ . '/class-acgd-login-name.php';
require_once __DIR__ . '/class-acgd-access-restriction.php';
require_once __DIR__ . '/class-acgd-basic-auth.php';
require_once __DIR__ . '/class-acgd-user-access.php';
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
ACGD_Access_Restriction::init();
ACGD_Basic_Auth::init();
ACGD_User_Access::init();
ACGD_Settings::init();

/*-------------------------------------------*/
/* Legacy plugin-update-checker cron cleanup / 旧版の plugin-update-checker が残した cron の掃除
/*-------------------------------------------*/

/**
 * Clears the cron event a previous self-distributed build's bundled plugin-update-checker scheduled, on
 * sites that moved straight to this wordpress.org build without an intervening deactivate/activate cycle.
 *
 * That library only ever cleared its own cron event (puc_cron_check_updates-etbs-account-guard) on
 * deactivation (register_deactivation_hook()); this build carries none of that code, so a site that swapped
 * the plugin files in place while it stayed active never runs that hook, and the event would otherwise fire
 * forever with nothing left that recognizes it. uninstall.php already removes the same event as a backstop
 * (docs/spec.md 3.6), but that backstop only runs if the site is ever uninstalled, which a site that keeps
 * using this plugin never does. Hooked to admin_init so it runs once an admin using this build actually
 * opens wp-admin, rather than on every front-end request. wp_next_scheduled() only reads the already
 * autoloaded 'cron' option, so this costs no extra query, and is a no-op once the event is gone — whether
 * because this function already cleared it or because it was never scheduled in the first place, on a site
 * installed straight from wordpress.org — so calling it on every admin_init is safe and idempotent.
 *
 * 以前の自社配布版が同梱していた plugin-update-checker が仕掛けた cron イベント
 * （puc_cron_check_updates-etbs-account-guard）を、無効化・有効化を挟まずにこの wordpress.org 版へ
 * 移行したサイトで消す。
 *
 * このライブラリは自分の cron イベントを無効化のときにしか消さなかった（register_deactivation_hook()）。
 * この版にはそのコードが無いため、有効なままファイルだけ入れ替えたサイトではそのフックが一度も走らず、
 * 放っておくとこのイベントを知るコードがどこにも無いまま永久に空振りし続ける。uninstall.php にも同じ
 * イベントを消す保険を置いているが（docs/spec.md 3.6）、それはアンインストールされたときにしか働かず、
 * この版を使い続けるサイトでは永久に走らない。フロント側の毎リクエストではなく、この版を使っている
 * 管理者が実際に wp-admin を開いたときに一度だけ走るよう admin_init に掛ける。wp_next_scheduled() は
 * 既に autoload 済みの 'cron' オプションを読むだけなので、追加のクエリは発生しない。イベントが既に
 * 無い状態（このプラグイン自身がすでに消した後、または wordpress.org から直接インストールしたサイトで
 * もとから存在しない）では何もしないため、毎回の admin_init で呼んでも安全（冪等）。
 *
 * @return void
 */
function acgd_clear_legacy_puc_cron() {
	if ( wp_next_scheduled( 'puc_cron_check_updates-etbs-account-guard' ) ) {
		wp_clear_scheduled_hook( 'puc_cron_check_updates-etbs-account-guard' );
	}
}
add_action( 'admin_init', 'acgd_clear_legacy_puc_cron' );

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
 * Screen reader users are told that the link opens in a new tab, in the same form core uses.
 * スクリーンリーダーの利用者には、本体と同じ形で「新しいタブで開く」ことを伝える。
 *
 * @param string $page Which page: 'donate' or 'request'. / どのページか（'donate' または 'request'）。
 * @param string $text Link text, not escaped. / リンク文言（未エスケープ）。
 * @return string The escaped HTML of the link. / エスケープ済みのリンク HTML。
 */
function acgd_get_support_link( $page, $text ) {
	return sprintf(
		'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
		esc_url( acgd_get_support_url( $page ) ),
		esc_html( $text ),
		esc_html__( '(opens in a new tab)', 'etbs-account-guard' )
	);
}

/**
 * Joins sentences that belong to the same paragraph, with the separator of the current language.
 * 同じ段落に入る文を、表示中の言語の区切りでつなぐ。
 *
 * Each sentence is its own translation (the translation functions take one sentence each), so the space
 * between sentences cannot live inside them. English puts a space there, Japanese does not; the separator
 * is therefore a translation of its own, and the Japanese translation drops the space.
 * 文はそれぞれ別の翻訳にする（翻訳関数には1文ずつ入れる）ので、文と文の間の空白を訳文の中に持てない。
 * 英語はそこに空白を入れ、日本語は入れない。そのため区切りを独立した翻訳にし、日本語訳では空白を外す。
 *
 * @param string[] $sentences Sentences, already escaped. / 文（エスケープ済み）。
 * @return string The joined sentences. / つないだ文。
 */
function acgd_join_sentences( $sentences ) {
	$sentences = array_values( (array) $sentences );
	$joined    = (string) array_shift( $sentences );

	foreach ( $sentences as $sentence ) {
		$joined = sprintf(
			/* translators: Joins two sentences of the same paragraph. 1: first sentence, 2: next sentence. Remove the space in languages that do not put a space between sentences. */
			esc_html_x( '%1$s %2$s', 'sentences in a row', 'etbs-account-guard' ),
			$joined,
			$sentence
		);
	}

	return $joined;
}

/**
 * Returns the two support sentences shared by the settings screen footer and the dashboard widget.
 * 設定画面のフッターとダッシュボードのウィジェットで共通に使う、支援の案内2文を返す。
 *
 * Each sentence is a separate translation, as the translation functions take one sentence each,
 * and acgd_join_sentences() puts them together.
 * 翻訳関数には1文ずつ入れる決まりなので、2文は別々の翻訳にし、acgd_join_sentences() でつなぐ。
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
		/* translators: %s: link for requesting new features or custom development */
		esc_html__( 'For new features or custom development, please %s.', 'etbs-account-guard' ),
		acgd_get_support_link( 'request', __( 'send us a request', 'etbs-account-guard' ) )
	);

	return acgd_join_sentences( array( $donate, $request ) );
}

/**
 * HTML allowed in the sentences printed by this plugin's admin screens.
 * このプラグインの管理画面が出力する文で許可する HTML。
 *
 * Links (including the support links that open in a new tab and their screen reader text) and <code>.
 * リンク（新しいタブで開く支援リンクと、そのスクリーンリーダー用の文言を含む）と <code>。
 *
 * @return array Allowed HTML for wp_kses(). / wp_kses() に渡す許可リスト。
 */
function acgd_allowed_sentence_html() {
	return array(
		'a'    => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
		'span' => array(
			'class' => true,
		),
		'code' => array(),
	);
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

/*-------------------------------------------*/
/* Access Restriction fault notice / アクセス制限の故障通知
/*-------------------------------------------*/

/**
 * Warns users who can manage options, on every admin screen except this plugin's own settings screen, when
 * Access Restriction is currently stopped (docs/spec.md 5.5): a fault of its own, or the emergency switch.
 *
 * Replaces the removed dashboard widget as the place this is surfaced outside of the Access Restriction tab
 * of the settings screen (the wordpress.org build does not ship a dashboard widget). Skipped only when both
 * ACGD_Settings::SCREEN_ID matches and the Access Restriction tab is the one showing (ACGD_Settings::
 * get_current_tab() === 'access'), because that tab already prints the same warning (ACGD_Settings::
 * render_access_restriction_notices()); showing both would duplicate it. Every other tab of this plugin's
 * settings screen — including its default, Login Name Protection — prints nothing of its own, so this notice
 * still needs to fire there (UX review, high priority: matching on the screen ID alone suppressed it across
 * the whole settings screen). No dismiss button and nothing recorded in user meta: the notice disappears on
 * its own once the underlying state clears, so there is nothing to remember. When both conditions are false,
 * neither current_user_can() short-circuits nor the two state checks below issue any additional query beyond
 * what has_fault() / is_switch_disabled() already do elsewhere in a request.
 * アクセス制限が止まっているとき（docs/spec.md 5.5：自分自身の故障、または非常用スイッチ）に、
 * manage_options を持つユーザーへ、本プラグインの設定画面の「アクセス制限」タブ以外の管理画面で
 * 警告を出す。
 *
 * 削除したダッシュボードのウィジェットに代わる、設定画面の「アクセス制限」タブ以外での表示場所に
 * なる（wordpress.org 版はダッシュボードのウィジェットを持たない）。ACGD_Settings::SCREEN_ID と
 * 一致し、かつ「アクセス制限」タブを開いているとき（ACGD_Settings::get_current_tab() === 'access'）
 * だけ出さない。そのタブには ACGD_Settings::render_access_restriction_notices() が同じ警告を既に
 * 出しており、二重表示になるため。設定画面の既定タブ「ログイン名の保護」を含む他のタブには何も
 * 出ないので、この通知はそこでも出す必要がある（植草レビュー・優先度高：画面IDだけで判定すると
 * 設定画面全体で抑制されてしまっていた）。閉じるボタンは無く、ユーザーメタにも何も記録しない。
 * 状態が直れば自動で消えるので、記憶させる必要が無い。両方の条件が偽のときは、current_user_can()
 * 以降の分岐でも has_fault() / is_switch_disabled() が他の場所で既に行っている以上の問い合わせは
 * 発生しない。
 *
 * @return void
 */
function acgd_access_restriction_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Skipped only on the Access Restriction tab itself: that is the one tab where
	// ACGD_Settings::render_access_restriction_notices() already prints the same warning. The settings
	// screen's other tabs (its default is Login Name Protection; see ACGD_Settings::get_current_tab())
	// show nothing of their own, so this notice must still fire there — matching on the screen ID alone
	// suppressed it across the whole settings screen, leaving a stopped Access Restriction unreported
	// while any other tab was open (UX review, high priority).
	// 「アクセス制限」タブそのものにいるときだけ出さない。ACGD_Settings::
	// render_access_restriction_notices() が同じ警告を出すのはそのタブだけで、設定画面の既定タブ
	// （「ログイン名の保護」。ACGD_Settings::get_current_tab() 参照）を含む他のタブには何も出ない。
	// 画面IDだけで判定すると設定画面全体で抑制され、「アクセス制限」以外のタブを開いている間は
	// 停止中でも気付けなくなっていた（植草レビュー・優先度高）。
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && ACGD_Settings::SCREEN_ID === $screen->id && 'access' === ACGD_Settings::get_current_tab() ) {
		return;
	}

	$has_fault       = ACGD_Access_Restriction::has_fault();
	$switch_disabled = ACGD_Access_Restriction::is_switch_disabled();
	if ( ! $has_fault && ! $switch_disabled ) {
		return;
	}

	// Shared by both notices below: points to the Access Restriction tab for details. Built once here (and
	// only once at least one notice is due) so the sentence, and the translation call it costs, is not
	// duplicated or paid for on every admin screen load.
	// 以下の両方の通知で共通に使う、詳細への案内文。ここで一度だけ（少なくとも一方の通知が出るときだけ）
	// 作り、翻訳の呼び出しをすべての管理画面表示のたびに払わせない。
	$open_tab_sentence = sprintf(
		/* translators: %s: URL of the Access Restriction tab of the settings screen */
		__( 'Open the <a href="%s">Access Restriction tab</a> of the settings screen for details.', 'etbs-account-guard' ),
		esc_url( ACGD_Settings::get_page_url( 'access' ) )
	);

	if ( $has_fault ) {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				// Each sentence is its own translation (coding-rules.md: one sentence per translation
				// function), joined by acgd_join_sentences() the same way as elsewhere in this plugin.
				// 各文をそれぞれ別の翻訳にし（coding-rules.md：翻訳関数には1文ずつ）、このプラグインの
				// 他の箇所と同じく acgd_join_sentences() でつなぐ。
				echo wp_kses(
					acgd_join_sentences(
						array(
							esc_html__( 'Access Restriction is stopped because of an internal problem.', 'etbs-account-guard' ),
							esc_html__( 'Everyone can sign in without an IP check until this is fixed.', 'etbs-account-guard' ),
							$open_tab_sentence,
						)
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	if ( $switch_disabled ) {
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				// Same reasoning as the notice above: one sentence per translation, joined together.
				// 上の通知と同じ考え方：翻訳は1文ずつにし、つなぎ合わせる。
				echo wp_kses(
					acgd_join_sentences(
						array(
							sprintf(
								/* translators: %s: PHP constant, ACGD_DISABLE_RESTRICTION */
								esc_html__( 'The emergency switch (%s in wp-config.php) is turned on, so Access Restriction is stopped.', 'etbs-account-guard' ),
								'<code>' . esc_html( 'ACGD_DISABLE_RESTRICTION' ) . '</code>'
							),
							esc_html__( 'Login Name Protection is not affected.', 'etbs-account-guard' ),
							$open_tab_sentence,
						)
					),
					array(
						'a'    => array( 'href' => true ),
						'code' => array(),
					)
				);
				?>
			</p>
		</div>
		<?php
	}
}
add_action( 'admin_notices', 'acgd_access_restriction_admin_notices' );
