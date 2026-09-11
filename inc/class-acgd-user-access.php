<?php
/**
 * Access Restriction: the per-user screens (docs/spec.md 5.6) — the user edit screen section and the
 * Access Restriction column of the Users list.
 * アクセス制限のユーザーごとの画面（docs/spec.md 5.6）——ユーザー編集画面の区画と、
 * ユーザー一覧の「アクセス制限」列。
 *
 * Both are limited to manage_options. The section relies on WordPress core's own IS_PROFILE_PAGE split
 * (wp-admin/profile.php sets it true before loading wp-admin/user-edit.php; wp-admin/user-edit.php leaves it
 * false), which fires show_user_profile only for profile.php and edit_user_profile for every other route to
 * this screen, REGARDLESS of whether the target user happens to be the one currently logged in. Ordinary
 * navigation (the toolbar's "Edit My Profile", one's own row in the Users list) always goes through
 * profile.php, so hooking edit_user_profile keeps docs/spec.md 5.6 ("本人のプロフィール画面：何も出さない")
 * true in practice: this section shows nothing there, withholding the hint of where one is and is not
 * allowed to connect from. The one deliberate exception is a raw link straight to
 * wp-admin/user-edit.php?user_id=<own ID> (never through get_edit_profile_url(), which always forces
 * profile.php for one's own ID), placed on the Access Restriction settings tab so an admin has a way to set
 * up their own BASIC authentication credentials (docs/spec.md 5.3, "自分をBASICモードにするときは、先に
 * 自分の資格情報を設定し..."; see ACGD_Settings::render_own_account_notice() and the decision record on
 * issue #4). Reaching this screen that way still fires edit_user_profile as core intends for a non-profile.php
 * route, so save_fields() below now handles a target that is the current admin, instead of refusing to run
 * at all as it once did.
 * どちらも manage_options に限る。この区画は本体自身の IS_PROFILE_PAGE の分岐（wp-admin/profile.php が
 * true にしてから wp-admin/user-edit.php を読み込む。wp-admin/user-edit.php では false のまま）に乗っている。
 * これは profile.php のときだけ show_user_profile を、それ以外の経路では常に edit_user_profile を発火させる
 * ——対象ユーザーが現在ログイン中の本人かどうかとは無関係に。通常の導線（ツールバーの「プロフィールを編集」、
 * ユーザー一覧の自分の行）は必ず profile.php を経由するため、edit_user_profile にフックすることで
 * docs/spec.md 5.6「本人のプロフィール画面：何も出さない」は実質的に成り立つ：この区画はそこには何も出さず、
 * 自分がどこから接続できる・できないかの手がかりを与えない。唯一の意図的な例外が、
 * wp-admin/user-edit.php?user_id=<自分のID> への生のリンク（自分の ID には常に profile.php を強制する
 * get_edit_profile_url() は経由しない）で、「アクセス制限」設定タブに置く。管理者が自分自身の BASIC 認証
 * 資格情報を設定する手段を用意するため（docs/spec.md 5.3「自分をBASICモードにするときは、先に自分の
 * 資格情報を設定し...」。ACGD_Settings::render_own_account_notice() と issue #4 の decision record を参照）。
 * この経路で到達しても、コアの意図どおり非 profile.php の経路として edit_user_profile が発火するので、
 * 下の save_fields() は「対象が今の管理者自身」というケースを、かつては即座に拒否していたのをやめ、
 * 実際に扱うようにしている。
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
		// edit_user_profile only: fires for one's own account too when reached other than through
		// profile.php. See the class docblock for why that keeps docs/spec.md 5.6 true in practice.
		// edit_user_profile のみ：profile.php 以外の経路で来た場合は自分自身の口座でも発火する。
		// それでも docs/spec.md 5.6 が実質的に成り立つ理由はクラスの docblock を参照。
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
	 * @param WP_User $user User being edited. Usually someone else; can be the current admin themselves when
	 *                       reached through the direct user-edit.php link (see the class docblock). / 編集対象のユーザー。通常は他人。クラスの docblock にある直接リンク経由なら現在の管理者自身にもなる。
	 * @return void
	 */
	public static function render_fields( $user ) {
		if ( ! current_user_can( 'manage_options' ) || ! ( $user instanceof WP_User ) ) {
			return;
		}

		$is_self  = ( get_current_user_id() === (int) $user->ID );
		$resubmit = self::get_resubmit_data( $user->ID );
		$mode     = ( $resubmit && isset( $resubmit['mode'] ) ) ? $resubmit['mode'] : ACGD_Access_Restriction::get_user_mode( $user->ID );
		$ips      = ( $resubmit && isset( $resubmit['ips'] ) ) ? $resubmit['ips'] : ACGD_Access_Restriction::get_user_ip_text( $user->ID );
		$basic_id = ( $resubmit && isset( $resubmit['basic_id'] ) ) ? $resubmit['basic_id'] : ACGD_Access_Restriction::get_basic_id( $user->ID );
		?>
		<?php if ( $is_self ) : ?>
			<?php
			/*
			 * A disabled submit button, hidden but still part of the DOM, printed before anything else this
			 * section adds (UX review, high priority). wp-admin/user-edit.php has no <button>/<input
			 * type="submit"> of its own before this hook point (edit_user_profile fires after every text,
			 * password and email field — name, nickname, email, website, biography, the "New Password" pair —
			 * and only "Update User" below is a submit control), so without this, the "Verify" button further
			 * down would become the form's *default button*: the one a browser activates when Enter is pressed
			 * in an earlier field, which would silently send the admin to the "Verify" round trip (discarding
			 * whatever else they had just typed) instead of saving. A form's default button is simply the
			 * first submit-type control in tree order, disabled or not; putting a disabled one first makes
			 * *it* the default button, and a disabled default button cannot be activated, so implicit
			 * (Enter-key) submission of this form does nothing from here on — the "Update User" button still
			 * works normally when clicked directly. This is a display/mis-click safeguard, not an
			 * authentication decision, so it does not run into the "don't decide access with JavaScript" rule
			 * (CLAUDE.md) — and in fact it needs no JavaScript at all.
			 * 無効化した submit ボタンを、見た目には隠しつつ DOM には残したまま、この区画が何かを足すより
			 * 前に出力する（UX レビュー・優先度高）。wp-admin/user-edit.php は、このフックが発火する時点
			 * （edit_user_profile は氏名・ニックネーム・メール・ウェブサイト・自己紹介・「新しいパスワード」
			 * の組など、あらゆるテキスト/パスワード/メール欄より後に発火し、以降で唯一の送信系コントロールは
			 * 下の「更新」ボタンだけ）より前に <button>/<input type="submit"> を1つも持たない。そのため
			 * これが無いと、下の「確認」ボタンがこのフォームの「既定ボタン」——早い段階の欄で Enter を
			 * 押したときブラウザが起動する対象——になってしまい、直前まで入力していた他の変更を保存せず
			 * 「確認」の往復へ静かに送ってしまう。フォームの既定ボタンは、無効・有効を問わず単に
			 * DOM 順で最初の送信系コントロールなので、無効化したものを先に置けばそれ自身が既定ボタンになり、
			 * 無効な既定ボタンは起動できないため、以降この フォームの Enter キーによる暗黙送信は何も
			 * しなくなる（「更新」ボタンを直接クリックする通常の保存は今までどおり動く）。これは認証可否の
			 * 判定ではなく表示・誤操作防止の用途なので、「JavaScript で判定しない」方針（CLAUDE.md）には
			 * 抵触しない——そのうえ、これは JavaScript を一切使わない。
			 */
			?>
			<button type="submit" disabled="disabled" aria-hidden="true" tabindex="-1" style="display:none;"></button>
		<?php endif; ?>
		<h2 id="<?php echo esc_attr( self::SECTION_ID ); ?>"><?php esc_html_e( 'Access Restriction', 'etbs-account-guard' ); ?></h2>
		<?php if ( $is_self ) : ?>
			<?php self::render_verify_notice(); ?>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="acgd-user-mode"><?php esc_html_e( 'Mode for this user', 'etbs-account-guard' ); ?></label></th>
				<td>
					<select name="acgd_user_mode" id="acgd-user-mode">
						<option value="follow" <?php selected( 'follow', $mode ); ?>><?php esc_html_e( 'Follow the role setting', 'etbs-account-guard' ); ?></option>
						<option value="none" <?php selected( 'none', $mode ); ?>><?php esc_html_e( 'No restriction', 'etbs-account-guard' ); ?></option>
						<option value="ip" <?php selected( 'ip', $mode ); ?>><?php esc_html_e( 'IP restriction', 'etbs-account-guard' ); ?></option>
						<option value="basic" <?php selected( 'basic', $mode ); ?>><?php esc_html_e( 'BASIC authentication', 'etbs-account-guard' ); ?></option>
					</select>
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
			<tr>
				<th scope="row"><label for="acgd-basic-id"><?php esc_html_e( 'BASIC authentication ID', 'etbs-account-guard' ); ?></label></th>
				<td>
					<input type="text" name="acgd_basic_id" id="acgd-basic-id" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $basic_id ); ?>" />
					<p class="description"><?php esc_html_e( 'Not the WordPress login name. Must be different from every other user\'s BASIC authentication ID on this site.', 'etbs-account-guard' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="acgd-basic-password"><?php esc_html_e( 'BASIC authentication password', 'etbs-account-guard' ); ?></label></th>
				<td>
					<input type="password" name="acgd_basic_password" id="acgd-basic-password" class="regular-text" autocomplete="new-password" value="" />
					<p class="description">
						<?php
						if ( ACGD_Access_Restriction::has_basic_credentials( $user->ID ) ) {
							esc_html_e( 'A password is already saved and is never shown again. Leave this blank to keep it, or enter a new one to replace it.', 'etbs-account-guard' );
						} else {
							esc_html_e( 'Required before this user can be put in BASIC authentication mode.', 'etbs-account-guard' );
						}
						?>
					</p>
				</td>
			</tr>
			<?php if ( $is_self ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Verify your own BASIC authentication', 'etbs-account-guard' ); ?></th>
					<td>
						<input type="hidden" name="acgd_user_id" value="<?php echo esc_attr( $user->ID ); ?>" />
						<?php
						/*
						 * formaction/formmethod post this same form's fields to a different URL (the "Verify"
						 * admin-post handler) instead of user-edit.php, without duplicating every field into a
						 * second <form> (decision record on issue #4). The nonce printed at the end of this
						 * section is shared with that handler.
						 * formaction/formmethod で、この同じフォームの内容を user-edit.php ではなく別の URL
						 * （「確認」の admin-post ハンドラ）へ送る。フィールドを2つ目の <form> に複製せずに済む
						 * （issue #4 の decision record）。この区画の末尾で出す nonce をそのハンドラと共有する。
						 */
						?>
						<button type="submit" class="button" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" formmethod="post" name="action" value="<?php echo esc_attr( ACGD_Basic_Auth::VERIFY_REQUEST_ACTION ); ?>">
							<?php esc_html_e( 'Verify', 'etbs-account-guard' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Set the ID and password above, click Verify to confirm they work, and you will be returned here to save them.', 'etbs-account-guard' ); ?>
						</p>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
	}

	/**
	 * Prints a notice reflecting the outcome of the "Verify" round trip (docs/spec.md 5.3), read from the
	 * acgd_basic_verify query flag that ACGD_Basic_Auth's redirects attach. Shown only on one's own screen
	 * (render_fields() only calls this when $is_self), since that flag is only ever meaningful there.
	 * 「確認」の往復（docs/spec.md 5.3）の結果を、ACGD_Basic_Auth のリダイレクトが付ける acgd_basic_verify
	 * クエリの目印から読んで出す。自分自身の画面のときだけ表示する（render_fields() が $is_self のときにしか
	 * 呼ばないため）。この目印が意味を持つのはそこだけであるため。
	 *
	 * @return void
	 */
	private static function render_verify_notice() {
		// Display only; nothing is read from a form here, and the value only selects which fixed sentence to
		// print. 表示のみ。ここでフォームの内容は読まず、値は固定文言の出し分けにしか使わない。
		$flag = isset( $_GET['acgd_basic_verify'] ) ? sanitize_key( wp_unslash( $_GET['acgd_basic_verify'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		$messages = array(
			'ok'         => array( 'success', __( 'Verified. Your BASIC authentication ID and password work. Click "Update User" below to save.', 'etbs-account-guard' ) ),
			'expired'    => array( 'warning', __( 'The verification has expired. Enter the ID and password again and click Verify.', 'etbs-account-guard' ) ),
			'not_self'   => array( 'error', __( 'The "Verify" button only works for your own account.', 'etbs-account-guard' ) ),
			'incomplete' => array( 'error', __( 'Enter a BASIC authentication ID and password, and set the mode to "BASIC authentication" before verifying.', 'etbs-account-guard' ) ),
		);
		if ( ! isset( $messages[ $flag ] ) ) {
			return;
		}

		list( $type, $text ) = $messages[ $flag ];
		?>
		<div class="notice inline notice-<?php echo esc_attr( $type ); ?>"><p><?php echo esc_html( $text ); ?></p></div>
		<?php
	}

	/**
	 * Validates and saves the Access Restriction fields of the user edit screen.
	 * ユーザー編集画面の「アクセス制限」の項目を検証し、保存する。
	 *
	 * On an invalid IP list, a save that would leave no unrestricted manage_options user (save-time check 1,
	 * docs/spec.md 5.1), a BASIC ID already used by someone else, a BASIC mode with no credentials, BASIC
	 * mode blocked by the receive diagnosis (docs/spec.md 5.3), or — when the target is the current admin's
	 * own account — a BASIC setup that has not just been confirmed through the "Verify" round trip, or a new
	 * setting (any mode, not just BASIC) that the current request itself would not satisfy (save-time check
	 * 2, docs/spec.md 5.1 and 5.3), nothing is written and an error is queued for append_pending_error() to
	 * attach.
	 * IP 一覧が不正なとき、保存後に制限なしの manage_options ユーザーが1人もいなくなるとき（保存時の
	 * チェック1、docs/spec.md 5.1）、BASIC の ID が既に他の人に使われているとき、BASIC モードなのに
	 * 資格情報が無いとき、受信の診断により BASIC モードが止められているとき（docs/spec.md 5.3）、
	 * または対象が今の管理者自身のときに「確認」の往復をたった今通していない BASIC 設定、もしくは
	 * （BASIC に限らずどのモードでも）今のリクエスト自体が満たせない新しい設定
	 * （保存時のチェック2、docs/spec.md 5.1・5.3）のいずれかに当たれば、何も書き込まず
	 * append_pending_error() が使うエラーを積む。
	 *
	 * @param int $user_id User being saved. / 保存対象のユーザー。
	 * @return void
	 */
	public static function save_fields( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		$user_id = (int) $user_id;
		$is_self = ( get_current_user_id() === $user_id );

		$mode = isset( $_POST['acgd_user_mode'] ) ? sanitize_key( wp_unslash( $_POST['acgd_user_mode'] ) ) : 'follow';
		if ( ! in_array( $mode, array( 'follow', 'none', 'ip', 'basic' ), true ) ) {
			$mode = 'follow'; // Unknown value: fall back to the safe default. / 未知の値は安全な既定値に倒す。
		}

		$ip_text      = isset( $_POST['acgd_user_ips'] ) ? (string) wp_unslash( $_POST['acgd_user_ips'] ) : '';
		$basic_id     = isset( $_POST['acgd_basic_id'] ) ? sanitize_text_field( wp_unslash( $_POST['acgd_basic_id'] ) ) : '';
		$basic_pass   = isset( $_POST['acgd_basic_password'] ) ? (string) wp_unslash( $_POST['acgd_basic_password'] ) : '';
		$existing_id  = ACGD_Access_Restriction::get_basic_id( $user_id );
		$had_hash     = ACGD_Access_Restriction::has_basic_credentials( $user_id );
		// A blank BASIC ID field means "no BASIC identity at all" only when nothing was ever saved; once an
		// ID exists, the field is always redisplayed filled in (it is not a secret; see render_fields()), so
		// leaving it blank on a resubmit can only mean the admin actually cleared it.
		// BASIC ID 欄が空なのは「BASIC の身元自体が無い」ことを意味するのは、一度も保存していないときだけ。
		// 一度 ID が存在すれば、この欄は常に埋めて出し直す（秘密ではないため。render_fields() を参照）ので、
		// 出し直し後に空なのは、管理者が実際に消した場合しかありえない。
		$final_id     = ( '' === $basic_id && '' !== $existing_id ) ? $existing_id : $basic_id;
		$final_hash   = null; // null = leave the stored hash untouched. / null = 保存済みのハッシュを変えない。
		$new_password = ( '' !== $basic_pass );

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
			self::stash_resubmit( $user_id, $mode, $ip_text, $basic_id );
			return;
		}

		if ( ACGD_Access_Restriction::count_unrestricted_admins( ACGD_Access_Restriction::get_role_modes(), array( $user_id => $mode ) ) < 1 ) {
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
			self::stash_resubmit( $user_id, $mode, $ip_text, $basic_id );
			return;
		}

		if ( 'basic' === $mode ) {
			if ( ! ACGD_Basic_Auth::diagnosis_allows_basic_mode() ) {
				self::$pending_error = wp_kses(
					sprintf(
						/* translators: %s: URL of the Access Restriction tab of the settings screen */
						__( 'BASIC authentication mode cannot be turned on because the receive diagnosis on the <a href="%s">Access Restriction settings tab</a> has not succeeded. Run it there first. Not saved.', 'etbs-account-guard' ),
						esc_url( ACGD_Settings::get_page_url( 'access' ) )
					),
					array( 'a' => array( 'href' => true ) )
				);
				self::stash_resubmit( $user_id, $mode, $ip_text, $basic_id );
				return;
			}
			if ( '' === $final_id || ( ! $had_hash && ! $new_password ) ) {
				self::$pending_error = esc_html__( 'Enter both a BASIC authentication ID and a password before choosing "BASIC authentication" mode. Not saved.', 'etbs-account-guard' );
				self::stash_resubmit( $user_id, $mode, $ip_text, $basic_id );
				return;
			}
			if ( ACGD_Access_Restriction::basic_id_taken_by_other( $final_id, $user_id ) ) {
				self::$pending_error = esc_html__( 'This BASIC authentication ID is already used by another user on this site. Choose a different one. Not saved.', 'etbs-account-guard' );
				self::stash_resubmit( $user_id, $mode, $ip_text, $basic_id );
				return;
			}

			// Save-time check 2 for BASIC (docs/spec.md 5.1, 5.3): only the current admin's own access is
			// ever at stake here (nobody else's save can change the current request's own identity), and only
			// when the ID or password is actually changing, or BASIC mode was not already active for them —
			// if it already was and nothing about the credentials changed, the admin necessarily reached this
			// screen carrying valid BASIC credentials already (see ACGD_Access_Restriction::
			// check_access_on_request()), so there is nothing new to re-confirm.
			// BASIC の保存時チェック2（docs/spec.md 5.1・5.3）：ここで問題になり得るのは今の管理者自身の
			// アクセスだけ（他人の保存が今のリクエストの本人性を変えることは無い）。しかも ID・パスワードが
			// 実際に変わる、またはこれまで BASIC モードでなかったときだけ必要——既に BASIC モードで
			// 資格情報も変えないなら、この画面に来られている時点で既に正しい BASIC 資格情報を伴っている
			// はず（ACGD_Access_Restriction::check_access_on_request() を参照）なので、改めて確認する
			// ものが無い。
			$credentials_changing = $new_password || ( $final_id !== $existing_id );
			$prior_mode            = ACGD_Access_Restriction::get_user_mode( $user_id );
			if ( $is_self && ( 'basic' !== $prior_mode || $credentials_changing ) ) {
				$to_confirm = $new_password ? $basic_pass : ''; // A confirmation can only ever cover a password actually typed just now. / 確認できるのは、たった今実際に入力したパスワードだけ。
				if ( ! $new_password || ! ACGD_Basic_Auth::consume_verification( $user_id, $final_id, $to_confirm ) ) {
					self::$pending_error = esc_html__( 'Click "Verify" and confirm your new BASIC authentication ID and password before saving them for your own account. Not saved.', 'etbs-account-guard' );
					self::stash_resubmit( $user_id, $mode, $ip_text, $basic_id );
					return;
				}
			}

			if ( $new_password ) {
				$final_hash = password_hash( $basic_pass, PASSWORD_DEFAULT ); // docs/spec.md 5.3: password_hash(), verified with password_verify(). / docs/spec.md 5.3：password_hash() で保存し、password_verify() で照合する。
			}
		} else {
			// Not saving in BASIC mode this time: check3 (docs/spec.md 5.1) is about who ends up in BASIC
			// mode, so an ID typed here without choosing BASIC mode is not yet a commitment. Still validate it
			// for uniqueness if given, so a value the admin is preparing does not silently collide later.
			// 今回は BASIC モードで保存しない：チェック3（docs/spec.md 5.1）が問われるのは実際に BASIC
			// モードになる人だけなので、BASIC を選ばずに ID だけ入力してもまだ確定ではない。それでも、
			// 入力された ID は一意性だけ検証しておき、後で静かに衝突しないようにする。
			if ( '' !== $basic_id && ACGD_Access_Restriction::basic_id_taken_by_other( $basic_id, $user_id ) ) {
				self::$pending_error = esc_html__( 'This BASIC authentication ID is already used by another user on this site. Choose a different one. Not saved.', 'etbs-account-guard' );
				self::stash_resubmit( $user_id, $mode, $ip_text, $basic_id );
				return;
			}

			// Save-time check 2 for non-BASIC modes (docs/spec.md 5.1): mirrors the BASIC branch above, for
			// the case that branch does not cover. Before this fix, choosing "IP restriction" (or "Follow the
			// role setting" when a held non-administrator role's own setting resolves to 'ip' or 'basic') for
			// one's own account here had no equivalent of check 2 at all, so an admin could lock themselves
			// out immediately (etbs-senior-wp audit, issue #4). $mode / $validated['entries'] are what is
			// about to be saved, not yet written, so they are passed in rather than read back from storage;
			// current_user_still_allowed() itself covers both an 'ip' and a role-driven 'basic' outcome
			// (see its own docblock), using the current request's REMOTE_ADDR / BASIC credentials exactly as
			// the settings tab's own check 2 does.
			// 非 BASIC モードの保存時チェック2（docs/spec.md 5.1）：上の BASIC 分岐がカバーしない場合の
			// 横展開。この修正前は、自分自身に対してここで「IP制限」を選ぶ場合（または「権限の設定に従う」
			// のままで、自分が持つ非administrator権限の設定が 'ip'／'basic' に解決される場合）に相当する
			// チェック2が一切無く、管理者が自分自身を即座に締め出せた（大の監査指摘、issue #4）。
			// $mode・$validated['entries'] はこれから保存する値でまだ書き込まれていないため、DB から
			// 読み直すのではなく引数で渡す。current_user_still_allowed() 自体が 'ip' と権限由来の 'basic' の
			// 両方をカバーする（自身の docblock を参照）。判定には今のリクエストの REMOTE_ADDR・BASIC
			// 資格情報を使う点も「アクセス制限」設定タブ自身のチェック2と同じ。
			if ( $is_self && ! ACGD_Access_Restriction::current_user_still_allowed(
				ACGD_Access_Restriction::get_role_modes(),
				ACGD_Access_Restriction::parse_ip_list( ACGD_Access_Restriction::get_site_ip_text() ),
				$mode,
				$validated['entries']
			) ) {
				$remote              = ACGD_Access_Restriction::get_remote_addr();
				self::$pending_error = null === $remote
					? esc_html__( 'Your own account, from where you are connecting right now, would not satisfy this new setting. Choose a setting that does not depend on IP restriction, such as BASIC authentication or no restriction. Not saved.', 'etbs-account-guard' )
					: sprintf(
						/* translators: %s: the current user's own IP address, to add to the IP list */
						esc_html__( 'Your own account would not satisfy this new setting: your current connection (%s) is not on the list. Add it, or choose a setting that still allows it. Not saved.', 'etbs-account-guard' ),
						// $remote already passed inet_pton() validation in get_remote_addr(); esc_html() here
						// is defense in depth, not a load-bearing escape (matches the settings tab's own
						// equivalent message; see ACGD_Settings::sanitize_access_restriction_settings()).
						// $remote は get_remote_addr() 内で inet_pton() の検証を通過済み。ここでの esc_html() は
						// 保険であり、これが無いと危険という意味ではない（「アクセス制限」設定タブの同じ
						// メッセージと揃えている。ACGD_Settings::sanitize_access_restriction_settings() を参照）。
						esc_html( $remote )
					);
				self::stash_resubmit( $user_id, $mode, $ip_text, $basic_id );
				return;
			}

			if ( $new_password ) {
				$final_hash = password_hash( $basic_pass, PASSWORD_DEFAULT );
			}
		}

		update_user_meta( $user_id, ACGD_Access_Restriction::USER_MODE_META, $mode );
		update_user_meta( $user_id, ACGD_Access_Restriction::USER_IPS_META, $ip_text );
		update_user_meta( $user_id, ACGD_Access_Restriction::USER_BASIC_ID_META, $final_id );
		// Keeps ACGD_Access_Restriction::BASIC_ID_COUNT_OPTION accurate (security review, MEDIUM/performance):
		// this is the only place USER_BASIC_ID_META is ever written, so this is also the only place its
		// presence can change. $existing_id was read before any of the writes above.
		// ACGD_Access_Restriction::BASIC_ID_COUNT_OPTION を正しい値に保つ（セキュリティレビュー・
		// MEDIUM／性能）：USER_BASIC_ID_META を書き込むのはここだけなので、その有無が変わりうるのもここだけ。
		// $existing_id は上のどの書き込みよりも前に読んでいる。
		ACGD_Access_Restriction::update_basic_id_count( '' !== $existing_id, '' !== $final_id );
		if ( null !== $final_hash ) {
			update_user_meta( $user_id, ACGD_Access_Restriction::USER_BASIC_HASH_META, $final_hash );
		}
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
	 * Stashes a rejected (or not-yet-verified) submission of this screen's fields, so the profile page can be
	 * redisplayed with it. See RESUBMIT_TRANSIENT_PREFIX for why a transient, rather than writing straight to
	 * user meta, is used. Public: ACGD_Basic_Auth::handle_verify_request() also stashes this screen's fields
	 * before sending the admin off to confirm their own BASIC credentials, so they come back after the
	 * confirmation round trip instead of an empty form.
	 * この画面で拒否された（またはまだ確認していない）送信内容を、プロフィール画面の出し直しに使えるよう
	 * 保存する。なぜユーザーメタへ直接書くのではなく transient を使うかは RESUBMIT_TRANSIENT_PREFIX を参照。
	 * public にしているのは、ACGD_Basic_Auth::handle_verify_request() も、管理者を自分の BASIC 資格情報の
	 * 確認へ送り出す前にこの画面の内容を保存し、確認の往復の後に空のフォームではなく元の内容へ戻すため。
	 *
	 * @param int    $target_id Target user being edited. / 編集対象のユーザー。
	 * @param string $mode      Submitted mode ('follow', 'none', 'ip' or 'basic'; already validated by the caller). / 送信されたモード（'follow'・'none'・'ip'・'basic'。呼び出し側で検証済み）。
	 * @param string $ip_text   Raw added-IP text, exactly as submitted (may contain invalid lines). / 送信された生の追加 IP（不正な行を含みうる）。
	 * @param string $basic_id  Submitted BASIC authentication ID (never the password; see the class docblock notes on render_fields()). / 送信された BASIC 認証の ID（パスワードは含めない。render_fields() の説明を参照）。
	 * @return void
	 */
	public static function stash_resubmit( $target_id, $mode, $ip_text, $basic_id = '' ) {
		set_transient(
			self::resubmit_key( get_current_user_id(), $target_id ),
			array(
				'mode'     => $mode,
				'ips'      => $ip_text,
				'basic_id' => $basic_id,
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
	 *     @type string $mode     Mode, as submitted. / 送信されたモード。
	 *     @type string $ips      Raw added-IP text, as submitted. / 送信された生の追加 IP。
	 *     @type string $basic_id BASIC authentication ID, as submitted. / 送信された BASIC 認証の ID。
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
