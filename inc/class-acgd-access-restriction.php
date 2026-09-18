<?php
/**
 * Access Restriction: IP restriction, BASIC authentication and the shared foundation
 * (docs/spec.md 5.1, 5.2, 5.3, 5.4, 5.5).
 * アクセス制限：IP 制限・BASIC 認証と共通の土台（docs/spec.md 5.1・5.2・5.3・5.4・5.5）。
 *
 * BASIC authentication's own credential storage and request-time verification (matching submitted
 * PHP_AUTH_* / Authorization headers against a user, and stripping them from $_SERVER once matched) live
 * in ACGD_Basic_Auth; this class only stores the id/password-hash user meta and calls into that class from
 * its own enforcement hooks (check_access_on_request(), filter_rest_authentication_errors()), the same way
 * it already enforces IP restriction.
 * BASIC 認証自身の資格情報の保存と、リクエスト時の照合（送信された PHP_AUTH_* / Authorization ヘッダーを
 * ユーザーと突き合わせ、一致したら $_SERVER から消す処理）は ACGD_Basic_Auth に置く。このクラスは
 * ID・パスワードハッシュのユーザーメタだけを持ち、既存の IP 制限の判定と同じ場所（check_access_on_request()・
 * filter_rest_authentication_errors()）から ACGD_Basic_Auth を呼び出す。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modes, IP matching, the denial log, the emergency switch, and the hooks that enforce IP restriction.
 * モード・IP の判定・拒否の記録・非常用スイッチ、そして IP 制限を効かせるフック。
 */
class ACGD_Access_Restriction {

	/**
	 * Option that stores the per-role modes and the site-wide IP list (registered with register_setting()).
	 * 権限ごとのモードと、サイトの IP 一覧を保存するオプション（register_setting() で登録）。
	 *
	 * @var string
	 */
	const OPTION = 'acgd_access_restriction';

	/**
	 * Option that holds the denial log (docs/spec.md 5.4). Not autoloaded: it is read only on its own
	 * settings tab, and can grow up to DENIAL_LOG_MAX entries.
	 * 拒否の記録を持つオプション（docs/spec.md 5.4）。autoload しない。専用タブでしか読まず、
	 * 最大 DENIAL_LOG_MAX 件まで増えうるため。
	 *
	 * @var string
	 */
	const DENIAL_LOG_OPTION = 'acgd_access_denial_log';

	/**
	 * Maximum number of entries kept in the denial log (docs/spec.md 5.4). / 拒否の記録に残す最大件数（docs/spec.md 5.4）。
	 *
	 * @var int
	 */
	const DENIAL_LOG_MAX = 100;

	/**
	 * Option that records a fault of this feature (docs/spec.md 5.5), so an admin screen can warn about it.
	 * Not autoloaded, and only ever holds a small array; temporary state, rebuilt or cleared by the next
	 * successful check or settings save.
	 * この機能の故障を記録するオプション（docs/spec.md 5.5）。管理画面で警告を出すためのもの。autoload しない。
	 * 小さな配列しか持たない一時状態で、次に判定が成功するか設定を保存すると作り直されるか消える。
	 *
	 * @var string
	 */
	const FAULT_OPTION = 'acgd_access_restriction_fault';

	/**
	 * User meta that stores one user's own mode ('follow', 'none', 'ip' or 'basic'; default 'follow').
	 * 1人のユーザー自身のモードを持つユーザーメタ（'follow'・'none'・'ip'・'basic'。既定は 'follow'）。
	 *
	 * @var string
	 */
	const USER_MODE_META = 'acgd_access_mode';

	/**
	 * User meta that stores the IP addresses added for one user, on top of the site-wide list.
	 * サイトの一覧に加えて、そのユーザーに追加した IP アドレスを持つユーザーメタ。
	 *
	 * @var string
	 */
	const USER_IPS_META = 'acgd_user_ips';

	/**
	 * User meta that stores one user's BASIC authentication ID (docs/spec.md 5.3). Not a secret by itself
	 * (comparable to a login name), so it is stored as plain text and may be redisplayed on the user edit
	 * screen, unlike the password.
	 * 1人のユーザーの BASIC 認証の ID を持つユーザーメタ（docs/spec.md 5.3）。それ自体は秘密ではない
	 * （ログイン名に近い）ため平文で保存し、パスワードとは違いユーザー編集画面での再表示もしてよい。
	 *
	 * @var string
	 */
	const USER_BASIC_ID_META = 'acgd_basic_id';

	/**
	 * User meta that stores the password_hash() of one user's BASIC authentication password. Never read back
	 * for display; only password_verify() against a submitted password.
	 * 1人のユーザーの BASIC 認証パスワードの password_hash() を持つユーザーメタ。表示用に読み出すことは無く、
	 * 送信されたパスワードとの password_verify() にだけ使う。
	 *
	 * @var string
	 */
	const USER_BASIC_HASH_META = 'acgd_basic_password_hash';

	/**
	 * Option that caches how many users currently have a non-empty BASIC authentication ID saved (security
	 * review, MEDIUM/performance, 2026-09-11: without this, maybe_strip_own_header() — a determine_current_user
	 * filter at priority 1, so it runs on every request that carries an Authorization header, including
	 * Application Passwords — ran a get_users( meta_key EXISTS ) query even on sites with nobody in BASIC
	 * mode). Kept in sync at the single place that writes USER_BASIC_ID_META
	 * (ACGD_User_Access::save_fields(), via update_basic_id_count()), which recomputes the true count from
	 * usermeta on that rare, manual save so it always reflects reality. Read on the hot path without a query
	 * of its own. Autoloaded on purpose: it is read on that same hot path, so keeping it in
	 * the autoloaded options cache (loaded once per request regardless) costs nothing extra, unlike a
	 * dedicated get_option() call for a non-autoloaded value. This is a derived cache, not something a user
	 * configured, so it belongs with the "temporary state" that uninstall.php deletes (docs/spec.md 3.6),
	 * the same as the denial log and the diagnosis result.
	 * BASIC 認証の ID を現在いくつのユーザーが持っているか（空文字でないもの）をキャッシュするオプション
	 * （セキュリティレビュー・MEDIUM／性能、2026-09-11：これが無いと maybe_strip_own_header()——
	 * determine_current_user フィルタの優先度1で、Authorization ヘッダーを持つリクエストすべて
	 * （アプリケーションパスワードを含む）で動く——が、BASIC モードの利用者が1人もいないサイトでも
	 * get_users( meta_key EXISTS ) を毎回実行していた）。USER_BASIC_ID_META を書き込む唯一の場所
	 * （ACGD_User_Access::save_fields()。update_basic_id_count() 経由）でだけ、その稀な手動保存のたびに
	 * usermeta から真の値を再計算するため常に実態と一致する。読み取り側の高頻度経路では自前のクエリ無しで
	 * 読める。autoload するのは意図的：同じ高頻度の経路で読むため、どのみち1リクエストに
	 * 1回読み込まれる autoload オプションのキャッシュに乗せれば追加コストが無い（autoload しない値の
	 * 専用 get_option() はそのぶんの問い合わせが増える）。これは利用者が設定した値ではなく派生的な
	 * キャッシュなので、uninstall.php が消す「一時状態」（docs/spec.md 3.6）に属する。拒否の記録・
	 * 診断結果と同じ扱い。
	 *
	 * @var string
	 */
	const BASIC_ID_COUNT_OPTION = 'acgd_basic_id_count';

	const MODE_FOLLOW = 'follow';
	const MODE_NONE   = 'none';
	const MODE_IP     = 'ip';
	const MODE_BASIC  = 'basic';

	/**
	 * Number of candidate users loaded at a time by find_restricted_users(). / find_restricted_users() が一度に読み込む候補の人数。
	 *
	 * @var int
	 */
	const RESTRICTED_USERS_BATCH = 200;

	/**
	 * Priority of the wp_authenticate_user filter that judges IP restriction at login.
	 * ログイン時に IP 制限を判定する wp_authenticate_user フィルタの優先度。
	 *
	 * Core checks the password only after this filter returns a WP_User (wp_authenticate_username_password() /
	 * wp_authenticate_email_password()), so any priority here still runs before the password is checked.
	 * Plugins that add a gate of their own, such as a CAPTCHA, commonly hook the same filter at a low priority
	 * (SiteGuard WP Plugin 1.7.8 uses 1). Running well after them (99) means: a wrong CAPTCHA answer gets the
	 * CAPTCHA plugin's own error, unrelated to whether the account is restricted, and only an input that
	 * passes the CAPTCHA reaches this check, where a restricted account gets the same common error as a wrong
	 * password. Running before them would instead let a restricted account's CAPTCHA failures and successes
	 * look different (always the common error, regardless of the CAPTCHA), which tells an attacker the
	 * account exists and is restricted. See docs/spec.md 5.2 and ~/.claude/etbs-plugin-rules.md 3節.
	 *
	 * 本体はこのフィルタが WP_User を返した後にだけパスワードを照合する
	 * （wp_authenticate_username_password() / wp_authenticate_email_password()）ので、
	 * ここでの優先度がいくつでもパスワードの照合より前になる。画像認証などの関門を足すプラグインは、
	 * 同じフィルタの低い優先度で動くことが多い（SiteGuard WP Plugin 1.7.8 は 1）。それより十分後ろ（99）で
	 * 動かすと、画像認証の誤答はそのプラグイン自身のエラーになり（対象かどうかに関係しない）、
	 * 画像認証を通った入力だけがここへ進み、対象で許可されていなければ誤ったパスワードと同じ共通のエラーになる。
	 * 逆に先に動かすと、対象アカウントは画像認証の成否に関係なく常に共通のエラーになり、
	 * 画像認証の誤答との違いで「対象である」ことが攻撃者に伝わってしまう。docs/spec.md 5.2 と
	 * ~/.claude/etbs-plugin-rules.md 3節を参照。
	 *
	 * @var int
	 */
	const WP_AUTHENTICATE_USER_PRIORITY = 99;

	/**
	 * Priority of the rest_authentication_errors filter that judges IP restriction on the REST API.
	 * REST API で IP 制限を判定する rest_authentication_errors フィルタの優先度。
	 *
	 * Core surfaces an application password failure at priority 90 (rest_application_password_check_errors)
	 * and checks the cookie at priority 100 (rest_cookie_check_errors). Running after both (here: 200) means
	 * the current user this filter sees is whatever core (or another plugin) has already determined, so this
	 * check never itself becomes the thing that determines the current user early. It also runs comfortably
	 * before priority 9999, where ACGD_Login_Name replaces the remaining "account exists" errors with the
	 * common one. See docs/spec.md 5.2.
	 *
	 * 本体はアプリケーションパスワードの失敗を優先度 90（rest_application_password_check_errors）で返し、
	 * cookie の確認を優先度 100（rest_cookie_check_errors）で行う。両方より後ろ（ここでは 200）で動かすと、
	 * このフィルタが見る「現在のユーザー」は本体（や他プラグイン）が既に確定させたものになり、
	 * この判定自体が現在のユーザーを早く確定させる原因にならない。優先度 9999（ACGD_Login_Name が
	 * 残った「アカウントの有無が分かる」エラーを共通のエラーに差し替える）より十分前にも収まる。
	 * docs/spec.md 5.2 を参照。
	 *
	 * @var int
	 */
	const REST_AUTHENTICATION_PRIORITY = 200;

