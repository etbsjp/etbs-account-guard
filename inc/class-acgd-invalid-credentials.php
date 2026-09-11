<?php
/**
 * The common "invalid credentials" error shared by the features of this plugin.
 * このプラグインの機能が共通で使う「資格情報が正しくない」エラー。
 *
 * Login Name Protection (item f of docs/spec.md 4) returns it for an unknown account and for a wrong
 * password alike. Access Restriction (1.1.0, docs/spec.md 5.2) returns the same error when it denies a
 * login, so that the reason is never shown. Keeping the code and the text in one place keeps the two
 * features from drifting apart.
 * ログイン名の保護（docs/spec.md 4節の f）は、アカウントが無いときもパスワードが違うときもこれを返す。
 * アクセス制限（1.1.0・docs/spec.md 5.2）もログインを拒否するときに同じエラーを返し、理由を明かさない。
 * コードと文言を1か所に置くことで、2つの機能の間でずれが生じないようにする。
 *
 * @package etbs-account-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the common error and replaces other errors with it.
 * 共通のエラーを作り、他のエラーをそれに差し替える。
 */
class ACGD_Invalid_Credentials {

	/**
	 * Error code of the common error. / 共通のエラーのコード。
	 *
	 * A code of this plugin rather than a core code. wp-login.php branches on the first error code: it fills
	 * the username field back in only for incorrect_password and empty_password. With a code of its own,
	 * the screen after a failed login is the same whether or not the account exists, whatever errors other
	 * plugins return for existing accounts only. The form still shakes, because shake_error_codes gets this code.
	 * 本体のコードではなく、このプラグイン独自のコードにする。wp-login.php は先頭のエラーコードで分岐し、
	 * ユーザー名欄を incorrect_password と empty_password のときだけ入力済みで戻す。独自のコードにすると、
	 * 他のプラグインが存在するアカウントにだけ返すエラーがどうであれ、ログインに失敗した後の画面は
	 * アカウントの有無で変わらない。shake_error_codes にこのコードを足すので、フォームは従来どおり揺れる。
	 *
	 * @var string
	 */
	const CODE = 'acgd_invalid_credentials';

	/**
	 * HTTP status of the common error in REST API responses. / REST API の応答での共通のエラーの HTTP ステータス。
	 *
	 * @var int
	 */
	const REST_STATUS = 401;

	/**
	 * Registers the hooks. / フックを登録する。
	 *
	 * The shake filter is not tied to a switch: adding a code that never appears changes nothing, and
	 * Access Restriction (1.1.0) uses the code whatever the switches of Login Name Protection are.
	 * 揺れのフィルタはスイッチに結び付けない。現れないコードを足しても何も変わらず、アクセス制限（1.1.0）は
	 * ログイン名の保護のスイッチに関係なくこのコードを使うため。
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'shake_error_codes', array( __CLASS__, 'filter_shake_error_codes' ) );
	}

	/**
	 * Lets the login form shake for the common error, as it does for core's login errors.
	 * 本体のログインエラーと同じく、共通のエラーでもログインフォームを揺らす。
	 *
	 * @param string[] $codes Error codes that shake the login form. / ログインフォームを揺らすエラーコード。
	 * @return string[] Error codes. / エラーコード。
	 */
	public static function filter_shake_error_codes( $codes ) {
		if ( ! is_array( $codes ) ) {
			return $codes;
		}
		if ( ! in_array( self::CODE, $codes, true ) ) {
			$codes[] = self::CODE;
		}

		return $codes;
	}

	/**
	 * Returns the sentence of the common error, as plain text. / 共通のエラーの文を、プレーンテキストで返す。
	 *
	 * @return string Translated sentence, not escaped. / 翻訳済みの文（未エスケープ）。
	 */
	public static function get_message() {
		return __( 'The username or password you entered is incorrect.', 'etbs-account-guard' );
	}

