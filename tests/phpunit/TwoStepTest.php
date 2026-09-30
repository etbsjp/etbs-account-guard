<?php
/**
 * Tests of inc/class-acgd-two-step.php (Two-Step Verification, docs/spec.md 7).
 * inc/class-acgd-two-step.php（2段階認証。docs/spec.md 7）のテスト。
 *
 * Only logic with thin dependencies is tested here (see bootstrap.php). The storage helpers are exercised
 * against ACGD_Test_Wpdb, an in-memory stand-in for the few statements this class sends; it proves the PHP
 * side (what is read, compared and written), not MySQL's own atomicity, which is measured on the test site.
 * 依存の薄いロジックだけをここで検査する（bootstrap.php を参照）。行の読み書きは、このクラスが送る
 * 数種類の文だけを再現するメモリ上の代役 ACGD_Test_Wpdb で動かす。確かめられるのは PHP 側（何を読み・
 * 比べ・書くか）で、MySQL 自体の原子性ではない（そちらは検証サイトで測る）。
 *
 * @package etbs-account-guard
 */

use PHPUnit\Framework\TestCase;

/**
 * In-memory stand-in for $wpdb, covering only the statements ACGD_Two_Step sends.
 * ACGD_Two_Step が送る文だけを扱う、$wpdb のメモリ上の代役。
 */
class ACGD_Test_Wpdb {

	/**
	 * Table name. / テーブル名。
	 *
	 * @var string
	 */
	public $options = 'wp_options';

	/**
	 * Stored rows: option_name => option_value. / 保存された行（option_name => option_value）。
	 *
	 * @var string[]
	 */
	public $rows = array();

	/**
	 * Names deleted, in order. / 消した名前（順に）。
	 *
	 * @var string[]
	 */
	public $deleted = array();

	/**
	 * Called right before a compare-and-swap UPDATE runs, to simulate another request writing first.
	 * 値ごと差し替えの UPDATE の直前に呼び、他のリクエストが先に書いた状況を作る。
	 *
	 * @var callable|null
	 */
	public $before_swap = null;

	/**
	 * Keeps the template and the arguments instead of building SQL. / SQL を組み立てず、雛形と引数を持つ。
	 *
	 * @param string $query Template. / 雛形。
	 * @param mixed  ...$args Arguments. / 引数。
	 * @return array Prepared statement. / 用意した文。
	 */
	public function prepare( $query, ...$args ) {
		return array(
			'sql'  => $query,
			'args' => $args,
		);
	}

	/**
	 * Same as core's esc_like(). / 本体の esc_like() と同じ。
	 *
	 * @param string $text Text. / 文字列。
	 * @return string Escaped. / 逃がした文字列。
	 */
	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Runs a write statement. / 書き込みの文を実行する。
	 *
	 * @param array $stmt Prepared statement. / 用意した文。
	 * @return int|false Rows affected, or false. / 影響した行数、または false。
	 */
	public function query( $stmt ) {
		$sql  = $stmt['sql'];
		$args = $stmt['args'];

		if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
			if ( array_key_exists( $args[0], $this->rows ) ) {
				return 0;
			}
			$this->rows[ $args[0] ] = (string) $args[1];
			return 1;
		}
		if ( 0 === strpos( $sql, 'INSERT' ) ) {
			if ( array_key_exists( $args[0], $this->rows ) ) {
				return false; // Duplicate key. / 重複キー。
			}
			$this->rows[ $args[0] ] = (string) $args[1];
			return 1;
		}
		if ( 0 === strpos( $sql, 'DELETE' ) ) {
			$name = $args[0];
			if ( ! array_key_exists( $name, $this->rows ) || ( isset( $args[1] ) && $this->rows[ $name ] !== $args[1] ) ) {
				return 0;
			}
			unset( $this->rows[ $name ] );
			$this->deleted[] = $name;
			return 1;
		}
		if ( false !== strpos( $sql, 'option_value + 1' ) ) {
			$name = $args[0];
			if ( ! array_key_exists( $name, $this->rows ) || (int) $this->rows[ $name ] >= (int) $args[1] ) {
				return 0;
			}
			$this->rows[ $name ] = (string) ( (int) $this->rows[ $name ] + 1 );
			return 1;
		}
		if ( 0 === strpos( $sql, 'UPDATE' ) ) {
			if ( null !== $this->before_swap ) {
				call_user_func( $this->before_swap, $this );
			}
			list( $value, $name, $expected ) = $args;
			if ( ! array_key_exists( $name, $this->rows ) || $this->rows[ $name ] !== $expected ) {
				return 0;
			}
			$this->rows[ $name ] = (string) $value;
			return 1;
		}