	/**
	 * Priority of the admin_init action that judges access on every admin-side request (check_access_on_request()).
	 * 管理画面側の毎回のアクセスを判定する admin_init の優先度（check_access_on_request()）。
	 *
	 * Named rather than left implicit because another class hooks admin_init relative to this one:
	 * ACGD_Basic_Auth::init() registers its "Verify" handler at this priority + 1, so that access to the
	 * current request is still judged first, exactly as on every other admin screen. Both sides read this
	 * constant, so the ordering cannot quietly break if this value is ever changed.
	 *
	 * 既定のままにせず名前を付けているのは、別のクラスがこれを基準に admin_init へ登録しているため：
	 * ACGD_Basic_Auth::init() は「確認」のハンドラをこの優先度＋1で登録し、そのリクエストに対する
	 * アクセス制限の判定が先に効くようにしている（他の管理画面と全く同じ順序）。両方がこの定数を読むので、
	 * この値を変えても順序が黙って壊れることは無い。
	 *
	 * @var int
	 */
	const ADMIN_INIT_PRIORITY = 10;

	/**
	 * Registers the hooks. / フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		// Login (wp-login.php, XML-RPC, and anything else that calls wp_authenticate()). / ログイン（wp-login.php・XML-RPC など wp_authenticate() を通るもの）。
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'filter_wp_authenticate_user' ), self::WP_AUTHENTICATE_USER_PRIORITY );

		// Application passwords, for the target user and for the REST API. / アプリケーションパスワード（対象ユーザーと REST API の両方）。
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'filter_app_passwords_available' ), 10, 2 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'filter_rest_authentication_errors' ), self::REST_AUTHENTICATION_PRIORITY );

		/*
		 * Every access after login: admin_init covers wp-admin screens and, because both admin-ajax.php and
		 * admin-post.php load wp-admin/includes/admin.php before dispatching, wp_ajax_* and admin_post_*
		 * actions as well (a check limited to is_admin() would miss those two). template_redirect covers the front
		 * end. The REST API never reaches either hook (it exits from parse_request before template_redirect,
		 * and has its own bootstrap), which is why it has its own filter above.
		 *
		 * ログイン後の毎回のアクセス：admin_init は管理画面の画面に加え、admin-ajax.php と admin-post.php が
		 * どちらもディスパッチの前に wp-admin/includes/admin.php を読み込むため、wp_ajax_* / admin_post_*
		 * のアクションにも掛かる（is_admin() だけで絞ると、その2つを取りこぼす）。template_redirect はフロントを
		 * 担う。REST API はどちらにも来ない（parse_request の時点で抜け、テンプレートより前に終わる）ため、
		 * 上の専用のフィルタで別に扱っている。
		 */
		add_action( 'admin_init', array( __CLASS__, 'check_access_on_request' ), self::ADMIN_INIT_PRIORITY );
		add_action( 'template_redirect', array( __CLASS__, 'check_access_on_request' ) );
	}

	/*-------------------------------------------*/
	/* Settings storage / 設定の保存
	/*-------------------------------------------*/

	/**
	 * Returns the saved per-role modes: role => 'none' | 'ip' | 'basic'. A role missing from the saved value
	 * (including every role when the option is missing or broken) is 'none', the protected default.
	 * 保存済みの権限ごとのモードを返す（権限 => 'none' | 'ip' | 'basic'）。保存値に無い権限
	 * （オプションが無い・壊れているときはすべての権限）は、既定の 'none' になる。
	 *
	 * administrator never appears here even if it was submitted; see sanitize_role_modes() in class-acgd-settings.php.
	 * administrator は、送信されていてもここには現れない（class-acgd-settings.php の sanitize_role_modes() を参照）。
	 *
	 * @return string[] Role => mode. / 権限 => モード。
	 */
	public static function get_role_modes() {
		$settings = get_option( self::OPTION, array() );
		$roles    = ( is_array( $settings ) && isset( $settings['roles'] ) && is_array( $settings['roles'] ) ) ? $settings['roles'] : array();

		$valid = array();
		foreach ( $roles as $role => $mode ) {
			if ( is_string( $role ) && in_array( $mode, array( self::MODE_NONE, self::MODE_IP, self::MODE_BASIC ), true ) ) {
				$valid[ $role ] = $mode;
			}
		}

		return $valid;
	}

	/**
	 * Returns the saved site-wide IP list, as the raw text of the textarea (one entry per line, with comments).
	 * 保存済みのサイトの IP 一覧を、テキストエリアの生の文字列で返す（1行1件、コメット付き）。
	 *
	 * @return string Raw text, empty when there is none. / 生の文字列（無ければ空文字）。
	 */
	public static function get_site_ip_text() {
		$settings = get_option( self::OPTION, array() );

		return ( is_array( $settings ) && isset( $settings['site_ips'] ) && is_string( $settings['site_ips'] ) ) ? $settings['site_ips'] : '';
	}

	/**
	 * Returns one user's own mode: 'follow' (default), 'none', 'ip' or 'basic'.
	 * ユーザー自身のモードを返す（既定 'follow'、他に 'none'・'ip'・'basic'）。
	 *
	 * @param int $user_id User ID. / ユーザー ID。
	 * @return string Mode. / モード。
	 */
	public static function get_user_mode( $user_id ) {
		$mode = get_user_meta( (int) $user_id, self::USER_MODE_META, true );

		return in_array( $mode, array( self::MODE_NONE, self::MODE_IP, self::MODE_BASIC ), true ) ? $mode : self::MODE_FOLLOW;
	}

	/**
	 * Returns the IP addresses added for one user, as raw text (docs/spec.md 5.2, "ユーザーごとの追加").
	 * 1ユーザーに追加した IP アドレスを、生の文字列で返す（docs/spec.md 5.2「ユーザーごとの追加」）。
	 *
	 * @param int $user_id User ID. / ユーザー ID。
	 * @return string Raw text, empty when there is none. / 生の文字列（無ければ空文字）。
	 */
	public static function get_user_ip_text( $user_id ) {
		$text = get_user_meta( (int) $user_id, self::USER_IPS_META, true );

		return is_string( $text ) ? $text : '';
	}

	/*-------------------------------------------*/
	/* BASIC authentication credentials (docs/spec.md 5.3) / BASIC 認証の資格情報（docs/spec.md 5.3）
	/*-------------------------------------------*/

	/**
	 * Returns one user's BASIC authentication ID, or an empty string when none is set.
	 * 1ユーザーの BASIC 認証の ID を返す。未設定なら空文字。
	 *
	 * @param int $user_id User ID. / ユーザー ID。
	 * @return string ID, as stored (not a secret; safe to redisplay). / 保存されている ID（秘密ではなく再表示してよい）。
	 */
	public static function get_basic_id( $user_id ) {
		$id = get_user_meta( (int) $user_id, self::USER_BASIC_ID_META, true );

		return is_string( $id ) ? $id : '';
	}

	/**
	 * Tells whether one user has both a BASIC authentication ID and a password hash saved. Used by save-time
	 * check 3 (docs/spec.md 5.1, "BASIC モードになるユーザーは全員が資格情報を設定済み").
	 * 1ユーザーが BASIC 認証の ID とパスワードハッシュの両方を保存済みかを返す。保存時のチェック3
	 * （docs/spec.md 5.1「BASIC モードになるユーザーは全員が資格情報を設定済み」）に使う。
	 *
	 * @param int $user_id User ID. / ユーザー ID。
	 * @return bool Whether both are set. / 両方とも設定済みか。
	 */
	public static function has_basic_credentials( $user_id ) {
		if ( '' === self::get_basic_id( $user_id ) ) {
			return false;
		}

		$hash = get_user_meta( (int) $user_id, self::USER_BASIC_HASH_META, true );

		return is_string( $hash ) && '' !== $hash;
	}

	/**
	 * Returns every user of the current site who has a BASIC authentication ID saved (candidates for
	 * basic_id_taken_by_other() and find_basic_user_by_credentials()).
	 * 現在のサイトで BASIC 認証の ID を保存済みの全ユーザーを返す（basic_id_taken_by_other() と
	 * find_basic_user_by_credentials() の候補）。
	 *
	 * Checks BASIC_ID_COUNT_OPTION first and returns an empty array without a query at all when it is zero
	 * (security review, MEDIUM/performance, 2026-09-11) — the common case on a site where nobody uses BASIC
	 * mode, including every request that reaches find_basic_user_by_credentials() through
	 * ACGD_Basic_Auth::maybe_strip_own_header().
	 * まず BASIC_ID_COUNT_OPTION を見て、0件ならクエリすら実行せず空配列を返す（セキュリティレビュー・
	 * MEDIUM／性能、2026-09-11）。BASIC モードの利用者が1人もいないサイトでの通常の場合に当たり、
	 * ACGD_Basic_Auth::maybe_strip_own_header() 経由で find_basic_user_by_credentials() に届くリクエスト
	 * すべてがこれに該当する。
	 *
	 * @return WP_User[] Users with a BASIC ID saved. / BASIC ID を保存済みのユーザー。
	 */
	private static function get_users_with_basic_id() {
		if ( (int) get_option( self::BASIC_ID_COUNT_OPTION, 0 ) < 1 ) {
			return array();
		}

		return get_users(
			array(
				'meta_key'     => self::USER_BASIC_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Settings/profile screens only; the number of users with a BASIC ID is small by nature (one credential per person, set up by hand), and BASIC_ID_COUNT_OPTION above already skips this entirely when nobody has one.
				'meta_compare' => 'EXISTS',
				'fields'       => 'all',
			)
		);
	}

	/**
	 * Keeps BASIC_ID_COUNT_OPTION in sync after a save that may have changed whether one user's BASIC
	 * authentication ID is non-empty. Called from the single place that writes USER_BASIC_ID_META
	 * (ACGD_User_Access::save_fields()), right after that write. A no-op unless presence actually flipped
	 * (someone's ID field going from blank to set, or set to blank), so repeated saves that leave BASIC
	 * credentials untouched — the overwhelming majority, since most saves are of the IP or "no restriction"
	 * modes — never touch this option at all.
	 *
	 * Recomputes the true count from the usermeta table rather than adjusting the cached value by +1/-1
	 * (security review, MEDIUM/race condition, 2026-09-11): two admins saving different users' BASIC settings
	 * at nearly the same time would otherwise race on the same read-modify-write of this option, and one
	 * increment/decrement would be lost. Because the value was never a re-derivation but a running diff, a
	 * loss here does not self-heal — it persists until someone happens to flip presence again. If the drift
	 * lands on 0 while at least one user still has an ID, get_users_with_basic_id() would start returning an
	 * empty array and lock out every existing, legitimately-configured BASIC user. A save here is a rare,
	 * manual admin action (docs/spec.md 5.6, only reachable from the user edit screen's own section), so
	 * recomputing with a direct COUNT query on every flip costs nothing that matters — unlike
	 * get_users_with_basic_id() above, which stays a cached-count-guarded read because that path runs on
	 * every request carrying an Authorization header.
	 * BASIC_ID_COUNT_OPTION を、1人のユーザーの BASIC 認証 ID の有無が保存で変わったかもしれないことに
	 * 合わせて更新する。USER_BASIC_ID_META を書き込む唯一の場所（ACGD_User_Access::save_fields()）から、
	 * その書き込み直後に呼ぶ。ID の有無が実際に変わったとき（空→設定、設定→空）以外は何もしない。BASIC の
	 * 資格情報に触れない保存（大多数を占める IP や「制限なし」モードの保存）では、このオプションに一切触れない。
	 *
	 * 算術的な +1/-1 でキャッシュ値を調整するのではなく、usermeta テーブルから真の値を再計算する
	 * （セキュリティレビュー・中／レースコンディション、2026-09-11）：そうしないと、2人の管理者がほぼ
	 * 同時に別々のユーザーの BASIC 設定を保存したとき、同じオプションへの read-modify-write が競合し、
	 * 片方の増減が失われる。この値はもともと再計算ではなく差分の積み上げだったため、一度失われても
	 * 自己修復せず、次に誰かが偶然また有無を変えるまでズレたままになる。実際には1人以上 BASIC ID を
	 * 持つのにズレが0まで下がると、get_users_with_basic_id() が空配列を返すようになり、既存の正当な
	 * BASIC 利用者が全員ロックアウトされる。ここでの保存はレアな手動の管理操作（docs/spec.md 5.6、
	 * ユーザー編集画面のこの区画からしか届かない）なので、有無が変わるたびに直接の COUNT クエリで
	 * 再計算しても実害のあるコストにはならない——高頻度な読み取り経路（Authorization ヘッダーを持つ
	 * リクエストすべてで動く）である上の get_users_with_basic_id() をキャッシュされた件数で早期リターン
	 * させ続けているのとは目的が異なる。
	 *
	 * @param bool $had_id Whether the user had a non-empty BASIC authentication ID before this save. / 保存前に BASIC 認証 ID を持っていたか。
	 * @param bool $has_id Whether the user has a non-empty BASIC authentication ID after this save. / 保存後に BASIC 認証 ID を持っているか。
	 * @return void
	 */
	public static function update_basic_id_count( $had_id, $has_id ) {
		if ( (bool) $had_id === (bool) $has_id ) {
			return;
		}

		global $wpdb;

		// Only the meta key goes through a placeholder; the empty-string comparison is a literal, not user
		// input. No cache is warmed for this (WordPress core has none for a raw aggregate like this), matching
		// the same direct-query pattern used by ACGD_Login_Name::count_users_with_login_as_public_name().
		// プレースホルダに通すのは meta_key だけで、空文字との比較はリテラル（利用者入力ではない）。
		// この種の集約に対する WordPress 本体のキャッシュは無く、ACGD_Login_Name::count_users_with_login_as_public_name()
		// と同じ直接クエリの型に揃えている。
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No core API aggregates "how many users have a non-empty value for this meta key". Rare admin-save path only.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != ''",
				self::USER_BASIC_ID_META
			)
		);
		// phpcs:enable

		update_option( self::BASIC_ID_COUNT_OPTION, $count, true ); // Autoloaded on purpose; see BASIC_ID_COUNT_OPTION. / 意図的に autoload する。理由は BASIC_ID_COUNT_OPTION を参照。
	}

	/**
	 * Tells whether a BASIC authentication ID is already used by a different user (docs/spec.md 5.3,
	 * "ID はサイト内で重複させない"). The ID is not a secret, so a plain, non-constant-time comparison would
	 * be fine on its own merits; hash_equals() is used anyway for the same string-comparison discipline as
	 * the rest of this feature (docs/spec.md 5.3, "文字列比較は hash_equals").
	 * ある BASIC 認証の ID が、別のユーザーに既に使われているかを返す（docs/spec.md 5.3「ID はサイト内で
	 * 重複させない」）。ID 自体は秘密ではないので、比較そのものは通常の等値比較でも安全上は問題ないが、
	 * この機能全体の文字列比較の作法（docs/spec.md 5.3「文字列比較は hash_equals」）に揃えて hash_equals() を使う。
	 *
	 * @param string $id               ID to check. / 確かめる ID。
	 * @param int    $exclude_user_id  User allowed to already have this ID (the one being saved). / この ID を既に持っていてよいユーザー（保存対象本人）。
	 * @return bool Whether another user already has it. / 別のユーザーが既に持っているか。
	 */
	public static function basic_id_taken_by_other( $id, $exclude_user_id ) {
		if ( '' === $id ) {
			return false;
		}

		foreach ( self::get_users_with_basic_id() as $user ) {
			if ( (int) $user->ID === (int) $exclude_user_id ) {
				continue;
			}
			if ( hash_equals( self::get_basic_id( $user->ID ), (string) $id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Finds the user whose BASIC authentication credentials match the given ID and password, if any.
	 * 与えた ID とパスワードに一致する BASIC 認証の資格情報を持つユーザーを返す（無ければ null）。
	 *
	 * @param string $id       Submitted ID. / 送信された ID。
	 * @param string $password Submitted password (plain text). / 送信されたパスワード（平文）。
	 * @return WP_User|null Matching user, or null. / 一致するユーザー。無ければ null。
	 */
	public static function find_basic_user_by_credentials( $id, $password ) {
		if ( '' === $id || '' === $password ) {
			return null;
		}

		foreach ( self::get_users_with_basic_id() as $user ) {
			if ( ! hash_equals( self::get_basic_id( $user->ID ), (string) $id ) ) {
				continue;
			}

			$hash = get_user_meta( $user->ID, self::USER_BASIC_HASH_META, true );
			if ( is_string( $hash ) && '' !== $hash && password_verify( (string) $password, $hash ) ) {
				return $user;
			}

			// The ID matched but the password did not (or no hash is saved): this ID is unique
			// (basic_id_taken_by_other() is enforced at save time), so no other user can match either.
			// ID は一致したがパスワードが違う（またはハッシュ未保存）：ID は一意にしてある
			// （保存時に basic_id_taken_by_other() で強制）ので、他のユーザーが一致することもない。
			return null;
		}

		return null;
	}

	/**
	 * Finds up to $limit users who end up in BASIC mode under the given (possibly prospective) role modes
	 * but have not saved their own BASIC credentials yet. Used by save-time check 3 (docs/spec.md 5.1).
	 * 与えた（保存前かもしれない）権限モードのもとで BASIC モードになるが、まだ自分の BASIC 資格情報を
	 * 保存していないユーザーを、最大 $limit 人まで探す。保存時のチェック3（docs/spec.md 5.1）に使う。
	 *
	 * Mirrors find_restricted_users(): only candidates that can end up restricted at all are loaded (the same
	 * meta query), narrowing what to look at without changing which users are actually judged restricted.
	 * find_restricted_users() と同じ作り。制限されうる候補だけを読み込み（同じメタクエリ）、
	 * 見る範囲を絞るだけで、実際に制限と判定されるユーザーの範囲は変えない。
	 *
	 * @param string[] $role_modes         Role => mode to test. / 判定する 権限 => モード。
	 * @param array    $forced_user_modes  User ID => mode, for users whose own setting is about to change. / ユーザー ID => モード（変更しようとしている分だけ）。
	 * @param int      $limit              Maximum number of users to return. / 返す最大人数。
	 * @return WP_User[] Users missing BASIC credentials. / BASIC 資格情報が未設定のユーザー。
	 */
	public static function find_basic_users_without_credentials( $role_modes, $forced_user_modes = array(), $limit = 5 ) {
		$limit   = (int) $limit;
		$missing = array();
		if ( $limit < 1 ) {
			return $missing;
		}

		$ids = get_users(
			array(
				'fields'     => 'ID',
				'orderby'    => 'login',
				'order'      => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Narrows the users to look at; the alternative is loading every user. Settings screen only. / 見るユーザーを絞るため。代わりは全ユーザーの読み込み。設定画面のみ。
				'meta_query' => self::restricted_candidates_meta_query( $role_modes ),
			)
		);

		foreach ( array_chunk( array_map( 'intval', $ids ), self::RESTRICTED_USERS_BATCH ) as $batch ) {
			cache_users( $batch );

			foreach ( $batch as $user_id ) {
				$user = get_userdata( $user_id );
				if ( ! $user ) {
					continue;
				}
				$forced = array_key_exists( $user_id, $forced_user_modes ) ? $forced_user_modes[ $user_id ] : null;
				$modes  = self::compute_effective_modes( $user, $role_modes, $forced );
				if ( in_array( self::MODE_BASIC, $modes, true ) && ! self::has_basic_credentials( $user_id ) ) {
					$missing[] = $user;
					if ( count( $missing ) >= $limit ) {
						return $missing;
					}
				}
			}
		}

		return $missing;
	}

	/*-------------------------------------------*/
	/* Effective mode / 実際に効くモード
	/*-------------------------------------------*/

	/**
	 * Computes which modes apply to a user, optionally against settings that are not saved yet.
	 * ユーザーに効くモードを求める。まだ保存していない設定に対しても計算できる。
	 *
	 * The user's own setting wins over the role setting (docs/spec.md 5.1, "ユーザーの設定が権限の設定より優先").
	 * When it is 'follow', every role the user holds is looked at (except administrator, which is always
	 * unrestricted at the role level; a user-level override can still restrict an administrator), and every
	 * mode required by any of them is required (docs/spec.md 5.1, "求められているものを全部課す") — the result
	 * can therefore hold both 'ip' and 'basic'.
	 * ユーザー自身の設定が権限の設定より優先する（docs/spec.md 5.1「ユーザーの設定が権限の設定より優先」）。
	 * 'follow' のときは、そのユーザーが持つ権限をすべて見る（administrator は権限単位では常に制限なし。
	 * ユーザー単位の上書きなら管理者も制限できる）。どれか1つでも求めるモードはすべて課す
	 * （docs/spec.md 5.1「求められているものを全部課す」）ので、結果に 'ip' と 'basic' の両方が入ることもある。
	 *
	 * @param WP_User    $user        User to judge. / 判定するユーザー。
	 * @param string[]   $role_modes  Role => mode, as get_role_modes() returns (or a prospective value). / 権限 => モード（get_role_modes() と同じ形。保存前の値でもよい）。
	 * @param string|null $forced_mode When given, used instead of the user's saved mode (for "what if" checks
	 *                                 on the value about to be saved). / 与えたときは、保存済みのユーザーの
	 *                                 モードの代わりに使う（保存しようとしている値での「もし」の判定用）。
	 * @return string[] Subset of array( 'ip', 'basic' ), empty when unrestricted. / array('ip','basic') の部分集合。制限なしなら空。
	 */
	public static function compute_effective_modes( $user, $role_modes, $forced_mode = null ) {
		if ( ! $user instanceof WP_User ) {
			return array();
		}

		$mode = null !== $forced_mode ? $forced_mode : self::get_user_mode( $user->ID );
		if ( self::MODE_FOLLOW !== $mode ) {
			return self::MODE_NONE === $mode ? array() : array( $mode );
		}

		$modes = array();
		foreach ( (array) $user->roles as $role ) {
			if ( 'administrator' === $role ) {
				continue; // Always unrestricted at the role level; see docs/spec.md 5.1. / 権限単位では常に制限なし（docs/spec.md 5.1）。
			}
			$role_mode = isset( $role_modes[ $role ] ) ? $role_modes[ $role ] : self::MODE_NONE;
			if ( self::MODE_NONE !== $role_mode ) {
				$modes[ $role_mode ] = true;
			}
		}

		return array_keys( $modes );
	}

	/**
	 * Computes which modes currently apply to a user, from the saved settings. / 保存済みの設定から、ユーザーに今効くモードを求める。
	 *
	 * @param WP_User|false|null $user User to judge. / 判定するユーザー。
	 * @return string[] Subset of array( 'ip', 'basic' ), empty when unrestricted. / array('ip','basic') の部分集合。制限なしなら空。
	 */
	public static function get_effective_modes( $user ) {
		if ( ! $user instanceof WP_User ) {
			return array();
		}

		return self::compute_effective_modes( $user, self::get_role_modes() );
	}

	/**
	 * Counts users who can manage options and are, under the given (possibly prospective) settings, entirely
	 * unrestricted. Used by save-time check 1 (docs/spec.md 5.1).
	 * manage_options を持ち、与えた（保存前かもしれない）設定のもとで完全に制限なしとなるユーザーを数える。
	 * 保存時のチェック1（docs/spec.md 5.1）に使う。
	 *
	 * @param string[] $role_modes         Role => mode to test. / 判定する 権限 => モード。
	 * @param array    $forced_user_modes  User ID => mode, for users whose own setting is about to change
	 *                                     (usually zero or one entry). Users not listed use their saved mode.
	 *                                     ユーザー ID => モード。設定を変えようとしているユーザーの分だけ渡す
	 *                                     （通常0件か1件）。無いユーザーは保存済みの値を使う。
	 * @return int Number of unrestricted users who can manage options. / 制限なしの manage_options ユーザーの数。
	 */
	public static function count_unrestricted_admins( $role_modes, $forced_user_modes = array() ) {
		$count = 0;

		// The candidates only narrow down whom to look at; whether each one counts is still decided by
		// user_can() and compute_effective_modes(), exactly as when every user was looked at.
		// 候補は「誰を見るか」を減らすだけ。数えるかどうかは、全ユーザーを見ていたときと同じく
		// user_can() と compute_effective_modes() で決める。
		foreach ( self::get_manage_options_candidates() as $user ) {
			if ( ! user_can( $user, 'manage_options' ) ) {
				continue;
			}
			$forced = array_key_exists( $user->ID, $forced_user_modes ) ? $forced_user_modes[ $user->ID ] : null;
			if ( empty( self::compute_effective_modes( $user, $role_modes, $forced ) ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Returns the users of the current site who may be able to manage options: the candidates for
	 * count_unrestricted_admins(). A superset is fine (the caller still checks user_can() on each one); what
	 * matters is not dropping anyone that user_can() would accept, and not going beyond the users that
	 * get_users() without arguments returns (on multisite, the members of the current site).
	 * 現在のサイトで manage_options を持ちうるユーザー（count_unrestricted_admins() の候補）を返す。
	 * 余分に含むのは構わない（呼び出し側が1人ずつ user_can() を見る）。大事なのは、user_can() が認める人を
	 * 落とさないことと、引数なしの get_users() が返す範囲（マルチサイトでは現在のサイトのメンバー）を
	 * 超えないこと。
	 *
	 * The 'capability' argument of WP_User_Query (WordPress 5.9 and later) matches users who hold the
	 * capability through a role of this site or as a capability given to the user directly. On WordPress
	 * before 5.9 the argument is ignored and every user is returned: slower, but the result of
	 * count_unrestricted_admins() is the same (and nothing fatal happens; "Requires at least" is not declared).
	 * WP_User_Query の 'capability' 引数（WordPress 5.9 以上）は、このサイトの権限経由で持っている人と、
	 * ユーザー個別に付けた capability として持っている人の両方を拾う。5.9 未満ではこの引数が無視されて
	 * 全ユーザーが返る。遅くなるだけで count_unrestricted_admins() の結果は変わらない
	 * （Fatal にもならない。"Requires at least" は宣言していない）。
	 *
	 * What the 'capability' argument cannot see:
	 * - Super admins on multisite: WP_User::has_cap() grants them everything without any stored role or
	 *   capability. They are added here, limited to members of the current site as get_users() is.
	 * - Capabilities granted only at run time by a user_has_cap / map_meta_cap filter of another plugin.
	 *   Such users are not candidates, so they are not counted; this can only make save-time check 1 stricter
	 *   (refuse a save that would otherwise pass), never let a save through that leaves nobody unrestricted.
	 * 'capability' 引数では見えないもの：
	 * - マルチサイトの特権管理者：WP_User::has_cap() が、保存された権限・capability と関係なく全部を認める。
	 *   ここで get_users() と同じく現在のサイトのメンバーに限って足す。
	 * - 他プラグインの user_has_cap / map_meta_cap フィルタが実行時にだけ与える capability。
	 *   そのユーザーは候補にならず数えられない。これは保存時のチェック1を厳しくする（本来通る保存を拒む）
	 *   方向にしか働かず、制限なしの人が残らない保存を通してしまうことは無い。
	 *
	 * @return WP_User[] Candidates, each at most once. / 候補（同じ人は1回だけ）。
	 */
	private static function get_manage_options_candidates() {
		// fields 'all' (the default): user_can() and compute_effective_modes() need WP_User objects, and
		// WP_User_Query then primes the user and user meta caches for these users only.
		// fields は既定の 'all'。user_can() と compute_effective_modes() は WP_User を要し、WP_User_Query は
		// このとき、ここで返すユーザーの分だけユーザーとユーザーメタのキャッシュを読み込む。
		$candidates = array();
		foreach ( get_users( array( 'capability' => 'manage_options' ) ) as $user ) {
			$candidates[ $user->ID ] = $user;
		}

		if ( is_multisite() ) {
			$super_admins = get_super_admins();
			// Guard against an empty list: an empty login__in means "no condition" (every user).
			// 空の一覧を渡さない：login__in が空だと「条件なし」（全ユーザー）になる。
			if ( $super_admins ) {
				// The default blog_id limits this to members of the current site, like get_users() without arguments.
				// 既定の blog_id により、引数なしの get_users() と同じく現在のサイトのメンバーに限られる。
				foreach ( get_users( array( 'login__in' => array_values( $super_admins ) ) ) as $user ) {
					$candidates[ $user->ID ] = $user;
				}
			}
		}

		return array_values( $candidates );
	}

	/**
	 * Returns up to $limit users of the current site who end up restricted under the given role modes, with
	 * their effective modes, in the same order as get_users() without arguments (by login name). Used by the
	 * "resulting restricted users" list of the Access Restriction tab (docs/spec.md 5.6).
	 * 与えた権限のモードのもとで、結果として制限される現在のサイトのユーザーを、実際に効くモードと一緒に
	 * 最大 $limit 人返す。順序は引数なしの get_users() と同じ（ログイン名順）。「アクセス制限」タブの
	 * 「結果として制限されるユーザーの一覧」（docs/spec.md 5.6）に使う。
	 *
	 * Only users who can end up restricted are looked at (see restricted_candidates_meta_query()): their IDs
	 * come from one query, and the users themselves are loaded RESTRICTED_USERS_BATCH at a time, stopping as
	 * soon as $limit users are found. Whether each one is restricted is still decided by
	 * compute_effective_modes(), so a candidate whose own setting is "No restriction" is dropped there.
	 * 制限されうるユーザーだけを見る（restricted_candidates_meta_query() を参照）。ID は1本のクエリで取り、
	 * ユーザー本体は RESTRICTED_USERS_BATCH 人ずつ読み込み、$limit 人見つかった時点で止める。
	 * 制限されるかどうかは従来どおり compute_effective_modes() で決めるので、権限は制限でもユーザー単位で
	 * 「制限なし」にしている候補はそこで落ちる。
	 *
	 * @param string[] $role_modes Role => mode, as get_role_modes() returns. / 権限 => モード（get_role_modes() と同じ形）。
	 * @param int      $limit      Maximum number of users to return. / 返す最大人数。
	 * @return array[] {
	 *     Restricted users, in order. / 制限されるユーザー（順序どおり）。
	 *
	 *     @type WP_User  $user  User. / ユーザー。
	 *     @type string[] $modes Effective modes, as compute_effective_modes() returns (never empty). / 実際に効くモード（compute_effective_modes() の戻り値。空にはならない）。
	 * }
	 */
	public static function find_restricted_users( $role_modes, $limit ) {
		$limit      = (int) $limit;
		$restricted = array();
		if ( $limit < 1 ) {
			return $restricted;
		}

		// IDs only, ordered like get_users() without arguments ('login', ASC). The default blog_id keeps this to
		// the members of the current site on multisite, the same range as get_users() without arguments.
		// ID だけを、引数なしの get_users() と同じ順序（'login' の昇順）で取る。既定の blog_id により、
		// マルチサイトでは引数なしの get_users() と同じく現在のサイトのメンバーに限られる。
		$ids = get_users(
			array(
				'fields'     => 'ID',
				'orderby'    => 'login',
				'order'      => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Narrows the users to look at; the alternative is loading every user. Settings screen only. / 見るユーザーを絞るため。代わりは全ユーザーの読み込み。設定画面のみ。
				'meta_query' => self::restricted_candidates_meta_query( $role_modes ),
			)
		);

		foreach ( array_chunk( array_map( 'intval', $ids ), self::RESTRICTED_USERS_BATCH ) as $batch ) {
			// Loads this batch's users and their user meta in two queries, so get_userdata() below reads from the cache.
			// この小分けのユーザーとユーザーメタを2本のクエリで読み込み、下の get_userdata() がキャッシュから読むようにする。
			cache_users( $batch );

			foreach ( $batch as $user_id ) {
				$user = get_userdata( $user_id );
				if ( ! $user ) {
					continue;
				}
				$modes = self::compute_effective_modes( $user, $role_modes );
				if ( $modes ) {
					$restricted[] = array(
						'user'  => $user,
						'modes' => $modes,
					);
					if ( count( $restricted ) >= $limit ) {
						return $restricted;
					}
				}
			}
		}

		return $restricted;
	}

	/**
	 * Builds the meta query that picks every user who can end up restricted under the given role modes:
	 * (1) holding a role whose mode is not 'none', or (2) having their own mode set to 'ip' or 'basic'.
	 * Anyone else is unrestricted by compute_effective_modes(): their own mode is 'follow' with no restricted
	 * role, or 'none'. Extra users are fine (compute_effective_modes() drops them); missing ones are not.
	 * 与えた権限のモードのもとで制限されうるユーザーを全員拾うメタクエリを作る。
	 * (1) モードが 'none' でない権限を持つ、または (2) ユーザー自身のモードが 'ip' か 'basic'。
	 * それ以外の人は compute_effective_modes() で必ず制限なしになる（自身のモードが 'follow' で制限する
	 * 権限を持たないか、'none'）。余分に拾うのは構わない（compute_effective_modes() が落とす）が、取りこぼしは不可。
	 *
	 * (1) uses exactly the clause WP_User_Query builds for 'role__in' (the serialized role name in the
	 * capabilities meta of this site, matched with LIKE). 'role__in' itself is not used because WP_User_Query
	 * joins it to 'meta_query' with AND, while (1) and (2) must be joined with OR; one OR query also keeps the
	 * login-name order of a single query, which merging two result sets in PHP could not reproduce (the order
	 * follows the database collation). administrator is left out: it is always unrestricted at the role level.
	 * (1) は、WP_User_Query が 'role__in' に対して作るのとまったく同じ句（このサイトの capabilities メタに
	 * 入っているシリアライズ済みの権限名を LIKE で探す）を使う。'role__in' そのものを使わないのは、
	 * WP_User_Query がそれを 'meta_query' と AND でつなぐため（(1) と (2) は OR でつなぐ必要がある）。
	 * OR の1本にしておけば、1本のクエリのログイン名順もそのまま保てる（2つの結果を PHP で合わせると、
	 * データベースの照合順序に従う並びを再現できない）。administrator は権限単位では常に制限なしなので入れない。
	 *
	 * With every role at 'none', only (2) remains: users restricted by their own setting are still found.
	 * すべての権限が 'none' なら (2) だけが残る。ユーザー単位で制限している人はそれでも拾える。
	 *
	 * @param string[] $role_modes Role => mode, as get_role_modes() returns. / 権限 => モード（get_role_modes() と同じ形）。
	 * @return array Meta query for WP_User_Query. / WP_User_Query に渡すメタクエリ。
	 */
	private static function restricted_candidates_meta_query( $role_modes ) {
		global $wpdb;

		$clauses = array(
			'relation' => 'OR',
			// (2) Own mode is 'ip' or 'basic'. The values go through WP_Meta_Query's own placeholders.
			// (2) 自身のモードが 'ip' か 'basic'。値は WP_Meta_Query 自身のプレースホルダを通る。
			array(
				'key'     => self::USER_MODE_META,
				'value'   => array( self::MODE_IP, self::MODE_BASIC ),
				'compare' => 'IN',
			),
		);

		// (1) Holds a role whose mode is not 'none'. Same key as WP_User_Query uses for the current site.
		// (1) モードが 'none' でない権限を持つ。キーは WP_User_Query が現在のサイトに使うものと同じ。
		$caps_key = $wpdb->get_blog_prefix( get_current_blog_id() ) . 'capabilities';
		foreach ( (array) $role_modes as $role => $mode ) {
			if ( 'administrator' === $role || self::MODE_NONE === $mode ) {
				continue;
			}
			$clauses[] = array(
				'key'     => $caps_key,
				'value'   => '"' . $role . '"',
				'compare' => 'LIKE',
			);
		}

		return $clauses;
	}

	/**
	 * Tells whether the current user's own access would still be allowed under prospective role modes and
	 * site IP list. Used by save-time check 2 (docs/spec.md 5.1) when saving the Access Restriction tab
	 * (which can change both at once) and, with $forced_user_mode / $forced_user_ip_entries, when saving the
	 * current user's own row on the user edit screen (docs/spec.md 5.1, 5.6): that screen changes the
	 * current user's own mode and per-user IP list, neither of which is yet written to the database when
	 * this is called, so the prospective values have to be passed in rather than re-read from storage.
	 * 現在のユーザー自身のアクセスが、保存前の権限モードとサイトの IP 一覧のもとでも成り立つかを返す。
	 * 「アクセス制限」タブの保存時のチェック2（docs/spec.md 5.1）に使うほか、$forced_user_mode /
	 * $forced_user_ip_entries を渡せば、ユーザー編集画面で現在のユーザー自身の行を保存するとき
	 * （docs/spec.md 5.1・5.6）にも使える：あの画面は現在のユーザー自身のモードとユーザーごとの IP 一覧を
	 * 変えるが、この呼び出しの時点ではどちらもまだ DB に書き込まれていないため、保存しようとしている値を
	 * 引数で渡す必要がある（DB から読み直せない）。
	 *
	 * A 'basic' requirement is satisfied only when the current request itself already carries BASIC
	 * credentials that match the current user's own saved ones (ACGD_Basic_Auth::request_satisfies()) — the
	 * same live check enforce_for_request() uses to grant access, not a re-derivation of it. A brand new
	 * BASIC setup (no saved credentials yet, or credentials about to change) can never satisfy this from the
	 * role tab: docs/spec.md 5.3 requires going through the user edit screen's own "Verify" round trip first,
	 * which is a separate, per-user path (see ACGD_User_Access), not this tab's check 2.
	 * 'basic' が求められる場合は、今のリクエスト自体が「現在のユーザー自身の保存済み資格情報」と一致する
	 * BASIC 認証を既に伴っているときだけ成立する（ACGD_Basic_Auth::request_satisfies()。
	 * enforce_for_request() がアクセスを許可するのと同じ生の判定を再利用するだけで、別ロジックにはしない）。
	 * まだ資格情報が無い・変えようとしている新規の BASIC 設定は、このタブからは絶対に成立しない
	 * （docs/spec.md 5.3 はユーザー編集画面の「確認」の往復を先に通すことを求めており、それはこのタブの
	 * チェック2ではなくユーザーごとの別経路。ACGD_User_Access を参照）。
	 *
	 * @param string[]    $role_modes             Prospective role => mode. / 保存しようとしている 権限 => モード。
	 * @param string[]    $site_ip_entries        Prospective site-wide IP entries, already parsed (parse_ip_list()). / 保存しようとしているサイトの IP 一覧（parse_ip_list() 済み）。
	 * @param string|null $forced_user_mode       When given, used instead of the current user's saved mode
	 *                                            (the user edit screen's own prospective mode, not yet saved).
	 *                                            Null (default) reads the current user's saved mode, unchanged.
	 *                                            与えたときは、現在のユーザーの保存済みモードの代わりに使う
	 *                                            （ユーザー編集画面で保存しようとしているモード。まだ未保存）。
	 *                                            既定の null は、現在のユーザーの保存済みモードをそのまま使う。
	 * @param string[]|null $forced_user_ip_entries When given, used instead of the current user's saved
	 *                                              per-user IP entries (already parsed). Null (default) reads
	 *                                              them from storage, unchanged.
	 *                                              与えたときは、現在のユーザーの保存済みのユーザーごとの
	 *                                              IP 一覧（解析済み）の代わりに使う。既定の null は、
	 *                                              保存済みの値をそのまま読む。
	 * @return bool Whether the current user would still get in. / 現在のユーザーがなお入れるか。
	 */
	public static function current_user_still_allowed( $role_modes, $site_ip_entries, $forced_user_mode = null, $forced_user_ip_entries = null ) {
		$current = wp_get_current_user();
		if ( ! $current instanceof WP_User || 0 === $current->ID ) {
			return true; // No authenticated context; nothing of "oneself" to check. / 認証されたユーザーが無く、確かめる「本人」がいない。
		}

		$modes = self::compute_effective_modes( $current, $role_modes, $forced_user_mode );
		if ( in_array( self::MODE_BASIC, $modes, true ) && ! ACGD_Basic_Auth::request_satisfies( $current ) ) {
			return false;
		}
		if ( in_array( self::MODE_IP, $modes, true ) ) {
			$remote          = self::get_remote_addr();
			$user_ip_entries = null !== $forced_user_ip_entries ? $forced_user_ip_entries : self::parse_ip_list( self::get_user_ip_text( $current->ID ) );
			$entries         = array_merge( $site_ip_entries, $user_ip_entries );

			return null !== $remote && self::ip_in_list( $remote, $entries );
		}

		return true;
	}

	/*-------------------------------------------*/
	/* IP list: parsing and matching / IP 一覧：解析と判定
	/*-------------------------------------------*/

	/**
	 * Splits IP list text into entries: one per line, comments (after #) and blank lines removed.
	 * IP 一覧の文字列を1行1件に分ける。コメット（# の後ろ）と空行は取り除く。
	 *
	 * @param string $text Raw text. / 生の文字列。
	 * @return string[] Entries (single IPs or CIDR ranges), not validated. / 一覧の各行（単独の IP か CIDR。未検証）。
	 */
	public static function parse_ip_list( $text ) {
		$entries = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( self::strip_comment( $line ) );
			if ( '' !== $line ) {
				$entries[] = $line;
			}
		}

		return $entries;
	}

	/**
	 * Validates IP list text line by line. / IP 一覧の文字列を1行ずつ検証する。
	 *
	 * @param string $text Raw text. / 生の文字列。
	 * @return array {
	 *     @type string[] $entries Valid entries, in order. / 有効な行（順序どおり）。
	 *     @type string[] $invalid Invalid lines: 1-based line number => original line text. / 無効な行（1始まりの行番号 => 元の行の文字列）。
	 * }
	 */
	/**
	 * Sanitizes a submitted IP list, line by line. wp_check_invalid_utf8() answers for the whole string it is
	 * handed, so sanitizing the field in one go turns a single invalid byte anywhere -- including inside a '#'
	 * note -- into an empty field, and saving that would wipe the list without saying so. Sanitizing line by
	 * line keeps the damage to the line it is on. A line that had content but sanitizes to nothing is not valid
	 * UTF-8; it is replaced with U+FFFD, which is itself valid UTF-8 and is never a valid address or range, so
	 * validate_ip_list() reports it through its existing "invalid line" message: no new string, nothing is
	 * saved, and the admin is told which line.
	 *
	 * Never hand the raw text back instead. Those bytes do not reach validate_ip_list() at all when they sit in
	 * a note (strip_comment() cuts the note off before the line is checked), so the list saves "successfully":
	 * $wpdb->process_fields() then refuses the write and update_user_meta()/update_option() return false without
	 * touching the cache, which the callers do not check -- a silent no-op save. esc_textarea() cannot render
	 * those bytes either (it passes ENT_QUOTES without ENT_SUBSTITUTE, so it returns an empty string).
	 *
	 * ★ sanitize_textarea_field() also rewrites the '#' notes themselves: see docs/spec.md 5.2. The character
	 * set of an address or a CIDR range is untouched, so which addresses are allowed never changes.
	 *
	 * 送信された IP 一覧を、行ごとにサニタイズする。wp_check_invalid_utf8() は渡された文字列全体について
	 * 答えるため、欄まるごとに掛けると、どこか1バイトの不正が——'#' 以降のメモの中であっても——欄全体を
	 * 空にし、それを保存すると一覧が無言で消える。行ごとに掛ければ被害はその行に留まる。中身があったのに
	 * 空になった行は不正な UTF-8 なので U+FFFD に差し替える。U+FFFD 自体は正しい UTF-8 で、アドレスにも
	 * 範囲にも決してならないため、validate_ip_list() の既存の「不正な行」の文言でそのまま報告される。
	 * 新しい訳語は要らず、何も保存されず、どの行かが管理者に伝わる。
	 *
	 * 代わりに生の文字列へ戻してはいけない。そのバイトがメモの中にあると validate_ip_list() まで届かず
	 * （strip_comment() が検証前にメモを切り落とすため）、一覧は「保存成功」に見える。その後
	 * $wpdb->process_fields() が書き込みを拒否し、update_user_meta() / update_option() はキャッシュに
	 * 触れないまま false を返すが、呼び出し側はそれを見ていない——無言の空振り保存になる。
	 * esc_textarea() もそのバイトを描画できない（ENT_SUBSTITUTE を付けずに ENT_QUOTES を渡すので空文字を返す）。
	 *
	 * ★ sanitize_textarea_field() は '#' 以降のメモ自体も書き換える（docs/spec.md 5.2）。アドレスと
	 * CIDR の範囲の文字集合には当たらないので、通る許可先が変わることは無い。
	 *
	 * @param mixed $raw Submitted text (not necessarily a string). / 送信された文字列（文字列とは限らない）。
	 * @return string Sanitized text, safe to validate, save and redisplay. / 検証・保存・再表示に耐えるサニタイズ済みの文字列。
	 */
	public static function sanitize_ip_list_text( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		foreach ( $lines as $index => $line ) {
			$clean           = sanitize_textarea_field( $line );
			$lines[ $index ] = ( '' === $clean && '' !== trim( $line ) ) ? "\xEF\xBF\xBD" : $clean;
		}

		return implode( "\n", $lines );
	}

	public static function validate_ip_list( $text ) {
		$entries = array();
		$invalid = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $index => $raw_line ) {
			$line = trim( self::strip_comment( $raw_line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( self::is_valid_ip_or_cidr( $line ) ) {
				$entries[] = $line;
			} else {
				$invalid[ $index + 1 ] = trim( $raw_line );
			}
		}

		return array(
			'entries' => $entries,
			'invalid' => $invalid,
		);
	}

	/**
	 * Removes a trailing "# note" from one line of the IP list. / IP 一覧の1行から、末尾の「# メモ」を取り除く。
	 *
	 * @param string $line One line. / 1行。
	 * @return string Line without its comment. / コメットを除いた行。
	 */
	private static function strip_comment( $line ) {
		$hash = strpos( $line, '#' );

		return false === $hash ? $line : substr( $line, 0, $hash );
	}

	/**
	 * Tells whether one entry is a valid single IP address or a valid CIDR range (IPv4 or IPv6).
	 * 1件が有効な単独の IP アドレスか、有効な CIDR の範囲（IPv4 か IPv6）かを返す。
	 *
	 * @param string $entry One entry, already trimmed and without its comment. / 1件（trim 済み、コメット除去済み）。
	 * @return bool Whether it is valid. / 有効かどうか。
	 */
	public static function is_valid_ip_or_cidr( $entry ) {
		if ( false === strpos( $entry, '/' ) ) {
			return false !== self::inet_pton_safe( self::normalize_ip( $entry ) );
		}

		$parts  = explode( '/', $entry, 2 );
		$packed = self::inet_pton_safe( self::normalize_ip( trim( $parts[0] ) ) );
		if ( false === $packed ) {
			return false;
		}
		$mask = trim( $parts[1] );
		if ( '' === $mask || ! ctype_digit( $mask ) ) {
			return false;
		}

		$max_bits = 4 === strlen( $packed ) ? 32 : 128;

		return ( (int) $mask ) <= $max_bits;
	}

	/**
	 * Normalizes an IPv4-mapped IPv6 address (such as ::ffff:1.2.3.4) to its plain IPv4 form. Any other
	 * address (including a plain IPv6 one) is returned unchanged.
	 * IPv4-mapped の IPv6 アドレス（::ffff:1.2.3.4 など）を、普通の IPv4 の表記に直す。
	 * それ以外のアドレス（普通の IPv6 を含む）はそのまま返す。
	 *
	 * ★ This converts the address to IPv4 and compares as IPv4; it never rounds an address to a shorter
	 * prefix (such as /64) to "normalize" it. Rounding IPv4-mapped addresses that way once collapsed every
	 * IPv4 visitor into a single bucket in another plugin (PageGuard); see ~/.claude/etbs-plugin-rules.md 2節
	 * "踏んだ罠" and docs/spec.md 5.2.
	 * ★ ここでは IPv4 に変換したうえで IPv4 として比較する。短い prefix（/64 など）に丸めて「正規化」する
	 * ことは一切しない。IPv4-mapped アドレスをその方法で丸めた結果、別のプラグイン（PageGuard）で
	 * 全 IPv4 訪問者が1つのバケットに潰れた実例がある。~/.claude/etbs-plugin-rules.md 2節「踏んだ罠」と
	 * docs/spec.md 5.2 を参照。
	 *
	 * @param string $ip Address, as text. / アドレス（文字列）。
	 * @return string Normalized address, as text. / 正規化したアドレス（文字列）。
	 */
	public static function normalize_ip( $ip ) {
		$ip = trim( (string) $ip );
		if ( '' === $ip || false === strpos( $ip, ':' ) ) {
			return $ip;
		}

		$packed = self::inet_pton_safe( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $ip;
		}

		// The IPv4-mapped prefix is 80 zero bits followed by 16 one bits (::ffff:0:0/96).
		// IPv4-mapped の prefix は、0 が80ビット続いた後に1が16ビット続く形（::ffff:0:0/96）。
		if ( "\0\0\0\0\0\0\0\0\0\0\xff\xff" !== substr( $packed, 0, 12 ) ) {
			return $ip;
		}

		$mapped = inet_ntop( substr( $packed, 12, 4 ) );

		return false === $mapped ? $ip : $mapped;
	}

	/**
	 * Converts an address to its packed binary form, or false when it is not a valid address.
	 * アドレスをパック済みの二進表現に変換する。有効なアドレスでなければ false。
	 *
	 * @param string $ip Address, as text. / アドレス（文字列）。
	 * @return string|false Packed binary form, or false. / パック済みの二進表現、または false。
	 */
	private static function inet_pton_safe( $ip ) {
		if ( '' === $ip ) {
			return false;
		}

		// inet_pton() raises an E_WARNING for a malformed address; the false return already says so.
		// inet_pton() は不正なアドレスに E_WARNING を出すが、戻り値の false だけで判定できる。
		return @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see comment above.
	}

	/**
	 * Tells whether an address matches any entry of a list (single IPs and CIDR ranges alike).
	 * アドレスが一覧のどれか1件（単独の IP・CIDR の範囲のどちらも）に一致するかを返す。
	 *
	 * @param string   $ip      Address to test, as text. / 判定するアドレス（文字列）。
	 * @param string[] $entries Entries, already parsed (parse_ip_list()) but not necessarily validated. / 一覧（parse_ip_list() 済み。検証済みとは限らない）。
	 * @return bool Whether it matches. / 一致するか。
	 */
	public static function ip_in_list( $ip, $entries ) {
		foreach ( $entries as $entry ) {
			if ( self::ip_matches( $ip, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether an address matches one entry (a single IP or a CIDR range).
	 * アドレスが1件（単独の IP か CIDR の範囲）に一致するかを返す。
	 *
	 * The match is a plain per-bit comparison of the packed address against the entry, for every prefix
	 * length from /0 to /128 individually; no address or prefix length is ever rounded to a coarser one.
	 * 判定は、パック済みのアドレスと一覧の値を、/0 から /128 まで指定どおりの長さでビット単位に比べるだけ。
	 * アドレスも prefix の長さも、粗い単位へ丸めることはしない。
	 *
	 * @param string $ip    Address to test, as text. / 判定するアドレス（文字列）。
	 * @param string $entry One entry. / 一覧の1件。
	 * @return bool Whether it matches. / 一致するか。
	 */
	public static function ip_matches( $ip, $entry ) {
		$ip_bin = self::inet_pton_safe( self::normalize_ip( $ip ) );
		if ( false === $ip_bin ) {
			return false;
		}

		if ( false === strpos( $entry, '/' ) ) {
			$entry_bin = self::inet_pton_safe( self::normalize_ip( $entry ) );

			return false !== $entry_bin && $entry_bin === $ip_bin;
		}

		$parts      = explode( '/', $entry, 2 );
		$subnet_bin = self::inet_pton_safe( self::normalize_ip( trim( $parts[0] ) ) );
		if ( false === $subnet_bin || strlen( $subnet_bin ) !== strlen( $ip_bin ) ) {
			return false; // Different address family after normalization: never matches. / 正規化後も家族（v4/v6）が違えば一致しない。
		}
		if ( ! ctype_digit( trim( $parts[1] ) ) ) {
			return false;
		}

		$mask_bits      = (int) trim( $parts[1] );
		$full_bytes     = intdiv( $mask_bits, 8 );
		$remaining_bits = $mask_bits % 8;

		if ( $full_bytes > 0 && substr( $ip_bin, 0, $full_bytes ) !== substr( $subnet_bin, 0, $full_bytes ) ) {
			return false;
		}
		if ( $remaining_bits > 0 ) {
			$mask = chr( ( 0xFF << ( 8 - $remaining_bits ) ) & 0xFF );
			if ( ( substr( $ip_bin, $full_bytes, 1 ) & $mask ) !== ( substr( $subnet_bin, $full_bytes, 1 ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns the connecting address of the current request, from REMOTE_ADDR only.
	 * 今のリクエストの接続元アドレスを、REMOTE_ADDR だけから返す。
	 *
	 * ★ REMOTE_ADDR only. Headers such as X-Forwarded-For are never read for this: they can be set by the
	 * visitor themselves unless a trusted proxy strips and rewrites them, which this plugin has no way to
	 * confirm. See docs/spec.md 5.2 and CLAUDE.md.
	 * ★ REMOTE_ADDR だけを見る。X-Forwarded-For などのヘッダはここでは一切読まない。信頼できるプロキシが
	 * 書き換えていない限り訪問者自身が値を送れてしまい、それをこのプラグインが確かめる手段は無いため。
	 * docs/spec.md 5.2 と CLAUDE.md を参照。
	 *
	 * @return string|null Address, or null when it cannot be determined or is not a valid address. / アドレス。判定できない・有効なアドレスでないときは null。
	 */
	public static function get_remote_addr() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}

		// Server-set, not user input, but still passed through sanitize_text_field() defensively.
		// サーバが設定する値で利用者からの入力ではないが、念のため sanitize_text_field() を通す。
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

		return false !== self::inet_pton_safe( self::normalize_ip( $ip ) ) ? $ip : null;
	}

	/**
	 * Tells whether an address is allowed for a user: present in the site-wide list or in that user's own
	 * added list (docs/spec.md 5.2, "どちらかに入っていれば許可"). An empty combined list allows nobody.
	 * あるアドレスがユーザーに許可されているかを返す。サイトの一覧かそのユーザー自身の追加分の
	 * どちらかに入っていれば許可する（docs/spec.md 5.2「どちらかに入っていれば許可」）。
	 * 合わせた一覧が空なら、誰も許可しない。
	 *
	 * @param int         $user_id   User ID. / ユーザー ID。
	 * @param string|null $remote_ip Address to test, as get_remote_addr() returns. / 判定するアドレス（get_remote_addr() の戻り値と同じ形）。
	 * @return bool Whether it is allowed. / 許可されているか。
	 */
	public static function is_ip_allowed_for_user( $user_id, $remote_ip ) {
		if ( null === $remote_ip || '' === $remote_ip ) {
			return false;
		}

		$entries = array_merge(
			self::parse_ip_list( self::get_site_ip_text() ),
			self::parse_ip_list( self::get_user_ip_text( $user_id ) )
		);

		return $entries && self::ip_in_list( $remote_ip, $entries );
	}

	/*-------------------------------------------*/
	/* Emergency switch and self-fault / 非常用スイッチと自分の故障
	/*-------------------------------------------*/

	/**
	 * Tells whether Access Restriction is stopped, by the emergency switch or by a recorded fault of its own.
	 * アクセス制限が止まっているか（非常用スイッチ、または自分自身の記録済みの故障により）を返す。
	 *
	 * @return bool Whether it is stopped. / 止まっているか。
	 */
	public static function is_disabled() {
		return self::is_switch_disabled() || self::has_fault();
	}

	/**
	 * Tells whether the emergency switch (docs/spec.md 5.5) is set. / 非常用スイッチ（docs/spec.md 5.5）が立っているかを返す。
	 *
	 * @return bool Whether it is set. / 立っているか。
	 */
	public static function is_switch_disabled() {
		return defined( 'ACGD_DISABLE_RESTRICTION' ) && ACGD_DISABLE_RESTRICTION;
	}

	/**
	 * Tells whether a fault of this feature is currently recorded (docs/spec.md 5.5).
	 * この機能の故障が今も記録されているか（docs/spec.md 5.5）を返す。
	 *
	 * @return bool Whether a fault is recorded. / 記録されているか。
	 */
	public static function has_fault() {
		return (bool) get_option( self::FAULT_OPTION, false );
	}

	/**
	 * Returns the recorded fault, or an empty array when there is none.
	 * 記録済みの故障を返す。無ければ空の配列。
	 *
	 * @return array {
	 *     @type int    $time    When it was recorded (as time()). / 記録した時刻（time()）。
	 *     @type string $message Exception message, for the settings screen only (not shown to visitors). / 例外のメッセージ（設定画面にだけ出す。訪問者には見せない）。
	 * }
	 */
	public static function get_fault() {
		$fault = get_option( self::FAULT_OPTION, array() );

		return is_array( $fault ) ? $fault : array();
	}

	/**
	 * Records a fault of this feature (docs/spec.md 5.5: "アクセス制限を止めて通す"). Public: this is the one
	 * shared recording point for every `catch ( Throwable $e )` that fails this feature open, including
	 * ACGD_Basic_Auth::maybe_strip_own_header() (MEDIUM-2 fix, PR #6 review — it fails open the same way but,
	 * before this fix, had no way to reach this method and so never raised the 5.5 warning).
	 * この機能の故障を記録する（docs/spec.md 5.5「アクセス制限を止めて通す」）。public にしているのは、
	 * この機能を止めて通すあらゆる `catch ( Throwable $e )` が使う、共通の記録先がここだけであるため。
	 * ACGD_Basic_Auth::maybe_strip_own_header() も含む（MEDIUM-2 の修正。PR #6 レビュー：同じように止めて
	 * 通してはいたが、修正前はここへ到達する手段が無く、5.5 の警告が一度も出なかった）。
	 *
	 * @param string $message Exception message. / 例外のメッセージ。
	 * @return void
	 */
	public static function record_fault( $message ) {
		update_option(
			self::FAULT_OPTION,
			array(
				'time'    => time(),
				'message' => (string) $message,
			),
			false
		);
	}

	/**
	 * Clears a recorded fault. Called after the Access Restriction settings are saved successfully, so that
	 * fixing the settings is what turns the warning off.
	 * 記録済みの故障を消す。アクセス制限の設定の保存に成功した後に呼び、
	 * 設定を直すことが警告を消す手段になるようにする。
	 *
	 * @return void
	 */
	public static function clear_fault() {
		delete_option( self::FAULT_OPTION );
	}

	/*-------------------------------------------*/
	/* Denial log (docs/spec.md 5.4) / 拒否の記録（docs/spec.md 5.4）
	/*-------------------------------------------*/

	/**
	 * Adds one entry to the denial log, keeping only the most recent DENIAL_LOG_MAX (docs/spec.md 5.4).
	 * 拒否の記録に1件足し、直近の DENIAL_LOG_MAX 件だけを残す（docs/spec.md 5.4）。
	 *
	 * @param int         $user_id   User who was denied. / 拒否されたユーザー。
	 * @param string|null $remote_ip Connecting address, as get_remote_addr() returns. / 接続元アドレス（get_remote_addr() の戻り値と同じ形）。
	 * @param string      $context   One of 'login', 'session' (a later access, whose session was destroyed) or 'rest'.
	 *                               'login'・'session'（ログイン後のアクセスで、セッションを破棄した）・'rest' のいずれか。
	 * @return void
	 */
	public static function log_denial( $user_id, $remote_ip, $context ) {
		$log = get_option( self::DENIAL_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time'    => time(),
				'user_id' => (int) $user_id,
				'ip'      => null === $remote_ip ? '' : (string) $remote_ip,
				'context' => (string) $context,
			)
		);

		if ( count( $log ) > self::DENIAL_LOG_MAX ) {
			$log = array_slice( $log, 0, self::DENIAL_LOG_MAX );
		}

		update_option( self::DENIAL_LOG_OPTION, $log, false );
	}

	/**
	 * Returns the denial log, newest first. / 拒否の記録を、新しい順で返す。
	 *
	 * @return array[] Entries, as log_denial() stores them. / log_denial() が保存する形の各件。
	 */
	public static function get_denial_log() {
		$log = get_option( self::DENIAL_LOG_OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/*-------------------------------------------*/
	/* Session destruction (docs/spec.md 5.2) / セッションの破棄（docs/spec.md 5.2）
	/*-------------------------------------------*/

	/**
	 * Destroys only the current session and continues the request as an anonymous visitor.
	 * 今のセッションだけを破棄し、リクエストは未ログインの訪問者として続ける。
	 *
	 * Does not stop the request with a 403: a public page or an unauthenticated form must keep working, as
	 * it would for any other visitor (docs/spec.md 5.2).
	 * 403 で止めない。公開ページやログインなしのフォームは、他の訪問者と同じように使えたままにする
	 * （docs/spec.md 5.2）。
	 *
	 * @return void
	 */
	public static function destroy_current_session_and_continue() {
		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			return;
		}

		if ( function_exists( 'wp_get_session_token' ) ) {
			$token = wp_get_session_token();
			if ( $token ) {
				WP_Session_Tokens::get_instance( $user_id )->destroy( $token );
			}
		}

		if ( function_exists( 'wp_clear_auth_cookie' ) ) {
			wp_clear_auth_cookie();
		}

		wp_set_current_user( 0 );
	}

	/*-------------------------------------------*/
	/* Hooks / フック
	/*-------------------------------------------*/

	/**
	 * Judges IP restriction at login, before the password is checked (item 5.2). See WP_AUTHENTICATE_USER_PRIORITY
	 * for why this priority.
	 * ログイン時、パスワードを照合する前に IP 制限を判定する（5.2）。この優先度の理由は
	 * WP_AUTHENTICATE_USER_PRIORITY を参照。
	 *
	 * @param WP_User|WP_Error|null $user Result of the earlier wp_authenticate_user callbacks. / それまでの wp_authenticate_user コールバックの結果。
	 * @return WP_User|WP_Error|null The result. / 結果。
	 */
	public static function filter_wp_authenticate_user( $user ) {
		// An earlier callback (a CAPTCHA plugin, for example) already failed; leave that error as it is.
		// 先のコールバック（画像認証プラグインなど）が既に失敗させている：そのエラーをそのまま残す。
		if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		try {
			if ( self::is_disabled() ) {
				return $user;
			}

			if ( in_array( self::MODE_IP, self::get_effective_modes( $user ), true ) ) {
				$remote = self::get_remote_addr();
				if ( ! self::is_ip_allowed_for_user( $user->ID, $remote ) ) {
					self::log_denial( $user->ID, $remote, 'login' );

					// Same common error as a wrong password; the reason is never revealed. / 誤ったパスワードと同じ共通のエラー。理由は明かさない。
					return ACGD_Invalid_Credentials::get_login_error();
				}
			}

			/*
			 * MODE_BASIC is never judged here, on purpose (docs/spec.md 5.3): unlike IP restriction, BASIC
			 * authentication does not gate the WordPress login form itself. It is enforced afterwards, on the
			 * next access, by check_access_on_request() / filter_rest_authentication_errors(); a BASIC-mode
			 * user's login succeeds normally here and is challenged for their BASIC credentials on the next
			 * admin request instead.
			 * MODE_BASIC はここでは意図的に判定しない（docs/spec.md 5.3）。IP 制限と違い、BASIC 認証は
			 * WordPress のログインフォーム自体を関門にしない。判定は次のアクセスで
			 * check_access_on_request() / filter_rest_authentication_errors() が行う。BASIC モードの
			 * ユーザーのログインはここでは普通に成功し、次の管理画面アクセスで BASIC の確認を求められる。
			 */
			return $user;
		} catch ( Throwable $e ) {
			self::record_fault( $e->getMessage() );

			return $user; // Fail open: stop restricting, let core's own authentication continue (docs/spec.md 5.5). / 制限を止めて通す（docs/spec.md 5.5）。
		}
	}

	/**
	 * Turns off application passwords for a restricted user (docs/spec.md 5.2).
	 * 制限対象のユーザーではアプリケーションパスワードを使えなくする（docs/spec.md 5.2）。
	 *
	 * @param bool    $available Whether application passwords are available, so far. / それまでの判定でアプリケーションパスワードが使えるか。
	 * @param WP_User $user      User being asked about. / 判定対象のユーザー。
	 * @return bool Whether they are available. / 使えるか。
	 */
	public static function filter_app_passwords_available( $available, $user ) {
		if ( ! $available || ! ( $user instanceof WP_User ) ) {
			return $available;
		}

		try {
			if ( self::is_disabled() ) {
				return $available;
			}

			return empty( self::get_effective_modes( $user ) ) ? $available : false;
		} catch ( Throwable $e ) {
			self::record_fault( $e->getMessage() );

			return $available; // Fail open (docs/spec.md 5.5). / 止めて通す（docs/spec.md 5.5）。
		}
	}

	/**
	 * Judges IP restriction and BASIC authentication on every REST API request whose current user is already
	 * determined (docs/spec.md 5.2, 5.3). See REST_AUTHENTICATION_PRIORITY for why this priority.
	 *
	 * An IP denial discards the whole session and lets the request continue as anonymous
	 * (destroy_current_session_and_continue()), exactly as on any other access (docs/spec.md 5.2). A BASIC
	 * denial only sets the current user to 0 for this request (docs/spec.md 5.3, "セッションは消さない"):
	 * the cookie and session stay valid, so a REST call made without the BASIC header attached (a public
	 * endpoint a logged-out visitor could also reach) is simply treated as anonymous, not signed out. Neither
	 * branch returns a WP_Error; this filter never itself produces a 403/401 for REST.
	 * REST API のリクエストで、現在のユーザーが確定している場合に IP 制限と BASIC 認証を判定する
	 * （docs/spec.md 5.2・5.3）。この優先度の理由は REST_AUTHENTICATION_PRIORITY を参照。
	 *
	 * IP の拒否はセッションごと破棄し未ログインとして続行する（destroy_current_session_and_continue()。
	 * 他のアクセスと同じ、docs/spec.md 5.2）。BASIC の拒否は、このリクエストだけ現在のユーザーを 0 にする
	 * （docs/spec.md 5.3「セッションは消さない」）。cookie・セッションはそのままなので、BASIC ヘッダーを
	 * 付けていない REST 呼び出し（ログアウト中の訪問者も届く公開エンドポイントへのもの）は、
	 * サインアウトさせるのではなく単に未ログイン扱いにするだけで済む。どちらの分岐も WP_Error を返さない。
	 * このフィルタ自身が REST に 403/401 を作ることは無い。
	 *
	 * @param WP_Error|null|true|mixed $result Result of the earlier rest_authentication_errors callbacks. / それまでのコールバックの結果。
	 * @return WP_Error|null|true|mixed The result. / 結果。
	 */
	public static function filter_rest_authentication_errors( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		try {
			if ( self::is_disabled() ) {
				return $result;
			}

			$user_id = get_current_user_id();
			if ( 0 === $user_id ) {
				return $result;
			}
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return $result;
			}
			$modes = self::get_effective_modes( $user );
			if ( empty( $modes ) ) {
				return $result;
			}

			$remote = self::get_remote_addr();

			if ( in_array( self::MODE_IP, $modes, true ) && ! self::is_ip_allowed_for_user( $user->ID, $remote ) ) {
				self::log_denial( $user->ID, $remote, 'rest' );
				self::destroy_current_session_and_continue();

				return $result;
			}

			if ( in_array( self::MODE_BASIC, $modes, true ) && ! ACGD_Basic_Auth::request_satisfies( $user ) ) {
				// MEDIUM-3 fix (PR #6 review): log only an actual failed attempt (credentials were submitted
				// and did not satisfy this user), not the ordinary case of no BASIC header at all. BASIC keeps
				// the session alive (docs/spec.md 5.3), so unlike an IP denial just above (which discards the
				// session and so is only ever logged once), this "not satisfied" outcome repeats on every
				// single REST call this plugin's own realm has not yet challenged the browser for. Logging it
				// unconditionally would flood the 100-entry log (docs/spec.md 5.4) with ordinary browsing
				// instead of signal. See the matching comment in check_access_on_request().
				// MEDIUM-3 の修正（PR #6 レビュー）：実際に失敗した試行（資格情報が送られてきて、このユーザーを
				// 満たさなかった）だけを記録する。BASIC ヘッダーが一切無い、というだけの通常のケースは記録しない。
				// BASIC はセッションを温存する（docs/spec.md 5.3）ため、すぐ上の IP の拒否（セッションを破棄
				// するので一度しか記録されない）と違い、このプラグインの realm でまだ一度もブラウザに確認を
				// 求めていない REST 呼び出しでは、この「満たしていない」という結果が毎回のリクエストで
				// 繰り返される。無条件に記録すると、直近100件の記録（docs/spec.md 5.4）が通常の閲覧だけで
				// 埋まり、兆候として使えなくなる。check_access_on_request() の同じ内容のコメントも参照。
				if ( null !== ACGD_Basic_Auth::get_submitted_credentials() ) {
					self::log_denial( $user->ID, $remote, 'basic' );
				}
				wp_set_current_user( 0 ); // This request only; cookie and session are kept (docs/spec.md 5.3). / このリクエストだけ。cookie・セッションは残す（docs/spec.md 5.3）。
			}

			return $result;
		} catch ( Throwable $e ) {
			self::record_fault( $e->getMessage() );

			return $result; // Fail open (docs/spec.md 5.5). / 止めて通す（docs/spec.md 5.5）。
		}
	}

	/**
	 * Judges IP restriction and BASIC authentication on every access after login, outside the REST API
	 * (docs/spec.md 5.2, 5.3). Hooked to admin_init and template_redirect; see init() for why those two cover
	 * the front end, admin screens, admin-ajax.php and admin-post.php.
	 * ログイン後の毎回のアクセスで、REST API 以外を対象に IP 制限と BASIC 認証を判定する
	 * （docs/spec.md 5.2・5.3）。admin_init と template_redirect に掛ける。この2つがフロント・管理画面・
	 * admin-ajax.php・admin-post.php をなぜ覆うかは init() を参照。
	 *
	 * An IP denial discards the whole session, exactly as before (docs/spec.md 5.2), and stops here: once
	 * anonymous, there is nothing left of "this user's BASIC requirement" to check. A BASIC denial keeps the
	 * session and, only when this is a plain navigation to an admin screen — not admin-ajax.php (excluded by
	 * wp_doing_ajax()) and not admin-post.php (excluded by name: is_admin() and wp_doing_ajax() alone do not
	 * tell it apart from a screen load, since DOING_AJAX is never defined for it either) — sends the visitor
	 * to the confirmation screen instead of quietly rendering the page as anonymous (docs/spec.md 5.3,
	 * "管理画面のときだけ確認画面へ送る"). Both excluded endpoints are driven by a script (an XHR call, a form
	 * POST expecting its own redirect), not a person free to interact with a browser's native BASIC dialog.
	 * IP の拒否は今までどおりセッションごと破棄し（docs/spec.md 5.2）、ここで終える：未ログインになった時点で、
	 * もう「このユーザーの BASIC 要件」として確かめるものが無いため。BASIC の拒否はセッションを残し、
	 * 素の管理画面ナビゲーションのとき——admin-ajax.php は wp_doing_ajax() で除外、admin-post.php は
	 * 名前で除外（DOING_AJAX がこちらでも定義されないため、is_admin() と wp_doing_ajax() だけでは
	 * 画面表示と区別できない）——だけ、黙って未ログインの画面を出す代わりに確認画面へ送る
	 * （docs/spec.md 5.3「管理画面のときだけ確認画面へ送る」）。除外する2つはどちらもスクリプト駆動
	 * （XHR 呼び出し・自前のリダイレクトを期待するフォーム POST）で、ブラウザのネイティブな BASIC
	 * ダイアログを操作できる人がその場にいるとは限らない。
	 *
	 * @return void
	 */
	public static function check_access_on_request() {
		try {
			if ( self::is_disabled() ) {
				return;
			}

			$user_id = get_current_user_id();
			if ( 0 === $user_id ) {
				return;
			}
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return;
			}
			$modes = self::get_effective_modes( $user );
			if ( empty( $modes ) ) {
				return;
			}

			$remote = self::get_remote_addr();

			if ( in_array( self::MODE_IP, $modes, true ) && ! self::is_ip_allowed_for_user( $user->ID, $remote ) ) {
				self::log_denial( $user->ID, $remote, 'session' );
				self::destroy_current_session_and_continue();
				return;
			}

			if ( in_array( self::MODE_BASIC, $modes, true ) && ! ACGD_Basic_Auth::request_satisfies( $user ) ) {
				// MEDIUM-3 fix (PR #6 review): log only an actual failed attempt (credentials were submitted
				// and did not satisfy this user), not the ordinary case of no BASIC header at all. The
				// confirmation screen this method redirects to below is limited to admin-screen navigation
				// (its own docblock), but this "not satisfied" branch itself runs on every access after login,
				// including the front end and admin-ajax/admin-post, where the browser is never challenged and
				// so never attaches the header. Because BASIC keeps the session alive (docs/spec.md 5.3, unlike
				// an IP denial just above, which discards the session and so is only ever logged once), a
				// BASIC-mode user's ordinary browsing would otherwise re-log this exact "no header" outcome on
				// every single such request, flooding the 100-entry log (docs/spec.md 5.4) with normal use
				// instead of signal.
				// MEDIUM-3 の修正（PR #6 レビュー）：実際に失敗した試行（資格情報が送られてきて、このユーザーを
				// 満たさなかった）だけを記録する。BASIC ヘッダーが一切無い、というだけの通常のケースは記録しない。
				// このメソッドが下でリダイレクトする確認画面は管理画面ナビゲーションに限定している（メソッド
				// 自身の docblock）が、この「満たしていない」分岐自体はログイン後の毎回のアクセス——フロント・
				// admin-ajax・admin-post を含む——で走り、そこではブラウザは一度も確認を求められておらず
				// ヘッダーを一切付けない。BASIC はセッションを温存する（docs/spec.md 5.3。すぐ上の IP の拒否は
				// セッションを破棄するので一度しか記録されないのと対照的）ため、記録しなければ BASIC モードの
				// 通常の閲覧だけで、この「ヘッダー無し」という同じ結果を毎回のアクセスのたびに記録し、
				// 直近100件の記録（docs/spec.md 5.4）を通常の利用で埋め尽くしてしまう。
				if ( null !== ACGD_Basic_Auth::get_submitted_credentials() ) {
					self::log_denial( $user->ID, $remote, 'basic' );
				}
				wp_set_current_user( 0 ); // This request only; cookie and session are kept (docs/spec.md 5.3). / このリクエストだけ。cookie・セッションは残す（docs/spec.md 5.3）。

				// See the method docblock for why admin-ajax.php and admin-post.php are excluded.
				// admin-ajax.php・admin-post.php を除く理由はメソッドの docblock を参照。
				$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';
				if ( is_admin() && ! wp_doing_ajax() && 'admin-post.php' !== $script ) {
					ACGD_Basic_Auth::redirect_to_challenge();
					// redirect_to_challenge() always exits; nothing after this line runs.
				}
			}
		} catch ( Throwable $e ) {
			self::record_fault( $e->getMessage() );
			// Fail open: nothing to undo, restriction is simply not applied this request (docs/spec.md 5.5). / 止めて通す。何も取り消さず、この回は制限を適用しないだけ（docs/spec.md 5.5）。
		}
	}
}
