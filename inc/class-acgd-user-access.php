<?php
/**
 * Access Restriction: the per-user screens (docs/spec.md 5.6) — the user edit screen section and the
 * Access Restriction column of the Users list.
 * アクセス制限のユーザーごとの画面（docs/spec.md 5.6）——ユーザー編集画面の区画と、
 * ユーザー一覧の「アクセス制限」列。
 *
 * Both are limited to manage_options, and the section is never shown on one's own profile: showing it there
 * would tell a user where they are and are not allowed to connect from, which is exactly the hint
 * docs/spec.md 5.6 says to withhold ("許可されている場所の手がかりを与えない"). Hooking edit_user_profile
 * (fired only when viewing someone else's profile) rather than show_user_profile (one's own) enforces this
 * structurally, not just with a capability check.
 * どちらも manage_options に限る。区画は本人のプロフィールには出さない。出してしまうと、
 * 自分がどこから接続できる・できないかの手がかりを本人に与えてしまう（docs/spec.md 5.6
 * 「許可されている場所の手がかりを与えない」）。show_user_profile（本人用）ではなく
 * edit_user_profile（他人のプロフィールを開いたときだけ発火）に掛けることで、権限チェックだけでなく
 * 構造的にもこれを守る。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User edit screen section and Users list column for Access Restriction.
 * アクセス制限の、ユーザー編集画面の区画とユーザー一覧の列。
 */
class ACGD_User_Access {

	/**
	 * Nonce action for the user edit screen fields. / ユーザー編集画面の項目の nonce アクション。
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'acgd_user_access';

	/**
	 * Nonce field name. / nonce のフィールド名。
	 *
	 * @var string
	 */
	const NONCE_NAME = 'acgd_user_access_nonce';

	/**
	 * HTML id of the section heading. / 区画の見出しの HTML の id。
	 *
	 * @var string
	 */
	const SECTION_ID = 'acgd-user-access';

	/**
	 * Users list column name. / ユーザー一覧の列名。
	 *
	 * @var string
	 */
	const COLUMN = 'acgd_access';

	/**
	 * Validation error to show on the next render of the profile screen, or an empty string.
	 * Set only within one request by save_fields(), and read by append_pending_error() further along the
	 * same request; there is no cross-request state here.
	 * プロフィール画面を出し直すときに表示する検証エラー（無ければ空文字）。save_fields() が同じリクエストの
	 * 中でだけ設定し、後続の append_pending_error() が読む。リクエストをまたぐ状態は持たない。
	 *
	 * @var string
	 */
	private static $pending_error = '';

	/**
	 * Prefix of the transient that holds a rejected submission of this screen's fields, so the profile page
	 * can be redisplayed with what the admin actually typed instead of the unchanged saved value (UX review).
	 * Keyed by both the submitting admin and the target user's ID (one admin can be mid-edit on more than
	 * one user's screen; edit_user.php redirects to a fresh GET on failure too, so $_POST does not survive).
	 * Expires on its own after RESUBMIT_TTL, so it needs no entry in uninstall.php. See the matching constant
	 * in ACGD_Settings for the Access Restriction tab's own version of this mechanism.
	 * この画面の項目で拒否された送信内容を保持する transient の接頭辞。保存済みの値ではなく、管理者が
	 * 実際に入力した内容でプロフィール画面を出し直すために使う（UX レビュー）。送信した管理者と、
	 * 編集対象のユーザー ID の両方で分ける（1人の管理者が複数ユーザーの編集画面を同時に開きうる。
	 * edit_user.php も失敗時は新しい GET へ転送するため $_POST は残らない）。RESUBMIT_TTL で自然に消えるため、
	 * uninstall.php への記載は不要。同じ仕組みの「アクセス制限」タブ版は ACGD_Settings の対応する定数を参照。
	 *
	 * @var string
	 */
	const RESUBMIT_TRANSIENT_PREFIX = 'acgd_user_resubmit_';

	/**
	 * How long a rejected submission is kept for redisplay. See ACGD_Settings::RESUBMIT_TTL for the reasoning.
	 * 拒否された送信内容を残しておく時間。理由は ACGD_Settings::RESUBMIT_TTL を参照。
	 *
	 * @var int
	 */
	const RESUBMIT_TTL = MINUTE_IN_SECONDS;

	/**
	 * Registers the hooks. / フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		// edit_user_profile only: never fires for one's own profile. See the class docblock.
		// edit_user_profile のみ：本人のプロフィールでは発火しない（クラスの docblock を参照）。
		add_action( 'edit_user_profile', array( __CLASS__, 'render_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_fields' ) );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'append_pending_error' ) );

		add_filter( 'manage_users_columns', array( __CLASS__, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );
	}

	/*-------------------------------------------*/
	/* User edit screen / ユーザー編集画面
	/*-------------------------------------------*/

