<?php
/**
 * Tests of the receive diagnosis in inc/class-acgd-basic-auth.php (docs/spec.md 5.3, issue #31).
 * inc/class-acgd-basic-auth.php の受信の診断（docs/spec.md 5.3、issue #31）のテスト。
 *
 * The HTTP requests are replaced by the wp_remote_get() stub below, which answers per URL and records every
 * request it receives, so what is sent where (and what is not sent at all) can be checked. The real requests
 * are measured on the test site and recorded in the PR.
 * HTTP リクエストは下の wp_remote_get() スタブで置き換える。URL ごとに応答を決め、受け取った要求をすべて
 * 記録するので、どこへ何を送ったか（送らなかったか）を確かめられる。実際の要求は検証サイトで測り、PR に記録する。
 *
 * @package etbs-account-guard
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error. / 最小限の WP_Error。
	 */
	class WP_Error {
		/**
		 * Message. / メッセージ。
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Constructor. / コンストラクタ。
		 *
		 * @param string $code    Code (unused). / コード（使わない）。
		 * @param string $message Message. / メッセージ。
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->message = $message;
		}

		/**
		 * Returns the message. / メッセージを返す。
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stub. / スタブ。
	 *
	 * @param mixed $thing Value. / 値。
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub. / スタブ。
	 *
	 * @param string $text Text. / 文字列。
	 * @return string
	 */
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub returning the value unchanged. / 値をそのまま返すスタブ。
	 *
	 * @param string $hook  Hook name. / フック名。
	 * @param mixed  $value Value. / 値。
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Stub. / スタブ。
	 *
	 * @param string $path Path. / パス。
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( 'wp_login_url' ) ) {
	/**
	 * Stub; the URL can be changed like the login_url filter would. / スタブ。login_url フィルターのように URL を変えられる。
	 *
	 * @return string
	 */
	function wp_login_url() {
		return isset( $GLOBALS['acgd_test_login_url'] ) ? $GLOBALS['acgd_test_login_url'] : 'https://example.test/wp-login.php';
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Stub. / スタブ。
	 *
	 * @return string
	 */
	function admin_url() {
		return 'https://example.test/wp-admin/';
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Stub for the array form only. / 配列の形だけのスタブ。
	 *
	 * @param array  $args Query args. / クエリ引数。
	 * @param string $url  URL.
	 * @return string
	 */
	function add_query_arg( $args, $url ) {
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Stub recording the transient. / transient を記録するスタブ。
	 *
	 * @param string $key   Key. / キー。
	 * @param mixed  $value Value. / 値。
	 * @return bool
	 */
	function set_transient( $key, $value ) {
		$GLOBALS['acgd_test_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Stub. / スタブ。
	 *
	 * @param string $key Key. / キー。
	 * @return bool
	 */
	function delete_transient( $key ) {
		unset( $GLOBALS['acgd_test_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Stub answering per URL (the part before '?') and recording each request.
	 * URL（'?' より前）ごとに応答し、要求を記録するスタブ。
	 *
	 * @param string $url  URL.
	 * @param array  $args Arguments. / 引数。
	 * @return array|WP_Error
	 */
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['acgd_test_requests'][] = array(
			'url'  => $url,
			'args' => $args,
		);
		$key = strtok( $url, '?' );
		if ( isset( $GLOBALS['acgd_test_responses'][ $key ] ) ) {
			return $GLOBALS['acgd_test_responses'][ $key ];
		}
		return new WP_Error( 'http_request_failed', 'no stubbed response for ' . $key );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Stub. / スタブ。
	 *
	 * @param array $response Response. / 応答。
	 * @return int|string
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? $response['response']['code'] : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	/**
	 * Stub; header names are case-insensitive, as in WordPress. / スタブ。WordPress と同じくヘッダー名の大文字小文字を区別しない。
	 *
	 * @param array  $response Response. / 応答。
	 * @param string $header   Header name. / ヘッダー名。
	 * @return string|array
	 */
	function wp_remote_retrieve_header( $response, $header ) {
		if ( empty( $response['headers'] ) ) {
			return '';
		}
		$headers = array_change_key_case( $response['headers'], CASE_LOWER );
		$header  = strtolower( $header );
		return isset( $headers[ $header ] ) ? $headers[ $header ] : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Stub. / スタブ。
	 *
	 * @param array $response Response. / 応答。
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? $response['body'] : '';
	}
}

require_once dirname( __DIR__, 2 ) . '/inc/class-acgd-basic-auth.php';

/**
 * Receive diagnosis tests. / 受信の診断のテスト。
 */
class BasicDiagnosisTest extends TestCase {

	const LOGIN = 'https://example.test/wp-login.php';
	const ADMIN = 'https://example.test/wp-admin/';
	const HOME  = 'https://example.test/';

	/**
	 * Resets the stubs. / スタブを初期化する。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['acgd_test_requests']   = array();
		$GLOBALS['acgd_test_responses']  = array();
		$GLOBALS['acgd_test_transients'] = array();
		unset( $GLOBALS['acgd_test_login_url'] );
		acgd_test_reset_options();
	}

	/**
	 * Builds a response. / 応答を組み立てる。
	 *
	 * @param int    $code    Status. / 状態コード。
	 * @param array  $headers Headers. / ヘッダー。
	 * @param string $body    Body. / 本文。
	 * @return array
	 */
	private static function response( $code, $headers = array(), $body = '' ) {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => $headers,
			'body'     => $body,
		);
	}

	/**
	 * Calls the private run_diagnosis(). / private の run_diagnosis() を呼ぶ。
	 *
	 * @return array
	 */
	private static function run_diagnosis() {
		$method = new ReflectionMethod( 'ACGD_Basic_Auth', 'run_diagnosis' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	/**
	 * URLs requested, without the query. / 要求した URL（クエリを除く）。
	 *
	 * @return string[]
	 */
	private static function requested() {
		return array_map(
			function ( $r ) {
				return strtok( $r['url'], '?' );
			},
			$GLOBALS['acgd_test_requests']
		);
	}

	/**
	 * Login 200, admin 302, front page answering the probe. / ログイン 200・管理画面 302・トップは診断に応答。
	 *
	 * @param bool $received Whether the probe reports the header received. / 診断がヘッダーを受け取ったと答えるか。
	 * @return void
	 */
	private static function stub_clean_site( $received = true ) {
		$GLOBALS['acgd_test_responses'][ self::LOGIN ] = self::response( 200 );
		$GLOBALS['acgd_test_responses'][ self::ADMIN ] = self::response( 302, array( 'Location' => self::LOGIN ) );
		$GLOBALS['acgd_test_responses'][ self::HOME ]  = self::response( 200, array(), wp_json_encode_stub( array( 'received' => $received ) ) );
	}

	/* ---- server_challenge_of() ---- */

	public function test_challenge_basic() {
		$this->assertSame( 'Basic realm="x"', ACGD_Basic_Auth::server_challenge_of( self::response( 401, array( 'WWW-Authenticate' => 'Basic realm="x"' ) ) ) );
	}

	public function test_challenge_digest_counts_too() {
		$this->assertSame( 'Digest realm="x"', ACGD_Basic_Auth::server_challenge_of( self::response( 401, array( 'www-authenticate' => 'Digest realm="x"' ) ) ) );
	}

	public function test_challenge_without_header_is_empty() {
		$this->assertSame( '', ACGD_Basic_Auth::server_challenge_of( self::response( 401 ) ) );
	}

	public function test_challenge_blank_header_is_empty() {
		$this->assertSame( '', ACGD_Basic_Auth::server_challenge_of( self::response( 401, array( 'WWW-Authenticate' => '   ' ) ) ) );
	}

	public function test_challenge_multiple_headers_are_joined() {
		$this->assertSame(
			'Basic realm="a", Digest realm="b"',
			ACGD_Basic_Auth::server_challenge_of( self::response( 401, array( 'WWW-Authenticate' => array( 'Basic realm="a"', 'Digest realm="b"' ) ) ) )
		);
	}

	public function test_challenge_ignored_unless_401() {
		foreach ( array( 200, 302, 403, 404, 500 ) as $code ) {
			$this->assertSame( '', ACGD_Basic_Auth::server_challenge_of( self::response( $code, array( 'WWW-Authenticate' => 'Basic realm="x"' ) ) ), (string) $code );
		}
	}

	/* ---- run_diagnosis() ---- */

	public function test_login_only_server_basic_is_detected() {
		self::stub_clean_site();
		$GLOBALS['acgd_test_responses'][ self::LOGIN ] = self::response( 401, array( 'WWW-Authenticate' => 'Basic realm="server"' ) );

		$result = self::run_diagnosis();

		$this->assertSame( 'server_basic_auth', $result['status'] );
		$this->assertSame( self::LOGIN . ': Basic realm="server"', $result['detail'] );
		$this->assertSame( array( self::LOGIN ), self::requested(), 'Stops at the first challenge: no admin or front page request.' );
		$this->assertSame( array(), $GLOBALS['acgd_test_transients'], 'No probe token is created.' );
	}

	public function test_admin_only_server_basic_is_detected() {
		self::stub_clean_site();
		$GLOBALS['acgd_test_responses'][ self::ADMIN ] = self::response( 401, array( 'WWW-Authenticate' => 'Basic realm="server"' ) );

		$result = self::run_diagnosis();

		$this->assertSame( 'server_basic_auth', $result['status'] );
		$this->assertSame( self::ADMIN . ': Basic realm="server"', $result['detail'] );
		$this->assertSame( array( self::LOGIN, self::ADMIN ), self::requested() );
	}

	public function test_clean_site_succeeds_with_status_codes_in_detail() {
		self::stub_clean_site( true );

		$result = self::run_diagnosis();

		$this->assertSame( 'success', $result['status'] );
		$this->assertSame( self::LOGIN . ': 200; ' . self::ADMIN . ': 302', $result['detail'] );
		$this->assertSame( array( self::LOGIN, self::ADMIN, self::HOME ), self::requested() );
	}

	public function test_clean_site_not_received() {
		self::stub_clean_site( false );

		$result = self::run_diagnosis();

		$this->assertSame( 'not_received', $result['status'] );
		$this->assertSame( self::LOGIN . ': 200; ' . self::ADMIN . ': 302', $result['detail'] );
	}

	public function test_login_request_error_is_unknown_and_stops() {
		self::stub_clean_site();
		$GLOBALS['acgd_test_responses'][ self::LOGIN ] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$result = self::run_diagnosis();

		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( self::LOGIN . ': cURL error 28: Operation timed out', $result['detail'] );
		$this->assertSame( array( self::LOGIN ), self::requested() );
	}

	public function test_admin_request_error_is_unknown() {
		self::stub_clean_site();
		$GLOBALS['acgd_test_responses'][ self::ADMIN ] = new WP_Error( 'http_request_failed', 'timeout' );

		$result = self::run_diagnosis();

		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( self::ADMIN . ': timeout', $result['detail'] );
		$this->assertSame( array( self::LOGIN, self::ADMIN ), self::requested() );
	}

	public function test_front_page_server_basic_still_detected() {
		self::stub_clean_site();
		$GLOBALS['acgd_test_responses'][ self::HOME ] = self::response( 401, array( 'WWW-Authenticate' => 'Basic realm="site"' ) );

		$result = self::run_diagnosis();

		$this->assertSame( 'server_basic_auth', $result['status'] );
		$this->assertSame( self::HOME . ': Basic realm="site"', $result['detail'] );
	}

	public function test_non_401_answers_pass_through() {
		foreach ( array( 403, 404 ) as $code ) {
			$this->setUp();
			self::stub_clean_site();
			$GLOBALS['acgd_test_responses'][ self::LOGIN ] = self::response( $code );
			$GLOBALS['acgd_test_responses'][ self::ADMIN ] = self::response( $code );

			$this->assertSame( 'success', self::run_diagnosis()['status'], (string) $code );
		}
	}

	public function test_401_without_challenge_passes_through() {
		self::stub_clean_site();
		$GLOBALS['acgd_test_responses'][ self::LOGIN ] = self::response( 401 );
		$GLOBALS['acgd_test_responses'][ self::ADMIN ] = self::response( 401 );

		$this->assertSame( 'success', self::run_diagnosis()['status'] );
	}

	public function test_request_arguments() {
		self::stub_clean_site();
		self::run_diagnosis();

		$requests = $GLOBALS['acgd_test_requests'];
		foreach ( array( 0, 1 ) as $i ) {
			$this->assertArrayNotHasKey( 'headers', $requests[ $i ]['args'], 'No Authorization on the server checks.' );
			$this->assertSame( 0, $requests[ $i ]['args']['redirection'] );
			$this->assertSame( 5, $requests[ $i ]['args']['timeout'] );
		}
		$this->assertStringStartsWith( 'Basic ', $requests[2]['args']['headers']['Authorization'], 'The front page probe still carries the dummy credentials.' );
	}

	public function test_renamed_login_url_is_checked() {
		$GLOBALS['acgd_test_login_url'] = 'https://example.test/login-renamed/';
		self::stub_clean_site();
		$GLOBALS['acgd_test_responses']['https://example.test/login-renamed/'] = self::response( 401, array( 'WWW-Authenticate' => 'Basic realm="server"' ) );

		$result = self::run_diagnosis();

		$this->assertSame( 'server_basic_auth', $result['status'] );
		$this->assertSame( array( 'https://example.test/login-renamed/' ), self::requested() );
	}

	public function test_old_saved_success_still_allows_basic_mode() {
		// Decision for issue #31: results saved before the update are kept (the changelog asks to run it again).
		// issue #31 の決定：更新前に保存された結果は残す（変更履歴で実行し直しを案内する）。
		acgd_test_set_option(
			ACGD_Basic_Auth::DIAG_RESULT_OPTION,
			array(
				'status'     => 'success',
				'checked_at' => 1700000000,
				'detail'     => '',
			)
		);
		$this->assertTrue( ACGD_Basic_Auth::diagnosis_allows_basic_mode() );

		acgd_test_set_option(
			ACGD_Basic_Auth::DIAG_RESULT_OPTION,
			array(
				'status'     => 'server_basic_auth',
				'checked_at' => 1700000000,
				'detail'     => self::LOGIN . ': Basic realm="server"',
			)
		);
		$this->assertFalse( ACGD_Basic_Auth::diagnosis_allows_basic_mode() );
	}
}

/**
 * JSON encoder for the stubbed probe body. / スタブの診断応答の本文用の JSON エンコーダー。
 *
 * @param mixed $data Data. / データ。
 * @return string
 */
function wp_json_encode_stub( $data ) {
	return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stub. / テスト用のスタブ。
}