		return false;
	}

	/**
	 * Reads one value. / 値を1つ読む。
	 *
	 * @param array $stmt Prepared statement. / 用意した文。
	 * @return string|null Value. / 値。
	 */
	public function get_var( $stmt ) {
		$name = $stmt['args'][0];

		return array_key_exists( $name, $this->rows ) ? $this->rows[ $name ] : null;
	}

	/**
	 * Reads rows whose name starts with the escaped LIKE prefix. / LIKE の接頭辞で始まる行を読む。
	 *
	 * @param array  $stmt   Prepared statement. / 用意した文。
	 * @param string $output Output type (ignored). / 出力の形（無視）。
	 * @return array[] Rows. / 行。
	 */
	public function get_results( $stmt, $output = '' ) {
		unset( $output );
		$prefix = stripcslashes( substr( $stmt['args'][0], 0, -1 ) );
		$rows   = array();
		foreach ( $this->rows as $name => $value ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				$rows[] = array(
					'option_name'  => $name,
					'option_value' => $value,
				);
			}
		}

		return $rows;
	}
}

/**
 * Tests of ACGD_Two_Step. / ACGD_Two_Step のテスト。
 */
class TwoStepTest extends TestCase {

	/**
	 * Gives each test an empty option store and a fresh $wpdb stand-in. / テストごとに空のオプションストアと新しい $wpdb の代役を用意する。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		acgd_test_reset_options();
		$GLOBALS['wpdb'] = new ACGD_Test_Wpdb();
	}

	/**
	 * Tests ACGD_Two_Step::generate_code(): always six ASCII digits (7.5).
	 * ACGD_Two_Step::generate_code() のテスト。常に半角数字6桁（7.5）。
	 *
	 * @return void
	 */
	public function test_generate_code() {
		for ( $i = 0; $i < 500; $i++ ) {
			$this->assertMatchesRegularExpression( '/\A[0-9]{6}\z/', ACGD_Two_Step::generate_code() );
		}
	}

