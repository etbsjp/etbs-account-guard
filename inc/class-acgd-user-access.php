<?php
/**
 * Access Restriction: the per-user screens (docs/spec.md 5.6) — the user edit screen section and the
 * Access Restriction column of the Users list.
 * アクセス制限のユーザーごとの画面（docs/spec.md 5.6）——ユーザー編集画面の区画と、
 * ユーザー一覧の「アクセス制限」列。
 *
 * The section is registered on BOTH of WordPress core's own profile-screen hooks: edit_user_profile (fires
 * when the person being edited is someone other than whoever is looking, e.g. an admin editing another
 * user's wp-admin/user-edit.php screen) and show_user_profile (fires on one's own wp-admin/profile.php —
 * core's IS_PROFILE_PAGE split decides this purely by whether the target user ID equals the current user's
 * own ID, never by which URL was used to get there; see the decision record on issue #4, PR #6). Originally
 * this section hooked edit_user_profile only, on the theory that a raw link straight to
 * wp-admin/user-edit.php?user_id=<own ID> (bypassing get_edit_profile_url(), which always forces profile.php
 * for one's own ID) would still fire edit_user_profile even for one's own account — but core does not decide
 * the hook that way (IS_PROFILE_PAGE = target ID === current user ID, full stop), so that link silently
 * showed nothing (UI test finding, PR #6). Both hooks now render/save the exact same section, gated the exact
 * same way — current_user_can( 'manage_options' ) — rather than by "whose account is this". A user who lacks
 * manage_options (someone actually subject to a restriction) still sees nothing on wp-admin/profile.php,
 * satisfying docs/spec.md 5.6's "本人のプロフィール画面：何も出さない" (withholding the hint of where one is
 * and is not allowed to connect from); the only people who ever see this section on their own profile screen
 * are manage_options holders, who already see the same information (the full IP list, everyone's mode) on the
 * "設定 > ETBS Account Guard" Access Restriction tab, so showing it here again is not a new hint to anyone
 * (decision record on issue #4, PR #6 second round). The section's content is identical either way — no
 * filtering by $is_self beyond what already existed (the "Verify" button, which only ever made sense for
 * one's own account regardless of which hook rendered it).
 * この区画は本体自身の2つのプロフィール画面フック**両方**に登録している：edit_user_profile（編集対象が
 * 閲覧者自身ではない場合に発火。例：管理者が他人の wp-admin/user-edit.php 画面を編集するとき）と
 * show_user_profile（本人自身の wp-admin/profile.php で発火）——本体の IS_PROFILE_PAGE の分岐は、
 * 対象ユーザー ID が現在のユーザー自身の ID と一致するかどうかだけで決まり、どの URL で到達したかは一切
 * 関係ない（issue #4・PR #6 の decision record を参照）。当初この区画は edit_user_profile だけにフックして
 * いた：`wp-admin/user-edit.php?user_id=<自分のID>` への生のリンク（自分の ID には常に profile.php を強制する
 * get_edit_profile_url() を経由しない）を踏めば、自分自身の口座でも edit_user_profile が発火するはず、という
 * 想定だったが、本体はそのようにフックを決めていない（IS_PROFILE_PAGE ＝「対象 ID が現在のユーザー自身の ID
 * と一致するか」それだけ）ため、そのリンクは何も表示しないまま無言で終わっていた（UI テストでの判明。PR #6）。
 * 今はどちらのフックも全く同じ区画を描画・保存し、条件も「誰の口座か」ではなく全く同じ
 * current_user_can( 'manage_options' ) で揃えている。manage_options を持たない人（実際に制限を受ける側）は
 * wp-admin/profile.php でも依然として何も見えず、docs/spec.md 5.6「本人のプロフィール画面：何も出さない」
 * （自分がどこから接続できる・できないかの手がかりを与えない）を満たす。自分自身のプロフィール画面でこの
 * 区画が見える唯一の相手は manage_options を持つ人であり、その人は「設定 > ETBS Account Guard」のアクセス
 * 制限タブで既に同じ情報（IP の全一覧・全員のモード）を見られるため、ここにも出すことは誰にとっても
 * 新しい手がかりにならない（issue #4 の decision record、PR #6 の2回目のラウンド）。区画の中身はどちらの
 * フック経由でも同一——既にあった以上の $is_self による選別（「確認」ボタン。どちらのフックで描画されても
 * 意味を持つのは自分自身の口座のときだけ、という点も変わらない）は増やしていない。
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
		// edit_user_profile: someone else's wp-admin/user-edit.php screen. show_user_profile: one's own
		// wp-admin/profile.php screen (decision record on issue #4, PR #6 second round — see the class
		// docblock for why both are needed and why manage_options, not $is_self, is the actual gate).
		// edit_user_profile：他人の wp-admin/user-edit.php 画面。show_user_profile：自分自身の
		// wp-admin/profile.php 画面（issue #4・PR #6 の2回目のラウンドの decision record。両方が要る理由と、
		// 実際の条件が「本人か」ではなく manage_options である理由はクラスの docblock を参照）。
		add_action( 'edit_user_profile', array( __CLASS__, 'render_fields' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_fields' ) );
		// user_profile_update_errors fires from wp-admin/user-edit.php for both the "someone else" and "one's
		// own" cases alike, so one registration already covers save_fields() regardless of which of the two
		// hooks above ran. / user_profile_update_errors は wp-admin/user-edit.php から「他人」「本人」どちらの
		// ケースでも発火するため、この1回の登録だけで、上の2つのフックのどちらが動いた save_fields() もカバーする。
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
	 * @param WP_User $user User being edited: someone else on edit_user_profile, or the current admin
	 *                       themselves on show_user_profile (see the class docblock). / 編集対象のユーザー。
	 *                       edit_user_profile なら他人、show_user_profile なら現在の管理者自身
	 *                       （クラスの docblock を参照）。
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
						 * Core's own "your-profile" form (wp-admin/user-edit.php / profile.php) does not print a
						 * wp_referer_field() itself, so without one, ACGD_Basic_Auth::handle_verify_request() has
						 * no reliable way to tell which of the two screens this form was actually submitted from.
						 * Printing it here means it rides along with this same <form> to the "Verify" admin-post
						 * handler, which reads it back with wp_get_referer() to return to the exact screen the
						 * admin started from — "Profile" (profile.php) when verifying one's own account, or
						 * "Edit User" (user-edit.php?user_id=...) when a manage_options admin is verifying someone
						 * else's (UX review HIGH fix, issue #4: this previously always hardcoded user-edit.php,
						 * so confirming from one's own "Profile" screen landed back on "Edit User" instead).
						 * 本体自身の「your-profile」フォーム（wp-admin/user-edit.php・profile.php）は
						 * wp_referer_field() を自分では出さない。そのため、これが無いと
						 * ACGD_Basic_Auth::handle_verify_request() には、このフォームが実際にどちらの画面から
						 * 送信されたかを知る確実な手段が無い。ここで出しておけば、この同じ <form> に乗って
						 * 「確認」の admin-post ハンドラへ届き、そちらが wp_get_referer() で読み戻すことで、
						 * 管理者が出発した画面そのもの——自分自身を確認するときは「プロフィール」
						 * （profile.php）、manage_options を持つ管理者が他人を確認するときは「ユーザーを編集」
						 * （user-edit.php?user_id=...）——へ正しく戻せる（UX レビューの HIGH 修正、issue #4：
						 * 以前は常に user-edit.php を固定で使っていたため、自分自身の「プロフィール」画面から
						 * 確認しても「ユーザーを編集」に着地していた）。
						 */
						?>
						<?php wp_referer_field(); ?>
						<?php self::render_verify_token_field(); ?>
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

		/*
		 * Mirrors wp-admin/user-edit.php's own `submit_button( IS_PROFILE_PAGE ? __( 'Update Profile' ) :
		 * __( 'Update User' ) )` choice, so this notice always names the label actually printed on this screen's
		 * submit button (UI test finding, issue #4: the notice previously hardcoded "Update User", but the only
		 * button core prints on one's own profile.php is "Update Profile" — "Update User" does not exist there).
		 * render_verify_notice() is only ever called for $is_self (render_fields()), where IS_PROFILE_PAGE is
		 * always true (see the class docblock: core decides IS_PROFILE_PAGE purely by whether the target user
		 * ID equals the current user's own ID, which is exactly what $is_self already checks — there is no
		 * known case in which the two disagree). Reading IS_PROFILE_PAGE directly here, rather than reusing
		 * $is_self, is still the more robust choice: it is the very same variable core's own submit_button()
		 * call above switches on, so this notice cannot drift from the label actually printed even if some
		 * future change altered how core decides IS_PROFILE_PAGE. IS_PROFILE_PAGE is defined by
		 * wp-admin/user-edit.php before it fires the show_user_profile/edit_user_profile hooks that lead here,
		 * so it is already set by the time this method runs.
		 * wp-admin/user-edit.php 自身の `submit_button( IS_PROFILE_PAGE ? __( 'Update Profile' ) :
		 * __( 'Update User' ) )` の出し分けに合わせる。そうすることで、この通知は常にこの画面で実際に出ている
		 * 送信ボタンのラベルを言う（UIテストでの指摘、issue #4：この通知は以前「ユーザーを更新」に固定していた
		 * が、本人自身の profile.php で本体が出すボタンは「プロフィールを更新」だけで、「ユーザーを更新」は
		 * この画面に存在しない）。render_verify_notice() は $is_self のときにしか呼ばれない（render_fields()）
		 * が、そこでは IS_PROFILE_PAGE は常に true（クラスの docblock を参照：本体が IS_PROFILE_PAGE を決める
		 * のは対象ユーザー ID が現在のユーザー自身の ID と一致するかどうかだけであり、これは $is_self が既に
		 * 見ていることと同じで、両者が食い違う既知のケースは無い）。それでもここで $is_self を使い回さず
		 * IS_PROFILE_PAGE を直接参照するのには意味がある：これは本体自身の上記 submit_button() 呼び出しが
		 * 分岐に使っているのと全く同じ変数であるため、将来 本体が IS_PROFILE_PAGE を決める条件を変えたとしても、
		 * この通知が実際に出ているラベルからずれることは無い。IS_PROFILE_PAGE は、ここに至る
		 * show_user_profile / edit_user_profile フックを発火する前に wp-admin/user-edit.php が定義している
		 * ため、このメソッドの実行時点で既に定義済み。
		 */
		$update_label = ( defined( 'IS_PROFILE_PAGE' ) && IS_PROFILE_PAGE )
			? __( 'Update Profile', 'etbs-account-guard' )
			: __( 'Update User', 'etbs-account-guard' );

		$messages = array(
			// The second sentence exists only because this notice and the password field's own description
			// (render_fields()) sit next to each other right after a successful "Verify": the field is always
			// blank here (render_fields() never redisplays a password), and without this line that blank field
			// reads as "nothing to save" next to a generic "leave blank to keep the existing one" description —
			// which is only true for someone else's account, not this just-confirmed one (UX review, issue #4).
			// 2文目は、この通知とパスワード欄自体の説明文（render_fields()）が「確認」成功直後に隣り合って
			// 出るために足した。この欄は常に空欄で出るが（render_fields() はパスワードを一切出し直さない）、
			// この1文が無いと「空欄＝保存されない」に見えてしまう。パスワード欄の既存の説明文（「空欄なら
			// 既存のものを保持」）は他人の編集時にも当てはまる一般文であり、確認直後のこの状況には正確ではない
			// ため（UX レビュー、issue #4）。
			'ok'         => array(
				'success',
				sprintf(
					/* translators: %s: the label of this screen's own submit button, either "Update Profile" or "Update User" */
					__( 'Verified. Your BASIC authentication ID and password work. Click "%s" below to save.', 'etbs-account-guard' ),
					$update_label
				) . ' ' . __( 'The password field below will stay blank; the confirmed password is saved anyway.', 'etbs-account-guard' ),
			),
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
	 * Prints the one-time "Verify" confirmation token as a hidden field, but only on the one profile-screen
	 * rendering that immediately follows a successful confirmation (the acgd_basic_verify=ok redirect from
	 * ACGD_Basic_Auth::handle_verify() carries the token in its own query string; see
	 * ACGD_Basic_Auth::VERIFIED_TRANSIENT_PREFIX for why this exists — MEDIUM fix, PR #6 second review round).
	 * Any other rendering of this screen (a normal visit, a reload after navigating elsewhere, or one from a
	 * stale/expired confirmation) has no token in the query string and prints nothing here, so a save from
	 * that page cannot pick up a confirmed BASIC password it never actually confirmed just now.
	 * 「確認」のワンタイムトークンを hidden フィールドとして出力する。ただし、確認成功の直後に出し直された
	 * その1回のプロフィール画面の描画でだけ（ACGD_Basic_Auth::handle_verify() からの acgd_basic_verify=ok
	 * リダイレクトが、自身のクエリ文字列にこのトークンを載せている。なぜこの仕組みがあるかは
	 * ACGD_Basic_Auth::VERIFIED_TRANSIENT_PREFIX を参照——MEDIUM の修正、PR #6 の2回目のレビュー）。
	 * この画面のそれ以外の描画（通常の訪問、他画面へ移動した後の再読み込み、期限切れ・無効な確認からの
	 * 再表示）はクエリ文字列にトークンを持たず、ここでは何も出力しない。そのため、そちらのページからの保存が
	 * ——今まさに確認してもいない——確認済みの BASIC パスワードを拾ってしまうことは無い。
	 *
	 * @return void
	 */
	private static function render_verify_token_field() {
		// Read-only, and the value only ever gets echoed back into this same hidden field; the value that
		// actually matters is checked server-side against the transient in
		// ACGD_Basic_Auth::find_verified_hash(), so a forged or stale token submitted here simply fails to
		// match there and is treated the same as no token at all.
		// 読み取り専用で、この値はこの同じ hidden フィールドへそのまま出力するだけ。実際に効くのは
		// サーバー側で ACGD_Basic_Auth::find_verified_hash() が transient と突き合わせる部分であり、
		// ここで偽造・失効したトークンが送られても、そこで一致せず「トークンが無い」場合と同じに扱われる。
		$token = isset( $_GET[ ACGD_Basic_Auth::VERIFY_TOKEN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_GET[ ACGD_Basic_Auth::VERIFY_TOKEN_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; the value is re-validated server-side against the transient before it is ever trusted (see the method docblock).
		if ( '' === $token ) {
			return;
		}
		?>
		<input type="hidden" name="<?php echo esc_attr( ACGD_Basic_Auth::VERIFY_TOKEN_FIELD ); ?>" value="<?php echo esc_attr( $token ); ?>" />
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

		$ip_text    = isset( $_POST['acgd_user_ips'] ) ? (string) wp_unslash( $_POST['acgd_user_ips'] ) : '';
		$basic_id   = isset( $_POST['acgd_basic_id'] ) ? sanitize_text_field( wp_unslash( $_POST['acgd_basic_id'] ) ) : '';
		$basic_pass = isset( $_POST['acgd_basic_password'] ) ? (string) wp_unslash( $_POST['acgd_basic_password'] ) : '';
		// Only ever meaningful when $is_self (see ACGD_Basic_Auth::find_verified_hash()); read unconditionally
		// here anyway, since nothing is done with it until then. Present only on the one profile-screen
		// rendering right after a successful "Verify" (ACGD_User_Access::render_verify_token_field()); absent
		// on every other save, which is exactly the point (MEDIUM fix, PR #6 second review round).
		// 意味を持つのは $is_self のときだけ（ACGD_Basic_Auth::find_verified_hash() を参照）。それでもここでは
		// 無条件に読んでおく（使うのはそこから先だけなので害は無い）。「確認」成功直後に出し直された、その1回の
		// プロフィール画面の描画にだけ存在し（ACGD_User_Access::render_verify_token_field()）、それ以外の保存
		// では無い——それこそが狙い（MEDIUM の修正、PR #6 の2回目のレビュー）。
		$verify_token = isset( $_POST[ ACGD_Basic_Auth::VERIFY_TOKEN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ ACGD_Basic_Auth::VERIFY_TOKEN_FIELD ] ) ) : '';
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
		// Set true only where $final_hash is actually assigned from a confirmed "Verify" hash, further below.
		// Read once, at the very end of this method, to decide whether that confirmation is now consumed
		// (Low fix, etbs-senior-wp audit on PR #6: previously the confirmation was consumed the moment it was
		// looked up, even if the save then failed for an unrelated reason such as a BASIC ID collision — see
		// ACGD_Basic_Auth::find_verified_hash() for why that lookup no longer consumes by itself).
		// $final_hash が実際に「確認」済みのハッシュから代入された場合にだけ true にする。このメソッドの
		// 最後で一度だけ読み、その確認をここで消費したことにするかどうかを決める（大の監査（PR #6）の Low の
		// 修正：修正前は、探索した時点で確認が消費されていたため、その後 BASIC ID の衝突など無関係な理由で
		// 保存が失敗しても確認は失われていた。この探索がそれ自体では消費しなくなった理由は
		// ACGD_Basic_Auth::find_verified_hash() を参照）。
		$consume_verification_on_success = false;

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
			// Look up (without consuming — see ACGD_Basic_Auth::find_verified_hash()) a fresh "Verify"
			// confirmation for the exact ID being saved *before* deciding whether a password was even supplied
			// (HIGH fix, issue #4 / UX review, PR #6). Checking it here matters specifically for an admin who
			// has never had BASIC credentials before ($had_hash === false): a successful "Verify" always leaves
			// the password field blank on the very next redisplay, exactly like any other resubmit
			// (render_fields() never redisplays a password), so the two cases — "just confirmed, field is
			// blank" and "never confirmed anything" — were indistinguishable to the "ID and password both
			// required" check below when that check ran first. That silently rejected an already-confirmed,
			// first-time BASIC setup with "Not saved." even though "Verify" had reported success and told the
			// admin to click "Update User". Only meaningful for $is_self: find_verified_hash() is keyed to the
			// current admin's own confirmation (see its docblock), so nobody else's save can ever pick it up.
			// $verify_token additionally has to match the token stashed at confirmation time (MEDIUM fix, PR #6
			// second review round): without it, this lookup alone cannot tell a save from the page Verify just
			// returned to apart from any other, unrelated save landing within the same five minutes.
			// 「確認」(Verify) 済みの結果を、まさに保存しようとしている ID について（消費はせずに——
			// ACGD_Basic_Auth::find_verified_hash() を参照）先に探しにいく。パスワードが入力されたかどうかを
			// 判定する（すぐ下の）チェックより前に行う（HIGH 修正、issue #4／UX レビュー、PR #6）。この順序が
			// 特に効くのは、これまで BASIC 資格情報を一度も持ったことのない管理者（$had_hash === false）の
			// 場合：render_fields() はパスワードを一切出し直さないため、「確認」に成功していても、次に
			// 出し直されたパスワード欄は他のどの出し直しとも同じく必ず空欄になる。「確認済みだが欄は空」と
			// 「一度も確認していない」の2つを、下の「ID とパスワードの両方が必須」チェックを先に評価すると
			// 区別できず、「確認」が成功を報告し「ユーザーを更新をクリック」と案内した直後でも、確認済みの
			// 初回 BASIC 設定が「Not saved.」で無言で弾かれていた。$is_self のときだけ意味を持つ
			// （find_verified_hash() は今の管理者自身の確認に紐づくため。docblock を参照。他人の保存がこれを
			// 拾うことは無い）。$verify_token がさらに、確認したときに保存したトークンと一致しなければならない
			// のは（MEDIUM の修正、PR #6 の2回目のレビュー）、それが無いとこの探索だけでは、「確認」が
			// 送り返した先のページからの保存なのか、たまたま同じ5分に収まっただけの無関係な別の保存なのかを
			// 見分けられないため。
			$confirmed_hash = $is_self ? ACGD_Basic_Auth::find_verified_hash( $user_id, $final_id, $verify_token, $new_password ? $basic_pass : null ) : null;

			if ( '' === $final_id || ( ! $had_hash && ! $new_password && null === $confirmed_hash ) ) {
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
			$prior_mode           = ACGD_Access_Restriction::get_user_mode( $user_id );
			// Whether this save needs a fresh "Verify" confirmation at all: entering BASIC mode for the first
			// time, or actually changing the ID or typing a new password just now. Used only to decide whether
			// the *absence* of a confirmed hash (looked up once, above, before the "ID and password both
			// required" check) is an error — MEDIUM-1 / HIGH fix.
			// この保存に「確認」が必要かどうか：初めて BASIC モードにする、または今まさに ID を変える／新しい
			// パスワードを入力した場合。確認済みハッシュが無かったこと（探索は上で「ID とパスワードの両方が
			// 必須」チェックより前に一度だけ行っている）をエラーとするかどうかの判定にだけ使う
			// （MEDIUM-1／HIGH の修正）。
			$requires_confirmation = $is_self && ( 'basic' !== $prior_mode || $credentials_changing );
			if ( $is_self ) {
				// $confirmed_hash was already looked up above (before the "ID and password both required"
				// check), not re-looked-up here: find_verified_hash() does not consume anything by itself, but
				// re-calling it would still be pointless (same transient, same arguments, same answer).
				// A miss (no matching confirmation) is only an error when one was actually required; otherwise
				// it just means there was nothing to pick up, which is the ordinary case for a save that has
				// nothing to do with BASIC credentials at all.
				// $confirmed_hash は（「ID とパスワードの両方が必須」チェックより前に）上で既に探索済みであり、
				// ここでは探索し直さない：find_verified_hash() はそれ自体では何も消費しないが、同じ transient・
				// 同じ引数で呼び直しても同じ答えにしかならず意味が無い。
				// 見つからない（一致する確認が無い）ことがエラーになるのは、確認が実際に必要なときだけ。
				// そうでなければ単に拾うものが無かっただけで、BASIC の資格情報とは無関係な保存では通常そうなる。
				if ( null !== $confirmed_hash ) {
					$final_hash                      = $confirmed_hash; // Reuses the hash computed at Verify time; never hash the (possibly blank) submitted password again. / 「確認」時に計算済みのハッシュをそのまま使う。（空かもしれない）送信されたパスワードを改めてハッシュ化することは無い。
					$consume_verification_on_success = true; // Only actually invalidated once this save reaches its final write; see the flag's own declaration above. / この保存が実際に最後の書き込みへ到達した時点で初めて無効化する。フラグ自体の宣言を参照。
				} elseif ( $requires_confirmation ) {
					self::$pending_error = esc_html__( 'Click "Verify" and confirm your new BASIC authentication ID and password before saving them for your own account. Not saved.', 'etbs-account-guard' );
					self::stash_resubmit( $user_id, $mode, $ip_text, $basic_id );
					return;
				}
			}

			if ( null === $final_hash && $new_password ) {
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

		// Every early return above happens before this point, so reaching here means the save is actually going
		// through: only now is it safe to invalidate the "Verify" confirmation that $final_hash borrowed from,
		// so a save that instead failed further up (an invalid IP list, a BASIC ID collision, and so on) leaves
		// it intact for the admin to retry without going through "Verify" again (Low fix, etbs-senior-wp audit
		// on PR #6; see the flag's own declaration above and ACGD_Basic_Auth::find_verified_hash()).
		// ここより上のすべての早期 return は、ここへ到達する前に起きる。つまりここへ来たということは、保存が
		// 実際に行われるということ：$final_hash が借りた「確認」を無効化してよいのはここで初めてであり、
		// これより上で保存が失敗していれば（IP 一覧が不正、BASIC ID の衝突など）、管理者が「確認」をやり直さず
		// 再試行できるよう確認をそのまま残す（大の監査（PR #6）の Low の修正。フラグ自体の宣言と
		// ACGD_Basic_Auth::find_verified_hash() を参照）。
		if ( $consume_verification_on_success ) {
			ACGD_Basic_Auth::invalidate_verification( $user_id );
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
