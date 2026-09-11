<?php
/**
 * Access Restriction: IP restriction and the shared foundation (docs/spec.md 5.1, 5.2, 5.4, 5.5).
 * アクセス制限：IP 制限と共通の土台（docs/spec.md 5.1・5.2・5.4・5.5）。
 *
 * BASIC authentication itself (docs/spec.md 5.3) is a later issue (#4, "C"). The data this class stores
 * already has room for a 'basic' mode everywhere a mode is stored, so that #4 does not need to migrate
 * anything; only the enforcement and the settings screen fields for it are missing here.
 * BASIC 認証そのもの（docs/spec.md 5.3）は後続の issue（#4・「C」）で作る。モードを保存するすべての場所に
 * 'basic' を持てる形にしてあるので、#4 で移し替えは要らない。ここに無いのは、実際の判定と設定画面の項目だけ。
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

	const MODE_FOLLOW = 'follow';
	const MODE_NONE   = 'none';
	const MODE_IP     = 'ip';
	const MODE_BASIC  = 'basic';

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
		add_action( 'admin_init', array( __CLASS__, 'check_access_on_request' ) );
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

		foreach ( get_users() as $user ) {
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
	 * Tells whether the current user's own access would still be allowed under prospective role modes and
	 * site IP list. Used by save-time check 2 (docs/spec.md 5.1) when saving the Access Restriction tab,
	 * which can change both at once.
	 * 現在のユーザー自身のアクセスが、保存前の権限モードとサイトの IP 一覧のもとでも成り立つかを返す。
	 * 「アクセス制限」タブの保存時のチェック2（docs/spec.md 5.1）に使う。このタブは両方を同時に変えうる。
	 *
	 * A 'basic' requirement always fails this check: BASIC authentication is not enforced yet (issue #4), so
	 * there is no way to confirm the current request carries the right credentials, and failing safe means
	 * refusing the save rather than accepting an unverifiable one.
	 * 'basic' が求められる場合はこのチェックを必ず不成立にする。BASIC 認証はまだ判定していない（issue #4）ため、
	 * 今のリクエストが正しい資格情報を持っているかを確かめる手段が無く、安全側に倒して
	 * 確認できない設定は保存させない。
	 *
	 * @param string[] $role_modes       Prospective role => mode. / 保存しようとしている 権限 => モード。
	 * @param string[] $site_ip_entries  Prospective site-wide IP entries, already parsed (parse_ip_list()). / 保存しようとしているサイトの IP 一覧（parse_ip_list() 済み）。
	 * @return bool Whether the current user would still get in. / 現在のユーザーがなお入れるか。
	 */
	public static function current_user_still_allowed( $role_modes, $site_ip_entries ) {
		$current = wp_get_current_user();
		if ( ! $current instanceof WP_User || 0 === $current->ID ) {
			return true; // No authenticated context; nothing of "oneself" to check. / 認証されたユーザーが無く、確かめる「本人」がいない。
		}

		$modes = self::compute_effective_modes( $current, $role_modes );
		if ( in_array( self::MODE_BASIC, $modes, true ) ) {
			return false;
		}
		if ( in_array( self::MODE_IP, $modes, true ) ) {
			$remote  = self::get_remote_addr();
			$entries = array_merge( $site_ip_entries, self::parse_ip_list( self::get_user_ip_text( $current->ID ) ) );

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
	 * Records a fault of this feature (docs/spec.md 5.5: "アクセス制限を止めて通す").
	 * この機能の故障を記録する（docs/spec.md 5.5「アクセス制限を止めて通す」）。
	 *
	 * @param string $message Exception message. / 例外のメッセージ。
	 * @return void
	 */
	private static function record_fault( $message ) {
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

			// MODE_BASIC is not enforced here yet; see the class docblock. / MODE_BASIC はまだここで判定しない（クラスの docblock を参照）。
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
	 * Judges IP restriction on every REST API request whose current user is already determined (docs/spec.md 5.2).
	 * See REST_AUTHENTICATION_PRIORITY for why this priority. Denies by discarding the session and letting the
	 * request continue as anonymous, exactly like check_access_on_request(); it does not itself return a 403.
	 * 現在のユーザーが確定している REST API のリクエストで、IP 制限を判定する（docs/spec.md 5.2）。
	 * この優先度の理由は REST_AUTHENTICATION_PRIORITY を参照。拒否するときは check_access_on_request() と
	 * 同じくセッションを破棄して未ログインとして続けさせるだけで、このフィルタ自身は 403 を返さない。
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
			if ( ! $user || ! in_array( self::MODE_IP, self::get_effective_modes( $user ), true ) ) {
				return $result;
			}

			$remote = self::get_remote_addr();
			if ( self::is_ip_allowed_for_user( $user_id, $remote ) ) {
				return $result;
			}

			self::log_denial( $user_id, $remote, 'rest' );
			self::destroy_current_session_and_continue();

			return $result;
		} catch ( Throwable $e ) {
			self::record_fault( $e->getMessage() );

			return $result; // Fail open (docs/spec.md 5.5). / 止めて通す（docs/spec.md 5.5）。
		}
	}

	/**
	 * Judges IP restriction on every access after login, outside the REST API (docs/spec.md 5.2). Hooked to
	 * admin_init and template_redirect; see init() for why those two cover the front end, admin screens,
	 * admin-ajax.php and admin-post.php.
	 * ログイン後の毎回のアクセスで、REST API 以外を対象に IP 制限を判定する（docs/spec.md 5.2）。
	 * admin_init と template_redirect に掛ける。この2つがフロント・管理画面・admin-ajax.php・
	 * admin-post.php をなぜ覆うかは init() を参照。
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
			if ( ! $user || ! in_array( self::MODE_IP, self::get_effective_modes( $user ), true ) ) {
				return;
			}

			$remote = self::get_remote_addr();
			if ( self::is_ip_allowed_for_user( $user_id, $remote ) ) {
				return;
			}

			self::log_denial( $user_id, $remote, 'session' );
			self::destroy_current_session_and_continue();
		} catch ( Throwable $e ) {
			self::record_fault( $e->getMessage() );
			// Fail open: nothing to undo, restriction is simply not applied this request (docs/spec.md 5.5). / 止めて通す。何も取り消さず、この回は制限を適用しないだけ（docs/spec.md 5.5）。
		}
	}
}
