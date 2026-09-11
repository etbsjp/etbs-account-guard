<?php
/**
 * Login name protection (items a to h of section 4 of docs/spec.md).
 * ログイン名の保護（docs/spec.md 4節の a〜h）。
 *
 * Each item has its own switch in the settings, and every callback checks its own switch.
 * Turning an item off therefore returns only that route to the behavior of WordPress core.
 * 項目ごとに設定のスイッチがあり、各コールバックが自分のスイッチを確かめる。
 * したがって項目を OFF にすると、その経路だけが WordPress 本体の挙動に戻る。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks and helpers of the login name protection.
 * ログイン名の保護のフックと補助関数。
 */
class ACGD_Login_Name {

	/**
	 * Option that stores the switches of this feature (registered with register_setting()).
	 * この機能のスイッチを保存するオプション（register_setting() で登録）。
	 *
	 * @var string
	 */
	const OPTION = 'acgd_login_name_protection';

	/**
	 * Priority of the authenticate filter that rewrites the login errors (item f).
	 * ログインエラーを差し替える authenticate フィルタの優先度（f）。
	 *
	 * Core checks the password at 20 and runs its spam check at 99. Running well after them means this
	 * filter sees the final result, while callbacks of other plugins at usual priorities still see the
	 * original error codes.
	 * 本体のパスワード照合は 20、スパム判定は 99。それより十分後ろで動かすことで、こちらは最終結果を見られ、
	 * 通常の優先度で動く他プラグインのコールバックは元のエラーコードを見られる。
	 *
	 * @var int
	 */
	const AUTHENTICATE_PRIORITY = 9999;

	/**
	 * Login error codes that tell whether an account exists (item f).
	 * アカウントの有無が分かってしまうログインエラーのコード（f）。
	 *
	 * wp_authenticate_username_password() / wp_authenticate_email_password() answer invalid_username /
	 * invalid_email for an unknown account and incorrect_password for an existing one.
	 * Core also hooks wp_authenticate_application_password() on authenticate at 20. For REST API and XML-RPC
	 * requests on a site where an application password has ever been created, it answers invalid_username /
	 * invalid_email for an unknown account, and for an existing one incorrect_password,
	 * application_passwords_disabled (application passwords are off for the site) or
	 * application_passwords_disabled_for_user, replacing the error of the earlier callbacks.
	 * wp_authenticate_username_password() / wp_authenticate_email_password() は、アカウントが無いときは
	 * invalid_username / invalid_email、あるときは incorrect_password を返す。
	 * 本体は wp_authenticate_application_password() も authenticate の優先度 20 に登録している。アプリケーション
	 * パスワードが作られたことのあるサイトの REST API・XML-RPC のリクエストでは、アカウントが無いときは
	 * invalid_username / invalid_email、あるときは incorrect_password・application_passwords_disabled
	 * （サイトでアプリケーションパスワードが無効）・application_passwords_disabled_for_user を返し、
	 * それまでのコールバックのエラーを置き換える。
	 *
	 * @var string[]
	 */
	const REVEALING_LOGIN_CODES = array( 'invalid_username', 'invalid_email', 'incorrect_password', 'application_passwords_disabled', 'application_passwords_disabled_for_user' );

	/**
	 * Priority of the rest_authentication_errors filter that rewrites the REST login errors (item f).
	 * REST のログインエラーを差し替える rest_authentication_errors フィルタの優先度（f）。
	 *
	 * Core surfaces application password failures at 90 and checks the cookie at 100. Running well after
	 * them means this filter sees the final result, including errors other plugins return.
	 * 本体はアプリケーションパスワードの失敗を 90 で返し、cookie を 100 で確かめる。それより十分後ろで動かすことで、
	 * 他のプラグインが返したエラーも含めて最終結果を見られる。
	 *
	 * @var int
	 */
	const REST_AUTHENTICATION_PRIORITY = 9999;

	/**
	 * REST API authentication error codes that tell whether an account exists (item f).
	 * アカウントの有無が分かってしまう REST API の認証エラーのコード（f）。
	 *
	 * The same list as REVEALING_LOGIN_CODES: the REST API gets these errors from the same
	 * wp_authenticate_application_password(), called from determine_current_user instead of authenticate.
	 * One list keeps the two paths from drifting apart.
	 * REVEALING_LOGIN_CODES と同じ一覧。REST API のこれらのエラーは、authenticate ではなく
	 * determine_current_user から呼ばれる同じ wp_authenticate_application_password() が返す。
	 * 一覧を1つにして、2つの経路の間でずれが生じないようにする。
	 *
	 * @var string[]
	 */
	const REVEALING_REST_CODES = self::REVEALING_LOGIN_CODES;

	/**
	 * Lost password error codes that tell whether an account exists (item f).
	 * パスワード再発行で、アカウントの有無が分かってしまうエラーのコード（f）。
	 *
	 * invalidcombo / invalid_email mean "no such account". no_password_reset is returned only for an
	 * existing account whose password reset is not allowed, so it tells both that the account exists and
	 * which kind of account it is.
	 * invalidcombo / invalid_email は「アカウントが無い」。no_password_reset はパスワードの再発行が
	 * 許可されていない、存在するアカウントにだけ返るので、アカウントがあることと、どういうアカウントかが分かる。
	 *
	 * @var string[]
	 */
	const REVEALING_RESET_CODES = array( 'invalidcombo', 'invalid_email', 'no_password_reset' );