	/**
	 * Tests ACGD_Two_Step::code_hmac(): the exact formula of 7.5, and that every input changes the result.
	 * ACGD_Two_Step::code_hmac() のテスト。7.5 の式どおりで、どの入力が変わっても結果が変わる。
	 *
	 * @return void
	 */
	public function test_code_hmac() {
		$base = ACGD_Two_Step::code_hmac( 7, 'abc', '123456', 'key' );

		$test_cases = array(
			array(
				'test_condition_name' => '仕様の式（acgd-2s|ユーザーID|試行ID|コード）と一致する',
				'actual'              => $base,
				'expected'            => hash_hmac( 'sha256', 'acgd-2s|7|abc|123456', 'key' ),
				'same'                => true,
			),
			array(
				'test_condition_name' => '同じ入力なら同じ値',
				'actual'              => ACGD_Two_Step::code_hmac( 7, 'abc', '123456', 'key' ),
				'expected'            => $base,
				'same'                => true,
			),
			array(
				'test_condition_name' => 'ユーザー ID が違えば別の値（別人の試行のコードは使えない）',
				'actual'              => ACGD_Two_Step::code_hmac( 8, 'abc', '123456', 'key' ),
				'expected'            => $base,
				'same'                => false,
			),
			array(
				'test_condition_name' => '試行 ID が違えば別の値（別の試行のコードは使えない）',
				'actual'              => ACGD_Two_Step::code_hmac( 7, 'abd', '123456', 'key' ),
				'expected'            => $base,
				'same'                => false,
			),
			array(
				'test_condition_name' => 'コードが違えば別の値',
				'actual'              => ACGD_Two_Step::code_hmac( 7, 'abc', '123457', 'key' ),
				'expected'            => $base,
				'same'                => false,
			),
			array(
				'test_condition_name' => '鍵が違えば別の値',
				'actual'              => ACGD_Two_Step::code_hmac( 7, 'abc', '123456', 'other' ),
				'expected'            => $base,
				'same'                => false,
			),
			array(
				'test_condition_name' => '受信確認の HMAC は同じ入力でもログインの HMAC と別の値（用途を分ける接頭辞）',
				'actual'              => ACGD_Two_Step::receive_check_hmac( 7, 'abc', '123456', 'key' ),
				'expected'            => $base,
				'same'                => false,
			),
			array(
				'test_condition_name' => 'パスワードの指紋も別の接頭辞で、ハッシュが変われば別の値',
				'actual'              => ACGD_Two_Step::password_fingerprint( 7, '$2y$10$aaaa', 'key' ),
				'expected'            => ACGD_Two_Step::password_fingerprint( 7, '$2y$10$aaab', 'key' ),
				'same'                => false,
			),
		);

		foreach ( $test_cases as $case ) {
			if ( $case['same'] ) {
				$this->assertSame( $case['expected'], $case['actual'], $case['test_condition_name'] );
			} else {
				$this->assertNotSame( $case['expected'], $case['actual'], $case['test_condition_name'] );
			}
		}
	}

