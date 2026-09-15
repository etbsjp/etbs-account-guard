<?php
/**
 * Entry point of the implementation: modules, translations and support links.
 * 実装本体の入口。モジュールの読み込み・翻訳の登録・支援リンクを扱う。
 *
 * Login Name Protection (1.0.0) lives in class-acgd-login-name.php. Access Restriction (1.1.0) lives in
 * class-acgd-access-restriction.php (IP restriction, the shared foundation and BASIC credential storage),
 * class-acgd-basic-auth.php (BASIC authentication itself: matching, the confirmation screen and the receive
 * diagnosis) and class-acgd-user-access.php (the per-user screens). The settings screen is in
 * class-acgd-settings.php. The dashboard widget and the update checker are loaded from the main file.
 * 「ログイン名の保護」（1.0.0）は class-acgd-login-name.php にある。「アクセス制限」（1.1.0）は
 * class-acgd-access-restriction.php（IP 制限・共通の土台・BASIC 資格情報の保存）、
 * class-acgd-basic-auth.php（BASIC 認証そのもの：照合・確認画面・受信の診断）、class-acgd-user-access.php
 * （ユーザーごとの画面）にある。設定画面は class-acgd-settings.php にある。
 * ダッシュボードのウィジェットと更新チェッカーは本体ファイルから読み込む。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 * Replaces the removed dashboard widget as the place this is surfaced outside of the settings screen (the
 * wordpress.org build does not ship a dashboard widget). Skipped on ACGD_Settings::SCREEN_ID because the
 * Access Restriction tab already prints the same warning (ACGD_Settings::render_access_restriction_notices());
 * showing both would duplicate it. No dismiss button and nothing recorded in user meta: the notice disappears
 * on its own once the underlying state clears, so there is nothing to remember. When both conditions are
 * false, neither current_user_can() short-circuits nor the two state checks below issue any additional query
 * beyond what has_fault() / is_switch_disabled() already do elsewhere in a request.
 * アクセス制限が止まっているとき（docs/spec.md 5.5：自分自身の故障、または非常用スイッチ）に、
 * manage_options を持つユーザーへ、本プラグインの設定画面以外の管理画面で警告を出す。
 *
 * 削除したダッシュボードのウィジェットに代わる、設定画面の外での表示場所になる（wordpress.org 版は
 * ダッシュボードのウィジェットを持たない）。ACGD_Settings::SCREEN_ID では出さない。「アクセス制限」
 * タブに同じ警告が既に出るため（ACGD_Settings::render_access_restriction_notices()）、二重表示になる。
 * 閉じるボタンは無く、ユーザーメタにも何も記録しない。状態が直れば自動で消えるので、記憶させる必要が
 * 無い。両方の条件が偽のときは、current_user_can() 以降の分岐でも has_fault() / is_switch_disabled()
 * が他の場所で既に行っている以上の問い合わせは発生しない。
 *
 * @return void
 */
function acgd_access_restriction_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && ACGD_Settings::SCREEN_ID === $screen->id ) {
		return;
	}

	if ( ACGD_Access_Restriction::has_fault() ) {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s: URL of the Access Restriction tab of the settings screen */
					wp_kses( __( 'Access Restriction is stopped because of an internal problem, and everyone can sign in without an IP check until this is fixed. Open the <a href="%s">Access Restriction tab</a> of the settings screen for details.', 'etbs-account-guard' ), array( 'a' => array( 'href' => true ) ) ),
					esc_url( ACGD_Settings::get_page_url( 'access' ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	if ( ACGD_Access_Restriction::is_switch_disabled() ) {
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: PHP constant, ACGD_DISABLE_RESTRICTION; 2: URL of the Access Restriction tab of the settings screen */
						__( 'The emergency switch (%1$s in wp-config.php) is turned on, so Access Restriction is stopped. Login Name Protection is not affected. Open the <a href="%2$s">Access Restriction tab</a> of the settings screen for details.', 'etbs-account-guard' ),
						'<code>' . esc_html( 'ACGD_DISABLE_RESTRICTION' ) . '</code>',
						esc_url( ACGD_Settings::get_page_url( 'access' ) )
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