	/**
	 * Prints the Access Restriction section of the user edit screen. / ユーザー編集画面の「アクセス制限」の区画を出力する。
	 *
	 * Redisplays a rejected submission (UX review) rather than the saved value, when there is one for this
	 * admin and this target user (get_resubmit_data()), so a save-time check failure does not also discard
	 * what the admin had just typed.
	 * 拒否された送信内容があれば（get_resubmit_data()）、保存済みの値ではなくそちらを出し直す（UX レビュー）。
	 * 保存時のチェックに落ちても、管理者が入力したばかりの内容まで失われないようにするため。
	 *
	 * @param WP_User $user User being edited (never the current user; see the class docblock). / 編集対象のユーザー（現在のユーザー自身にはならない。クラスの docblock を参照）。
	 * @return void
	 */
	public static function render_fields( $user ) {
		if ( ! current_user_can( 'manage_options' ) || ! ( $user instanceof WP_User ) ) {
			return;
		}

		$resubmit = self::get_resubmit_data( $user->ID );
		$mode     = ( $resubmit && isset( $resubmit['mode'] ) ) ? $resubmit['mode'] : ACGD_Access_Restriction::get_user_mode( $user->ID );
		$ips      = ( $resubmit && isset( $resubmit['ips'] ) ) ? $resubmit['ips'] : ACGD_Access_Restriction::get_user_ip_text( $user->ID );
		?>
		<h2 id="<?php echo esc_attr( self::SECTION_ID ); ?>"><?php esc_html_e( 'Access Restriction', 'etbs-account-guard' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="acgd-user-mode"><?php esc_html_e( 'Mode for this user', 'etbs-account-guard' ); ?></label></th>
				<td>
					<select name="acgd_user_mode" id="acgd-user-mode">
						<option value="follow" <?php selected( 'follow', $mode ); ?>><?php esc_html_e( 'Follow the role setting', 'etbs-account-guard' ); ?></option>
						<option value="none" <?php selected( 'none', $mode ); ?>><?php esc_html_e( 'No restriction', 'etbs-account-guard' ); ?></option>
						<option value="ip" <?php selected( 'ip', $mode ); ?>><?php esc_html_e( 'IP restriction', 'etbs-account-guard' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'BASIC authentication will be added by a later update and cannot be chosen yet.', 'etbs-account-guard' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="acgd-user-ips"><?php esc_html_e( 'IP addresses added for this user', 'etbs-account-guard' ); ?></label></th>
				<td>
					<textarea name="acgd_user_ips" id="acgd-user-ips" rows="5" cols="40" class="large-text code"><?php echo esc_textarea( $ips ); ?></textarea>
					<p class="description">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: 1: example of a single IP address, 2: example of an IP range in CIDR notation, 3: URL of the Access Restriction tab of the settings screen */
								__( 'One IP address or range (CIDR) per line, such as %1$s or %2$s. Text after # is a note. Allowed in addition to the site-wide list on the <a href="%3$s">Access Restriction settings tab</a>.', 'etbs-account-guard' ),
								'<code>192.0.2.10</code>',
								'<code>192.0.2.0/24</code>',
								esc_url( ACGD_Settings::get_page_url( 'access' ) )
							),
							array(
								'code' => array(),
								'a'    => array( 'href' => true ),
							)
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
	}

	/**
	 * Validates and saves the Access Restriction fields of the user edit screen.
	 * ユーザー編集画面の「アクセス制限」の項目を検証し、保存する。
	 *
	 * On an invalid IP list, or a save that would leave no unrestricted manage_options user (save-time check
	 * 1, docs/spec.md 5.1), nothing is written and an error is queued for append_pending_error() to attach.
	 * IP 一覧が不正なとき、または保存後に制限なしの manage_options ユーザーが1人もいなくなるとき
	 * （保存時のチェック1、docs/spec.md 5.1）は、何も書き込まず append_pending_error() が使うエラーを積む。
	 *
	 * @param int $user_id User being saved. / 保存対象のユーザー。
	 * @return void
	 */
	public static function save_fields( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Defense in depth: the fields are never shown for one's own profile either (see the class docblock).
		// 念のための二重の防御：この区画は本人のプロフィールにはそもそも出さない（クラスの docblock を参照）。
		if ( get_current_user_id() === (int) $user_id ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		$mode = isset( $_POST['acgd_user_mode'] ) ? sanitize_key( wp_unslash( $_POST['acgd_user_mode'] ) ) : 'follow';
		if ( ! in_array( $mode, array( 'follow', 'none', 'ip' ), true ) ) {
			// 'basic' is not offered by this screen yet (see render_fields()); anything else falls back to
			// the safe default. 'basic' はこの画面ではまだ選べない（render_fields() を参照）。それ以外は安全な既定値に倒す。
			$mode = 'follow';
		}

		$ip_text   = isset( $_POST['acgd_user_ips'] ) ? (string) wp_unslash( $_POST['acgd_user_ips'] ) : '';
		$validated = ACGD_Access_Restriction::validate_ip_list( $ip_text );

		if ( $validated['invalid'] ) {
			// The profile screen prints WP_Error messages unescaped, and this line is the admin's own raw
			// submitted input; escape it here rather than trust it.
			// プロフィール画面は WP_Error のメッセージを未エスケープで出力するため、ここで自前でエスケープする。
			// この行は管理者自身が送信した生の入力である。
			self::$pending_error = sprintf(
				/* translators: 1: line number, 2: the line's content */
				esc_html__( 'Line %1$d of the IP addresses added for this user is not a valid IP address or range: %2$s', 'etbs-account-guard' ),
				(int) key( $validated['invalid'] ),
				esc_html( reset( $validated['invalid'] ) )
			);
			self::stash_resubmit( $user_id, $mode, $ip_text );
			return;
		}

		if ( ACGD_Access_Restriction::count_unrestricted_admins( ACGD_Access_Restriction::get_role_modes(), array( (int) $user_id => $mode ) ) < 1 ) {
			// Says where to go, not just what is wrong (UX review, matching the same message on the Access
			// Restriction settings tab), and links to it (UX review: a place that is named should be reachable).
			// wp_kses() (not esc_html__()) is used because the message now carries a real <a> link; the profile
			// screen prints WP_Error messages unescaped either way (see the comment above).
			// 何が悪いかだけでなく、どこへ行けばよいかも書く（UX レビュー。「アクセス制限」設定タブの同じ
			// メッセージと揃えている）。名指しした行き先にはリンクを張る（UX レビュー）。本物の <a> リンクを
			// 含むため esc_html__() ではなく wp_kses() を使う（プロフィール画面がこれを未エスケープで
			// 出力すること自体は上のコメントと同じ）。
			self::$pending_error = wp_kses(
				sprintf(
					/* translators: %s: URL of the Access Restriction tab of the settings screen */
					__( 'This would leave no administrator (or other user who can manage options) without a restriction. Change one of them back to "No restriction" here or on the <a href="%s">Access Restriction settings tab</a>. Not saved.', 'etbs-account-guard' ),
					esc_url( ACGD_Settings::get_page_url( 'access' ) )
				),
				array( 'a' => array( 'href' => true ) )
			);
			self::stash_resubmit( $user_id, $mode, $ip_text );
			return;
		}

		update_user_meta( $user_id, ACGD_Access_Restriction::USER_MODE_META, $mode );
		update_user_meta( $user_id, ACGD_Access_Restriction::USER_IPS_META, $ip_text );
		ACGD_Access_Restriction::clear_fault();
		// See ACGD_Settings::sanitize_access_restriction_settings() for why this is only ever a defensive
		// no-op in practice, and why it is kept anyway.
		// 実際には常に無害な呼び出しになる理由と、それでも残す理由は
		// ACGD_Settings::sanitize_access_restriction_settings() を参照。
		delete_transient( self::resubmit_key( get_current_user_id(), $user_id ) );
	}

	/**
	 * Returns the transient key for a rejected submission of this screen, for one (admin, target user) pair.
	 * この画面で拒否された送信内容の transient キーを、(管理者, 編集対象ユーザー) の組ごとに返す。
	 *
	 * @param int $admin_id  ID of the admin submitting the form. / フォームを送信した管理者の ID。
	 * @param int $target_id ID of the user being edited. / 編集対象のユーザーの ID。
	 * @return string Transient key. / transient のキー。
	 */
	private static function resubmit_key( $admin_id, $target_id ) {
		return self::RESUBMIT_TRANSIENT_PREFIX . (int) $admin_id . '_' . (int) $target_id;
	}

	/**
	 * Stashes a rejected submission of this screen's fields, so the profile page can be redisplayed with it.
	 * See RESUBMIT_TRANSIENT_PREFIX for why a transient, rather than writing straight to user meta, is used.
	 * この画面で拒否された送信内容を、プロフィール画面の出し直しに使えるよう保存する。
	 * なぜユーザーメタへ直接書くのではなく transient を使うかは RESUBMIT_TRANSIENT_PREFIX を参照。
	 *
	 * @param int    $target_id Target user being edited. / 編集対象のユーザー。
	 * @param string $mode      Submitted mode ('follow', 'none' or 'ip'; already validated by save_fields()). / 送信されたモード（'follow'・'none'・'ip'。save_fields() で検証済み）。
	 * @param string $ip_text   Raw added-IP text, exactly as submitted (may contain invalid lines). / 送信された生の追加 IP（不正な行を含みうる）。
	 * @return void
	 */
	private static function stash_resubmit( $target_id, $mode, $ip_text ) {
		set_transient(
			self::resubmit_key( get_current_user_id(), $target_id ),
			array(
				'mode' => $mode,
				'ips'  => $ip_text,
			),
			self::RESUBMIT_TTL
		);
	}

	/**
	 * Returns a rejected submission stashed by stash_resubmit(), if any, for the current admin editing the
	 * given target user. Deletes it immediately, so it is shown exactly once.
	 * stash_resubmit() が保存した、拒否された送信内容を、現在の管理者が対象ユーザーを編集している分だけ返す
	 * （無ければ null）。読んだ直後に消すので、一度だけ表示される。
	 *
	 * @param int $target_id Target user being edited. / 編集対象のユーザー。
	 * @return array|null {
	 *     @type string $mode Mode, as submitted. / 送信されたモード。
	 *     @type string $ips  Raw added-IP text, as submitted. / 送信された生の追加 IP。
	 * }
	 */
	private static function get_resubmit_data( $target_id ) {
		$key  = self::resubmit_key( get_current_user_id(), $target_id );
		$data = get_transient( $key );
		delete_transient( $key );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Attaches the validation error from save_fields(), if any, to the profile screen's errors.
	 * save_fields() の検証エラーがあれば、プロフィール画面のエラーに足す。
	 *
	 * @param WP_Error $errors Errors of the profile update, changed in place. / プロフィール更新のエラー（その場で変更する）。
	 * @return void
	 */
	public static function append_pending_error( $errors ) {
		if ( '' !== self::$pending_error && $errors instanceof WP_Error ) {
			$errors->add( 'acgd_access_restriction', self::$pending_error );
		}
	}

	/*-------------------------------------------*/
	/* Users list column / ユーザー一覧の列
	/*-------------------------------------------*/

	/**
	 * Adds the Access Restriction column to the Users list, for manage_options only.
	 * ユーザー一覧に「アクセス制限」列を足す（manage_options のみ）。
	 *
	 * @param string[] $columns Columns: slug => label. / 列（スラッグ => ラベル）。
	 * @return string[] Columns. / 列。
	 */
	public static function add_column( $columns ) {
		if ( current_user_can( 'manage_options' ) ) {
			$columns[ self::COLUMN ] = __( 'Access Restriction', 'etbs-account-guard' );
		}

		return $columns;
	}

	/**
	 * Prints the value of the Access Restriction column: the mode(s) that actually apply to that user.
	 * 「アクセス制限」列の値を出力する（そのユーザーに実際に効いているモード）。
	 *
	 * @param string $value       Value built by an earlier callback. / それまでのコールバックが作った値。
	 * @param string $column_name Column being rendered. / 描画中の列。
	 * @param int    $user_id     User in this row. / この行のユーザー。
	 * @return string Value (escaped HTML). / 値（エスケープ済みの HTML）。
	 */
	public static function render_column( $value, $column_name, $user_id ) {
		if ( self::COLUMN !== $column_name || ! current_user_can( 'manage_options' ) ) {
			return $value;
		}

		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return $value;
		}

		return esc_html( self::describe_modes( ACGD_Access_Restriction::get_effective_modes( $user ) ) );
	}

	/**
	 * Describes a set of effective modes in words, for the Users list column and the settings screen.
	 * 実際に効くモードの集合を文字で表す。ユーザー一覧の列と設定画面で使う。
	 *
	 * @param string[] $modes Subset of array( 'ip', 'basic' ). / array('ip','basic') の部分集合。
	 * @return string Description, not escaped. / 説明（未エスケープ）。
	 */
	public static function describe_modes( $modes ) {
		if ( empty( $modes ) ) {
			return __( 'No restriction', 'etbs-account-guard' );
		}

		$labels = array();
		if ( in_array( 'ip', $modes, true ) ) {
			$labels[] = __( 'IP', 'etbs-account-guard' );
		}
		if ( in_array( 'basic', $modes, true ) ) {
			$labels[] = __( 'BASIC', 'etbs-account-guard' );
		}

		/* translators: joins the restriction modes that apply to a user, such as "IP" and "IP + BASIC" */
		return implode( _x( ' + ', 'joins two access restriction modes', 'etbs-account-guard' ), $labels );
	}
}
