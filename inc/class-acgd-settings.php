<?php
/**
 * Settings screen: Settings > ETBS Account Guard (screen ID settings_page_etbs-account-guard).
 * 設定画面「設定 > ETBS Account Guard」（画面ID settings_page_etbs-account-guard）。
 *
 * The screen is built as tabs, added to get_tabs() with one renderer each: Login Name Protection (1.0.0),
 * and Access Restriction and Denial Log (1.1.0).
 * 画面はタブの形で作る。get_tabs() に項目を足し、それぞれに描画関数を持たせる：
 * 「ログイン名の保護」（1.0.0）、「アクセス制限」「拒否の記録」（1.1.0）。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings screen of this plugin. / このプラグインの設定画面。
 */
class ACGD_Settings {

	/**
	 * Menu slug given to add_options_page(). / add_options_page() に渡すメニューのスラッグ。
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'etbs-account-guard';

	/**
	 * Screen ID of the settings screen ('settings_page_' . PAGE_SLUG, as add_options_page() makes it).
	 * 設定画面の画面ID（add_options_page() が作る 'settings_page_' . PAGE_SLUG）。
	 *
	 * @var string
	 */
	const SCREEN_ID = 'settings_page_etbs-account-guard';

	/**
	 * Settings group of the Login Name Protection tab (the option_page value sent to options.php).
	 * 「ログイン名の保護」タブの設定グループ（options.php へ送る option_page の値）。
	 *
	 * @var string
	 */
	const LOGIN_NAME_GROUP = 'acgd_login_name_protection';

	/**
	 * Page ID of the Settings API sections of the Login Name Protection tab.
	 * 「ログイン名の保護」タブの Settings API のセクションを束ねるページID。
	 *
	 * @var string
	 */
	const LOGIN_NAME_SECTIONS = 'etbs-account-guard-login-name';

	/**
	 * Settings group of the Access Restriction tab (the option_page value sent to options.php).
	 * 「アクセス制限」タブの設定グループ（options.php へ送る option_page の値）。
	 *
	 * @var string
	 */
	const ACCESS_GROUP = 'acgd_access_restriction';

	/**
	 * Page ID of the Settings API sections of the Access Restriction tab.
	 * 「アクセス制限」タブの Settings API のセクションを束ねるページID。
	 *
	 * @var string
	 */
	const ACCESS_SECTIONS = 'etbs-account-guard-access';

	/**
	 * Maximum number of users listed in item h, and in the "resulting restricted users" list of the Access
	 * Restriction tab. h の一覧と、「アクセス制限」タブの「結果として制限されるユーザーの一覧」に出すユーザーの上限。
	 *
	 * @var int
	 */
	const PUBLIC_NAME_LIST_LIMIT = 100;

	/**
	 * HTML id of the heading of the list of item h. The dashboard widget links to it.
	 * h の一覧の見出しの HTML の id。ダッシュボードのウィジェットがここへリンクする。
	 *
	 * @var string
	 */
	const PUBLIC_NAMES_ID = 'acgd-public-names';

	/**
	 * Prefix of the transient that holds a rejected Access Restriction tab submission, so the form can be
	 * redisplayed with what the admin actually typed instead of the unchanged saved value (UX review).
	 * Keyed by the submitting user (options.php redirects to a fresh GET, so $_POST itself does not survive;
	 * this transient is the bridge). Read once and deleted immediately after (see get_resubmit_data()), and
	 * expires on its own after RESUBMIT_TTL regardless, so it needs no entry in uninstall.php.
	 * 「アクセス制限」タブの、拒否された送信内容を保持する transient の接頭辞。保存済みの値ではなく、
	 * 管理者が実際に入力した内容でフォームを出し直すために使う（UX レビュー）。送信した本人ごとに分ける
	 * （options.php は新しい GET へ転送するため $_POST 自体は残らず、この transient が橋渡しになる）。
	 * 一度読んだら即座に消し（get_resubmit_data() を参照）、そうでなくても RESUBMIT_TTL で自然に消えるため、
	 * uninstall.php への記載は不要。
	 *
	 * @var string
	 */
	const RESUBMIT_TRANSIENT_PREFIX = 'acgd_access_resubmit_';

	/**
	 * How long a rejected submission is kept for redisplay. Long enough to cover the redirect-then-render
	 * round trip; short enough that a stale one from an abandoned attempt does not resurface later.
	 * 拒否された送信内容を残しておく時間。転送されてから描画されるまでの往復には十分長く、
	 * 途中でやめた入力が後になって出てこないよう十分短くする。
	 *
	 * @var int
	 */
	const RESUBMIT_TTL = MINUTE_IN_SECONDS;

	/**
	 * In-request cache of get_resubmit_data(), so the transient is fetched and deleted only once even
	 * though both render_access_roles_field() and render_access_ips_field() need it on the same page load.
	 * false = not yet loaded. null = loaded, and there was nothing to redisplay.
	 * get_resubmit_data() のリクエスト内キャッシュ。render_access_roles_field() と render_access_ips_field()
	 * が同じページ読み込みで両方これを必要とするため、transient の取得・削除は1回だけにする。
	 * false = 未取得。null = 取得済みで、出し直す内容が無かった。
	 *
	 * @var array|null|false
	 */
	private static $resubmit_cache = false;

	/**
	 * Registers the hooks. / フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_access_restriction_settings' ) );
	}

	/**
	 * Returns the tabs of the settings screen: slug => label.
	 * 設定画面のタブを返す（スラッグ => ラベル）。
	 *
	 * @return string[] Tabs. / タブ。
	 */
	public static function get_tabs() {
		return array(
			'login-name' => __( 'Login Name Protection', 'etbs-account-guard' ),
			'access'     => __( 'Access Restriction', 'etbs-account-guard' ),
			'log'        => __( 'Denial Log', 'etbs-account-guard' ),
		);
	}

	/**
	 * Returns the tab to show. An unknown or missing tab falls back to the first one.
	 * 表示するタブを返す。未知・未指定のタブは先頭のタブに倒す。
	 *
	 * @return string Tab slug. / タブのスラッグ。
	 */
	public static function get_current_tab() {
		$tabs = self::get_tabs();

		// Only picks a tab to display; nothing is changed. / 表示するタブを選ぶだけで、何も変更しない。
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		return isset( $tabs[ $tab ] ) ? $tab : (string) key( $tabs );
	}

