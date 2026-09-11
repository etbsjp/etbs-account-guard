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
	 * @param WP_User $user User being edited (never the current user; see the class docblock). / 編集対象のユーザー（現在のユーザー自身にはならない。クラスの docblock を参照）。
	 * @return void
	 */
	public static function render_fields( $user ) {
		if ( ! current_user_can( 'manage_options' ) || ! ( $user instanceof WP_User ) ) {
			return;
		}

		$mode = ACGD_Access_Restriction::get_user_mode( $user->ID );
		$ips  = ACGD_Access_Restriction::get_user_ip_text( $user->ID );
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
					<p class="description"><?php esc_html_e( 'One IP address or range (CIDR) per line. Text after # is a note. Allowed in addition to the site-wide list on the Access Restriction settings tab.', 'etbs-account-guard' ); ?></p>
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
			self::$pending_error = sprintf(
				/* translators: 1: line number, 2: the line's content */
				__( 'Line %1$d of the IP addresses added for this user is not a valid IP address or range: %2$s', 'etbs-account-guard' ),
				(int) key( $validated['invalid'] ),
				reset( $validated['invalid'] )
			);
			return;
		}

		if ( ACGD_Access_Restriction::count_unrestricted_admins( ACGD_Access_Restriction::get_role_modes(), array( (int) $user_id => $mode ) ) < 1 ) {
			self::$pending_error = __( 'This would leave no administrator (or other user who can manage options) without a restriction. Not saved.', 'etbs-account-guard' );
			return;
		}

		update_user_meta( $user_id, ACGD_Access_Restriction::USER_MODE_META, $mode );
		update_user_meta( $user_id, ACGD_Access_Restriction::USER_IPS_META, $ip_text );
		ACGD_Access_Restriction::clear_fault();
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