	/**
	 * Tests ACGD_Two_Step::normalize_code_input() (7.5): full-width digits, spaces and hyphens; anything that
	 * is not six digits afterwards is a format error (null), not a wrong code.
	 * ACGD_Two_Step::normalize_code_input() のテスト（7.5）。全角数字・空白・ハイフン。その後6桁にならない
	 * 入力は形式のエラー（null）で、誤りではない。
	 *
	 * @return void
	 */
	public function test_normalize_code_input() {
		$test_cases = array(
			array(
				'test_condition_name' => '半角6桁 => そのまま',
				'input'               => '123456',
				'expected'            => '123456',
			),
			array(
				'test_condition_name' => '全角6桁 => 半角',
				'input'               => '１２３４５６',
				'expected'            => '123456',
			),
			array(
				'test_condition_name' => '全角と半角の混在 => 半角',
				'input'               => '12345６',
				'expected'            => '123456',
			),
			array(
				'test_condition_name' => '前後と途中の半角空白 => 除く',
				'input'               => ' 123 456 ',
				'expected'            => '123456',
			),
			array(
				'test_condition_name' => '全角空白 => 除く',
				'input'               => "123\u{3000}456",
				'expected'            => '123456',
			),
			array(
				'test_condition_name' => 'タブと改行 => 除く',
				'input'               => "123\t456\n",
				'expected'            => '123456',
			),
			array(
				'test_condition_name' => '半角ハイフン => 除く',
				'input'               => '123-456',
				'expected'            => '123456',
			),
			array(
				'test_condition_name' => '全角ハイフン => 除く',
				'input'               => '１２３－４５６',
				'expected'            => '123456',
			),
			array(
				'test_condition_name' => '日本語入力のハイフンキー（長音記号） => 除く',
				'input'               => '123ー456',
				'expected'            => '123456',
			),
			array(
				'test_condition_name' => '5桁 => 形式のエラー',
				'input'               => '12345',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '7桁 => 形式のエラー',
				'input'               => '1234567',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '英字を含む => 形式のエラー',
				'input'               => '12a456',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '空 => 形式のエラー',
				'input'               => '',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '文字列でない（配列） => 形式のエラー',
				'input'               => array( '123456' ),
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ACGD_Two_Step::normalize_code_input( $case['input'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * Tests ACGD_Two_Step::compute_send_slot() (7.5: 5 per hour, 60 seconds apart).
	 * ACGD_Two_Step::compute_send_slot() のテスト（7.5：1時間に5通・60秒あける）。
	 *
	 * @return void
	 */
	public function test_compute_send_slot() {
		$now = 1800000000;

		$test_cases = array(
			array(
				'test_condition_name' => '記録なし => 送れる。新しい窓の1通目',
				'stored'              => null,
				'expected'            => array( true, '', 0, $now . ':1:' . $now ),
			),
			array(
				'test_condition_name' => '空文字（INSERT IGNORE で作った直後） => 送れる。新しい窓の1通目',
				'stored'              => '',
				'expected'            => array( true, '', 0, $now . ':1:' . $now ),
			),
			array(
				'test_condition_name' => '読めない値 => 新しい窓として扱う',
				'stored'              => 'broken',
				'expected'            => array( true, '', 0, $now . ':1:' . $now ),
			),
			array(
				'test_condition_name' => '最後の送信から59秒 => 間隔で送れない。再開は最後+60秒',
				'stored'              => ( $now - 100 ) . ':1:' . ( $now - 59 ),
				'expected'            => array( false, 'gap', $now + 1, '' ),
			),
			array(
				'test_condition_name' => '最後の送信からちょうど60秒 => 送れる',
				'stored'              => ( $now - 100 ) . ':1:' . ( $now - 60 ),
				'expected'            => array( true, '', 0, ( $now - 100 ) . ':2:' . $now ),
			),
			array(
				'test_condition_name' => '窓の中で4通済み => 5通目は送れる',
				'stored'              => ( $now - 1000 ) . ':4:' . ( $now - 100 ),
				'expected'            => array( true, '', 0, ( $now - 1000 ) . ':5:' . $now ),
			),
			array(
				'test_condition_name' => '窓の中で5通済み => 6通目は送れない。再開は窓の開始+1時間',
				'stored'              => ( $now - 1000 ) . ':5:' . ( $now - 100 ),
				'expected'            => array( false, 'window', $now - 1000 + 3600, '' ),
			),
			array(
				'test_condition_name' => '窓の開始からちょうど1時間 => 新しい窓で送れる',
				'stored'              => ( $now - 3600 ) . ':5:' . ( $now - 100 ),
				'expected'            => array( true, '', 0, $now . ':1:' . $now ),
			),
			array(
				'test_condition_name' => '窓は切り替わるが最後の送信から60秒未満 => 間隔を先に見て送れない',
				'stored'              => ( $now - 3600 ) . ':5:' . ( $now - 30 ),
				'expected'            => array( false, 'gap', $now + 30, '' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$slot = ACGD_Two_Step::compute_send_slot( $case['stored'], $now );
			$this->assertSame( $case['expected'], array( $slot['ok'], $slot['reason'], $slot['retry_at'], $slot['value'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * Tests ACGD_Two_Step::parse_settings() (7.1, 7.11): missing is normal, broken is detected.
	 * ACGD_Two_Step::parse_settings() のテスト（7.1・7.11）。無いのは正常、壊れていれば検出する。
	 *
	 * @return void
	 */
	public function test_parse_settings() {
		$test_cases = array(
			array(
				'test_condition_name' => 'option が無い（既存サイトの更新直後） => 全権限なし・壊れていない',
				'raw'                 => null,
				'expected'            => array( array(), 30, false ),
			),
			array(
				'test_condition_name' => '空の配列（初回保存が拒否されたとき） => 全権限なし・壊れていない',
				'raw'                 => array(),
				'expected'            => array( array(), 30, false ),
			),
			array(
				'test_condition_name' => '正しい値 => そのまま',
				'raw'                 => array(
					'roles'      => array(
						'administrator' => 'email',
						'editor'        => 'none',
					),
					'trust_days' => 7,
				),
				'expected'            => array(
					array(
						'administrator' => 'email',
						'editor'        => 'none',
					),
					7,
					false,
				),
			),
			array(
				'test_condition_name' => '配列でない => 壊れている',
				'raw'                 => 'email',
				'expected'            => array( array(), 30, true ),
			),
			array(
				'test_condition_name' => 'roles が配列でない => 壊れている',
				'raw'                 => array( 'roles' => 'email' ),
				'expected'            => array( array(), 30, true ),
			),
			array(
				'test_condition_name' => '想定外の方式 => 壊れている（正しい部分は残す）',
				'raw'                 => array(
					'roles' => array(
						'administrator' => 'email',
						'editor'        => 'totp',
					),
				),
				'expected'            => array( array( 'administrator' => 'email' ), 30, true ),
			),
			array(
				'test_condition_name' => '想定外の日数 => 壊れている',
				'raw'                 => array( 'trust_days' => 5 ),
				'expected'            => array( array(), 30, true ),
			),
		);

		foreach ( $test_cases as $case ) {
			$parsed = ACGD_Two_Step::parse_settings( $case['raw'] );
			$this->assertSame( $case['expected'], array( $parsed['roles'], $parsed['trust_days'], $parsed['broken'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * Tests ACGD_Two_Step::compute_is_target() (7.1, 7.11), fed from parse_settings() as the class does.
	 * ACGD_Two_Step::compute_is_target() のテスト（7.1・7.11）。クラスと同じく parse_settings() の結果を渡す。
	 *
	 * @return void
	 */
	public function test_compute_is_target() {
		$roles_admin_email = array(
			'roles' => array(
				'administrator' => 'email',
				'editor'        => 'none',
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'option が無い => 管理者でも対象でない（既定オフ）',
				'raw'                 => null,
				'user_roles'          => array( 'administrator' ),
				'user_method'         => 'follow',
				'can_manage'          => true,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '権限が email・ユーザーは権限に従う => 対象',
				'raw'                 => $roles_admin_email,
				'user_roles'          => array( 'administrator' ),
				'user_method'         => 'follow',
				'can_manage'          => true,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '権限が email でもユーザー単位の「なし」が勝つ',
				'raw'                 => $roles_admin_email,
				'user_roles'          => array( 'administrator' ),
				'user_method'         => 'none',
				'can_manage'          => true,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '権限が「なし」でもユーザー単位の email が勝つ',
				'raw'                 => $roles_admin_email,
				'user_roles'          => array( 'editor' ),
				'user_method'         => 'email',
				'can_manage'          => false,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '複数の権限：どれか1つが email なら対象',
				'raw'                 => $roles_admin_email,
				'user_roles'          => array( 'editor', 'administrator' ),
				'user_method'         => 'follow',
				'can_manage'          => true,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '複数の権限：どれも email でなければ対象でない',
				'raw'                 => $roles_admin_email,
				'user_roles'          => array( 'editor', 'author' ),
				'user_method'         => 'follow',
				'can_manage'          => false,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '壊れた option：manage_options を持つ人は対象',
				'raw'                 => 'broken',
				'user_roles'          => array( 'administrator' ),
				'user_method'         => 'follow',
				'can_manage'          => true,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '壊れた option：ユーザー単位で email の人は対象',
				'raw'                 => 'broken',
				'user_roles'          => array( 'subscriber' ),
				'user_method'         => 'email',
				'can_manage'          => false,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '壊れた option：それ以外（顧客など）は対象でない（全員は閉じない）',
				'raw'                 => array( 'roles' => array( 'customer' => 'bogus' ) ),
				'user_roles'          => array( 'customer' ),
				'user_method'         => 'follow',
				'can_manage'          => false,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '壊れた option：manage_options を持つ人はユーザー単位で「なし」でも対象（狭い規則）',
				'raw'                 => 'broken',
				'user_roles'          => array( 'administrator' ),
				'user_method'         => 'none',
				'can_manage'          => true,
				'expected'            => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$parsed = ACGD_Two_Step::parse_settings( $case['raw'] );
			$actual = ACGD_Two_Step::compute_is_target( $case['user_roles'], $case['user_method'], $parsed['roles'], $parsed['broken'], $case['can_manage'] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * Tests ACGD_Two_Step::classify_request() (7.4-2-7, 7.4-2-8).
	 * ACGD_Two_Step::classify_request() のテスト（7.4-2-7・7.4-2-8）。
	 *
	 * @return void
	 */
	public function test_classify_request() {
		$form = array(
			'signon_login' => 'alice',
			'username'     => 'alice',
			'method'       => 'POST',
			'ajax'         => false,
			'json'         => false,
			'rest'         => false,
			'xmlrpc'       => false,
			'cron'         => false,
			'cli'          => false,
			'headers_sent' => false,
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'wp_signon() 経由の POST のフォーム => 画面遷移できる',
				'context'             => $form,
				'expected'            => 'interactive',
			),
			array(
				'test_condition_name' => 'wp_signon() を通っていない（wp_authenticate() の直接呼び出し） => それ以外',
				'context'             => array_merge( $form, array( 'signon_login' => null ) ),
				'expected'            => 'noninteractive',
			),
			array(
				'test_condition_name' => 'wp_signon() は別の名前で呼ばれていた => それ以外',
				'context'             => array_merge( $form, array( 'signon_login' => 'bob' ) ),
				'expected'            => 'noninteractive',
			),
			array(
				'test_condition_name' => 'GET => それ以外',
				'context'             => array_merge( $form, array( 'method' => 'GET' ) ),
				'expected'            => 'noninteractive',
			),
			array(
				'test_condition_name' => 'AJAX から wp_signon() => それ以外（JSON 側に共通のエラー）',
				'context'             => array_merge( $form, array( 'ajax' => true ) ),
				'expected'            => 'noninteractive',
			),
			array(
				'test_condition_name' => 'JSON のリクエスト => それ以外',
				'context'             => array_merge( $form, array( 'json' => true ) ),
				'expected'            => 'noninteractive',
			),
			array(
				'test_condition_name' => 'REST => それ以外',
				'context'             => array_merge( $form, array( 'rest' => true ) ),
				'expected'            => 'noninteractive',
			),
			array(
				'test_condition_name' => 'cron => それ以外',
				'context'             => array_merge( $form, array( 'cron' => true ) ),
				'expected'            => 'noninteractive',
			),
			array(
				'test_condition_name' => 'WP-CLI => それ以外',
				'context'             => array_merge( $form, array( 'cli' => true ) ),
				'expected'            => 'noninteractive',
			),
			array(
				'test_condition_name' => 'ヘッダー送信済み => それ以外',
				'context'             => array_merge( $form, array( 'headers_sent' => true ) ),
				'expected'            => 'noninteractive',
			),
			array(
				'test_condition_name' => 'XML-RPC => xmlrpc（他の条件より先に判定）',
				'context'             => array_merge( $form, array( 'xmlrpc' => true ) ),
				'expected'            => 'xmlrpc',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ACGD_Two_Step::classify_request( $case['context'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * Tests ACGD_Two_Step::mask_email() and ACGD_Two_Step::email_hash().
	 * ACGD_Two_Step::mask_email() と ACGD_Two_Step::email_hash() のテスト。
	 *
	 * @return void
	 */
	public function test_mask_email() {
		$test_cases = array(
			array(
				'test_condition_name' => '通常のアドレス => 先頭1文字と @ 以降',
				'input'               => 'taro@example.com',
				'expected'            => 't•••@example.com',
			),
			array(
				'test_condition_name' => '@ が無い => 全部伏せる',
				'input'               => 'nothing',
				'expected'            => '•••',
			),
			array(
				'test_condition_name' => '@ で始まる => 全部伏せる',
				'input'               => '@example.com',
				'expected'            => '•••',
			),
		);
		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ACGD_Two_Step::mask_email( $case['input'] ), $case['test_condition_name'] );
		}

		$this->assertSame( ACGD_Two_Step::email_hash( 'Taro@Example.com ' ), ACGD_Two_Step::email_hash( 'taro@example.com' ), 'email_hash：大文字小文字と前後の空白は区別しない' );
		$this->assertNotSame( ACGD_Two_Step::email_hash( 'taro@example.com' ), ACGD_Two_Step::email_hash( 'jiro@example.com' ), 'email_hash：別のアドレスは別の値' );
	}

	/**
	 * Tests ACGD_Two_Step::is_password_rehash() (7.8: core re-hashing the same password is not a change).
	 * ACGD_Two_Step::is_password_rehash() のテスト（7.8：本体が同じパスワードでハッシュを作り直すのは変更ではない）。
	 *
	 * @return void
	 */
	public function test_is_password_rehash() {
		$old            = new stdClass();
		$old->ID        = 3;
		$old->user_pass = password_hash( 'same-password', PASSWORD_BCRYPT );

		$test_cases = array(
			array(
				'test_condition_name' => '第3引数が無い（WP 6.7 より前） => 本物の変更',
				'password'            => 'same-password',
				'old'                 => null,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '変更前と同じパスワード（ログイン時の作り直し） => 作り直し',
				'password'            => 'same-password',
				'old'                 => $old,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '変更前と違うパスワード => 本物の変更',
				'password'            => 'new-password',
				'old'                 => $old,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'user_pass を持たないオブジェクト => 本物の変更',
				'password'            => 'same-password',
				'old'                 => new stdClass(),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ACGD_Two_Step::is_password_rehash( $case['password'], $case['old'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * Tests ACGD_Two_Step::on_wp_set_password() with one, two and three arguments (7.8: the action passes two
	 * arguments before WordPress 6.7 and three from 6.7; neither may be a fatal error), and what it clears.
	 * ACGD_Two_Step::on_wp_set_password() を引数1つ・2つ・3つで呼ぶテスト（7.8：このアクションは WordPress 6.7
	 * より前は2引数、6.7 から3引数。どちらでも Fatal にならないこと）と、何を消すか。
	 *
	 * @return void
	 */
	public function test_on_wp_set_password() {
		$old            = new stdClass();
		$old->ID        = 5;
		$old->user_pass = password_hash( 'kept', PASSWORD_BCRYPT );

		$attempt_hash = str_repeat( 'a', 64 );
		$other_hash   = str_repeat( 'b', 64 );

		$test_cases = array(
			array(
				'test_condition_name' => '引数1つ => Fatal にならず、何も消さない（ユーザーが分からない）',
				'args'                => array( 'new' ),
				'expected_deleted'    => array(),
			),
			array(
				'test_condition_name' => '引数2つ（WP 6.2〜6.6） => その人の送信の記録と試行を消す（他人の試行は残す）',
				'args'                => array( 'new', 5 ),
				'expected_deleted'    => array( 'acgd_2s_send_5', 'acgd_2s_' . $attempt_hash, 'acgd_2s_n_' . $attempt_hash ),
			),
			array(
				'test_condition_name' => '引数3つ・本物の変更 => 消す',
				'args'                => array( 'new', 5, $old ),
				'expected_deleted'    => array( 'acgd_2s_send_5', 'acgd_2s_' . $attempt_hash, 'acgd_2s_n_' . $attempt_hash ),
			),
			array(
				'test_condition_name' => '引数3つ・ログイン時の作り直し（同じパスワード） => 消さない',
				'args'                => array( 'kept', 5, $old ),
				'expected_deleted'    => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$wpdb       = new ACGD_Test_Wpdb();
			$wpdb->rows = array(
				'acgd_2s_send_5'             => '1800000000:1:1800000000',
				'acgd_2s_' . $attempt_hash   => '{"uid":5,"exp":1}',
				'acgd_2s_n_' . $attempt_hash => '0',
				'acgd_2s_' . $other_hash     => '{"uid":6,"exp":1}',
				'acgd_2s_n_' . $other_hash   => '0',
			);
			$GLOBALS['wpdb'] = $wpdb;

			call_user_func_array( array( 'ACGD_Two_Step', 'on_wp_set_password' ), $case['args'] );

			$this->assertSame( $case['expected_deleted'], $wpdb->deleted, $case['test_condition_name'] );
			$this->assertArrayHasKey( 'acgd_2s_' . $other_hash, $wpdb->rows, $case['test_condition_name'] . '（他人の試行は残る）' );
		}
	}

	/**
	 * Tests ACGD_Two_Step::reserve_send_slot() (7.5): the value is swapped only while it still holds what was
	 * read; losing the race re-reads, and losing every time counts as the limit.
	 * ACGD_Two_Step::reserve_send_slot() のテスト（7.5）。読んだ値のままのときだけ差し替え、先を越されたら
	 * 読み直し、毎回先を越されたら上限扱い。
	 *
	 * @return void
	 */
	public function test_reserve_send_slot() {
		$start = time() - 1000;

		$test_cases = array(
			array(
				'test_condition_name' => '記録なし => 行を作って1通目を確保',
				'rows'                => array(),
				'swaps_to_lose'       => 0,
				'expected_ok'         => true,
				'expected_value'      => '/\A[0-9]+:1:[0-9]+\z/',
			),
			array(
				'test_condition_name' => '1回だけ先を越される（他のリクエストが2通目を取った） => 読み直して3通目を確保',
				'rows'                => array( 'acgd_2s_send_9' => $start . ':1:' . ( $start + 1 ) ),
				'swaps_to_lose'       => 1,
				'expected_ok'         => true,
				'expected_value'      => '/\A' . $start . ':3:[0-9]+\z/',
			),
			array(
				'test_condition_name' => '毎回先を越される => 上限扱いで確保しない',
				'rows'                => array( 'acgd_2s_send_9' => $start . ':1:' . ( $start + 1 ) ),
				'swaps_to_lose'       => 99,
				'expected_ok'         => false,
				'expected_value'      => null,
			),
			array(
				'test_condition_name' => '窓の中で5通済み => 確保しない（行は変えない）',
				'rows'                => array( 'acgd_2s_send_9' => $start . ':5:' . ( $start + 1 ) ),
				'swaps_to_lose'       => 0,
				'expected_ok'         => false,
				'expected_value'      => '/\A' . $start . ':5:' . ( $start + 1 ) . '\z/',
			),
		);

		foreach ( $test_cases as $case ) {
			$wpdb       = new ACGD_Test_Wpdb();
			$wpdb->rows = $case['rows'];
			$lost       = 0;
			$to_lose    = $case['swaps_to_lose'];
			$counter    = 1;
			// Another request writes a new value right before each of our swaps, $to_lose times.
			// 自分の差し替えの直前に、他のリクエストが新しい値を書く（$to_lose 回）。
			$wpdb->before_swap = function ( $db ) use ( &$lost, $to_lose, $start, &$counter ) {
				if ( $lost < $to_lose ) {
					++$lost;
					++$counter;
					$db->rows['acgd_2s_send_9'] = $start . ':' . $counter . ':' . ( $start + 1 + $lost );
				}
			};
			$GLOBALS['wpdb'] = $wpdb;

			$slot = ACGD_Two_Step::reserve_send_slot( 9 );

			$this->assertSame( $case['expected_ok'], $slot['ok'], $case['test_condition_name'] );
			if ( null !== $case['expected_value'] ) {
				$this->assertMatchesRegularExpression( $case['expected_value'], $wpdb->rows['acgd_2s_send_9'], $case['test_condition_name'] );
			}
		}
	}
}