	/**
	 * Registers the hooks. / フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		// a: REST API user routes. / REST API のユーザー関連ルート。
		add_filter( 'rest_endpoints', array( __CLASS__, 'filter_rest_endpoints' ) );

		// b: oEmbed author. / oEmbed の投稿者。
		add_filter( 'oembed_response_data', array( __CLASS__, 'filter_oembed_response_data' ) );

		// c: user sitemap, and g: author archives (both answer with a 404 through pre_handle_404).
		// c: ユーザーのサイトマップ、g: 投稿者ページ（どちらも pre_handle_404 で 404 を返す）。
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'filter_sitemaps_add_provider' ), 10, 2 );
		add_filter( 'pre_handle_404', array( __CLASS__, 'filter_pre_handle_404' ), 10, 2 );

		// d: class names. / クラス名。
		add_filter( 'comment_class', array( __CLASS__, 'filter_comment_class' ), 10, 4 );
		add_filter( 'body_class', array( __CLASS__, 'filter_body_class' ) );

		// e: ?author= redirect. Before redirect_canonical(), which runs at priority 10.
		// e: ?author= の転送。優先度 10 で動く redirect_canonical() より前。
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_author_query' ), 1 );

		// f: login, REST API authentication and lost password messages. / ログイン・REST API の認証・パスワード再発行の文言。
		add_filter( 'authenticate', array( __CLASS__, 'filter_authenticate' ), self::AUTHENTICATE_PRIORITY );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'filter_rest_authentication_errors' ), self::REST_AUTHENTICATION_PRIORITY );
		add_action( 'lost_password', array( __CLASS__, 'maybe_redirect_lost_password' ) );
	}

	/*-------------------------------------------*/
	/* Settings / 設定
	/*-------------------------------------------*/

	/**
	 * Returns the default switches. Everything is on except g, which can break existing links.
	 * スイッチの既定値を返す。既存のリンクを壊しうる g だけ OFF で、他は ON。
	 *
	 * The keys are also the list of allowed keys for the sanitize callback of the settings screen.
	 * このキーは、設定画面の sanitize コールバックが許可するキーの一覧も兼ねる。
	 *
	 * @return bool[] Switch name => enabled. / スイッチ名 => 有効かどうか。
	 */
	public static function get_defaults() {
		return array(
			'rest_users'     => true,  // a.
			'oembed_author'  => true,  // b.
			'users_sitemap'  => true,  // c.
			'name_classes'   => true,  // d.
			'author_query'   => true,  // e.
			'login_messages' => true,  // f.
			'author_archive' => false, // g.
		);
	}

	/**
	 * Returns the saved switches merged over the defaults.
	 * 保存済みのスイッチを既定値に重ねて返す。
	 *
	 * A missing or broken option falls back to the defaults, that is, to the protected side.
	 * オプションが無い・壊れているときは既定値＝塞がる側に倒す。
	 *
	 * @return bool[] Switch name => enabled. / スイッチ名 => 有効かどうか。
	 */
	public static function get_settings() {
		$defaults = self::get_defaults();
		$saved    = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = array();
		foreach ( $defaults as $key => $default ) {
			$settings[ $key ] = array_key_exists( $key, $saved ) ? (bool) $saved[ $key ] : $default;
		}

		return $settings;
	}

	/**
	 * Tells whether one item is turned on. / 1項目が ON かどうかを返す。
	 *
	 * @param string $key Switch name (a key of get_defaults()). / スイッチ名（get_defaults() のキー）。
	 * @return bool Whether it is on. / ON かどうか。
	 */
	public static function is_enabled( $key ) {
		$settings = self::get_settings();

		return ! empty( $settings[ $key ] );
	}

	/*-------------------------------------------*/
	/* a: REST API / REST API
	/*-------------------------------------------*/

	/**
	 * Removes the user routes from the REST API for visitors who are not logged in (item a).
	 * ログインしていない訪問者に対し、REST API からユーザー関連のルートを取り除く（a）。
	 *
	 * Why rest_endpoints rather than rest_pre_dispatch or a permission hook:
	 * - Every way into the REST server finds its handler through match_request_to_handler() and
	 *   get_routes(), which applies this filter: /wp-json/ and ?rest_route= top-level requests, the
	 *   internal requests of _embed (embed_links() calls dispatch()), batch requests and rest_do_request().
	 *   Judging registered routes here covers all of them without looking at URL strings.
	 * - The request then ends in core's own rest_no_route (404) before any permission_callback or
	 *   get_user() runs, so an existing ID, a missing ID and the list give identical responses.
	 *   A hook after the route match (rest_request_before_callbacks is fine, but permission-level hooks
	 *   such as rest_dispatch_request are not) would let the controller answer rest_user_invalid_id (404)
	 *   for a missing ID and rest_user_cannot_view (401) for an existing one.
	 *   rest_pre_dispatch would force us to re-implement the router's path matching (it is case-insensitive
	 *   and tolerant of trailing slashes), which is the URL-string check the spec rules out.
	 * - For top-level requests, serve_request() runs check_authentication() before dispatch(), and the
	 *   cookie check resets a cookie without a valid nonce to user 0. is_user_logged_in() here therefore
	 *   matches the REST authentication: cookie + nonce and application passwords count as logged in.
	 *
	 * rest_pre_dispatch や権限フックではなく rest_endpoints を使う理由:
	 * - REST サーバへの入口はすべて match_request_to_handler() → get_routes() でハンドラを探し、
	 *   get_routes() がこのフィルタを通す。/wp-json/ と ?rest_route= の通常のリクエスト、_embed の
	 *   内部リクエスト（embed_links() が dispatch() を呼ぶ）、バッチ、rest_do_request() のすべてが該当する。
	 *   登録済みのルートをここで判定すれば、URL の文字列を見ずに全経路へ同じ判定が掛かる。
	 * - その結果、permission_callback や get_user() が動く前に本体の rest_no_route（404）で終わるので、
	 *   存在する ID・存在しない ID・一覧の応答がまったく同じになる。ルートの照合より後のフックのうち
	 *   権限まわりのもの（rest_dispatch_request など）では、コントローラが存在しない ID に
	 *   rest_user_invalid_id（404）、存在する ID に rest_user_cannot_view（401）を返してしまい区別がつく。
	 *   rest_pre_dispatch ではルータのパス照合（大文字小文字を区別せず、末尾のスラッシュも許す）を
	 *   自前で作り直すことになり、仕様が禁じる「URL の文字列での判定」になる。
	 * - 通常のリクエストでは serve_request() が dispatch() の前に check_authentication() を行い、
	 *   cookie の確認で nonce が正しくない cookie はユーザー 0 に戻される。したがってここでの
	 *   is_user_logged_in() は REST の認証結果と一致する（cookie＋nonce とアプリケーションパスワードはログイン扱い）。
	 *
	 * @param array $endpoints Registered routes: route pattern => handlers. / 登録済みのルート（ルートのパターン => ハンドラ）。
	 * @return array Routes. / ルート。
	 */
	public static function filter_rest_endpoints( $endpoints ) {
		// Check the cheap switch first, then the current user. / 安いスイッチを先に見て、次に現在のユーザーを見る。
		if ( ! is_array( $endpoints ) || ! self::is_enabled( 'rest_users' ) || is_user_logged_in() ) {
			return $endpoints;
		}

		foreach ( array_keys( $endpoints ) as $route ) {
			if ( self::is_users_route( $route ) ) {
				unset( $endpoints[ $route ] );
			}
		}

		return $endpoints;
	}

