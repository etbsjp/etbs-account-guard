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
	 * @var string[]
	 */
	const REVEALING_LOGIN_CODES = array( 'invalid_username', 'invalid_email', 'incorrect_password' );

	/**
	 * Lost password error codes that mean "no such account" (item f).
	 * パスワード再発行で「アカウントが無い」を意味するエラーのコード（f）。
	 *
	 * @var string[]
	 */
	const UNKNOWN_ACCOUNT_RESET_CODES = array( 'invalidcombo', 'invalid_email' );

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

		// f: login and lost password messages. / ログインとパスワード再発行の文言。
		add_filter( 'authenticate', array( __CLASS__, 'filter_authenticate' ), self::AUTHENTICATE_PRIORITY );
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
	 * which is what tells whether a name exists.
	 * This filter runs in WP::handle_404(), before template_redirect, so redirect_canonical() never sees
	 * these requests as author archives and never redirects them to /author/{name}/.
	 *
	 * c: ユーザーのプロバイダが無いと、WP_Sitemaps::render_sitemaps() は sitemap=users に対して何もせず戻り、
	 * その後はメインクエリとテーマ次第になる。ここで 404 を決めて、それに頼らない。
	 * g: /author/{名前}/・そのフィード・?author_name=・基本パーマリンクの ?author= はすべて is_author() なので
	 * まとめて対象になる。本体は投稿の無い投稿者でも存在すれば 200、無ければ 404 を返し、それで名前の有無が分かる。
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
		$is_author_archive = self::is_enabled( 'author_archive' ) && $wp_query->is_author();
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
	 * The admin screens (the author filter of edit.php), the REST API and admin-ajax never reach
	 * template_redirect; the explicit checks below state that and keep it true if this is ever moved.
	 * A 302 is used because the switch can be turned off, and a cached 301 would outlive it.
	 *
	 * template_redirect の優先度 1 で動く。優先度 10 の redirect_canonical() が動くと
	 * Location: /author/{名前}/ を返してしまうため、それより前に処理する。本体が転送せずに投稿者ページを
	 * 出す基本パーマリンクも対象になる。
	 * 数字だけでなくパラメータの値を問わず転送する。WP_Query は "1abc" のような値も投稿者の絞り込みとして扱うが、
	 * redirect_canonical() が転送するのは数字だけだから。
	 * 管理画面（edit.php の投稿者での絞り込み）・REST API・admin-ajax は template_redirect に来ない。
	 * 下の明示的な判定はそれを書き表し、将来この処理を移しても成り立つようにするためのもの。
	 * スイッチを OFF にできるので 302 にする。301 だとブラウザにキャッシュされ、OFF にした後も残る。
	 *
	 * @return void
	 */
	public static function maybe_redirect_author_query() {
		if ( ! self::is_enabled( 'author_query' ) ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Presence check only. Nothing from the request is used. / 有無を見るだけで、リクエストの値は使わない。
		if ( ! isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of a public query variable.
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
	 * @param WP_User|WP_Error|null $user Result of the earlier authenticate callbacks. / それまでの authenticate コールバックの結果。
	 * @return WP_User|WP_Error|null The result. / 結果。
	 */
	public static function filter_authenticate( $user ) {
		if ( ! is_wp_error( $user ) || ! self::is_enabled( 'login_messages' ) ) {
			return $user;
		}

		return self::replace_revealing_login_errors( $user );
	}

	/**
	 * Returns the error with invalid_username / invalid_email / incorrect_password replaced by the common error.
	 * invalid_username / invalid_email / incorrect_password を共通のエラーに差し替えたエラーを返す。
	 *
	 * Any other code (empty fields, CAPTCHA, login lockout and so on from other plugins) is kept with its
	 * messages and data, in its original order, so that staff still see why they could not log in.
	 * The common error comes last. An error without any of the three codes is returned as it is.
	 *
	 * それ以外のコード（空欄、他プラグインの画像認証・ログインロックなど）は、文言・データとともに元の順で残す。
	 * 職員の方が「なぜ入れないのか」を読めるようにするため。共通のエラーは最後に置く。
	 * 3つのコードをどれも含まないエラーはそのまま返す。
	 *
	 * @param WP_Error $error Login error. / ログインエラー。
	 * @return WP_Error The error to show. / 表示するエラー。
	 */
	public static function replace_revealing_login_errors( $error ) {
		$codes = $error->get_error_codes();
		if ( ! array_intersect( $codes, self::REVEALING_LOGIN_CODES ) ) {
			return $error;
		}

		$result = new WP_Error();
		foreach ( $codes as $code ) {
			// Drop the revealing ones; the common error replaces them below. / 名前の有無が分かるものは落とし、下で共通のエラーに置き換える。
			if ( in_array( $code, self::REVEALING_LOGIN_CODES, true ) ) {
				continue;
			}

			foreach ( $error->get_error_messages( $code ) as $message ) {
				$result->add( $code, $message );
			}
			$data = $error->get_error_data( $code );
			if ( null !== $data ) {
				$result->add_data( $data, $code );
			}
		}

		$common = self::get_invalid_credentials_error();
		$result->add( $common->get_error_code(), $common->get_error_message() );

		return $result;
	}

	/**
	 * Returns the common login error, the same for an unknown account and a wrong password.
	 * アカウントが無いときとパスワードが違うときで同じ、共通のログインエラーを返す。
	 *
	 * The code is incorrect_password on purpose. wp-login.php branches on the error code: it refills the
	 * username field only for incorrect_password (and empty_password), and clears it for invalid_username.
	 * The code must therefore be the same in both cases, or the screens would differ. incorrect_password
	 * gives the behavior that suits the common case of staff who mistyped the password: the username is
	 * kept, the form shakes and the focus goes to the password field.
	 * The link to the lost password screen is part of the message, as in core's incorrect_password message.
	 *
	 * コードは意図的に incorrect_password にしている。wp-login.php はエラーコードで分岐し、
	 * ユーザー名欄を incorrect_password（と empty_password）のときだけ入力済みで戻し、
	 * invalid_username のときは空にする。したがって両方の場合でコードが同じでなければ画面が変わってしまう。
	 * incorrect_password にすると、よくある「職員の方がパスワードを打ち間違えた」場合に合う動き
	 * （ユーザー名が残る・フォームが揺れる・パスワード欄にフォーカスが移る）になる。
	 * パスワード再発行へのリンクは、本体の incorrect_password の文言と同じく文言の中に含める。
	 *
	 * @return WP_Error The common error. / 共通のエラー。
	 */
	public static function get_invalid_credentials_error() {
		$text = sprintf(
			'<strong>%1$s</strong> %2$s',
			esc_html__( 'Error:', 'etbs-account-guard' ),
			esc_html__( 'The username or password you entered is incorrect.', 'etbs-account-guard' )
		);
		$link = '<a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( 'Lost your password?', 'etbs-account-guard' ) . '</a>';

		return new WP_Error( 'incorrect_password', $text . ' ' . $link );
	}

	/**
	 * Sends a lost password request for an unknown account to the same screen as a known one (item f).
	 * アカウントが無いときのパスワード再発行を、あるときと同じ画面へ進める（f）。
	 *
	 * For a known account, wp-login.php redirects to redirect_to or wp-login.php?checkemail=confirm.
	 * For an unknown one, retrieve_password() returns invalidcombo (username) or invalid_email (address),
	 * and the form is shown again with the error. Changing only the message would still tell the two apart,
	 * so this takes the same redirect instead. No email is sent, since there is no account to send it to.
	 * The lost_password action fires in wp-login.php only, after retrieve_password() and before any output,
	 * with the final errors. Requests that also carry other errors (an empty field, another plugin's CAPTCHA)
	 * are left alone, as are other forms that call retrieve_password(), such as WooCommerce My Account.
	 *
	 * アカウントがあるとき、wp-login.php は redirect_to か wp-login.php?checkemail=confirm へ転送する。
	 * 無いときは retrieve_password() が invalidcombo（ユーザー名）か invalid_email（メールアドレス）を返し、
	 * エラー付きでフォームを出し直す。文言を変えるだけでは両者の区別がつくので、同じ転送を行う。
	 * 送る相手のアカウントが無いので、メールは送らない。
	 * lost_password アクションは wp-login.php の中でだけ、retrieve_password() の後・出力の前に、最終的な
	 * エラーを渡して発火する。他のエラー（空欄・他プラグインの画像認証）が混ざっているリクエストや、
	 * retrieve_password() を呼ぶ他のフォーム（WooCommerce のマイアカウントなど）には触れない。
	 *
	 * @param WP_Error $errors Errors of the lost password request. / パスワード再発行のエラー。
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

		// Only when every error means "no such account". / すべてのエラーが「アカウントが無い」のときだけ。
		$codes = $errors->get_error_codes();
		if ( empty( $codes ) || array_diff( $codes, self::UNKNOWN_ACCOUNT_RESET_CODES ) ) {
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

	/*-------------------------------------------*/
	/* h: public names / 公開される名前
	/*-------------------------------------------*/

	/**
	 * Finds users whose display name or nickname is the same as their login name (item h). Read only.
	 * 表示名またはニックネームがログイン名と同じユーザーを探す（h）。読み取りのみ。
	 *
	 * WP_User_Query cannot compare two columns, so this is one direct query. On multisite it keeps to the
	 * users of the current site, as WP_User_Query does. It runs only on the settings screen.
	 * WP_User_Query は列どうしを比べられないので、1本の直接のクエリにする。マルチサイトでは
	 * WP_User_Query と同じく現在のサイトのユーザーに絞る。設定画面でだけ動く。
	 *
	 * @param int $limit Maximum number of users to return. / 返すユーザーの上限。
	 * @return array {
	 *     @type int      $total Number of matching users. / 該当するユーザーの数。
	 *     @type object[] $users Up to $limit rows with ID, user_login, display_name, nickname, and the flags
	 *                           display_matches / nickname_matches ("1" or "0"), ordered by ID. The flags come
	 *                           from the same SQL comparison as the WHERE clause, so letter case is ignored
	 *                           there too, as in the login itself.
	 *                           ID・user_login・display_name・nickname と、判定 display_matches / nickname_matches
	 *                           （"1" か "0"）を持つ行（最大 $limit 件、ID 順）。判定は WHERE 句と同じ SQL の比較から
	 *                           取るので、ログインそのものと同じく大文字小文字を区別しない。
	 * }
	 */
	public static function find_users_with_login_as_public_name( $limit = 100 ) {
		global $wpdb;

		// On a single site the EXISTS clause is skipped by passing 0. / シングルサイトでは 0 を渡して EXISTS を飛ばす。
		$is_multisite = is_multisite() ? 1 : 0;
		$caps_key     = $wpdb->get_blog_prefix() . 'capabilities';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A column-to-column comparison has no API. Settings screen only, so no cache.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT u.ID )
				FROM {$wpdb->users} AS u
				LEFT JOIN {$wpdb->usermeta} AS n ON ( n.user_id = u.ID AND n.meta_key = 'nickname' )
				WHERE ( u.display_name = u.user_login OR n.meta_value = u.user_login )
				AND ( %d = 0 OR EXISTS ( SELECT 1 FROM {$wpdb->usermeta} AS c WHERE c.user_id = u.ID AND c.meta_key = %s ) )",
				$is_multisite,
				$caps_key
			)
		);

		$users = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT u.ID, u.user_login, u.display_name, n.meta_value AS nickname,
					( u.display_name = u.user_login ) AS display_matches,
					( n.meta_value = u.user_login ) AS nickname_matches
				FROM {$wpdb->users} AS u
				LEFT JOIN {$wpdb->usermeta} AS n ON ( n.user_id = u.ID AND n.meta_key = 'nickname' )
				WHERE ( u.display_name = u.user_login OR n.meta_value = u.user_login )
				AND ( %d = 0 OR EXISTS ( SELECT 1 FROM {$wpdb->usermeta} AS c WHERE c.user_id = u.ID AND c.meta_key = %s ) )
				ORDER BY u.ID ASC
				LIMIT %d",
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
