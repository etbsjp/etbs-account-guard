/**
 * Makes Enter on the profile screen behave exactly like a click on "Update Profile".
 * プロフィール画面の Enter を、「プロフィールを更新」のクリックと全く同じ扱いにする。
 *
 * The Access Restriction section prints a nameless, hidden submit button so that it — and not the
 * "Verify" button further down — is the form's default button, the control a browser activates when
 * Enter is pressed in a text field (see ACGD_User_Access::render_fields() for why). That by itself
 * already saves the profile on Enter, but core's own wp-admin/js/user-profile.js only lowers its
 * "unsaved changes" guard from a click on #submit / #wp-submit:
 *
 *     $submitButton = $( '#submit, #wp-submit' ).on( 'click', function () { updateLock = false; } );
 *     $submitButtons.on( 'click', function () { isSubmitting = true; } );
 *
 * Enter never touches either of those, so isSubmitting stayed false and core's beforeunload handler put
 * up "The changes you made will be lost if you navigate away from this page." on a submission that was
 * in fact about to save — the wording and the actual behaviour were the exact opposite of each other,
 * and choosing "Cancel" in that dialog then really did cancel the save (issue #4).
 *
 * So this hands the default button's click over to the real save button: prevent the implicit
 * submission, then click #submit — the very node core bound those handlers to (hence getElementById,
 * matching core's own selector, rather than a lookup scoped to the form). Core then lowers updateLock
 * and raises isSubmitting itself, and submits the form the same way it always does.
 *
 * ★ Saving must never depend on this file: without it (JavaScript off, a build that fails to load it)
 * Enter still submits the form through the default button exactly as before — core's dialog appears, and
 * "Leave this page" saves. What this file may do is hand a click on to another control and let that
 * control's own behaviour stand, including when that behaviour is to do nothing: while core has #submit
 * disabled (the "Confirm use of weak password" checkbox unchecked), delegating to it submits nothing,
 * which is precisely what clicking "Update Profile" does in that state. See the note further down.
 *
 * この区画は、下にある「確認」ボタンではなく自分がフォームの既定ボタン——テキスト欄で Enter を押したとき
 * ブラウザが起動するコントロール——になるよう、名前を持たない隠しの送信ボタンを出力している（理由は
 * ACGD_User_Access::render_fields() を参照）。それだけでも Enter で保存自体は行われるが、本体自身の
 * wp-admin/js/user-profile.js は「変更が保存されていない」の見張りを #submit / #wp-submit の
 * **クリック**でしか下ろさない（上記の引用）。Enter はそのどちらにも触れないため isSubmitting は false の
 * ままで、これから保存されるはずの送信に対して本体の beforeunload が「このページから移動すると、行った変更が
 * 失われます」を出していた。文言と実際の動きが正反対であるうえ、そこで「キャンセル」を選ぶと本当に保存が
 * 取り消されてしまう（issue #4）。
 * そこで、既定ボタンのクリックを本物の保存ボタンへ委ねる：暗黙送信を止めてから #submit をクリックする。
 * 本体がハンドラを結び付けた当のノードでなければ意味がないので、フォーム内に絞った検索ではなく本体自身と
 * 同じセレクタ（getElementById）で引く。あとは本体が自分で updateLock を下ろし isSubmitting を上げ、
 * いつもどおりフォームを送信する。
 * ★ 保存がこのファイルに依存することがあってはならない：このファイルが無くても（JavaScript を切っている、
 * 読み込みに失敗した）Enter は今までどおり既定ボタン経由でフォームを送信する——本体のダイアログが出て、
 * 「このページを離れる」で保存される。このファイルがしてよいのは、クリックを別のコントロールへ渡して、
 * そのコントロール自身の挙動に委ねることまで——その挙動が「何もしない」であっても同じ。本体が #submit を
 * 無効にしている間（「脆弱なパスワードの使用を確認」のチェックが外れている間）は、委ねても何も送信され
 * ないが、それはその状態で「プロフィールを更新」を押したときと全く同じ結果。下の注記も参照。
 */
( function () {
	'use strict';

	// The hidden default button. Absent on every screen that does not print this section (a user without
	// manage_options, or someone else's user-edit.php), and this file then does nothing at all.
	// ★ This id is a literal copy of ACGD_User_Access::DEFAULT_SUBMIT_ID (inc/class-acgd-user-access.php),
	// which is where the button is printed. A script file is served as-is and cannot read a PHP constant,
	// so the two are kept in step by hand: change one, change the other in the same commit. See the
	// docblock on that constant for what drifting apart looks like (this file quietly finds nothing).
	// 隠しの既定ボタン。この区画を出さない画面（manage_options を持たない人、他人の user-edit.php）には
	// 存在しないので、そのときこのファイルは何もしない。
	// ★ この id は ACGD_User_Access::DEFAULT_SUBMIT_ID（inc/class-acgd-user-access.php。ボタンを出力して
	// いるのもそこ）のリテラルの写し。スクリプトファイルはそのまま配信されるので PHP の定数は読めず、
	// 両者は人の手で揃える：片方を変えたら同じコミットでもう片方も変えること。ずれたときどう見えるかは
	// その定数の docblock を参照（このファイルは黙って何も見つけられなくなる）。
	var defaultButton = document.getElementById( 'acgd-default-submit' );

	if ( ! defaultButton ) {
		return;
	}

	defaultButton.addEventListener( 'click', function ( event ) {
		// The screen's own save button, printed by core's submit_button(). If core ever stops printing it
		// under this id, leave the click alone: the implicit submission goes through untouched and the
		// behaviour falls back to what it is without this file.
		// この画面自身の保存ボタン。本体の submit_button() が出している。本体がこの id で出さなくなった場合は
		// クリックに手を触れない——暗黙送信はそのまま通り、このファイルが無いときの動きに落ちる。
		var saveButton = document.getElementById( 'submit' );

		if ( ! saveButton ) {
			return;
		}

		/*
		 * Note that a disabled save button is deliberately not worked around here. Core disables #submit
		 * while the "Confirm use of weak password" checkbox is unchecked, and a click on a disabled button
		 * does nothing — which is exactly what clicking "Update Profile" would do in that state.
		 * 保存ボタンが無効なときに、ここで迂回しないのは意図的。本体は「脆弱なパスワードの使用を確認」の
		 * チェックが外れている間 #submit を無効にしており、無効なボタンへのクリックは何も起こさない——
		 * その状態で「プロフィールを更新」を押したときと同じ挙動になる。
		 */
		event.preventDefault();
		saveButton.click();
	} );
}() );
