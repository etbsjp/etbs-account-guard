<?php
/**
 * Tests of inc/class-acgd-time.php (showing stored times in the site's time zone).
 * inc/class-acgd-time.php（保存した時刻をサイトのタイムゾーンで表示する）のテスト。
 *
 * @package etbs-account-guard
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests of ACGD_Time. / ACGD_Time のテスト。
 */
class TimeTest extends TestCase {

	/**
	 * Gives each test an empty option store. / テストごとにオプションストアを空にする。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		acgd_test_reset_options();
	}

	/**
	 * Tests ACGD_Time::offset_at(). / ACGD_Time::offset_at() のテスト。
	 *
	 * America/New_York switched in 2026 at 2026-03-08 07:00:00 UTC (to UTC-4) and 2026-11-01 06:00:00 UTC (to UTC-5).
	 * America/New_York の 2026 年の切り替えは 2026-03-08 07:00:00 UTC（UTC-4 へ）と 2026-11-01 06:00:00 UTC（UTC-5 へ）。
	 *
	 * @return void
	 */
	public function test_offset_at() {
		$test_cases = array(
			array(
				'test_condition_name' => 'Asia/Tokyo の場合 => +9時間',
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'timezone_string'     => 'Asia/Tokyo',
				'gmt_offset'          => 9,
				'expected'            => 32400,
			),
			array(
				'test_condition_name' => 'America/New_York の夏（夏時間中）の場合 => -4時間',
				'timestamp'           => gmmktime( 12, 0, 0, 7, 1, 2026 ),
				'timezone_string'     => 'America/New_York',
				'gmt_offset'          => -5,
				'expected'            => -14400,
			),
			array(
				'test_condition_name' => 'America/New_York の冬の場合 => -5時間（gmt_offset が「いま」の -4 でも、その時点の値を使う）',
				'timestamp'           => gmmktime( 12, 0, 0, 1, 15, 2026 ),
				'timezone_string'     => 'America/New_York',
				'gmt_offset'          => -4,
				'expected'            => -18000,
			),
			array(
				'test_condition_name' => '夏時間が始まる1秒前（2026-03-08 06:59:59 UTC）の場合 => -5時間',
				'timestamp'           => gmmktime( 6, 59, 59, 3, 8, 2026 ),
				'timezone_string'     => 'America/New_York',
				'gmt_offset'          => -4,
				'expected'            => -18000,
			),
			array(
				'test_condition_name' => '夏時間が始まった瞬間（2026-03-08 07:00:00 UTC）の場合 => -4時間',
				'timestamp'           => gmmktime( 7, 0, 0, 3, 8, 2026 ),
				'timezone_string'     => 'America/New_York',
				'gmt_offset'          => -5,
				'expected'            => -14400,
			),
			array(
				'test_condition_name' => '夏時間が終わる1秒前（2026-11-01 05:59:59 UTC）の場合 => -4時間',
				'timestamp'           => gmmktime( 5, 59, 59, 11, 1, 2026 ),
				'timezone_string'     => 'America/New_York',
				'gmt_offset'          => -5,
				'expected'            => -14400,
			),
			array(
				'test_condition_name' => '夏時間が終わった瞬間（2026-11-01 06:00:00 UTC）の場合 => -5時間',
				'timestamp'           => gmmktime( 6, 0, 0, 11, 1, 2026 ),
				'timezone_string'     => 'America/New_York',
				'gmt_offset'          => -4,
				'expected'            => -18000,
			),
			array(
				'test_condition_name' => '地域名と gmt_offset が食い違う場合 => 地域名を優先する',
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'timezone_string'     => 'Asia/Tokyo',
				'gmt_offset'          => 0,
				'expected'            => 32400,
			),
			array(
				'test_condition_name' => '地域名が UTC の場合 => 0',
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'timezone_string'     => 'UTC',
				'gmt_offset'          => 0,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '手動のオフセット「UTC+9」（地域名が空、gmt_offset は文字列で保存）の場合 => +9時間',
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'timezone_string'     => '',
				'gmt_offset'          => '9',
				'expected'            => 32400,
			),
			array(
				'test_condition_name' => '手動のオフセット「UTC+5:30」の場合 => +5時間30分',
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'timezone_string'     => '',
				'gmt_offset'          => '5.5',
				'expected'            => 19800,
			),
			array(
				'test_condition_name' => '手動のオフセット「UTC+5:45」の場合 => +5時間45分',
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'timezone_string'     => '',
				'gmt_offset'          => '5.75',
				'expected'            => 20700,
			),
			array(
				'test_condition_name' => '手動のオフセット「UTC-3:30」の場合 => -3時間30分',
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'timezone_string'     => '',
				'gmt_offset'          => '-3.5',
				'expected'            => -12600,
			),
			array(
				'test_condition_name' => '地域名が未知の名前の場合 => gmt_offset に任せる（offset_at() 単体の話。表示は 5.3 以降の date_i18n() 側で例外になり、守れない）',
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'timezone_string'     => 'Invalid/Zone',
				'gmt_offset'          => '9',
				'expected'            => 32400,
			),
			array(
				'test_condition_name' => '両方のオプションが無い（get_option() が false）場合 => 0',
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'timezone_string'     => false,
				'gmt_offset'          => false,
				'expected'            => 0,
			),
			array(
				'test_condition_name' => 'タイムスタンプが 0（記録に時刻が無い）の場合 => 1970年当時のオフセット',
				'timestamp'           => 0,
				'timezone_string'     => 'Asia/Tokyo',
				'gmt_offset'          => 9,
				'expected'            => 32400,
			),
		);

		foreach ( $test_cases as $case ) {
			// Run the method under test. / テスト対象を実行する。
			$actual = ACGD_Time::offset_at( $case['timestamp'], $case['timezone_string'], $case['gmt_offset'] );

			// Check the result, and that it is an int (it is added to a timestamp). / 結果と、int であること（タイムスタンプに足すため）を確かめる。
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * Tests ACGD_Time::to_local(), which reads the two options itself. / オプション2つを自分で読む ACGD_Time::to_local() のテスト。
	 *
	 * @return void
	 */
	public function test_to_local() {
		$test_cases = array(
			array(
				'test_condition_name' => 'Asia/Tokyo の場合 => 9時間後の値',
				'options'             => array(
					'timezone_string' => 'Asia/Tokyo',
					'gmt_offset'      => 9,
				),
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'expected'            => gmmktime( 2, 44, 0, 9, 23, 2026 ) + 32400,
			),
			array(
				'test_condition_name' => '手動のオフセット「UTC-3:30」の場合 => 3時間30分前の値',
				'options'             => array(
					'timezone_string' => '',
					'gmt_offset'      => '-3.5',
				),
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'expected'            => gmmktime( 2, 44, 0, 9, 23, 2026 ) - 12600,
			),
			array(
				'test_condition_name' => 'オプションが1つも無い場合 => そのまま',
				'options'             => array(),
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'expected'            => gmmktime( 2, 44, 0, 9, 23, 2026 ),
			),
			array(
				'test_condition_name' => 'タイムスタンプが数字の文字列の場合 => int として扱う',
				'options'             => array(
					'timezone_string' => 'Asia/Tokyo',
					'gmt_offset'      => 9,
				),
				'timestamp'           => '1790131440',
				'expected'            => 1790131440 + 32400,
			),
		);

		foreach ( $test_cases as $case ) {
			// Set the options of this case. / このケースのオプションを設定する。
			acgd_test_reset_options();
			foreach ( $case['options'] as $name => $value ) {
				acgd_test_set_option( $name, $value );
			}

			// Run the method under test and check the result. / テスト対象を実行し、結果を確かめる。
			$this->assertSame( $case['expected'], ACGD_Time::to_local( $case['timestamp'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * Tests ACGD_Time::format_local(), the one the settings screen calls.
	 * 設定画面が呼ぶ ACGD_Time::format_local() のテスト。
	 *
	 * The first case is the reported one: a denial at 11:44 in Asia/Tokyo was shown as 02:44.
	 * 最初のケースが報告のあった事例：Asia/Tokyo で 11:44 の拒否が 02:44 と表示されていた。
	 *
	 * @return void
	 */
	public function test_format_local() {
		$test_cases = array(
			array(
				'test_condition_name' => 'Asia/Tokyo で 02:44 UTC の記録の場合 => 11:44 と表示する（UTC の 02:44 のままにしない）',
				'options'             => array(
					'timezone_string' => 'Asia/Tokyo',
					'gmt_offset'      => 9,
				),
				'format'              => 'Y-m-d H:i:s',
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'expected'            => '2026-09-23 11:44:00',
			),
			array(
				'test_condition_name' => 'Asia/Tokyo で 20:30 UTC の記録の場合 => 日付も翌日に繰り上がる',
				'options'             => array(
					'timezone_string' => 'Asia/Tokyo',
					'gmt_offset'      => 9,
				),
				'format'              => 'Y-m-d H:i:s',
				'timestamp'           => gmmktime( 20, 30, 0, 9, 22, 2026 ),
				'expected'            => '2026-09-23 05:30:00',
			),
			array(
				'test_condition_name' => 'America/New_York で夏時間中の記録を冬に見る場合 => 記録した時点の -4 時間で表示する',
				'options'             => array(
					'timezone_string' => 'America/New_York',
					'gmt_offset'      => -5,
				),
				'format'              => 'Y-m-d H:i:s',
				'timestamp'           => gmmktime( 16, 0, 0, 7, 1, 2026 ),
				'expected'            => '2026-07-01 12:00:00',
			),
			array(
				'test_condition_name' => '受信診断と同じ「日付書式 + 時刻書式」の場合 => 両方ともサイトの時刻で出す',
				'options'             => array(
					'timezone_string' => 'Asia/Tokyo',
					'gmt_offset'      => 9,
				),
				'format'              => 'Y/m/d H:i',
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'expected'            => '2026/09/23 11:44',
			),
			array(
				'test_condition_name' => 'サイトが UTC の場合 => 変わらない',
				'options'             => array(
					'timezone_string' => 'UTC',
					'gmt_offset'      => 0,
				),
				'format'              => 'Y-m-d H:i:s',
				'timestamp'           => gmmktime( 2, 44, 0, 9, 23, 2026 ),
				'expected'            => '2026-09-23 02:44:00',
			),
			array(
				'test_condition_name' => '記録に時刻が無い（0）場合 => 1970-01-01 00:00:00 UTC をサイトの時刻で出す',
				'options'             => array(
					'timezone_string' => 'Asia/Tokyo',
					'gmt_offset'      => 9,
				),
				'format'              => 'Y-m-d H:i:s',
				'timestamp'           => 0,
				'expected'            => '1970-01-01 09:00:00',
			),
		);

		foreach ( $test_cases as $case ) {
			// Set the options of this case. / このケースのオプションを設定する。
			acgd_test_reset_options();
			foreach ( $case['options'] as $name => $value ) {
				acgd_test_set_option( $name, $value );
			}

			// Run the method under test and check the result. / テスト対象を実行し、結果を確かめる。
			$this->assertSame( $case['expected'], ACGD_Time::format_local( $case['format'], $case['timestamp'] ), $case['test_condition_name'] );
		}
	}
}
