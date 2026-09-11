<?php
/**
 * Dashboard widget: current state, overview, how to use, notes, support and a button to the settings screen.
 * ダッシュボードのウィジェット（今の状態・概要・使い方・注意事項・サポート案内・設定画面へのボタン）。
 *
 * Kept in its own file so that a WordPress.org build can drop it by removing one require line
 * in the main file. Nothing else depends on it.
 * wordpress.org 版で本体ファイルの require を1行消せば外せるよう、独立したファイルにしている。
 * 他のファイルはこのファイルに依存しない。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the widget to the dashboard of users who can manage options.
 * manage_options を持つユーザーのダッシュボードにウィジェットを足す。
 *
 * @return void
 */
function acgd_add_dashboard_widget() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'acgd_dashboard_widget',
		esc_html__( 'ETBS Account Guard', 'etbs-account-guard' ),
		'acgd_render_dashboard_widget'
	);
}
add_action( 'wp_dashboard_setup', 'acgd_add_dashboard_widget' );

/**
 * Prints the content of the widget. / ウィジェットの中身を出力する。
 *
 * The headings are h3 because the widget title is h2. Sentences of the same paragraph are joined with
 * acgd_join_sentences(), so that no space appears between them in languages that do not use one.
 * ウィジェット名が h2 なので、見出しは h3 にする。同じ段落の文は acgd_join_sentences() でつなぎ、
 * 文の間に空白を入れない言語で空白が出ないようにする。
 *
 * @return void
 */
function acgd_render_dashboard_widget() {
	$allowed = acgd_allowed_sentence_html();

	// Access Restriction stopped because of its own fault (docs/spec.md 5.5) comes first: it needs attention
	// sooner than anything else the widget shows.
	// アクセス制限が自分自身の故障で止まっている（docs/spec.md 5.5）ときは先頭に出す。他の何より早く気付いてほしいため。
	if ( class_exists( 'ACGD_Access_Restriction' ) && ACGD_Access_Restriction::has_fault() ) {
		?>
		<div class="notice notice-error inline">
			<p>
				<?php
				printf(
					/* translators: %s: URL of the Access Restriction tab of the settings screen */
					wp_kses( __( 'Access Restriction is stopped because of an internal problem. Open the <a href="%s">Access Restriction tab</a> of the settings screen for details.', 'etbs-account-guard' ), array( 'a' => array( 'href' => true ) ) ),
					esc_url( ACGD_Settings::get_page_url( 'access' ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	// Current state first: users whose public name is their login name (item h). Only a count query.
	// Nothing is shown when there are none.
	// 先頭に今の状態：公開される名前がログイン名と同じユーザー（h）。件数だけの問い合わせ。0人なら何も出さない。
	$count = ACGD_Login_Name::count_users_with_login_as_public_name();
	if ( $count > 0 ) {
		?>
		<p>
			<?php
			printf(
				/* translators: %d: number of users */
				esc_html( _n( '%d user has a display name or nickname that is the same as their login name.', '%d users have a display name or nickname that is the same as their login name.', $count, 'etbs-account-guard' ) ),
				(int) $count
			);
			?>
		</p>
		<p><a href="<?php echo esc_url( ACGD_Settings::get_page_url() . '#' . ACGD_Settings::PUBLIC_NAMES_ID ); ?>"><?php esc_html_e( 'Review the users', 'etbs-account-guard' ); ?></a></p>
		<?php
	}
	?>
	<p><?php esc_html_e( 'Hides the login names of your users from visitors who are not logged in.', 'etbs-account-guard' ); ?></p>

	<h3><?php esc_html_e( 'How to use', 'etbs-account-guard' ); ?></h3>
	<p>
		<?php
		echo wp_kses(
			acgd_join_sentences(
				array(
					esc_html__( 'All protections except "Author pages" work as soon as the plugin is activated.', 'etbs-account-guard' ),
					esc_html__( 'You can turn each one on or off on the settings screen.', 'etbs-account-guard' ),
				)
			),
			$allowed
		);
		?>
	</p>

	<h3><?php esc_html_e( 'Notes', 'etbs-account-guard' ); ?></h3>
	<ul class="ul-disc">
		<li>
			<?php
			$author_pages = sprintf(
				/* translators: %s: URL of an author page, such as /author/{name}/ */
				esc_html__( 'Author pages (%s) are still shown by default, and their addresses contain the login name.', 'etbs-account-guard' ),
				'<code>' . esc_html( '/author/{name}/' ) . '</code>'
			);
			$turn_on = sprintf(
				/* translators: %s: URL of the "Author pages" item on the settings screen */
				wp_kses( __( 'If your theme does not link to author pages, turn on "Author pages" on the <a href="%s">settings screen</a>.', 'etbs-account-guard' ), array( 'a' => array( 'href' => true ) ) ),
				esc_url( ACGD_Settings::get_page_url() . '#' . ACGD_Settings::get_field_id( 'author_archive' ) )
			);
			echo wp_kses( acgd_join_sentences( array( $author_pages, $turn_on ) ), $allowed );
			?>
		</li>
		<?php if ( class_exists( 'WooCommerce' ) ) : ?>
			<li><?php esc_html_e( 'The lost password form of WooCommerce My Account is not covered.', 'etbs-account-guard' ); ?></li>
		<?php endif; ?>
		<li>
			<?php
			// One of the three places the emergency switch is documented (docs/spec.md 5.5), along with
			// README.md / readme.txt and the Access Restriction settings tab.
			// 非常用スイッチ（docs/spec.md 5.5）を書く3か所のうちの1つ（README.md・readme.txt、
			// 「アクセス制限」設定タブと合わせて）。
			echo wp_kses(
				sprintf(
					/* translators: 1: PHP constant, ACGD_DISABLE_RESTRICTION; 2: URL of the Access Restriction tab of the settings screen */
					__( 'If Access Restriction ever locks everyone out, add %1$s to wp-config.php to stop it (details on the <a href="%2$s">Access Restriction settings tab</a>).', 'etbs-account-guard' ),
					'<code>' . esc_html( "define( 'ACGD_DISABLE_RESTRICTION', true );" ) . '</code>',
					esc_url( ACGD_Settings::get_page_url( 'access' ) )
				),
				array_merge( $allowed, array( 'code' => array() ) )
			);
			?>
		</li>
		<li>
			<?php
			echo wp_kses(
				acgd_join_sentences(
					array(
						esc_html__( 'Two-factor authentication, login attempt limits and CAPTCHA are not included.', 'etbs-account-guard' ),
						esc_html__( 'Use a dedicated security plugin for them.', 'etbs-account-guard' ),
					)
				),
				$allowed
			);
			?>
		</li>
	</ul>

	<h3><?php esc_html_e( 'Support', 'etbs-account-guard' ); ?></h3>
	<p><?php echo wp_kses( acgd_get_support_sentences(), $allowed ); ?></p>

	<p>
		<a class="button button-primary" href="<?php echo esc_url( ACGD_Settings::get_page_url() ); ?>"><?php esc_html_e( 'Open the settings', 'etbs-account-guard' ); ?></a>
	</p>
	<?php
}
