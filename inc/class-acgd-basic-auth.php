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
	 * Name of the "Verify" button on the user edit screen. It is the button's own name/value pair, submitted
	 * with the rest of core's own <form id="your-profile"> to the profile screen itself, and picked up on
	 * admin_init by maybe_handle_verify_request().
	 *
	 * ★ Deliberately NOT "action". This used to be name="action" value="acgd_verify_basic" together with
	 * formaction="…/admin-post.php", but wp-admin/user-edit.php prints its own
	 * <input type="hidden" name="action" value="update" /> (line 970 of WordPress 7.1) AFTER the
	 * show_user_profile hook this section is rendered from (line 909). The request body therefore carried
	 * action=acgd_verify_basic first and action=update second, and PHP keeps the LAST value of a repeated
	 * key — so $_REQUEST['action'] was always "update", and admin-post.php, which has no admin_post_update
	 * handler, answered with wp_die( '', 400 ): an empty error page. The confirmation screen could not be
	 * reached from a real browser at all (issue #4, UI test finding; the curl reproduction that had passed
	 * happened to send the two fields in the opposite order). Putting the action in the formaction's query
	 * string does not help either: with PHP's default request_order=GP the POST body overwrites the query
	 * string in $_REQUEST (measured).
	 * ユーザー編集画面の「確認」ボタンの名前。ボタン自身の name/value の組であり、本体の
	 * <form id="your-profile"> の他の項目と一緒にプロフィール画面そのものへ送信され、admin_init の
	 * maybe_handle_verify_request() が受け取る。
	 *
	 * ★ 「action」にはしない（意図的）。以前は name="action" value="acgd_verify_basic" ＋
	 * formaction="…/admin-post.php" だったが、wp-admin/user-edit.php は自前の
	 * <input type="hidden" name="action" value="update" />（WordPress 7.1 の 970 行目）を、この区画を描画する
	 * show_user_profile フック（909 行目）より **後ろ** に出力する。そのため送信本文には
	 * action=acgd_verify_basic → action=update の順で2つ入り、PHP は同名キーを **後勝ち** で採るので
	 * $_REQUEST['action'] は常に "update" になっていた。admin-post.php には admin_post_update が無いため
	 * wp_die( '', 400 )（本文が空のエラー画面）になり、実ブラウザからは確認画面へ一度も到達できなかった
	 * （issue #4、UIテストで判明。通っていた curl の再現は、たまたま2つの順序が逆だっただけ）。
	 * formaction のクエリ文字列に action を入れる案も効かない：PHP の既定 request_order=GP では
	 * POST 本文が $_REQUEST のクエリ文字列を上書きする（実測）。
	 *
	 * @var string
	 */
	const VERIFY_REQUEST_FIELD = 'acgd_verify_basic';

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
	 * the confirmed ID, the password_hash() of the confirmed password (computed once, at confirmation time —
	 * see handle_verify()), and a one-time token (MEDIUM fix, PR #6 second review round) so find_verified_hash()
	 * can hand that same hash straight to save_fields() even when the password field comes back blank on the
	 * profile screen (MEDIUM-1 fix, PR #6 first review round): render_fields() never redisplays a password, so
	 * requiring it to be retyped before the hash could be looked up meant a blank field on the save click
	 * ("Update Profile" on one's own profile screen) right after a successful Verify silently kept the old
	 * password.
	 * The token exists to bind the hash to one specific rendering of the profile screen — the one printed
	 * immediately after this successful confirmation (see render_verify_token_field() in ACGD_User_Access) —
	 * rather than to "any save within VERIFY_TTL". Without it, a save unrelated to BASIC credentials (changing
	 * one's display name, say) submitted later within the TTL, from a profile screen loaded normally (no
	 * confirmation just happened), would still silently pick up and apply the confirmed password: save_fields()
	 * has no other way to tell "the form that is being submitted right now is the one Verify just sent the
	 * admin back to" from "some unrelated later save that merely happens to fall inside the same five minutes"
	 * (etbs-senior-wp audit, PR #6, non-blocking Medium; the user decided to close it before release since
	 * BASIC authentication has not shipped yet). The transient is looked up (find_verified_hash()) without being
	 * deleted, and is only actually invalidated (invalidate_verification()) once save_fields() is certain the
	 * save is going through — not merely because some other, unrelated save-time check failed first (etbs-
	 * senior-wp audit, same PR, non-blocking Low: forcing a fresh Verify after a save that failed only because,
	 * say, the chosen BASIC ID collided with someone else's was needless UX friction, not a security fix).
	 * VERIFY_ACTION の確認成功を記録する transient の接頭辞。管理者ごとに分ける。確認できた ID、確認できた
	 * パスワードの password_hash()（確認できた時点で一度だけ計算する。handle_verify() を参照）、そして
	 * ワンタイムトークン（MEDIUM の修正。PR #6 の2回目のレビュー）を持つ。これにより、プロフィール画面で
	 * パスワード欄が空のまま出し直されても（MEDIUM-1 の修正。PR #6 の1回目のレビュー：render_fields() は
	 * パスワードを一切出し直さないため、ハッシュを引くのに再入力を必須にすると、「確認」成功直後に空欄のまま
	 * 保存ボタン（本人のプロフィール画面では「プロフィールを更新」）を押した場合に古いパスワードが無言で
	 * 残ってしまっていた）、find_verified_hash() が同じハッシュをそのまま save_fields() へ渡せる。
	 * トークンの役割は、このハッシュを「確認の直後に出し直されたプロフィール画面（その1回の表示。
	 * ACGD_User_Access の render_verify_token_field() を参照）」に結び付けることであり、「VERIFY_TTL の間の
	 * どの保存でも使える」にしないためにある。トークンが無いと、BASIC の資格情報とは無関係な保存
	 * （例えば表示名の変更）を、確認とは関係なく通常どおり読み込んだプロフィール画面から TTL 内に送信しても、
	 * 確認済みのパスワードが無言で適用されてしまう：save_fields() には「今まさに送信されているフォームが、
	 * 確認の直後に送り返されたものそのもの」なのか「たまたま同じ5分に収まっただけの無関係な後の保存」なのかを
	 * 見分ける手段が他に無い（大の監査、PR #6、非ブロッカーの Medium。BASIC 認証はまだ公開前の機能のため、
	 * ユーザーの判断でリリース前に閉じることにした）。この transient は消費せずに参照するだけ
	 * （find_verified_hash()）にとどめ、save_fields() が実際に保存へ進むと確定した時点で初めて無効化する
	 * （invalidate_verification()）——他の無関係な保存時チェック（例えば選んだ BASIC ID が他人と衝突していた
	 * だけ）が先に失敗しただけでは消費しない（大の監査、同じ PR、非ブロッカーの Low：それだけの理由で
	 * 「確認」からやり直させるのはセキュリティ上の修正ではなく、単なる UX の手戻りだった）。
	 *
	 * @var string
	 */
	const VERIFIED_TRANSIENT_PREFIX = 'acgd_basic_verified_';

	/**
	 * Query and POST field name of the one-time token described in VERIFIED_TRANSIENT_PREFIX. Carried in the
	 * redirect_to query string from handle_verify() to the profile screen (render_verify_token_field() prints
	 * it as a hidden field from there), and read back from $_POST by ACGD_User_Access::save_fields().
	 * VERIFIED_TRANSIENT_PREFIX で説明したワンタイムトークンの、クエリおよび POST のフィールド名。
	 * handle_verify() からプロフィール画面へのリダイレクトのクエリ文字列で運び
	 * （そこで render_verify_token_field() が hidden フィールドとして出力する）、
	 * ACGD_User_Access::save_fields() が $_POST から読み戻す。
	 *
	 * @var string
	 */
	const VERIFY_TOKEN_FIELD = 'acgd_basic_verify_token';

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
	 * The $_SERVER keys a BASIC credential can arrive in, and therefore every key that has to be removed
	 * again (docs/spec.md 5.3 ★★★). One list, read by both clear_submitted_credentials_from_server() and
	 * has_credentials_in_server(), because those two must never disagree: a key present in what "clear"
	 * removes but missing from what "has" looks for — or the reverse — reopens the very window this plugin
	 * closes (code review, Low).
	 * ★ The pair PHP_AUTH_USER / PHP_AUTH_PW is also read individually in
	 * parse_submitted_credentials(), which cannot loop over them: it reads one as the ID and the other as
	 * the password. A new key added to the parsing side must be added here as well, or "has" will answer
	 * false, the priority 15 callback will stop early, and that key's credentials will stay in $_SERVER.
	 * BASIC の資格情報が届きうる $_SERVER のキー——つまり、消し直さなければならないキーの全体
	 * （docs/spec.md 5.3 ★★★）。clear_submitted_credentials_from_server() と
	 * has_credentials_in_server() の両方がこの1つの一覧を読む。両者が食い違ってはならないため：
	 * 「消す」側にあって「あるか」側に無いキー（あるいはその逆）は、このプラグインが閉じている窓を
	 * そのまま開け直してしまう（コードレビュー・Low）。
	 * ★ PHP_AUTH_USER / PHP_AUTH_PW の組は parse_submitted_credentials() でも個別に読んでいる。
	 * あちらはこの2つをループで回せない：片方を ID、もう片方をパスワードとして読むため。解析側にキーを
	 * 足したときは、必ずこちらにも足すこと。さもないと「あるか」が false を返して優先度15のコールバックが
	 * 早期に止まり、そのキーの資格情報が $_SERVER に残る。
	 *
	 * @var string[]
	 */
	const CREDENTIAL_SERVER_KEYS = array( 'PHP_AUTH_USER', 'PHP_AUTH_PW', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' );

	/**
	 * The subset of CREDENTIAL_SERVER_KEYS that carries a whole "Authorization: Basic <base64>" header, to be
	 * decoded by hand when PHP_AUTH_* is not populated (docs/spec.md 5.3: CGI/FastCGI). Kept as its own list
	 * so parse_submitted_credentials() loops over exactly these and nothing else.
	 * CREDENTIAL_SERVER_KEYS のうち、「Authorization: Basic <base64>」のヘッダーそのものを運ぶキー。
	 * PHP_AUTH_* が埋まらない環境（docs/spec.md 5.3：CGI/FastCGI）で自前で復号する対象。
	 * parse_submitted_credentials() がまさにこれだけを回せるよう、別の一覧として持つ。
	 *
	 * @var string[]
	 */
	const AUTHORIZATION_HEADER_KEYS = array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' );

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
	 * Whether maybe_strip_confirmed_header() has already consulted this request's confirmation. Its own
	 * re-entry guard, the counterpart of $matched_user's role for the priority 1 callback: determine_current_user
	 * fires again on every re-resolution of the current user, and the answer cannot change within a request.
	 * maybe_strip_confirmed_header() が、このリクエストの確認を既に参照したかどうか。あちらの再入ガードで、
	 * 優先度1のコールバックにとっての $matched_user と同じ役割を果たす：determine_current_user は現在の
	 * ユーザーが再解決されるたびに再発火するが、1リクエストの中で答えは変わりようがない。
	 *
	 * @var bool
	 */
	private static $confirmed_header_checked = false;

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
		/*
		 * Priority 15: the same job for credentials that are confirmed but not saved yet — the window between
		 * clicking "Verify" and clicking "Update Profile", during which the browser keeps sending
		 * Authorization: Basic to this origin while nothing in the database matches it yet, so
		 * maybe_strip_own_header() above finds nothing and leaves the header in place. What that left behind
		 * was core's own red notice on the very screen the admin lands back on — "Your website appears to use
		 * Basic Authentication, which is not currently compatible with Application Passwords", printed by
		 * wp-admin/user-edit.php from wp_is_site_protected_by_basic_auth(), which is nothing but
		 * ! empty( $_SERVER['PHP_AUTH_USER'] ) || ! empty( $_SERVER['PHP_AUTH_PW'] )
		 * (wp-includes/load.php) — sitting next to the green "Verified" one (UI test finding, issue #4).
		 * 15 and not 1: this needs to know WHOSE confirmation to look for, and the confirmation is held per
		 * admin (VERIFIED_TRANSIENT_PREFIX). Core resolves the wp-admin auth cookie on this very filter at
		 * priority 10, so by 15 the id has already been handed to us as $input on every wp-admin request —
		 * and 15 is still before core's application password callback at 20, which is the one thing this has
		 * to get ahead of (docs/spec.md 5.3 ★★★, and the known trap that leaving the header in place makes
		 * REST answer 401).
		 * 優先度15：確認は済んだがまだ保存していない資格情報について、同じ仕事をする。「確認」を押してから
		 * 「プロフィールを更新」を押すまでの間、ブラウザはこのオリジンへ Authorization: Basic を送り続ける
		 * のに、データベースにはまだ一致するものが無い——だから上の maybe_strip_own_header() は何も見つけず、
		 * ヘッダーを残したままにしていた。その結果、管理者が戻ってくるまさにその画面に本体の赤い通知
		 * 「サイトでは Basic 認証が使われているようですが、現在、アプリケーションパスワードとは互換性が
		 * ありません」が、緑の「確認できました」の隣に並んでいた（UIテストでの指摘、issue #4）。この通知は
		 * wp-admin/user-edit.php が wp_is_site_protected_by_basic_auth() を見て出しており、その中身は
		 * ! empty( $_SERVER['PHP_AUTH_USER'] ) || ! empty( $_SERVER['PHP_AUTH_PW'] ) だけ
		 * （wp-includes/load.php）。
		 * 1 ではなく 15 なのは、「誰の確認を探すのか」を知る必要があるため（確認は管理者ごとに
		 * VERIFIED_TRANSIENT_PREFIX で持っている）。本体はまさにこのフィルタの優先度10で wp-admin の
		 * auth cookie を解決するので、15 の時点では管理画面のリクエストなら ID が $input として渡って
		 * きている。しかも 15 は本体のアプリケーションパスワードのコールバック（20）よりは前であり、
		 * 先回りしなければならないのはそれだけ（docs/spec.md 5.3 ★★★、および「ヘッダーを残すと REST が
		 * 401 を返す」という既知の罠）。
		 */
		add_filter( 'determine_current_user', array( __CLASS__, 'maybe_strip_confirmed_header' ), 15 );

		// The confirmation screen and the "Verify" round trip, both at the wp-login.php level (docs/spec.md 5.3).
		// 確認画面と「確認」ボタンの往復。どちらも wp-login.php の階層（docs/spec.md 5.3）。
		add_action( 'login_form_' . self::CHALLENGE_ACTION, array( __CLASS__, 'handle_challenge' ) );
		add_action( 'login_form_' . self::VERIFY_ACTION, array( __CLASS__, 'handle_verify' ) );
		/*
		 * admin_init, not admin_post_*: the "Verify" button submits core's own profile form to the profile
		 * screen itself, and carries no "action" field of its own (see VERIFY_REQUEST_FIELD for why it cannot).
		 * The timing works out because wp-admin/user-edit.php's very first statement is
		 * require_once __DIR__ . '/admin.php' (line 10 of WordPress 7.1), and wp-admin/admin.php fires
		 * admin_init at line 180 — long before user-edit.php reaches `switch ( $action ) { case 'update':`
		 * (line 131), which is where core's own save (check_admin_referer() then edit_user()) happens. So this
		 * handler gets the request first and redirects away before any profile field is written.
		 * One step after ACGD_Access_Restriction::check_access_on_request(), whose own priority is read from
		 * ACGD_Access_Restriction::ADMIN_INIT_PRIORITY rather than assumed here: enforcing access on this
		 * request still comes first, exactly as on every other admin screen.
		 * admin_post_* ではなく admin_init に掛ける：「確認」ボタンは本体自身のプロフィール用フォームを
		 * プロフィール画面そのものへ送信し、自前の「action」フィールドを持たない（持てない理由は
		 * VERIFY_REQUEST_FIELD を参照）。タイミングが成り立つのは、wp-admin/user-edit.php の最初の文が
		 * require_once __DIR__ . '/admin.php'（WordPress 7.1 の 10 行目）であり、wp-admin/admin.php は
		 * 180 行目で admin_init を発火するため——本体の保存（check_admin_referer() のあと edit_user()）を行う
		 * `switch ( $action ) { case 'update':`（131 行目）に到達するよりはるかに前になる。よってこのハンドラが
		 * 先にリクエストを受け取り、プロフィールの項目が1つも書き込まれないうちにリダイレクトで離脱できる。
		 * ACGD_Access_Restriction::check_access_on_request() の1つ後ろ。その優先度はここで決め打ちにせず
		 * ACGD_Access_Restriction::ADMIN_INIT_PRIORITY から読む：このリクエストに対するアクセス制限の判定が
		 * 先に効く点は、他の管理画面と全く同じにする。
		 */
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_verify_request' ), ACGD_Access_Restriction::ADMIN_INIT_PRIORITY + 1 );

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

		foreach ( self::AUTHORIZATION_HEADER_KEYS as $key ) {
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

			$decoded = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Not obfuscation: this decodes the standard base64 payload of an incoming BASIC authentication header (RFC 7617), the same encoding every HTTP client and server uses for it. / 難読化ではない：受信した BASIC 認証ヘッダー（RFC 7617）の標準的な base64 部分を復号しているだけで、あらゆる HTTP クライアント・サーバーがこの符号化を使う。Strict mode: malformed base64 becomes false. / 厳格モード：不正な base64 は false になる。
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
		foreach ( self::CREDENTIAL_SERVER_KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
	}

	/**
	 * Tells whether any of those same keys is still present in $_SERVER — that is, whether there is still
	 * anything for clear_submitted_credentials_from_server() to remove. Deliberately reads $_SERVER on every
	 * call and caches nothing: this is the state of the request right now, which is a different question from
	 * "what did this request originally submit" (get_submitted_credentials(), which caches and keeps
	 * answering after the header has been cleared). Reads the same CREDENTIAL_SERVER_KEYS its counterpart
	 * removes, so the two can never disagree about which keys count (code review, Low: they used to repeat
	 * the list, and a key added to one but not the other would let this return false, stop the callback
	 * early, and leave that key's credentials sitting in $_SERVER).
	 * 同じキーのどれかが $_SERVER にまだ残っているか——つまり
	 * clear_submitted_credentials_from_server() に消す対象がまだ残っているか——を返す。毎回 $_SERVER を読み、
	 * 何もキャッシュしないのは意図的：これは「今このリクエストがどういう状態か」であって、
	 * 「このリクエストが元々何を送ってきたか」（get_submitted_credentials()。あちらはキャッシュし、
	 * ヘッダーを消した後も答え続ける）とは別の問いだから。見るのは、対になるメソッドが消すのと同じ
	 * CREDENTIAL_SERVER_KEYS。そうすることで「どのキーを数えるか」で両者が食い違うことが起こり得なくなる
	 * （コードレビュー・Low：以前は一覧をそれぞれ書き並べていた。片方にだけキーを足すと、こちらが false を
	 * 返してコールバックを早期に止め、そのキーの資格情報が $_SERVER に残ってしまう）。
	 *
	 * @return bool True while any BASIC credential key remains in $_SERVER. / BASIC の資格情報のキーが $_SERVER に残っていれば true。
	 */
	private static function has_credentials_in_server() {
		foreach ( self::CREDENTIAL_SERVER_KEYS as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				return true;
			}
		}

		return false;
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

			$matched            = ACGD_Access_Restriction::find_basic_user_by_credentials( $submitted['username'], $submitted['password'] );
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
	 * Same as maybe_strip_own_header(), for the one case it cannot cover: credentials this plugin has just
	 * confirmed through the "Verify" round trip but which are not saved anywhere yet (see init() for the
	 * symptom this removes and why this runs at priority 15). Never changes $input.
	 * maybe_strip_own_header() と同じことを、あちらでは扱えない1つのケースについて行う：「確認」の往復で
	 * このプラグイン自身が確認したばかりで、まだどこにも保存されていない資格情報（この修正が消している症状と、
	 * 優先度15で動かす理由は init() を参照）。$input は一切変えない。
	 *
	 * ★ Recognizing them is NOT the same as accepting them, and this method deliberately does not touch
	 * $matched_user: a confirmation is not a saved credential, so nothing here may let this request satisfy
	 * BASIC authentication for anybody (is_request_authenticated_for() must keep answering from saved
	 * credentials alone — docs/spec.md 5.3). The only effect is the one side effect: clearing $_SERVER.
	 * ★ 「認識する」ことは「受け入れる」ことではない。このメソッドは意図的に $matched_user に触らない：
	 * 確認済みであることは保存済みであることではないので、ここで誰かについて BASIC 認証を満たしたことに
	 * なってはならない（is_request_authenticated_for() は保存済みの資格情報だけで答え続けること
	 * ——docs/spec.md 5.3）。効果は副作用ひとつ、$_SERVER を消すことだけ。
	 *
	 * ★ It also stays within "never touch credentials that are not this person's own": the confirmation it
	 * compares against is the one held for THIS request's own admin, and the comparison is the confirmed
	 * id plus password_verify() against the confirmed hash. Someone else's credentials, and any unrelated
	 * Authorization header, match nothing here and are left exactly as they arrived.
	 * ★ 「自分のもの以外には手を出さない」も崩していない：突き合わせる相手はこのリクエスト自身の管理者に
	 * ついて保持されている確認であり、比較は確認済みの ID と、確認済みハッシュに対する password_verify()。
	 * 他人の資格情報や無関係な Authorization ヘッダーはここでは何とも一致せず、届いたそのままで残る。
	 *
	 * @param int|false $input Result of the earlier determine_current_user callbacks; on a wp-admin request
	 *                          core has already resolved it to the current user's ID by this priority.
	 *                          / それまでのコールバックの結果。管理画面のリクエストでは、この優先度の時点で
	 *                          本体が既に現在のユーザー ID に解決している。
	 * @return int|false The result, unchanged. / 結果（変更しない）。
	 */
	public static function maybe_strip_confirmed_header( $input ) {
		try {
			/*
			 * ★ Read $_SERVER itself, not get_submitted_credentials(): the question here is "is there still
			 * anything in $_SERVER to remove", and that method deliberately answers a different one. It
			 * parses once per request and caches, so it keeps answering "yes, these credentials" after the
			 * header has already been cleared — which is exactly what a later save-time comparison needs,
			 * and exactly the wrong thing for a gate whose only purpose is to do nothing when there is
			 * nothing left to do. Using it here meant that on a site with saved BASIC credentials (the
			 * steady state, where priority 1 clears $_SERVER on every request) this went on to run a
			 * get_transient() on every single admin request, and a password_verify() on every one of them
			 * for as long as a confirmation was alive (code review, Low).
			 * ★ ここで読むのは $_SERVER 自身であって get_submitted_credentials() ではない：ここでの問いは
			 * 「$_SERVER にまだ消すものが残っているか」であり、あのメソッドは意図的に別の問いに答える。
			 * リクエストにつき1回解析してキャッシュするので、ヘッダーを消した後も「ある。この資格情報だ」と
			 * 答え続ける——それは後で保存時に突き合わせるために必要な性質であり、「もう何も無いなら何もしない」
			 * ためだけの門にとっては、まさに間違った性質。ここであれを使うと、BASIC 認証を保存済みのサイト
			 * （＝優先度1が毎リクエストで $_SERVER を消す定常状態）で、管理画面の全リクエストで
			 * get_transient() が走り、確認が生きている間は password_verify() まで毎回走っていた
			 * （コードレビュー・Low）。
			 */
			if ( ! self::has_credentials_in_server() ) {
				return $input;
			}

			/*
			 * Re-entry guard, the counterpart of priority 1's own ( false !== $matched_user ) check.
			 * determine_current_user fires again whenever something re-resolves the current user (any
			 * wp_set_current_user() call does), and as far as core is concerned the answer here does not
			 * change within one request: the id $input carries at this priority comes from core's auth
			 * cookie, which is the same on every pass. (Another plugin filtering in a different id before
			 * priority 15 could change it — this deliberately answers from the first pass either way, since
			 * the confirmation it looks up belongs to whoever core says is signed in.) So consult the
			 * confirmation at most once (code review, Low).
			 * 再入ガード。優先度1が持っている ( false !== $matched_user ) の判定に相当するもの。
			 * determine_current_user は、現在のユーザーを再解決する何か（wp_set_current_user() の呼び出しなど）
			 * があるたびに再発火するが、本体だけを見ればここでの答えは1リクエストの中で変わらない：この
			 * 優先度で $input が運んでくる ID は本体の auth cookie 由来で、何度発火しても同じ。（優先度15より
			 * 前で別のプラグインが違う ID をフィルタで返せば変わり得る。その場合もここは最初の1回の答えを
			 * 採る——引く確認は「本体がログイン中だと言っている人」のものだから。）よって確認の参照は多くても
			 * 1回に留める（コードレビュー・Low）。
			 */
			if ( self::$confirmed_header_checked ) {
				return $input;
			}

			if ( ACGD_Access_Restriction::is_disabled() ) {
				return $input;
			}

			// What arrived, as originally submitted. / 届いた内容（元々送信されたまま）。
			$submitted = self::get_submitted_credentials();
			if ( null === $submitted ) {
				return $input;
			}

			// Whose confirmation to look for. $input carries it on wp-admin requests (see init()); anywhere
			// else nobody is resolved yet at this priority, and there is then no confirmation to consult.
			// 誰の確認を探すか。管理画面のリクエストでは $input が持っている（init() を参照）。それ以外では
			// この優先度の時点で誰も解決されておらず、そのとき参照すべき確認も無い。
			$admin_id = is_numeric( $input ) ? (int) $input : 0;
			if ( $admin_id <= 0 ) {
				return $input;
			}

			// From here on the answer is settled for this request, whatever it turns out to be.
			// ここから先は、結果がどうであれこのリクエストについては答えが確定する。
			self::$confirmed_header_checked = true;

			$confirmed = get_transient( self::VERIFIED_TRANSIENT_PREFIX . $admin_id );
			if ( ! is_array( $confirmed ) || ! isset( $confirmed['id'], $confirmed['hash'] ) ) {
				return $input;
			}

			if ( ! hash_equals( (string) $confirmed['id'], $submitted['username'] ) ) {
				return $input;
			}
			if ( ! password_verify( $submitted['password'], (string) $confirmed['hash'] ) ) {
				return $input;
			}

			self::clear_submitted_credentials_from_server();
		} catch ( Throwable $e ) {
			/*
			 * Fail open, and — unlike the other Throwable catches in this class — do NOT call
			 * ACGD_Access_Restriction::record_fault() here.
			 * ★ record_fault() is not a log, it is the kill switch: is_disabled() is
			 * is_switch_disabled() || has_fault(), so one exception recorded from here would stop IP
			 * restriction and BASIC authentication for the whole site, permanently, until somebody saves
			 * the Access Restriction tab again and clear_fault() runs. The other four call sites are all on
			 * the deciding path, where docs/spec.md 5.5's "stop and let through" is the whole point: if the
			 * code that decides whether to let someone in cannot run, the honest thing is to stop deciding
			 * and say so loudly. This callback decides nothing — priority 15 only strips a header core would
			 * otherwise pick up.
			 * What a failure here actually costs (see init() for the mechanism and docs/spec.md 5.3 ★★★):
			 * core's red "Basic Authentication ... not compatible with Application Passwords" notice sits
			 * next to the green one on the profile screen, and — because the header then survives into
			 * core's own wp_validate_application_password() at priority 20, whose failure answers through
			 * rest_authentication_errors — that admin's REST requests come back 401 for as long as the
			 * confirmation window lasts, which breaks their block editor between "Verify" and "Update
			 * Profile". Real, and bounded to one admin for a few minutes. Recording it would instead cost
			 * IP restriction and BASIC authentication for the whole site until somebody clears the fault by
			 * hand. That trade is not worth making (code review, Medium).
			 * A fault here must still never break core's own authentication for this request, which is what
			 * the catch guarantees. What it must not do is vanish without trace: a future TypeError or an
			 * object-cache failure would otherwise switch off the 5.3 ★★★ protection in silence. So it is
			 * written to the debug log when WP_DEBUG is on — class and message only, never the credentials.
			 * A stopping-free fault log for production is its own piece of work, not something to bolt on
			 * here (docs/spec.md 5.5).
			 * 止めて通す。そして——このクラスの他の Throwable の catch と違い——ここでは
			 * ACGD_Access_Restriction::record_fault() を呼ばない。
			 * ★ record_fault() はログではなく機能停止スイッチである：is_disabled() は
			 * is_switch_disabled() || has_fault() なので、ここから例外を1回記録するだけで、IP 制限と
			 * BASIC 認証がサイト全体で止まり、しかも誰かがアクセス制限タブを保存し直して clear_fault() が
			 * 走るまで永続する。他の4箇所はすべて判定の経路にあり、そこでは docs/spec.md 5.5 の
			 * 「止めて通す」がまさに要点になる：入れてよいかを決めるコードが動けないなら、決めるのをやめて
			 * 大きな声で知らせるのが誠実だから。このコールバックは何も決めていない——優先度15は、本体が
			 * 拾ってしまうヘッダーを剥がすだけ。
			 * ここでの失敗が実際に招く代償（仕組みは init()、docs/spec.md 5.3 ★★★ を参照）：プロフィール
			 * 画面で本体の赤い通知「サイトでは Basic 認証が使われているようですが、現在、アプリケーション
			 * パスワードとは互換性がありません」が緑の通知と並ぶ。そして——ヘッダーが残ったまま優先度20の
			 * 本体の wp_validate_application_password() に届き、その失敗が rest_authentication_errors 経由で
			 * 応答するため——確認の窓が続く間、その管理者の REST が 401 を返し、「確認」から「プロフィールを
			 * 更新」までの間ブロックエディターが壊れる。実害はあるが、1人の管理者・数分に限られる。
			 * 一方、記録してしまえば代償は、誰かが手で故障を解除するまでサイト全体の IP 制限と BASIC 認証に
			 * なる。釣り合わない取引はしない（コードレビュー・Medium）。
			 * ここでの故障がこのリクエストの本体側の認証を壊すことは絶対に無い、という点は catch が引き続き
			 * 保証する。ただし痕跡も無く消えてはならない：そのままでは、将来の TypeError やオブジェクト
			 * キャッシュの障害で 5.3 ★★★ の保護が黙って効かなくなる。そこで WP_DEBUG が真のときだけ
			 * デバッグログに書く——クラス名とメッセージだけで、資格情報は決して載せない。本番でも止めずに
			 * 記録する仕組みは、それ自体が別の作業であって、ここに後付けするものではない
			 * （docs/spec.md 5.5）。
			 */
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic for a protection that otherwise fails silently (docs/spec.md 5.5); logs the exception class and message only, never the submitted credentials. / 黙って効かなくなりうる保護のための、デバッグ時限定の診断（docs/spec.md 5.5）。載せるのは例外のクラス名とメッセージだけで、送信された資格情報は決して載せない。
				error_log( 'ETBS Account Guard: maybe_strip_confirmed_header() failed: ' . get_class( $e ) . ': ' . $e->getMessage() );
			}
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

		if ( self::request_satisfies( $user ) ) {
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
		$current  = ( is_ssl() ? 'https://' : 'http://' );
		$current .= isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$current .= isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		wp_safe_redirect( self::challenge_url( self::validated_redirect_to( $current ) ) );
		exit;
	}

	/*-------------------------------------------*/
	/* The user edit screen's "Verify" round trip (docs/spec.md 5.3) / ユーザー編集画面の「確認」の往復（docs/spec.md 5.3）
	/*-------------------------------------------*/

	/**
	 * Handles the user edit screen's "Verify" button, on admin_init, before core's own profile save can run
	 * (see init() for the timing and VERIFY_REQUEST_FIELD for why this is no longer an admin-post action).
	 * The button is a secondary submit button inside core's own <form id="your-profile">, so this request
	 * carries that whole form — mode, added IPs, and the BASIC id/password — as well as core's "action=update"
	 * hidden field. Stashes the submitted form for redisplay and the (id, password) pair to confirm, then
	 * sends the browser to VERIFY_ACTION.
	 *
	 * ★ No path out of this method leaves a request that carries VERIFY_REQUEST_FIELD able to save: every one
	 * of them ends the request outright — wp_die() with 403, or a redirect followed by exit — so
	 * wp-admin/user-edit.php never reaches its own `case 'update':` — clicking "Verify" must not also save
	 * the profile, not even when a guard here declines to handle the request. The one exception is the screen
	 * guard at the very top, which returns without doing anything at all: it fires on requests that are not
	 * this button (a POST to some other admin entry point), and on those there is nothing to keep from
	 * saving. Earlier revisions had a third shape, a guard that unset core's own action field and then
	 * returned so the screen would still render; nothing does that any more (code audit, Medium: the helper
	 * it used had lost its last caller while this docblock still presented it as a live path).
	 *
	 * Only ever meaningful for an admin verifying their own account (docs/spec.md 5.3); a mismatched target is
	 * bounced back without starting a confirmation.
	 * ユーザー編集画面の「確認」ボタンを、本体自身のプロフィール保存が動くより前の admin_init で処理する
	 * （タイミングは init()、admin-post アクションをやめた理由は VERIFY_REQUEST_FIELD を参照）。
	 * このボタンは本体自身の <form id="your-profile"> の中にある副次的な送信ボタンなので、このリクエストには
	 * そのフォーム全体——モード・追加した IP・BASIC の ID/パスワード——と、本体の「action=update」の隠し
	 * フィールドが一緒に乗っている。送信されたフォームを出し直し用に、(id, password) の組を確認用に
	 * それぞれ保存してから、ブラウザを VERIFY_ACTION へ送る。
	 *
	 * ★ VERIFY_REQUEST_FIELD を載せたリクエストを「保存できる状態」のまま抜ける経路は1つも無い：
	 * すべての経路がリクエストをその場で終わらせる——403 の wp_die()、またはリダイレクトして exit。
	 * そうすることで wp-admin/user-edit.php は自身の `case 'update':` に到達しない——「確認」を押しただけで
	 * プロフィールまで保存されてはならない。ここのガードが処理を降りるときも同じ。例外は先頭の画面ガードで、
	 * そこは何もせずに return する：発火しているのがこのボタンではないリクエスト（別の管理画面の入口への
	 * POST）だからで、そこには「保存させてはならないもの」が無い。以前の版には3つ目の形——本体の action の
	 * フィールドを外してから return し、画面はそのまま描画させるガード——があったが、今はどこにも無い
	 * （コード監査・Medium：そのヘルパーは最後の呼び出し元を失っていたのに、この docblock はまだ現役の
	 * 経路として説明していた）。
	 *
	 * 意味を持つのは管理者が自分自身を確認するときだけ（docs/spec.md 5.3）。対象が食い違っていれば、
	 * 確認を始めずに送り返す。
	 *
	 * @return void
	 */
	public static function maybe_handle_verify_request() {
		// Presence of the button's own name/value pair is what marks this request; every check below runs
		// before anything is read out of the form or written anywhere.
		// このボタン自身の name/value の組があることが目印。以下のチェックはすべて、フォームの内容を読んだり
		// どこかへ書き込んだりする前に行う。
		if ( ! isset( $_POST[ self::VERIFY_REQUEST_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; the nonces are verified immediately below, before any value is read. / 存在確認のみ。値を読む前に、すぐ下で nonce を検証する。
			return;
		}

		/*
		 * ★ The two profile screens are the only place this button exists, and this guard is what keeps this
		 * handler off every other admin entry point. admin_init does not fire on a screen — it fires on every
		 * request that loads wp-admin/admin.php, and that includes admin-post.php and admin-ajax.php. Both of
		 * those read $_REQUEST['action'] to pick their own destination *after* admin_init has run, so a POST
		 * carrying VERIFY_REQUEST_FIELD aimed at either of them used to reach the guards below and take the
		 * request away from whatever was supposed to handle it: the capability guard of the day unset the
		 * action field the request is dispatched on (so an AJAX call would lose its destination, and PHP 8
		 * turns core's own read of the missing value into a warning), and the nonce guards answer with a
		 * redirect and exit, which turns an AJAX response into a 302 (PR #6, code review round, Medium).
		 * $GLOBALS['pagenow'] is the right thing to test here, and it is already settled at this point:
		 * wp-settings.php requires wp-includes/vars.php during bootstrap (line 561 in WP 7.1), long before
		 * wp-admin/admin.php fires admin_init (line 180 there), and vars.php derives $pagenow from
		 * $_SERVER['PHP_SELF'] for every is_admin() request — so it reads 'admin-ajax.php' and
		 * 'admin-post.php' on exactly the two entry points this has to keep out (both define WP_ADMIN before
		 * loading WordPress, so is_admin() is true there and $pagenow is set for them too). Verified against
		 * the running WordPress 7.1 on the test site, not assumed.
		 * get_current_screen() would be the tidier-looking test and is not usable: the screen object is not
		 * built until wp-admin/admin.php has already fired admin_init.
		 * ★ このボタンが存在する画面はプロフィールの2画面だけであり、このハンドラを他のすべての管理画面の
		 * 入口から遠ざけているのがこのガード。admin_init は「画面」で発火するのではなく、
		 * wp-admin/admin.php を読み込むあらゆるリクエストで発火する——admin-post.php と admin-ajax.php を
		 * 含めて。どちらも自分の行き先を決めるために $_REQUEST['action'] を読むのが admin_init の**後**なので、
		 * VERIFY_REQUEST_FIELD を載せた POST をそこへ撃たれると、以前は下のガードまで到達して、本来の
		 * 処理先からリクエストを奪っていた：当時の権限ガードはディスパッチに使われる action の
		 * フィールドを削っていたため AJAX は行き先を失い（PHP 8 では本体側がその欠けた値を読んで Warning）、
		 * nonce のガードはリダイレクトして exit するため AJAX の応答が 302 に化ける
		 * （PR #6・コードレビュー回・Medium）。
		 * ここで見るべきは $GLOBALS['pagenow'] であり、この時点で既に確定している：wp-settings.php が
		 * 起動処理の中で wp-includes/vars.php を読み（WP 7.1 では 561 行目）、wp-admin/admin.php が
		 * admin_init を発火する（同 180 行目）のはそれよりずっと後。vars.php は is_admin() のリクエスト
		 * すべてについて $_SERVER['PHP_SELF'] から $pagenow を決めるので、まさに締め出したい2つの入口では
		 * 'admin-ajax.php' / 'admin-post.php' になる（どちらも WordPress を読み込む前に WP_ADMIN を定義
		 * するため is_admin() は真で、$pagenow も設定される）。検証サイトで動いている WordPress 7.1 の
		 * 実ファイルで確かめた（推測ではない）。
		 * get_current_screen() は見た目には綺麗だが使えない：画面オブジェクトが作られるのは、
		 * wp-admin/admin.php が admin_init を発火した後だから。
		 */
		$pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		if ( ! in_array( $pagenow, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}

		/*
		 * None of the guards below may simply return: a request that carries VERIFY_REQUEST_FIELD also
		 * carries core's own action=update hidden field, so a bare return hands it straight on to
		 * wp-admin/user-edit.php's `case 'update':` — and if core's own check_admin_referer() there happens
		 * to pass, the profile is saved. Clicking "Verify" would then silently have become a save (LOW,
		 * security-adjacent, PR #6 third review round). It is not enough that the two nonces this method
		 * checks normally expire together with core's: they are separate fields, this method checks core's
		 * against $_POST['acgd_user_id'] while core checks it against its own $_REQUEST['user_id'], and one
		 * of them can be removed or replaced on its own by editing the page's DOM (confirmed by the UI test
		 * as the one path that really did save).
		 * So no guard below returns; each one ends the request, in one of two ways:
		 * - The capability guard stops it outright with wp_die() and 403. It used to unset core's own action
		 *   field and return, which did keep the request from saving but let the screen render as if nothing
		 *   had happened: a submission was thrown away in silence, with no notice anywhere (the notice,
		 *   ACGD_User_Access::render_verify_notice(), is printed by a section that itself requires
		 *   manage_options, so nothing this person could see would ever have come of it — which is exactly
		 *   why saying so out loud is the honest answer; PR #6, code review round, Low). Now that the screen
		 *   guard above keeps admin-ajax.php and admin-post.php out entirely, the only way to arrive here is
		 *   a real profile screen, where a 403 page is a sensible thing to land on.
		 * - The target and nonce guards redirect to the screen the form came from and exit
		 *   (decline_verify_request(), with the notice key that says which of the two it was), so the person
		 *   is told why nothing happened. Leaving the screen open too long is the case actually reached in
		 *   practice, and this keeps the ★ invariant in this method's docblock intact.
		 * 以下のガードはどれも単に return してはならない：VERIFY_REQUEST_FIELD を載せたリクエストは本体自身の
		 * action=update の隠しフィールドも一緒に載せているため、素の return ではそのまま
		 * wp-admin/user-edit.php の `case 'update':` に渡ってしまい、そこで本体の check_admin_referer() が
		 * たまたま通ればプロフィールが保存される。「確認」を押したつもりが保存になっていた、という事態になる
		 * （LOW・セキュリティ隣接、PR #6 の3回目のレビュー）。このメソッドが確かめる2つの nonce が通常は
		 * 本体のものと同時に期限切れになる、というだけでは足りない：別々のフィールドであり、しかも本体の
		 * nonce をこのメソッドは $_POST['acgd_user_id'] に対して、本体は自身の $_REQUEST['user_id'] に対して
		 * 検証する。さらにページの DOM を書き換えれば片方だけを消す・差し替えることもできる（UIテストで、
		 * 実際に保存まで進む唯一の経路として確認済み）。
		 * そこで以下のガードはどれも return せず、それぞれリクエストを終わらせる。方法は2通り：
		 * - 権限のガードは、wp_die() と 403 でリクエストをその場で止める。以前は本体の action のフィールドを
		 *   外して return していた。それでも保存はされないが、画面は何事も無かったかのように表示されるため、
		 *   送信が通知も無く黙って捨てられていた（通知を出す ACGD_User_Access::render_verify_notice() は
		 *   manage_options を要求する区画自身が出しているので、この人には元々何も見えない——だからこそ
		 *   はっきり断る方が誠実である。PR #6・コードレビュー回・Low）。上の画面ガードで
		 *   admin-ajax.php・admin-post.php を完全に締め出した今、ここに来られるのは本物のプロフィール画面
		 *   だけなので、403 のページに着地するのは筋が通る。
		 * - 対象と nonce のガードは、フォームが来た画面へリダイレクトして exit する
		 *   （decline_verify_request()。どちらのガードだったかを表す通知キーを渡す）。これで何も起きなかった
		 *   理由が本人に伝わる。実運用で実際に到達するのは「画面を開いたまま放置した」ケースであり、
		 *   このメソッドの docblock の ★ もこれで保たれる。
		 */
		if ( ! current_user_can( 'manage_options' ) ) {
			// wp_die() ends the request, so core's `case 'update':` is never reached and nothing can be
			// saved on the way out — unsetting core's action field first would add nothing. The wording and
			// the call itself are the same ones the other two capability guards in this class already use,
			// so no new string enters languages/.
			// wp_die() はリクエストを終わらせるので、本体の `case 'update':` には到達せず、降り際に何かが
			// 保存されることもない。先に本体の action のフィールドを外しても足しにならない。文言と呼び方は、
			// このクラスの他の2つの権限ガードが既に使っているものと同じにしてあるので、languages/ に
			// 新しい文言は増えない。
			wp_die( esc_html__( 'You do not have permission to do this.', 'etbs-account-guard' ), '', array( 'response' => 403 ) );
		}

		$admin_id  = get_current_user_id();
		$target_id = isset( $_POST['acgd_user_id'] ) ? (int) $_POST['acgd_user_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately below; the value is needed to build the nonce action itself. / 直後に検証する。nonce のアクション文字列を組み立てるのにこの値が要るため先に読む。

		/*
		 * acgd_user_id is a field of the form, so whoever submits chooses it — on its own it names nobody in
		 * particular. Core has its own user_id for the same person, and core is the one that validates its
		 * nonce against that: wp-admin/user-edit.php prints it as a hidden field of this very form (line 971
		 * in WP 7.1), which means it rides along on BOTH screens, because wp-admin/profile.php only defines
		 * IS_PROFILE_PAGE and requires user-edit.php — on profile.php it holds the current user's own ID
		 * (confirmed by reading the rendered DOM of both screens, not assumed from the query string, where
		 * only user-edit.php has it). So the two must agree, and a mismatch is declined. That is what
		 * actually ties this request to the screen it claims to come from, which the nonce alone does not do
		 * (the nonce is built from acgd_user_id, so it agrees with whatever that field says — an admin can
		 * pass their own screen's nonce while naming their own ID, which is harmless, but the comment below
		 * used to promise more than the code delivered; PR #6, code review round, Low).
		 * The isset() is kept rather than requiring the field: core printing it is core's business, and if a
		 * future layout stops printing it, this must go back to declining nothing rather than start
		 * rejecting every legitimate click.
		 * acgd_user_id はフォームのフィールドなので送信する側が選べる値であり、それ単体では誰も特定しない。
		 * 本体は同じ人物を指す自分自身の user_id を持っており、本体の nonce を検証しているのはそちらに対して。
		 * その user_id は wp-admin/user-edit.php がこのフォーム自身の hidden フィールドとして出力している
		 * （WP 7.1 では 971 行目）ため、**2画面とも**一緒に送られてくる——wp-admin/profile.php は
		 * IS_PROFILE_PAGE を定義して user-edit.php を require するだけなので、profile.php では現在の
		 * ユーザー自身の ID が入る（クエリ文字列に user_id があるのは user-edit.php だけ、という思い込みでは
		 * なく、両画面の描画後の DOM を読んで確認した）。よって両者は一致していなければならず、食い違えば
		 * 拒否する。リクエストを「それが名乗る画面」に実際に結び付けているのはこの突き合わせであり、
		 * nonce だけでは結び付かない（nonce は acgd_user_id から組み立てるので、そのフィールドの言うことに
		 * 必ず同意する。管理者が自分の画面の nonce を自分の ID で通すことはできるが、それ自体は無害。
		 * ただし下のコメントは実装より強い約束をしていた。PR #6・コードレビュー回・Low）。
		 * フィールドを必須にせず isset() のままにしてあるのは、出力するかどうかは本体の側の事情であり、
		 * 将来の画面構成でこれが出力されなくなったときには「何も拒否しない」に戻るべきで、正当なクリックを
		 * すべて弾き始めてはならないため。
		 */
		if ( isset( $_REQUEST['user_id'] ) && (int) $_REQUEST['user_id'] !== $target_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Compared against the posted target before any nonce is built or any value is used; the nonces are verified immediately below. / nonce を組み立てる前・値を使う前の突き合わせのみ。nonce は直後に検証する。
			self::decline_verify_request( 'mismatch' );
		}

		/*
		 * Two nonces, both already present in this one form:
		 * - This plugin's own (NONCE_NAME / NONCE_ACTION), printed at the end of ACGD_User_Access::
		 *   render_fields(). It is the nonce the previous admin-post handler checked, kept unchanged so that
		 *   moving the handler does not weaken this section's own trust boundary.
		 * - Core's own "update-user_<ID>", in _wpnonce, printed by wp-admin/user-edit.php's
		 *   wp_nonce_field( 'update-user_' . $user_id ). This handler now intercepts a submission that core
		 *   itself would have validated with check_admin_referer( 'update-user_' . $user_id ), so checking the
		 *   very same nonce keeps the interception no more permissive than the save it takes the place of.
		 *   Note what this nonce does and does not prove: it is built from acgd_user_id, a field of the form,
		 *   so it confirms that whoever submitted holds a valid nonce for the ID they named — not that they
		 *   are on that person's screen. The check just above, against core's own $_REQUEST['user_id'], is
		 *   what ties the request to the screen when core supplies one.
		 * この1つのフォームに元から入っている2つの nonce を、両方とも確かめる：
		 * - このプラグイン自身のもの（NONCE_NAME / NONCE_ACTION）。ACGD_User_Access::render_fields() の末尾で
		 *   出力している。従来の admin-post ハンドラが確かめていたのと同じもので、受け口を移したことで
		 *   この区画自身の信頼境界が緩まないよう、そのまま残す。
		 * - 本体自身の「update-user_<ID>」（_wpnonce）。wp-admin/user-edit.php の
		 *   wp_nonce_field( 'update-user_' . $user_id ) が出力している。このハンドラは、本体が
		 *   check_admin_referer( 'update-user_' . $user_id ) で検証したはずの送信を横取りするのだから、
		 *   まさに同じ nonce を確かめることで、置き換えた保存処理より緩くならないようにする。
		 *   この nonce が何を証明し、何を証明しないかに注意：組み立ての材料はフォームのフィールドである
		 *   acgd_user_id なので、証明できるのは「送信者が、自分の名乗った ID について有効な nonce を
		 *   持っている」ことであって、「その人物の画面にいる」ことではない。リクエストを画面に結び付けて
		 *   いるのは、本体が user_id を供給するときに効く、すぐ上の突き合わせのほう。
		 */
		if ( ! isset( $_POST[ ACGD_User_Access::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ ACGD_User_Access::NONCE_NAME ] ) ), ACGD_User_Access::NONCE_ACTION ) ) {
			self::decline_verify_request();
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $target_id ) ) {
			self::decline_verify_request();
		}

		// Land back on whichever screen this form was actually submitted from, instead of hardcoding
		// user-edit.php (UX review HIGH fix, issue #4). "Verify" only ever confirms one's own account
		// (docs/spec.md 5.3, and ACGD_User_Access::render_fields() only prints the button when $is_self), so
		// the two screens to tell apart are the two ways one's own profile screen can be reached: "Profile"
		// (profile.php) and "Edit User" with one's own ID (user-edit.php?user_id=<self>). A request whose
		// acgd_user_id names somebody else does not belong to any button this plugin prints and is bounced
		// back unhandled below ('not_self'); $edit_url is still what sends it back, which is why nothing here
		// assumes the target is the current admin.
		// wp_get_referer() reads the _wp_http_referer hidden field ACGD_User_Access::
		// render_fields() prints for this purpose (core's own "your-profile" form prints one too, by way of
		// wp_nonce_field(); the two carry the same value — see the comment on that call in render_fields()),
		// and validates it stays on this site. Falls back to get_edit_user_link(), which is the core function
		// that actually branches on this: profile.php for one's own account, user-edit.php?user_id=... for
		// anyone else's (get_edit_profile_url() does NOT do this — it always points at the CURRENT user's
		// own profile.php no matter what ID is passed to it; verified against wp-includes/link-template.php,
		// caught only by CLI simulation of both the self and the other-user round trip, not by php -l).
		// get_edit_user_link() can itself return '' if the current user lacks the edit_user capability for
		// $target_id; wp_safe_redirect( '' ) then silently emits a 200 with no body instead of redirecting
		// (the same core quirk documented on validated_redirect_to() below), so fall back once more to
		// admin_url() rather than ever handing '' to wp_safe_redirect().
		// ★ Which of the two actually supplies the URL changed when this stopped being an admin-post handler.
		// The form now posts to itself, and core's wp_get_referer() deliberately returns false when the
		// referer equals this request's own REQUEST_URI (wp-includes/functions.php), so:
		//   - opened as profile.php: referer and REQUEST_URI are both /wp-admin/profile.php, wp_get_referer()
		//     returns false, and get_edit_user_link( self ) supplies /wp-admin/profile.php — the same screen,
		//     and the same place core itself sends a finished profile save (user-edit.php, `case 'update':`,
		//     redirects to get_edit_user_link( $user_id ) with updated=true).
		//   - opened as user-edit.php?user_id=<self>: core's form still posts to profile.php (its action is
		//     self_admin_url( IS_PROFILE_PAGE ? 'profile.php' : 'user-edit.php' ), and IS_PROFILE_PAGE is true
		//     for one's own ID however the screen was reached), so the referer differs from REQUEST_URI and
		//     wp_get_referer() returns user-edit.php?user_id=<self> — back exactly where the admin started.
		// Either way the admin lands on a screen that renders this section, which is what the HIGH fix was for.
		// ★ 実際にどちらが URL を供給するかは、admin-post のハンドラをやめた時点で変わった。フォームは今や
		// 自分自身へ送信され、本体の wp_get_referer() はリファラがこのリクエスト自身の REQUEST_URI と
		// 等しいとき意図的に false を返す（wp-includes/functions.php）。したがって：
		//   - profile.php として開いた場合：リファラも REQUEST_URI も /wp-admin/profile.php なので
		//     wp_get_referer() は false になり、get_edit_user_link( 本人 ) が /wp-admin/profile.php を返す——
		//     同じ画面であり、本体自身がプロフィール保存の完了後に送る先（user-edit.php の `case 'update':`
		//     が updated=true 付きで get_edit_user_link( $user_id ) へリダイレクトする）とも同じ。
		//   - user-edit.php?user_id=<自分> として開いた場合：本体のフォームの送信先はやはり profile.php
		//     （action は self_admin_url( IS_PROFILE_PAGE ? 'profile.php' : 'user-edit.php' ) であり、
		//     自分自身の ID ならどちらの入口から来ても IS_PROFILE_PAGE は true）。よってリファラは
		//     REQUEST_URI と異なり、wp_get_referer() は user-edit.php?user_id=<自分> を返す——出発した画面
		//     そのものへ戻る。
		// どちらにせよ、この区画が描画される画面に着地する。HIGH 修正が目指していたのはそこ。
		// このフォームが実際に送信された画面へ戻す。user-edit.php に固定していた従来の書き方をやめる
		// （UX レビューの HIGH 修正、issue #4）。「確認」が対象にするのは常に自分自身の口座だけ
		// （docs/spec.md 5.3。ACGD_User_Access::render_fields() は $is_self のときにしかボタンを出さない）
		// なので、区別すべきなのは「自分自身のプロフィール画面に至る2通りの入口」——「プロフィール」
		// （profile.php）と、自分自身の ID を指す「ユーザーを編集」（user-edit.php?user_id=<自分>）——になる。
		// acgd_user_id が他人を指すリクエストは、このプラグインが出すどのボタンにも対応せず、下で
		// 受け付けずに送り返す（'not_self'）。その送り返しに使うのもこの $edit_url であるため、ここでは
		// 対象が今の管理者自身であることを前提にしていない。
		// wp_get_referer() は、この目的で
		// ACGD_User_Access::render_fields() が出す _wp_http_referer の隠しフィールドを読み（本体自身の
		// 「your-profile」フォームも wp_nonce_field() 経由で同じものを出しており、値は同一。render_fields()
		// のその呼び出しに付けたコメントを参照）、サイト内に留まっているか検証する。無い稀なケースでは
		// get_edit_user_link() にフォールバックする：本人なら profile.php、他人なら
		// user-edit.php?user_id=... を実際に出し分けるのはこちらの本体関数（get_edit_profile_url() では
		// ない——引数に何を渡しても常に「今の」ユーザー自身の profile.php を返す。
		// wp-includes/link-template.php で確認済み。php -l では検出できず、本人・他人それぞれの往復を
		// CLI で模擬してはじめて判明した）。get_edit_user_link() 自体も、$target_id に対する edit_user
		// 権限が今の管理者に無ければ '' を返しうる。wp_safe_redirect( '' ) はリダイレクトせず本文0バイトの
		// 200 を黙って返す（下の validated_redirect_to() に書いた本体の同じ癖）ため、'' を
		// wp_safe_redirect() へ渡さないよう最後に admin_url() へさらにフォールバックする。
		$edit_url = wp_get_referer();
		if ( ! $edit_url ) {
			$edit_url = get_edit_user_link( $target_id );
		}
		if ( ! $edit_url ) {
			$edit_url = admin_url();
		}

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
			// append_pending_error(): those hang off personal_options_update / user_profile_update_errors,
			// which wp-admin/user-edit.php only fires from inside its own `case 'update':`. This handler runs
			// earlier, on admin_init, and exits here, so that case is never reached and neither hook ever
			// fires on this request — there is no WP_Error queue to add to.
			// 「確認」ボタンが意味を持つのは自分自身の口座だけ（docs/spec.md 5.3）：確認が満たすのは
			// 保存する本人（＝常に今の管理者）の保存時チェック2であるため。ここではクエリの目印で伝える。
			// save_fields()/append_pending_error() の WP_Error の仕組みを使わないのは、それらが
			// personal_options_update / user_profile_update_errors にぶら下がっており、wp-admin/user-edit.php が
			// それらを発火するのは自身の `case 'update':` の中だけだから。このハンドラはそれより前の admin_init で
			// 動いてここで exit するため、その case には到達せず、このリクエストではどちらのフックも発火しない
			// ——積むべき WP_Error の列がそもそも存在しない。
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
	 * Stops handling a "Verify" request that one of maybe_handle_verify_request()'s guards turned down, and
	 * sends the browser back to the screen the form came from with a notice
	 * (ACGD_User_Access::render_verify_notice()), so that nothing is saved and the person is told why nothing
	 * happened. Always exits.
	 * Three guards call this — the target guard and the two nonce guards — for two different reasons, so each
	 * passes the notice key that fits its own reason (code audit, Low: this used to hardcode 'expired', which
	 * told someone whose request did not match the screen that their confirmation had timed out — an answer
	 * that does not fit the question):
	 * - 'expired' for the two nonce guards. The case actually reached in practice: the screen sat open past
	 *   the nonce's lifetime, and retyping the pair is exactly the right thing to do next.
	 * - 'mismatch' for the target guard, where the form's own acgd_user_id and core's user_id name two
	 *   different people. Nothing has timed out there and retyping changes nothing; reloading the screen
	 *   does.
	 * maybe_handle_verify_request() のガードが受け付けなかった「確認」リクエストの処理をやめ、フォームが来た
	 * 画面へ通知（ACGD_User_Access::render_verify_notice()）付きで送り返す。何も保存されず、なぜ何も
	 * 起きなかったのかも伝わる。必ず exit する。
	 * 呼び出し元は3箇所のガード——対象のガードと、nonce のガード2つ——で、理由は2種類。それぞれが自分の
	 * 理由に合う通知キーを渡す（コード監査・Low：以前は 'expired' を固定で使っていたため、画面と食い違う
	 * リクエストを送った人に「確認の期限が切れました」と答えていた——問いに対して噛み合わない答えだった）：
	 * - nonce の2つのガードは 'expired'。実運用で実際に到達するのはこちら：画面を nonce の寿命より長く
	 *   開いたままにしていた場合で、次にすべきことはまさに ID とパスワードの再入力。
	 * - 対象のガードは 'mismatch'。フォーム自身の acgd_user_id と本体の user_id が別人を指している状態。
	 *   ここでは何も期限切れになっていないし、入力し直しても何も変わらない。画面を読み込み直せば変わる。
	 *
	 * The nonce has not been verified at this point, so nothing is read out of the form and nothing is
	 * written anywhere — not even the resubmit stash. wp_get_referer() is still safe to use for the
	 * destination: core runs it through wp_validate_redirect(), which keeps it on this site. It falls back to
	 * one's own profile screen, which is where this notice is meant to be read.
	 * この時点で nonce は未検証なので、フォームの内容は一切読まず、どこにも書き込まない（入力内容の
	 * 保管すらしない）。行き先に wp_get_referer() を使うこと自体は安全：本体が wp_validate_redirect() に
	 * 通しており、サイト内に留まる。無ければ本人のプロフィール画面に倒す。この通知を読む場所はそこであるため。
	 *
	 * @param string $reason Notice key for ACGD_User_Access::render_verify_notice(): 'expired' or 'mismatch'.
	 *                       / ACGD_User_Access::render_verify_notice() の通知キー。'expired' か 'mismatch'。
	 * @return void
	 */
	private static function decline_verify_request( $reason = 'expired' ) {
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'profile.php' );
		}

		// Anything other than the two known keys would print no notice at all (render_verify_notice() only
		// prints for keys it has a sentence for), so fall back to the one that is always true of a declined
		// request rather than redirecting silently.
		// この2つ以外のキーだと通知が一切出ない（render_verify_notice() は文言を持つキーのときだけ出力する）
		// ため、無言でリダイレクトするのではなく、断られたリクエストについて常に言える方へ倒す。
		if ( ! in_array( $reason, array( 'expired', 'mismatch' ), true ) ) {
			$reason = 'expired';
		}

		wp_safe_redirect( add_query_arg( 'acgd_basic_verify', $reason, $back ) );
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

		$admin_id = get_current_user_id();

		// This entire method is about the current admin verifying their own account (docs/spec.md 5.3):
		// there is no "verify someone else's credentials" version of this challenge, since the BASIC
		// credentials have to be typed by the person they belong to. So unlike maybe_handle_verify_request()'s
		// $edit_url (which really can point at someone else's "Edit User" screen), this fallback — only
		// used if $_REQUEST['redirect_to'] is itself missing or invalid, which normally never happens since
		// maybe_handle_verify_request() always supplies it — is always "Profile" (profile.php), never
		// "Edit User" (UX review HIGH fix, issue #4, applied here too for the same reason).
		// このメソッドはすべて、今の管理者が自分自身を確認する話でしかない（docs/spec.md 5.3）：BASIC の
		// 資格情報は本人が入力する必要があるため、「他人の資格情報を確認する」版は存在しない。そのため
		// maybe_handle_verify_request() の $edit_url（他人の「ユーザーを編集」画面を指しうる）とは違い、この
		// フォールバック——$_REQUEST['redirect_to'] 自体が無い・不正なときだけ使われ、
		// maybe_handle_verify_request() が常にこれを渡すため通常は起きない——は常に「プロフィール」
		// （profile.php）であって「ユーザーを編集」ではない（UX レビューの HIGH 修正、issue #4。
		// 同じ理由でここにも適用する）。
		$default_back = get_edit_profile_url( $admin_id );
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
			// Random, unguessable, and unrelated to the credentials themselves: its only job is to let
			// render_verify_token_field() mark the one profile-screen rendering this redirect leads to, so
			// save_fields() can tell it apart from any other later save (see VERIFIED_TRANSIENT_PREFIX / MEDIUM
			// fix, PR #6 second review round). wp_generate_password() with no special characters is convenient
			// here only because it is already a core-provided random string generator; the value is never
			// hashed or compared to anything a person types, so it is not "a password" in any real sense.
			// ランダムで推測不能、資格情報そのものとは無関係：役割は、このリダイレクトの先にある1回きりの
			// プロフィール画面の表示だけを render_verify_token_field() に印付けさせ、save_fields() がそれを
			// 他のどの後続の保存とも区別できるようにすること（VERIFIED_TRANSIENT_PREFIX・MEDIUM の修正、
			// PR #6 の2回目のレビューを参照）。wp_generate_password() を使うのは、本体が用意するランダム文字列
			// 生成関数として手近だからにすぎない：この値をハッシュ化したり人の入力と比較したりすることは無いため、
			// 実質的には「パスワード」ではない。
			$token = wp_generate_password( 32, false, false );
			set_transient(
				self::VERIFIED_TRANSIENT_PREFIX . $admin_id,
				array(
					'id'    => $pending['id'],
					// Computed once, here, so a later resubmit with a blank password field (MEDIUM-1 fix) can
					// still reuse this exact hash instead of needing the password retyped. / ここで一度だけ
					// 計算しておく。パスワード欄が空のまま出し直されても（MEDIUM-1 の修正）、再入力なしに
					// このハッシュをそのまま使い回せるようにするため。
					'hash'  => password_hash( $pending['password'], PASSWORD_DEFAULT ),
					'token' => $token,
				),
				self::VERIFY_TTL
			);
			wp_safe_redirect(
				add_query_arg(
					array(
						'acgd_basic_verify'      => 'ok',
						self::VERIFY_TOKEN_FIELD => $token,
					),
					$redirect_to
				)
			);
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
	 * Tells whether a fresh "Verify" confirmation exists for the given admin, ID and token, WITHOUT consuming
	 * it — the transient is left in place either way. Called by ACGD_User_Access::save_fields() when an admin
	 * is setting their own mode to BASIC authentication.
	 *
	 * Split from the actual consumption (invalidate_verification()) so a save that ends up rejected for an
	 * unrelated reason (an IP list typo, a BASIC ID collision, and so on — checked by save_fields() after this
	 * lookup) does not also burn the confirmation the admin correctly went through; only save_fields() itself
	 * knows when the save is actually going to succeed, so only it decides when to call
	 * invalidate_verification() (Low fix, etbs-senior-wp audit on PR #6: previously the single consume_
	 * verification() method deleted the transient the moment it was looked up, before any of those unrelated
	 * checks ran).
	 *
	 * $token must match VERIFIED_TRANSIENT_PREFIX's stored token exactly (hash_equals()), or this returns null
	 * without even checking $id or $password: a missing or wrong token means the form being submitted right now
	 * is not the one handle_verify() sent the admin back to, so nothing here should be trusted regardless of
	 * what else matches (MEDIUM fix, PR #6 second review round — see VERIFIED_TRANSIENT_PREFIX and
	 * ACGD_User_Access::render_verify_token_field()).
	 *
	 * $password is what to require of the confirmation, not what to hash and store: pass the exact string just
	 * retyped in the password field to require it match what was confirmed (password_verify() against the
	 * hash from handle_verify()), or null when that field was left blank — the normal case right after a
	 * successful Verify, since render_fields() never redisplays a password — to trust the confirmation as-is
	 * and hand back its hash unchanged (MEDIUM-1 fix, PR #6 first review round: previously a blank password
	 * field here always failed this check, silently leaving the old hash saved even though "Verify" had just
	 * succeeded).
	 * 与えた管理者・ID・トークンについて、確認済みの結果があるかを返す。**消費はしない**（どちらの結果でも
	 * transient はそのまま残す）。管理者が自分自身のモードを BASIC 認証にするとき、
	 * ACGD_User_Access::save_fields() から呼ぶ。
	 *
	 * 実際の消費（invalidate_verification()）とは分けている：この探索の後で save_fields() が確かめる、確認とは
	 * 無関係な理由（IP 一覧の誤記、BASIC ID の衝突など）で結局保存が拒否されても、管理者が正しく通した確認まで
	 * 一緒に消費しないようにするため。保存が実際に成功するかどうかを知っているのは save_fields() 自身だけなので、
	 * invalidate_verification() を呼ぶかどうかもそちらだけが決める（大の監査（PR #6）の Low の修正：修正前は
	 * 単一の consume_verification() が、探索した瞬間に――それらの無関係なチェックより前に――transient を
	 * 消していた）。
	 *
	 * $token は VERIFIED_TRANSIENT_PREFIX が保持するトークンと厳密に一致（hash_equals()）しなければならず、
	 * 一致しなければ $id・$password を見るまでもなく null を返す：トークンが無い・違うということは、今まさに
	 * 送信されているフォームが handle_verify() が管理者を送り返した先そのものではないということであり、
	 * 他の何が一致していても信頼してはならない（MEDIUM の修正。PR #6 の2回目のレビュー。
	 * VERIFIED_TRANSIENT_PREFIX と ACGD_User_Access::render_verify_token_field() を参照）。
	 *
	 * $password は「確認済みのものに何を求めるか」であって、ハッシュ化して保存する対象ではない：パスワード欄に
	 * たった今入力し直した文字列そのものを渡せば、確認済みのものと一致すること（handle_verify() が作った
	 * ハッシュに対する password_verify()）を求める。その欄が空のまま（render_fields() はパスワードを一切
	 * 出し直さないため、「確認」成功直後の通常のケース）なら null を渡し、確認済みの内容をそのまま信頼して
	 * そのハッシュを変更せずに返す（MEDIUM-1 の修正。PR #6 の1回目のレビュー：修正前はここでパスワード欄が
	 * 空だと必ずこのチェックに失敗し、「確認」に成功した直後でも古いハッシュが無言で保存されたまま残っていた）。
	 *
	 * @param int         $admin_id Admin who confirmed. / 確認した管理者。
	 * @param string      $id       ID being saved now. / 今保存しようとしている ID。
	 * @param string      $token    Token submitted with this save (VERIFY_TOKEN_FIELD), or '' if absent. / この保存で送信されたトークン（VERIFY_TOKEN_FIELD）。無ければ ''。
	 * @param string|null $password Password just retyped, or null if that field was left blank. / たった今入力し直したパスワード。欄が空なら null。
	 * @return string|null The confirmed password's password_hash(), ready to save as-is, or null when there is
	 *                      no matching confirmation. / 確認済みパスワードの password_hash()（そのまま保存できる）。
	 *                      一致する確認が無ければ null。
	 */
	public static function find_verified_hash( $admin_id, $id, $token, $password ) {
		$value = get_transient( self::VERIFIED_TRANSIENT_PREFIX . (int) $admin_id );
		if ( ! is_array( $value ) || ! isset( $value['id'], $value['hash'], $value['token'] ) ) {
			return null;
		}

		if ( '' === (string) $token || ! hash_equals( (string) $value['token'], (string) $token ) ) {
			return null; // Not the form Verify just sent the admin back to. / 「確認」が送り返した先のフォームではない。
		}
		if ( ! hash_equals( (string) $value['id'], (string) $id ) ) {
			return null; // Confirmed a different ID than the one being saved now. / 確認したのは今保存しようとしているのとは別の ID。
		}
		if ( null !== $password && ! password_verify( $password, $value['hash'] ) ) {
			return null; // Retyped, but not what was actually confirmed. / 入力し直されたが、確認済みの内容とは一致しない。
		}

		return $value['hash'];
	}

	/**
	 * Deletes the given admin's pending "Verify" confirmation, if any, so it cannot be looked up again by
	 * find_verified_hash(). Called by ACGD_User_Access::save_fields() only once it is certain the confirmed
	 * hash is actually about to be written — see find_verified_hash() for why this is a separate step.
	 * 与えた管理者の確認済み（未消費）の transient があれば削除し、find_verified_hash() が二度と拾えないように
	 * する。ACGD_User_Access::save_fields() が、確認済みハッシュを実際にこれから書き込むと確定した時点でだけ
	 * 呼ぶ。なぜ別の手順に分けているかは find_verified_hash() を参照。
	 *
	 * @param int $admin_id Admin whose confirmation to invalidate. / 確認を無効化する対象の管理者。
	 * @return void
	 */
	public static function invalidate_verification( $admin_id ) {
		delete_transient( self::VERIFIED_TRANSIENT_PREFIX . (int) $admin_id );
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
	 * @param string $fallback Fallback when missing or invalid. / 無い・不正なときの既定値。
	 * @return string Validated URL. / 検証済みの URL。
	 */
	private static function validated_redirect_to( $fallback ) {
		$raw = isset( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; the value itself is validated by wp_validate_redirect() below, and nothing is changed by reading it.

		if ( '' === $raw ) {
			// wp_validate_redirect() does NOT fall back to $fallback when $raw is '' (core bug-for-bug
			// behavior, wp-includes/pluggable.php): parse_url('') returns an array with no 'host', so the
			// host-mismatch branch that substitutes $fallback never fires, and '' is returned unchanged.
			// That empty string then reaches wp_safe_redirect()/wp_redirect(), whose
			// `if ( ! $location ) { return false; }` guard silently emits no headers and no body at all —
			// a "200 OK, 0 bytes" response instead of a redirect. This hit the plugin's most ordinary path
			// (unauthenticated wp-admin access → challenge screen → correct credentials), since
			// redirect_to_challenge() also routes through this method without a redirect_to request param
			// (issue #4, UI test finding). Fall back ourselves; never leave it to wp_validate_redirect().
			// wp_validate_redirect() は $raw が '' のとき $fallback へフォールバックしない（本体の仕様。
			// wp-includes/pluggable.php）：parse_url('') は 'host' を持たない配列を返すため、$fallback に
			// 差し替えるホスト不一致の分岐が発火せず、'' がそのまま返る。その空文字が
			// wp_safe_redirect()/wp_redirect() に渡ると、`if ( ! $location ) { return false; }` の
			// ガードに引っかかり、ヘッダーも本文も一切出さずに終わる——リダイレクトではなく
			// 「200 OK・本文0バイト」になる。redirect_to_challenge() も redirect_to のリクエストパラメータ
			// 無しでこのメソッドを通るため、このプラグインで一番普通の導線（未認証での wp-admin アクセス→
			// 確認画面→正しい資格情報）がまるごと壊れていた（issue #4、UIテストで判明）。ここで自分で
			// フォールバックする。wp_validate_redirect() 任せにしない。
			return $fallback;
		}

		return wp_validate_redirect( $raw, $fallback );
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
			<?php
			if ( '' === trim( (string) $line ) ) {
				continue;
			}
			?>
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
				'token'              => $token,
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
					'Authorization' => 'Basic ' . base64_encode( self::DIAG_TEST_USER . ':' . self::DIAG_TEST_PASS ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Not obfuscation: this builds the standard base64 payload of an outgoing BASIC authentication header (RFC 7617) for the diagnosis's own loopback request. / 難読化ではない：診断自身のループバックリクエスト用に、送信する BASIC 認証ヘッダー（RFC 7617）の標準的な base64 部分を組み立てているだけ。
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