	/**
	 * Tells whether a registered route pattern belongs to /wp/v2/users.
	 * 登録済みのルートのパターンが /wp/v2/users 配下かどうかを返す。
	 *
	 * The argument is the registered pattern (for example /wp/v2/users/(?P<id>[\d]+)), not a request URL.
	 * 引数は登録済みのパターン（例 /wp/v2/users/(?P<id>[\d]+)）で、リクエストの URL ではない。
	 *
	 * @param string $route Route pattern. / ルートのパターン。
	 * @return bool Whether it is a user route. / ユーザー関連のルートかどうか。
	 */
	public static function is_users_route( $route ) {
		$base = '/wp/v2/users';

		// The collection itself, or anything below it. / 一覧そのもの、またはその配下。
		return $base === $route || 0 === strpos( (string) $route, $base . '/' );
	}

	/*-------------------------------------------*/
	/* b: oEmbed / oEmbed
	/*-------------------------------------------*/

	/**
	 * Replaces the oEmbed author with the values core uses for a post without an author (item b).
	 * oEmbed の投稿者を、本体が投稿者なしの投稿に使う値へ置き換える（b）。
	 *
	 * get_oembed_response_data() builds both the JSON and the XML (format=xml) response, so one filter covers both.
	 * JSON と XML（format=xml）の応答はどちらも get_oembed_response_data() が作るので、1つのフィルタで両方に効く。
	 *
	 * @param array $data oEmbed response data. / oEmbed の応答データ。
	 * @return array Response data. / 応答データ。
	 */
	public static function filter_oembed_response_data( $data ) {
		if ( ! is_array( $data ) || ! self::is_enabled( 'oembed_author' ) ) {
			return $data;
		}

		// The same two values as the defaults in get_oembed_response_data().
		// get_oembed_response_data() の既定値と同じ2つの値。
		$data['author_name'] = get_bloginfo( 'name' );
		$data['author_url']  = get_home_url();

		return $data;
	}

	/*-------------------------------------------*/
	/* c, g: sitemap and author archives / サイトマップと投稿者ページ
	/*-------------------------------------------*/

	/**
	 * Keeps the users provider out of the sitemaps (item c).
	 * サイトマップにユーザーのプロバイダを登録させない（c）。
	 *
	 * @param WP_Sitemaps_Provider|mixed $provider Provider to register. / 登録するプロバイダ。
	 * @param string                     $name     Provider name. / プロバイダ名。
	 * @return WP_Sitemaps_Provider|mixed|false The provider, or false to skip it. / プロバイダ。登録しないときは false。
	 */
	public static function filter_sitemaps_add_provider( $provider, $name ) {
		if ( 'users' === $name && self::is_enabled( 'users_sitemap' ) ) {
			return false;
		}

		return $provider;
	}

