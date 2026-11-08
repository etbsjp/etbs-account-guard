<?php
/**
 * Tests of the "already passed the server's own BASIC authentication" mark in inc/class-acgd-basic-auth.php
 * (ACGD_Basic_Auth::has_passed_server_auth(), issue #32).
 * inc/class-acgd-basic-auth.php の「サーバー自身の BASIC 認証を既に通った印」
 * （ACGD_Basic_Auth::has_passed_server_auth()、issue #32）のテスト。
 *
 * The $_SERVER values follow what was measured on Apache (PHP-FPM), nginx (PHP-FPM) and LiteSpeed (issue #32).
 * The 403 screen itself exits, so it is checked on the test site and recorded in the PR.
 * $_SERVER の値は Apache（PHP-FPM）・nginx（PHP-FPM）・LiteSpeed での実測（issue #32）に合わせている。
 * 403 の画面そのものは exit するので、検証サイトで確かめて PR に記録する。
 *
 * @package etbs-account-guard
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Stub of wp_unslash() for strings. / 文字列に対する wp_unslash() のスタブ。
	 *
	 * @param string $value Value. / 値。
	 * @return string
	 */
	function wp_unslash( $value ) {
		return stripslashes( (string) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Minimal stub of sanitize_text_field(): strips tags and trims, the two parts the code under test relies on.
	 * sanitize_text_field() の最小スタブ。テスト対象が頼る2つ（タグの除去と前後の空白の除去）だけを行う。
	 *
	 * @param string $value Value. / 値。
	 * @return string
	 */
	function sanitize_text_field( $value ) {
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub returning the text unchanged. / 文字列をそのまま返すスタブ。
	 *
	 * @param string $text Text. / 文字列。
	 * @return string
	 */
	function __( $text ) {
		return $text;
	}
}

require_once dirname( __DIR__, 2 ) . '/inc/class-acgd-basic-auth.php';

/**
 * Server authentication mark tests. / サーバー認証の印のテスト。
 */
class BasicServerAuthTest extends TestCase {

	/**
	 * The keys these tests set, removed before and after each test.
	 * テストで設定するキー。各テストの前後で消す。
	 *
	 * @var string[]
	 */
	const KEYS = array( 'AUTH_TYPE', 'REMOTE_USER', 'PHP_AUTH_USER', 'HTTP_AUTHORIZATION' );

	/**
	 * Clears the keys. / キーを消す。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		foreach ( self::KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
	}

	/**
	 * Clears the keys again so other tests are not affected. / 他のテストに影響しないよう、もう一度消す。
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->setUp();
	}

	/**
	 * Cases and the expected result. / ケースと期待する結果。
	 *
	 * @return array
	 */
	public function provide_cases() {
		return array(
			// Marks left by Apache (PHP-FPM) and LiteSpeed. / Apache（PHP-FPM）と LiteSpeed が残す印。
			'Basic'                                 => array(
				array(
					'AUTH_TYPE'   => 'Basic',
					'REMOTE_USER' => 'srvuser',
				),
				true,
			),
			'basic (case-insensitive)'              => array(
				array(
					'AUTH_TYPE'   => 'basic',
					'REMOTE_USER' => 'srvuser',
				),
				true,
			),
			'Digest'                                => array(
				array(
					'AUTH_TYPE'   => 'Digest',
					'REMOTE_USER' => 'srvuser',
				),
				true,
			),
			'whitespace around AUTH_TYPE'           => array(
				array(
					'AUTH_TYPE'   => " Basic\t",
					'REMOTE_USER' => 'srvuser',
				),
				true,
			),
			'REMOTE_USER is "0"'                    => array(
				array(
					'AUTH_TYPE'   => 'Basic',
					'REMOTE_USER' => '0',
				),
				true,
			),
			// Not the mark. / 印ではないもの。
			'empty REMOTE_USER'                     => array(
				array(
					'AUTH_TYPE'   => 'Basic',
					'REMOTE_USER' => '',
				),
				false,
			),
			'whitespace-only REMOTE_USER'           => array(
				array(
					'AUTH_TYPE'   => 'Basic',
					'REMOTE_USER' => '  ',
				),
				false,
			),
			'no REMOTE_USER'                        => array(
				array( 'AUTH_TYPE' => 'Basic' ),
				false,
			),
			'no AUTH_TYPE'                          => array(
				array( 'REMOTE_USER' => 'srvuser' ),
				false,
			),
			// A cookie-based single sign-on fills REMOTE_USER with another AUTH_TYPE (or none).
			// Cookie 型のシングルサインオンは、別の AUTH_TYPE（または無し）で REMOTE_USER を埋める。
			'REMOTE_USER only (cookie-type SSO)'    => array(
				array(
					'AUTH_TYPE'   => 'shibboleth',
					'REMOTE_USER' => 'ssouser',
				),
				false,
			),
			'empty AUTH_TYPE'                       => array(
				array(
					'AUTH_TYPE'   => '',
					'REMOTE_USER' => 'srvuser',
				),
				false,
			),
			// nginx with the default fastcgi_params: credentials arrive but no mark (falls back to the 401).
			// 既定の fastcgi_params の nginx：資格情報は届くが印は無い（401 に落ちる）。
			'nginx (PHP_AUTH_USER only)'            => array(
				array(
					'PHP_AUTH_USER'      => 'srvuser',
					'HTTP_AUTHORIZATION' => 'Basic c3J2dXNlcjpwYXNz',
				),
				false,
			),
			'nothing'                               => array(
				array(),
				false,
			),
		);
	}

	/**
	 * Judges the mark for each case. / ケースごとに印を判定する。
	 *
	 * @dataProvider provide_cases
	 *
	 * @param array $server   $_SERVER values to set. / 設定する $_SERVER の値。
	 * @param bool  $expected Expected result. / 期待する結果。
	 * @return void
	 */
	public function test_has_passed_server_auth( $server, $expected ) {
		// Set the case's values, then judge. / ケースの値を入れてから判定する。
		foreach ( $server as $key => $value ) {
			$_SERVER[ $key ] = $value;
		}

		$this->assertSame( $expected, ACGD_Basic_Auth::has_passed_server_auth() );
	}

	/**
	 * Judging does not change $_SERVER (no side effects). / 判定しても $_SERVER は変わらない（副作用が無い）。
	 *
	 * @return void
	 */
	public function test_has_no_side_effects() {
		$_SERVER['AUTH_TYPE']          = 'Basic';
		$_SERVER['REMOTE_USER']        = 'srvuser';
		$_SERVER['PHP_AUTH_USER']      = 'srvuser';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic c3J2dXNlcjpwYXNz';
		$before                        = $_SERVER;

		ACGD_Basic_Auth::has_passed_server_auth();

		$this->assertSame( $before, $_SERVER );
	}

	/**
	 * The emergency switch is shown on the confirmation screen to manage_options only, never on "Verify".
	 * 非常用スイッチは確認画面で manage_options の人にだけ出し、「確認」の往復では出さない。
	 *
	 * @return void
	 */
	public function test_overlap_shows_emergency_switch() {
		$this->assertTrue( ACGD_Basic_Auth::overlap_shows_emergency_switch( ACGD_Basic_Auth::SCREEN_CHALLENGE, true ) );
		$this->assertFalse( ACGD_Basic_Auth::overlap_shows_emergency_switch( ACGD_Basic_Auth::SCREEN_CHALLENGE, false ) );
		$this->assertFalse( ACGD_Basic_Auth::overlap_shows_emergency_switch( ACGD_Basic_Auth::SCREEN_VERIFY, true ) );
		$this->assertFalse( ACGD_Basic_Auth::overlap_shows_emergency_switch( ACGD_Basic_Auth::SCREEN_VERIFY, false ) );
	}

	/**
	 * The confirmation screen gets "Log out" only. / 確認画面は「ログアウト」だけ。
	 *
	 * @return void
	 */
	public function test_overlap_actions_on_challenge() {
		$actions = ACGD_Basic_Auth::overlap_actions( ACGD_Basic_Auth::SCREEN_CHALLENGE, 'https://example.test/wp-admin/profile.php', 'https://example.test/logout' );

		$this->assertSame(
			array(
				array(
					'url'     => 'https://example.test/logout',
					'label'   => 'Log out',
					'primary' => true,
				),
			),
			$actions
		);
	}

	/**
	 * "Verify" gets "Back to your profile" (primary, to the given URL) and then "Log out".
	 * 「確認」の往復は「プロフィールに戻る」（primary・渡した URL へ）、続いて「ログアウト」。
	 *
	 * @return void
	 */
	public function test_overlap_actions_on_verify() {
		$actions = ACGD_Basic_Auth::overlap_actions( ACGD_Basic_Auth::SCREEN_VERIFY, 'https://example.test/wp-admin/profile.php', 'https://example.test/logout' );

		$this->assertSame(
			array(
				array(
					'url'     => 'https://example.test/wp-admin/profile.php',
					'label'   => 'Back to your profile',
					'primary' => true,
				),
				array(
					'url'     => 'https://example.test/logout',
					'label'   => 'Log out',
					'primary' => false,
				),
			),
			$actions
		);
	}

	/**
	 * "Verify" without a destination falls back to "Log out" only (no empty link).
	 * 戻り先の無い「確認」の往復は「ログアウト」だけになる（空のリンクを出さない）。
	 *
	 * @return void
	 */
	public function test_overlap_actions_on_verify_without_back_url() {
		$actions = ACGD_Basic_Auth::overlap_actions( ACGD_Basic_Auth::SCREEN_VERIFY, '', 'https://example.test/logout' );

		$this->assertCount( 1, $actions );
		$this->assertSame( 'Log out', $actions[0]['label'] );
		$this->assertTrue( $actions[0]['primary'] );
	}
}
