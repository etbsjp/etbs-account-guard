<?php
/**
 * BASIC authentication (docs/spec.md 5.3): reading submitted credentials, matching them against a user,
 * stripping them from $_SERVER once matched, the confirmation screen at wp-login.php, the user edit
 * screen's "Verify" round trip, and the receive diagnosis.
 * BASIC 認証（docs/spec.md 5.3）：送信された資格情報の読み取り・ユーザーとの照合・一致した後の $_SERVER
 * からの除去、wp-login.php の確認画面、ユーザー編集画面の「確認」の往復、受信の診断。
 *
 * ACGD_Access_Restriction stores the credentials (user meta) and calls request_satisfies() from its own
 * enforcement hooks (check_access_on_request(), filter_rest_authentication_errors()), the same way it
 * already enforces IP restriction; this class owns everything specific to BASIC itself.
 * 資格情報の保存（ユーザーメタ）と、request_satisfies() の呼び出し（check_access_on_request()・
 * filter_rest_authentication_errors() という既存の IP 制限と同じ判定の場所から）は
 * ACGD_Access_Restriction が持つ。BASIC 自身に固有のものはすべてこのクラスに置く。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BASIC authentication support. / BASIC 認証を支える処理。
 */
class ACGD_Basic_Auth {

	/**
	 * wp-login.php action that shows the confirmation screen (docs/spec.md 5.3, "wp-login.php の階層で出す").
	 * A native browser dialog only, never a custom HTML login form.
	 * 確認画面を出す wp-login.php のアクション（docs/spec.md 5.3「wp-login.php の階層で出す」）。
	 * ブラウザのネイティブなダイアログだけで、独自の HTML ログインフォームは持たない。
	 *
	 * @var string
	 */
	const CHALLENGE_ACTION = 'acgd_basic';

	/**
	 * wp-login.php action used by the user edit screen's "Verify" button (docs/spec.md 5.3, "自分をBASIC
	 * モードにするときは...確認ボタンで一度確認画面を通ってから保存する"). Separate from CHALLENGE_ACTION
	 * because it checks a not-yet-saved (id, password) pair from a transient, not a saved user's credentials.
	 * ユーザー編集画面の「確認」ボタンが使う wp-login.php のアクション（docs/spec.md 5.3「自分をBASIC
	 * モードにするときは...確認ボタンで一度確認画面を通ってから保存する」）。CHALLENGE_ACTION とは別にする。
	 * 保存済みユーザーの資格情報ではなく、まだ保存していない (id, password) の組を transient から見るため。
	 *
	 * @var string
	 */
	const VERIFY_ACTION = 'acgd_basic_verify';

	/**
	 * admin-post action of the "Verify" button on the user edit screen. Stashes the submitted form (mode, IP
	 * text and BASIC id/password, reusing ACGD_User_Access's resubmit transient) and the (id, password) pair
	 * to check, then redirects to VERIFY_ACTION.
	 * ユーザー編集画面の「確認」ボタンの admin-post アクション。送信されたフォーム（モード・IP・BASIC の
	 * ID/パスワード。ACGD_User_Access の再表示用 transient を流用）と、確認する (id, password) の組を
	 * 保存してから VERIFY_ACTION へリダイレクトする。
	 *
	 * @var string
	 */
	const VERIFY_REQUEST_ACTION = 'acgd_verify_basic';

	/**
	 * Prefix of the transient that holds a pending (id, password) pair awaiting confirmation through
	 * VERIFY_ACTION, keyed by the admin submitting it. Short TTL: only needs to survive the redirect to the
	 * confirmation screen and back.
	 * VERIFY_ACTION で確認待ちの (id, password) の組を持つ transient の接頭辞。送信した管理者ごとに分ける。
	 * TTL は短い：確認画面への往復に耐えればよいだけのため。
	 *
	 * @var string
	 */
	const PENDING_TRANSIENT_PREFIX = 'acgd_basic_pending_';

	/**
	 * Prefix of the transient that records a successful VERIFY_ACTION confirmation, keyed by the admin. Holds
	 * the confirmed ID and the password_hash() of the confirmed password (computed once, at confirmation
	 * time — see handle_verify()), so consume_verification() can hand that same hash straight to save_fields()
	 * even when the password field comes back blank on the profile screen (MEDIUM-1 fix, PR #6 review):
	 * render_fields() never redisplays a password, so requiring it to be retyped before the hash could be
	 * looked up meant a blank field on the "Update User" click right after a successful Verify silently kept
	 * the old password. A later save can still only use a confirmation of the same ID (not a stale one from a
	 * different attempt), and a password retyped anyway must still be the one that was confirmed
	 * (see consume_verification()).
	 * VERIFY_ACTION の確認成功を記録する transient の接頭辞。管理者ごとに分ける。確認できた ID と、確認できた
	 * パスワードの password_hash()（確認できた時点で一度だけ計算する。handle_verify() を参照）を持つ。
	 * これにより、プロフィール画面でパスワード欄が空のまま出し直されても（MEDIUM-1 の修正。PR #6 レビュー：
	 * render_fields() はパスワードを一切出し直さないため、ハッシュを引くのに再入力を必須にすると、「確認」
	 * 成功直後に空欄のまま「ユーザーを更新」を押した場合に古いパスワードが無言で残ってしまっていた）、
	 * consume_verification() が同じハッシュをそのまま save_fields() へ渡せる。それでも、後の保存で使えるのは
	 * 同じ ID を確認した結果だけ（別の試行の古い確認は使えない）。パスワードを改めて入力した場合も、それが
	 * 確認済みのものと一致することを求める（consume_verification() を参照）。
	 *
	 * @var string
	 */
	const VERIFIED_TRANSIENT_PREFIX = 'acgd_basic_verified_';

	/**
	 * How long the pending-verification and verified-confirmation transients live. Long enough for the
	 * confirmation round trip (including a browser's native BASIC dialog, which a person may take a moment to
	 * fill in); short enough that a confirmation from one attempt cannot resurface for a later, unrelated one.
	 * 確認待ち・確認成功の transient を残す時間。ブラウザのネイティブな BASIC ダイアログへの入力を含む往復に
	 * 十分な長さにしつつ、ある試行の確認が後の無関係な試行に紛れ込まない短さにする。
	 *
	 * @var int
	 */
	const VERIFY_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Option that holds the receive diagnosis result (docs/spec.md 5.3, "受信の診断"). Not autoloaded: read
	 * only on the Access Restriction settings tab and by the save-time gate that blocks turning BASIC mode on.
	 * 受信の診断（docs/spec.md 5.3）の結果を持つオプション。autoload しない。「アクセス制限」設定タブと、
	 * BASIC モードを有効にする保存を止めるゲートでしか読まない。
	 *
	 * @var string
	 */
	const DIAG_RESULT_OPTION = 'acgd_basic_diagnosis';

	/**
	 * Query variable that marks the front-end probe endpoint of the receive diagnosis's loopback request.
	 * 受信の診断のループバックリクエストが使う、フロント側の応答エンドポイントを見分けるクエリ変数。
	 *
	 * @var string
	 */
	const DIAG_QUERY_VAR = 'acgd_basic_diag';