	/**
	 * Answers with a 404 for the user sitemap (item c) and, when turned on, for author archives (item g).
	 * ユーザーのサイトマップ（c）と、ON のときは投稿者ページ（g）に 404 を返す。
	 *
	 * c: without the users provider, WP_Sitemaps::render_sitemaps() simply returns for sitemap=users and
	 * what follows depends on the main query and the theme. Deciding the 404 here does not rely on that.
	 * g: covers /author/{name}/, its feeds, ?author_name= and plain-permalink ?author=, since all of them
	 * are is_author(). Core decides a 200 for an existing author even without posts, and a 404 otherwise,
	 * which is what tells whether a name exists. Only visitors who are not logged in get the 404; logged-in
	 * users see author pages as before, like every other item of this feature.
	 * This filter runs in WP::handle_404(), before template_redirect, so redirect_canonical() never sees
	 * these requests as author archives and never redirects them to /author/{name}/.
	 *
	 * c: ユーザーのプロバイダが無いと、WP_Sitemaps::render_sitemaps() は sitemap=users に対して何もせず戻り、
	 * その後はメインクエリとテーマ次第になる。ここで 404 を決めて、それに頼らない。
	 * g: /author/{名前}/・そのフィード・?author_name=・基本パーマリンクの ?author= はすべて is_author() なので
	 * まとめて対象になる。本体は投稿の無い投稿者でも存在すれば 200、無ければ 404 を返し、それで名前の有無が分かる。
	 * 404 にするのはログインしていない訪問者だけで、ログイン中は、この機能の他の項目と同じく従来どおり表示する。
	 * このフィルタは WP::handle_404() の中、template_redirect より前に動くので、redirect_canonical() が
	 * これらを投稿者ページとして /author/{名前}/ へ転送することもない。
	 *
	 * @param bool     $preempt  Whether an earlier callback short-circuited the 404 handling. / 先のコールバックが 404 の処理を済ませたか。
	 * @param WP_Query $wp_query Main query. / メインクエリ。
	 * @return bool True when this callback decided a 404. / このコールバックが 404 を決めたときは true。
	 */
	public static function filter_pre_handle_404( $preempt, $wp_query ) {
		if ( ! $wp_query instanceof WP_Query ) {
			return $preempt;
		}

		$is_users_sitemap  = self::is_enabled( 'users_sitemap' ) && 'users' === $wp_query->get( 'sitemap' );
		$is_author_archive = self::is_enabled( 'author_archive' ) && $wp_query->is_author() && ! is_user_logged_in();
		if ( ! $is_users_sitemap && ! $is_author_archive ) {
			return $preempt;
		}

		// The same three calls WP::handle_404() makes when it decides on a 404.
		// WP::handle_404() が 404 と決めたときと同じ3つの呼び出し。
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		return true;
	}

	/*-------------------------------------------*/
	/* d: class names / クラス名
	/*-------------------------------------------*/

	/**
	 * Removes comment-author-{name} from comments by registered users (item d).
	 * 登録ユーザーのコメントから comment-author-{名前} を外す（d）。
	 *
	 * @param string[]        $classes    Comment classes. / コメントのクラス。
	 * @param string|string[] $css_class  Extra classes passed to comment_class(). Unused. / comment_class() に渡された追加のクラス（未使用）。
	 * @param string|int      $comment_id Comment ID. / コメント ID。
	 * @param WP_Comment|null $comment    Comment. / コメント。
	 * @return string[] Classes. / クラス。
	 */
	public static function filter_comment_class( $classes, $css_class = '', $comment_id = 0, $comment = null ) {
		if ( ! is_array( $classes ) || ! self::is_enabled( 'name_classes' ) ) {
			return $classes;
		}

		// Older callers may not pass the comment object. / 古い呼び出し元はコメントのオブジェクトを渡さないことがある。
		if ( ! $comment instanceof WP_Comment ) {
			$comment = get_comment( $comment_id );
		}
		if ( ! $comment instanceof WP_Comment || (int) $comment->user_id <= 0 ) {
			return $classes;
		}

		// Core adds the class only when the user exists (get_comment_class()). / 本体もユーザーが存在するときだけクラスを足す（get_comment_class()）。
		$user = get_userdata( (int) $comment->user_id );
		if ( ! $user ) {
			return $classes;
		}

		return self::remove_name_class( $classes, 'comment-author-', $user->user_nicename, $comment->user_id );
	}

	/**
	 * Removes author-{name} from the body classes of author archives (item d).
	 * 投稿者ページの body のクラスから author-{名前} を外す（d）。
	 *
	 * @param string[] $classes Body classes. / body のクラス。
	 * @return string[] Classes. / クラス。
	 */
	public static function filter_body_class( $classes ) {
		if ( ! is_array( $classes ) || ! self::is_enabled( 'name_classes' ) || ! is_author() ) {
			return $classes;
		}

		// Same object and same condition as get_body_class(). / get_body_class() と同じオブジェクト・同じ条件。
		$author = get_queried_object();
		if ( ! isset( $author->user_nicename, $author->ID ) ) {
			return $classes;
		}

		return self::remove_name_class( $classes, 'author-', $author->user_nicename, $author->ID );
	}

	/**
	 * Removes the class that core built from the user name, and keeps it when it was built from the ID.
	 * 本体がユーザー名から作ったクラスを外す。ID から作られていた場合は残す。
	 *
	 * Core builds it as $prefix . sanitize_html_class( $nicename, $user_id ), which falls back to the
	 * user ID when the name sanitizes to nothing. Rebuilding it the same way finds the exact class core
	 * added. If that equals $prefix . $user_id, it carries only the ID and is kept; otherwise it carries
	 * the name and is removed. Other ID classes, such as author-{ID}, are never touched.
	 *
	 * 本体は $prefix . sanitize_html_class( $nicename, $user_id ) で作り、名前が空に落ちると ID を使う。
	 * 同じ式で作り直せば、本体が足したクラスそのものが分かる。それが $prefix . $user_id と同じなら
	 * ID しか含まないので残し、違えば名前を含むので外す。author-{ID} など他の ID 入りのクラスには触れない。
	 *
	 * @param string[]   $classes  Classes. / クラス。
	 * @param string     $prefix   Class prefix, such as 'author-'. / クラスの接頭辞（例 'author-'）。
	 * @param string     $nicename user_nicename of the user. / ユーザーの user_nicename。
	 * @param int|string $user_id  User ID. / ユーザー ID。
	 * @return string[] Classes, re-indexed. / クラス（添字を振り直したもの）。
	 */
	public static function remove_name_class( $classes, $prefix, $nicename, $user_id ) {
		$added    = $prefix . sanitize_html_class( $nicename, $user_id );
		$id_class = $prefix . sanitize_html_class( (string) $user_id );

		// Built from the ID only: nothing to hide. / ID だけで作られている：隠すものは無い。
		if ( $added === $id_class ) {
			return $classes;
		}

		return array_values( array_diff( $classes, array( $added ) ) );
	}

