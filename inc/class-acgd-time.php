<?php
/**
 * Displaying stored times in the site's time zone.
 * 保存した時刻をサイトのタイムゾーンで表示する。
 *
 * This plugin stores times as time() (a Unix timestamp, which is UTC). date_i18n() expects a
 * "timestamp with offset" (the local wall-clock time counted as if it were UTC), so passing time()
 * to it directly prints UTC: a denial at 11:44 in Asia/Tokyo was shown as 02:44.
 * このプラグインは時刻を time()（Unix 時刻＝UTC）で保存している。date_i18n() が受け取るのは
 * 「オフセット込みのタイムスタンプ」（現地の時計の値を UTC とみなして数えたもの）なので、
 * time() をそのまま渡すと UTC で表示される（Asia/Tokyo で 11:44 の拒否が 02:44 と出ていた）。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts stored UTC timestamps for display. / 保存した UTC のタイムスタンプを表示用に変換する。
 */
class ACGD_Time {

	/**
	 * Formats a Unix timestamp (as time() returns) in the site's time zone, with translated month and day names.
	 * Unix 時刻（time() の戻り値）をサイトのタイムゾーンで、月名・曜日名を訳した形に整形する。
	 *
	 * date_i18n(), not wp_date() (WordPress 5.3+): this plugin declares no minimum WordPress version.
	 * date_i18n() を使う（wp_date() は WordPress 5.3 以降のため使わない）。このプラグインは WordPress の下限を宣言していない。
	 *
	 * Known limit of that choice: in the repeated hour when daylight saving ends, zone characters (T, e, O, P) may show
	 * the other side's abbreviation or offset; the wall-clock digits are correct.
	 * この選択の既知の限界：夏時間が終わって同じ1時間が2回来る間だけ、書式の T・e・O・P が反対側の略号・オフセットに
	 * なりうる（時計の数字は正しい）。
	 *
	 * @param string $format    PHP date format. / PHP の日付書式。
	 * @param int    $timestamp Unix timestamp (UTC). / Unix 時刻（UTC）。
	 * @return string Formatted date, not escaped. / 整形した日時（未エスケープ）。
	 */
	public static function format_local( $format, $timestamp ) {
		return date_i18n( $format, self::to_local( $timestamp ) );
	}

	/**
	 * Returns the "timestamp with offset" that date_i18n() expects for a Unix timestamp.
	 * Unix 時刻を、date_i18n() が受け取る「オフセット込みのタイムスタンプ」に直して返す。
	 *
	 * @param int $timestamp Unix timestamp (UTC). / Unix 時刻（UTC）。
	 * @return int Timestamp with the site's offset at that moment added. / その時点のサイトのオフセットを足したタイムスタンプ。
	 */
	public static function to_local( $timestamp ) {
		$timestamp = (int) $timestamp;

		return $timestamp + self::offset_at( $timestamp, get_option( 'timezone_string' ), get_option( 'gmt_offset' ) );
	}

	/**
	 * Returns the site's UTC offset in seconds at the given moment.
	 * 指定した時点での、サイトの UTC からのオフセット（秒）を返す。
	 *
	 * A named time zone (timezone_string, e.g. Asia/Tokyo) is asked for its offset at that very moment, so a
	 * record made before a daylight saving change keeps the offset it had then. get_option( 'gmt_offset' ) is
	 * not used for such zones: WordPress fills it from the time zone's offset right now (wp_timezone_override_offset()),
	 * which is wrong for records on the other side of a change. A manual offset ("UTC+9", "UTC+5:30") is stored
	 * only in gmt_offset, as a number of hours that can be fractional.
	 * 地域名のタイムゾーン（timezone_string。例: Asia/Tokyo）は、その時点のオフセットを問い合わせる。
	 * 夏時間の切り替わりより前の記録も、当時のオフセットで表示するため。こうしたタイムゾーンで
	 * get_option( 'gmt_offset' ) を使わないのは、WordPress がその値を「いま」のオフセットで埋める
	 * （wp_timezone_override_offset()）ため、切り替わりをまたいだ記録ではずれるから。
	 * 手動のオフセット（「UTC+9」「UTC+5:30」）は gmt_offset にだけ、小数を含みうる時間数で保存される。
	 *
	 * @param int   $timestamp       Unix timestamp (UTC). / Unix 時刻（UTC）。
	 * @param mixed $timezone_string Value of the timezone_string option. / timezone_string オプションの値。
	 * @param mixed $gmt_offset      Value of the gmt_offset option (hours). / gmt_offset オプションの値（時間）。
	 * @return int Offset in seconds. / オフセット（秒）。
	 */
	public static function offset_at( $timestamp, $timezone_string, $gmt_offset ) {
		$zone = null;
		if ( is_string( $timezone_string ) && '' !== $timezone_string ) {
			try {
				$zone = new DateTimeZone( $timezone_string );
			} catch ( Exception $e ) {
				// An unknown zone name (e.g. removed from this PHP's tz database): fall back to gmt_offset below.
				// 未知のタイムゾーン名（この PHP のタイムゾーンデータベースから消えたものなど）は、下の gmt_offset に任せる。
				$zone = null;
			}
		}

		if ( null !== $zone ) {
			// '@' makes DateTime read the value as a Unix timestamp in UTC. / '@' を付けると DateTime は値を UTC の Unix 時刻として読む。
			return $zone->getOffset( new DateTime( '@' . (int) $timestamp ) );
		}

		// The offsets WordPress offers are multiples of 0.25 hours and exact as floats; round() only keeps a value
		// stored by other means (e.g. WP-CLI) on the nearest second instead of truncating it.
		// WordPress が選ばせるオフセットは 0.25 時間刻みで、浮動小数でも誤差が出ない。round() は、ほかの手段
		// （WP-CLI など）で保存された値でも切り捨てず、最も近い秒に揃えるためだけのもの。
		return (int) round( (float) $gmt_offset * HOUR_IN_SECONDS );
	}
}