	/**
	 * Prefix of the one-time transient token that authorizes one probe response (docs/spec.md 5.3). Random
	 * per run, so no fixed key exists for uninstall.php to remove; it is deleted the moment it is used, and
	 * expires on its own regardless (see run_diagnosis()).
	 * 受信の診断の1回きりの応答を許す使い捨てトークンの transient の接頭辞。実行ごとに乱数で決まるため、
	 * uninstall.php で消せる固定のキーが無い。使われた瞬間に消え、そうでなくても自然に期限切れになる
	 * （run_diagnosis() を参照）。
	 *
	 * @var string
	 */
	const DIAG_TOKEN_PREFIX = 'acgd_basic_diag_token_';

	/**
	 * Dummy credentials sent by the receive diagnosis's loopback request. Meaningless on their own; only
	 * whether they arrive at PHP at all is checked, never against any real user's credentials.
	 * 受信の診断のループバックリクエストが送るダミーの資格情報。値そのものに意味は無く、
	 * PHP に届くかどうかだけを確かめる（実在のユーザーの資格情報とは照合しない）。
	 *
	 * @var string
	 */
	const DIAG_TEST_USER = 'acgd-diagnosis';
	const DIAG_TEST_PASS = 'acgd-diagnosis';

	/**
	 * In-request cache of get_submitted_credentials(): false = not parsed yet, null = parsed, nothing found,
	 * array = parsed credentials. $_SERVER is read at most once per request, so a later call still returns
	 * the same result even after maybe_strip_own_header() has already cleared $_SERVER.
	 * get_submitted_credentials() のリクエスト内キャッシュ。false=未解析、null=解析済みで何も無かった、
	 * array=解析済みの資格情報。$_SERVER の読み取りはリクエストにつき最大1回。maybe_strip_own_header() が
	 * 既に $_SERVER を消した後でも、後続の呼び出しは同じ結果を返す。
	 *
	 * @var array|null|false
	 */
	private static $submitted_credentials = false;

	/**
	 * In-request cache of the user whose saved BASIC credentials match the credentials submitted with this
	 * request: false = not resolved yet, null = resolved, no match, WP_User = the matching user.
	 * このリクエストで送信された資格情報が、保存済みの BASIC 資格情報と一致したユーザーのリクエスト内
	 * キャッシュ。false=未解決、null=解決済みで一致なし、WP_User=一致したユーザー。
	 *
	 * @var WP_User|null|false
	 */
	private static $matched_user = false;

	/**
	 * Registers the hooks. / フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		/*
		 * Priority 1: well before core's own determine_current_user callbacks (10 for the auth cookie, 20 for
		 * application passwords, Puc/... etc.). This must run before the application password one so that,
		 * once we recognize the submitted credentials as this plugin's own, we can clear them from $_SERVER
		 * before core ever tries to interpret them as an application password attempt (docs/spec.md 5.3 ★★★).
		 * 優先度1：本体自身の determine_current_user のコールバック（cookie 用の10、アプリケーションパスワード
		 * 用の20など）より十分前。アプリケーションパスワード用より前に動く必要があるのは、送信された資格情報が
		 * このプラグイン自身のものだと分かった時点で、本体がそれをアプリケーションパスワードの試行として
		 * 解釈する前に $_SERVER から消すため（docs/spec.md 5.3 ★★★）。
		 */
		add_filter( 'determine_current_user', array( __CLASS__, 'maybe_strip_own_header' ), 1 );

		// The confirmation screen and the "Verify" round trip, both at the wp-login.php level (docs/spec.md 5.3).
		// 確認画面と「確認」ボタンの往復。どちらも wp-login.php の階層（docs/spec.md 5.3）。
		add_action( 'login_form_' . self::CHALLENGE_ACTION, array( __CLASS__, 'handle_challenge' ) );
		add_action( 'login_form_' . self::VERIFY_ACTION, array( __CLASS__, 'handle_verify' ) );
		add_action( 'admin_post_' . self::VERIFY_REQUEST_ACTION, array( __CLASS__, 'handle_verify_request' ) );

