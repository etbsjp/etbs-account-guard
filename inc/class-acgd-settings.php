<?php
/**
 * Settings screen: Settings > ETBS Account Guard (screen ID settings_page_etbs-account-guard).
 * 設定画面「設定 > ETBS Account Guard」（画面ID settings_page_etbs-account-guard）。
 *
 * The screen is built as tabs. 1.0.0 has one tab, Login Name Protection; 1.1.0 adds Access Restriction
 * and Denial Log by adding entries to get_tabs() and a renderer for each.
 * 画面はタブの形で作る。1.0.0 のタブは「ログイン名の保護」の1つだけで、1.1.0 で get_tabs() に項目を足し、
 * それぞれの描画関数を足して「アクセス制限」「拒否の記録」を加える。
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
	 * Maximum number of users listed in item h. / h の一覧に出すユーザーの上限。
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
	 * Registers the hooks. / フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Returns the tabs of the settings screen: slug => label.
	 * 設定画面のタブを返す（スラッグ => ラベル）。
	 *
	 * 1.1.0 adds 'access' (Access Restriction) and 'log' (Denial Log) here.
	 * 1.1.0 でここに 'access'（アクセス制限）と 'log'（拒否の記録）を足す。
	 *
	 * @return string[] Tabs. / タブ。
	 */
	public static function get_tabs() {
		return array(
			'login-name' => __( 'Login Name Protection', 'etbs-account-guard' ),
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
			// One renderer per tab. 1.1.0 adds the renderers of its tabs here.
			// タブごとに描画関数を1つ持つ。1.1.0 でそのタブの描画関数をここに足す。
			if ( 'login-name' === $current ) {
				self::render_login_name_tab();
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
		<table class="widefat striped">
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
					// The flags come from the same SQL comparison that found the row, so they follow the same collation (letter case is ignored).
					// 判定はこの行を見つけたのと同じ SQL の比較から取るので、照合順序（大文字小文字を区別しない）も同じになる。
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
}