	/**
	 * Returns the URL of the settings screen. / 設定画面の URL を返す。
	 *
	 * @param string $tab Tab slug. Empty for the first tab. / タブのスラッグ。空なら先頭のタブ。
	 * @return string URL, not escaped. / URL（未エスケープ）。
	 */
	public static function get_page_url( $tab = '' ) {
		$args = array( 'page' => self::PAGE_SLUG );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * Adds the settings screen under Settings. / 設定メニューの下に設定画面を足す。
	 *
	 * @return void
	 */
	public static function register_page() {
		add_options_page(
			__( 'ETBS Account Guard', 'etbs-account-guard' ),
			__( 'ETBS Account Guard', 'etbs-account-guard' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/*-------------------------------------------*/
	/* Login Name Protection tab: settings / 「ログイン名の保護」タブ：設定
	/*-------------------------------------------*/

	/**
	 * Registers the option, the section and the fields of the Login Name Protection tab.
	 * 「ログイン名の保護」タブのオプション・セクション・項目を登録する。
	 *
	 * The option is saved by core's options.php, so it never appears in an update_option() search.
	 * Keep it in mind when listing what this plugin stores (see uninstall.php).
	 * このオプションは本体の options.php が保存するので、update_option() を検索しても現れない。
	 * このプラグインが保存するものを数えるときに漏らさないこと（uninstall.php を参照）。
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::LOGIN_NAME_GROUP,
			ACGD_Login_Name::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_login_name_settings' ),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'acgd_login_name_items',
			__( 'Protection items', 'etbs-account-guard' ),
			array( __CLASS__, 'render_login_name_section' ),
			self::LOGIN_NAME_SECTIONS
		);

		foreach ( self::get_login_name_fields() as $key => $field ) {
			add_settings_field(
				'acgd_login_name_' . $key,
				// do_settings_fields() prints the title as it is, so escape it here. / do_settings_fields() は見出しをそのまま出すので、ここでエスケープする。
				esc_html( $field['title'] ),
				array( __CLASS__, 'render_login_name_field' ),
				self::LOGIN_NAME_SECTIONS,
				'acgd_login_name_items',
				array( 'key' => $key )
			);
		}
	}

	/**
	 * Keeps only the known switches of the Login Name Protection tab, as booleans.
	 * 「ログイン名の保護」タブの既知のスイッチだけを、真偽値にして残す。
	 *
	 * An unchecked checkbox is not sent at all, so a missing key means off.
	 * The result is valid input again, so running it twice (as add_option() does on the first save) changes nothing.
	 * チェックの外れたチェックボックスは送られないので、キーが無いことは OFF を意味する。
	 * 結果はそのまま正しい入力でもあるので、2回通しても（初回保存で add_option() がそうする）変わらない。
	 *
	 * @param mixed $input Submitted value. / 送信された値。
	 * @return bool[] Switch name => enabled. / スイッチ名 => 有効かどうか。
	 */
	public static function sanitize_login_name_settings( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$output = array();
		foreach ( array_keys( ACGD_Login_Name::get_defaults() ) as $key ) {
			$output[ $key ] = ! empty( $input[ $key ] );
		}

		return $output;
	}

	/**
	 * Returns the fields of the Login Name Protection tab, in the order of items a to g.
	 * 「ログイン名の保護」タブの項目を a〜g の順で返す。
	 *
	 * 'label' and each entry of 'description' are HTML with every translated part escaped; only <code> is
	 * added around fixed values. They are printed through wp_kses() with <code> allowed.
	 * 'description' holds one sentence per entry, and each is printed as its own paragraph. The descriptions
	 * follow one pattern: what can be seen while the item is off, then what changes when it is on.
	 * 'label' と 'description' の各要素は、翻訳部分をすべてエスケープした HTML。固定の値を <code> で囲むだけで、
	 * 出力時は <code> だけを許可した wp_kses() を通す。
	 * 'description' は1要素に1文を持ち、それぞれを1つの段落として出す。説明は「オフの間に何が見えるか」、
	 * 続いて「オンにすると何が変わるか」の順に揃える。
	 *
	 * @return array[] Switch name => array( 'title', 'label', 'description' ). / スイッチ名 => 見出し・ラベル・説明。
	 */
	public static function get_login_name_fields() {
		return array(
			'rest_users'     => array(
				'title'       => __( 'REST API', 'etbs-account-guard' ),
				'label'       => sprintf(
					/* translators: %s: REST API route of the users, such as /wp/v2/users */
					esc_html__( 'Hide the user information of the REST API (%s) from visitors who are not logged in', 'etbs-account-guard' ),
					self::code( '/wp/v2/users' )
				),
				'description' => array(
					sprintf(
						/* translators: %s: address of the user list of the REST API, such as /wp-json/wp/v2/users */
						esc_html__( 'While this is off, anyone can see the list of users and their login names by opening %s in a browser.', 'etbs-account-guard' ),
						self::code( '/wp-json/wp/v2/users' )
					),
					esc_html__( 'Themes and apps that read author names through the REST API without logging in, such as headless sites, will no longer get them.', 'etbs-account-guard' ),
					esc_html__( 'Logged-in users, including the block editor, can use them as before.', 'etbs-account-guard' ),
				),
			),
			'oembed_author'  => array(
				'title'       => __( 'Embeds (oEmbed)', 'etbs-account-guard' ),
				'label'       => esc_html__( 'Use the site name and home page URL as the author of embedded posts', 'etbs-account-guard' ),
				'description' => array(
					esc_html__( 'While this is off, a post embedded on another site carries the address of the author page, which contains the login name.', 'etbs-account-guard' ),
				),
			),
			'users_sitemap'  => array(
				'title'       => __( 'Sitemap', 'etbs-account-guard' ),
				'label'       => sprintf(
					/* translators: %s: file name of the user sitemap, such as wp-sitemap-users-1.xml */
					esc_html__( 'Remove the user sitemap (%s)', 'etbs-account-guard' ),
					self::code( 'wp-sitemap-users-1.xml' )
				),
				'description' => array(
					esc_html__( 'While this is off, the user sitemap lists author page addresses, which contain login names.', 'etbs-account-guard' ),
					esc_html__( 'Sitemaps of posts and pages are not affected.', 'etbs-account-guard' ),
				),
			),
			'name_classes'   => array(
				'title'       => __( 'HTML class names', 'etbs-account-guard' ),
				'label'       => esc_html__( 'Remove class names that contain the login name', 'etbs-account-guard' ),
				'description' => array(
					esc_html__( 'While this is off, the HTML of comments by registered users and of author pages contains class names with the login name.', 'etbs-account-guard' ),
					sprintf(
						/* translators: 1: class name added to comments by registered users, 2: class name added to author pages */
						esc_html__( 'This removes %1$s from comments by registered users and %2$s from author pages.', 'etbs-account-guard' ),
						self::code( 'comment-author-{name}' ),
						self::code( 'author-{name}' )
					),
					sprintf(
						/* translators: %s: example of a class name that contains the user ID */
						esc_html__( 'Class names that contain the user ID, such as %s, are kept.', 'etbs-account-guard' ),
						self::code( 'author-1' )
					),
					esc_html__( 'If your custom CSS uses these class names, that CSS will no longer apply.', 'etbs-account-guard' ),
				),
			),
			'author_query'   => array(
				'title'       => __( 'Author ID links', 'etbs-account-guard' ),
				'label'       => sprintf(
					/* translators: %s: example of a link by author number, such as /?author=1 */
					esc_html__( 'Redirect links by author number, such as %s, to the home page', 'etbs-account-guard' ),
					self::code( '/?author=1' )
				),
				'description' => array(
					esc_html__( 'While this is off, WordPress sends these links to the author page, whose address contains the login name.', 'etbs-account-guard' ),
					esc_html__( 'Filtering by author in the admin screens is not affected.', 'etbs-account-guard' ),
				),
			),
			'login_messages' => array(
				'title'       => __( 'Login errors', 'etbs-account-guard' ),
				'label'       => esc_html__( 'Show the same error for an unknown username and a wrong password', 'etbs-account-guard' ),
				'description' => array(
					esc_html__( 'While this is off, the error message tells whether the username exists.', 'etbs-account-guard' ),
					esc_html__( 'On the lost password screen, an unknown username or email address shows the same screen as a registered one.', 'etbs-account-guard' ),
					esc_html__( 'Registered users receive the password reset email as before.', 'etbs-account-guard' ),
					esc_html__( 'Errors from other plugins, such as CAPTCHA or login lockout, are shown as they are.', 'etbs-account-guard' ),
				),
			),
			'author_archive' => array(
				'title'       => __( 'Author pages', 'etbs-account-guard' ),
				'label'       => sprintf(
					/* translators: %s: URL of an author page, such as /author/{name}/ */
					esc_html__( 'Show a "Page not found" screen instead of author pages (%s) to visitors who are not logged in', 'etbs-account-guard' ),
					self::code( '/author/{name}/' )
				),
				'description' => array(
					esc_html__( 'While this is off, the address of each author page contains the login name, so anyone who follows a link to it can see the login name.', 'etbs-account-guard' ),
					esc_html__( 'Before turning this on, open a post on your site and click the author name.', 'etbs-account-guard' ),
					sprintf(
						/* translators: %s: the part that the address of every author page contains, such as /author/ */
						esc_html__( 'If a page whose address contains %s opens, your theme links to author pages, and those links will lead to "Page not found".', 'etbs-account-guard' ),
						self::code( '/author/' )
					),
					esc_html__( 'While you are logged in, author pages are shown as before, so check the result in a private window of your browser.', 'etbs-account-guard' ),
				),
			),
		);
	}

	/**
	 * Returns the HTML id of the checkbox of one switch. Links (such as the one from the dashboard widget
	 * to "Author pages") use it as the anchor.
	 * スイッチ1つのチェックボックスの HTML の id を返す。リンク（ダッシュボードのウィジェットから
	 * 「投稿者ページ」へのものなど）はこれをアンカーに使う。
	 *
	 * @param string $key Switch name (a key of ACGD_Login_Name::get_defaults()). / スイッチ名（ACGD_Login_Name::get_defaults() のキー）。
	 * @return string HTML id, such as acgd-login-name-author-archive. / HTML の id（例 acgd-login-name-author-archive）。
	 */
	public static function get_field_id( $key ) {
		return 'acgd-login-name-' . str_replace( '_', '-', $key );
	}

	/**
	 * Wraps a fixed value in <code>. / 固定の値を <code> で囲む。
	 *
	 * @param string $text Fixed value, not escaped. / 固定の値（未エスケープ）。
	 * @return string Escaped HTML. / エスケープ済みの HTML。
	 */
	private static function code( $text ) {
		return '<code>' . esc_html( $text ) . '</code>';
	}

	/**
	 * HTML allowed in the labels and descriptions of the fields. / 項目のラベルと説明で許可する HTML。
	 *
	 * @return array Allowed HTML for wp_kses(). / wp_kses() に渡す許可リスト。
	 */
	private static function allowed_field_html() {
		return array( 'code' => array() );
	}

	/*-------------------------------------------*/
	/* Screen / 画面
	/*-------------------------------------------*/

	/**
	 * Prints the settings screen. / 設定画面を出力する。
	 *
	 * The "Settings saved." notice is printed by core (wp-admin/options-head.php is loaded for pages
	 * under Settings), so settings_errors() is not called here; calling it again would print it twice.
	 * 「設定を保存しました。」の通知は本体が出す（設定メニュー配下の画面では wp-admin/options-head.php が
	 * 読み込まれる）。ここで settings_errors() を呼ぶと二重に出るので呼ばない。
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current = self::get_current_tab();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Settings tabs', 'etbs-account-guard' ); ?>">
				<?php
				foreach ( self::get_tabs() as $slug => $label ) {
					$is_current = $current === $slug;
					printf(
						'<a href="%1$s" class="%2$s"%3$s>%4$s</a>',
						esc_url( self::get_page_url( $slug ) ),
						esc_attr( $is_current ? 'nav-tab nav-tab-active' : 'nav-tab' ),
						$is_current ? ' aria-current="page"' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>

			<?php
			// The Access Restriction tab warns here, above the tab content, when this feature is currently
			// stopped (docs/spec.md 5.5): a fault of its own, or the emergency switch.
			// 「アクセス制限」タブでは、この機能が止まっているとき（docs/spec.md 5.5：自分自身の故障、
			// または非常用スイッチ）に、タブの中身より上でここに警告を出す。
			if ( 'access' === $current ) {
				self::render_access_restriction_notices();
			}
			// One renderer per tab. / タブごとに描画関数を1つ持つ。
			if ( 'login-name' === $current ) {
				self::render_login_name_tab();
			} elseif ( 'access' === $current ) {
				self::render_access_tab();
			} elseif ( 'log' === $current ) {
				self::render_log_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Prints the Login Name Protection tab: the switches, then the list of item h.
	 * 「ログイン名の保護」タブを出力する（スイッチ、その下に h の一覧）。
	 *
	 * @return void
	 */
	private static function render_login_name_tab() {
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( self::LOGIN_NAME_GROUP );
			do_settings_sections( self::LOGIN_NAME_SECTIONS );
			submit_button();
			?>
		</form>
		<?php
		self::render_public_name_section();
	}

	/**
	 * Prints the introduction of the protection items. / 保護する項目の前置きを出力する。
	 *
	 * @return void
	 */
	public static function render_login_name_section() {
		?>
		<p>
			<?php
			// Joined without a space in languages that do not use one. / 空白を使わない言語では空白なしでつなぐ。
			echo wp_kses(
				acgd_join_sentences(
					array(
						esc_html__( 'WordPress reveals login names to visitors who are not logged in at several places, and each item below closes one of them.', 'etbs-account-guard' ),
						esc_html__( 'Keep them on, and turn one off only if your theme or another plugin stops working as expected after you activate this plugin.', 'etbs-account-guard' ),
					)
				),
				acgd_allowed_sentence_html()
			);
			?>
		</p>
		<p><?php esc_html_e( '"Author pages" is off by default; read its description before turning it on.', 'etbs-account-guard' ); ?></p>
		<?php
	}

	/**
	 * Prints one switch of the Login Name Protection tab. / 「ログイン名の保護」タブのスイッチを1つ出力する。
	 *
	 * @param array $args Field arguments. 'key' is the switch name. / 項目の引数。'key' がスイッチ名。
	 * @return void
	 */
	public static function render_login_name_field( $args ) {
		$fields = self::get_login_name_fields();
		$key    = isset( $args['key'] ) ? $args['key'] : '';
		if ( ! isset( $fields[ $key ] ) ) {
			return;
		}

		$field        = $fields[ $key ];
		$settings     = ACGD_Login_Name::get_settings();
		$input_id     = self::get_field_id( $key );
		$descriptions = array_values( $field['description'] );

		// One paragraph per sentence, each with an id; the checkbox lists them in aria-describedby.
		// 1文につき1段落とし、それぞれに id を付ける。チェックボックスは aria-describedby でそれらを列挙する。
		$description_ids = array();
		foreach ( array_keys( $descriptions ) as $index ) {
			$description_ids[] = $input_id . '-description-' . ( $index + 1 );
		}
		?>
		<fieldset>
			<legend class="screen-reader-text"><span><?php echo esc_html( $field['title'] ); ?></span></legend>
			<label for="<?php echo esc_attr( $input_id ); ?>">
				<input type="checkbox" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( ACGD_Login_Name::OPTION . '[' . $key . ']' ); ?>" value="1" aria-describedby="<?php echo esc_attr( implode( ' ', $description_ids ) ); ?>" <?php checked( ! empty( $settings[ $key ] ) ); ?> />
				<?php echo wp_kses( $field['label'], self::allowed_field_html() ); ?>
			</label>
			<?php foreach ( $descriptions as $index => $sentence ) : ?>
				<p class="description" id="<?php echo esc_attr( $description_ids[ $index ] ); ?>"><?php echo wp_kses( $sentence, self::allowed_field_html() ); ?></p>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Prints the list of users whose display name or nickname is their login name (item h). Read only.
	 * 表示名またはニックネームがログイン名と同じユーザーの一覧を出力する（h）。読み取りのみ。
	 *
	 * @return void
	 */
	private static function render_public_name_section() {
		$result = ACGD_Login_Name::find_users_with_login_as_public_name( self::PUBLIC_NAME_LIST_LIMIT );
		?>
		<h2 id="<?php echo esc_attr( self::PUBLIC_NAMES_ID ); ?>"><?php esc_html_e( 'Users whose public name is their login name', 'etbs-account-guard' ); ?></h2>
		<p>
			<?php
			echo wp_kses(
				acgd_join_sentences(
					array(
						esc_html__( 'Display names and nicknames are shown to visitors, for example as the author of posts and comments and in feeds.', 'etbs-account-guard' ),
						esc_html__( 'For each user below, open the profile, change the nickname, and choose a different name in "Display name publicly as".', 'etbs-account-guard' ),
						esc_html__( 'This plugin does not change them automatically.', 'etbs-account-guard' ),
					)
				),
				acgd_allowed_sentence_html()
			);
			?>
		</p>
		<?php
		if ( 0 === $result['total'] ) {
			?>
			<p><?php esc_html_e( 'No users have a display name or nickname that is the same as their login name.', 'etbs-account-guard' ); ?></p>
			<?php
			return;
		}

		if ( $result['total'] > count( $result['users'] ) ) {
			?>
			<p>
				<?php
				printf(
					/* translators: 1: number of users shown, 2: number of matching users */
					esc_html__( 'Showing the first %1$d of %2$d users.', 'etbs-account-guard' ),
					(int) count( $result['users'] ),
					(int) $result['total']
				);
				?>
			</p>
			<?php
		}
		?>
		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Login name (Username)', 'etbs-account-guard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Display name', 'etbs-account-guard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Nickname', 'etbs-account-guard' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['users'] as $user ) : ?>
					<?php
					// The flags compare the values shown in this row with the same SQL comparison that finds the users, so they follow the same collation (letter case is ignored).
					// 判定はこの行に出す値を、ユーザーを探すのと同じ SQL の比較で比べたものなので、照合順序（大文字小文字を区別しない）も同じになる。
					$edit_link = get_edit_user_link( (int) $user->ID );
					?>
					<tr>
						<td>
							<?php if ( $edit_link ) : ?>
								<a href="<?php echo esc_url( $edit_link ); ?>"><strong><?php echo esc_html( $user->user_login ); ?></strong></a>
								<?php // The same row action as the Users screen; it opens the profile at the nickname field. / ユーザー一覧と同じ行の操作。プロフィールをニックネームの欄で開く。 ?>
								<div class="row-actions visible"><span class="edit"><a href="<?php echo esc_url( $edit_link . '#nickname' ); ?>"><?php esc_html_e( 'Edit', 'etbs-account-guard' ); ?></a></span></div>
							<?php else : ?>
								<strong><?php echo esc_html( $user->user_login ); ?></strong>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( self::describe_public_name( $user->display_name, ! empty( $user->display_matches ) ) ); ?></td>
						<td><?php echo esc_html( self::describe_public_name( (string) $user->nickname, ! empty( $user->nickname_matches ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Returns a public name for the list of item h, with a note in words when it is the same as the login name.
	 * h の一覧用に公開される名前を返す。ログイン名と同じときは、そのことを文字で添える。
	 *
	 * The note is text, not only a color or an icon, so that it is not lost for anyone.
	 * 色やアイコンだけに頼らず文字で添えるので、誰にとっても情報が失われない。
	 *
	 * @param string $name    Display name or nickname. / 表示名またはニックネーム。
	 * @param bool   $matches Whether it is the same as the login name. / ログイン名と同じか。
	 * @return string Text, not escaped. / 文字列（未エスケープ）。
	 */
	public static function describe_public_name( $name, $matches ) {
		if ( ! $matches ) {
			return $name;
		}

		/* translators: %s: display name or nickname that is the same as the login name */
		return sprintf( __( '%s (same as the login name)', 'etbs-account-guard' ), $name );
	}

	/*-------------------------------------------*/
	/* Access Restriction tab: settings / 「アクセス制限」タブ：設定
	/*-------------------------------------------*/

	/**
	 * Registers the option, the section and the fields of the Access Restriction tab.
	 * 「アクセス制限」タブのオプション・セクション・項目を登録する。
	 *
	 * A separate admin_init callback from register_settings(), so that a change to one tab's registration
	 * never risks the other's. The option is saved by core's options.php, so, like the Login Name Protection
	 * option, it never appears in an update_option() search (see uninstall.php).
	 * register_settings() とは別の admin_init コールバックにし、片方のタブの登録を変えても
	 * もう片方に影響しないようにする。このオプションも本体の options.php が保存するため、
	 * 「ログイン名の保護」のオプションと同じく update_option() を検索しても現れない（uninstall.php を参照）。
	 *
	 * @return void
	 */
	public static function register_access_restriction_settings() {
		register_setting(
			self::ACCESS_GROUP,
			ACGD_Access_Restriction::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_access_restriction_settings' ),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'acgd_access_roles',
			__( 'Restriction by role', 'etbs-account-guard' ),
			array( __CLASS__, 'render_access_roles_section' ),
			self::ACCESS_SECTIONS
		);
		add_settings_field(
			'acgd_access_roles_table',
			__( 'Roles', 'etbs-account-guard' ),
			array( __CLASS__, 'render_access_roles_field' ),
			self::ACCESS_SECTIONS,
			'acgd_access_roles'
		);

		add_settings_section(
			'acgd_access_ips',
			__( 'Site-wide IP list', 'etbs-account-guard' ),
			array( __CLASS__, 'render_access_ips_section' ),
			self::ACCESS_SECTIONS
		);
		add_settings_field(
			'acgd_access_site_ips',
			__( 'Allowed IP addresses', 'etbs-account-guard' ),
			array( __CLASS__, 'render_access_ips_field' ),
			self::ACCESS_SECTIONS,
			'acgd_access_ips',
			// label_for makes the Settings API wrap the title in <label for="...">, matching the id the
			// textarea uses (render_access_ips_field()). Without it the field has no accessible name.
			// label_for を渡すと、Settings API が見出しを <label for="..."> で包む。テキストエリアの id
			// （render_access_ips_field() を参照）と合わせている。無いと、この項目だけアクセシブルな名前を持たない。
			array( 'label_for' => 'acgd-access-site-ips' )
		);
	}

	/**
	 * Sanitizes and validates the Access Restriction tab, and applies save-time checks 1 and 2 (docs/spec.md 5.1).
	 * 「アクセス制限」タブを検証し、保存時のチェック1・2（docs/spec.md 5.1）を適用する。
	 *
	 * Check 3 (every BASIC-mode user has credentials set) is not implemented here: BASIC mode is not offered
	 * by this screen yet (see sanitize_role_modes()), so it cannot be reached from here (issue #4).
	 * On any failure, the previously saved value is returned unchanged and an error is queued with
	 * add_settings_error(), which the Settings API prints back on this same tab.
	 * チェック3（BASIC モードのユーザー全員が資格情報を設定済み）はここでは実装しない。この画面では
	 * まだ BASIC モードを選べない（sanitize_role_modes() を参照）ため、ここには到達しない（issue #4）。
	 * どの判定に失敗しても、保存済みの値をそのまま返し、add_settings_error() でエラーを積む。
	 * Settings API が同じタブにそれを出し直す。
	 *
	 * @param mixed $input Submitted value. / 送信された値。
	 * @return array The value to save. / 保存する値。
	 */
	public static function sanitize_access_restriction_settings( $input ) {
		$existing = get_option( ACGD_Access_Restriction::OPTION, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$new_roles = self::sanitize_role_modes( isset( $input['roles'] ) ? $input['roles'] : array() );
		$ip_text   = isset( $input['site_ips'] ) ? (string) wp_unslash( $input['site_ips'] ) : '';
		$validated = ACGD_Access_Restriction::validate_ip_list( $ip_text );

		if ( $validated['invalid'] ) {
			add_settings_error(
				ACGD_Access_Restriction::OPTION,
				'acgd_invalid_ip',
				sprintf(
					/* translators: 1: line number, 2: the line's content */
					esc_html__( 'Line %1$d of the site-wide IP list is not a valid IP address or range: %2$s', 'etbs-account-guard' ),
					(int) key( $validated['invalid'] ),
					// settings_errors() prints this message unescaped, and this line is the admin's own raw
					// submitted input; escape it here rather than trust it.
					// settings_errors() はこのメッセージを未エスケープで出力するため、ここで自前でエスケープする。
					// この行は管理者自身が送信した生の入力である。
					esc_html( reset( $validated['invalid'] ) )
				)
			);
			self::stash_resubmit( $new_roles, $ip_text );
			return $existing;
		}

		// Save-time check 1 (docs/spec.md 5.1): at least one unrestricted manage_options user must remain.
		// 保存時のチェック1（docs/spec.md 5.1）：制限されていない manage_options のユーザーが1人以上残ること。
		if ( ACGD_Access_Restriction::count_unrestricted_admins( $new_roles ) < 1 ) {
			add_settings_error(
				ACGD_Access_Restriction::OPTION,
				'acgd_no_unrestricted_admin',
				// Says where to go, not just what is wrong (UX review): switch someone who can manage
				// options, on this tab or on their own user edit screen, back to no restriction.
				// settings_errors() prints this unescaped; the string has no user input, but esc_html__()
				// is used anyway for consistency with the messages below that do carry user input.
				// 何が悪いかだけでなく、どこへ行けばよいかも書く（UX レビュー）。設定を管理できる誰かを、
				// このタブかその人自身の編集画面で「制限なし」に戻す。settings_errors() はこれを未エスケープで
				// 出力する。この文字列自体に利用者の入力は無いが、利用者の入力を含む下のメッセージと
				// 揃えるため esc_html__() にしている。
				esc_html__( 'This would leave no administrator (or other user who can manage options) without a restriction. Change one of them back to "No restriction" here or on their own user edit screen. Not saved.', 'etbs-account-guard' )
			);
			self::stash_resubmit( $new_roles, $ip_text );
			return $existing;
		}

		// Save-time check 2 (docs/spec.md 5.1): the current access must satisfy the new settings.
		// 保存時のチェック2（docs/spec.md 5.1）：いまのアクセスが新しい設定を満たしていること。
		if ( ! ACGD_Access_Restriction::current_user_still_allowed( $new_roles, $validated['entries'] ) ) {
			$remote = ACGD_Access_Restriction::get_remote_addr();
			add_settings_error(
				ACGD_Access_Restriction::OPTION,
				'acgd_self_lockout',
				// Names the address to add, so recovering does not require first finding it elsewhere on
				// the tab (UX review). null $remote (unreadable REMOTE_ADDR) falls back to a plain message.
				// 直す手がかりとして、追加すべきアドレスをここに書く。タブの別の場所で先に探す必要が無いように
				// する（UX レビュー）。$remote が null（REMOTE_ADDR を読めない）ときは、そのままの文言に倒す。
				null === $remote
					? esc_html__( 'Your own account, from where you are connecting right now, would not satisfy these new settings. Not saved.', 'etbs-account-guard' )
					: sprintf(
						/* translators: %s: the current user's own IP address, to add to the site-wide IP list */
						esc_html__( 'Your own account would not satisfy these new settings: your current connection (%s) is not on the list. Add it, or choose a setting that still allows it. Not saved.', 'etbs-account-guard' ),
						// $remote already passed inet_pton() validation in get_remote_addr(); esc_html() here
						// is defense in depth, not a load-bearing escape.
						// $remote は get_remote_addr() 内で inet_pton() の検証を通過済み。ここでの esc_html() は
						// 保険であり、これが無いと危険という意味ではない。
						esc_html( $remote )
					)
			);
			self::stash_resubmit( $new_roles, $ip_text );
			return $existing;
		}

		ACGD_Access_Restriction::clear_fault();
		// A save can only succeed here after a fresh page load without a pending resubmit (the resubmit
		// path always redisplays the form and stops before another sanitize call happens), so this is only
		// ever a no-op in practice. Deleted anyway, so a leftover from an abandoned failed attempt within
		// RESUBMIT_TTL can never outlive a successful save.
		// ここに到達する保存は、保留中の再表示が無い状態（再表示の経路は必ずフォームを出し直して止まり、
		// もう一度 sanitize を呼ばない）から来るので、実際には常に無害な呼び出しになる。それでも消しておき、
		// RESUBMIT_TTL 以内に途中でやめた失敗の残りが、成功した保存より後まで残らないようにする。
		delete_transient( self::RESUBMIT_TRANSIENT_PREFIX . get_current_user_id() );

		return array(
			'roles'    => $new_roles,
			'site_ips' => $ip_text,
		);
	}

	/**
	 * Stashes a rejected Access Restriction tab submission, so the form can be redisplayed with it.
	 * See RESUBMIT_TRANSIENT_PREFIX for why a transient, rather than returning it as the option value,
	 * is used.
	 * 拒否された「アクセス制限」タブの送信内容を、フォームの出し直しに使えるよう保存する。
	 * なぜオプションの値として返す（保存する）のではなく transient を使うかは RESUBMIT_TRANSIENT_PREFIX を参照。
	 *
	 * @param string[] $roles   Sanitized (but possibly check-1/check-2-failing) role => mode. / サニタイズ済み（チェック1・2には失敗しうる）の権限 => モード。
	 * @param string   $ip_text Raw site-wide IP list text, exactly as submitted (may contain invalid lines). / 送信された生のサイトの IP 一覧（不正な行を含みうる）。
	 * @return void
	 */
	private static function stash_resubmit( $roles, $ip_text ) {
		set_transient(
			self::RESUBMIT_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'roles'    => $roles,
				'site_ips' => $ip_text,
			),
			self::RESUBMIT_TTL
		);
	}

	/**
	 * Returns a rejected submission stashed by stash_resubmit(), if any, for the current user. Reads the
	 * transient once per request (both render_access_roles_field() and render_access_ips_field() call this
	 * on the same page load) and deletes it immediately, so it is shown exactly once.
	 * stash_resubmit() が保存した、拒否された送信内容を、現在のユーザーの分だけ返す（無ければ null）。
	 * transient はリクエストにつき1回だけ読み（render_access_roles_field() と render_access_ips_field() が
	 * 同じページ読み込みでどちらもこれを呼ぶ）、読んだ直後に消すので、一度だけ表示される。
	 *
	 * @return array|null {
	 *     @type string[] $roles    Role => mode, as submitted. / 送信された 権限 => モード。
	 *     @type string   $site_ips Raw site-wide IP list text, as submitted. / 送信された生のサイトの IP 一覧。
	 * }
	 */
	private static function get_resubmit_data() {
		if ( false === self::$resubmit_cache ) {
			$key  = self::RESUBMIT_TRANSIENT_PREFIX . get_current_user_id();
			$data = get_transient( $key );
			delete_transient( $key );
			self::$resubmit_cache = is_array( $data ) ? $data : null;
		}

		return self::$resubmit_cache;
	}

	/**
	 * Keeps only known roles and known modes from the submitted per-role table.
	 * 送信された権限ごとの表から、既知の権限・既知のモードだけを残す。
	 *
	 * administrator never appears in the result, whatever was submitted for it: it is always unrestricted at
	 * the role level (docs/spec.md 5.1). 'basic' is a valid stored mode (see ACGD_Access_Restriction), but
	 * this screen does not offer it yet (BASIC authentication itself is issue #4), so it is not in the list
	 * of modes accepted here; an unknown or missing value falls back to 'none'.
	 * administrator は、送信内容にかかわらず結果に現れない。権限単位では常に制限なしのため
	 * （docs/spec.md 5.1）。'basic' は保存できるモードの1つだが（ACGD_Access_Restriction を参照）、
	 * この画面ではまだ選べない（BASIC 認証そのものは issue #4）ため、ここで受け付けるモードの一覧には無く、
	 * 未知の値・未送信は 'none' に倒す。
	 *
	 * @param mixed $input Submitted value: role => mode. / 送信された値（権限 => モード）。
	 * @return string[] Role => mode ('none' or 'ip' only). / 権限 => モード（'none' か 'ip' のみ）。
	 */
	private static function sanitize_role_modes( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$allowed_modes = array( ACGD_Access_Restriction::MODE_NONE, ACGD_Access_Restriction::MODE_IP );
		$output        = array();

		foreach ( array_keys( wp_roles()->get_names() ) as $role ) {
			if ( 'administrator' === $role ) {
				continue;
			}
			$mode            = isset( $input[ $role ] ) ? sanitize_key( wp_unslash( $input[ $role ] ) ) : ACGD_Access_Restriction::MODE_NONE;
			$output[ $role ] = in_array( $mode, $allowed_modes, true ) ? $mode : ACGD_Access_Restriction::MODE_NONE;
		}

		return $output;
	}

	/*-------------------------------------------*/
	/* Access Restriction tab: screen / 「アクセス制限」タブ：画面
	/*-------------------------------------------*/

	/**
	 * Prints the Access Restriction tab: warnings are printed separately, before the tabs, by
	 * render_access_restriction_notices(), called from render_page().
	 * 「アクセス制限」タブを出力する。警告は render_page() から呼ぶ render_access_restriction_notices() が、
	 * タブより前に別に出す。
	 *
	 * @return void
	 */
	private static function render_access_tab() {
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( self::ACCESS_GROUP );
			do_settings_sections( self::ACCESS_SECTIONS );
			submit_button();
			?>
		</form>
		<?php
		self::render_restricted_users_list();
		self::render_emergency_switch_notice();
	}

	/**
	 * Prints a warning when Access Restriction is currently stopped (docs/spec.md 5.5): a fault of its own,
	 * or the emergency switch. Printed above the tab content by render_page().
	 * アクセス制限が止まっているとき（docs/spec.md 5.5：自分自身の故障、または非常用スイッチ）に警告を出す。
	 * render_page() が、タブの中身より前にこれを出す。
	 *
	 * @return void
	 */
	private static function render_access_restriction_notices() {
		if ( ACGD_Access_Restriction::has_fault() ) {
			?>
			<div class="notice notice-error"><p><?php esc_html_e( 'Access Restriction is stopped because of an internal problem, and everyone can sign in without an IP check until this is fixed. Saving this tab again, once the settings are valid, clears this warning.', 'etbs-account-guard' ); ?></p></div>
			<?php
		}
		if ( ACGD_Access_Restriction::is_switch_disabled() ) {
			?>
			<div class="notice notice-warning"><p><?php echo wp_kses( sprintf( /* translators: %s: PHP constant name, ACGD_DISABLE_RESTRICTION */ esc_html__( 'The emergency switch (%s in wp-config.php) is turned on, so Access Restriction is stopped. Login Name Protection is not affected.', 'etbs-account-guard' ), self::code( 'ACGD_DISABLE_RESTRICTION' ) ), self::allowed_field_html() ); ?></p></div>
			<?php
		}
	}

	/**
	 * Prints the introduction of the per-role restriction section. / 権限ごとの制限の前置きを出力する。
	 *
	 * @return void
	 */
	public static function render_access_roles_section() {
		?>
		<p>
			<?php
			echo wp_kses(
				acgd_join_sentences(
					array(
						esc_html__( 'Choose a mode for each role. Everyone with that role is restricted, unless their own user setting overrides it.', 'etbs-account-guard' ),
						esc_html__( 'A user who holds more than one role, with different modes, is held to all of them.', 'etbs-account-guard' ),
					)
				),
				self::allowed_field_html()
			);
			?>
		</p>
		<p><?php esc_html_e( 'administrator is always unrestricted at the role level; restrict a specific administrator from their own user edit screen instead.', 'etbs-account-guard' ); ?></p>
		<p><?php esc_html_e( 'BASIC authentication will be added by a later update; only "No restriction" and "IP restriction" can be chosen here for now.', 'etbs-account-guard' ); ?></p>
		<?php
	}

	/**
	 * Prints the per-role mode table. / 権限ごとのモードの表を出力する。
	 *
	 * Redisplays a rejected submission (UX review) rather than the saved value, when there is one for the
	 * current user (get_resubmit_data()), so a save-time check failure does not also discard the roles the
	 * admin had just chosen.
	 * 拒否された送信内容があれば（get_resubmit_data()）、保存済みの値ではなくそちらを出し直す（UX レビュー）。
	 * 保存時のチェックに落ちても、管理者が選んだばかりの権限の内容まで失われないようにするため。
	 *
	 * @return void
	 */
	public static function render_access_roles_field() {
		$resubmit    = self::get_resubmit_data();
		$saved_roles = ( $resubmit && isset( $resubmit['roles'] ) ) ? $resubmit['roles'] : ACGD_Access_Restriction::get_role_modes();
		$all_roles   = wp_roles()->get_names();
		?>
		<table class="widefat fixed striped" style="max-width:600px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Role', 'etbs-account-guard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Mode', 'etbs-account-guard' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $all_roles as $role => $label ) : ?>
					<?php $role_label = translate_user_role( $label ); ?>
					<tr>
						<th scope="row"><?php echo esc_html( $role_label ); ?></th>
						<td>
							<?php if ( 'administrator' === $role ) : ?>
								<?php esc_html_e( 'No restriction (fixed)', 'etbs-account-guard' ); ?>
							<?php else : ?>
								<?php
								$field_id   = 'acgd-access-role-' . $role;
								$mode       = isset( $saved_roles[ $role ] ) ? $saved_roles[ $role ] : ACGD_Access_Restriction::MODE_NONE;
								$field_name = ACGD_Access_Restriction::OPTION . '[roles][' . $role . ']';
								?>
								<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: role name, such as Editor */ __( 'Mode for %s', 'etbs-account-guard' ), $role_label ) ); ?>">
									<option value="none" <?php selected( 'none', $mode ); ?>><?php esc_html_e( 'No restriction', 'etbs-account-guard' ); ?></option>
									<option value="ip" <?php selected( 'ip', $mode ); ?>><?php esc_html_e( 'IP restriction', 'etbs-account-guard' ); ?></option>
								</select>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Prints the introduction of the site-wide IP list section, including the current connection (moved
	 * here, ahead of the field and the Save button, per UX review: an admin filling in this field, or
	 * recovering from the self-lockout error of check 2, needs their own address before typing, not after
	 * scrolling past the submit button).
	 * サイトの IP 一覧の前置きを出力する。いまの接続元もここに含める（UX レビューにより、項目・保存ボタンより
	 * 前に移した。この項目に入力する管理者や、チェック2の締め出しエラーから復帰する管理者は、
	 * 送信ボタンを過ぎてスクロールした後ではなく、入力する前に自分のアドレスを知る必要があるため）。
	 *
	 * @return void
	 */
	public static function render_access_ips_section() {
		self::render_current_connection();
		?>
		<p>
			<?php
			echo wp_kses(
				acgd_join_sentences(
					array(
						sprintf(
							/* translators: 1: example of a single IP address, 2: example of an IP range in CIDR notation */
							esc_html__( 'One IP address or range (CIDR) per line, such as %1$s or %2$s. Text after # is a note, and blank lines are ignored.', 'etbs-account-guard' ),
							self::code( '192.0.2.10' ),
							self::code( '192.0.2.0/24' )
						),
						esc_html__( 'A restricted user is let in from any address on this list, plus any address added just for them on their own user edit screen.', 'etbs-account-guard' ),
						esc_html__( 'Judged from the address the server sees for this connection only; headers such as X-Forwarded-For are never used.', 'etbs-account-guard' ),
					)
				),
				self::allowed_field_html()
			);
			?>
		</p>
		<?php
	}

	/**
	 * Prints the site-wide IP list textarea. / サイトの IP 一覧のテキストエリアを出力する。
	 *
	 * Redisplays a rejected submission (UX review) rather than the saved value, when there is one for the
	 * current user (get_resubmit_data()); see render_access_roles_field() for the same reasoning.
	 * 拒否された送信内容があれば（get_resubmit_data()）、保存済みの値ではなくそちらを出し直す（UX レビュー）。
	 * 理由は render_access_roles_field() と同じ。
	 *
	 * @return void
	 */
	public static function render_access_ips_field() {
		$resubmit = self::get_resubmit_data();
		$ip_text  = ( $resubmit && isset( $resubmit['site_ips'] ) ) ? $resubmit['site_ips'] : ACGD_Access_Restriction::get_site_ip_text();
		?>
		<textarea id="acgd-access-site-ips" name="<?php echo esc_attr( ACGD_Access_Restriction::OPTION . '[site_ips]' ); ?>" rows="8" cols="50" class="large-text code"><?php echo esc_textarea( $ip_text ); ?></textarea>
		<?php
	}

	/**
	 * Prints the address the server currently sees for this connection (docs/spec.md 5.2,
	 * "サーバから見えている、いまの接続元"). Read only, but printed inside the form (called from
	 * render_access_ips_section(), ahead of the IP list field), so it is visible before typing an address
	 * and while recovering from the self-lockout error of check 2.
	 * サーバが今のこの接続について見ているアドレスを出力する（docs/spec.md 5.2
	 * 「サーバから見えている、いまの接続元」）。読み取りのみだが、フォームの中（render_access_ips_section() から、
	 * IP 一覧の項目より前に）呼ぶ。アドレスを入力する前や、チェック2の締め出しエラーから復帰するときに見えるようにするため。
	 *
	 * @return void
	 */
	private static function render_current_connection() {
		$remote = ACGD_Access_Restriction::get_remote_addr();
		?>
		<p>
			<strong><?php esc_html_e( 'Your current connection:', 'etbs-account-guard' ); ?></strong>
			<?php
			if ( null === $remote ) {
				esc_html_e( 'The server cannot tell what address you are connecting from right now.', 'etbs-account-guard' );
			} else {
				echo wp_kses(
					sprintf(
						/* translators: %s: the visitor's IP address, as the server sees it for this connection */
						esc_html__( 'The server sees this connection as coming from %s.', 'etbs-account-guard' ),
						self::code( $remote )
					),
					self::allowed_field_html()
				);
			}
			?>
		</p>
		<?php
	}

	/**
	 * Prints the list of users who end up restricted under the saved settings (docs/spec.md 5.6,
	 * "結果として制限されるユーザーの一覧"). Read only.
	 * 保存済みの設定のもとで、結果として制限されるユーザーの一覧を出力する（docs/spec.md 5.6
	 * 「結果として制限されるユーザーの一覧」）。読み取りのみ。
	 *
	 * Only users who can end up restricted are loaded, a batch at a time, stopping at PUBLIC_NAME_LIST_LIMIT
	 * (see ACGD_Access_Restriction::find_restricted_users()); the order and the content of the list are the
	 * same as when every user was loaded and judged.
	 * 制限されうるユーザーだけを小分けに読み込み、PUBLIC_NAME_LIST_LIMIT 人で止める
	 * （ACGD_Access_Restriction::find_restricted_users() を参照）。一覧の順序と中身は、全ユーザーを読み込んで
	 * 判定していたときと同じ。
	 *
	 * @return void
	 */
	private static function render_restricted_users_list() {
		$restricted = ACGD_Access_Restriction::find_restricted_users( ACGD_Access_Restriction::get_role_modes(), self::PUBLIC_NAME_LIST_LIMIT );
		?>
		<h2><?php esc_html_e( 'Users who are currently restricted', 'etbs-account-guard' ); ?></h2>
		<?php if ( ! $restricted ) : ?>
			<p><?php esc_html_e( 'No user is restricted right now.', 'etbs-account-guard' ); ?></p>
			<?php
			return;
		endif;
		?>
		<?php // Horizontal scroll wrapper for narrow viewports (UX review). / 狭い画面幅での横スクロール対策（UX レビュー）。 ?>
		<div style="overflow-x:auto;">
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Login name (Username)', 'etbs-account-guard' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Mode', 'etbs-account-guard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $restricted as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['user']->user_login ); ?></td>
							<td><?php echo esc_html( ACGD_User_Access::describe_modes( $row['modes'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Prints the explanation of the emergency switch (docs/spec.md 5.5). One of the three places it is
	 * documented (README.md / readme.txt, this tab, and the dashboard widget notes).
	 * 非常用スイッチ（docs/spec.md 5.5）の説明を出力する。書く3か所のうちの1つ
	 * （README.md・readme.txt、このタブ、ダッシュボードのウィジェットの注意事項）。
	 *
	 * @return void
	 */
	private static function render_emergency_switch_notice() {
		?>
		<h2><?php esc_html_e( 'Emergency switch', 'etbs-account-guard' ); ?></h2>
		<p>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: PHP constant to add to wp-config.php, ACGD_DISABLE_RESTRICTION */
					esc_html__( 'If Access Restriction locks everyone out, add %s to wp-config.php. This stops Access Restriction only; Login Name Protection keeps working.', 'etbs-account-guard' ),
					self::code( "define( 'ACGD_DISABLE_RESTRICTION', true );" )
				),
				self::allowed_field_html()
			);
			?>
		</p>
		<?php
	}

	/*-------------------------------------------*/
	/* Denial Log tab / 「拒否の記録」タブ
	/*-------------------------------------------*/

	/**
	 * Prints the Denial Log tab (docs/spec.md 5.4): the most recent denials, newest first. Read only; there
	 * is nothing to save on this tab.
	 * 「拒否の記録」タブ（docs/spec.md 5.4）を出力する（直近の拒否を新しい順で）。読み取りのみで、
	 * このタブに保存するものは無い。
	 *
	 * @return void
	 */
	private static function render_log_tab() {
		$log = ACGD_Access_Restriction::get_denial_log();
		?>
		<p>
			<?php
			printf(
				/* translators: %d: maximum number of entries kept in the denial log */
				esc_html__( 'The most recent %d denials are kept here.', 'etbs-account-guard' ),
				(int) ACGD_Access_Restriction::DENIAL_LOG_MAX
			);
			?>
		</p>
		<?php if ( ! $log ) : ?>
			<p><?php esc_html_e( 'No denials have been recorded.', 'etbs-account-guard' ); ?></p>
			<?php
			return;
		endif;
		?>
		<?php // Horizontal scroll wrapper for narrow viewports (UX review). / 狭い画面幅での横スクロール対策（UX レビュー）。 ?>
		<div style="overflow-x:auto;">
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date and time', 'etbs-account-guard' ); ?></th>
						<th scope="col"><?php esc_html_e( 'User', 'etbs-account-guard' ); ?></th>
						<th scope="col"><?php esc_html_e( 'IP address', 'etbs-account-guard' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Where', 'etbs-account-guard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<?php
						$user = ! empty( $entry['user_id'] ) ? get_userdata( (int) $entry['user_id'] ) : false;
						?>
						<tr>
							<td>
								<?php
								// date_i18n(), not wp_date() (WordPress 5.3+): this plugin declares no minimum WordPress version.
								// date_i18n()（wp_date() は WordPress 5.3 以降のため使わない）。このプラグインは WordPress の下限を宣言していない。
								echo esc_html( date_i18n( 'Y-m-d H:i:s', isset( $entry['time'] ) ? (int) $entry['time'] : 0 ) );
								?>
							</td>
							<td><?php echo esc_html( $user ? $user->user_login : (string) ( isset( $entry['user_id'] ) ? $entry['user_id'] : '' ) ); ?></td>
							<td><?php echo esc_html( isset( $entry['ip'] ) ? (string) $entry['ip'] : '' ); ?></td>
							<td><?php echo esc_html( self::describe_denial_context( isset( $entry['context'] ) ? (string) $entry['context'] : '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Describes one denial log context in words. / 拒否の記録の場面を文字で表す。
	 *
	 * @param string $context One of 'login', 'session' or 'rest'. / 'login'・'session'・'rest' のいずれか。
	 * @return string Description, not escaped. / 説明（未エスケープ）。
	 */
	private static function describe_denial_context( $context ) {
		switch ( $context ) {
			case 'login':
				return __( 'Login', 'etbs-account-guard' );
			case 'session':
				return __( 'After login', 'etbs-account-guard' );
			case 'rest':
				return __( 'REST API', 'etbs-account-guard' );
			default:
				return $context;
		}
	}
}
