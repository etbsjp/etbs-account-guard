<?php
/**
 * Two-Step Verification: a verification code sent to the registered email address, asked for after the
 * password has been accepted (docs/spec.md 7).
 * 2段階認証：パスワードが合った後に、登録メールアドレスへ送った確認コードの入力を求める（docs/spec.md 7）。
 *
 * What is here (PR-B, docs/spec.md 7.15): who is a target (7.1), the data (7.2), the save-time checks and the
 * receive check (7.3), the login flow and the code screen (7.4, 7.5, 7.10), the session mark (7.6), the
 * bypass routes (7.7), the emails (7.9), the settings tab, the user edit screen section and the Users list
 * column (7.10), failure handling and the emergency switch (7.11) and the denial log entries (7.13).
 * Trusted devices (7.8) are PR-C; only the place in the login flow where they will be checked is kept
 * (is_trusted_device()).
 * ここにあるもの（PR-B。docs/spec.md 7.15）：対象の決め方（7.1）、データ（7.2）、保存時のチェックと受信確認（7.3）、
 * ログインの流れとコード入力画面（7.4・7.5・7.10）、セッションの印（7.6）、迂回経路（7.7）、メール（7.9）、
 * 設定タブ・ユーザー編集画面の区画・ユーザー一覧の列（7.10）、失敗したときと非常用スイッチ（7.11）、
 * 拒否の記録の場面（7.13）。信頼した端末（7.8）は PR-C で、ログインの流れの中でそれを確かめる場所
 * （is_trusted_device()）だけを置いている。
 *
 * ★ Unlike Access Restriction (5.5), this feature fails closed, and it has a fault record of its own: it
 * never calls ACGD_Access_Restriction::record_fault() / is_disabled() (7.11).
 * ★ アクセス制限（5.5）と逆に、この機能は故障時に閉じる。故障の記録も独自に持ち、
 * ACGD_Access_Restriction::record_fault() / is_disabled() を一切呼ばない（7.11）。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two-Step Verification by an emailed verification code. / メールの確認コードによる2段階認証。
 */
class ACGD_Two_Step {

	/**
	 * Option that stores the per-role methods and the number of days a device is trusted (7.2).
	 * Registered with register_setting(), so it never appears in an update_option() search. Missing is a
	 * normal state (every role "none"), not a fault (7.1).
	 * 権限ごとの方式と、端末を信頼する日数を持つオプション（7.2）。register_setting() で登録するので
	 * update_option() の検索には現れない。存在しないのは正常な状態（全権限「なし」）で、故障ではない（7.1）。
	 *
	 * @var string
	 */
	const OPTION = 'acgd_two_step';

	/**
	 * User meta that stores one user's own method: 'follow' (default), 'none' or 'email' (7.2).
	 * ユーザー自身の方式を持つユーザーメタ（'follow'（既定）・'none'・'email'。7.2）。
	 *
	 * @var string
	 */
	const USER_METHOD_META = 'acgd_two_step_method';

	/**
	 * Option that records a fault of this feature (7.11). Written with add_option(), so an existing record is
	 * never rewritten; cleared by a successful save of the Two-Step Verification tab. Not autoloaded.
	 * この機能の故障の記録（7.11）。add_option() で書くので、既にある記録は書き直さない。2段階認証タブの
	 * 保存に成功すると消える。autoload しない。
	 *
	 * @var string
	 */
	const FAULT_OPTION = 'acgd_two_step_fault';

	/**
	 * Prefix of every option row this feature reads and writes directly with $wpdb (attempts, wrong-code
	 * counters, send records, receive check marks). See 7.2 for why they bypass the Options API.
	 * この機能が $wpdb で直接読み書きするオプション行（試行・誤りの回数・送信の記録・受信確認の印）の接頭辞。
	 * Options API を通さない理由は 7.2 を参照。
	 *
	 * @var string
	 */
	const ROW_PREFIX = 'acgd_2s_';

	const METHOD_FOLLOW = 'follow';
	const METHOD_NONE   = 'none';
	const METHOD_EMAIL  = 'email';

	/**
	 * Number of digits of a code (7.5). / コードの桁数（7.5）。
	 *
	 * @var int
	 */
	const CODE_DIGITS = 6;

	/**
	 * How long one code is valid (7.5: 10 minutes). / 1つのコードの有効期限（7.5：10分）。
	 *
	 * @var int
	 */
	const CODE_TTL = 600;

	/**
	 * How long one attempt lives, counted from the password entry (7.4-3: 30 minutes). Longer than CODE_TTL on
	 * purpose, so an expired code can be replaced from the same screen without typing the password again.
	 * 1つの試行の寿命。パスワードの入力から数える（7.4-3：30分）。CODE_TTL より長いのは意図的で、
	 * 期限切れのコードをパスワードからやり直さずに同じ画面から再送できるようにするため。
	 *
	 * @var int
	 */
	const ATTEMPT_TTL = 1800;

	/**
	 * Wrong entries allowed for one code (7.5). / 1つのコードに許す誤りの回数（7.5）。
	 *
	 * @var int
	 */
	const MAX_WRONG = 5;

	/**
	 * Length of the sending window, and the number of emails allowed in it, per user (7.5: 5 per hour).
	 * Each email carries MAX_WRONG tries, so this is the brute-force ceiling itself; do not loosen it.
	 * ユーザーごとの送信の窓の長さと、その中で送れる通数（7.5：1時間に5通）。1通ごとに MAX_WRONG 回の枠が
	 * 付くので、これが総当たりの上限そのもの。緩めないこと。
	 *
	 * @var int
	 */
	const SEND_WINDOW = 3600;
	const SEND_MAX    = 5;

	/**
	 * Minimum gap between two emails to the same user (7.5: 60 seconds). / 同じ人への送信の最小間隔（7.5：60秒）。
	 *
	 * @var int
	 */
	const SEND_GAP = 60;

	/**
	 * How many times reserve_send_slot() re-reads after losing a race, before treating it as the limit (7.5).
	 * reserve_send_slot() が先を越されたときに読み直す回数。超えたら上限扱い（7.5）。
	 *
	 * @var int
	 */
	const SEND_RETRIES = 3;

	/**
	 * How long a receive check stays valid for a save (7.3: 10 minutes). / 受信確認が保存に効く時間（7.3：10分）。
	 *
	 * @var int
	 */
	const RECEIVE_CHECK_TTL = 600;

	/**
	 * Key added to the session information of a session that went through the second step (7.6).
	 * 2段階目を通したセッションの情報に足すキー（7.6）。
	 *
	 * @var string
	 */
	const SESSION_MARK = 'acgd_two_step';

	/**
	 * wp-login.php actions of the code screen and of the resend button (7.10).
	 * コード入力画面と「コードを再送する」の wp-login.php のアクション（7.10）。
	 *
	 * @var string
	 */
	const ACTION_CODE   = 'acgd_code';
	const ACTION_RESEND = 'acgd_code_resend';

	/**
	 * Name of the hidden field that carries the attempt ID alongside the cookie (7.4-4).
	 * Cookie と並んで試行 ID を運ぶ hidden の名前（7.4-4）。
	 *
	 * @var string
	 */
	const ATTEMPT_FIELD = 'acgd_attempt';

	/**
	 * Name of the code field of the code screen. / コード入力画面のコード欄の名前。
	 *
	 * @var string
	 */
	const CODE_FIELD = 'acgd_code';

	/**
	 * Name of the nonce field of the code screen's forms (the action is per attempt; see code_nonce_action()).
	 * コード入力画面のフォームの nonce の名前（アクションは試行ごと。code_nonce_action() を参照）。
	 *
	 * @var string
	 */
	const CODE_NONCE_FIELD = '_acgd_code_nonce';

	/**
	 * Settings group of the Two-Step Verification tab. / 「2段階認証」タブの設定グループ。
	 *
	 * @var string
	 */
	const SETTINGS_GROUP = 'acgd_two_step';

	/**
	 * Slug of the Two-Step Verification tab of the settings screen. / 設定画面の「2段階認証」タブのスラッグ。
	 *
	 * @var string
	 */
	const TAB = 'two-step';

	/**
	 * Nonce action of the receive check (admin-ajax and the admin-post fallback). / 受信確認の nonce アクション。
	 *
	 * @var string
	 */
	const RC_NONCE_ACTION = 'acgd_2s_rc';

	/**
	 * Name of the receive check buttons ('send' or 'verify') and of its code field.
	 * 受信確認のボタン（値は 'send' か 'verify'）とコード欄の名前。
	 *
	 * @var string
	 */
	const RC_DO_FIELD   = 'acgd_2s_rc_do';
	const RC_CODE_FIELD = 'acgd_2s_rc_code';

	/**
	 * Nonce action and field of the user edit screen section. / ユーザー編集画面の区画の nonce。
	 *
	 * @var string
	 */
	const USER_NONCE_ACTION = 'acgd_two_step_user';
	const USER_NONCE_NAME   = 'acgd_two_step_user_nonce';

	/**
	 * Users list column. / ユーザー一覧の列。
	 *
	 * @var string
	 */
	const COLUMN = 'acgd_two_step';

	/**
	 * Script handle of the receive check (a src-less handle carrying an inline script; display only).
	 * 受信確認のスクリプトのハンドル（src を持たずインラインのスクリプトだけを載せる。表示用途のみ）。
	 *
	 * @var string
	 */
	const RC_SCRIPT_HANDLE = 'acgd-two-step-receive-check';

	/**
	 * Transient prefixes that hold a rejected submission for redisplay, the same mechanism as the Access
	 * Restriction tab (ACGD_Settings::RESUBMIT_TRANSIENT_PREFIX). They expire after RESUBMIT_TTL on their own.
	 * 拒否された送信内容を出し直すための transient の接頭辞。「アクセス制限」タブと同じ仕組み
	 * （ACGD_Settings::RESUBMIT_TRANSIENT_PREFIX）。RESUBMIT_TTL で自然に消える。
	 *
	 * @var string
	 */
	const SETTINGS_RESUBMIT_PREFIX = 'acgd_two_step_resubmit_';
	const USER_RESUBMIT_PREFIX     = 'acgd_two_step_user_resubmit_';
	const RESUBMIT_TTL             = MINUTE_IN_SECONDS;

	/**
	 * Maximum number of users listed on the settings tab. / 設定タブに並べるユーザーの上限。
	 *
	 * @var int
	 */
	const LIST_LIMIT = 100;

	/**
	 * Users loaded at a time when looking for targets. / 対象を探すときに一度に読み込む人数。
	 *
	 * @var int
	 */
	const USERS_BATCH = 200;

	/**
	 * Maximum number of rows looked at by one cleanup of expired rows. / 期限切れの行の掃除で1回に見る行数の上限。
	 *
	 * @var int
	 */
	const CLEANUP_LIMIT = 500;

	/**
	 * The last credentials seen by the secure_signon_cookie filter in this request (7.4-2-7): which login
	 * name wp_signon() was called with, and its remember / secure cookie choices. Null until it fires.
	 * このリクエストで secure_signon_cookie フィルタが最後に見た資格情報（7.4-2-7）：wp_signon() がどの
	 * ログイン名で呼ばれたか、「ログイン状態を保存」と secure cookie の選択。発火するまでは null。
	 *
	 * @var array|null
	 */
	private static $signon = null;

	/**
	 * ID of the user an application password authenticated in this request, or 0 (7.4-2-3, 7.6).
	 * このリクエストでアプリケーションパスワードが認証したユーザーの ID。無ければ 0（7.4-2-3・7.6）。
	 *
	 * @var int
	 */
	private static $app_password_user_id = 0;

	/**
	 * ID of the user whose next session this request creates must carry the mark (after the code, 7.6).
	 * このリクエストで作られる次のセッションに印を付けるユーザーの ID（コードを通した後。7.6）。
	 *
	 * @var int
	 */
	private static $mark_user_id = 0;

	/**
	 * Validation error of the user edit screen section, for user_profile_update_errors in the same request.
	 * ユーザー編集画面の区画の検証エラー。同じリクエストの user_profile_update_errors で使う。
	 *
	 * @var string
	 */
	private static $pending_error = '';

	/**
	 * Registers the hooks. Nothing is registered on multisite (7.1: not supported there, because the login
	 * cookie can be shared across the network and a site without Two-Step Verification would let people in).
	 * フックを登録する。マルチサイトでは何も登録しない（7.1：非対応。ログイン Cookie がネットワークで共有され
	 * うるため、2段階認証を掛けていないサイトから入れてしまう）。
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::is_available() ) {
			return;
		}

		// Watchers used by the login flow (7.4-2-3, 7.4-2-7). / ログインの流れが使う見張り（7.4-2-3・7.4-2-7）。
		add_filter( 'secure_signon_cookie', array( __CLASS__, 'capture_signon' ), PHP_INT_MAX, 2 );
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'capture_application_password' ), 10, 1 );

		/*
		 * The second step sits at the very end of authenticate (7.4-2): after core's password check (20), the
		 * cookie check (30), Login Name Protection's common error (9999) and whatever other plugins add, so
		 * only a login every other gate has already accepted as a WP_User reaches it. IP restriction and
		 * CAPTCHA plugins run on wp_authenticate_user, which is inside priority 20 — a wrong CAPTCHA or a
		 * disallowed address never gets here, and no email is sent for it (docs/spec.md 5.2).
		 * 2段階目は authenticate の最後尾に置く（7.4-2）。本体のパスワード照合（20）・Cookie の確認（30）・
		 * ログイン名の保護の共通エラー（9999）・他のプラグインが足すものの後なので、他の関門をすべて通って
		 * WP_User になったログインだけがここへ来る。IP 制限や画像認証のプラグインは優先度 20 の内側の
		 * wp_authenticate_user で動くので、画像認証の誤答や許可されていない接続元はここへ来ず、メールも
		 * 送られない（docs/spec.md 5.2）。
		 */
		add_filter( 'authenticate', array( __CLASS__, 'filter_authenticate' ), PHP_INT_MAX, 3 );

		// The code screen, always on the wp-login.php level (7.10). / コード入力画面。常に wp-login.php の階層（7.10）。
		add_action( 'login_form_' . self::ACTION_CODE, array( __CLASS__, 'handle_code_screen' ) );
		add_action( 'login_form_' . self::ACTION_RESEND, array( __CLASS__, 'handle_resend' ) );