	/**
	 * Returns the common error for the login screen. / ログイン画面用の共通のエラーを返す。
	 *
	 * The link to the lost password screen is part of the message, as in core's incorrect_password message.
	 * パスワード再発行へのリンクは、本体の incorrect_password の文言と同じく文言の中に含める。
	 *
	 * @return WP_Error The common error. / 共通のエラー。
	 */
	public static function get_login_error() {
		$text = sprintf(
			'<strong>%1$s</strong> %2$s',
			esc_html__( 'Error:', 'etbs-account-guard' ),
			esc_html( self::get_message() )
		);
		$link = '<a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( 'Lost your password?', 'etbs-account-guard' ) . '</a>';

		return new WP_Error( self::CODE, $text . ' ' . $link );
	}

	/**
	 * Returns the common error for REST API authentication (status 401, plain text).
	 * REST API の認証用の共通のエラーを返す（ステータス 401・プレーンテキスト）。
	 *
	 * @return WP_Error The common error. / 共通のエラー。
	 */
	public static function get_rest_error() {
		return new WP_Error( self::CODE, self::get_message(), array( 'status' => self::REST_STATUS ) );
	}

	/**
	 * Returns the error with the given codes replaced by the common error.
	 * 指定したコードを共通のエラーに差し替えたエラーを返す。
	 *
	 * The common error comes first, so that its code is the one wp-login.php and the REST API branch on
	 * (the username field, the shake, and the HTTP status all follow the first code). Every other code
	 * (empty fields, CAPTCHA, login lockout and so on from other plugins) follows with its messages and
	 * data, in its original order, so that staff still see why they could not log in.
	 * An error without any of the given codes is returned as it is.
	 *
	 * 共通のエラーを先頭に置き、wp-login.php と REST API が分岐に使うコードをこれにする（ユーザー名欄・
	 * フォームの揺れ・HTTP ステータスはどれも先頭のコードで決まる）。それ以外のコード（空欄、他プラグインの
	 * 画像認証・ログインロックなど）は、文言・データとともに元の順で後ろに残す。職員の方が「なぜ入れないのか」を
	 * 読めるようにするため。指定したコードをどれも含まないエラーはそのまま返す。
	 *
	 * @param WP_Error $error  Error to look at. / 見るエラー。
	 * @param string[] $codes  Codes to replace. / 差し替えるコード。
	 * @param WP_Error $common The common error to put in their place. / 代わりに置く共通のエラー。
	 * @return WP_Error The error to use. / 使うエラー。
	 */
	public static function replace_codes( $error, $codes, $common ) {
		if ( ! array_intersect( $error->get_error_codes(), $codes ) ) {
			return $error;
		}

		$result = new WP_Error();
		self::copy_errors( $common, $result );
		self::copy_errors( $error, $result, $codes );

		return $result;
	}

	/**
	 * Copies the codes, messages and data of one error into another.
	 * あるエラーのコード・文言・データを、別のエラーへ写す。
	 *
	 * Written out instead of WP_Error::merge_from(), which WordPress added in 5.6, because this plugin
	 * declares no minimum WordPress version.
	 * WP_Error::merge_from() は WordPress 5.6 で追加されたもので、このプラグインは WordPress の下限を
	 * 宣言していないため、同じ処理をここに書く。
	 *
	 * @param WP_Error $from       Source. / 写し元。
	 * @param WP_Error $to         Destination, changed in place. / 写し先（その場で変更する）。
	 * @param string[] $skip_codes Codes not to copy. / 写さないコード。
	 * @return void
	 */
	public static function copy_errors( $from, $to, $skip_codes = array() ) {
		foreach ( $from->get_error_codes() as $code ) {
			if ( in_array( $code, $skip_codes, true ) ) {
				continue;
			}

			foreach ( $from->get_error_messages( $code ) as $message ) {
				$to->add( $code, $message );
			}
			$data = $from->get_error_data( $code );
			if ( null !== $data ) {
				$to->add_data( $data, $code );
			}
		}
	}
}