		// Receive diagnosis (docs/spec.md 5.3). / 受信の診断（docs/spec.md 5.3）。
		add_action( 'admin_post_acgd_run_basic_diagnosis', array( __CLASS__, 'handle_run_diagnosis' ) );
		// Not admin_init: the probe is an unauthenticated front-end request, like a real BASIC-protected one.
		// admin_init ではない。診断の応答は、実際の BASIC 保護対象と同じ未認証のフロント側リクエストのため。
		add_action( 'init', array( __CLASS__, 'maybe_respond_to_diagnosis_probe' ) );
	}

	/*-------------------------------------------*/
	/* Reading submitted credentials / 送信された資格情報の読み取り
	/*-------------------------------------------*/

	/**
	 * Extracts BASIC authentication credentials from the current request, from PHP_AUTH_USER/PHP_AUTH_PW or,
	 * failing that, a self-decoded HTTP_AUTHORIZATION / REDIRECT_HTTP_AUTHORIZATION header (docs/spec.md 5.3:
	 * CGI/FastCGI does not populate PHP_AUTH_*). Adapted from PageGuard's inc/class-auth.php. Parses $_SERVER
	 * at most once per request and caches the result, so a call after maybe_strip_own_header() has cleared
	 * $_SERVER still returns what was originally submitted.
	 * 現在のリクエストから BASIC 認証の資格情報を取り出す。PHP_AUTH_USER/PHP_AUTH_PW を優先し、
	 * 無ければ HTTP_AUTHORIZATION / REDIRECT_HTTP_AUTHORIZATION を自前で base64 デコードする
	 * （docs/spec.md 5.3：CGI/FastCGI では PHP_AUTH_* が埋まらない）。PageGuard の inc/class-auth.php を
	 * 参考にしている。$_SERVER の解析はリクエストにつき最大1回でキャッシュするため、
	 * maybe_strip_own_header() が $_SERVER を消した後に呼んでも、元々送信されていた内容を返す。
	 *
	 * @return array|null {
	 *     @type string $username Submitted ID. / 送信された ID。
	 *     @type string $password Submitted password. / 送信されたパスワード。
	 * } or null when nothing was submitted. / 何も送信されていなければ null。
	 */
	public static function get_submitted_credentials() {
		if ( false !== self::$submitted_credentials ) {
			return self::$submitted_credentials;
		}

		self::$submitted_credentials = self::parse_submitted_credentials();

		return self::$submitted_credentials;
	}

	/**
	 * Does the actual parsing for get_submitted_credentials(), called at most once per request.
	 * get_submitted_credentials() の実際の解析を行う。リクエストにつき最大1回だけ呼ばれる。
	 *
	 * @return array|null See get_submitted_credentials(). / get_submitted_credentials() を参照。
	 */
	private static function parse_submitted_credentials() {
		if ( isset( $_SERVER['PHP_AUTH_USER'] ) && '' !== $_SERVER['PHP_AUTH_USER'] ) {
			return array(
				// $_SERVER goes through wp_magic_quotes() too; wp_unslash() avoids a false mismatch for a
				// password containing a backslash or a quote.
				// $_SERVER にも wp_magic_quotes() が掛かるため、wp_unslash() を通す。これを忘れると
				// バックスラッシュや引用符を含むパスワードが一致しなくなる。
				'username' => (string) wp_unslash( $_SERVER['PHP_AUTH_USER'] ),
				'password' => isset( $_SERVER['PHP_AUTH_PW'] ) ? (string) wp_unslash( $_SERVER['PHP_AUTH_PW'] ) : '',
			);
		}

		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$header = trim( (string) wp_unslash( $_SERVER[ $key ] ) );
			if ( 0 !== stripos( $header, 'Basic ' ) ) { // Scheme name is case-insensitive (RFC 7235). / スキーム名は大文字小文字を区別しない（RFC 7235）。
				continue;
			}

			$encoded = trim( substr( $header, 6 ) );
			if ( '' === $encoded ) {
				continue;
			}

			$decoded = base64_decode( $encoded, true ); // Strict mode: malformed base64 becomes false. / 厳格モード：不正な base64 は false になる。
			if ( false === $decoded ) {
				continue;
			}

			$position = strpos( $decoded, ':' );
			if ( false === $position ) {
				continue;
			}

			return array(
				'username' => substr( $decoded, 0, $position ), // The password may itself contain a colon, so split on the first one only. / パスワード側にコロンが入り得るので、最初のコロンだけで分割する。
				'password' => substr( $decoded, $position + 1 ),
			);
		}

		return null;
	}

	/**
	 * Removes this request's BASIC authentication headers from $_SERVER (docs/spec.md 5.3 ★★★). Called only
	 * after the submitted credentials have already been matched to this plugin's own user, so core never gets
	 * a chance to treat them as an application password attempt (see the class docblock and init()).
	 * このリクエストの BASIC 認証ヘッダーを $_SERVER から消す（docs/spec.md 5.3 ★★★）。送信された資格情報が
	 * このプラグイン自身のユーザーと一致すると分かった後にだけ呼ぶ。本体がそれをアプリケーションパスワードの
	 * 試行として扱う機会を与えないため（クラスの docblock と init() を参照）。
	 *
	 * @return void
	 */
	private static function clear_submitted_credentials_from_server() {
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
	}

	/*-------------------------------------------*/
	/* Matching against a user / ユーザーとの照合
	/*-------------------------------------------*/

	/**
	 * Filters determine_current_user to detect this request's BASIC credentials, match them against every
	 * user's saved ones, and — only on a match — strip them from $_SERVER (docs/spec.md 5.3 ★★★). Never
	 * changes $input: this callback only has a side effect (clearing $_SERVER); it is not itself how a user
	 * gets logged in (BASIC authentication gates access after a normal WordPress login, see docs/spec.md 5.3
	 * and ACGD_Access_Restriction::filter_wp_authenticate_user()).
	 * determine_current_user をフィルタし、このリクエストの BASIC 資格情報を検出して全ユーザーの保存済み
	 * 資格情報と照合し、一致したときだけ $_SERVER から消す（docs/spec.md 5.3 ★★★）。$input は一切変えない：
	 * このコールバックは副作用（$_SERVER を消すこと）だけが目的で、これ自体はユーザーをログインさせる手段では
	 * ない（BASIC 認証は通常の WordPress ログインの後にアクセスを絞る仕組み。docs/spec.md 5.3 と
	 * ACGD_Access_Restriction::filter_wp_authenticate_user() を参照）。
	 *
	 * @param int|false $input Result of the earlier determine_current_user callbacks. / それまでのコールバックの結果。
	 * @return int|false The result, unchanged. / 結果（変更しない）。
	 */
	public static function maybe_strip_own_header( $input ) {
		if ( false !== self::$matched_user ) {
			return $input; // Already resolved earlier in this request. / このリクエストで既に解決済み。
		}

		try {
			if ( ACGD_Access_Restriction::is_disabled() ) {
				self::$matched_user = null;
				return $input;
			}

			$submitted = self::get_submitted_credentials();
			if ( null === $submitted ) {
				self::$matched_user = null;
				return $input;
			}

			$matched = ACGD_Access_Restriction::find_basic_user_by_credentials( $submitted['username'], $submitted['password'] );
			self::$matched_user = $matched ? $matched : null;

			if ( $matched ) {
				self::clear_submitted_credentials_from_server();
			}
		} catch ( Throwable $e ) {
			// Fail open: never let a fault here break core's own authentication for this request. Also record
			// it through ACGD_Access_Restriction's own shared fault log (docs/spec.md 5.5, MEDIUM-2 fix, PR #6
			// review), so a manage_options user sees the same "Access Restriction is stopped" warning that the
			// other four Throwable catches already raise; record_fault() is the one method that writes it.
			// 止めて通す：ここでの故障が、このリクエストの本体側の認証を壊すことは絶対に無いようにする。
			// あわせて ACGD_Access_Restriction 側の共通の故障記録にも残す（docs/spec.md 5.5、MEDIUM-2 の修正。
			// PR #6 レビュー）。これにより、他の4箇所の Throwable の catch と同じ「アクセス制限は停止中」の
			// 警告が manage_options の人に出るようになる。それを書き込む唯一のメソッドが record_fault()。
			self::$matched_user = null;
			ACGD_Access_Restriction::record_fault( $e->getMessage() );
		}

		return $input;
	}

	/**
	 * Returns the user whose saved BASIC credentials match this request's, resolving it now if
	 * maybe_strip_own_header() has not already run (nothing in this request has called wp_get_current_user()
	 * yet). Safe to call repeatedly; the result is cached (see $matched_user).
	 * このリクエストの資格情報と一致する、保存済み BASIC 資格情報を持つユーザーを返す。
	 * maybe_strip_own_header() がまだ動いていなければ（このリクエストで wp_get_current_user() が
	 * 一度も呼ばれていなければ）ここで解決する。何度呼んでも安全（$matched_user にキャッシュする）。
	 *
	 * @return WP_User|null Matching user, or null. / 一致するユーザー。無ければ null。
	 */
	public static function get_matched_user() {
		if ( false === self::$matched_user ) {
			wp_get_current_user(); // Forces determine_current_user, and therefore maybe_strip_own_header(), to run. / determine_current_user、つまり maybe_strip_own_header() を強制的に動かす。
		}

		return false === self::$matched_user ? null : self::$matched_user;
	}

	/**
	 * Tells whether this request already satisfies BASIC authentication for the given user: its submitted
	 * credentials matched, and matched specifically this user's own (docs/spec.md 5.3, "別のユーザーの
	 * 資格情報では通れない"), not merely some user's. Used both by the enforcement hooks of
	 * ACGD_Access_Restriction and by its own save-time check 2 (current_user_still_allowed()).
	 * 与えたユーザーについて、このリクエストが既に BASIC 認証を満たしているかを返す。送信された資格情報が
	 * 一致し、かつそれがまさにこのユーザー自身のものであること（docs/spec.md 5.3「別のユーザーの資格情報
	 * では通れない」）。ACGD_Access_Restriction の判定フックと、保存時のチェック2
	 * （current_user_still_allowed()）の両方から使う。
	 *
	 * @param WP_User $user User whose BASIC requirement is being judged. / BASIC の要件を判定する対象のユーザー。
	 * @return bool Whether satisfied. / 満たしているか。
	 */
	public static function request_satisfies( WP_User $user ) {
		$matched = self::get_matched_user();

		return $matched instanceof WP_User && $matched->ID === $user->ID;
	}

	/*-------------------------------------------*/
	/* Confirmation screen (docs/spec.md 5.3) / 確認画面（docs/spec.md 5.3）
	/*-------------------------------------------*/

	/**
	 * Handles wp-login.php?action=acgd_basic: a native "401 + WWW-Authenticate: Basic" prompt, never a custom
	 * HTML login form (docs/spec.md 5.3, UX review). Reached only after a normal WordPress login already
	 * succeeded (the cookie identifies who this is for); a visitor who is not logged in at all is sent to the
	 * ordinary login screen instead. Always exits.
	 * wp-login.php?action=acgd_basic を処理する。独自の HTML ログインフォームではなく、ネイティブな
	 * 「401 + WWW-Authenticate: Basic」の入口だけ（docs/spec.md 5.3、UX レビュー）。通常の WordPress
	 * ログインが既に成功した後にだけ到達する（cookie で本人が分かる）。そもそもログインしていない訪問者は
	 * 通常のログイン画面へ送る。必ず exit する。
	 *
	 * @return void
	 */
	public static function handle_challenge() {
		$redirect_to = self::validated_redirect_to( admin_url() );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $redirect_to ) );
			exit;
		}

		$user  = wp_get_current_user();
		$modes = ACGD_Access_Restriction::get_effective_modes( $user );
		if ( ! in_array( ACGD_Access_Restriction::MODE_BASIC, $modes, true ) ) {
			// Nothing to confirm any more (the mode changed since the redirect that sent them here).
			// もう確認すべきものが無い（ここへ送られた後にモードが変わった）。
			wp_safe_redirect( $redirect_to );
			exit;
		}

		if ( ACGD_Basic_Auth::request_satisfies( $user ) ) {
			wp_safe_redirect( $redirect_to );
			exit;
		}

		$submitted = self::get_submitted_credentials();
		if ( null === $submitted ) {
			self::send_challenge_response( self::challenge_url( $redirect_to ), false );
		}

		// Credentials were submitted (and, per request_satisfies() above, did not match this user).
		// 資格情報は送信されたが（上の request_satisfies() の結果どおり）このユーザーとは一致しなかった。
		ACGD_Access_Restriction::log_denial( $user->ID, ACGD_Access_Restriction::get_remote_addr(), 'basic' );
		self::send_challenge_response( self::challenge_url( $redirect_to ), true );
	}

	/**
	 * Sends the 401 + WWW-Authenticate response and a minimal HTML body, then exits. Reused for both the
	 * plain first prompt and a re-prompt after a wrong attempt; most browsers show their native dialog again
	 * automatically on either, so the body (shown only if the visitor cancels the dialog) is what needs to
	 * explain the situation and offer a way out (docs/spec.md 5.3, UX review): a link that reloads the same
	 * URL (retrying the dialog), a nonce-protected logout link, and a note for the rare case where no dialog
	 * appears at all (a remote browser isolation service, for example — docs/spec.md 5.3 marks this untested).
	 * 401 + WWW-Authenticate を送り、最小限の HTML 本文を出して exit する。最初の単純な提示と、誤答後の
	 * 再提示のどちらにも使う。どちらでもほとんどのブラウザはネイティブなダイアログを自動で出し直すため、
	 * 本文（ダイアログをキャンセルしたときだけ見える）には状況説明と復帰手段を書く（docs/spec.md 5.3、
	 * UX レビュー）：同じ URL を再読み込みするリンク（ダイアログのやり直し）、nonce 付きのログアウトリンク、
	 * ダイアログが一切出ない稀なケース（リモートブラウザ分離サービスなど。docs/spec.md 5.3 は未検証と
	 * 明記している）向けの一言。
	 *
	 * @param string $retry_url URL to reload to try again (already validated). / 再試行のために再読み込みする URL（検証済み）。
	 * @param bool   $failed    Whether this follows a wrong attempt (changes the wording only). / 誤答の後かどうか（文言だけが変わる）。
	 * @return void
	 */
	private static function send_challenge_response( $retry_url, $failed ) {
		nocache_headers();
		header( 'WWW-Authenticate: Basic realm="' . self::get_realm() . '", charset="UTF-8"' );
		status_header( 401 );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

		$logout_url = wp_logout_url( $retry_url ); // Core adds its own nonce. / 本体が自前で nonce を付ける。

		if ( $failed ) {
			$title = __( 'The username or password was not accepted', 'etbs-account-guard' );
			$intro = __( 'This screen protects your account with a second username and password (BASIC authentication), separate from your WordPress login.', 'etbs-account-guard' );
		} else {
			$title = __( 'A second sign-in is required', 'etbs-account-guard' );
			$intro = __( 'This screen protects your account with a second username and password (BASIC authentication), separate from your WordPress login.', 'etbs-account-guard' );
		}

		$lines = array(
			$intro,
			__( 'If a username/password prompt did not appear, use the link below to try again.', 'etbs-account-guard' ),
			__( 'If you no longer have the second username and password, contact your site administrator.', 'etbs-account-guard' ),
		);

		self::render_minimal_page(
			$title,
			$lines,
			array(
				array(
					// Reloading the same URL makes most browsers re-prompt for BASIC credentials.
					// 同じ URL を再読み込みすると、ほとんどのブラウザは BASIC の入力を出し直す。
					'url'     => $retry_url,
					'label'   => __( 'Try again', 'etbs-account-guard' ),
					'primary' => true,
				),
				array(
					'url'     => $logout_url,
					'label'   => __( 'Log out', 'etbs-account-guard' ),
					'primary' => false,
				),
			)
		);
		exit;
	}

	/**
	 * Builds the realm string for the WWW-Authenticate header: the site name plus a short note of purpose, so
	 * this dialog is not confused with the ordinary WordPress login (docs/spec.md 5.3, UX review). One realm
	 * for the whole site (docs/spec.md 5.3), so the browser attaches the credentials it learns here to every
	 * later request under the same origin. Quotes, backslashes and line breaks are stripped so the site name
	 * cannot break the header's syntax or inject another header (adapted from PageGuard's inc/class-auth.php).
	 * WWW-Authenticate ヘッダーの realm 文字列を組み立てる：サイト名＋短い用途の一言とし、通常の WordPress
	 * ログインと混同しにくくする（docs/spec.md 5.3、UX レビュー）。realm はサイトごとに1つ（docs/spec.md
	 * 5.3）とし、ブラウザがここで覚えた資格情報を、同じオリジンの以降のリクエストすべてに付けるようにする。
	 * 引用符・バックスラッシュ・改行は取り除き、サイト名がヘッダーの構文を壊したり別のヘッダーを注入したり
	 * できないようにする（PageGuard の inc/class-auth.php を参考にしている）。
	 *
	 * @return string Realm string, ready to embed in the header (not otherwise escaped). / realm 文字列（ヘッダーに埋め込める状態。これ以上のエスケープはしない）。
	 */
	private static function get_realm() {
		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name = str_replace( array( '"', '\\' ), '', (string) $name );
		$name = preg_replace( '/[\r\n\t]+/', ' ', $name );
		$name = trim( (string) $name );

		if ( '' === $name ) {
			$name = 'ETBS Account Guard';
		}

		/* translators: %s: site name, used as the realm shown in the browser's BASIC authentication prompt */
		return sprintf( __( '%s Staff Access', 'etbs-account-guard' ), $name );
	}

	/**
	 * Returns the URL of the confirmation screen (CHALLENGE_ACTION), with a validated redirect_to.
	 * 確認画面（CHALLENGE_ACTION）の URL を返す。redirect_to は検証済みのものを使う。
	 *
	 * @param string $redirect_to Already-validated destination. / 検証済みの行き先。
	 * @return string URL, not escaped. / URL（未エスケープ）。
	 */
	public static function challenge_url( $redirect_to ) {
		return add_query_arg(
			array(
				'action'      => self::CHALLENGE_ACTION,
				'redirect_to' => rawurlencode( $redirect_to ),
			),
			wp_login_url()
		);
	}

	/**
	 * Redirects the current request to the confirmation screen and exits (docs/spec.md 5.3, "管理画面のときだけ
	 * 確認画面へ送る"). Called only from ACGD_Access_Restriction::check_access_on_request(), for a plain
	 * navigation to an admin screen. The current request's own URL becomes redirect_to, so confirming sends
	 * the visitor back to the page they were trying to reach.
	 * 現在のリクエストを確認画面へリダイレクトして exit する（docs/spec.md 5.3「管理画面のときだけ確認画面へ
	 * 送る」）。ACGD_Access_Restriction::check_access_on_request() の、素の管理画面ナビゲーションからのみ呼ぶ。
	 * 今のリクエスト自身の URL を redirect_to にするので、確認できれば元々開こうとしていた画面へ戻る。
	 *
	 * @return void
	 */
	public static function redirect_to_challenge() {
		$current = ( is_ssl() ? 'https://' : 'http://' );
		$current .= isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$current .= isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		wp_safe_redirect( self::challenge_url( self::validated_redirect_to( $current ) ) );
		exit;
	}

	/*-------------------------------------------*/
	/* The user edit screen's "Verify" round trip (docs/spec.md 5.3) / ユーザー編集画面の「確認」の往復（docs/spec.md 5.3）
	/*-------------------------------------------*/

	/**
	 * Handles the admin-post request behind the user edit screen's "Verify" button (a secondary submit
	 * button, using the formaction/formmethod attributes to post the same form's fields — mode, added IPs,
	 * and the BASIC id/password — to this URL instead of the profile screen; see ACGD_User_Access). Stashes
	 * the submitted form for redisplay and the (id, password) pair to confirm, then sends the browser to
	 * VERIFY_ACTION. Only ever meaningful for an admin verifying their own account (docs/spec.md 5.3); a
	 * mismatched target is bounced back without starting a confirmation.
	 * ユーザー編集画面の「確認」ボタン（formaction/formmethod 属性で、同じフォームの内容——モード・追加した
	 * IP・BASIC の ID/パスワード——をプロフィール画面ではなくこの URL へ POST する副次的な送信ボタン。
	 * ACGD_User_Access を参照）の裏側にある admin-post リクエストを処理する。送信されたフォームを
	 * 出し直し用に、(id, password) の組を確認用にそれぞれ保存してから、ブラウザを VERIFY_ACTION へ送る。
	 * 意味を持つのは管理者が自分自身を確認するときだけ（docs/spec.md 5.3）。対象が食い違っていれば、
	 * 確認を始めずに送り返す。
	 *
	 * @return void
	 */
	public static function handle_verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'etbs-account-guard' ), '', array( 'response' => 403 ) );
		}
		if ( ! isset( $_POST[ ACGD_User_Access::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ ACGD_User_Access::NONCE_NAME ] ) ), ACGD_User_Access::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'This link has expired. Please try again.', 'etbs-account-guard' ), '', array( 'response' => 403 ) );
		}

		$admin_id  = get_current_user_id();
		$target_id = isset( $_POST['acgd_user_id'] ) ? (int) $_POST['acgd_user_id'] : 0;
		$edit_url  = admin_url( 'user-edit.php?user_id=' . $target_id );

		$mode     = isset( $_POST['acgd_user_mode'] ) ? sanitize_key( wp_unslash( $_POST['acgd_user_mode'] ) ) : 'follow';
		$ip_text  = isset( $_POST['acgd_user_ips'] ) ? (string) wp_unslash( $_POST['acgd_user_ips'] ) : '';
		$basic_id = isset( $_POST['acgd_basic_id'] ) ? sanitize_text_field( wp_unslash( $_POST['acgd_basic_id'] ) ) : '';
		$password = isset( $_POST['acgd_basic_password'] ) ? (string) wp_unslash( $_POST['acgd_basic_password'] ) : '';

		// Whatever was typed is worth redisplaying either way, so the admin does not have to retype it
		// (the password itself is the one exception: never put a submitted password back into the page — see
		// ACGD_User_Access::render_fields()). / どちらにしても入力内容は出し直す価値がある（管理者が
		// 打ち直さなくて済むように）。パスワードだけは例外（送信されたパスワードを画面に戻さない。
		// ACGD_User_Access::render_fields() を参照）。
		ACGD_User_Access::stash_resubmit( $target_id, $mode, $ip_text, $basic_id );

		if ( $target_id !== $admin_id ) {
			// The "Verify" button only makes sense for one's own account (docs/spec.md 5.3): confirming
			// satisfies save-time check 2 for the person saving, which is only ever the current admin.
			// This is reported through a query flag, not the WP_Error queue used by save_fields()/
			// append_pending_error(): that mechanism only fires during core's own profile save (POST to
			// user-edit.php itself), which this admin-post redirect is not.
			// 「確認」ボタンが意味を持つのは自分自身の口座だけ（docs/spec.md 5.3）：確認が満たすのは
			// 保存する本人（＝常に今の管理者）の保存時チェック2であるため。ここではクエリの目印で伝える。
			// save_fields()/append_pending_error() の WP_Error の仕組みは、本体自身のプロフィール保存
			// （user-edit.php 自身への POST）のときにしか発火せず、この admin-post のリダイレクトはそれに当たらない。
			wp_safe_redirect( add_query_arg( 'acgd_basic_verify', 'not_self', $edit_url ) );
			exit;
		}
		if ( 'basic' !== $mode || '' === $basic_id || '' === $password ) {
			wp_safe_redirect( add_query_arg( 'acgd_basic_verify', 'incomplete', $edit_url ) );
			exit;
		}

		set_transient(
			self::PENDING_TRANSIENT_PREFIX . $admin_id,
			array(
				'id'       => $basic_id,
				'password' => $password,
			),
			self::VERIFY_TTL
		);

		wp_safe_redirect( self::verify_url( $edit_url ) );
		exit;
	}

	/**
	 * Handles wp-login.php?action=acgd_basic_verify: challenges the current admin for the BASIC credentials
	 * they just typed on the user edit screen (still unsaved, held in the PENDING_TRANSIENT_PREFIX
	 * transient), so that typing them correctly proves the credentials work before they are ever saved
	 * (docs/spec.md 5.3, save-time check 2 for one's own account — see ACGD_User_Access::save_fields()).
	 * Compares with hash_equals() against the exact submitted values, not password_verify(): there is no
	 * hash yet, this is a plain-text round trip of what the admin just typed. Always exits.
	 * wp-login.php?action=acgd_basic_verify を処理する。ユーザー編集画面でたった今入力した（まだ保存して
	 * いない。PENDING_TRANSIENT_PREFIX の transient にある）BASIC 資格情報を、今の管理者に対して実際に
	 * 求める。正しく入力できることで、保存する前に「その資格情報が動く」ことを確かめる（docs/spec.md 5.3、
	 * 自分自身に対する保存時チェック2。ACGD_User_Access::save_fields() を参照）。password_verify() ではなく
	 * hash_equals() で送信された値そのものと比べる：まだハッシュが無く、管理者がたった今入力した平文の
	 * 往復にすぎないため。必ず exit する。
	 *
	 * @return void
	 */
	public static function handle_verify() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'etbs-account-guard' ), '', array( 'response' => 403 ) );
		}

		$admin_id     = get_current_user_id();
		$default_back = admin_url( 'user-edit.php?user_id=' . $admin_id );
		$redirect_to  = self::validated_redirect_to( $default_back );

		$pending_key = self::PENDING_TRANSIENT_PREFIX . $admin_id;
		$pending     = get_transient( $pending_key );
		if ( ! is_array( $pending ) || ! isset( $pending['id'], $pending['password'] ) ) {
			wp_safe_redirect( add_query_arg( 'acgd_basic_verify', 'expired', $redirect_to ) );
			exit;
		}

		$submitted = self::get_submitted_credentials();
		if ( null === $submitted ) {
			self::send_challenge_response( self::verify_url( $redirect_to ), false );
		}

		if ( hash_equals( (string) $pending['id'], $submitted['username'] ) && hash_equals( (string) $pending['password'], $submitted['password'] ) ) {
			// Not core's own credentials, so no other code will strip these; do it ourselves anyway
			// (docs/spec.md 5.3 ★★★ applies to any credentials this plugin recognizes, confirmed or saved alike).
			// 本体自身の資格情報ではないので他のコードは消さない。それでも自分で消す
			// （docs/spec.md 5.3 ★★★ は、保存済みかどうかにかかわらずこのプラグインが認識した資格情報全般に掛かる）。
			self::clear_submitted_credentials_from_server();
			delete_transient( $pending_key );
			set_transient(
				self::VERIFIED_TRANSIENT_PREFIX . $admin_id,
				array(
					'id'   => $pending['id'],
					// Computed once, here, so a later resubmit with a blank password field (MEDIUM-1 fix) can
					// still reuse this exact hash instead of needing the password retyped. / ここで一度だけ
					// 計算しておく。パスワード欄が空のまま出し直されても（MEDIUM-1 の修正）、再入力なしに
					// このハッシュをそのまま使い回せるようにするため。
					'hash' => password_hash( $pending['password'], PASSWORD_DEFAULT ),
				),
				self::VERIFY_TTL
			);
			wp_safe_redirect( add_query_arg( 'acgd_basic_verify', 'ok', $redirect_to ) );
			exit;
		}

		self::send_challenge_response( self::verify_url( $redirect_to ), true );
	}

	/**
	 * Returns the URL of the "Verify" confirmation screen (VERIFY_ACTION). / 「確認」画面（VERIFY_ACTION）の URL を返す。
	 *
	 * @param string $redirect_to Already-validated destination. / 検証済みの行き先。
	 * @return string URL, not escaped. / URL（未エスケープ）。
	 */
	private static function verify_url( $redirect_to ) {
		return add_query_arg(
			array(
				'action'      => self::VERIFY_ACTION,
				'redirect_to' => rawurlencode( $redirect_to ),
			),
			wp_login_url()
		);
	}

	/**
	 * Tells whether a fresh "Verify" confirmation exists for the given admin and ID, and consumes it (deletes
	 * the transient) so it cannot be reused for a different save. Called by ACGD_User_Access::save_fields()
	 * when an admin is setting their own mode to BASIC authentication.
	 *
	 * $password is what to require of the confirmation, not what to hash and store: pass the exact string just
	 * retyped in the password field to require it match what was confirmed (password_verify() against the
	 * hash from handle_verify()), or null when that field was left blank — the normal case right after a
	 * successful Verify, since render_fields() never redisplays a password — to trust the confirmation as-is
	 * and hand back its hash unchanged (MEDIUM-1 fix, PR #6 review: previously a blank password field here
	 * always failed this check, silently leaving the old hash saved even though "Verify" had just succeeded).
	 * 与えた管理者と ID について、確認済みの結果があるかを返し、あれば消費する（transient を消し、別の保存に
	 * 使い回せないようにする）。管理者が自分自身のモードを BASIC 認証にするとき、
	 * ACGD_User_Access::save_fields() から呼ぶ。
	 *
	 * $password は「確認済みのものに何を求めるか」であって、ハッシュ化して保存する対象ではない：パスワード欄に
	 * たった今入力し直した文字列そのものを渡せば、確認済みのものと一致すること（handle_verify() が作った
	 * ハッシュに対する password_verify()）を求める。その欄が空のまま（render_fields() はパスワードを一切
	 * 出し直さないため、「確認」成功直後の通常のケース）なら null を渡し、確認済みの内容をそのまま信頼して
	 * そのハッシュを変更せずに返す（MEDIUM-1 の修正。PR #6 レビュー：修正前はここでパスワード欄が空だと
	 * 必ずこのチェックに失敗し、「確認」に成功した直後でも古いハッシュが無言で保存されたまま残っていた）。
	 *
	 * @param int         $admin_id Admin who confirmed. / 確認した管理者。
	 * @param string      $id       ID being saved now. / 今保存しようとしている ID。
	 * @param string|null $password Password just retyped, or null if that field was left blank. / たった今入力し直したパスワード。欄が空なら null。
	 * @return string|null The confirmed password's password_hash(), ready to save as-is, or null when there is
	 *                      no matching, unconsumed confirmation. / 確認済みパスワードの password_hash()（そのまま
	 *                      保存できる）。一致する未消費の確認が無ければ null。
	 */
	public static function consume_verification( $admin_id, $id, $password ) {
		$key   = self::VERIFIED_TRANSIENT_PREFIX . (int) $admin_id;
		$value = get_transient( $key );
		if ( ! is_array( $value ) || ! isset( $value['id'], $value['hash'] ) ) {
			return null;
		}

		delete_transient( $key ); // Single use either way (matched or not). / 一致してもしなくても1回きりで消費する。

		if ( ! hash_equals( (string) $value['id'], (string) $id ) ) {
			return null; // Confirmed a different ID than the one being saved now. / 確認したのは今保存しようとしているのとは別の ID。
		}
		if ( null !== $password && ! password_verify( $password, $value['hash'] ) ) {
			return null; // Retyped, but not what was actually confirmed. / 入力し直されたが、確認済みの内容とは一致しない。
		}

		return $value['hash'];
	}

	/*-------------------------------------------*/
	/* Shared helpers / 共通のヘルパー
	/*-------------------------------------------*/

	/**
	 * Validates a redirect_to value (docs/spec.md 5.3, "redirect_to は wp_validate_redirect で検証する"),
	 * reading it from the request when present.
	 * redirect_to の値を検証する（docs/spec.md 5.3「redirect_to は wp_validate_redirect で検証する」）。
	 * リクエストにあればそこから読む。
	 *
	 * @param string $default Fallback when missing or invalid. / 無い・不正なときの既定値。
	 * @return string Validated URL. / 検証済みの URL。
	 */
	private static function validated_redirect_to( $default ) {
		$raw = isset( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; the value itself is validated by wp_validate_redirect() below, and nothing is changed by reading it.

		if ( '' === $raw ) {
			// wp_validate_redirect() does NOT fall back to $default when $raw is '' (core bug-for-bug
			// behavior, wp-includes/pluggable.php): parse_url('') returns an array with no 'host', so the
			// host-mismatch branch that substitutes $default never fires, and '' is returned unchanged.
			// That empty string then reaches wp_safe_redirect()/wp_redirect(), whose
			// `if ( ! $location ) { return false; }` guard silently emits no headers and no body at all —
			// a "200 OK, 0 bytes" response instead of a redirect. This hit the plugin's most ordinary path
			// (unauthenticated wp-admin access → challenge screen → correct credentials), since
			// redirect_to_challenge() also routes through this method without a redirect_to request param
			// (issue #4, UI test finding). Fall back ourselves; never leave it to wp_validate_redirect().
			// wp_validate_redirect() は $raw が '' のとき $default へフォールバックしない（本体の仕様。
			// wp-includes/pluggable.php）：parse_url('') は 'host' を持たない配列を返すため、$default に
			// 差し替えるホスト不一致の分岐が発火せず、'' がそのまま返る。その空文字が
			// wp_safe_redirect()/wp_redirect() に渡ると、`if ( ! $location ) { return false; }` の
			// ガードに引っかかり、ヘッダーも本文も一切出さずに終わる——リダイレクトではなく
			// 「200 OK・本文0バイト」になる。redirect_to_challenge() も redirect_to のリクエストパラメータ
			// 無しでこのメソッドを通るため、このプラグインで一番普通の導線（未認証での wp-admin アクセス→
			// 確認画面→正しい資格情報）がまるごと壊れていた（issue #4、UIテストで判明）。ここで自分で
			// フォールバックする。wp_validate_redirect() 任せにしない。
			return $default;
		}

		return wp_validate_redirect( $raw, $default );
	}

	/**
	 * Prints a minimal, self-contained HTML page (no theme template: this can run before WordPress has
	 * chosen one, and must never leak a protected page's own markup) and exits. Adapted from PageGuard's
	 * inc/class-auth.php render_page().
	 * 最小限の自己完結した HTML ページを出力して exit する（テーマのテンプレートは使わない：WordPress が
	 * テンプレートを選ぶ前に動きうるうえ、保護対象ページ自身のマークアップを漏らしてはならないため）。
	 * PageGuard の inc/class-auth.php の render_page() を参考にしている。
	 *
	 * @param string   $title   Heading. / 見出し。
	 * @param string[] $lines   Paragraphs, plain text. / 段落（プレーンテキスト）。
	 * @param array    $actions Links: array of array( 'url', 'label', 'primary' ). / リンク（'url'・'label'・'primary' を持つ配列の配列）。
	 * @return void
	 */
	private static function render_minimal_page( $title, $lines, $actions ) {
		$site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$page_title = ( '' !== trim( (string) $site_name ) ) ? $title . ' | ' . $site_name : $title;
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $page_title ); ?></title>
<style>
body { margin: 0; padding: 3em 1.5em; background: #f0f0f1; color: #1d2327; font-family: -apple-system, "Segoe UI", "Hiragino Kaku Gothic ProN", "Noto Sans JP", sans-serif; line-height: 1.7; }
.acgd-box { max-width: 32em; margin: 0 auto; padding: 1.75em 2em; background: #fff; border: 1px solid #dcdcde; border-radius: 4px; }
.acgd-box h1 { margin: 0 0 .75em; font-size: 1.25em; }
.acgd-box p { margin: 0 0 .5em; }
.acgd-actions { margin: 1.5em 0 0; display: flex; flex-wrap: wrap; gap: .75em; }
.acgd-actions a { display: inline-block; padding: .5em 1.25em; border: 1px solid #2271b1; border-radius: 3px; color: #2271b1; text-decoration: none; }
.acgd-actions a:hover, .acgd-actions a:focus { background: #f0f6fc; }
.acgd-actions a.acgd-primary { background: #2271b1; color: #fff; }
.acgd-actions a.acgd-primary:hover, .acgd-actions a.acgd-primary:focus { background: #135e96; border-color: #135e96; }
@media (prefers-color-scheme: dark) {
	body { background: #1d2327; color: #f0f0f1; }
	.acgd-box { background: #2c3338; border-color: #3c434a; }
	.acgd-actions a { border-color: #72aee6; color: #72aee6; }
	.acgd-actions a:hover, .acgd-actions a:focus { background: #32373c; }
	.acgd-actions a.acgd-primary { background: #2271b1; border-color: #2271b1; color: #fff; }
	.acgd-actions a.acgd-primary:hover, .acgd-actions a.acgd-primary:focus { background: #135e96; border-color: #135e96; }
}
</style>
</head>
<body>
<div class="acgd-box">
<h1><?php echo esc_html( $title ); ?></h1>
<?php foreach ( $lines as $line ) : ?>
	<?php if ( '' === trim( (string) $line ) ) { continue; } ?>
<p><?php echo esc_html( $line ); ?></p>
<?php endforeach; ?>
<?php if ( $actions ) : ?>
<div class="acgd-actions">
	<?php foreach ( $actions as $action ) : ?>
	<a href="<?php echo esc_url( $action['url'] ); ?>"<?php echo ! empty( $action['primary'] ) ? ' class="acgd-primary"' : ''; ?>><?php echo esc_html( $action['label'] ); ?></a>
	<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</body>
</html>
		<?php
	}

	/*-------------------------------------------*/
	/* Receive diagnosis (docs/spec.md 5.3) / 受信の診断（docs/spec.md 5.3）
	/*-------------------------------------------*/

	/**
	 * Tells whether BASIC mode may currently be turned on: the receive diagnosis has been run and its saved
	 * result is 'success'. Anything else — never run, 'not_received', 'server_basic_auth' or 'unknown' — fails
	 * closed (docs/spec.md 5.3, "そのときBASICモードを保存させない"). Called by the save-time gates of both
	 * the Access Restriction tab (per role) and the user edit screen (per user).
	 * BASIC モードを今オンにしてよいかを返す。受信の診断を実行済みで、保存済みの結果が 'success' のときだけ
	 * true。それ以外（未実行・'not_received'・'server_basic_auth'・'unknown'）はすべて安全側に倒し false
	 * にする（docs/spec.md 5.3「そのときBASICモードを保存させない」）。「アクセス制限」タブ（権限ごと）と
	 * ユーザー編集画面（ユーザーごと）の両方の保存時ゲートから呼ぶ。
	 *
	 * @return bool Whether allowed. / 許可されているか。
	 */
	public static function diagnosis_allows_basic_mode() {
		$result = self::get_diagnosis_result();

		return null !== $result && 'success' === $result['status'];
	}

	/**
	 * Returns the saved receive diagnosis result, or null when it has never been run.
	 * 保存済みの受信診断の結果を返す。一度も実行していなければ null。
	 *
	 * @return array|null {
	 *     @type string $status     One of 'success', 'not_received', 'server_basic_auth' or 'unknown'. / 'success'・'not_received'・'server_basic_auth'・'unknown' のいずれか。
	 *     @type int    $checked_at When it was run (time()). / 実行した時刻（time()）。
	 *     @type string $detail     Technical detail, English/raw, for the settings screen only. / 技術的な詳細（英語・生の文言。設定画面にだけ出す）。
	 * }
	 */
	public static function get_diagnosis_result() {
		$result = get_option( self::DIAG_RESULT_OPTION, null );
		if ( ! is_array( $result ) || ! isset( $result['status'], $result['checked_at'] ) ) {
			return null;
		}

		return array(
			'status'     => (string) $result['status'],
			'checked_at' => (int) $result['checked_at'],
			'detail'     => isset( $result['detail'] ) ? (string) $result['detail'] : '',
		);
	}

	/**
	 * Handles the "Run diagnosis" button (its own small admin-post form, separate from the Settings API form
	 * of the Access Restriction tab for the same reason PageGuard keeps its diagnosis button separate: a
	 * shared form would resubmit the whole role table and IP list along with it).
	 * 「診断を実行」ボタンを処理する（「アクセス制限」タブの Settings API フォームとは別の、専用の小さな
	 * admin-post フォーム。PageGuard が診断ボタンを分けているのと同じ理由：同じフォームにすると
	 * 権限の表や IP 一覧まで一緒に送信されてしまう）。
	 *
	 * @return void
	 */
	public static function handle_run_diagnosis() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'etbs-account-guard' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'acgd_run_basic_diagnosis', 'acgd_basic_diag_nonce' );

		update_option( self::DIAG_RESULT_OPTION, self::run_diagnosis(), false ); // Not autoloaded (see the constant). / autoload しない（定数の説明を参照）。

		wp_safe_redirect( ACGD_Settings::get_page_url( 'access' ) );
		exit;
	}

	/**
	 * Runs the receive diagnosis: a loopback request to this site carrying a dummy BASIC Authorization
	 * header, inspected to tell apart three outcomes (docs/spec.md 5.3): the header reaching PHP (success),
	 * not reaching it (a CGI/FastCGI setup missing the .htaccess workaround), or being intercepted by the web
	 * server's own BASIC authentication before WordPress ever runs (only one Authorization header can be sent
	 * per request, so this plugin's own cannot coexist with it). The third case is detected from the loopback
	 * response itself — a 401 with its own WWW-Authenticate header — never from inside the probe endpoint,
	 * which never executes when the server intercepts the request first. Adapted from PageGuard's
	 * inc/class-settings.php run_diagnosis(), extended with this 401/WWW-Authenticate distinction.
	 * 受信の診断を実行する：ダミーの BASIC Authorization ヘッダーを付けた自サイトへのループバックリクエストを
	 * 送り、その応答から3つの結果を見分ける（docs/spec.md 5.3）：ヘッダーが PHP まで届く（成功）、
	 * 届かない（.htaccess の回避策が要る CGI/FastCGI 構成）、WordPress が動く前にサーバー自身の BASIC 認証に
	 * 横取りされる（1リクエストに載せられる Authorization ヘッダーは1つだけなので、このプラグイン自身のもの
	 * とは共存できない）。3つ目は、応答そのもの（自前の WWW-Authenticate を伴う 401）から判定する。
	 * サーバーがリクエストを先に横取りする場合、応答エンドポイント自体は一度も実行されないため。
	 * PageGuard の inc/class-settings.php の run_diagnosis() を参考にし、この 401/WWW-Authenticate の
	 * 区別を加えて拡張している。
	 *
	 * @return array See get_diagnosis_result(). / get_diagnosis_result() を参照。
	 */
	private static function run_diagnosis() {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( self::DIAG_TOKEN_PREFIX . $token, 1, 60 ); // 60 seconds is comfortably more than a loopback needs. / 60秒はループバックに要る時間より十分長い。

		$url = add_query_arg(
			array(
				self::DIAG_QUERY_VAR => '1',
				'token'               => $token,
			),
			home_url( '/' )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // Same reasoning as core's own loopback requests. / 本体自身のループバックリクエストと同じ考え方。
				'headers'     => array(
					'Authorization' => 'Basic ' . base64_encode( self::DIAG_TEST_USER . ':' . self::DIAG_TEST_PASS ),
				),
			)
		);

		delete_transient( self::DIAG_TOKEN_PREFIX . $token ); // Single use regardless of the outcome. / 結果に関わらず使い捨てる。

		if ( is_wp_error( $response ) ) {
			return array(
				'status'     => 'unknown',
				'checked_at' => time(),
				'detail'     => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code ) {
			$www_authenticate = wp_remote_retrieve_header( $response, 'www-authenticate' );
			if ( $www_authenticate ) {
				// The web server itself challenged for BASIC authentication before WordPress ran at all.
				// WordPress が動く前に、Web サーバー自身が BASIC 認証を要求した。
				return array(
					'status'     => 'server_basic_auth',
					'checked_at' => time(),
					'detail'     => (string) $www_authenticate,
				);
			}
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) || ! isset( $body['received'] ) ) {
			return array(
				'status'     => 'unknown',
				'checked_at' => time(),
				'detail'     => sprintf(
					/* translators: %d: HTTP status code of the diagnosis request's response */
					__( 'Unexpected response to the diagnosis request (status: %d).', 'etbs-account-guard' ),
					$code
				),
			);
		}

		return array(
			'status'     => ! empty( $body['received'] ) ? 'success' : 'not_received',
			'checked_at' => time(),
			'detail'     => '',
		);
	}

	/**
	 * Front-end endpoint the loopback request in run_diagnosis() calls, answering only whether a BASIC
	 * Authorization reached PHP for this request — never whether it is valid for any real user (the
	 * diagnosis's dummy credentials never match one). Responds only to a request carrying a valid, unexpired,
	 * one-time token, so it cannot be used to probe this site's headers from outside.
	 * run_diagnosis() のループバックリクエストが呼ぶフロント側のエンドポイント。このリクエストで BASIC の
	 * Authorization が PHP に届いたかだけを答える（実在のどのユーザーにとって正しいかは一切見ない。
	 * 診断のダミー資格情報はどのユーザーとも一致しない）。有効・未失効の使い捨てトークンを伴うリクエストにだけ
	 * 応答するので、外部からこのサイトのヘッダーを探る用途には使えない。
	 *
	 * @return void
	 */
	public static function maybe_respond_to_diagnosis_probe() {
		// No nonce: this is an intentionally unauthenticated endpoint (it must answer for a visitor with no
		// session at all, the same as a real BASIC-protected request would be). The one-time token below,
		// checked against its own transient, is what stands in for a nonce here.
		// nonce は無い：これは意図的に未認証のエンドポイント（実際の BASIC 保護対象へのリクエストと同じく、
		// セッションを一切持たない訪問者にも応答する必要がある）。下の使い捨てトークン（専用の transient と
		// 突き合わせる）が、ここでの nonce の代わりを果たす。
		if ( ! isset( $_GET[ self::DIAG_QUERY_VAR ], $_GET['token'] ) || '1' !== $_GET[ self::DIAG_QUERY_VAR ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See comment above.
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See comment above.
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			return;
		}

		$transient_key = self::DIAG_TOKEN_PREFIX . $token;
		if ( false === get_transient( $transient_key ) ) {
			return; // Missing, expired or already used: do not act like a diagnosis endpoint. / 無い・失効・使用済み：診断エンドポイントとして振る舞わない。
		}
		delete_transient( $transient_key ); // Single use. / 使い捨て。

		$received = null !== self::get_submitted_credentials();

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( array( 'received' => $received ) );
		exit;
	}
}