		// Session mark (7.6). / セッションの印（7.6）。
		add_filter( 'attach_session_information', array( __CLASS__, 'filter_attach_session_information' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'check_session_on_request' ), ACGD_Access_Restriction::ADMIN_INIT_PRIORITY );
		add_action( 'template_redirect', array( __CLASS__, 'check_session_on_request' ) );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'filter_rest_authentication_errors' ), ACGD_Access_Restriction::REST_AUTHENTICATION_PRIORITY + 10 );

		// A password change clears this user's attempts and send records (7.5). / パスワードの変更で試行・送信の記録を消す（7.5）。
		add_action( 'after_password_reset', array( __CLASS__, 'on_after_password_reset' ), 10, 1 );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ), 10, 2 );
		add_action( 'wp_set_password', array( __CLASS__, 'on_wp_set_password' ), 10, 3 );

		// Receive check (7.3). / 受信確認（7.3）。
		add_action( 'wp_ajax_' . self::RC_NONCE_ACTION, array( __CLASS__, 'ajax_receive_check' ) );
		add_action( 'admin_post_' . self::RC_NONCE_ACTION, array( __CLASS__, 'handle_receive_check_post' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_profile_receive_check' ), ACGD_Access_Restriction::ADMIN_INIT_PRIORITY + 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_receive_check_script' ) );

		// Settings tab, user edit screen, Users list, notices (7.10, 7.11). / 設定タブ・ユーザー編集画面・ユーザー一覧・通知（7.10・7.11）。
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_user_fields' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_user_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_fields' ) );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'append_pending_error' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/*-------------------------------------------*/
	/* Switches / スイッチ
	/*-------------------------------------------*/

	/**
	 * Tells whether this feature exists on this site at all: never on multisite (7.1).
	 * この機能がこのサイトにそもそもあるかを返す。マルチサイトでは無い（7.1）。
	 *
	 * @return bool Whether available. / あるか。
	 */
	public static function is_available() {
		return ! is_multisite();
	}

	/**
	 * Tells whether the emergency switch of this feature is set (7.11). Independent of ACGD_DISABLE_RESTRICTION.
	 * この機能の非常用スイッチが立っているかを返す（7.11）。ACGD_DISABLE_RESTRICTION とは独立。
	 *
	 * @return bool Whether set. / 立っているか。
	 */
	public static function is_switch_disabled() {
		return defined( 'ACGD_DISABLE_TWO_STEP' ) && ACGD_DISABLE_TWO_STEP;
	}

	/*-------------------------------------------*/
	/* Settings and targets (7.1, 7.2) / 設定と対象（7.1・7.2）
	/*-------------------------------------------*/

	/**
	 * Reads a stored value of OPTION into a known shape, and tells whether it is broken (7.11).
	 * OPTION の保存値を決まった形に読み、壊れているかも返す（7.11）。
	 *
	 * Missing (null) is normal: every role "none" (7.1). An array without some key uses the default for that
	 * key (an empty array is what the Settings API stores when the very first save is rejected). Broken means:
	 * not an array, 'roles' not an array, a role whose method is not 'none' / 'email', or a 'trust_days' that is
	 * not 0, 7 or 30. The valid part is still returned, but the caller must use the narrow rule (7.11).
	 * 無い（null）のは正常で、全権限「なし」（7.1）。キーが欠けた配列はそのキーの既定値を使う（最初の保存が
	 * 拒否されたとき、Settings API は空の配列を保存する）。壊れているとは：配列でない、'roles' が配列でない、
	 * 方式が 'none' / 'email' でない権限がある、'trust_days' が 0・7・30 のどれでもない。正しい部分は返すが、
	 * 呼び出し側は狭い規則（7.11）を使わなければならない。
	 *
	 * @param mixed $raw Stored value, or null when the option does not exist. / 保存値。オプションが無ければ null。
	 * @return array {
	 *     @type string[] $roles      Role => 'none' | 'email'. / 権限 => 方式。
	 *     @type int      $trust_days 0, 7 or 30 (7.8; used by PR-C). / 0・7・30（7.8。PR-C で使う）。
	 *     @type bool     $broken     Whether the stored value is broken. / 保存値が壊れているか。
	 * }
	 */
	public static function parse_settings( $raw ) {
		$parsed = array(
			'roles'      => array(),
			'trust_days' => 30,
			'broken'     => false,
		);

		if ( null === $raw ) {
			return $parsed;
		}
		if ( ! is_array( $raw ) ) {
			$parsed['broken'] = true;
			return $parsed;
		}

		if ( array_key_exists( 'roles', $raw ) ) {
			if ( ! is_array( $raw['roles'] ) ) {
				$parsed['broken'] = true;
			} else {
				foreach ( $raw['roles'] as $role => $method ) {
					if ( is_string( $role ) && in_array( $method, array( self::METHOD_NONE, self::METHOD_EMAIL ), true ) ) {
						$parsed['roles'][ $role ] = $method;
					} else {
						$parsed['broken'] = true;
					}
				}
			}
		}

		if ( array_key_exists( 'trust_days', $raw ) ) {
			if ( is_int( $raw['trust_days'] ) && in_array( $raw['trust_days'], array( 0, 7, 30 ), true ) ) {
				$parsed['trust_days'] = $raw['trust_days'];
			} else {
				$parsed['broken'] = true;
			}
		}

		return $parsed;
	}

	/**
	 * Returns the saved settings, parsed (parse_settings()). / 保存済みの設定を解析して返す（parse_settings()）。
	 *
	 * @return array Same shape as parse_settings(). / parse_settings() と同じ形。
	 */
	public static function get_settings() {
		return self::parse_settings( get_option( self::OPTION, null ) );
	}

	/**
	 * Returns one user's own method: 'follow' (default), 'none' or 'email'. / ユーザー自身の方式を返す。
	 *
	 * @param int $user_id User ID. / ユーザー ID。
	 * @return string Method. / 方式。
	 */
	public static function get_user_method( $user_id ) {
		$method = get_user_meta( (int) $user_id, self::USER_METHOD_META, true );

		return in_array( $method, array( self::METHOD_NONE, self::METHOD_EMAIL ), true ) ? $method : self::METHOD_FOLLOW;
	}

	/**
	 * Decides whether a user is a target, from plain values (7.1, 7.11). Pure.
	 * ユーザーが対象かを、素の値から判定する（7.1・7.11）。副作用なし。
	 *
	 * The user's own method wins over the roles. With 'follow', one role whose method is 'email' is enough
	 * ("確認コード" wins when roles disagree). administrator is not special here: it can be chosen like any
	 * other role (7.1). With a broken option, only users set to 'email' themselves and users who can manage
	 * options are targets (7.11: close narrowly, never everyone, so customers can still log in).
	 * ユーザー自身の方式が権限より優先。'follow' なら、方式が 'email' の権限を1つでも持てば対象（食い違えば
	 * 「確認コード」）。administrator もここでは特別扱いしない（7.1：他の権限と同じく選べる）。
	 * オプションが壊れているときは、自分で 'email' にしている人と manage_options を持つ人だけを対象にする
	 * （7.11：全員は閉じない。顧客のログインまで止めない）。
	 *
	 * @param string[] $user_roles   Roles the user holds. / ユーザーが持つ権限。
	 * @param string   $user_method  'follow', 'none' or 'email'. / ユーザー自身の方式。
	 * @param string[] $role_methods Role => method. / 権限 => 方式。
	 * @param bool     $broken       Whether the option is broken. / オプションが壊れているか。
	 * @param bool     $can_manage   Whether the user can manage options (read only when broken). / manage_options を持つか（壊れているときだけ読む）。
	 * @return bool Whether a target. / 対象か。
	 */
	public static function compute_is_target( $user_roles, $user_method, $role_methods, $broken, $can_manage ) {
		if ( $broken ) {
			return self::METHOD_EMAIL === $user_method || (bool) $can_manage;
		}
		if ( self::METHOD_EMAIL === $user_method ) {
			return true;
		}
		if ( self::METHOD_NONE === $user_method ) {
			return false;
		}

		foreach ( (array) $user_roles as $role ) {
			if ( isset( $role_methods[ $role ] ) && self::METHOD_EMAIL === $role_methods[ $role ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether a user is a target of Two-Step Verification, from the saved settings or from values about
	 * to be saved. A broken option is recorded as a fault here (7.11) and judged by the narrow rule.
	 * ユーザーが2段階認証の対象かを、保存済みの設定、または保存しようとしている値から返す。オプションが
	 * 壊れていればここで故障として記録し（7.11）、狭い規則で判定する。
	 *
	 * @param WP_User       $user          User. / ユーザー。
	 * @param string[]|null $role_methods  Prospective role => method, or null for the saved ones. / 保存前の 権限 => 方式。null なら保存済み。
	 * @param string|null   $forced_method Prospective own method, or null for the saved one. / 保存前の本人の方式。null なら保存済み。
	 * @return bool Whether a target. / 対象か。
	 */
	public static function is_target( $user, $role_methods = null, $forced_method = null ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}

		$broken = false;
		if ( null === $role_methods ) {
			$settings     = self::get_settings();
			$role_methods = $settings['roles'];
			$broken       = $settings['broken'];
			if ( $broken ) {
				self::record_fault( 'broken_option' );
			}
		}

		$method = null !== $forced_method ? $forced_method : self::get_user_method( $user->ID );

		return self::compute_is_target( $user->roles, $method, $role_methods, $broken, $broken && user_can( $user, 'manage_options' ) );
	}

	/**
	 * Judges the target by the narrow rule only (7.11), for when the ordinary judgment itself threw.
	 * 通常の判定そのものが例外を投げたときに、狭い規則（7.11）だけで対象を判定する。
	 *
	 * @param WP_User $user User. / ユーザー。
	 * @return bool Whether a target. / 対象か。
	 */
	private static function is_target_narrow( $user ) {
		try {
			return self::compute_is_target( array(), self::get_user_method( $user->ID ), array(), true, user_can( $user, 'manage_options' ) );
		} catch ( Throwable $e ) {
			return true; // Cannot tell at all: close (7.11). / まったく判定できない：閉じる（7.11）。
		}
	}

	/*-------------------------------------------*/
	/* Fault record (7.11) / 故障の記録（7.11）
	/*-------------------------------------------*/

	/**
	 * Records a fault of this feature, unless one is already recorded (7.2: not rewritten on every login while
	 * the option stays broken). add_option() does nothing when the row exists.
	 * この機能の故障を記録する。既に記録があれば書き直さない（7.2：壊れたままログインが続いても毎回は
	 * 書かない）。add_option() は行があれば何もしない。
	 *
	 * @param string $message What happened (shown on the settings tab only). / 何が起きたか（設定タブにだけ出す）。
	 * @return void
	 */
	public static function record_fault( $message ) {
		add_option(
			self::FAULT_OPTION,
			array(
				'time'    => time(),
				'message' => (string) $message,
			),
			'',
			false
		);
	}

	/**
	 * Tells whether a fault is recorded. / 故障が記録されているかを返す。
	 *
	 * @return bool Whether recorded. / 記録されているか。
	 */
	public static function has_fault() {
		return (bool) get_option( self::FAULT_OPTION, false );
	}

	/**
	 * Clears the fault record (called after a successful save of the tab). / 故障の記録を消す（タブの保存成功後）。
	 *
	 * @return void
	 */
	public static function clear_fault() {
		delete_option( self::FAULT_OPTION );
	}

	/*-------------------------------------------*/
	/* Pure helpers (codes, input, limits) / 純粋な処理（コード・入力・上限）
	/*-------------------------------------------*/

	/**
	 * Generates a code: six digits from random_int( 0, 999999 ), zero padded (7.5).
	 * コードを作る。random_int( 0, 999999 ) を0埋めの6桁にする（7.5）。
	 *
	 * @return string Six digits. / 数字6桁。
	 */
	public static function generate_code() {
		return sprintf( '%06d', random_int( 0, 999999 ) );
	}

	/**
	 * Returns the HMAC of a login code (7.5). The key is shared with the auth cookie, so a prefix and the user
	 * ID separate the use. / ログインのコードの HMAC を返す（7.5）。鍵を auth Cookie と共有するので、
	 * 接頭辞とユーザー ID で用途を分ける。
	 *
	 * @param int    $user_id    User ID. / ユーザー ID。
	 * @param string $attempt_id Attempt ID (the value in the cookie, not its hash). / 試行 ID（Cookie の値。ハッシュではない）。
	 * @param string $code       Code. / コード。
	 * @param string $key        HMAC key (wp_salt( 'auth' ) in production). / HMAC の鍵（本番は wp_salt( 'auth' )）。
	 * @return string Hex HMAC. / 16進の HMAC。
	 */
	public static function code_hmac( $user_id, $attempt_id, $code, $key ) {
		return hash_hmac( 'sha256', 'acgd-2s|' . (int) $user_id . '|' . $attempt_id . '|' . $code, $key );
	}

	/**
	 * Returns the HMAC of a receive check code (7.3). A prefix of its own, so a receive check code can never
	 * be accepted as a login code or the other way round. / 受信確認のコードの HMAC を返す（7.3）。
	 * 接頭辞を分け、受信確認のコードがログインのコードとして（またはその逆に）通ることが無いようにする。
	 *
	 * @param int    $user_id  User ID. / ユーザー ID。
	 * @param string $check_id Receive check ID (random, kept in the row). / 受信確認の ID（乱数。行に持つ）。
	 * @param string $code     Code. / コード。
	 * @param string $key      HMAC key. / HMAC の鍵。
	 * @return string Hex HMAC. / 16進の HMAC。
	 */
	public static function receive_check_hmac( $user_id, $check_id, $code, $key ) {
		return hash_hmac( 'sha256', 'acgd-2s-rc|' . (int) $user_id . '|' . $check_id . '|' . $code, $key );
	}

	/**
	 * Returns the fingerprint of a password hash, stored in the attempt to notice a password change (7.4-3).
	 * An HMAC, so the row never holds anything derived from the hash that could be checked offline.
	 * 試行に保存し、パスワードの変更に気付くための、パスワードハッシュの指紋を返す（7.4-3）。
	 * HMAC にして、ハッシュから導いた値をそのまま行に持たないようにする。
	 *
	 * @param int    $user_id   User ID. / ユーザー ID。
	 * @param string $user_pass Stored password hash. / 保存されているパスワードハッシュ。
	 * @param string $key       HMAC key. / HMAC の鍵。
	 * @return string Hex HMAC. / 16進の HMAC。
	 */
	public static function password_fingerprint( $user_id, $user_pass, $key ) {
		return hash_hmac( 'sha256', 'acgd-2s-fp|' . (int) $user_id . '|' . $user_pass, $key );
	}

	/**
	 * Normalizes a typed code (7.5): full-width digits become ASCII, white space and hyphens are removed.
	 * Returns null when the result is not exactly six ASCII digits; such input is a format error and is not
	 * counted as a wrong code. / 入力されたコードを正規化する（7.5）。全角数字を半角に直し、空白とハイフンを
	 * 除く。結果がちょうど6桁の数字でなければ null を返す（形式のエラーで、誤りには数えない）。
	 *
	 * The long vowel mark (ー) is removed too: the hyphen key of a Japanese input method types it.
	 * 長音記号（ー）も除く。日本語入力でハイフンのキーを押すとこれが入るため。
	 *
	 * @param mixed $raw Typed value. / 入力値。
	 * @return string|null Six digits, or null. / 数字6桁、または null。
	 */
	public static function normalize_code_input( $raw ) {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$map = array();
		// Full-width digits U+FF10 to U+FF19. / 全角数字 U+FF10〜U+FF19。
		$full_width = array( "\u{FF10}", "\u{FF11}", "\u{FF12}", "\u{FF13}", "\u{FF14}", "\u{FF15}", "\u{FF16}", "\u{FF17}", "\u{FF18}", "\u{FF19}" );
		foreach ( $full_width as $digit => $char ) {
			$map[ $char ] = (string) $digit;
		}
		// Removed: ideographic space, no-break space, hyphens and dashes, and the long vowel mark.
		// 除くもの：全角スペース・ノーブレークスペース・ハイフンとダッシュ・長音記号。
		$removed = array( "\u{3000}", "\u{00A0}", "\u{FF0D}", "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}", "\u{30FC}", '-' );
		foreach ( $removed as $char ) {
			$map[ $char ] = '';
		}

		$code = strtr( $raw, $map );
		$code = preg_replace( '/\s+/', '', $code );

		return ( is_string( $code ) && preg_match( '/\A[0-9]{' . self::CODE_DIGITS . '}\z/', $code ) ) ? $code : null;
	}

	/**
	 * Decides whether one more email may be sent, from the stored send record (7.5). Pure.
	 * 保存済みの送信の記録から、もう1通送ってよいかを判定する（7.5）。副作用なし。
	 *
	 * The record is "window start:count in window:last send". A missing or unreadable record starts a new
	 * window. The 60-second gap is checked first, then the per-hour count.
	 * 記録は「窓の開始:窓の中の回数:最後の送信」。無い・読めない記録は新しい窓から始める。
	 * 60秒の間隔を先に、1時間あたりの回数を後に見る。
	 *
	 * @param string|null $stored Stored value, or null. / 保存値、または null。
	 * @param int         $now    Current time. / 現在時刻。
	 * @return array {
	 *     @type bool   $ok       Whether one more may be sent. / もう1通送ってよいか。
	 *     @type string $reason   '' when ok, 'gap' or 'window' otherwise. / 送れない理由（'gap' か 'window'）。
	 *     @type int    $retry_at When sending becomes possible again (0 when ok). / 次に送れる時刻（送れるなら 0）。
	 *     @type string $value    New value to store when ok (empty otherwise). / 送れるときに保存する新しい値。
	 * }
	 */
	public static function compute_send_slot( $stored, $now ) {
		$now    = (int) $now;
		$record = self::parse_send_record( $stored );

		if ( null !== $record && $now < $record['last'] + self::SEND_GAP ) {
			return array(
				'ok'       => false,
				'reason'   => 'gap',
				'retry_at' => $record['last'] + self::SEND_GAP,
				'value'    => '',
			);
		}

		if ( null === $record || $now >= $record['start'] + self::SEND_WINDOW ) {
			// A new window. / 新しい窓。
			return array(
				'ok'       => true,
				'reason'   => '',
				'retry_at' => 0,
				'value'    => $now . ':1:' . $now,
			);
		}

		if ( $record['count'] >= self::SEND_MAX ) {
			return array(
				'ok'       => false,
				'reason'   => 'window',
				'retry_at' => $record['start'] + self::SEND_WINDOW,
				'value'    => '',
			);
		}

		return array(
			'ok'       => true,
			'reason'   => '',
			'retry_at' => 0,
			'value'    => $record['start'] . ':' . ( $record['count'] + 1 ) . ':' . $now,
		);
	}

	/**
	 * Reads a send record "start:count:last" into integers, or null when missing or unreadable.
	 * 送信の記録「開始:回数:最後」を整数に読む。無い・読めなければ null。
	 *
	 * @param string|null $stored Stored value. / 保存値。
	 * @return array|null { start, count, last } or null. / 整数の配列、または null。
	 */
	private static function parse_send_record( $stored ) {
		if ( ! is_string( $stored ) || ! preg_match( '/\A([0-9]+):([0-9]+):([0-9]+)\z/', $stored, $m ) ) {
			return null;
		}

		return array(
			'start' => (int) $m[1],
			'count' => (int) $m[2],
			'last'  => (int) $m[3],
		);
	}

	/**
	 * Masks an email address for display on the code screen (7.10), such as t•••@example.com.
	 * コード入力画面に出すため、メールアドレスを伏せ字にする（7.10）。例 t•••@example.com。
	 *
	 * @param string $email Address. / アドレス。
	 * @return string Masked address, not escaped. / 伏せ字にしたアドレス（未エスケープ）。
	 */
	public static function mask_email( $email ) {
		$email = (string) $email;
		$at    = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return '•••';
		}

		$first = function_exists( 'mb_substr' ) ? mb_substr( $email, 0, 1, 'UTF-8' ) : substr( $email, 0, 1 );

		return $first . '•••' . substr( $email, $at );
	}

	/**
	 * Returns the hash stored for an email address in a receive check mark (7.3). Case and surrounding space
	 * are ignored, as WordPress compares addresses. / 受信確認の印に保存するメールアドレスのハッシュを返す
	 * （7.3）。大文字小文字と前後の空白は区別しない（WordPress のアドレスの比べ方と同じ）。
	 *
	 * @param string $email Address. / アドレス。
	 * @return string Hex hash. / 16進のハッシュ。
	 */
	public static function email_hash( $email ) {
		return hash( 'sha256', strtolower( trim( (string) $email ) ) );
	}

	/**
	 * Tells what kind of request a login is (7.4-2-7, 7.4-2-8). Pure.
	 * ログインがどの種類のリクエストかを返す（7.4-2-7・7.4-2-8）。副作用なし。
	 *
	 * 'interactive' only when all hold: secure_signon_cookie fired in this request for the same login name
	 * (the login came through wp_signon()), POST, not AJAX, not a JSON request, not REST, not XML-RPC, not cron,
	 * not WP-CLI, headers not sent yet. 'xmlrpc' for XML-RPC. Anything else is 'noninteractive'.
	 * 'interactive' は次のすべてを満たすときだけ：このリクエストで同じログイン名について secure_signon_cookie が
	 * 発火した（wp_signon() 経由）、POST、AJAX でない、JSON のリクエストでない、REST でない、XML-RPC でない、
	 * cron でない、WP-CLI でない、ヘッダーをまだ送っていない。XML-RPC は 'xmlrpc'。それ以外は 'noninteractive'。
	 *
	 * @param array $context {
	 *     @type string|null $signon_login Login name wp_signon() saw (sanitize_user() applied), or null. / wp_signon() が見たログイン名。
	 *     @type string      $username     Login name the authenticate filter got. / authenticate フィルタが受け取ったログイン名。
	 *     @type string      $method       Request method. / リクエストのメソッド。
	 *     @type bool        $ajax, $json, $rest, $xmlrpc, $cron, $cli, $headers_sent
	 * }
	 * @return string 'interactive', 'xmlrpc' or 'noninteractive'.
	 */
	public static function classify_request( $context ) {
		$flag = function ( $key ) use ( $context ) {
			return ! empty( $context[ $key ] );
		};

		if ( $flag( 'xmlrpc' ) ) {
			return 'xmlrpc';
		}

		$signon_matches = isset( $context['signon_login'], $context['username'] )
			&& is_string( $context['signon_login'] )
			&& '' !== $context['signon_login']
			&& $context['signon_login'] === (string) $context['username'];

		if ( $signon_matches
			&& isset( $context['method'] ) && 'POST' === $context['method']
			&& ! $flag( 'ajax' ) && ! $flag( 'json' ) && ! $flag( 'rest' )
			&& ! $flag( 'cron' ) && ! $flag( 'cli' ) && ! $flag( 'headers_sent' ) ) {
			return 'interactive';
		}

		return 'noninteractive';
	}

	/**
	 * Tells whether a wp_set_password call is core re-hashing the same password at login (7.8, 7.5), which
	 * must not clear anything. $old_user_data is the third argument of the action, which only exists from
	 * WordPress 6.7 on; without it (null) the call is a real change. / wp_set_password の呼び出しが、
	 * ログイン時に本体が同じパスワードでハッシュを作り直しただけかを返す（7.8・7.5）。そのときは何も消さない。
	 * $old_user_data はアクションの第3引数で WordPress 6.7 からしか無い。無い（null）なら本物の変更とみなす。
	 *
	 * @param string     $password      New plain password. / 新しい平文のパスワード。
	 * @param mixed|null $old_user_data User data before the change, or null. / 変更前のユーザーデータ、または null。
	 * @return bool Whether it is a re-hash of the same password. / 同じパスワードの作り直しか。
	 */
	public static function is_password_rehash( $password, $old_user_data ) {
		if ( ! is_object( $old_user_data ) || empty( $old_user_data->user_pass ) || ! is_string( $old_user_data->user_pass ) ) {
			return false;
		}
		if ( ! function_exists( 'wp_check_password' ) ) {
			return false;
		}

		$old_id = isset( $old_user_data->ID ) ? (int) $old_user_data->ID : '';

		return (bool) wp_check_password( (string) $password, $old_user_data->user_pass, $old_id );
	}

	/*-------------------------------------------*/
	/* Row storage with $wpdb (7.2) / $wpdb での行の読み書き（7.2）
	/*-------------------------------------------*/

	/*
	 * Every acgd_2s_* row is read and written here with $wpdb, never with get_option() / add_option() /
	 * delete_option() (7.2): counters and deletions happen in SQL, so an object cache would return stale
	 * values, and the attempt ID is chosen by the visitor, so reading unknown names through get_option()
	 * would grow the notoptions cache. INSERTs name autoload 'off' explicitly (the column default is 'yes';
	 * 'off' is also never autoloaded before WordPress 6.6, which loads only 'yes').
	 * acgd_2s_* の行はすべてここで $wpdb で読み書きし、get_option() / add_option() / delete_option() は使わない
	 * （7.2）。回数と削除を SQL で行うのでオブジェクトキャッシュは古い値を返し、また試行 ID は訪問者が決める値
	 * なので、知らない名前を get_option() で読むと notoptions のキャッシュが膨らむ。INSERT では autoload 列に
	 * 'off' を明示する（列の既定値は 'yes'。'off' は 'yes' だけを読む WordPress 6.6 より前でも読み込まれない）。
	 *
	 * phpcs: direct queries without caching are the point here (see above).
	 * phpcs：キャッシュを使わない直接のクエリであること自体が目的（上を参照）。
	 */

	/**
	 * Inserts a row, autoload 'off'. / 行を1つ作る（autoload 'off'）。
	 *
	 * @param string $name   Option name. / オプション名。
	 * @param string $value  Value. / 値。
	 * @param bool   $ignore Use INSERT IGNORE (keep an existing row). / INSERT IGNORE にする（既にある行は残す）。
	 * @return bool Whether a row was inserted. / 行を作ったか。
	 */
	private static function insert_row( $name, $value, $ignore = false ) {
		global $wpdb;

		$sql = $ignore
			? "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')"
			: "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- See the note above these helpers; $sql is one of two fixed strings and is prepared here.
		$result = $wpdb->query( $wpdb->prepare( $sql, $name, $value ) );

		return 1 === (int) $result;
	}

	/**
	 * Reads a row's value, or null when there is no such row. / 行の値を読む。無ければ null。
	 *
	 * @param string $name Option name. / オプション名。
	 * @return string|null Value, or null. / 値、または null。
	 */
	private static function get_row( $name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See the note above these helpers.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Deletes a row, optionally only while it still holds a given value. / 行を消す。値を与えれば、まだその値のときだけ消す。
	 *
	 * @param string      $name     Option name. / オプション名。
	 * @param string|null $expected Value the row must still hold, or null for any. / 行がまだ持っているべき値。null なら問わない。
	 * @return int Rows deleted. / 消した行数。
	 */
	private static function delete_row( $name, $expected = null ) {
		global $wpdb;

		if ( null === $expected ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See the note above these helpers.
			return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See the note above these helpers.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $expected ) );
	}

	/**
	 * Replaces a row's value only while it still holds the value read before ("値ごと差し替え", 7.5).
	 * 行の値を、先に読んだ値のままのときだけ差し替える（7.5「値ごと差し替え」）。
	 *
	 * @param string $name     Option name. / オプション名。
	 * @param string $expected Value read before. / 先に読んだ値。
	 * @param string $value    New value. / 新しい値。
	 * @return bool Whether this request won (exactly one row changed). / このリクエストが取れたか（ちょうど1行変わったか）。
	 */
	private static function compare_and_swap( $name, $expected, $value ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See the note above these helpers.
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $expected ) );

		return 1 === (int) $result;
	}

	/**
	 * Adds one to a wrong-code counter, only while it is below MAX_WRONG, in one statement (7.5). Called
	 * before the code is compared: read, add and write in PHP would let parallel requests go past the limit.
	 * 誤りの回数を、MAX_WRONG 未満のときだけ1つの文で1増やす（7.5）。コードを比べる前に呼ぶ。PHP で読む→
	 * 足す→書くにすると、並列のリクエストで上限を超えて試せる。
	 *
	 * @param string $name Counter row name. / 回数の行の名前。
	 * @return bool Whether this request got a try (exactly one row changed). / このリクエストが1回分を得たか。
	 */
	private static function take_try( $name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See the note above these helpers.
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $name, self::MAX_WRONG ) );

		return 1 === (int) $result;
	}

	/**
	 * Returns the rows of this feature (name => value), at most $limit. / この機能の行を返す（名前 => 値。最大 $limit 件）。
	 *
	 * @param int $limit Maximum rows. / 最大行数。
	 * @return string[] Name => value. / 名前 => 値。
	 */
	private static function get_all_rows( $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See the note above these helpers.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d", $wpdb->esc_like( self::ROW_PREFIX ) . '%', (int) $limit ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['option_name'] ] = (string) $row['option_value'];
		}

		return $out;
	}

	/**
	 * Row names. / 行の名前。
	 *
	 * @param string $id_hash sha256 of an attempt ID or receive check ID. / 試行 ID・受信確認 ID の sha256。
	 * @return string Name. / 名前。
	 */
	private static function attempt_row( $id_hash ) {
		return self::ROW_PREFIX . $id_hash;
	}

	/**
	 * Name of the wrong-code counter row of an attempt or a receive check. / 試行・受信確認の誤りの回数の行の名前。
	 *
	 * @param string $id_hash sha256 of the ID. / ID の sha256。
	 * @return string Name. / 名前。
	 */
	private static function counter_row( $id_hash ) {
		return self::ROW_PREFIX . 'n_' . $id_hash;
	}

	/**
	 * Name of the send record row of a user. / ユーザーの送信の記録の行の名前。
	 *
	 * @param int $user_id User ID. / ユーザー ID。
	 * @return string Name. / 名前。
	 */
	private static function send_row( $user_id ) {
		return self::ROW_PREFIX . 'send_' . (int) $user_id;
	}

	/**
	 * Name of the receive check row of a user. / ユーザーの受信確認の行の名前。
	 *
	 * @param int $user_id User ID. / ユーザー ID。
	 * @return string Name. / 名前。
	 */
	private static function receive_check_row( $user_id ) {
		return self::ROW_PREFIX . 'verified_' . (int) $user_id;
	}

	/**
	 * Decodes a JSON row value into an array, or null. JSON rather than serialize(): nothing in these rows is
	 * ever turned back into an object. / JSON の行の値を配列に戻す（できなければ null）。serialize() ではなく
	 * JSON にするのは、これらの行からオブジェクトを復元することが無いようにするため。
	 *
	 * @param string|null $value Row value. / 行の値。
	 * @return array|null Decoded value. / 戻した値。
	 */
	private static function decode( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/*-------------------------------------------*/
	/* Send limit (7.5) / 送信の上限（7.5）
	/*-------------------------------------------*/

	/**
	 * Reserves one email for a user, atomically ("値ごと差し替え", 7.5): create the row if missing with
	 * INSERT IGNORE, read it, decide in PHP (compute_send_slot()), then UPDATE only while it still holds what
	 * was read. Losing that race re-reads a few times; still losing counts as the limit.
	 * ユーザーに1通分を原子的に確保する（7.5「値ごと差し替え」）。無ければ INSERT IGNORE で作り、読み、
	 * PHP で判定し（compute_send_slot()）、読んだ値のままのときだけ UPDATE する。先を越されたら数回読み直し、
	 * それでも取れなければ上限扱い。
	 *
	 * @param int $user_id User ID. / ユーザー ID。
	 * @return array Same shape as compute_send_slot(). / compute_send_slot() と同じ形。
	 */
	public static function reserve_send_slot( $user_id ) {
		$name = self::send_row( $user_id );
		self::insert_row( $name, '', true );

		for ( $i = 0; $i < self::SEND_RETRIES; $i++ ) {
			$current = self::get_row( $name );
			$current = null === $current ? '' : $current;
			$slot    = self::compute_send_slot( $current, time() );
			if ( ! $slot['ok'] ) {
				return $slot;
			}
			if ( self::compare_and_swap( $name, $current, $slot['value'] ) ) {
				return $slot;
			}
		}

		return array(
			'ok'       => false,
			'reason'   => 'window',
			'retry_at' => time() + self::SEND_GAP,
			'value'    => '',
		);
	}

	/*-------------------------------------------*/
	/* Attempts (7.4) / 試行（7.4）
	/*-------------------------------------------*/

	/**
	 * Reads the attempt ID from the attempt cookie, or '' when missing or not 64 hex characters.
	 * 試行の Cookie から試行 ID を読む。無い・16進64文字でなければ ''。
	 *
	 * @return string Attempt ID. / 試行 ID。
	 */
	private static function read_attempt_cookie() {
		$name = self::cookie_name();
		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return '';
		}
		$value = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );

		return preg_match( '/\A[0-9a-f]{64}\z/', $value ) ? $value : '';
	}

	/**
	 * Name of the attempt cookie (per site, like core's own cookies). / 試行の Cookie の名前（本体の Cookie と同じくサイトごと）。
	 *
	 * @return string Name. / 名前。
	 */
	private static function cookie_name() {
		return 'acgd_2s_' . ( defined( 'COOKIEHASH' ) ? COOKIEHASH : md5( home_url() ) );
	}

	/**
	 * Sets (or, with an empty value, clears) the attempt cookie: HttpOnly, Secure on HTTPS, SameSite=Lax, on
	 * COOKIEPATH and also on SITECOOKIEPATH when it differs, like core's LOGGED_IN cookie (7.4-4).
	 * 試行の Cookie を出す（値が空なら消す）。HttpOnly・HTTPS なら Secure・SameSite=Lax。path は COOKIEPATH で、
	 * SITECOOKIEPATH が違う構成では両方に出す（本体の LOGGED_IN と同じ。7.4-4）。
	 *
	 * @param string $value   Attempt ID, or '' to clear. / 試行 ID。消すときは ''。
	 * @param int    $expires Expiry (ignored when clearing). / 期限（消すときは無視）。
	 * @return void
	 */
	private static function set_attempt_cookie( $value, $expires ) {
		if ( headers_sent() ) {
			return;
		}

		$paths = array( COOKIEPATH );
		if ( SITECOOKIEPATH !== COOKIEPATH ) {
			$paths[] = SITECOOKIEPATH;
		}

		foreach ( $paths as $path ) {
			// The options array form of setcookie() needs PHP 7.3, the declared minimum.
			// setcookie() のオプション配列の形は PHP 7.3 から。宣言した下限と同じ。
			setcookie(
				self::cookie_name(),
				$value,
				array(
					'expires'  => '' === $value ? time() - YEAR_IN_SECONDS : (int) $expires,
					'path'     => $path,
					'domain'   => (string) COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
	}

	/**
	 * Loads an attempt by its ID. / 試行 ID から試行を読む。
	 *
	 * @param string $attempt_id Attempt ID. / 試行 ID。
	 * @return array|null Attempt, or null. / 試行、または null。
	 */
	private static function load_attempt( $attempt_id ) {
		if ( '' === $attempt_id ) {
			return null;
		}
		$attempt = self::decode( self::get_row( self::attempt_row( hash( 'sha256', $attempt_id ) ) ) );
		if ( null === $attempt || empty( $attempt['uid'] ) || ! isset( $attempt['exp'], $attempt['hmac'], $attempt['code_exp'] ) ) {
			return null;
		}

		return $attempt;
	}

	/**
	 * Deletes an attempt and its counter. / 試行とその回数の行を消す。
	 *
	 * @param string $attempt_id Attempt ID. / 試行 ID。
	 * @return int Rows deleted from the attempt row itself (1 when this request removed it). / 試行の行から消した行数（このリクエストが消したなら 1）。
	 */
	private static function delete_attempt( $attempt_id ) {
		$hash    = hash( 'sha256', $attempt_id );
		$deleted = self::delete_row( self::attempt_row( $hash ) );
		self::delete_row( self::counter_row( $hash ) );

		return $deleted;
	}

	/**
	 * Creates an attempt and sends its code (7.4-2-7, 7.5). The counter row is created before the attempt row,
	 * so an attempt is never seen without its counter. On a failed email the rows are deleted again (7.11).
	 * 試行を作ってコードを送る（7.4-2-7・7.5）。回数の行を試行の行より先に作り、回数の行の無い試行が
	 * 見えることが無いようにする。メールを送れなければ行を消す（7.11）。
	 *
	 * @param WP_User $user    User. / ユーザー。
	 * @param array   $context Values carried from the password entry (see build_attempt_context()). / パスワード入力から持ち越す値。
	 * @param int     $expires Attempt expiry (kept across resends). / 試行の期限（再送しても変えない）。
	 * @return array { @type string $id Attempt ID ('' on failure). @type bool $mail_failed Whether the email failed. }
	 */
	private static function issue_attempt( $user, $context, $expires ) {
		$attempt_id = bin2hex( random_bytes( 32 ) );
		$hash       = hash( 'sha256', $attempt_id );
		$code       = self::generate_code();
		$now        = time();

		$attempt = array_merge(
			$context,
			array(
				'uid'      => (int) $user->ID,
				'hmac'     => self::code_hmac( $user->ID, $attempt_id, $code, wp_salt( 'auth' ) ),
				'code_exp' => $now + self::CODE_TTL,
				'sent'     => $now,
				'exp'      => (int) $expires,
			)
		);

		if ( ! self::insert_row( self::counter_row( $hash ), '0' ) || ! self::insert_row( self::attempt_row( $hash ), (string) wp_json_encode( $attempt ) ) ) {
			self::delete_attempt( $attempt_id );
			throw new RuntimeException( 'Could not store the attempt.' );
		}

		if ( ! self::send_login_code_mail( $user, $code, $attempt['code_exp'], $attempt['ip'] ) ) {
			self::delete_attempt( $attempt_id );
			return array(
				'id'          => '',
				'mail_failed' => true,
			);
		}

		return array(
			'id'          => $attempt_id,
			'mail_failed' => false,
		);
	}

	/**
	 * Deletes expired rows of this feature, as a side job of creating a new attempt (7.2; no cron). Each row is
	 * judged by the times inside it, never by its presence alone. / 新しい試行を作るついでに、この機能の
	 * 期限切れの行を消す（7.2。cron は置かない）。行の有無ではなく、中身の時刻で判定する。
	 *
	 * @return void
	 */
	private static function cleanup_expired_rows() {
		$now  = time();
		$rows = self::get_all_rows( self::CLEANUP_LIMIT );

		foreach ( $rows as $name => $value ) {
			$rest = substr( $name, strlen( self::ROW_PREFIX ) );

			if ( preg_match( '/\A[0-9a-f]{64}\z/', $rest ) ) {
				// An attempt. / 試行。
				$attempt = self::decode( $value );
				if ( null === $attempt || ! isset( $attempt['exp'] ) || (int) $attempt['exp'] <= $now ) {
					if ( self::delete_row( $name, $value ) ) {
						self::delete_row( self::counter_row( $rest ) );
					}
				}
			} elseif ( 0 === strpos( $rest, 'send_' ) ) {
				$record = self::parse_send_record( $value );
				if ( null === $record || ( $now >= $record['start'] + self::SEND_WINDOW && $now >= $record['last'] + self::SEND_GAP ) ) {
					self::delete_row( $name, $value );
				}
			} elseif ( 0 === strpos( $rest, 'verified_' ) ) {
				$check = self::decode( $value );
				$until = null === $check ? 0 : ( isset( $check['exp'] ) ? (int) $check['exp'] : ( isset( $check['code_exp'] ) ? (int) $check['code_exp'] : 0 ) );
				if ( $until <= $now && self::delete_row( $name, $value ) && null !== $check && ! empty( $check['rid'] ) ) {
					self::delete_row( self::counter_row( (string) $check['rid'] ) );
				}
			}
		}
	}

	/**
	 * Deletes a user's attempts and send record (7.5: a password change releases the send limit an attacker
	 * may have used up). The receive check mark is not touched. / ユーザーの試行と送信の記録を消す（7.5：
	 * 攻撃者に送信上限を使い切られても、パスワードを変えれば入れる）。受信確認の印には触れない。
	 *
	 * @param int $user_id User ID. / ユーザー ID。
	 * @return void
	 */
	public static function clear_user_records( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return;
		}

		self::delete_row( self::send_row( $user_id ) );

		foreach ( self::get_all_rows( self::CLEANUP_LIMIT ) as $name => $value ) {
			$rest = substr( $name, strlen( self::ROW_PREFIX ) );
			if ( ! preg_match( '/\A[0-9a-f]{64}\z/', $rest ) ) {
				continue;
			}
			$attempt = self::decode( $value );
			if ( null !== $attempt && isset( $attempt['uid'] ) && (int) $attempt['uid'] === $user_id && self::delete_row( $name, $value ) ) {
				self::delete_row( self::counter_row( $rest ) );
			}
		}
	}

	/*-------------------------------------------*/
	/* Login flow (7.4, 7.7) / ログインの流れ（7.4・7.7）
	/*-------------------------------------------*/

	/**
	 * Remembers what wp_signon() is about to authenticate (7.4-2-7, 7.4-3): the login name, and the remember
	 * and secure cookie choices from $credentials, not from $_POST (WooCommerce passes them as arguments).
	 * Returns the value unchanged.
	 * wp_signon() がこれから認証するものを控える（7.4-2-7・7.4-3）。ログイン名と、「ログイン状態を保存」・
	 * secure cookie の選択を、$_POST ではなく $credentials から取る（WooCommerce は引数で渡すため）。値は変えない。
	 *
	 * @param bool  $secure_cookie Whether a secure cookie will be used. / secure cookie を使うか。
	 * @param array $credentials   Credentials given to wp_signon(). / wp_signon() に渡された資格情報。
	 * @return bool The same value. / 同じ値。
	 */
	public static function capture_signon( $secure_cookie, $credentials = array() ) {
		$credentials  = is_array( $credentials ) ? $credentials : array();
		self::$signon = array(
			'user_login' => isset( $credentials['user_login'] ) ? (string) $credentials['user_login'] : '',
			'remember'   => ! empty( $credentials['remember'] ),
			'secure'     => (bool) $secure_cookie,
		);

		return $secure_cookie;
	}

	/**
	 * Remembers that an application password authenticated a user in this request (7.4-2-3, 7.6).
	 * このリクエストでアプリケーションパスワードがユーザーを認証したことを控える（7.4-2-3・7.6）。
	 *
	 * @param WP_User $user User. / ユーザー。
	 * @return void
	 */
	public static function capture_application_password( $user ) {
		if ( $user instanceof WP_User ) {
			self::$app_password_user_id = (int) $user->ID;
		}
	}

	/**
	 * The second step, at the very end of authenticate (7.4-2). See init() for why this priority.
	 * authenticate の最後尾での2段階目（7.4-2）。この優先度の理由は init() を参照。
	 *
	 * @param WP_User|WP_Error|null $user     Result so far. / それまでの結果。
	 * @param string                $username Login name (after sanitize_user()). / ログイン名（sanitize_user() 済み）。
	 * @param string                $password Password (after trim()). / パスワード（trim() 済み）。
	 * @return WP_User|WP_Error|null The result. / 結果。
	 */
	public static function filter_authenticate( $user, $username = '', $password = '' ) {
		// 2-1: only a login every earlier gate accepted. / 2-1：それまでの関門が受け入れたログインだけ。
		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		// 2-2: the emergency switch (multisite never registers this filter). / 2-2：非常用スイッチ（マルチサイトではこのフィルタ自体を登録しない）。
		if ( self::is_switch_disabled() ) {
			return $user;
		}

		try {
			$target = self::is_target( $user );
		} catch ( Throwable $e ) {
			self::record_fault( $e->getMessage() );
			$target = self::is_target_narrow( $user );
		}
		if ( ! $target ) {
			return $user;
		}

		$interactive = false;
		try {
			// 2-3: an application password for the same user (7.7). / 2-3：同じユーザーのアプリケーションパスワード（7.7）。
			if ( self::$app_password_user_id === (int) $user->ID ) {
				if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
					return $user;
				}
				// Outside XML-RPC and REST (application_password_is_api_request changed, for example): no session (7.7).
				// XML-RPC・REST 以外（application_password_is_api_request を変えられた等）：セッションを作らせない（7.7）。
				ACGD_Access_Restriction::log_denial( $user->ID, ACGD_Access_Restriction::get_remote_addr(), 'two_step_noninteractive' );
				return ACGD_Invalid_Credentials::get_login_error();
			}

			// 2-4: already signed in as the same user with a marked session (a password re-check inside it).
			// 2-4：同じユーザーの印付きセッションでログイン済み（その中でのパスワードの再確認）。
			if ( self::request_has_marked_session( $user->ID, true ) ) {
				return $user;
			}

			// 2-5: no username and no password, and a valid auth cookie of the same user: wp-login.php opened
			// with GET calls wp_signon( array() ) and wp_authenticate_cookie() returns this WP_User. Never "an
			// empty password is enough" on its own.
			// 2-5：ユーザー名もパスワードも空で、同じユーザーの有効な auth Cookie がある：wp-login.php を GET で
			// 開くと本体が wp_signon( array() ) を呼び、wp_authenticate_cookie() がこの WP_User を返す。
			// 「パスワードが空なら通す」にはしない。
			if ( '' === (string) $username && '' === (string) $password && self::valid_auth_cookie_user_id() === (int) $user->ID ) {
				return $user;
			}

			// 2-6: a trusted device (7.8, PR-C). / 2-6：信頼した端末（7.8。PR-C）。
			if ( self::is_trusted_device( $user ) ) {
				self::$mark_user_id = (int) $user->ID;
				return $user;
			}

			$kind        = self::classify_request( self::request_context( $username ) );
			$interactive = ( 'interactive' === $kind );

			// 2-7: a form a person is looking at: send the code and move to the code screen. Exits.
			// 2-7：人が見ているフォーム：コードを送ってコード入力画面へ移る。exit する。
			if ( $interactive ) {
				self::start_interactive( $user );
			}

			// 2-8: anything else. The common error, so the screen never tells whether the password was right.
			// 2-8：それ以外。共通のエラーにし、パスワードの正否を画面から分からないようにする。
			$remote = ACGD_Access_Restriction::get_remote_addr();
			if ( 'xmlrpc' === $kind ) {
				// No email: nobody is watching this route, and repeated password tries would flood the inbox.
				// 通知しない：人が見ていない経路で、パスワードの試しが続くとメールが溢れるため。
				ACGD_Access_Restriction::log_denial( $user->ID, $remote, 'two_step_xmlrpc' );
			} else {
				ACGD_Access_Restriction::log_denial( $user->ID, $remote, 'two_step_noninteractive' );
				$slot = self::reserve_send_slot( $user->ID );
				if ( $slot['ok'] ) {
					self::send_notice_mail( $user, $remote );
				}
			}

			return ACGD_Invalid_Credentials::get_login_error();
		} catch ( Throwable $e ) {
			// Fail closed (7.11). / 閉じる（7.11）。
			self::record_fault( $e->getMessage() );

			return $interactive ? self::get_problem_error() : ACGD_Invalid_Credentials::get_login_error();
		}
	}

	/**
	 * The login error for a fault on a screen a person is looking at (7.11). / 人が見ている画面での故障のエラー（7.11）。
	 *
	 * @return WP_Error Error. / エラー。
	 */
	private static function get_problem_error() {
		return new WP_Error(
			'acgd_two_step_problem',
			sprintf(
				'<strong>%1$s</strong> %2$s',
				esc_html__( 'Error:', 'etbs-account-guard' ),
				esc_html( self::get_problem_message() )
			)
		);
	}

	/**
	 * Sentence shown when the verification itself failed (7.11). / 確認そのものに問題が起きたときの文（7.11）。
	 *
	 * @return string Text, not escaped. / 文（未エスケープ）。
	 */
	private static function get_problem_message() {
		return acgd_join_sentences( array( __( 'A problem occurred while confirming your sign-in.', 'etbs-account-guard' ), __( 'Please contact your site administrator.', 'etbs-account-guard' ) ) );
	}

	/**
	 * Collects the request values classify_request() looks at. / classify_request() が見るリクエストの値を集める。
	 *
	 * @param string $username Login name the authenticate filter got. / authenticate フィルタが受け取ったログイン名。
	 * @return array Context. / 文脈。
	 */
	private static function request_context( $username ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		return array(
			// wp_authenticate() applies sanitize_user() before the authenticate filter; do the same to compare.
			// wp_authenticate() は authenticate フィルタの前に sanitize_user() を掛ける。比べるため同じ処理を掛ける。
			'signon_login' => null === self::$signon ? null : sanitize_user( self::$signon['user_login'] ),
			'username'     => (string) $username,
			'method'       => $method,
			'ajax'         => wp_doing_ajax(),
			'json'         => function_exists( 'wp_is_json_request' ) && wp_is_json_request(),
			'rest'         => defined( 'REST_REQUEST' ) && REST_REQUEST,
			'xmlrpc'       => defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
			'cron'         => wp_doing_cron(),
			'cli'          => defined( 'WP_CLI' ) && WP_CLI,
			'headers_sent' => headers_sent(),
		);
	}

	/**
	 * Returns the ID of the user whose auth cookie (either scheme) is valid in this request, or 0.
	 * このリクエストで有効な auth Cookie（どちらの方式でも）のユーザー ID を返す。無ければ 0。
	 *
	 * @return int User ID. / ユーザー ID。
	 */
	private static function valid_auth_cookie_user_id() {
		$user_id = wp_validate_auth_cookie( '', 'secure_auth' );
		if ( ! $user_id ) {
			$user_id = wp_validate_auth_cookie( '', 'auth' );
		}

		return (int) $user_id;
	}

	/**
	 * Place of the trusted device check in the login flow (7.4-2-6). Trusted devices arrive with PR-C (7.8);
	 * until then no device is trusted.
	 * ログインの流れの中の、信頼した端末の確認の場所（7.4-2-6）。信頼した端末は PR-C（7.8）で入る。
	 * それまではどの端末も信頼しない。
	 *
	 * @param WP_User $user User. / ユーザー。
	 * @return bool Whether this device is trusted for the user. / この端末がそのユーザーに信頼されているか。
	 */
	private static function is_trusted_device( $user ) {
		unset( $user ); // Used by PR-C. / PR-C で使う。
		return false;
	}

	/**
	 * Starts the second step for a form a person is looking at (7.4-2-7): reserve an email, create the attempt,
	 * send the code, set the cookie and move to the code screen. No login cookie is issued. Always exits.
	 * 人が見ているフォームで2段階目を始める（7.4-2-7）。1通分を確保し、試行を作り、コードを送り、Cookie を出して
	 * コード入力画面へ移る。ログインの Cookie は出さない。必ず exit する。
	 *
	 * @param WP_User $user User whose password was accepted. / パスワードが合ったユーザー。
	 * @return void
	 */
	private static function start_interactive( $user ) {
		$context = self::build_attempt_context( $user );
		$extra   = self::screen_args( $context );

		// Reserve first; without a slot, no attempt is created (7.4-2-7). / 先に確保する。取れなければ試行を作らない（7.4-2-7）。
		$slot = self::reserve_send_slot( $user->ID );
		if ( ! $slot['ok'] ) {
			ACGD_Access_Restriction::log_denial( $user->ID, $context['ip'], 'two_step_limit' );
			self::redirect_to_screen( array_merge( $extra, array( 'acgd_limit' => (int) $slot['retry_at'] ) ) );
		}

		self::cleanup_expired_rows();

		$result = self::issue_attempt( $user, $context, time() + self::ATTEMPT_TTL );
		if ( $result['mail_failed'] ) {
			ACGD_Access_Restriction::log_denial( $user->ID, $context['ip'], 'two_step_mail_failed' );
			self::redirect_to_screen( array_merge( $extra, array( 'acgd_mail_failed' => 1 ) ) );
		}

		self::set_attempt_cookie( $result['id'], time() + self::ATTEMPT_TTL );
		self::redirect_to_screen( $extra );
	}

	/**
	 * Collects what the attempt must carry from the password entry (7.4-3). Later requests' values are never
	 * trusted for these. / パスワード入力から試行に持たせるものを集める（7.4-3）。これらについて後続の
	 * リクエストの値は信用しない。
	 *
	 * Where the login came from decides how it finishes (7.4-5): 'login' for wp-login.php, 'other' for any
	 * other form (WooCommerce My Account, for example), whose destination is decided here the way that form
	 * would decide it after wp_signon() returned.
	 * どこから来たかで終わり方が決まる（7.4-5）：wp-login.php は 'login'、他のフォーム（WooCommerce の
	 * マイアカウントなど）は 'other'。後者の行き先は、wp_signon() が戻った後にそのフォームが決めるのと同じ
	 * 方法でここで決める。
	 *
	 * @param WP_User $user User. / ユーザー。
	 * @return array Context stored in the attempt. / 試行に保存する文脈。
	 */
	private static function build_attempt_context( $user ) {
		// phpcs:disable WordPress.Security.NonceVerification -- Read while the login form's own request is being processed: wp-login.php has no nonce, and WooCommerce verified its own before calling wp_signon(). The values are only stored and replayed through wp_safe_redirect() / wp_validate_redirect().
		$origin   = ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) ? 'login' : 'other';
		$redirect = '';

		if ( 'login' === $origin ) {
			// The requested redirect_to, as wp-login.php reads it (wp-login.php: $requested_redirect_to).
			// wp-login.php と同じく、要求された redirect_to（wp-login.php の $requested_redirect_to）。
			$redirect = ( isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		} elseif ( isset( $_POST['woocommerce-login-nonce'] ) && function_exists( 'wc_get_page_permalink' ) ) {
			// WooCommerce's WC_Form_Handler::process_login(): 'redirect', else the referer, else My Account,
			// through the woocommerce_login_redirect filter and wp_validate_redirect().
			// WooCommerce の WC_Form_Handler::process_login() と同じ：'redirect'、無ければリファラー、無ければ
			// マイアカウント。woocommerce_login_redirect フィルタと wp_validate_redirect() を通す。
			$my_account = wc_get_page_permalink( 'myaccount' );
			if ( ! empty( $_POST['redirect'] ) && is_string( $_POST['redirect'] ) ) {
				$redirect = esc_url_raw( wp_unslash( $_POST['redirect'] ) );
			} elseif ( wp_get_raw_referer() ) {
				$redirect = wp_get_raw_referer();
			} else {
				$redirect = $my_account;
			}
			$redirect = wp_validate_redirect( apply_filters( 'woocommerce_login_redirect', remove_query_arg( 'wc_error', $redirect ), $user ), $my_account ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied as WooCommerce applies it.
		} else {
			$raw      = ( isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : (string) wp_get_raw_referer();
			$redirect = wp_validate_redirect( $raw, home_url( '/' ) );
		}

		$context = array(
			'origin'    => $origin,
			'redirect'  => substr( (string) $redirect, 0, 2048 ),
			'interim'   => isset( $_REQUEST['interim-login'] ),
			'customize' => isset( $_REQUEST['customize-login'] ),
			'remember'  => null !== self::$signon && self::$signon['remember'],
			'secure'    => null !== self::$signon && self::$signon['secure'],
			'ip'        => (string) ACGD_Access_Restriction::get_remote_addr(),
			// From get_userdata(), not $user: core re-hashes after the check and returns the old object (7.4-3).
			// $user ではなく get_userdata() から：本体は照合後にハッシュを作り直し、古いオブジェクトを返す（7.4-3）。
			'fp'        => self::current_fingerprint( $user->ID ),
		);
		// phpcs:enable

		return $context;
	}

	/**
	 * Returns the fingerprint of the password hash currently stored for a user, read again from the database
	 * (7.4-3). / ユーザーにいま保存されているパスワードハッシュの指紋を、DB から読み直して返す（7.4-3）。
	 *
	 * @param int $user_id User ID. / ユーザー ID。
	 * @return string Fingerprint, or '' when the user is gone. / 指紋。ユーザーがいなければ ''。
	 */
	private static function current_fingerprint( $user_id ) {
		clean_user_cache( (int) $user_id );
		$fresh = get_userdata( (int) $user_id );

		return $fresh ? self::password_fingerprint( $fresh->ID, $fresh->user_pass, wp_salt( 'auth' ) ) : '';
	}

	/**
	 * Query arguments the code screen must keep: interim-login and customize-login switch wp-login.php's own
	 * layout. / コード入力画面が保つべきクエリ引数。interim-login と customize-login は wp-login.php 自身の
	 * レイアウトを切り替える。
	 *
	 * @param array $attempt Attempt or context. / 試行または文脈。
	 * @return array Query arguments. / クエリ引数。
	 */
	private static function screen_args( $attempt ) {
		$args = array();
		if ( ! empty( $attempt['interim'] ) ) {
			$args['interim-login'] = 1;
		}
		if ( ! empty( $attempt['customize'] ) ) {
			$args['customize-login'] = 1;
		}

		return $args;
	}

	/**
	 * Redirects to the code screen and exits. Built on wp_login_url(), so a renamed login page (SiteGuard WP
	 * Plugin) is followed (7.4-6). The attempt ID is never in the URL (7.4-4).
	 * コード入力画面へ転送して exit する。wp_login_url() から作るので、変更したログインページ（SiteGuard WP
	 * Plugin）に従う（7.4-6）。試行 ID は URL に載せない（7.4-4）。
	 *
	 * @param array $args Query arguments. / クエリ引数。
	 * @return void
	 */
	private static function redirect_to_screen( $args ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'action' => self::ACTION_CODE ), $args ), wp_login_url() ) );
		exit;
	}

	/**
	 * URL a form on the code screen posts to (7.4-6: site_url( ..., 'login_post' )).
	 * コード入力画面のフォームの送信先（7.4-6：site_url( ..., 'login_post' )）。
	 *
	 * @param string $action ACTION_CODE or ACTION_RESEND.
	 * @param array  $args   Extra query arguments. / 追加のクエリ引数。
	 * @return string URL, not escaped. / URL（未エスケープ）。
	 */
	private static function form_url( $action, $args ) {
		return add_query_arg( $args, site_url( 'wp-login.php?action=' . $action, 'login_post' ) );
	}

	/**
	 * Nonce action of the code screen's forms, per attempt (7.10). / コード入力画面のフォームの nonce アクション（試行ごと。7.10）。
	 *
	 * @param string $attempt_id Attempt ID. / 試行 ID。
	 * @return string Action. / アクション。
	 */
	private static function code_nonce_action( $attempt_id ) {
		return 'acgd_code_' . substr( hash( 'sha256', $attempt_id ), 0, 16 );
	}

	/**
	 * Sends the no-cache and no-referrer headers of the code screen (7.10). / コード入力画面のキャッシュ禁止・リファラー無しのヘッダーを送る（7.10）。
	 *
	 * @return void
	 */
	private static function send_screen_headers() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // For page cache plugins that cache the login screen. / ログイン画面をキャッシュするプラグインへの対策。
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
		}
	}

	/**
	 * Checks the request of a code screen form: the nonce and the attempt ID in the hidden field, which must
	 * equal the cookie (7.4-4). Anything else accepts nothing. / コード入力画面のフォームのリクエストを確かめる。
	 * nonce と、hidden の試行 ID が Cookie と一致すること（7.4-4）。それ以外は何も受け付けない。
	 *
	 * @param string $attempt_id Attempt ID from the cookie. / Cookie の試行 ID。
	 * @return bool Whether valid. / 正しいか。
	 */
	private static function verify_form( $attempt_id ) {
		$posted = isset( $_POST[ self::ATTEMPT_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::ATTEMPT_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked right below; the hidden value is compared with hash_equals() only.
		$nonce  = isset( $_POST[ self::CODE_NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CODE_NONCE_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is the nonce itself.

		return '' !== $attempt_id
			&& '' !== $posted
			&& hash_equals( $attempt_id, $posted )
			&& wp_verify_nonce( $nonce, self::code_nonce_action( $attempt_id ) );
	}

	/**
	 * Handles wp-login.php?action=acgd_code: shows the code screen, and on POST checks the code (7.4, 7.5,
	 * 7.10). Always exits. / wp-login.php?action=acgd_code を処理する。コード入力画面を出し、POST なら
	 * コードを確かめる（7.4・7.5・7.10）。必ず exit する。
	 *
	 * @return void
	 */
	public static function handle_code_screen() {
		self::send_screen_headers();

		// Display-only flags from the redirects of this class; they carry no secret. / このクラスの転送が付ける表示用の目印。秘密は載っていない。
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$limit_until = isset( $_GET['acgd_limit'] ) ? absint( $_GET['acgd_limit'] ) : 0;
		$mail_failed = ! empty( $_GET['acgd_mail_failed'] );
		$resent      = ! empty( $_GET['acgd_resent'] );
		// phpcs:enable

		$attempt_id = self::read_attempt_cookie();

		if ( '' === $attempt_id ) {
			if ( $limit_until ) {
				self::render_message_screen( self::limit_message( $limit_until ), self::start_over_url( null ) );
			}
			if ( $mail_failed ) {
				self::render_message_screen( self::mail_failed_message(), self::start_over_url( null ) );
			}
			self::render_message_screen( acgd_join_sentences( array( __( 'This page needs cookies.', 'etbs-account-guard' ), __( 'Enable cookies in your browser and sign in again.', 'etbs-account-guard' ) ) ), self::start_over_url( null ) );
		}

		try {
			$attempt = self::load_attempt( $attempt_id );
			if ( null === $attempt || (int) $attempt['exp'] <= time() ) {
				if ( null !== $attempt ) {
					self::delete_attempt( $attempt_id );
				}
				self::set_attempt_cookie( '', 0 );
				self::render_message_screen( acgd_join_sentences( array( __( 'This sign-in can no longer be completed.', 'etbs-account-guard' ), __( 'Please sign in again.', 'etbs-account-guard' ) ) ), self::start_over_url( $attempt ) );
			}

			$errors  = new WP_Error();
			$invalid = false;
			$status  = $resent ? acgd_join_sentences( array( __( 'A new verification code has been sent.', 'etbs-account-guard' ), __( 'The previous code no longer works.', 'etbs-account-guard' ) ) ) : '';

			if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
				if ( ! self::verify_form( $attempt_id ) ) {
					self::render_message_screen( acgd_join_sentences( array( __( 'This request could not be accepted.', 'etbs-account-guard' ), __( 'Please sign in again.', 'etbs-account-guard' ) ) ), self::start_over_url( $attempt ) );
				}

				// "Start over": discard the attempt and go back (7.10). / 「最初からやり直す」：試行を捨てて戻る（7.10）。
				if ( isset( $_POST['acgd_start_over'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_form() above checked the nonce.
					self::delete_attempt( $attempt_id );
					self::set_attempt_cookie( '', 0 );
					wp_safe_redirect( self::start_over_url( $attempt ) );
					exit;
				}

				$typed  = isset( $_POST[ self::CODE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CODE_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_form() above checked the nonce.
				$result = self::check_code( $attempt_id, $attempt, $typed );
				if ( 'ok' === $result['status'] ) {
					self::complete_login( $attempt_id, $attempt );
				}
				if ( 'limit' === $result['status'] ) {
					self::render_message_screen( acgd_join_sentences( array( __( 'The code was entered incorrectly too many times.', 'etbs-account-guard' ), __( 'Please sign in again with your password.', 'etbs-account-guard' ) ) ), self::start_over_url( $attempt ) );
				}

				$invalid = true;
				$status  = '';
				$errors->add( 'acgd_code', self::code_error_message( $result ) );
			}

			self::render_code_screen( $attempt_id, $attempt, $errors, $invalid, $status );
		} catch ( Throwable $e ) {
			// Fail closed (7.11). / 閉じる（7.11）。
			self::record_fault( $e->getMessage() );
			self::render_message_screen( self::get_problem_message(), self::start_over_url( null ) );
		}
	}

	/**
	 * Checks a typed code against an attempt (7.5): expiry, format, one try taken atomically, then the HMAC.
	 * Records wrong, expired and limit in the denial log (7.13) and fires wp_login_failed for a wrong code.
	 * 入力されたコードを試行と照合する（7.5）。期限、形式、1回分を原子的に取ってから HMAC。誤り・期限切れ・
	 * 上限を拒否の記録に残し（7.13）、誤りでは wp_login_failed を発火させる。
	 *
	 * @param string $attempt_id Attempt ID. / 試行 ID。
	 * @param array  $attempt    Attempt. / 試行。
	 * @param string $typed      Typed code. / 入力されたコード。
	 * @return array { @type string $status 'ok', 'expired', 'format', 'wrong' or 'limit'. @type int $remaining Tries left. }
	 */
	private static function check_code( $attempt_id, $attempt, $typed ) {
		$user_id = (int) $attempt['uid'];
		$remote  = ACGD_Access_Restriction::get_remote_addr();

		if ( (int) $attempt['code_exp'] <= time() ) {
			ACGD_Access_Restriction::log_denial( $user_id, $remote, 'two_step_expired' );
			return array(
				'status'    => 'expired',
				'remaining' => 0,
			);
		}

		$code = self::normalize_code_input( $typed );
		if ( null === $code ) {
			return array(
				'status'    => 'format',
				'remaining' => 0,
			);
		}

		$hash = hash( 'sha256', $attempt_id );
		if ( ! self::take_try( self::counter_row( $hash ) ) ) {
			self::delete_attempt( $attempt_id );
			self::set_attempt_cookie( '', 0 );
			ACGD_Access_Restriction::log_denial( $user_id, $remote, 'two_step_limit' );
			return array(
				'status'    => 'limit',
				'remaining' => 0,
			);
		}

		if ( hash_equals( (string) $attempt['hmac'], self::code_hmac( $user_id, $attempt_id, $code, wp_salt( 'auth' ) ) ) ) {
			return array(
				'status'    => 'ok',
				'remaining' => 0,
			);
		}

		ACGD_Access_Restriction::log_denial( $user_id, $remote, 'two_step_wrong' );
		$user = get_userdata( $user_id );
		if ( $user ) {
			// Lets plugins that count failed logins count this one too (7.13). The password was already right,
			// so passing the login name does not collide with Login Name Protection.
			// 失敗したログインを数えるプラグインが数えられるようにする（7.13）。パスワードは合った後なので、
			// ログイン名を渡しても名前の保護とは衝突しない。
			do_action( 'wp_login_failed', $user->user_login, new WP_Error( 'acgd_two_step_wrong_code', __( 'The verification code is incorrect.', 'etbs-account-guard' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own action.
		}

		// A counter already gone means a parallel request used up the last try and discarded the attempt.
		// 回数の行がもう無いのは、並列のリクエストが最後の1回を使って試行を捨てた後だということ。
		$used      = self::get_row( self::counter_row( $hash ) );
		$remaining = null === $used ? 0 : max( 0, self::MAX_WRONG - (int) $used );
		if ( $remaining < 1 ) {
			self::delete_attempt( $attempt_id );
			self::set_attempt_cookie( '', 0 );
			ACGD_Access_Restriction::log_denial( $user_id, $remote, 'two_step_limit' );
			return array(
				'status'    => 'limit',
				'remaining' => 0,
			);
		}

		return array(
			'status'    => 'wrong',
			'remaining' => $remaining,
		);
	}

	/**
	 * Error sentence for a code check result. / コードの照合結果のエラー文。
	 *
	 * @param array $result Result of check_code(). / check_code() の結果。
	 * @return string Escaped HTML. / エスケープ済みの HTML。
	 */
	private static function code_error_message( $result ) {
		switch ( $result['status'] ) {
			case 'expired':
				$text = acgd_join_sentences( array( __( 'This verification code has expired.', 'etbs-account-guard' ), __( 'Send a new code from this screen.', 'etbs-account-guard' ) ) );
				break;
			case 'format':
				$text = __( 'Enter the 6-digit verification code from the email.', 'etbs-account-guard' );
				break;
			default:
				$text = self::wrong_code_message( (int) $result['remaining'] );
		}

		return sprintf( '<strong>%1$s</strong> %2$s', esc_html__( 'Error:', 'etbs-account-guard' ), esc_html( $text ) );
	}

	/**
	 * Sentence for the send limit, with the time sending becomes possible again and how to release it (7.10).
	 * 送信上限の文。再開の目安の時刻と解除の方法を添える（7.10）。
	 *
	 * @param int $retry_at When sending becomes possible again. / 次に送れる時刻。
	 * @return string Text, not escaped. / 文（未エスケープ）。
	 */
	private static function limit_message( $retry_at ) {
		return acgd_join_sentences(
			array(
				__( 'Too many verification codes have been sent.', 'etbs-account-guard' ),
				sprintf(
					/* translators: %s: date and time when a new code can be sent */
					__( 'You can sign in again after %s.', 'etbs-account-guard' ),
					ACGD_Time::format_local( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $retry_at )
				),
				acgd_join_sentences( array( __( 'Your account is not locked.', 'etbs-account-guard' ), __( 'Changing your password releases this limit.', 'etbs-account-guard' ) ) ),
			)
		);
	}

	/**
	 * Sentences for a failed email at login (7.11). / ログインでメールを送れなかったときの文（7.11）。
	 *
	 * Sentences are joined with acgd_join_sentences(), which only puts the language's separator between them
	 * (no space in Japanese), so plain text stays plain text; it is escaped where it is printed.
	 * 文は acgd_join_sentences() でつなぐ。言語ごとの区切り（日本語は空白なし）を挟むだけなので、テキストは
	 * テキストのままで、出力するところでエスケープする。
	 *
	 * @return string Text, not escaped. / 文（未エスケープ）。
	 */
	private static function mail_failed_message() {
		return acgd_join_sentences(
			array(
				__( 'The email with your verification code could not be sent, so you have not been signed in.', 'etbs-account-guard' ),
				__( 'Please try again later or contact your site administrator.', 'etbs-account-guard' ),
			)
		);
	}

	/**
	 * Sentences for a wrong code, with the tries left. / 誤ったコードの文。残りの回数を添える。
	 *
	 * @param int $remaining Tries left. / 残りの回数。
	 * @return string Text, not escaped. / 文（未エスケープ）。
	 */
	private static function wrong_code_message( $remaining ) {
		return acgd_join_sentences(
			array(
				__( 'The verification code is incorrect.', 'etbs-account-guard' ),
				sprintf(
					/* translators: %d: number of tries left */
					_n( 'You can try %d more time.', 'You can try %d more times.', (int) $remaining, 'etbs-account-guard' ),
					(int) $remaining
				),
			)
		);
	}

	/**
	 * Sentences for a send refused by the 60-second gap (7.5). / 60秒の間隔で送れなかったときの文（7.5）。
	 *
	 * @param int $retry_at When sending becomes possible again. / 次に送れる時刻。
	 * @return string Text, not escaped. / 文（未エスケープ）。
	 */
	private static function gap_message( $retry_at ) {
		return acgd_join_sentences(
			array(
				__( 'Please wait a moment before sending another code.', 'etbs-account-guard' ),
				sprintf(
					/* translators: %s: time (or date and time) when a new code can be sent */
					__( 'You can send a new code after %s.', 'etbs-account-guard' ),
					ACGD_Time::format_local( get_option( 'time_format' ), (int) $retry_at )
				),
			)
		);
	}

	/**
	 * URL of "start over": the login screen for a wp-login.php attempt, the saved destination otherwise.
	 * 「最初からやり直す」の行き先。wp-login.php の試行ならログイン画面、それ以外は保存した行き先。
	 *
	 * @param array|null $attempt Attempt, or null. / 試行、または null。
	 * @return string URL, not escaped. / URL（未エスケープ）。
	 */
	private static function start_over_url( $attempt ) {
		if ( is_array( $attempt ) && 'other' === ( isset( $attempt['origin'] ) ? $attempt['origin'] : '' ) && ! empty( $attempt['redirect'] ) ) {
			return wp_validate_redirect( (string) $attempt['redirect'], wp_login_url() );
		}
		$redirect = ( is_array( $attempt ) && ! empty( $attempt['redirect'] ) ) ? (string) $attempt['redirect'] : '';

		return add_query_arg( is_array( $attempt ) ? self::screen_args( $attempt ) : array(), wp_login_url( $redirect ) );
	}

	/**
	 * Prints a screen with one message and a link, then exits. / 文1つとリンクの画面を出して exit する。
	 *
	 * @param string $message Text, not escaped. / 文（未エスケープ）。
	 * @param string $link    URL of the link back. / 戻るリンクの URL。
	 * @return void
	 */
	private static function render_message_screen( $message, $link ) {
		$errors = new WP_Error( 'acgd_two_step', esc_html( $message ) );
		login_header( __( 'Verification code', 'etbs-account-guard' ), '', $errors );
		?>
		<p id="nav"><a href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Back to the login screen', 'etbs-account-guard' ); ?></a></p>
		<?php
		login_footer();
		exit;
	}

	/**
	 * Prints the code screen (7.10) and exits. / コード入力画面（7.10）を出して exit する。
	 *
	 * @param string   $attempt_id Attempt ID. / 試行 ID。
	 * @param array    $attempt    Attempt. / 試行。
	 * @param WP_Error $errors     Errors, shown in #login_error. / #login_error に出すエラー。
	 * @param bool     $invalid    Whether the last code was not accepted (aria-invalid). / 直前のコードが通らなかったか（aria-invalid）。
	 * @param string   $status     Status sentence (role="status"), not escaped. / 状態の文（role="status"。未エスケープ）。
	 * @return void
	 */
	private static function render_code_screen( $attempt_id, $attempt, $errors, $invalid, $status ) {
		$user      = get_userdata( (int) $attempt['uid'] );
		$email     = $user ? self::mask_email( $user->user_email ) : '•••';
		$time_fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$args      = self::screen_args( $attempt );
		$nonce_act = self::code_nonce_action( $attempt_id );

		$message = '' === $status ? '' : '<p class="message" role="status">' . esc_html( $status ) . '</p>';
		login_header( __( 'Verification code', 'etbs-account-guard' ), $message, $errors );
		?>
		<form name="acgd_code_form" id="loginform" action="<?php echo esc_url( self::form_url( self::ACTION_CODE, $args ) ); ?>" method="post">
			<p>
				<?php
				echo wp_kses(
					acgd_join_sentences(
						array(
							sprintf(
								/* translators: 1: masked email address, such as t•••@example.com, 2: date and time the code was sent */
								esc_html__( 'We sent a verification code to %1$s at %2$s.', 'etbs-account-guard' ),
								'<strong>' . esc_html( $email ) . '</strong>',
								esc_html( ACGD_Time::format_local( $time_fmt, (int) $attempt['sent'] ) )
							),
							sprintf(
								/* translators: %s: date and time the code expires */
								esc_html__( 'It expires at %s.', 'etbs-account-guard' ),
								esc_html( ACGD_Time::format_local( $time_fmt, (int) $attempt['code_exp'] ) )
							),
						)
					),
					array( 'strong' => array() )
				);
				?>
			</p>
			<p>
				<?php // No pattern / maxlength: they stop full-width input with a browser bubble and cut pasted text (7.10). / pattern・maxlength は付けない。全角の入力が吹き出しで止まり、貼り付けが切れるため（7.10）。 ?>
				<label for="acgd-code"><?php esc_html_e( 'Verification code (6 digits)', 'etbs-account-guard' ); ?></label>
				<input type="text" name="<?php echo esc_attr( self::CODE_FIELD ); ?>" id="acgd-code" class="input" value="" size="20" inputmode="numeric" autocomplete="one-time-code" autocapitalize="off" spellcheck="false"<?php echo $invalid ? ' aria-invalid="true" aria-describedby="login_error"' : ''; ?> />
			</p>
			<input type="hidden" name="<?php echo esc_attr( self::ATTEMPT_FIELD ); ?>" value="<?php echo esc_attr( $attempt_id ); ?>" />
			<?php wp_nonce_field( $nonce_act, self::CODE_NONCE_FIELD, false ); ?>
			<p class="submit">
				<input type="submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Verify', 'etbs-account-guard' ); ?>" />
			</p>
		</form>
		<form action="<?php echo esc_url( self::form_url( self::ACTION_RESEND, $args ) ); ?>" method="post" style="margin-top:16px;padding:16px 24px;">
			<input type="hidden" name="<?php echo esc_attr( self::ATTEMPT_FIELD ); ?>" value="<?php echo esc_attr( $attempt_id ); ?>" />
			<?php wp_nonce_field( $nonce_act, self::CODE_NONCE_FIELD, false ); ?>
			<p><input type="submit" class="button" value="<?php esc_attr_e( 'Send a new code', 'etbs-account-guard' ); ?>" /></p>
		</form>
		<form action="<?php echo esc_url( self::form_url( self::ACTION_CODE, $args ) ); ?>" method="post" style="padding:0 24px 16px;">
			<input type="hidden" name="<?php echo esc_attr( self::ATTEMPT_FIELD ); ?>" value="<?php echo esc_attr( $attempt_id ); ?>" />
			<?php wp_nonce_field( $nonce_act, self::CODE_NONCE_FIELD, false ); ?>
			<p><input type="submit" name="acgd_start_over" class="button-link" value="<?php esc_attr_e( 'Start over', 'etbs-account-guard' ); ?>" /></p>
		</form>
		<div style="padding:0 24px;">
			<p><strong><?php esc_html_e( 'If the email does not arrive', 'etbs-account-guard' ); ?></strong></p>
			<ul style="list-style:disc;margin-left:1.5em;">
				<li><?php echo esc_html( acgd_join_sentences( array( __( 'Wait a few minutes.', 'etbs-account-guard' ), __( 'Delivery can take a little time.', 'etbs-account-guard' ) ) ) ); ?></li>
				<li><?php esc_html_e( 'Check your spam or junk folder.', 'etbs-account-guard' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: email address the message is expected to come from */
						esc_html__( 'Look for an email from %s (this may differ if your site uses a mail plugin).', 'etbs-account-guard' ),
						'<code>' . esc_html( self::expected_sender() ) . '</code>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Send a new code with the button above.', 'etbs-account-guard' ); ?></li>
				<li><?php esc_html_e( 'If it still does not arrive, contact your site administrator.', 'etbs-account-guard' ); ?></li>
			</ul>
		</div>
		<?php
		login_footer( 'acgd-code' );
		exit;
	}

	/**
	 * Returns the address emails are expected to come from: core's default "wordpress@<site domain>" through
	 * the wp_mail_from filter, as wp_mail() builds it (7.9). An SMTP plugin that changes it later is not seen.
	 * メールの送信元の見込みを返す。wp_mail() と同じく、本体の既定「wordpress@<サイトのドメイン>」に
	 * wp_mail_from フィルタを通したもの（7.9）。後段で SMTP プラグインが変える分は見えない。
	 *
	 * @return string Address, not escaped. / アドレス（未エスケープ）。
	 */
	private static function expected_sender() {
		$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$from     = 'wordpress@';
		if ( null !== $sitename && false !== $sitename ) {
			if ( 0 === strpos( $sitename, 'www.' ) ) {
				$sitename = substr( $sitename, 4 );
			}
			$from .= $sitename;
		}

		return (string) apply_filters( 'wp_mail_from', $from ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, applied as wp_mail() applies it.
	}

	/**
	 * Handles wp-login.php?action=acgd_code_resend (POST): a new attempt with a new code replaces the old one,
	 * so the previous code and attempt ID stop working and each attempt row is written only once (7.2, 7.5).
	 * The attempt keeps its original expiry (counted from the password entry). Always exits.
	 * wp-login.php?action=acgd_code_resend（POST）を処理する。新しいコードを持つ新しい試行で古い試行を置き換える。
	 * 前のコードと試行 ID は使えなくなり、試行の行はどれも一度だけ書かれる（7.2・7.5）。試行の期限は元のまま
	 * （パスワード入力から数える）。必ず exit する。
	 *
	 * @return void
	 */
	public static function handle_resend() {
		self::send_screen_headers();

		$attempt_id = self::read_attempt_cookie();
		try {
			$attempt = self::load_attempt( $attempt_id );
			if ( null === $attempt || (int) $attempt['exp'] <= time() || ! self::verify_form( $attempt_id ) ) {
				self::render_message_screen( acgd_join_sentences( array( __( 'This sign-in can no longer be completed.', 'etbs-account-guard' ), __( 'Please sign in again.', 'etbs-account-guard' ) ) ), self::start_over_url( $attempt ) );
			}

			$user = get_userdata( (int) $attempt['uid'] );
			if ( ! $user ) {
				self::delete_attempt( $attempt_id );
				self::set_attempt_cookie( '', 0 );
				self::render_message_screen( acgd_join_sentences( array( __( 'This sign-in can no longer be completed.', 'etbs-account-guard' ), __( 'Please sign in again.', 'etbs-account-guard' ) ) ), self::start_over_url( $attempt ) );
			}

			$slot = self::reserve_send_slot( $user->ID );
			if ( ! $slot['ok'] ) {
				$text = 'gap' === $slot['reason']
					? self::gap_message( (int) $slot['retry_at'] )
					: self::limit_message( (int) $slot['retry_at'] );
				if ( 'window' === $slot['reason'] ) {
					ACGD_Access_Restriction::log_denial( $user->ID, ACGD_Access_Restriction::get_remote_addr(), 'two_step_limit' );
				}
				self::render_code_screen( $attempt_id, $attempt, new WP_Error( 'acgd_code', sprintf( '<strong>%1$s</strong> %2$s', esc_html__( 'Error:', 'etbs-account-guard' ), esc_html( $text ) ) ), false, '' );
			}

			$context = array_intersect_key( $attempt, array_flip( array( 'origin', 'redirect', 'interim', 'customize', 'remember', 'secure', 'ip', 'fp' ) ) );
			$result  = self::issue_attempt( $user, $context, (int) $attempt['exp'] );
			self::delete_attempt( $attempt_id );

			if ( $result['mail_failed'] ) {
				self::set_attempt_cookie( '', 0 );
				ACGD_Access_Restriction::log_denial( $user->ID, ACGD_Access_Restriction::get_remote_addr(), 'two_step_mail_failed' );
				self::render_message_screen( self::mail_failed_message(), self::start_over_url( $attempt ) );
			}

			self::set_attempt_cookie( $result['id'], (int) $attempt['exp'] );
			self::redirect_to_screen( array_merge( self::screen_args( $attempt ), array( 'acgd_resent' => 1 ) ) );
		} catch ( Throwable $e ) {
			self::record_fault( $e->getMessage() );
			self::render_message_screen( self::get_problem_message(), self::start_over_url( null ) );
		}
	}

	/**
	 * Finishes the login after a correct code, in one place and in the order of core's wp_signon() and
	 * wp-login.php (7.4-5). Always exits.
	 * 正しいコードの後のログインの完了処理を1か所で、本体の wp_signon() と wp-login.php の順に行う（7.4-5）。
	 * 必ず exit する。
	 *
	 * 1. Re-check: the user still exists, the password has not changed (fingerprint), still a target, the
	 *    switch is off. / 再確認：ユーザーがいる、パスワードが変わっていない（指紋）、まだ対象、スイッチが無効。
	 * 2. DELETE the attempt; continue only when exactly this request removed it (single use).
	 *    試行を DELETE し、このリクエストがちょうど消したときだけ続ける（使い捨て）。
	 * 3. wp_set_auth_cookie() (wp-includes/user.php wp_signon(): `wp_set_auth_cookie( $user->ID, $credentials['remember'], $secure_cookie )`),
	 *    clear user_activation_key (wp_signon(): "Clear `user_activation_key` after a successful login"),
	 *    do_action( 'wp_login' ) (wp_signon()).
	 * 4. Where it goes: wp-login.php's own post-login steps for a wp-login.php attempt; the form's own
	 *    destination for any other form. / 行き先：wp-login.php の試行は wp-login.php 自身の後処理、他のフォームは
	 *    そのフォームの行き先。
	 *
	 * @param string $attempt_id Attempt ID. / 試行 ID。
	 * @param array  $attempt    Attempt. / 試行。
	 * @return void
	 */
	private static function complete_login( $attempt_id, $attempt ) {
		$user_id = (int) $attempt['uid'];
		clean_user_cache( $user_id );
		$user = get_userdata( $user_id );

		$still_valid = $user
			&& hash_equals( (string) $attempt['fp'], self::password_fingerprint( $user->ID, $user->user_pass, wp_salt( 'auth' ) ) )
			&& ! self::is_switch_disabled()
			&& self::is_target( $user );

		if ( ! $still_valid ) {
			self::delete_attempt( $attempt_id );
			self::set_attempt_cookie( '', 0 );
			self::render_message_screen( acgd_join_sentences( array( __( 'This sign-in can no longer be completed.', 'etbs-account-guard' ), __( 'Please sign in again.', 'etbs-account-guard' ) ) ), self::start_over_url( $attempt ) );
		}

		// Single use: only the request that actually deleted the row goes on (7.4-5). / 使い捨て：行を実際に消したリクエストだけが進む（7.4-5）。
		if ( 1 !== self::delete_attempt( $attempt_id ) ) {
			self::set_attempt_cookie( '', 0 );
			self::render_message_screen( acgd_join_sentences( array( __( 'This sign-in can no longer be completed.', 'etbs-account-guard' ), __( 'Please sign in again.', 'etbs-account-guard' ) ) ), self::start_over_url( $attempt ) );
		}
		self::set_attempt_cookie( '', 0 );

		// The session created next carries the mark (7.6). / 次に作られるセッションに印を付ける（7.6）。
		self::$mark_user_id = $user->ID;
		wp_set_auth_cookie( $user->ID, ! empty( $attempt['remember'] ), ! empty( $attempt['secure'] ) );

		global $wpdb;
		if ( ! empty( $user->user_activation_key ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The same statement as core's wp_signon(); the user cache is cleaned right after.
			$wpdb->update( $wpdb->users, array( 'user_activation_key' => '' ), array( 'ID' => $user->ID ) );
			$user->user_activation_key = '';
			clean_user_cache( $user->ID );
		}

		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own action, fired as wp_signon() fires it.

		if ( 'login' !== ( isset( $attempt['origin'] ) ? $attempt['origin'] : 'login' ) ) {
			// Another form (WooCommerce My Account, for example): its own destination, already filtered (7.4-5).
			// 他のフォーム（WooCommerce のマイアカウントなど）：そのフォームの行き先。フィルタは既に通してある（7.4-5）。
			wp_safe_redirect( wp_validate_redirect( (string) $attempt['redirect'], home_url( '/' ) ) );
			exit;
		}

		self::finish_wp_login_redirect( $user, $attempt );
	}

	/**
	 * The post-login steps of wp-login.php, for an attempt that started there (wp-login.php, case 'login':
	 * the redirect_to handling, the login_redirect filter, interim-login, the admin email check and the
	 * default destination). Always exits.
	 * wp-login.php から始まった試行のための、wp-login.php のログイン後の処理（wp-login.php の case 'login'：
	 * redirect_to の扱い・login_redirect フィルタ・interim-login・管理者メールの確認・既定の行き先）。必ず exit する。
	 *
	 * @param WP_User $user    User. / ユーザー。
	 * @param array   $attempt Attempt. / 試行。
	 * @return void
	 */
	private static function finish_wp_login_redirect( $user, $attempt ) {
		$requested_redirect_to = (string) $attempt['redirect'];
		$redirect_to           = '' !== $requested_redirect_to ? $requested_redirect_to : admin_url();
		// wp-login.php: "Redirect to HTTPS if user wants SSL." / wp-login.php と同じ。
		if ( ! empty( $attempt['secure'] ) && false !== strpos( $redirect_to, 'wp-admin' ) ) {
			$redirect_to = preg_replace( '|^http://|', 'https://', $redirect_to );
		}

		$redirect_to = apply_filters( 'login_redirect', $redirect_to, $requested_redirect_to, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, applied as wp-login.php applies it.

		if ( ! empty( $attempt['interim'] ) ) {
			// wp-login.php's interim-login success screen: the body class interim-login-success closes the modal.
			// wp-login.php の interim-login の成功画面。body class の interim-login-success でモーダルが閉じる。
			$GLOBALS['interim_login'] = 'success'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp-login.php's own global, set as wp-login.php sets it.
			if ( ! empty( $attempt['customize'] ) ) {
				wp_enqueue_script( 'customize-base' );
			}
			login_header( '', '<p class="message">' . esc_html__( 'You have logged in successfully.', 'etbs-account-guard' ) . '</p>' );
			?>
			</div>
			<?php
			do_action( 'login_footer' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own action.
			if ( ! empty( $attempt['customize'] ) ) {
				wp_print_inline_script_tag( "setTimeout( function(){ new wp.customize.Messenger({ url: '" . esc_url_raw( wp_customize_url() ) . "', channel: 'login' }).send('login') }, 1000 );" );
			}
			?>
			</body></html>
			<?php
			exit;
		}

		// wp-login.php: "Check if it is time to add a redirect to the admin email confirmation screen."
		if ( $user->exists() && $user->has_cap( 'manage_options' ) ) {
			$admin_email_lifespan       = (int) get_option( 'admin_email_lifespan' );
			$admin_email_check_interval = (int) apply_filters( 'admin_email_check_interval', 6 * MONTH_IN_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter.
			if ( $admin_email_check_interval > 0 && time() > $admin_email_lifespan ) {
				$redirect_to = add_query_arg(
					array(
						'action'  => 'confirm_admin_email',
						'wp_lang' => get_user_locale( $user ),
					),
					wp_login_url( $redirect_to )
				);
			}
		}

		// wp-login.php: the default destination when none was requested. / wp-login.php と同じ既定の行き先。
		if ( empty( $redirect_to ) || 'wp-admin/' === $redirect_to || admin_url() === $redirect_to ) {
			if ( ! $user->has_cap( 'edit_posts' ) ) {
				$redirect_to = $user->has_cap( 'read' ) ? admin_url( 'profile.php' ) : home_url();
			}
			wp_redirect( $redirect_to ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The same call as wp-login.php here; the value is admin_url(), profile.php or home_url().
			exit;
		}

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/*-------------------------------------------*/
	/* Session mark (7.6) / セッションの印（7.6）
	/*-------------------------------------------*/

	/**
	 * Adds the mark to a new session when it follows a correct code (or a trusted device), or when it is made
	 * inside a marked session of the same user — the request's LOGGED_IN cookie, parsed with
	 * wp_parse_auth_cookie(), points to a marked session of that user (7.6). Not "is the current user set": on
	 * wp-login.php it may not be. The cookie is only parsed, not validated: after one's own password change the
	 * old cookie no longer validates, but its session (and its random token) is the one being carried over.
	 * 新しいセッションに印を付ける：コード（または信頼した端末）の直後か、同じユーザーの印付きセッションの中で
	 * 作られたとき——リクエストの LOGGED_IN Cookie を wp_parse_auth_cookie() で読み、それがそのユーザーの
	 * 印付きセッションを指すとき（7.6）。「現在のユーザーが確定済みか」では判定しない（wp-login.php では
	 * 未確定のことがある）。Cookie は読むだけで検証しない：本人がパスワードを変えた直後は古い Cookie は
	 * 検証に通らないが、引き継ぐべきはまさにそのセッション（とその乱数のトークン）だから。
	 *
	 * @param array $session Session information. / セッションの情報。
	 * @param int   $user_id User ID. / ユーザー ID。
	 * @return array Session information. / セッションの情報。
	 */
	public static function filter_attach_session_information( $session, $user_id ) {
		$session = is_array( $session ) ? $session : array();
		try {
			if ( self::$mark_user_id === (int) $user_id || self::request_has_marked_session( (int) $user_id, false ) ) {
				$session[ self::SESSION_MARK ] = time();
			}
		} catch ( Throwable $e ) {
			// No mark: the session is discarded on its next access (fail closed, 7.11). / 印を付けない：次のアクセスで破棄される（閉じる。7.11）。
			self::record_fault( $e->getMessage() );
		}

		return $session;
	}

	/**
	 * Tells whether this request carries a LOGGED_IN cookie of the given user that points to a marked session.
	 * このリクエストが、指定したユーザーの印付きセッションを指す LOGGED_IN Cookie を持つかを返す。
	 *
	 * @param int  $user_id  User ID. / ユーザー ID。
	 * @param bool $validate Whether the cookie itself must also validate (the login flow, 7.4-2-4). / Cookie 自体の検証も求めるか（ログインの流れ。7.4-2-4）。
	 * @return bool Whether it does. / 持つか。
	 */
	private static function request_has_marked_session( $user_id, $validate ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return false;
		}
		if ( $validate && (int) wp_validate_auth_cookie( '', 'logged_in' ) !== $user_id ) {
			return false;
		}

		$cookie = wp_parse_auth_cookie( '', 'logged_in' );
		if ( ! is_array( $cookie ) || empty( $cookie['token'] ) || empty( $cookie['username'] ) ) {
			return false;
		}
		$owner = get_user_by( 'login', $cookie['username'] );
		if ( ! $owner || (int) $owner->ID !== $user_id ) {
			return false;
		}

		return self::session_is_marked( $user_id, $cookie['token'] );
	}

	/**
	 * Tells whether one session of a user carries the mark. / ユーザーのあるセッションに印があるかを返す。
	 *
	 * @param int    $user_id User ID. / ユーザー ID。
	 * @param string $token   Session token. / セッションのトークン。
	 * @return bool Whether marked. / 印があるか。
	 */
	private static function session_is_marked( $user_id, $token ) {
		if ( '' === (string) $token ) {
			return false;
		}
		$session = WP_Session_Tokens::get_instance( (int) $user_id )->get( (string) $token );

		return is_array( $session ) && ! empty( $session[ self::SESSION_MARK ] );
	}

	/**
	 * Adds the mark to the current session (after a receive check, 7.6: as strong as a code at login).
	 * 今のセッションに印を付ける（受信確認の後。7.6：ログインのコードと同じ強さ）。
	 *
	 * @param int $user_id User ID. / ユーザー ID。
	 * @return void
	 */
	private static function mark_current_session( $user_id ) {
		$token = wp_get_session_token();
		if ( '' === $token ) {
			return;
		}
		$manager = WP_Session_Tokens::get_instance( (int) $user_id );
		$session = $manager->get( $token );
		if ( is_array( $session ) && empty( $session[ self::SESSION_MARK ] ) ) {
			$session[ self::SESSION_MARK ] = time();
			$manager->update( $token, $session );
		}
	}

	/**
	 * Discards the current session of a target whose session has no mark, and goes on as an anonymous visitor
	 * (7.6; the same handling as Access Restriction's later accesses, 5.2). Hooked to admin_init and
	 * template_redirect, where Access Restriction judges. Application passwords create no session and are left
	 * alone. / 印の無いセッションの対象者について、そのセッションだけを破棄し、未ログインとして続ける
	 * （7.6。アクセス制限のログイン後の扱い 5.2 と同じ）。アクセス制限の判定と同じ admin_init・template_redirect に
	 * 掛ける。アプリケーションパスワードはセッションを作らないので触らない。
	 *
	 * @return void
	 */
	public static function check_session_on_request() {
		self::enforce_session_mark();
	}

	/**
	 * The same check on the REST API (7.6). Placed after Access Restriction's own REST check, and after core's
	 * cookie check at 100, so it never decides the current user early (docs/spec.md 5.2).
	 * REST API での同じ判定（7.6）。アクセス制限の REST の判定の後、本体の Cookie の確認（100）の後に置き、
	 * 現在のユーザーを早く確定させる原因にならないようにする（docs/spec.md 5.2）。
	 *
	 * @param mixed $result Result so far. / それまでの結果。
	 * @return mixed The result. / 結果。
	 */
	public static function filter_rest_authentication_errors( $result ) {
		if ( ! is_wp_error( $result ) ) {
			self::enforce_session_mark();
		}

		return $result;
	}

	/**
	 * Shared body of the session mark checks. / セッションの印の判定の共通部分。
	 *
	 * @return void
	 */
	private static function enforce_session_mark() {
		if ( self::is_switch_disabled() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( 0 === $user_id || self::$app_password_user_id === $user_id ) {
			return;
		}
		if ( function_exists( 'rest_get_authenticated_app_password' ) && rest_get_authenticated_app_password() ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		try {
			$target = self::is_target( $user );
		} catch ( Throwable $e ) {
			self::record_fault( $e->getMessage() );
			$target = self::is_target_narrow( $user );
		}
		if ( ! $target ) {
			return;
		}

		try {
			/**
			 * Filters whether a target's session without the mark is kept (7.6). Default false. For sites that
			 * need another plugin's sign-in route (User Switching, for example) for targets.
			 * 対象者の印の無いセッションを残すかを絞り込む（7.6）。既定は false。対象者に他のプラグインの
			 * ログイン手段（User Switching など）を使わせたいサイト向け。
			 *
			 * @param bool    $exempt Whether to keep the session. / セッションを残すか。
			 * @param WP_User $user   User. / ユーザー。
			 */
			if ( apply_filters( 'acgd_two_step_session_exempt', false, $user ) ) {
				return;
			}
			if ( self::session_is_marked( $user_id, wp_get_session_token() ) ) {
				return;
			}
		} catch ( Throwable $e ) {
			self::record_fault( $e->getMessage() ); // Fail closed below (7.11). / 下で閉じる（7.11）。
		}

		ACGD_Access_Restriction::log_denial( $user_id, ACGD_Access_Restriction::get_remote_addr(), 'two_step_session' );
		ACGD_Access_Restriction::destroy_current_session_and_continue();
	}

	/*-------------------------------------------*/
	/* Password changes (7.5) / パスワードの変更（7.5）
	/*-------------------------------------------*/

	/**
	 * A password reset clears the user's attempts and send record (7.5). / パスワードの再設定で試行と送信の記録を消す（7.5）。
	 *
	 * @param WP_User $user User. / ユーザー。
	 * @return void
	 */
	public static function on_after_password_reset( $user ) {
		if ( $user instanceof WP_User ) {
			self::clear_user_records( $user->ID );
		}
	}

	/**
	 * A profile save that changed the password clears the same records (7.5). / パスワードが変わったプロフィールの保存で同じ記録を消す（7.5）。
	 *
	 * @param int          $user_id       User ID. / ユーザー ID。
	 * @param WP_User|null $old_user_data User before the save. / 保存前のユーザー。
	 * @return void
	 */
	public static function on_profile_update( $user_id, $old_user_data = null ) {
		$new = get_userdata( (int) $user_id );
		if ( $new && is_object( $old_user_data ) && isset( $old_user_data->user_pass ) && $old_user_data->user_pass !== $new->user_pass ) {
			self::clear_user_records( $user_id );
		}
	}

	/**
	 * wp_set_password clears the same records, except when core only re-hashed the same password at login
	 * (7.5, 7.8). Every argument after the first has a default: the action passes two arguments before
	 * WordPress 6.7 and three from 6.7 on, and a required third argument would be a fatal error on 6.2 to 6.6.
	 * wp_set_password で同じ記録を消す。ただしログイン時に本体が同じパスワードでハッシュを作り直しただけの
	 * ときは消さない（7.5・7.8）。2つ目以降の引数はすべて既定値を持つ：このアクションは WordPress 6.7 より前は
	 * 2引数、6.7 から3引数で、第3引数を必須にすると 6.2〜6.6 で Fatal になる。
	 *
	 * @param string     $password      New plain password. / 新しい平文のパスワード。
	 * @param int        $user_id       User ID. / ユーザー ID。
	 * @param mixed|null $old_user_data User data before the change (WordPress 6.7+). / 変更前のユーザーデータ（WordPress 6.7 以降）。
	 * @return void
	 */
	public static function on_wp_set_password( $password, $user_id = 0, $old_user_data = null ) {
		if ( (int) $user_id < 1 || self::is_password_rehash( $password, $old_user_data ) ) {
			return;
		}
		self::clear_user_records( $user_id );
	}

	/*-------------------------------------------*/
	/* Emails (7.9) / メール（7.9）
	/*-------------------------------------------*/

	/**
	 * Sends a plain text email to a user in that user's locale (7.9: switch_to_user_locale(), WordPress 6.2+;
	 * without it the locale simply is not switched). No link in the body, no code in the subject, and the
	 * From address is left to the site.
	 * ユーザーに、その人のロケールでテキストのメールを送る（7.9：switch_to_user_locale()。WordPress 6.2 以降。
	 * 無ければ切り替えないだけ）。本文にリンクを入れず、件名にコードを入れず、送信元はサイトに任せる。
	 *
	 * @param WP_User  $user    Recipient. / 宛先。
	 * @param callable $builder Returns array( subject, lines ) in the switched locale. / 切り替えたロケールで array( 件名, 行 ) を返す。
	 * @return bool What wp_mail() returned (true does not prove delivery, 7.9). / wp_mail() の戻り値（true でも届いたとは限らない。7.9）。
	 */
	private static function send_mail_in_user_locale( $user, $builder ) {
		if ( ! is_email( $user->user_email ) ) {
			return false;
		}

		$switched = function_exists( 'switch_to_user_locale' ) ? switch_to_user_locale( $user->ID ) : false;
		try {
			list( $subject, $lines ) = call_user_func( $builder );
			$sent                    = wp_mail( $user->user_email, $subject, implode( "\n", $lines ) );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}

		return (bool) $sent;
	}

	/**
	 * Site name for emails (plain text). / メール用のサイト名（テキスト）。
	 *
	 * @return string Site name. / サイト名。
	 */
	private static function mail_site_name() {
		return wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
	}

	/**
	 * Site domain for emails, written as plain text (7.9: not a link). / メール用のサイトのドメイン（リンクにしない。7.9）。
	 *
	 * @return string Domain. / ドメイン。
	 */
	private static function mail_site_domain() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Formats a time for emails in the site's time zone (7.9). / メール用に時刻をサイトのタイムゾーンで整形する（7.9）。
	 *
	 * @param int $time Unix time. / Unix 時刻。
	 * @return string Formatted. / 整形した時刻。
	 */
	private static function mail_time( $time ) {
		return ACGD_Time::format_local( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $time );
	}

	/**
	 * Sends the login code (7.9). / ログインの確認コードを送る（7.9）。
	 *
	 * @param WP_User $user    Recipient. / 宛先。
	 * @param string  $code    Code. / コード。
	 * @param int     $expires Code expiry. / コードの期限。
	 * @param string  $ip      Connecting address. / 接続元。
	 * @return bool What wp_mail() returned. / wp_mail() の戻り値。
	 */
	private static function send_login_code_mail( $user, $code, $expires, $ip ) {
		$now = time();

		return self::send_mail_in_user_locale(
			$user,
			function () use ( $code, $expires, $ip, $now ) {
				$site = self::mail_site_name();
				return array(
					/* translators: %s: site name */
					sprintf( __( '[%s] Your verification code', 'etbs-account-guard' ), $site ),
					array(
						/* translators: 1: site name, 2: site domain (plain text) */
						sprintf( __( 'Your username and password were just entered to sign in to %1$s (%2$s).', 'etbs-account-guard' ), $site, self::mail_site_domain() ),
						'',
						/* translators: %s: 6-digit verification code */
						sprintf( __( 'Verification code: %s', 'etbs-account-guard' ), $code ),
						/* translators: %s: date and time the code expires */
						sprintf( __( 'This code expires at %s.', 'etbs-account-guard' ), self::mail_time( $expires ) ),
						'',
						__( 'Do not share this code with anyone.', 'etbs-account-guard' ),
						__( 'Administrators and support staff will never ask you for it.', 'etbs-account-guard' ),
						'',
						/* translators: %s: date and time of the sign-in */
						sprintf( __( 'Date and time: %s', 'etbs-account-guard' ), self::mail_time( $now ) ),
						/* translators: %s: IP address of the sign-in */
						sprintf( __( 'IP address: %s', 'etbs-account-guard' ), '' !== $ip ? $ip : '-' ),
						'',
						__( 'If this was not you, your password may be known to someone else.', 'etbs-account-guard' ),
						__( 'Nobody can sign in without this code.', 'etbs-account-guard' ),
						__( 'Sign in the usual way and change your password, or contact your site administrator.', 'etbs-account-guard' ),
					),
				);
			}
		);
	}

	/**
	 * Sends the receive check code (7.3, 7.9): a different subject and body from the login code.
	 * 受信確認のコードを送る（7.3・7.9）。件名と本文をログインのものと分ける。
	 *
	 * @param WP_User $user    Recipient. / 宛先。
	 * @param string  $code    Code. / コード。
	 * @param int     $expires Code expiry. / コードの期限。
	 * @return bool What wp_mail() returned. / wp_mail() の戻り値。
	 */
	private static function send_receive_check_mail( $user, $code, $expires ) {
		return self::send_mail_in_user_locale(
			$user,
			function () use ( $code, $expires ) {
				$site = self::mail_site_name();
				return array(
					/* translators: %s: site name */
					sprintf( __( '[%s] Verification code for setting up two-step verification', 'etbs-account-guard' ), $site ),
					array(
						/* translators: 1: site name, 2: site domain (plain text) */
						sprintf( __( 'This is a verification code for setting up two-step verification on %1$s (%2$s).', 'etbs-account-guard' ), $site, self::mail_site_domain() ),
						__( 'This email is not for signing in.', 'etbs-account-guard' ),
						'',
						/* translators: %s: 6-digit verification code */
						sprintf( __( 'Verification code: %s', 'etbs-account-guard' ), $code ),
						/* translators: %s: date and time the code expires */
						sprintf( __( 'This code expires at %s.', 'etbs-account-guard' ), self::mail_time( $expires ) ),
						'',
						__( 'If you did not set this up, contact your site administrator.', 'etbs-account-guard' ),
					),
				);
			}
		);
	}

	/**
	 * Sends the notice of a stopped sign-in (7.4-2-8, 7.9). No code. / 止めたログインの通知を送る（7.4-2-8・7.9）。コードは無い。
	 *
	 * @param WP_User     $user Recipient. / 宛先。
	 * @param string|null $ip   Connecting address. / 接続元。
	 * @return bool What wp_mail() returned. / wp_mail() の戻り値。
	 */
	private static function send_notice_mail( $user, $ip ) {
		$now = time();

		return self::send_mail_in_user_locale(
			$user,
			function () use ( $ip, $now ) {
				$site = self::mail_site_name();
				return array(
					/* translators: %s: site name */
					sprintf( __( '[%s] A sign-in with your password was stopped', 'etbs-account-guard' ), $site ),
					array(
						/* translators: 1: site name, 2: site domain (plain text) */
						sprintf( __( 'Someone tried to sign in to %1$s (%2$s) with your correct password.', 'etbs-account-guard' ), $site, self::mail_site_domain() ),
						__( 'The sign-in was stopped, because two-step verification cannot be completed that way.', 'etbs-account-guard' ),
						'',
						/* translators: %s: date and time of the sign-in */
						sprintf( __( 'Date and time: %s', 'etbs-account-guard' ), self::mail_time( $now ) ),
						/* translators: %s: IP address of the sign-in */
						sprintf( __( 'IP address: %s', 'etbs-account-guard' ), ( null !== $ip && '' !== $ip ) ? $ip : '-' ),
						'',
						__( 'If this was not you, change your password.', 'etbs-account-guard' ),
					),
				);
			}
		);
	}

	/*-------------------------------------------*/
	/* Receive check (7.3) / 受信確認（7.3）
	/*-------------------------------------------*/

	/**
	 * Sends a receive check code to the current admin (7.3). The same per-user send limit applies (7.5).
	 * 今の管理者に受信確認のコードを送る（7.3）。送信の上限は同じものを使う（7.5）。
	 *
	 * @param WP_User $user Current admin. / 今の管理者。
	 * @return array Result: status 'sent', 'gap', 'window', 'no_email' or 'mail_failed', and 'time'. / 結果。
	 */
	public static function receive_check_send( $user ) {
		if ( ! is_email( $user->user_email ) ) {
			return array( 'status' => 'no_email' );
		}

		$slot = self::reserve_send_slot( $user->ID );
		if ( ! $slot['ok'] ) {
			return array(
				'status' => $slot['reason'],
				'time'   => (int) $slot['retry_at'],
			);
		}

		$row = self::receive_check_row( $user->ID );
		$old = self::decode( self::get_row( $row ) );
		if ( null !== $old && ! empty( $old['rid'] ) ) {
			self::delete_row( self::counter_row( (string) $old['rid'] ) );
		}
		self::delete_row( $row );

		// A random ID per code: the key of its wrong-code counter and part of its HMAC. It never leaves the
		// server (the receive check is tied to the signed-in admin, not to a cookie).
		// コードごとの乱数の ID。誤りの回数の行のキーで、HMAC の一部でもある。サーバの外には出さない
		// （受信確認はログイン中の管理者に結び付き、Cookie には結び付かない）。
		$rid     = bin2hex( random_bytes( 32 ) );
		$code    = self::generate_code();
		$expires = time() + self::CODE_TTL;
		$pending = array(
			's'        => 'pending',
			'rid'      => $rid,
			'hmac'     => self::receive_check_hmac( $user->ID, $rid, $code, wp_salt( 'auth' ) ),
			'code_exp' => $expires,
			'em'       => self::email_hash( $user->user_email ),
		);
		if ( ! self::insert_row( self::counter_row( $rid ), '0' ) || ! self::insert_row( $row, (string) wp_json_encode( $pending ) ) ) {
			self::delete_row( self::counter_row( $rid ) );
			return array( 'status' => 'error' );
		}

		if ( ! self::send_receive_check_mail( $user, $code, $expires ) ) {
			self::delete_row( $row );
			self::delete_row( self::counter_row( $rid ) );
			return array( 'status' => 'mail_failed' );
		}

		return array(
			'status' => 'sent',
			'time'   => $expires,
		);
	}

	/**
	 * Checks a receive check code (7.3). On success the row becomes the mark (the confirmed address's hash and
	 * RECEIVE_CHECK_TTL), swapped only while it still holds the pending value, and the current session gets
	 * the session mark (7.6). / 受信確認のコードを確かめる（7.3）。成功すると行は印（確認したアドレスの
	 * ハッシュと RECEIVE_CHECK_TTL）になる。まだ保留中の値のときだけ差し替え、今のセッションに印を付ける（7.6）。
	 *
	 * @param WP_User $user  Current admin. / 今の管理者。
	 * @param string  $typed Typed code. / 入力されたコード。
	 * @return array Result: status 'verified', 'wrong', 'format', 'expired', 'none' or 'too_many', 'remaining', 'time'. / 結果。
	 */
	public static function receive_check_verify( $user, $typed ) {
		$row   = self::receive_check_row( $user->ID );
		$raw   = self::get_row( $row );
		$check = self::decode( $raw );
		if ( null === $check || 'pending' !== ( isset( $check['s'] ) ? $check['s'] : '' ) || empty( $check['rid'] ) ) {
			return array( 'status' => 'none' );
		}
		if ( (int) $check['code_exp'] <= time() ) {
			return array( 'status' => 'expired' );
		}

		$code = self::normalize_code_input( $typed );
		if ( null === $code ) {
			return array( 'status' => 'format' );
		}

		$counter = self::counter_row( (string) $check['rid'] );
		if ( ! self::take_try( $counter ) ) {
			self::delete_row( $row, $raw );
			self::delete_row( $counter );
			return array( 'status' => 'too_many' );
		}

		if ( ! hash_equals( (string) $check['hmac'], self::receive_check_hmac( $user->ID, (string) $check['rid'], $code, wp_salt( 'auth' ) ) ) ) {
			$used      = self::get_row( $counter );
			$remaining = null === $used ? 0 : max( 0, self::MAX_WRONG - (int) $used );
			if ( $remaining < 1 ) {
				self::delete_row( $row, $raw );
				self::delete_row( $counter );
				return array( 'status' => 'too_many' );
			}
			return array(
				'status'    => 'wrong',
				'remaining' => $remaining,
			);
		}

		$until    = time() + self::RECEIVE_CHECK_TTL;
		$verified = array(
			's'   => 'verified',
			'em'  => (string) $check['em'],
			'exp' => $until,
		);
		if ( ! self::compare_and_swap( $row, $raw, (string) wp_json_encode( $verified ) ) ) {
			return array( 'status' => 'none' );
		}
		self::delete_row( $counter );
		self::mark_current_session( $user->ID );

		return array(
			'status' => 'verified',
			'time'   => $until,
		);
	}

	/**
	 * Tells whether a user holds a valid receive check mark for every given address (7.3): verified, not past
	 * RECEIVE_CHECK_TTL, and each address hashing to the confirmed one. Not consumed: register_setting()
	 * calls sanitize twice on the first save (class-acgd-settings.php).
	 * ユーザーが、与えたすべてのアドレスについて有効な受信確認の印を持つかを返す（7.3）：確認済み、
	 * RECEIVE_CHECK_TTL を過ぎていない、各アドレスのハッシュが確認したものと同じ。消費しない：
	 * register_setting() は初回保存で sanitize を2回呼ぶ（class-acgd-settings.php）。
	 *
	 * @param int      $user_id User ID. / ユーザー ID。
	 * @param string[] $emails  Addresses that must all match. / すべて一致すべきアドレス。
	 * @return bool Whether valid. / 有効か。
	 */
	public static function has_valid_receive_check( $user_id, $emails ) {
		$check = self::decode( self::get_row( self::receive_check_row( $user_id ) ) );
		if ( null === $check || 'verified' !== ( isset( $check['s'] ) ? $check['s'] : '' ) || (int) ( isset( $check['exp'] ) ? $check['exp'] : 0 ) <= time() ) {
			return false;
		}
		foreach ( (array) $emails as $email ) {
			if ( ! hash_equals( (string) $check['em'], self::email_hash( $email ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns until when the current receive check mark is valid, or 0. / 今の受信確認の印の有効期限を返す（無ければ 0）。
	 *
	 * @param WP_User $user User. / ユーザー。
	 * @return int Time, or 0. / 時刻、または 0。
	 */
	private static function receive_check_valid_until( $user ) {
		if ( ! self::has_valid_receive_check( $user->ID, array( $user->user_email ) ) ) {
			return 0;
		}
		$check = self::decode( self::get_row( self::receive_check_row( $user->ID ) ) );

		return null === $check ? 0 : (int) $check['exp'];
	}

	/**
	 * Runs one receive check action for the current admin. / 今の管理者の受信確認の操作を1つ行う。
	 *
	 * @param string $action 'send' or 'verify'. / 'send' か 'verify'。
	 * @param string $typed  Typed code (for verify). / 入力されたコード（verify のとき）。
	 * @return array Result. / 結果。
	 */
	private static function run_receive_check( $action, $typed ) {
		$user = wp_get_current_user();
		try {
			return 'verify' === $action ? self::receive_check_verify( $user, $typed ) : self::receive_check_send( $user );
		} catch ( Throwable $e ) {
			self::record_fault( $e->getMessage() );
			return array( 'status' => 'error' );
		}
	}

	/**
	 * Sentence for a receive check result. / 受信確認の結果の文。
	 *
	 * @param array $result Result. / 結果。
	 * @return string Text, not escaped. / 文（未エスケープ）。
	 */
	private static function receive_check_message( $result ) {
		$time   = isset( $result['time'] ) ? (int) $result['time'] : 0;
		$format = get_option( 'time_format' );

		switch ( isset( $result['status'] ) ? $result['status'] : '' ) {
			case 'sent':
				return acgd_join_sentences(
					array(
						__( 'A verification code has been sent to your email address.', 'etbs-account-guard' ),
						/* translators: %s: time the code expires */
						sprintf( __( 'Enter it below before %s.', 'etbs-account-guard' ), ACGD_Time::format_local( $format, $time ) ),
					)
				);
			case 'verified':
				return acgd_join_sentences(
					array(
						__( 'Confirmed.', 'etbs-account-guard' ),
						/* translators: %s: time until which the confirmation is valid */
						sprintf( __( 'You can now save settings that turn on two-step verification for your own account until %s.', 'etbs-account-guard' ), ACGD_Time::format_local( $format, $time ) ),
					)
				);
			case 'gap':
				return self::gap_message( $time );
			case 'window':
				return acgd_join_sentences(
					array(
						__( 'Too many verification codes have been sent.', 'etbs-account-guard' ),
						/* translators: %s: time (or date and time) when a new code can be sent */
						sprintf( __( 'You can send a new code after %s.', 'etbs-account-guard' ), ACGD_Time::format_local( get_option( 'date_format' ) . ' ' . $format, $time ) ),
					)
				);
			case 'no_email':
				return acgd_join_sentences( array( __( 'Your account has no valid email address.', 'etbs-account-guard' ), __( 'Set one on your profile first.', 'etbs-account-guard' ) ) );
			case 'mail_failed':
				return acgd_join_sentences( array( __( 'The email could not be sent.', 'etbs-account-guard' ), __( 'Check the email settings of this site.', 'etbs-account-guard' ) ) );
			case 'wrong':
				return self::wrong_code_message( (int) $result['remaining'] );
			case 'format':
				return __( 'Enter the 6-digit verification code from the email.', 'etbs-account-guard' );
			case 'expired':
				return acgd_join_sentences( array( __( 'This verification code has expired.', 'etbs-account-guard' ), __( 'Send a new code.', 'etbs-account-guard' ) ) );
			case 'too_many':
				return acgd_join_sentences( array( __( 'The code was entered incorrectly too many times.', 'etbs-account-guard' ), __( 'Send a new code.', 'etbs-account-guard' ) ) );
			case 'none':
				return acgd_join_sentences( array( __( 'There is no code waiting to be confirmed.', 'etbs-account-guard' ), __( 'Send a new code.', 'etbs-account-guard' ) ) );
			default:
				return self::get_problem_message();
		}
	}

	/**
	 * admin-ajax handler of the receive check (7.3). The server decides; the script only shows the sentence.
	 * 受信確認の admin-ajax の処理（7.3）。判定はサーバが行い、スクリプトは文を出すだけ。
	 *
	 * @return void
	 */
	public static function ajax_receive_check() {
		check_ajax_referer( self::RC_NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'etbs-account-guard' ) ), 403 );
		}

		$action = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$typed  = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$result = self::run_receive_check( $action, $typed );

		wp_send_json_success(
			array(
				'status'  => $result['status'],
				'message' => self::receive_check_message( $result ),
				'invalid' => in_array( $result['status'], array( 'wrong', 'format', 'expired', 'too_many' ), true ),
			)
		);
	}

	/**
	 * admin-post fallback of the receive check on the settings tab, for browsers without JavaScript (7.3).
	 * Redirects back to the tab with the result in the query string. / 設定タブの受信確認の、JavaScript の
	 * 無いブラウザ向けの admin-post の経路（7.3）。結果をクエリに載せてタブへ戻す。
	 *
	 * @return void
	 */
	public static function handle_receive_check_post() {
		check_admin_referer( self::RC_NONCE_ACTION, 'acgd_2s_rc_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'etbs-account-guard' ), '', array( 'response' => 403 ) );
		}

		$action = isset( $_POST[ self::RC_DO_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::RC_DO_FIELD ] ) ) : '';
		$typed  = isset( $_POST[ self::RC_CODE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::RC_CODE_FIELD ] ) ) : '';
		$result = self::run_receive_check( $action, $typed );

		wp_safe_redirect( add_query_arg( self::result_query_args( $result ), ACGD_Settings::get_page_url( self::TAB ) ) );
		exit;
	}

	/**
	 * Receive check buttons of the user edit screen, for browsers without JavaScript (7.3). The buttons sit in
	 * core's <form id="your-profile">, so the click is picked up on admin_init before core saves, the section's
	 * own choices are kept for redisplay, and the request always ends here (the profile is not saved), the same
	 * round trip as BASIC's "Verify" (ACGD_Basic_Auth::maybe_handle_verify_request()).
	 * ユーザー編集画面の受信確認のボタンの、JavaScript の無いブラウザ向けの経路（7.3）。ボタンは本体の
	 * <form id="your-profile"> の中にあるので、本体が保存する前の admin_init で受け取り、区画の選択を出し直し用に
	 * 残し、リクエストは必ずここで終える（プロフィールは保存しない）。BASIC の「確認」
	 * （ACGD_Basic_Auth::maybe_handle_verify_request()）と同じ往復。
	 *
	 * @return void
	 */
	public static function maybe_handle_profile_receive_check() {
		if ( ! isset( $_POST[ self::RC_DO_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; the nonces are verified below before anything is read.
			return;
		}
		$pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		if ( ! in_array( $pagenow, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'profile.php' );
		}

		$target_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Needed to build core's nonce action, verified right below.
		if ( ! current_user_can( 'manage_options' )
			|| get_current_user_id() !== $target_id
			|| ! isset( $_POST[ self::USER_NONCE_NAME ], $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::USER_NONCE_NAME ] ) ), self::USER_NONCE_ACTION )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $target_id ) ) {
			wp_safe_redirect( add_query_arg( 'acgd_2s_rc', 'error', $back ) );
			exit;
		}

		// Keep this section's choice, and Access Restriction's section, for redisplay. / この区画と「アクセス制限」の区画の入力を出し直し用に残す。
		$method = isset( $_POST['acgd_two_step_method'] ) ? sanitize_key( wp_unslash( $_POST['acgd_two_step_method'] ) ) : self::METHOD_FOLLOW;
		self::stash_user_resubmit( $target_id, $method );
		if ( isset( $_POST[ ACGD_User_Access::NONCE_NAME ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ ACGD_User_Access::NONCE_NAME ] ) ), ACGD_User_Access::NONCE_ACTION ) ) {
			ACGD_User_Access::stash_resubmit(
				$target_id,
				isset( $_POST['acgd_user_mode'] ) ? sanitize_key( wp_unslash( $_POST['acgd_user_mode'] ) ) : 'follow',
				ACGD_Access_Restriction::sanitize_ip_list_text( isset( $_POST['acgd_user_ips'] ) ? wp_unslash( $_POST['acgd_user_ips'] ) : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_ip_list_text() sanitizes it, line by line.
				isset( $_POST['acgd_basic_id'] ) ? sanitize_text_field( wp_unslash( $_POST['acgd_basic_id'] ) ) : ''
			);
		}

		$action = sanitize_key( wp_unslash( $_POST[ self::RC_DO_FIELD ] ) );
		$typed  = isset( $_POST[ self::RC_CODE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::RC_CODE_FIELD ] ) ) : '';
		$result = self::run_receive_check( $action, $typed );

		wp_safe_redirect( add_query_arg( self::result_query_args( $result ), $back ) . '#acgd-two-step' );
		exit;
	}

	/**
	 * Query arguments carrying a receive check result to the next screen (display only).
	 * 受信確認の結果を次の画面へ運ぶクエリ引数（表示用のみ）。
	 *
	 * @param array $result Result. / 結果。
	 * @return array Query arguments. / クエリ引数。
	 */
	private static function result_query_args( $result ) {
		return array(
			'acgd_2s_rc'   => isset( $result['status'] ) ? $result['status'] : 'error',
			'acgd_2s_rc_n' => isset( $result['remaining'] ) ? (int) $result['remaining'] : 0,
			'acgd_2s_rc_t' => isset( $result['time'] ) ? (int) $result['time'] : 0,
		);
	}

	/**
	 * Reads a receive check result from the query string (display only). / クエリから受信確認の結果を読む（表示用のみ）。
	 *
	 * @return array|null Result, or null. / 結果、または null。
	 */
	private static function result_from_query() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only; the values only pick a fixed sentence and a time.
		if ( ! isset( $_GET['acgd_2s_rc'] ) ) {
			return null;
		}

		$result = array(
			'status'    => sanitize_key( wp_unslash( $_GET['acgd_2s_rc'] ) ),
			'remaining' => isset( $_GET['acgd_2s_rc_n'] ) ? absint( $_GET['acgd_2s_rc_n'] ) : 0,
			'time'      => isset( $_GET['acgd_2s_rc_t'] ) ? absint( $_GET['acgd_2s_rc_t'] ) : 0,
		);
		// phpcs:enable

		return $result;
	}

	/**
	 * Loads the receive check script (display only) on the screens where the receive check is shown, for
	 * people who can manage options. A src-less handle carries it inline, so no extra file is fetched.
	 * 受信確認のスクリプト（表示用のみ）を、受信確認を出す画面で、manage_options を持つ人にだけ読み込む。
	 * src を持たないハンドルにインラインで載せるので、ファイルを別に取りに行かない。
	 *
	 * @param string $hook_suffix Current admin screen. / 今の管理画面。
	 * @return void
	 */
	public static function enqueue_receive_check_script( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php', ACGD_Settings::SCREEN_ID ), true ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$script = <<<'ACGD_JS'
(function () {
	function send(button) {
		var box = button.closest('[data-acgd-rc-box]');
		if (!box || !window.fetch || !window.FormData) { return false; }
		var input = box.querySelector('[data-acgd-rc-code]');
		var status = box.querySelector('[data-acgd-rc-status]');
		var data = new FormData();
		data.append('action', 'acgd_2s_rc');
		data.append('nonce', box.getAttribute('data-nonce'));
		data.append('do', button.getAttribute('data-acgd-rc'));
		if (input) { data.append('code', input.value); }
		button.disabled = true;
		fetch(box.getAttribute('data-ajax-url'), { method: 'POST', credentials: 'same-origin', body: data })
			.then(function (response) { return response.json(); })
			.then(function (json) {
				var d = json && json.data ? json.data : {};
				if (status) { status.textContent = d.message || box.getAttribute('data-error'); }
				if (input) {
					if (d.invalid) { input.setAttribute('aria-invalid', 'true'); } else { input.removeAttribute('aria-invalid'); }
					if (d.status === 'verified') { input.value = ''; }
				}
			})
			['catch'](function () { if (status) { status.textContent = box.getAttribute('data-error'); } })
			.then(function () { button.disabled = false; });
		return true;
	}
	document.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('[data-acgd-rc]') : null;
		if (button && send(button)) { event.preventDefault(); }
	});
	document.addEventListener('keydown', function (event) {
		if (event.key !== 'Enter' || !event.target.matches || !event.target.matches('[data-acgd-rc-code]')) { return; }
		var box = event.target.closest('[data-acgd-rc-box]');
		var verify = box ? box.querySelector('[data-acgd-rc="verify"]') : null;
		if (verify && send(verify)) { event.preventDefault(); }
	});
})();
ACGD_JS;

		// No src and no version: nothing is fetched. / src も版数も無い：何も取りに行かない。
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion, WordPress.WP.EnqueuedResourceParameters.NotInFooter -- With no src there is nothing to cache; the inline script only attaches listeners to the document.
		wp_register_script( self::RC_SCRIPT_HANDLE, false, array(), null, true );
		wp_add_inline_script( self::RC_SCRIPT_HANDLE, $script );
		wp_enqueue_script( self::RC_SCRIPT_HANDLE );
	}

	/**
	 * Prints the receive check box. On the settings tab it has forms of its own (posting to admin-post.php);
	 * on the user edit screen it sits inside core's profile form. / 受信確認の箱を出す。設定タブでは自前の
	 * フォーム（admin-post.php 宛て）を持ち、ユーザー編集画面では本体のプロフィールのフォームの中に置く。
	 *
	 * @param WP_User $user        Current admin. / 今の管理者。
	 * @param bool    $own_forms   Whether to print forms of its own (settings tab). / 自前のフォームを出すか（設定タブ）。
	 * @return void
	 */
	private static function render_receive_check_box( $user, $own_forms ) {
		$until  = self::receive_check_valid_until( $user );
		$result = self::result_from_query();
		$status = null === $result ? '' : self::receive_check_message( $result );
		$format = get_option( 'time_format' );
		?>
		<div data-acgd-rc-box data-nonce="<?php echo esc_attr( wp_create_nonce( self::RC_NONCE_ACTION ) ); ?>" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-error="<?php echo esc_attr( self::get_problem_message() ); ?>">
			<p>
				<?php
				if ( $until ) {
					echo wp_kses(
						acgd_join_sentences(
							array(
								sprintf(
									/* translators: %s: masked email address */
									esc_html__( 'Your email address %s has been confirmed.', 'etbs-account-guard' ),
									'<strong>' . esc_html( self::mask_email( $user->user_email ) ) . '</strong>'
								),
								sprintf(
									/* translators: %s: time until which the confirmation is valid */
									esc_html__( 'Settings that turn on two-step verification for your own account can be saved until %s.', 'etbs-account-guard' ),
									esc_html( ACGD_Time::format_local( $format, $until ) )
								),
							)
						),
						array( 'strong' => array() )
					);
				} else {
					printf(
						/* translators: %s: masked email address */
						esc_html__( 'Before turning on two-step verification for your own account, confirm that codes reach your email address %s.', 'etbs-account-guard' ),
						'<strong>' . esc_html( self::mask_email( $user->user_email ) ) . '</strong>'
					);
				}
				?>
			</p>
			<?php if ( $own_forms ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::RC_NONCE_ACTION ); ?>" />
					<?php wp_nonce_field( self::RC_NONCE_ACTION, 'acgd_2s_rc_nonce' ); ?>
					<p><button type="submit" class="button" name="<?php echo esc_attr( self::RC_DO_FIELD ); ?>" value="send" data-acgd-rc="send"><?php esc_html_e( 'Send a confirmation code', 'etbs-account-guard' ); ?></button></p>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::RC_NONCE_ACTION ); ?>" />
					<?php wp_nonce_field( self::RC_NONCE_ACTION, 'acgd_2s_rc_nonce' ); ?>
					<?php self::render_receive_check_code_field(); ?>
				</form>
			<?php else : ?>
				<p><button type="submit" class="button" name="<?php echo esc_attr( self::RC_DO_FIELD ); ?>" value="send" data-acgd-rc="send"><?php esc_html_e( 'Send a confirmation code', 'etbs-account-guard' ); ?></button></p>
				<?php self::render_receive_check_code_field(); ?>
				<p class="description"><?php esc_html_e( 'Without JavaScript, these buttons reload this screen without saving your other changes on it; the Two-Step Verification and Access Restriction choices are kept.', 'etbs-account-guard' ); ?></p>
			<?php endif; ?>
			<p data-acgd-rc-status role="status"><?php echo esc_html( $status ); ?></p>
		</div>
		<?php
	}

	/**
	 * Prints the code field and the confirm button of the receive check. / 受信確認のコード欄と確認ボタンを出す。
	 *
	 * @return void
	 */
	private static function render_receive_check_code_field() {
		?>
		<p>
			<label for="acgd-2s-rc-code"><?php esc_html_e( 'Confirmation code (6 digits)', 'etbs-account-guard' ); ?></label><br />
			<input type="text" id="acgd-2s-rc-code" name="<?php echo esc_attr( self::RC_CODE_FIELD ); ?>" class="regular-text" value="" inputmode="numeric" autocomplete="one-time-code" data-acgd-rc-code />
			<button type="submit" class="button" name="<?php echo esc_attr( self::RC_DO_FIELD ); ?>" value="verify" data-acgd-rc="verify"><?php esc_html_e( 'Confirm', 'etbs-account-guard' ); ?></button>
		</p>
		<?php
	}

	/*-------------------------------------------*/
	/* Settings tab (7.3, 7.10) / 設定タブ（7.3・7.10）
	/*-------------------------------------------*/

	/**
	 * Registers the option of the Two-Step Verification tab. Saved by core's options.php, so it never appears
	 * in an update_option() search (see uninstall.php). / 「2段階認証」タブのオプションを登録する。本体の
	 * options.php が保存するので update_option() の検索には現れない（uninstall.php を参照）。
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Keeps only known roles and methods from the submitted table. / 送信された表から既知の権限・方式だけを残す。
	 *
	 * @param mixed $input Submitted role => method. / 送信された 権限 => 方式。
	 * @return string[] Role => 'none' | 'email'. / 権限 => 方式。
	 */
	private static function sanitize_role_methods( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$output = array();
		foreach ( array_keys( wp_roles()->get_names() ) as $role ) {
			$method          = ( isset( $input[ $role ] ) && is_string( $input[ $role ] ) ) ? sanitize_key( $input[ $role ] ) : self::METHOD_NONE;
			$output[ $role ] = self::METHOD_EMAIL === $method ? self::METHOD_EMAIL : self::METHOD_NONE;
		}

		return $output;
	}

	/**
	 * Sanitizes the Two-Step Verification tab and applies the save-time checks (7.3). On a failure the saved
	 * value is kept, the submission is stashed for redisplay and an error is queued.
	 * 「2段階認証」タブを検証し、保存時のチェック（7.3）を掛ける。失敗したら保存済みの値のままにし、
	 * 送信内容を出し直し用に残し、エラーを積む。
	 *
	 * 1. A save that turns Two-Step Verification on for the person saving needs their receive check (valid,
	 *    and for their current address). The mark is not consumed (sanitize runs twice on the first save).
	 * 2. No "one manage_options user must stay without it" rule (7.3-2).
	 * 3. Every user who would be a target needs a non-empty, valid email address; receiving is not checked.
	 * 1. 保存する本人に2段階認証が掛かることになる保存は、本人の受信確認（有効で、いまのアドレスのもの）が要る。
	 *    印は消費しない（初回保存で sanitize が2回呼ばれる）。
	 * 2. 「掛からない manage_options を1人残す」は課さない（7.3-2）。
	 * 3. 対象になる全員に、空でない正しいメールアドレスが要る。受信までは確かめない。
	 *
	 * @param mixed $input Submitted value. / 送信された値。
	 * @return array Value to save. / 保存する値。
	 */
	public static function sanitize_settings( $input ) {
		$existing = get_option( self::OPTION, array() );
		$existing = is_array( $existing ) ? $existing : array();
		$input    = is_array( $input ) ? $input : array();

		$roles    = self::sanitize_role_methods( isset( $input['roles'] ) ? $input['roles'] : array() );
		$settings = self::get_settings();
		// The number of trusted days has no control until PR-C (7.8); the saved value (default 30) is kept.
		// 信頼する日数の欄は PR-C（7.8）まで無い。保存済みの値（既定 30）をそのまま残す。
		$new_value = array(
			'roles'      => $roles,
			'trust_days' => $settings['trust_days'],
		);

		$me = wp_get_current_user();
		if ( $me->exists() && ! self::is_target( $me ) && self::is_target( $me, $roles ) && ! self::has_valid_receive_check( $me->ID, array( $me->user_email ) ) ) {
			self::add_settings_error_once(
				'acgd_two_step_receive_check',
				acgd_join_sentences(
					array(
						__( 'These settings would turn on two-step verification for your own account.', 'etbs-account-guard' ),
						__( 'Confirm your email address with "Send a confirmation code" at the top of this tab first (the confirmation is valid for 10 minutes).', 'etbs-account-guard' ),
						__( 'Not saved.', 'etbs-account-guard' ),
					)
				)
			);
			self::stash_settings_resubmit( $roles );
			return $existing;
		}

		$bad = self::find_targets( $roles, 5, array( __CLASS__, 'has_bad_email' ) );
		if ( $bad ) {
			$names = array();
			foreach ( $bad as $user ) {
				$names[] = $user->user_login;
			}
			self::add_settings_error_once(
				'acgd_two_step_bad_email',
				acgd_join_sentences(
					array(
						sprintf(
							/* translators: %s: login names of users without a valid email address, separated by commas */
							__( 'These users would need a verification code but have no valid email address: %s.', 'etbs-account-guard' ),
							implode( ', ', $names )
						),
						__( 'Set their email addresses first.', 'etbs-account-guard' ),
						__( 'Not saved.', 'etbs-account-guard' ),
					)
				)
			);
			self::stash_settings_resubmit( $roles );
			return $existing;
		}

		self::clear_fault();
		delete_transient( self::SETTINGS_RESUBMIT_PREFIX . get_current_user_id() );

		return $new_value;
	}

	/**
	 * Tells whether a user's email address is empty or invalid. / ユーザーのメールアドレスが空・不正かを返す。
	 *
	 * @param WP_User $user User. / ユーザー。
	 * @return bool Whether bad. / 空・不正か。
	 */
	public static function has_bad_email( $user ) {
		return ! is_email( $user->user_email );
	}

	/**
	 * Queues a settings error once (sanitize runs twice on the first save). The message is escaped here,
	 * because settings_errors() prints it as it is. / 設定のエラーを1回だけ積む（初回保存で sanitize が
	 * 2回呼ばれるため）。settings_errors() はそのまま出すので、ここでエスケープする。
	 *
	 * @param string $code    Error code. / エラーコード。
	 * @param string $message Message, not escaped. / 文（未エスケープ）。
	 * @return void
	 */
	private static function add_settings_error_once( $code, $message ) {
		// These live in wp-admin/includes/template.php: present on options.php, absent when the option is
		// updated from elsewhere (WP-CLI, for example), where there is no screen to show the error on anyway.
		// これらは wp-admin/includes/template.php にある。options.php では読み込まれているが、他の経路
		// （WP-CLI など）でオプションを更新するときは無い。そのときはエラーを見せる画面もそもそも無い。
		if ( ! function_exists( 'add_settings_error' ) || ! function_exists( 'get_settings_errors' ) ) {
			return;
		}
		foreach ( get_settings_errors( self::OPTION ) as $error ) {
			if ( isset( $error['code'] ) && $code === $error['code'] ) {
				return;
			}
		}
		add_settings_error( self::OPTION, $code, esc_html( $message ) );
	}

	/**
	 * Stashes a rejected submission of the tab. / 拒否されたタブの送信内容を残す。
	 *
	 * @param string[] $roles Role => method. / 権限 => 方式。
	 * @return void
	 */
	private static function stash_settings_resubmit( $roles ) {
		set_transient( self::SETTINGS_RESUBMIT_PREFIX . get_current_user_id(), array( 'roles' => $roles ), self::RESUBMIT_TTL );
	}

	/**
	 * Returns (and deletes) a rejected submission of the tab. / 拒否されたタブの送信内容を返し、消す。
	 *
	 * @return string[]|null Role => method, or null. / 権限 => 方式、または null。
	 */
	private static function take_settings_resubmit() {
		$key  = self::SETTINGS_RESUBMIT_PREFIX . get_current_user_id();
		$data = get_transient( $key );
		delete_transient( $key );

		return ( is_array( $data ) && isset( $data['roles'] ) && is_array( $data['roles'] ) ) ? $data['roles'] : null;
	}

	/**
	 * Finds up to $limit users who are targets under the given role methods and pass $filter, in login name
	 * order. Only users who can be targets at all are loaded: holding a role set to 'email', or set to
	 * 'email' themselves. / 与えた方式のもとで対象になり、$filter を通るユーザーを、ログイン名順で最大
	 * $limit 人探す。対象になりうる人（方式が 'email' の権限を持つ、または自分で 'email'）だけを読み込む。
	 *
	 * @param string[]      $role_methods Role => method. / 権限 => 方式。
	 * @param int           $limit        Maximum. / 上限。
	 * @param callable|null $filter       Extra condition, or null. / 追加の条件、または null。
	 * @return WP_User[] Users. / ユーザー。
	 */
	public static function find_targets( $role_methods, $limit, $filter = null ) {
		global $wpdb;

		$clauses  = array(
			'relation' => 'OR',
			array(
				'key'   => self::USER_METHOD_META,
				'value' => self::METHOD_EMAIL,
			),
		);
		$caps_key = $wpdb->get_blog_prefix( get_current_blog_id() ) . 'capabilities';
		foreach ( (array) $role_methods as $role => $method ) {
			if ( self::METHOD_EMAIL === $method ) {
				$clauses[] = array(
					'key'     => $caps_key,
					'value'   => '"' . $role . '"',
					'compare' => 'LIKE',
				);
			}
		}

		$ids = get_users(
			array(
				'fields'     => 'ID',
				'orderby'    => 'login',
				'order'      => 'ASC',
				'meta_query' => $clauses, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Narrows the users to look at; settings screen only.
			)
		);

		$found = array();
		foreach ( array_chunk( array_map( 'intval', $ids ), self::USERS_BATCH ) as $batch ) {
			cache_users( $batch );
			foreach ( $batch as $user_id ) {
				$user = get_userdata( $user_id );
				if ( ! $user || ! self::is_target( $user, $role_methods ) ) {
					continue;
				}
				if ( null !== $filter && ! call_user_func( $filter, $user ) ) {
					continue;
				}
				$found[] = $user;
				if ( count( $found ) >= (int) $limit ) {
					return $found;
				}
			}
		}

		return $found;
	}

	/**
	 * Number of application passwords of a user (7.7). / ユーザーのアプリケーションパスワードの件数（7.7）。
	 *
	 * @param int $user_id User ID. / ユーザー ID。
	 * @return int Count. / 件数。
	 */
	private static function count_application_passwords( $user_id ) {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return 0;
		}

		return count( (array) WP_Application_Passwords::get_user_application_passwords( (int) $user_id ) );
	}

	/**
	 * Prints the Two-Step Verification tab (7.10). Called from ACGD_Settings::render_page().
	 * 「2段階認証」タブを出す（7.10）。ACGD_Settings::render_page() から呼ぶ。
	 *
	 * Order: explanation, your own receive check, per-role methods, target users, emergency switch (the part
	 * the next one depends on comes first). The number of trusted days is added with PR-C (7.8).
	 * 並び：説明・自分の受信確認・権限ごとの方式・対象者の一覧・非常用スイッチ（依存元が先）。
	 * 信頼する日数は PR-C（7.8）で足す。
	 *
	 * @return void
	 */
	public static function render_settings_tab() {
		self::render_state_notices();
		$me = wp_get_current_user();
		?>
		<p>
			<?php
			echo wp_kses(
				acgd_join_sentences(
					array(
						esc_html__( 'Two-step verification asks for a verification code sent to the user\'s registered email address after the password has been accepted.', 'etbs-account-guard' ),
						esc_html__( 'It is set separately from Access Restriction, and a user held to both goes through both: first the password and IP restriction, then the verification code, then BASIC authentication on the next screen.', 'etbs-account-guard' ),
						esc_html__( 'Unlike Access Restriction, it can also be turned on for the administrator role.', 'etbs-account-guard' ),
					)
				),
				acgd_allowed_sentence_html()
			);
			?>
		</p>
		<p><?php echo esc_html( acgd_join_sentences( array( __( 'Its strength depends on the email account: anyone who can read the user\'s inbox can also reset the password.', 'etbs-account-guard' ), __( 'Use it for users whose email account is protected with multi-factor authentication.', 'etbs-account-guard' ) ) ) ); ?></p>

		<h2><?php esc_html_e( 'Your own email address', 'etbs-account-guard' ); ?></h2>
		<?php self::render_receive_check_box( $me, true ); ?>

		<form method="post" action="options.php">
			<?php settings_fields( self::SETTINGS_GROUP ); ?>
			<h2><?php esc_html_e( 'Two-step verification by role', 'etbs-account-guard' ); ?></h2>
			<p><?php echo esc_html( acgd_join_sentences( array( __( 'A user\'s own setting on their user edit screen wins over the role.', 'etbs-account-guard' ), __( 'A user who holds more than one role needs a verification code if any of them requires one.', 'etbs-account-guard' ) ) ) ); ?></p>
			<?php self::render_role_table(); ?>
			<?php submit_button(); ?>
		</form>
		<?php
		self::render_target_list();
		self::render_switch_explanation();
	}

	/**
	 * Prints the per-role method table. / 権限ごとの方式の表を出す。
	 *
	 * @return void
	 */
	private static function render_role_table() {
		$resubmit = self::take_settings_resubmit();
		$settings = self::get_settings();
		$methods  = null !== $resubmit ? $resubmit : $settings['roles'];
		?>
		<table class="widefat fixed striped" style="max-width:600px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Role', 'etbs-account-guard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Two-step verification', 'etbs-account-guard' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( wp_roles()->get_names() as $role => $label ) : ?>
					<?php
					$role_label = translate_user_role( $label );
					$method     = isset( $methods[ $role ] ) ? $methods[ $role ] : self::METHOD_NONE;
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $role_label ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION . '[roles][' . $role . ']' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: role name, such as Editor */ __( 'Two-step verification for %s', 'etbs-account-guard' ), $role_label ) ); ?>">
								<option value="none" <?php selected( self::METHOD_NONE, $method ); ?>><?php esc_html_e( 'None', 'etbs-account-guard' ); ?></option>
								<option value="email" <?php selected( self::METHOD_EMAIL, $method ); ?>><?php esc_html_e( 'Verification code (email)', 'etbs-account-guard' ); ?></option>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Prints the users who are currently targets, with whether they have an email address and how many
	 * application passwords they have (7.7, 7.10). / 今の対象者を、メールアドレスの有無とアプリケーション
	 * パスワードの件数とともに出す（7.7・7.10）。
	 *
	 * @return void
	 */
	private static function render_target_list() {
		$settings = self::get_settings();
		$targets  = self::find_targets( $settings['roles'], self::LIST_LIMIT );
		?>
		<h2><?php esc_html_e( 'Users who need a verification code', 'etbs-account-guard' ); ?></h2>
		<p><?php echo esc_html( acgd_join_sentences( array( __( 'Application passwords made before two-step verification was turned on keep working without a code.', 'etbs-account-guard' ), __( 'Review them on each user\'s edit screen.', 'etbs-account-guard' ) ) ) ); ?></p>
		<?php if ( ! $targets ) : ?>
			<p><?php esc_html_e( 'No user needs a verification code right now.', 'etbs-account-guard' ); ?></p>
			<?php
			return;
		endif;
		?>
		<div style="overflow-x:auto;">
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Login name (Username)', 'etbs-account-guard' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email address', 'etbs-account-guard' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Application passwords', 'etbs-account-guard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $targets as $user ) : ?>
						<?php
						$edit  = get_edit_user_link( $user->ID );
						$count = self::count_application_passwords( $user->ID );
						?>
						<tr>
							<td>
								<?php if ( $edit ) : ?>
									<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $user->user_login ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $user->user_login ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( is_email( $user->user_email ) ? __( 'Set', 'etbs-account-guard' ) : __( 'Missing or invalid', 'etbs-account-guard' ) ); ?></td>
							<td>
								<?php if ( $count && $edit ) : ?>
									<a href="<?php echo esc_url( $edit . '#application-passwords-section' ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></a>
								<?php else : ?>
									<?php echo esc_html( number_format_i18n( $count ) ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Prints the explanation of the emergency switch (7.11). One of the places it is documented (README.md,
	 * readme.txt and this tab). Not shown on the login screens (7.10). / 非常用スイッチの説明を出す（7.11）。
	 * 書く場所の1つ（README.md・readme.txt・このタブ）。ログイン画面には出さない（7.10）。
	 *
	 * @return void
	 */
	private static function render_switch_explanation() {
		?>
		<h2><?php esc_html_e( 'Emergency switch', 'etbs-account-guard' ); ?></h2>
		<p>
			<?php
			echo wp_kses(
				acgd_join_sentences(
					array(
						sprintf(
							/* translators: %s: PHP constant to add to wp-config.php, ACGD_DISABLE_TWO_STEP */
							esc_html__( 'If nobody can sign in because codes do not arrive, add %s to wp-config.php.', 'etbs-account-guard' ),
							'<code>' . esc_html( "define( 'ACGD_DISABLE_TWO_STEP', true );" ) . '</code>'
						),
						esc_html__( 'This stops two-step verification only; Access Restriction and Login Name Protection keep working.', 'etbs-account-guard' ),
						esc_html__( 'Define it also on a copy of this site whose email sending is turned off.', 'etbs-account-guard' ),
					)
				),
				acgd_allowed_sentence_html()
			);
			?>
		</p>
		<?php
	}

	/**
	 * Prints the fault and switch warnings (7.11). / 故障とスイッチの警告を出す（7.11）。
	 *
	 * @return void
	 */
	private static function render_state_notices() {
		if ( self::has_fault() ) {
			?>
			<div class="notice notice-error inline"><p><?php echo wp_kses( acgd_join_sentences( array( esc_html__( 'Two-step verification has a problem with its settings or its processing.', 'etbs-account-guard' ), esc_html__( 'Until this tab is saved again, users set to a verification code and users who can manage options still need a code; other users can sign in with their password.', 'etbs-account-guard' ) ) ), array() ); ?></p></div>
			<?php
		}
		if ( self::is_switch_disabled() ) {
			?>
			<div class="notice notice-warning inline"><p><?php echo wp_kses( sprintf( /* translators: %s: PHP constant, ACGD_DISABLE_TWO_STEP */ esc_html__( 'The emergency switch (%s in wp-config.php) is turned on, so two-step verification is stopped.', 'etbs-account-guard' ), '<code>ACGD_DISABLE_TWO_STEP</code>' ), array( 'code' => array() ) ); ?></p></div>
			<?php
		}
	}

	/**
	 * Warns people who can manage options on every other admin screen while this feature has a fault or is
	 * stopped by the switch (7.11). Not on this plugin's Two-Step Verification tab, which says it itself.
	 * この機能に故障があるか、スイッチで止まっている間、manage_options を持つ人に他の管理画面で警告する
	 * （7.11）。自分で出す「2段階認証」タブでは出さない。
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ACGD_Settings::SCREEN_ID === $screen->id && self::TAB === ACGD_Settings::get_current_tab() ) {
			return;
		}

		$has_fault = self::has_fault();
		$switch    = self::is_switch_disabled();
		if ( ! $has_fault && ! $switch ) {
			return;
		}

		$open = sprintf(
			/* translators: %s: URL of the Two-Step Verification tab of the settings screen */
			__( 'Open the <a href="%s">Two-Step Verification tab</a> of the settings screen for details.', 'etbs-account-guard' ),
			esc_url( ACGD_Settings::get_page_url( self::TAB ) )
		);
		$allowed = array(
			'a'    => array( 'href' => true ),
			'code' => array(),
		);

		if ( $has_fault ) {
			?>
			<div class="notice notice-error"><p><?php echo wp_kses( acgd_join_sentences( array( esc_html__( 'Two-step verification has a problem with its settings or its processing.', 'etbs-account-guard' ), $open ) ), $allowed ); ?></p></div>
			<?php
		}
		if ( $switch ) {
			?>
			<div class="notice notice-warning"><p><?php echo wp_kses( acgd_join_sentences( array( sprintf( /* translators: %s: PHP constant, ACGD_DISABLE_TWO_STEP */ esc_html__( 'The emergency switch (%s in wp-config.php) is turned on, so two-step verification is stopped.', 'etbs-account-guard' ), '<code>ACGD_DISABLE_TWO_STEP</code>' ), $open ) ), $allowed ); ?></p></div>
			<?php
		}
	}

	/*-------------------------------------------*/
	/* User edit screen and Users list (7.3, 7.10) / ユーザー編集画面とユーザー一覧（7.3・7.10）
	/*-------------------------------------------*/

	/**
	 * Prints the Two-Step Verification section of the user edit screen, for people who can manage options
	 * only (7.1: nothing for anyone else, including on their own profile). / ユーザー編集画面の
	 * 「2段階認証」の区画を出す。manage_options を持つ人だけ（7.1：本人のプロフィールを含め、他の人には何も出さない）。
	 *
	 * @param WP_User $user User being edited. / 編集対象のユーザー。
	 * @return void
	 */
	public static function render_user_fields( $user ) {
		if ( ! current_user_can( 'manage_options' ) || ! $user instanceof WP_User ) {
			return;
		}

		$is_self   = get_current_user_id() === (int) $user->ID;
		$resubmit  = self::take_user_resubmit( $user->ID );
		$method    = null !== $resubmit ? $resubmit : self::get_user_method( $user->ID );
		$follows   = self::is_target( $user, null, self::METHOD_FOLLOW );
		$app_count = self::count_application_passwords( $user->ID );
		?>
		<h2 id="acgd-two-step"><?php esc_html_e( 'Two-Step Verification', 'etbs-account-guard' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="acgd-two-step-method"><?php esc_html_e( 'Verification code for this user', 'etbs-account-guard' ); ?></label></th>
				<td>
					<select name="acgd_two_step_method" id="acgd-two-step-method">
						<option value="follow" <?php selected( self::METHOD_FOLLOW, $method ); ?>>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: what the role setting currently means for this user, such as "Verification code (email)" or "None" */
									__( 'Follow the role setting (currently: %s)', 'etbs-account-guard' ),
									$follows ? __( 'Verification code (email)', 'etbs-account-guard' ) : __( 'None', 'etbs-account-guard' )
								)
							);
							?>
						</option>
						<option value="none" <?php selected( self::METHOD_NONE, $method ); ?>><?php esc_html_e( 'None', 'etbs-account-guard' ); ?></option>
						<option value="email" <?php selected( self::METHOD_EMAIL, $method ); ?>><?php esc_html_e( 'Verification code (email)', 'etbs-account-guard' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'The code is sent to the email address of this account.', 'etbs-account-guard' ); ?></p>
				</td>
			</tr>
			<?php if ( $is_self ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Confirm your email address', 'etbs-account-guard' ); ?></th>
					<td><?php self::render_receive_check_box( $user, false ); ?></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Application passwords', 'etbs-account-guard' ); ?></th>
				<td>
					<?php
					echo esc_html(
						acgd_join_sentences(
							array(
								sprintf(
									/* translators: %s: number of application passwords */
									__( 'Application passwords: %s.', 'etbs-account-guard' ),
									number_format_i18n( $app_count )
								),
								__( 'They work without a verification code.', 'etbs-account-guard' ),
							)
						)
					);
					?>
					<?php if ( $app_count ) : ?>
						<a href="#application-passwords-section"><?php esc_html_e( 'Review them', 'etbs-account-guard' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
		wp_nonce_field( self::USER_NONCE_ACTION, self::USER_NONCE_NAME );
	}

	/**
	 * Validates and saves the method of the user edit screen (7.3). / ユーザー編集画面の方式を検証して保存する（7.3）。
	 *
	 * Checked only when the save turns Two-Step Verification on for the user: for oneself, a valid receive
	 * check for the stored address and for the address submitted in the same save; for someone else, a
	 * non-empty, valid email address. / 保存でそのユーザーに2段階認証が掛かることになるときだけ確かめる：
	 * 本人なら、保存済みのアドレスと同じ保存で送られたアドレスの両方について有効な受信確認。他人なら、
	 * 空でない正しいメールアドレス。
	 *
	 * @param int $user_id User being saved. / 保存対象のユーザー。
	 * @return void
	 */
	public static function save_user_fields( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::USER_NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::USER_NONCE_NAME ] ) ), self::USER_NONCE_ACTION ) ) {
			return;
		}

		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}

		$method = isset( $_POST['acgd_two_step_method'] ) ? sanitize_key( wp_unslash( $_POST['acgd_two_step_method'] ) ) : self::METHOD_FOLLOW;
		if ( ! in_array( $method, array( self::METHOD_FOLLOW, self::METHOD_NONE, self::METHOD_EMAIL ), true ) ) {
			$method = self::METHOD_FOLLOW;
		}

		$posted_email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : $user->user_email;

		if ( ! self::is_target( $user ) && self::is_target( $user, null, $method ) ) {
			if ( get_current_user_id() === (int) $user->ID ) {
				if ( ! self::has_valid_receive_check( $user->ID, array( $user->user_email, $posted_email ) ) ) {
					self::$pending_error = esc_html(
						acgd_join_sentences(
							array(
								__( 'This would turn on two-step verification for your own account.', 'etbs-account-guard' ),
								__( 'Confirm your email address with "Send a confirmation code" in the Two-Step Verification section first (the confirmation is valid for 10 minutes, and the address must not change in the same save).', 'etbs-account-guard' ),
								__( 'Not saved.', 'etbs-account-guard' ),
							)
						)
					);
					self::stash_user_resubmit( $user->ID, $method );
					return;
				}
			} elseif ( ! is_email( $posted_email ) ) {
				self::$pending_error = esc_html( acgd_join_sentences( array( __( 'Two-step verification cannot be turned on for this user because the account has no valid email address.', 'etbs-account-guard' ), __( 'Not saved.', 'etbs-account-guard' ) ) ) );
				self::stash_user_resubmit( $user->ID, $method );
				return;
			}
		}

		update_user_meta( $user->ID, self::USER_METHOD_META, $method );
	}

	/**
	 * Attaches the validation error from save_user_fields() to the profile update's errors (core then saves
	 * nothing). / save_user_fields() の検証エラーをプロフィール更新のエラーに足す（本体は何も保存しない）。
	 *
	 * @param WP_Error $errors Errors, changed in place. / エラー（その場で変える）。
	 * @return void
	 */
	public static function append_pending_error( $errors ) {
		if ( '' !== self::$pending_error && $errors instanceof WP_Error ) {
			$errors->add( 'acgd_two_step', self::$pending_error );
		}
	}

	/**
	 * Stashes a method chosen on the user edit screen for redisplay. / ユーザー編集画面で選んだ方式を出し直し用に残す。
	 *
	 * @param int    $target_id User being edited. / 編集対象。
	 * @param string $method    Method. / 方式。
	 * @return void
	 */
	private static function stash_user_resubmit( $target_id, $method ) {
		if ( ! in_array( $method, array( self::METHOD_FOLLOW, self::METHOD_NONE, self::METHOD_EMAIL ), true ) ) {
			return;
		}
		set_transient( self::USER_RESUBMIT_PREFIX . get_current_user_id() . '_' . (int) $target_id, $method, self::RESUBMIT_TTL );
	}

	/**
	 * Returns (and deletes) a stashed method. / 残した方式を返し、消す。
	 *
	 * @param int $target_id User being edited. / 編集対象。
	 * @return string|null Method, or null. / 方式、または null。
	 */
	private static function take_user_resubmit( $target_id ) {
		$key  = self::USER_RESUBMIT_PREFIX . get_current_user_id() . '_' . (int) $target_id;
		$data = get_transient( $key );
		delete_transient( $key );

		return in_array( $data, array( self::METHOD_FOLLOW, self::METHOD_NONE, self::METHOD_EMAIL ), true ) ? $data : null;
	}

	/**
	 * Adds the Two-Step Verification column to the Users list (manage_options only).
	 * ユーザー一覧に「2段階認証」列を足す（manage_options のみ）。
	 *
	 * @param string[] $columns Columns. / 列。
	 * @return string[] Columns. / 列。
	 */
	public static function add_column( $columns ) {
		if ( current_user_can( 'manage_options' ) ) {
			$columns[ self::COLUMN ] = __( 'Two-Step Verification', 'etbs-account-guard' );
		}

		return $columns;
	}

	/**
	 * Prints the column: "Email" for a target, a dash otherwise (7.10). / 列の値。対象なら「メール」、それ以外は「—」（7.10）。
	 *
	 * @param string $value       Value so far. / それまでの値。
	 * @param string $column_name Column. / 列。
	 * @param int    $user_id     User. / ユーザー。
	 * @return string Escaped value. / エスケープ済みの値。
	 */
	public static function render_column( $value, $column_name, $user_id ) {
		if ( self::COLUMN !== $column_name || ! current_user_can( 'manage_options' ) ) {
			return $value;
		}
		$user = get_userdata( (int) $user_id );

		return esc_html( $user && self::is_target( $user ) ? __( 'Email', 'etbs-account-guard' ) : '—' );
	}
}
