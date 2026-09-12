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
 * ★ Everything here is a fallback, never a precondition: without this file (JavaScript off, a build that
 * fails to load it) Enter still submits the form through the default button exactly as before — core's
 * dialog appears, and "Leave this page" saves. Nothing here is allowed to make saving impossible.
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
 * ★ ここにあるものは全て「上乗せ」であって前提ではない：このファイルが無くても（JavaScript を切っている、
 * 読み込みに失敗した）Enter は今までどおり既定ボタン経由でフォームを送信する——本体のダイアログが出て、
 * 「このページを離れる」で保存される。ここの都合で保存できなくなることがあってはならない。
 */
( function () {
	'use strict';

	// The hidden default button. Absent on every screen that does not print this section (a user without
	// manage_options, or someone else's user-edit.php), and this file then does nothing at all.
	// 隠しの既定ボタン。この区画を出さない画面（manage_options を持たない人、他人の user-edit.php）には
	// 存在しないので、そのときこのファイルは何もしない。
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
