<?php
/**
 * Dashboard widget: overview, how to use, notes, support and a button to the settings screen.
 * ダッシュボードのウィジェット（概要・使い方・注意事項・サポート案内・設定画面へのボタン）。
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
 * The headings are h3 because the widget title is h2. / ウィジェット名が h2 なので、見出しは h3 にする。
 *
 * @return void
 */
function acgd_render_dashboard_widget() {
	?>
	<p><?php esc_html_e( 'ETBS Account Guard keeps the login names of your users away from visitors who are not logged in.', 'etbs-account-guard' ); ?></p>

	<h3><?php esc_html_e( 'How to use', 'etbs-account-guard' ); ?></h3>
	<p>
		<?php esc_html_e( 'Most protections work as soon as the plugin is activated.', 'etbs-account-guard' ); ?>
		<?php esc_html_e( 'You can turn each one on or off on the settings screen.', 'etbs-account-guard' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'The settings screen also lists users whose display name or nickname is the same as their login name.', 'etbs-account-guard' ); ?>
		<?php esc_html_e( 'Change them from the profile of each user.', 'etbs-account-guard' ); ?>
	</p>

	<h3><?php esc_html_e( 'Notes', 'etbs-account-guard' ); ?></h3>
	<p>
		<?php
		printf(
			/* translators: %s: URL of an author page, such as /author/{name}/ */
			esc_html__( 'Author pages (%s) are still shown by default.', 'etbs-account-guard' ),
			'<code>' . esc_html( '/author/{name}/' ) . '</code>'
		);
		?>
		<?php esc_html_e( 'To return 404 for them, turn on "Author pages" on the settings screen.', 'etbs-account-guard' ); ?>
	</p>
	<p><?php esc_html_e( 'The lost password form of WooCommerce My Account is not covered.', 'etbs-account-guard' ); ?></p>
	<p>
		<?php esc_html_e( 'Two-factor authentication, login attempt limits and CAPTCHA are not included.', 'etbs-account-guard' ); ?>
		<?php esc_html_e( 'Use a dedicated security plugin for them.', 'etbs-account-guard' ); ?>
	</p>

	<h3><?php esc_html_e( 'Support', 'etbs-account-guard' ); ?></h3>
	<p>
		<?php
		echo wp_kses(
			acgd_get_support_sentences(),
			array(
				'a' => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
			)
		);
		?>
	</p>

	<p>
		<a class="button button-primary" href="<?php echo esc_url( ACGD_Settings::get_page_url() ); ?>"><?php esc_html_e( 'Open the settings', 'etbs-account-guard' ); ?></a>
	</p>
	<?php
}