	/*-------------------------------------------*/
	/* e: ?author= / ?author=
	/*-------------------------------------------*/

	/**
	 * Redirects front-end requests with ?author= to the home page (item e).
	 * ?author= の付いたフロントのリクエストをトップへ転送する（e）。
	 *
	 * Runs on template_redirect at priority 1, before redirect_canonical() (priority 10), which would
	 * otherwise answer with Location: /author/{name}/. Plain permalinks, where core shows the author
	 * archive without redirecting, are covered too.
	 * Every value of the parameter is redirected, not only digits: WP_Query also treats values such as
	 * "1abc" as an author query, while redirect_canonical() redirects digits only.
	 * The decision is made on the parsed query variable ($wp->query_vars), not on $_GET alone. WP::parse_request()
	 * fills the public query variables from the query string and from POST data alike, and the main query
	 * follows what it parsed, whereas redirect_canonical() looks at $_GET only.
	 * It also fills them from a matched rewrite rule, so the request must carry author itself (in the query
	 * string or in POST data). An author= made by a rewrite rule of a theme or plugin belongs to that page's
	 * own address and is left alone.
	 * The admin screens (the author filter of edit.php), the REST API and admin-ajax never reach
	 * template_redirect; the explicit checks below state that and keep it true if this is ever moved.
	 * A 302 is used because the switch can be turned off, and a cached 301 would outlive it.
	 *
	 * template_redirect の優先度 1 で動く。優先度 10 の redirect_canonical() が動くと
	 * Location: /author/{名前}/ を返してしまうため、それより前に処理する。本体が転送せずに投稿者ページを
	 * 出す基本パーマリンクも対象になる。
	 * 数字だけでなくパラメータの値を問わず転送する。WP_Query は "1abc" のような値も投稿者の絞り込みとして扱うが、
	 * redirect_canonical() が転送するのは数字だけだから。
	 * 判定は $_GET だけでなく、解析済みのクエリ変数（$wp->query_vars）で行う。WP::parse_request() は公開クエリ変数を
	 * クエリ文字列からも POST のデータからも同じように取り込み、メインクエリはその解析結果に従う。
	 * 一方 redirect_canonical() が見るのは $_GET だけである。
	 * ただし、一致した書き換えルールからも取り込むので、リクエスト自身が author を持ってきたとき（クエリ文字列か
	 * POST のデータ）に限る。テーマやプラグインの書き換えルールが作った author= は、そのページ自身のアドレスの
	 * 一部なので触れない。
	 * 管理画面（edit.php の投稿者での絞り込み）・REST API・admin-ajax は template_redirect に来ない。
	 * 下の明示的な判定はそれを書き表し、将来この処理を移しても成り立つようにするためのもの。
	 * スイッチを OFF にできるので 302 にする。301 だとブラウザにキャッシュされ、OFF にした後も残る。
	 *
	 * @global WP $wp Current WordPress environment. / 現在の WordPress 環境。
	 *
	 * @return void
	 */
	public static function maybe_redirect_author_query() {
		global $wp;

		if ( ! self::is_enabled( 'author_query' ) ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Presence check only. Nothing from the request is used. / 有無を見るだけで、リクエストの値は使わない。
		if ( ! $wp instanceof WP || ! isset( $wp->query_vars['author'] ) ) {
			return;
		}

		// The request itself must carry author; one made by a rewrite rule is left alone (see above).
		// Only whether the key exists is read, and nothing is changed, so there is no nonce to check.
		// リクエスト自身が author を持ってきたときに限る。書き換えルールが作ったものには触れない（上記）。
		// キーの有無を見るだけで何も変更しないので、確かめる nonce は無い。
		$from_request = isset( $_GET['author'] ) || isset( $_POST['author'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Presence check only; no value is used and nothing is changed.
		if ( ! $from_request ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ), 302 );
		exit;
	}

	/*-------------------------------------------*/
	/* f: login and lost password / ログインとパスワード再発行
	/*-------------------------------------------*/

	/**
	 * Replaces the login errors that tell whether an account exists with one common error (item f).
	 * アカウントの有無が分かってしまうログインエラーを、共通の1つのエラーに差し替える（f）。
	 *
	 * Only the codes of REVEALING_LOGIN_CODES are replaced; any other code (empty fields, CAPTCHA, login
	 * lockout and so on from other plugins) stays with its message, after the common error.
	 * The code of the common error is chosen so that the username field comes back the same in both cases
	 * (see ACGD_Invalid_Credentials::CODE).
	 * 差し替えるのは REVEALING_LOGIN_CODES のコードだけで、それ以外のコード（空欄、
	 * 他プラグインの画像認証・ログインロックなど）は文言とともに共通のエラーの後ろに残す。
	 * 共通のエラーのコードは、どちらの場合もユーザー名欄が同じ状態で戻るように選んでいる
	 * （ACGD_Invalid_Credentials::CODE を参照）。
	 *
	 * @param WP_User|WP_Error|null $user Result of the earlier authenticate callbacks. / それまでの authenticate コールバックの結果。
	 * @return WP_User|WP_Error|null The result. / 結果。
	 */
	public static function filter_authenticate( $user ) {
		if ( ! is_wp_error( $user ) || ! self::is_enabled( 'login_messages' ) ) {
			return $user;
		}

		return ACGD_Invalid_Credentials::replace_codes( $user, self::REVEALING_LOGIN_CODES, ACGD_Invalid_Credentials::get_login_error() );
	}

	/**
	 * Replaces the REST API authentication errors that tell whether an account exists (item f).
	 * アカウントの有無が分かってしまう REST API の認証エラーを差し替える（f）。
	 *
	 * Application passwords (Basic authentication) are checked in determine_current_user, not in authenticate,
	 * so filter_authenticate() never sees them. Core returns the failure from rest_application_password_check_errors()
	 * at priority 90, but only when the current user was already determined by then; when something determines
	 * it earlier, an unknown account and a wrong password come back as different 401 errors. This filter
	 * runs after core's checks and replaces those codes with the common error (status 401), whoever
	 * determined the user and when.
	 * アプリケーションパスワード（Basic 認証）は authenticate ではなく determine_current_user で確かめられるので、
	 * filter_authenticate() には来ない。本体は rest_application_password_check_errors()（優先度 90）でその失敗を
	 * 返すが、それはその時点で現在のユーザーが確定済みのときだけで、何かがそれより前に確定させると、
	 * アカウントが無いときとパスワードが違うときで別々の 401 が返る。このフィルタは本体の確認の後で動き、
	 * 誰がいつユーザーを確定させたかに関係なく、それらのコードを共通のエラー（ステータス 401）に差し替える。
	 *
	 * @param WP_Error|null|true|mixed $result Result of the earlier rest_authentication_errors callbacks. / それまでのコールバックの結果。
	 * @return WP_Error|null|true|mixed The result. / 結果。
	 */
	public static function filter_rest_authentication_errors( $result ) {
		if ( ! is_wp_error( $result ) || ! self::is_enabled( 'login_messages' ) ) {
			return $result;
		}

		return ACGD_Invalid_Credentials::replace_codes( $result, self::REVEALING_REST_CODES, ACGD_Invalid_Credentials::get_rest_error() );
	}

	/**
	 * Makes a lost password request show the same result whether or not the account exists (item f).
	 * パスワード再発行の結果を、アカウントの有無にかかわらず同じにする（f）。
	 *
	 * For an existing account, wp-login.php redirects to redirect_to or wp-login.php?checkemail=confirm.
	 * For an unknown one, retrieve_password() returns invalidcombo (username) or invalid_email (address);
	 * for an existing account whose reset is not allowed, it returns no_password_reset. The form is then
	 * shown again with that error. Changing only the message would still tell them apart, so:
	 * - When those codes are the only errors, the request goes through the same gate as an existing account
	 *   first: the allow_password_reset filter, which get_password_reset_key() applies only after an account
	 *   was found. If a callback returns a WP_Error there (another plugin's CAPTCHA, for example), that error
	 *   is shown in their place, exactly as it would be for an existing account. Otherwise the request takes
	 *   the same redirect as a successful one. No email is sent, since there is no account to send it to.
	 * - When other errors are mixed in (an empty field, a CAPTCHA added on lostpassword_post), only those
	 *   codes are removed and the other errors are shown as they are, since an existing account would show
	 *   the other errors alone.
	 * retrieve_password_email_failure (the email could not be sent) is left alone: it is an operational signal
	 * that the site owner needs to see. The lost_password action fires in wp-login.php only, after
	 * retrieve_password() and before any output, with the final errors; other forms that call
	 * retrieve_password(), such as WooCommerce My Account, are not touched.
	 *
	 * アカウントがあるとき、wp-login.php は redirect_to か wp-login.php?checkemail=confirm へ転送する。
	 * 無いときは retrieve_password() が invalidcombo（ユーザー名）か invalid_email（メールアドレス）を、
	 * 再発行が許可されていない存在するアカウントには no_password_reset を返し、エラー付きでフォームを出し直す。
	 * 文言を変えるだけでは区別がつくので、次のようにする。
	 * - それらのコードだけのときは、先に、存在するアカウントが通るのと同じ関門を通す。get_password_reset_key() が
	 *   アカウントを見つけた後にだけ適用する allow_password_reset フィルタである。そこでコールバックが WP_Error を
	 *   返したら（他プラグインの画像認証など）、存在するアカウントのときと同じくそのエラーを代わりに出す。
	 *   そうでなければ、成功時と同じ転送を行う。送る相手のアカウントが無いので、メールは送らない。
	 * - 他のエラー（空欄、lostpassword_post で足された画像認証）が混ざっているときは、それらのコードだけを取り除き、
	 *   他のエラーはそのまま出す。存在するアカウントなら他のエラーだけが出るため。
	 * retrieve_password_email_failure（メールを送れなかった）には触れない。サイトの管理者が知る必要のある運用上の
	 * 合図だから。lost_password アクションは wp-login.php の中でだけ、retrieve_password() の後・出力の前に、
	 * 最終的なエラーを渡して発火する。retrieve_password() を呼ぶ他のフォーム（WooCommerce のマイアカウントなど）には触れない。
	 *
	 * @param WP_Error $errors Errors of the lost password request, changed in place. / パスワード再発行のエラー（その場で変更する）。
	 * @return void
	 */
	public static function maybe_redirect_lost_password( $errors ) {
		if ( ! is_wp_error( $errors ) || ! self::is_enabled( 'login_messages' ) ) {
			return;
		}

		// Only when the form was submitted, the same condition as $http_post in wp-login.php.
		// フォームが送信されたときだけ。wp-login.php の $http_post と同じ条件。
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			return;
		}

		$codes     = $errors->get_error_codes();
		$revealing = array_values( array_intersect( $codes, self::REVEALING_RESET_CODES ) );
		if ( ! $revealing ) {
			return;
		}

		// Mixed with other errors: drop only the revealing codes and show the rest.
		// 他のエラーと混ざっている：名前の有無が分かるコードだけを落とし、残りを出す。
		if ( array_diff( $codes, self::REVEALING_RESET_CODES ) ) {
			self::remove_error_codes( $errors, $revealing );
			return;
		}

		// Only revealing codes: the gate an existing account goes through comes first.
		// 名前の有無が分かるコードだけ：存在するアカウントが通る関門を先に通す。
		$gate = self::check_password_reset_gate();
		if ( is_wp_error( $gate ) ) {
			self::remove_error_codes( $errors, $revealing );
			ACGD_Invalid_Credentials::copy_errors( $gate, $errors );
			return;
		}

		/*
		 * The same destination as the success branch of wp-login.php. wp_safe_redirect() validates it
		 * exactly as it does there. The lost password form has no nonce in core either.
		 * wp-login.php の成功時と同じ転送先。wp_safe_redirect() がそこと同じく検証する。
		 * 本体のパスワード再発行フォームにも nonce は無い。
		 */
		$redirect_to = 'wp-login.php?checkemail=confirm';
		if ( ! empty( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core's form has no nonce; see above.
			$redirect_to = wp_unslash( $_REQUEST['redirect_to'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by wp_safe_redirect(), as in core.
		}

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Applies the gate that get_password_reset_key() applies to an existing account (item f).
	 * get_password_reset_key() が存在するアカウントに適用する関門を通す（f）。
	 *
	 * The same filter with the same default (true) as wp_is_password_reset_allowed_for_user(). There is no
	 * account, so the user ID is 0. A callback that cannot handle ID 0 and throws is treated as allowing the
	 * reset, so the request falls back to the success redirect instead of a fatal error.
	 * wp_is_password_reset_allowed_for_user() と同じフィルタを、同じ既定値（true）で通す。アカウントが無いので
	 * ユーザー ID は 0。ID 0 を扱えずに例外を投げるコールバックは「許可」とみなし、致命的エラーにせず成功時の転送に倒す。
	 *
	 * @return bool|WP_Error Result of the filter: a WP_Error means the reset would be refused with that error.
	 *                       フィルタの結果。WP_Error は、そのエラーで再発行が拒否されることを意味する。
	 */
	public static function check_password_reset_gate() {
		try {
			return apply_filters( 'allow_password_reset', true, 0 );
		} catch ( Throwable $e ) {
			return true;
		}
	}

	/**
	 * Removes the given codes, with their messages and data, from an error.
	 * エラーから、指定したコードを文言・データごと取り除く。
	 *
	 * @param WP_Error $errors Error, changed in place. / エラー（その場で変更する）。
	 * @param string[] $codes  Codes to remove. / 取り除くコード。
	 * @return void
	 */
	private static function remove_error_codes( $errors, $codes ) {
		foreach ( $codes as $code ) {
			$errors->remove( $code );
		}
	}

	/*-------------------------------------------*/
	/* h: public names / 公開される名前
	/*-------------------------------------------*/

	/**
	 * Counts users whose display name or nickname is the same as their login name (item h). Read only.
	 * 表示名またはニックネームがログイン名と同じユーザーを数える（h）。読み取りのみ。
	 *
	 * Only the count, for the dashboard widget, which shows it to users who can manage options. It runs on
	 * every view of the dashboard, so the query is shaped to stay light on sites with many users.
	 * WP_User_Query cannot compare two columns, so this is one direct query. On multisite it keeps to the
	 * users of the current site, as WP_User_Query does.
	 * The display name part and the nickname part are separate queries joined with UNION:
	 * - The display name part compares two columns of the users table, which needs one pass over that table.
	 * - The nickname part starts from the nickname rows (the meta_key index of usermeta) and looks up each
	 *   user by ID. A LEFT JOIN with OR in one WHERE clause instead makes MySQL walk every user and look up
	 *   the nickname one user at a time, which grows with the number of users (seconds at 100,000 users).
	 * - UNION (not UNION ALL) removes duplicate IDs, so a user whose display name and nickname both match
	 *   is counted once.
	 * find_users_with_login_as_public_name() picks its IDs with the same UNION and the same site condition.
	 * ダッシュボードのウィジェット（manage_options のユーザーにだけ出す）のための、件数だけの問い合わせ。
	 * ダッシュボードを開くたびに動くので、ユーザーの多いサイトでも軽く済む形にしている。
	 * WP_User_Query は列どうしを比べられないので、1本の直接のクエリにする。マルチサイトでは
	 * WP_User_Query と同じく現在のサイトのユーザーに絞る。
	 * 表示名の部分とニックネームの部分を別々の問い合わせにし、UNION でつなぐ。
	 * - 表示名の部分は users テーブルの2つの列を比べるので、そのテーブルを1回たどる。
	 * - ニックネームの部分は nickname の行（usermeta の meta_key の索引）から始め、ユーザーを ID で引く。
	 *   1つの WHERE 句で LEFT JOIN と OR を使うと、MySQL は全ユーザーをたどって1人ずつニックネームを引く形になり、
	 *   ユーザー数に比例して重くなる（10万人で数秒）。
	 * - UNION（UNION ALL ではない）は重複する ID を取り除くので、表示名とニックネームの両方が一致するユーザーも1人と数える。
	 * find_users_with_login_as_public_name() は、同じ UNION・同じサイトの条件で ID を選ぶ。
	 *
	 * @return int Number of matching users. / 該当するユーザーの数。
	 */
	public static function count_users_with_login_as_public_name() {
		global $wpdb;

		// On a single site the EXISTS clause is skipped by passing 0. / シングルサイトでは 0 を渡して EXISTS を飛ばす。
		$is_multisite = is_multisite() ? 1 : 0;
		$caps_key     = $wpdb->get_blog_prefix() . 'capabilities';

		// Only table names are put into the SQL text; both values go through placeholders.
		// SQL の文字列に埋めるのはテーブル名だけで、値は2つともプレースホルダで渡す。
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A column-to-column comparison has no API. Admin screens of manage_options users only, so no cache.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
					SELECT u.ID FROM {$wpdb->users} AS u WHERE u.display_name = u.user_login
					UNION
					SELECT n.user_id AS ID FROM {$wpdb->usermeta} AS n
					INNER JOIN {$wpdb->users} AS u2 ON ( u2.ID = n.user_id )
					WHERE n.meta_key = 'nickname' AND n.meta_value = u2.user_login
				) AS t
				WHERE ( %d = 0 OR EXISTS ( SELECT 1 FROM {$wpdb->usermeta} AS c WHERE c.user_id = t.ID AND c.meta_key = %s ) )",
				$is_multisite,
				$caps_key
			)
		);
		// phpcs:enable

		return $total;
	}

	/**
	 * Finds users whose display name or nickname is the same as their login name (item h). Read only.
	 * 表示名またはニックネームがログイン名と同じユーザーを探す（h）。読み取りのみ。
	 *
	 * The count comes from count_users_with_login_as_public_name(). The rows come from one direct query in two
	 * steps: the inner part picks up to $limit IDs, in ID order, with the same UNION and the same site condition
	 * as the count (see there for why it is a UNION); the outer part reads the rows of those IDs only.
	 * The outer WHERE clause repeats the match, so that a user with more than one nickname row (core keeps
	 * one) gets only the rows that match, as a single WHERE clause over all rows would give.
	 * It runs only on the settings screen.
	 * 件数は count_users_with_login_as_public_name() から取る。行は2段の1本の直接のクエリで取る。
	 * 内側で、件数と同じ UNION・同じサイトの条件で ID を ID 順に最大 $limit 件選び（UNION にする理由はそちらを参照）、
	 * 外側でその ID の行だけを読む。
	 * 外側の WHERE 句で一致の条件をもう一度掛けるので、nickname の行を複数持つユーザー（本体は1行しか作らない）でも、
	 * 全行に1つの WHERE 句を掛けた場合と同じく、一致した行だけになる。
	 * 設定画面でだけ動く。
	 *
	 * @param int $limit Maximum number of users to return. / 返すユーザーの上限。
	 * @return array {
	 *     @type int      $total Number of matching users. / 該当するユーザーの数。
	 *     @type object[] $users Up to $limit rows with ID, user_login, display_name, nickname, and the flags
	 *                           display_matches / nickname_matches ("1" or "0"), ordered by ID. The flags come
	 *                           from the same SQL comparisons as the query that picks the users, so letter
	 *                           case is ignored there too, as in the login itself.
	 *                           ID・user_login・display_name・nickname と、判定 display_matches / nickname_matches
	 *                           （"1" か "0"）を持つ行（最大 $limit 件、ID 順）。判定はユーザーを選ぶ問い合わせと同じ
	 *                           SQL の比較から取るので、ログインそのものと同じく大文字小文字を区別しない。
	 * }
	 */
	public static function find_users_with_login_as_public_name( $limit = 100 ) {
		global $wpdb;

		// On a single site the EXISTS clause is skipped by passing 0. / シングルサイトでは 0 を渡して EXISTS を飛ばす。
		$is_multisite = is_multisite() ? 1 : 0;
		$caps_key     = $wpdb->get_blog_prefix() . 'capabilities';

		$total = self::count_users_with_login_as_public_name();

		// Only table names are put into the SQL text; all three values go through placeholders.
		// SQL の文字列に埋めるのはテーブル名だけで、値は3つともプレースホルダで渡す。
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A column-to-column comparison has no API. Settings screen only, so no cache.
		$users = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT ru.ID, ru.user_login, ru.display_name, rn.meta_value AS nickname,
					( ru.display_name = ru.user_login ) AS display_matches,
					( rn.meta_value = ru.user_login ) AS nickname_matches
				FROM (
					SELECT t.ID FROM (
						SELECT u.ID FROM {$wpdb->users} AS u WHERE u.display_name = u.user_login
						UNION
						SELECT n.user_id AS ID FROM {$wpdb->usermeta} AS n
						INNER JOIN {$wpdb->users} AS u2 ON ( u2.ID = n.user_id )
						WHERE n.meta_key = 'nickname' AND n.meta_value = u2.user_login
					) AS t
					WHERE ( %d = 0 OR EXISTS ( SELECT 1 FROM {$wpdb->usermeta} AS c WHERE c.user_id = t.ID AND c.meta_key = %s ) )
					ORDER BY t.ID ASC
					LIMIT %d
				) AS ids
				INNER JOIN {$wpdb->users} AS ru ON ( ru.ID = ids.ID )
				LEFT JOIN {$wpdb->usermeta} AS rn ON ( rn.user_id = ru.ID AND rn.meta_key = 'nickname' )
				WHERE ( ru.display_name = ru.user_login OR rn.meta_value = ru.user_login )
				ORDER BY ru.ID ASC",
				$is_multisite,
				$caps_key,
				max( 1, (int) $limit )
			)
		);
		// phpcs:enable

		return array(
			'total' => $total,
			'users' => is_array( $users ) ? $users : array(),
		);
	}
}
