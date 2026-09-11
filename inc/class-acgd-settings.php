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
	 * 'label' and 'description' are HTML with every translated part escaped; only <code> is added around
	 * fixed values. They are printed through wp_kses() with <code> allowed.
	 * 'label' と 'description' は、翻訳部分をすべてエスケープした HTML。固定の値を <code> で囲むだけで、
	 * 出力時は <code> だけを許可した wp_kses() を通す。
	 *
	 * @return array[] Switch name => array( 'title', 'label', 'description' ). / スイッチ名 => 見出し・ラベル・説明。
	 */
	public static function get_login_name_fields() {
		return array(
			'rest_users'     => array(
				'title'       => __( 'REST API', 'etbs-account-guard' ),
				'label'       => sprintf(
					/* translators: %s: REST API route of the users, such as /wp/v2/users */
					esc_html__( 'Hide the user endpoints (%s) from visitors who are not logged in', 'etbs-account-guard' ),
					self::code( '/wp/v2/users' )
				),
				'description' => array(
					esc_html__( 'Author data embedded in posts is hidden as well.', 'etbs-account-guard' ),
					esc_html__( 'Logged-in users, including the block editor, can use them as before.', 'etbs-account-guard' ),
				),
			),
			'oembed_author'  => array(
				'title'       => __( 'oEmbed', 'etbs-account-guard' ),
				'label'       => esc_html__( 'Use the site name and home page URL as the author of embedded posts', 'etbs-account-guard' ),
				'description' => array(
					esc_html__( 'This applies when another site embeds one of your posts.', 'etbs-account-guard' ),
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
					esc_html__( 'Sitemaps of posts and pages are not affected.', 'etbs-account-guard' ),
				),
			),
			'name_classes'   => array(
				'title'       => __( 'Class names', 'etbs-account-guard' ),
				'label'       => esc_html__( 'Remove class names that contain the user name', 'etbs-account-guard' ),
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
				),
			),
			'author_query'   => array(
				'title'       => __( 'Author ID links', 'etbs-account-guard' ),
				'label'       => sprintf(
					/* translators: %s: example of a link by author ID, such as /?author=1 */
					esc_html__( 'Redirect %s to the home page', 'etbs-account-guard' ),
					self::code( '/?author=1' )
				),
				'description' => array(
					esc_html__( 'Otherwise WordPress redirects these links to the author page, whose URL contains the user name.', 'etbs-account-guard' ),
					esc_html__( 'Filtering by author in the admin screens is not affected.', 'etbs-account-guard' ),
				),
			),
			'login_messages' => array(
				'title'       => __( 'Login errors', 'etbs-account-guard' ),
				'label'       => esc_html__( 'Show the same error for an unknown username and a wrong password', 'etbs-account-guard' ),
				'description' => array(
					esc_html__( 'On the lost password screen, an unknown username or email address leads to the same screen as a registered one, and no email is sent.', 'etbs-account-guard' ),
					esc_html__( 'Errors from other plugins, such as CAPTCHA or login lockout, are shown as they are.', 'etbs-account-guard' ),
				),
			),
			'author_archive' => array(
				'title'       => __( 'Author pages', 'etbs-account-guard' ),
				'label'       => sprintf(
					/* translators: %s: URL of an author page, such as /author/{name}/ */
					esc_html__( 'Return 404 (Page not found) for author pages (%s)', 'etbs-account-guard' ),
					self::code( '/author/{name}/' )
				),
				'description' => array(
					esc_html__( 'Turn this on if your theme does not show links to author pages, because those links will lead to a Page not found screen.', 'etbs-account-guard' ),
				),
			),
		);
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
			<?php esc_html_e( 'Each item hides login names from visitors who are not logged in at one place in WordPress.', 'etbs-account-guard' ); ?>
			<?php esc_html_e( 'Uncheck an item only if it conflicts with your theme or another plugin.', 'etbs-account-guard' ); ?>
		</p>
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

		$field    = $fields[ $key ];
		$settings = ACGD_Login_Name::get_settings();
		$input_id = 'acgd-login-name-' . str_replace( '_', '-', $key );
		?>
		<fieldset>
			<legend class="screen-reader-text"><span><?php echo esc_html( $field['title'] ); ?></span></legend>
			<label for="<?php echo esc_attr( $input_id ); ?>">
				<input type="checkbox" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( ACGD_Login_Name::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?> />
				<?php echo wp_kses( $field['label'], self::allowed_field_html() ); ?>
			</label>
			<p class="description"><?php echo wp_kses( implode( ' ', $field['description'] ), self::allowed_field_html() ); ?></p>
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
		<h2><?php esc_html_e( 'Users whose public name is their login name', 'etbs-account-guard' ); ?></h2>
		<p>
			<?php esc_html_e( 'Display names and nicknames are shown to visitors, for example as the author of posts and comments and in feeds.', 'etbs-account-guard' ); ?>
			<?php esc_html_e( 'For each user below, open the profile, change the nickname, and choose a different name in "Display name publicly as".', 'etbs-account-guard' ); ?>
			<?php esc_html_e( 'This plugin does not change them automatically.', 'etbs-account-guard' ); ?>
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
					<th scope="col"><?php esc_html_e( 'Login name', 'etbs-account-guard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Display name', 'etbs-account-guard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Nickname', 'etbs-account-guard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Same as the login name', 'etbs-account-guard' ); ?></th>
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
							<?php else : ?>
								<strong><?php echo esc_html( $user->user_login ); ?></strong>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $user->display_name ); ?></td>
						<td><?php echo esc_html( (string) $user->nickname ); ?></td>
						<td><?php echo esc_html( self::describe_match( ! empty( $user->display_matches ), ! empty( $user->nickname_matches ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Returns which public name is the same as the login name, for the list of item h.
	 * h の一覧用に、どの公開される名前がログイン名と同じかを返す。
	 *
	 * @param bool $display_matches  Whether the display name matches. / 表示名が同じか。
	 * @param bool $nickname_matches Whether the nickname matches. / ニックネームが同じか。
	 * @return string Label, not escaped. / ラベル（未エスケープ）。
	 */
	private static function describe_match( $display_matches, $nickname_matches ) {
		if ( $display_matches && $nickname_matches ) {
			return __( 'Display name and nickname', 'etbs-account-guard' );
		}
		if ( $display_matches ) {
			return __( 'Display name', 'etbs-account-guard' );
		}

		return __( 'Nickname', 'etbs-account-guard' );
	}
}
