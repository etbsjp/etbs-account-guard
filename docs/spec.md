# ETBS Account Guard 仕様（正本）

サイトを管理するアカウントを守る WordPress プラグイン。**このファイルが仕様の正本**。
etbs のプラグイン共通ルールと既知の罠は `~/.claude/etbs-plugin-rules.md` にある（着手前に必ず読む）。

★ **この repo は public。** 施設名・顧客名・IP アドレス・実在のアカウント名をこのファイル・コミット・PR・issue に書かない。
導入先ごとの事情は非公開の台帳（`etbsjp/task-queue`）にだけ残す。

---

## 1. 目的と範囲

| 機能 | 版 | 何をするか |
|---|---|---|
| **ログイン名の保護** | 1.0.0 | ログインしていない人に、ログイン名（とログイン名から作られる URL 用の名前 `user_nicename`）を渡さない |
| **アクセス制限** | 1.1.0 | 権限（ロール）ごと・ユーザーごとに、「制限なし／IP 制限／BASIC 認証」を選んで、アカウントを使える条件を絞る |

- 機能は**名前で呼ぶ**（番号で呼ばない）。
- **やらないこと（現時点）**：二段階認証、ログイン試行の回数制限、画像認証、WAF。これらは SiteGuard WP Plugin などが担う。
  重ねて持つと「どちらの設定が効いているか」が分からなくなる。
  例外として、`?author=` の転送とログイン画面の文言は、**SiteGuard が入っていないサイトのために**このプラグインでも持つ（重なっても害は無い）。
- 将来の拡張の余地は残す（名前も構成も「アカウントを守る」の範囲で足せる形にする）。

---

## 2. 名前と識別子

| 項目 | 値 |
|---|---|
| 名前 | ETBS Account Guard |
| スラッグ・フォルダ名・本体ファイル | `etbs-account-guard` / `etbs-account-guard/etbs-account-guard.php` |
| テキストドメイン | `etbs-account-guard` |
| 関数・オプション・フック・ユーザーメタのプレフィックス | `acgd_` |
| 定数・クラスのプレフィックス | `ACGD_`（自プラグイン判定用に `ACGD_PLUGIN_FILE` を持つ） |
| 設定画面の画面ID | `settings_page_etbs-account-guard`（`add_options_page`。★ 画面IDは推測せず実測する） |
| リポジトリ / ブランチ | `etbsjp/etbs-account-guard`。**既定ブランチ・作業ブランチ・PR 先は `wporg`。** `dist` は既存の自社配布サイト向けの凍結ブランチで、触らない（`CLAUDE.md` 参照） |
| 更新配信 | wordpress.org の公式ディレクトリへ提出する版。更新チェッカーは同梱しない（wordpress.org は独自の更新機構を認めない）。配信は公式ディレクトリの SVN へ commit した瞬間に起き、その commit は人が行う |

★ **フォルダ名を最初から将来の wordpress.org のスラッグと同じにしておく。** 自社配布の個体は、フォルダ名で
api.wordpress.org に更新を照会しうる。フォルダ名が違うと、wp.org へ移るときに「他人が同名を取ると、その人の
プラグインが更新として配られる」窓が開く（EditLock で実際に開いた）。

---

## 3. 共通の決まり

### 3.1 言語・翻訳

- UI の原文は**英語**。日本語訳を同梱する（`languages/etbs-account-guard-ja.po` / `.mo`）。
- ヘッダに `Text Domain: etbs-account-guard` と `Domain Path: /languages`。
- ★★ **`init` で `load_plugin_textdomain( 'etbs-account-guard', false, dirname( plugin_basename( ACGD_PLUGIN_FILE ) ) . '/languages' )` を必ず呼ぶ。**
  WP 7.1 では `Domain Path` だけでは同梱の訳は読まれない（`custom_paths` は `load_plugin_textdomain()` でしか登録されない）。
  ★ 7.1 の `load_plugin_textdomain()` はファイルを読まずパスを登録するだけなので、**呼んだ直後の `is_textdomain_loaded()` は false**。
  それを根拠に「効いていない」と判断しない。検証は `switch_to_locale( 'ja' )` のうえで `__()` の戻り値を見る。
- ★ `.gitattributes` に `/languages/` を入れない（入れると `.mo` が配布 zip に入らない）。
- 翻訳関数に入れる文は1文ずつに区切る。
- `.po` を変えたら `.mo` を作り直す（`msgfmt` は `/opt/homebrew/bin/msgfmt`）。

### 3.2 readme とコメント

- `readme.txt` は**英語**で置く（将来 wp.org へ出すため）。`Contributors: etbsjp`（ユーザー名。表示名 `ETBS (DAI)` を書かない。`DAI` と書くと無関係の第三者を指す）。
- `README.md` は日本語でよい（利用者向けの説明）。
- ★ `readme.txt` が全体英語になるので、vk-agents の coding-rules の判定では **PHPDoc・インラインコメントは英日併記**になる。
- 本体ヘッダ：`Author: ETBS (DAI)` / `Author URI: https://etbs.jp` / `License: GPL-2.0-or-later`。

### 3.3 動作要件の宣言

- `Requires at least` / `Requires PHP` は**実在する下限があるときだけ書く。無ければ書かない**（ヘッダにも readme にも）。
- ★ **`Requires PHP` は 7.3 を宣言している**（1.2.1・issue #17）。柔軟なヒアドキュメント（`render_minimal_page()` の
  CSS）が唯一の拘束で、これが実在する下限。7.4 に丸めるのは過剰宣言になるので**しない**。
  `Requires at least` は、WordPress 側に実在する下限を特定していないので**引き続き無宣言**。
- 構文は **PHP 7.3 互換**で書く（アロー関数・型付きプロパティ・`??=`・`match`・`?->`・名前付き引数・`str_contains` などを使わない）。
  宣言が 7.3 だからであって、7.4 以上の構文を混ぜると宣言が嘘になる。
  ★ 宣言してあるので、WordPress は 7.3 未満のサイトへ更新リンクを出さない（`Requires PHP` は更新の応答に渡る）。
  無宣言だった頃の「警告なく更新を配ってしまう」はもう起きない。
- 手元で **PHP 7.3.5 / 7.4.30 / 8.3.17 の `php -l`** を全ファイル（PUC を除く）に通す。
  ★★ **CI の matrix は 7.4 / 8.3 だけで、宣言した下限の 7.3 を検査していない。** 宣言は利用者への約束なので、
  7.3 の `php -l` は手元で必ず通すこと（CI への 7.3 追加は別 issue）。
  ★ 実害は限定的：WordPress 7.1 本体が `$required_php_version = '7.4'` を要求するため、現行 WP を動かして
  いるサイトは原理的に 7.4 以上。7.3 が効くのは「古い WP ＋ PHP 7.3」の個体だけ。
- 新しい WP 関数を使うときは、その関数が存在しない WP でも Fatal にならないこと（`function_exists` か、フィルタ経由で「発火しないだけ」になる形）。

### 3.4 設定の保存

- 設定は `register_setting()` で登録し、sanitize コールバックで**許可した値だけ**を残す。
- 設定画面・保存とも `manage_options`。
- ★ `register_setting()` で保存するオプションは `update_option` の grep に現れない。uninstall の棚卸しで漏らさない。

### 3.5 サポート導線3面（初版から）

リンク先：「開発を支援」`https://etbs.jp/product/donate/`／「開発のご依頼」`https://etbs.jp/product-category/wordpress-tools/`。
UTM は `?utm_source=etbs-account-guard&utm_medium=plugin`、`target="_blank" rel="noopener noreferrer"`。

| 面 | フック | 限定条件 |
|---|---|---|
| プラグイン一覧の行 | `plugin_row_meta`（**4引数**で受ける） | `plugin_basename( ACGD_PLUGIN_FILE ) === $file` の行だけ |
| 設定画面のフッター | `admin_footer_text` | `settings_page_etbs-account-guard` の画面だけ（限定しないと全管理画面のフッターを乗っ取る） |

★ 当初は「ダッシュボード」（`wp_dashboard_setup` → `wp_add_dashboard_widget`）も3面目として持っていたが、
wordpress.org へ提出できる形にする過程（issue #13）でダッシュボードのウィジェット自体を外したため、
支援導線は上の2面になった。ウィジェットが持っていた「アクセス制限が止まっている」警告は、
`admin_notices` の通知（5.5 参照）に移した。

### 3.6 アンインストール（案A）

| 利用者が作ったコンテンツ | 利用者が設定した値 | 一時状態・自分が仕掛けた cron |
|---|---|---|
| 消さない | **消さない** | **消す** |

- 1.0.0：**残す**＝ログイン名の保護の設定。**消す**＝以前の自社配布版が同梱していた plugin-update-checker（v5p5）が残した、更新確認の状態（サイトオプション `external_updates-etbs-account-guard`。ライブラリがプラグインに付ける既定の名前で、`update_site_option()` で保存されていた）と、手動の更新確認のエラー（サイトの一時データ `puc_manual_check_errors-etbs-account-guard`。60秒）。★ wordpress.org 版（`wporg`）は plugin-update-checker を同梱しない（issue #13）ため、これらのキーはもう何によっても書き込まれないが、その版から入れ替えたサイトに残りうる残骸として、引き続き消す。同じ理由で cron イベント `puc_cron_check_updates-etbs-account-guard`（ライブラリの `Scheduler.php` が仕掛けていたもの）も消す。★★ このライブラリはこの cron を無効化のタイミングでしか自分で消さない（`register_deactivation_hook()`）ため、無効化・有効化を挟まずに自社配布版からこの版へ移行したサイト（有効なままファイルだけ入れ替えた場合など）では、このコードが無くなった後もフックが空振りし続ける。このプラグイン自身のコードは、1.0.0 以降テーブル・cron・一時状態を一度も持ったことが無い（消しているのは、いずれも過去に同梱していたライブラリの残骸）。
  ★★★ この cron の掃除は経路が2つある。**本来の経路**は `acgd_clear_legacy_puc_cron()`（`inc/func.php`・`admin_init`）で、この版を使い続けるサイトの管理者が wp-admin を開くたびに `wp_next_scheduled()` で予定の有無を確かめ、予定があるときだけ `wp_clear_scheduled_hook()` を呼ぶ一度きりの掃除（予定が無ければ何もせず、追加のクエリも発生しない。呼ぶたびに冪等）。`uninstall.php` 側の同じ呼び出しは**保険**として残す（削除しない）。移行後に管理画面を一度も開かずアンインストールされたサイトのため。
- 1.1.0：**残す**＝アクセス制限の設定、ユーザーメタ（モード・追加の IP・BASIC 認証の資格情報のハッシュ）。**消す**＝拒否の記録、受信診断の結果。
- 「消さない」ものは、`uninstall.php` の docblock に**なぜ消さないか**を書く（空にしない）。
- ★★★ **検証は管理画面の「削除」でやらない**（シンボリックリンク越しにこのリポジトリの中身が全部消える）。
  `require_once ABSPATH . 'wp-admin/includes/plugin.php'; uninstall_plugin( 'etbs-account-guard/etbs-account-guard.php' );` を CLI で呼ぶ。

### 3.7 版数の置き場

本体ヘッダの `Version:` と `readme.txt` の `Stable tag:`。**この2つだけ。**
置き場を増やしたら `CLAUDE.md` の「版数」節も直す。

- JS/CSS を読み込むときのキャッシュ用の版数は、**そのファイル自身の更新時刻**（`filemtime()`）を使う。`ACGD_VERSION` のような定数は**作らない**——作ると置き場が3つ目になり、リリースのたびに直し忘れる場所が増える。更新時刻なら手入れが要らず、ファイルが変わったときだけ変わる。

---

## 4. ログイン名の保護（1.0.0）

項目ごとに ON/OFF できる。**既定値は「入れれば塞がる」側**に倒す（OFF は既存の見た目・機能を壊しうる g だけ）。

| | 経路 | 対処 | 既定 |
|---|---|---|---|
| a | REST のユーザー関連ルート（`/wp/v2/users` 配下すべて。一覧・`/users/<id>`・`_embed=author` の埋め込み・`?rest_route=` 形式） | 未ログインには返さない | ON |
| b | oEmbed の `author_url`（`/author/<名前>/`）と `author_name`（表示名） | 本体が投稿者なしのときに使う値（サイト名・トップの URL）に置き換える | ON |
| c | ユーザーのサイトマップ（`wp-sitemap-users-*.xml`） | 出さない | ON |
| d | HTML のクラス名。登録ユーザーのコメントの `comment-author-<名前>`、投稿者ページの `<body>` の `author-<名前>` | 名前入りのクラスだけ外す | ON |
| e | `?author=数字` の転送（`/author/<名前>/` へ飛ぶ） | 管理画面の外で、トップへ転送する | ON |
| f | ログイン画面・パスワード再発行・REST API のアプリケーションパスワード（Basic 認証）の文言（「そのユーザー名は登録されていません」と「パスワードが違います」が別々に出る） | 名前の有無が分かる文言・画面の違いだけを共通にする | ON |
| g | 投稿者ページ `/author/<名前>/`（存在すれば 200、無ければ 404） | ログインしていない人には 404 にする（ログイン中は従来どおり） | **OFF** |
| h | 表示名・ニックネームがログイン名と同じ（フィードの `dc:creator` などに出る） | 設定画面に一覧を出すだけ（**自動では変えない**） | 常に表示 |

### 実装の要点

- **a**：**ルート単位で判定する。URL の文字列で判定しない**（`?rest_route=` と `/wp-json/` の両方、`_embed` の内部リクエストに同じ判定が掛かること）。
  判定は `is_user_logged_in()`。ログイン中（cookie＋nonce、アプリケーションパスワード）は従来どおり返す（ブロックエディタの投稿者欄が使う）。
  ★ 存在する ID と存在しない ID で**応答が区別できない**こと（ステータス・エラーコードとも同じ）。
- **b**：`oembed_response_data` で置き換える。JSON と XML（`format=xml`）の両方。
- **c**：`wp_sitemaps_add_provider` で `users` を外す。
- **d**：`comment_class` / `body_class`。本体の `sanitize_html_class( $nicename, $user_id )` は名前が空に落ちると ID を使うので、
  **名前入りのクラスだけ**を外し、`author-<ID>` など ID 入りのクラスは残す。
- **e**：本体の `redirect_canonical()`（`template_redirect` の優先度 10）より**前**に処理する。プレーンパーマリンクの `/?author=1`（転送されずに投稿者ページが出る）も対象。
  管理画面（`edit.php?author=` の絞り込みなど）・REST・admin-ajax には掛けない。
  判定は `$_GET` ではなく**解析済みのクエリ変数**（`$wp->query_vars['author']`）で行う。`WP::parse_request()` は公開クエリ変数をクエリ文字列からも POST のデータからも取り込み、メインクエリはその結果に従うが、`redirect_canonical()` が見るのは `$_GET` だけ。
  ただし転送するのは、**リクエスト自身が `author` を持ってきたとき**（`$_GET` か `$_POST` にキーがあるとき）だけ。`WP::parse_request()` は一致した書き換えルールからもクエリ変数を取り込むので、テーマやプラグインの書き換えルールが作った `author=` は、そのページ自身のアドレスとして転送しない。
- **f**：
  - ログイン：`authenticate` の結果が `invalid_username` / `invalid_email` / `incorrect_password` / `application_passwords_disabled` / `application_passwords_disabled_for_user` のときだけ、共通のエラー（コード `acgd_invalid_credentials`・文言 *The username or password you entered is incorrect.*）に差し替える。
    後ろの2つは、本体が `authenticate` の優先度 20 にも登録している `wp_authenticate_application_password()` が、REST API・XML-RPC のリクエスト（アプリケーションパスワードが作られたことのあるサイト）で、**存在するアカウントにだけ**返すもの（前段の `incorrect_password` を置き換える）。一覧は `ACGD_Login_Name::REVEALING_LOGIN_CODES` の1つだけにし、REST 側（下記）も同じものを使う。
    ★ コードは本体の `incorrect_password` を流用せず、独自のコードにする。`wp-login.php` はユーザー名欄を先頭のエラーコードが `incorrect_password`（と `empty_password`）のときだけ入力済みで戻すので、どの場合も欄が同じ状態（空）で戻るようにするため。独自のコードは `shake_error_codes` に足す（フォームは従来どおり揺れる）。
    共通のエラーは先頭に置く（ユーザー名欄・揺れ・REST の HTTP ステータスは先頭のコードで決まる）。置き場は `ACGD_Invalid_Credentials`（`inc/class-acgd-invalid-credentials.php`）で、1.1.0 のアクセス制限も同じものを使う。
    **それ以外のコード（空欄・画像認証・ログインロックなど他プラグインのエラー）は置き換えない**（職員の方が困る）。共通のエラーの後ろに残す。
    「パスワードをお忘れですか」のリンクは残す。
  - REST API（アプリケーションパスワードの Basic 認証）：アプリケーションパスワードは `authenticate` を通らず、`determine_current_user` で確かめられる。本体だけの構成なら失敗は `rest_not_logged_in` にまとまるが、`rest_authentication_errors` の優先度 90（`rest_application_password_check_errors`）より前に現在のユーザーを確定させるもの（他プラグインなど）があると、`invalid_username` と `incorrect_password` が別々の 401 で返る。
    `rest_authentication_errors` の優先度 9999 で、ログインと同じ一覧（`invalid_username` / `invalid_email` / `incorrect_password` / `application_passwords_disabled` / `application_passwords_disabled_for_user`）のどれかを含むエラーを、共通のエラー（`acgd_invalid_credentials`・status 401・文言はログインと同じ msgid）に差し替える。
  - パスワード再発行：名前の有無が分かるコード（アカウントが無いときの `invalidcombo` / `invalid_email`、再発行が許可されていない存在するアカウントにだけ返る `no_password_reset`）**だけ**のときは、存在するアカウントが通る関門 `apply_filters( 'allow_password_reset', true, 0 )` を先に通す。`WP_Error` が返ったら、名前の有無が分かるコードを消してそのエラーを出す（転送しない）。そうでなければ**アカウントがあるときと同じ画面**（`wp-login.php?checkemail=confirm`）へ進める。メールは送らない。
    関門のコールバックが ID 0 で例外（`Throwable`）を投げたら、許可（true）とみなす。
    名前の有無が分かるコードが**他のエラーと混ざっているとき**（空欄、`lostpassword_post` で足された画像認証など）は、名前の有無が分かるコードだけを `remove()` し、他のエラーは残す（転送しない）。
    ★ 文言を共通にするだけでは、アカウントがあるときは成功画面に進むので、名前の有無が分かってしまう。
    `retrieve_password_email_failure`（メール送信の失敗）は運用上の合図なので残す。
  - 既知の限界：WooCommerce のマイアカウントの**パスワード再発行フォーム**は独自の文言を出すので、1.0.0 の対象外（README に書く）。ログインフォームは `authenticate` を通るので対象になる。
  - 既知の限界：SiteGuard WP Plugin と併用するときは、SiteGuard の「ログイン詳細エラーメッセージの無効化」（Same Login Error Message）を ON のままにする。OFF だと画像認証エラーの文言が存在するアカウントにだけ出る（SiteGuard 自身の挙動）。README に書く。
  - 既知の限界：パスワード再発行の `retrieve_password_email_failure` と、メールを送るかどうかによる応答時間の差は残る。README に書く。
  - 既知の限界：ログインの応答時間の差。本体はアカウントがあるときだけ `wp_check_password()` を呼ぶので、アカウントの有無で応答時間に差が出る。空の照合を足すと、SiteGuard の画像認証の誤答（照合しない）と逆向きの差ができるため単純ではなく、**1.0.0 では塞がない**（README・readme.txt の既知の限界に、パスワード再発行の時間差と並べて書く）。
- **g**：`is_author()` かつ**ログインしていない**とき 404（投稿者のフィード `/author/<名前>/feed/`・`?author_name=` を含む）。ログイン中は従来どおり表示する（設定画面の説明に「結果はブラウザーのプライベートウィンドウで確かめる」旨と、オンにする前にテーマが投稿者ページへリンクしているかを確かめる手順を書く）。
- **h**：`display_name === user_login` または `nickname === user_login` のユーザーを、設定画面のログイン名の保護タブに一覧する（見出しの id は `acgd-public-names`）。列はログイン名 (ユーザー名)・表示名・ニックネームで、ログイン名と同じ値には「(ログイン名と同じ)」を文字で添える。各行のログイン名の下に、ユーザー一覧と同じ形の「編集」リンク（ユーザー編集画面の `#nickname`）を置く。
  ★ 当初はダッシュボードのウィジェットの先頭にも該当する人数と一覧へのリンクを出していたが、issue #13 で
  ウィジェット自体を外したのに合わせてこの表示も外した（設定画面のタブに既にある一覧と重複するうえ、
  常時出る宣伝的な通知になるのを避けるため）。この人数は、上記の設定画面のタブでのみ確認できる。

### 受け入れ条件（1.0.0）

未ログイン（cookie なし）で、検証サイトに対して実測し、**生出力を PR 本文に貼る**。

- [ ] `GET /wp-json/wp/v2/users`・`/?rest_route=/wp/v2/users`・`/wp-json/wp/v2/users/<存在するID>` がユーザーを返さない。`<存在しないID>` と応答が区別できない
- [ ] `GET /wp-json/wp/v2/posts?_embed=author`（または pages）の埋め込みに `slug` / `link` のユーザー情報が無い
- [ ] ログイン中（ブロックエディタ）は投稿者欄が従来どおり使える（REST をログイン状態で叩いて一覧が返る）
- [ ] 優先度 90 より前で `is_user_logged_in()` を呼ぶ検証用の mu-plugin を置いた状態で、アプリケーションパスワードの Basic 認証（存在しない名前／存在する名前＋誤ったパスワード）の REST 応答が同じ
- [ ] `GET /wp-json/oembed/1.0/embed?url=<トップ or 投稿>` の `author_url` がトップの URL、`author_name` がサイト名。`format=xml` も同じ
- [ ] `GET /wp-sitemap.xml` に users が無く、`/wp-sitemap-users-1.xml` が 404
- [ ] 登録ユーザーのコメントの HTML に `comment-author-<名前>` が無い
- [ ] `GET /?author=1` の `Location` がトップで、`/author/` を含まない（パーマリンク設定が「基本」でも同じ）。管理画面の `edit.php?author=1` は従来どおり
- [ ] `curl -X POST -d author=1 <トップ>` の `Location` がトップ
- [ ] ログイン：存在しないユーザー名と、存在するユーザー名＋誤ったパスワードで、**画面の文言が同じ**
- [ ] パスワード再発行：存在しないユーザー名と存在するユーザー名で、**進む画面が同じ**
- [ ] SiteGuard WP Plugin 1.7.8（既定設定）を入れた状態で、ログインとパスワード再発行それぞれ「存在する名前／存在しない名前 × 画像認証なし相当／画像認証を空欄・誤答」で、**画面（文言・ユーザー名欄の `value`・転送先）が名前の有無で区別できない**
- [ ] g を ON：`/author/<名前>/` が 404。OFF（既定）：従来どおり
- [ ] g はログイン中の人には掛からない（g を ON でも、ログイン中は `/author/<名前>/` が従来どおり表示される）
- [ ] h：表示名がログイン名と同じユーザーが一覧に出る
- [ ] 各項目を OFF にすると、その経路が従来の挙動に戻る
- [ ] サイトの言語が日本語のとき、画面が日本語で出る（`switch_to_locale( 'ja' )` で `.po` の msgid を全件 `__()` に通して突合）

---

## 5. アクセス制限（1.1.0）

1.1.0 は **PR を2本に分ける**：B＝IP 制限とアクセス制限の土台（対象の決め方・保存時のチェック・画面・拒否の記録・非常用スイッチ）／C＝BASIC 認証。
**B が dist に入るまで C に着手しない。** データの形は B の時点で3モード（`none` / `ip` / `basic`）を持たせ、C で移し替えが要らないようにする。

### 5.1 対象の決め方

| 段 | 選べる値 | 既定 |
|---|---|---|
| 権限ごと | 制限なし／IP／BASIC | **すべての権限が「制限なし」**。一覧はサイトにある権限をそのまま出す（他プラグインの独自の権限も並ぶ） |
| ユーザーごと | 権限の設定に従う／制限なし／IP／BASIC | 権限の設定に従う |

- **ユーザーの設定が権限の設定より優先。**
- 1人が複数の権限を持ち、設定が食い違う場合は、**求められているものを全部課す**（IP と BASIC の両方）。
- **administrator の権限単位の設定は「制限なし」で固定**（画面では選べない状態で表示）。ユーザー単位なら管理者も制限できる。
- ★ **保存時のチェック**（どちらかを満たさない設定は保存させず、理由を表示する）：
  1. 保存後も、**制限されていない `manage_options` のユーザーが1人以上**残る
  2. **保存する本人の新しい設定を、いまのアクセスが満たしている**（IP なら今の接続元が許可されている、BASIC なら今のリクエストに本人の資格情報が付いている）
  3. BASIC モードになるユーザーは、全員が資格情報を設定済み

### 5.2 IP 制限

- **ログイン時**（wp-login.php・XML-RPC など `wp_authenticate()` を通るもの）：**パスワードを照合する前に**判定する。
  判定は **`wp_authenticate_user`** で行う（本体の `wp_authenticate_username_password()` / `wp_authenticate_email_password()` が、ユーザー名・メールアドレスからユーザーを引いた後・パスワードを照合する前に通すフィルタ）。
  対象かつ許可されていない接続元なら、**f と同じ共通のエラー**（`ACGD_Invalid_Credentials` の `acgd_invalid_credentials`）を返して拒否する（場所が理由だと明かさない）。存在しないユーザー名との区別を付けない。
  ★ `authenticate` の優先度 20 より前で拒否しても効かない。本体の `wp_authenticate_username_password()` は、ユーザー名とパスワードが両方あると前段の `WP_Error` を捨てて照合をやり直す（先回りを認めるのは `WP_User` だけ）。
  ★ **画像認証などを足すプラグインも `wp_authenticate_user` で動く**（SiteGuard WP Plugin 1.7.8 は優先度 1）。同じフックに並ぶので、優先度の前後で「許可されていない接続元からの対象ユーザー」への応答が変わる。
  **IP の判定は、それらより後（優先度 99 など）に置く。** 画像認証が先に効けば、画像認証を通らない入力は「対象か・許可されているか」に関係なく同じ画像認証のエラーになり、画像認証を通った入力だけが IP の判定に進んで、誤ったパスワードと同じ共通のエラーになる。
  逆に IP の判定を先に置くと、対象ユーザーだけが画像認証の成否に関係なく共通のエラーになり、画像認証の誤答で「対象かどうか」が分かる。
  `wp_authenticate_user` はどの優先度でもパスワードの照合より前に通るので、後ろに置いても「パスワードを照合する前に判定する」は崩れない。優先度とその理由をコメントに書く。
- ★ **REST の判定の優先度**：1.1.0 で `rest_authentication_errors` に掛ける判定は、**優先度 100（本体の cookie の確認）より後・9999（f の差し替え）より前**に置く。
  優先度 90（`rest_application_password_check_errors`）より前に掛けたり、そこで `wp_get_current_user()` / `is_user_logged_in()` を呼んで現在のユーザーを早く確定させたりすると、
  アプリケーションパスワードの失敗が 90 で `invalid_username` / `incorrect_password` などの別々の 401 として返り、**このプラグイン自身が名前の有無の区別を作る**（f が OFF なら、そのまま利用者に見える）。
  失敗を返すのは 90 だけなので、それより後で現在のユーザーを確定させても失敗は返らない。100 より後なら、本体の cookie の確認が通常すでに現在のユーザーを確定させている。
- **ログイン後の毎回のアクセス**（フロント・管理画面・admin-ajax・admin-post・REST）：許可されていなければ、
  **そのセッションだけ破棄して**（`WP_Session_Tokens::destroy( wp_get_session_token() )`＋auth cookie の削除＋`wp_set_current_user( 0 )`）、
  **未ログインとして処理を続ける**。403 で止めない（公開ページやログインなしの予約フォームは、訪問者と同じように使えること）。
  ★ `/wp-admin/` だけの判定では、ログインしている人向けの `wp_ajax_*` や `admin_post_*` が素通りになる。
- **アプリケーションパスワード**：対象のユーザーでは `wp_is_application_passwords_available_for_user` で使えなくする。REST 側でも `rest_authentication_errors` で判定する。
  ★ アプリケーションパスワードは `determine_current_user`（優先度 20）で認証され、`authenticate` / `wp_login` を通らない。
  ★ `REST_REQUEST` は `parse_request` で定義されるので、`init` の時点では REST かどうか分からない。
- ★ **`init` などで早くに `wp_get_current_user()` を確定させると、本体の認証（アプリケーションパスワードなど）の判定順を変えてしまう**ことがある。判定の位置は本体の順序を確かめてから決め、根拠をコメントに書く。

**IP の一覧**
- **サイトに1つ＋ユーザーごとの追加**。どちらかに入っていれば許可。
- 1行に1つ。IPv4／IPv6、単独と範囲（CIDR）。`#` の後ろはメモ。不正な行は保存時に弾いて、どの行かを表示する。
- ★ `::ffff:1.2.3.4`（IPv4-mapped）は IPv4 に直してから比べる。**通る値の集合を列挙してから正規化関数を書く**（PageGuard で `::ffff:` を `::/64` に潰し、全 IPv4 訪問者が1つのバケットを共有した）。
- 判定は **`REMOTE_ADDR` だけ**。`X-Forwarded-For` などのヘッダは使わず、選ぶ設定も作らない（利用者側で書き換えられる）。
- 設定画面に「サーバから見えている、いまの接続元」を表示する。
- ★★ **保存時に `ACGD_Access_Restriction::sanitize_ip_list_text()` を通す**（1.2.1・wordpress.org 審査の指摘）。
  中身は `sanitize_textarea_field()` だが、**欄まるごとではなく1行ずつ**掛ける。アドレスと CIDR の
  文字集合（`0-9 a-f A-F : . /`）は一切影響を受けないので、**通る許可先が変わることは無い**。ただし
  **`#` 以降のメモは加工されうる**：`<` を含むメモはタグに見える部分が落ち、パーセント符号化（`%` ＋16進2桁）
  はその部分が消える。`%xx` を含む行は、**その行の**連続する空白が1つに詰められる（桁揃えが崩れる）。
  他の行は影響を受けない。
- ★★★ **行ごとに掛ける理由**：`wp_check_invalid_utf8()` は**渡された文字列全体**について答えるため、
  欄まるごとに掛けると、どこか1バイトの不正が——`#` 以降のメモの中であっても——**欄全体を空文字にする**。
  そのまま保存すると利用者の一覧が無言で全部消える。行ごとなら被害はその行に留まる。中身があったのに
  空になった行は **U+FFFD に差し替える**。U+FFFD 自体は正しい UTF-8 で、アドレスにも範囲にも決してならない
  ため、`validate_ip_list()` の既存の「不正な行」の文言でそのまま報告される（新しい訳語が要らない）。
- ★★★ **生の文字列に戻してはいけない**（1.2.1 の実装過程で一度この形にして、実測で否定した）。
  不正なバイトが `#` 以降のメモにあると `strip_comment()` が検証前に切り落とすため `validate_ip_list()` まで
  届かず、一覧は「保存成功」に見える。その後 `$wpdb->process_fields()` が書き込みを拒否し、
  `update_user_meta()` / `update_option()` はキャッシュに触れないまま `false` を返すが、呼び出し側は
  それを見ていない＝**無言の空振り保存**になる。`update_option()` 側は設定配列を丸ごと1つの列値にするので、
  同じ送信で変えた権限モードごと捨てられる。`esc_textarea()` もそのバイトを描画できない
  （`ENT_SUBSTITUTE` を付けずに `ENT_QUOTES` を渡すので空文字を返す＝テキストエリアが空になる）。
- ★ 送信値が文字列ですらない場合（細工された配列など）も、空ではなく U+FFFD の1行を返して拒否させる。
- ★ 設定タブ側は `wp_unslash()` を呼ばない。Settings API が `$input` を渡す前に unslash 済み
  （`wp-admin/options.php`）。呼ぶと `#` のメモからバックスラッシュが1つ食われる。

### 5.3 BASIC 認証

★★ **送信された ID は、受信の2つの分岐で同じように `sanitize_text_field()` を通す**（1.2.1・wordpress.org 審査の指摘）。
保存側（`ACGD_User_Access::save_fields()`・`maybe_handle_verify_request()`）が同じ関数を通しているため、
`hash_equals()` の左右を揃える必要がある。分岐は2つあり、どちらを通るかは**サーバー構成で決まる**——
本体の `wp_populate_basic_auth_from_authorization_header()` は `%^Basic [a-z\d/+]*={0,2}$%i` に一致しない
ヘッダーを見送るので、URL-safe base64 や空白が複数あるヘッダーは `PHP_AUTH_*` に展開されず、
`Authorization` ヘッダーを自前で解析する側へ落ちる。片方だけ揃えると、**環境によって通ったり通らなかったりする ID**
が生まれる。★ これにより、ID に連続した空白を含めて保存した利用者の「確認」が理由表示なしに永久に失敗する
不具合（保存側だけ空白が畳まれていた）も解消した。

★★ **パスワードはサニタイズしない**（受信側・保存側とも）。`password_verify()` と `hash_equals()` にしか渡らず、
出力も SQL も経由せず、保存されるのはハッシュだけである。`sanitize_text_field()` はタグを除去し空白を畳み
パーセント符号化を削るため、それらの文字を含むパスワードは**保存できるのに二度と受理されなくなる**。
本体も `wp-includes/user.php` で `$_SERVER['PHP_AUTH_PW']` をサニタイズも `wp_unslash()` もせず使っている。
★ 片側だけ正規化しないこと。

- 資格情報は**ユーザーごとに1組**（ID とパスワード）。ID はサイト内で重複させない。パスワードは `password_hash` で保存し、**再表示しない**。照合は `password_verify`、文字列比較は `hash_equals`。
- 認証ヘッダは `PHP_AUTH_USER` / `PHP_AUTH_PW` に加え、`HTTP_AUTHORIZATION` / `REDIRECT_HTTP_AUTHORIZATION` を自前で base64 デコードして受け取る（CGI/FastCGI では `PHP_AUTH_*` が入らない）。PageGuard の実装を参考にする。
- ★★★ **このプラグインの資格情報だと確かめたら、その場で `$_SERVER` の `PHP_AUTH_USER` / `PHP_AUTH_PW` / `HTTP_AUTHORIZATION` / `REDIRECT_HTTP_AUTHORIZATION` を消す。**
  残すと、本体がそれをアプリケーションパスワードとして照合し（`wp_authenticate_application_password`）、失敗が記録されて
  （`application_password_failed_authentication` → `rest_application_password_collect_status`）、
  **cookie でユーザーを特定できていない REST のリクエストが丸ごと 401 になる**（`rest_application_password_check_errors`）。
  ブラウザがサイト全体へ資格情報を送る状態のままログアウトすると、その端末で公開の REST（カートなど）が壊れる。
- **確認画面は `wp-login.php` の階層で出す**（例：`wp-login.php?action=acgd_basic`）。ブラウザは確認を出された URL の階層より下へ資格情報を自動で送るので、
  ルート階層で出せば管理画面・admin-ajax・REST すべてに最初から付く。realm はサイトごとに1つ。
- BASIC モードのユーザーがログインしたら、資格情報が付いていなければ確認画面を経由させる（`redirect_to` は `wp_validate_redirect` で検証する）。
- 資格情報が付いていない・違うアクセスは、**そのリクエストだけ未ログインとして扱い、セッションは消さない**。管理画面のときだけ確認画面へ送る。
  キャンセルしたときの 401 の本文には、ログアウトの手段（nonce 付き）を置く。
- 「確認」ボタンは、ID とパスワードの両方が入力されていれば**モードに関わらず**押せる（issue #8。確認は「この ID とパスワードが通るか」の検査であり、モードとは独立している）。自分を BASIC モードにするときは、モードを「制限なし」「IP 制限」のままでも先に自分の資格情報を確認画面に通しておき、そのあと「BASIC 認証」に切り替えて保存する（5.1 の保存時のチェック 2 のため。保存時のチェックはモードが `basic` のときだけ働き、変更しない）。
- **受信の診断**を設ける（PageGuard の受信診断を参考にする）。認証ヘッダが PHP まで届かないサーバ、または**サーバ側の BASIC 認証が既に掛かっているサイト**（1回のリクエストに付けられる認証ヘッダは1つだけで両立しない）では、BASIC モードを保存させない。
  届かない場合に貼る `.htaccess` のスニペットは**表示するだけ**で、自動で書き換えない。
- サイトが HTTPS でなければ警告する（BASIC 認証は毎回資格情報を送る）。
- ★ **「確認」ボタンを押すと、本体自身の「行った変更が失われます」の離脱警告が出ることがある（issue #7）。** これはこのフォーム（`<form id="your-profile">`）の実際の送信・離脱であり、本体の `wp-admin/js/user-profile.js` はその見張りを `#submit` / `#wp-submit` / `#createusersub` のクリックでしか下ろさないため、「確認」は意図的にそのどれでもない以上、他の離脱と区別なく警告の対象になりうる。クライアント側で「本物の警告」と見分けて抑え込む案は検討のうえ見送った：`beforeunload` の listener はページ全体で1本しかなく、本体の警告だけを狙って黙らせる方法が無いため（黙らせれば、無関係な他の警告まで一緒に消える）。代わりに、「確認」ボタンの近くに「警告が出てもここで入力した ID とパスワードは失われない」旨の説明を表示する（`ACGD_User_Access::render_fields()`）。この区画自身の項目（モード・IP 一覧・BASIC の ID）は、`ACGD_Basic_Auth::maybe_handle_verify_request()` が確認画面へリダイレクトする前に必ず `ACGD_User_Access::stash_resubmit()` で保管しており、戻ってきた画面で出し直されるため、実際に失われることはない。
- ★ **企業のネット閲覧を中継するサービス（リモートブラウザ分離）を通る端末で、BASIC 認証の確認画面が出せるかは未検証。** 導入先で使う前に確かめる。
- ★ **既知の限界：「確認」で確認画面を通ったが保存せずにログアウトした端末では、確認の保持期間（`ACGD_Basic_Auth::VERIFY_TTL`）が切れてもブラウザ側が覚えた資格情報の送信は止まらない。** その状態で REST のリクエストを出すと、本体がそれをアプリケーションパスワードとして解釈しようとして 401 になり得る（SSL 下で、誰か1人でもアプリケーションパスワードを作っている場合）。保存済みの資格情報を消す経路（優先度1・`maybe_strip_own_header()`）も確認済みを消す経路（優先度15・`maybe_strip_confirmed_header()`）も、この状況では cookie から現在のユーザーを特定できないため何もしない（5.5 の該当箇所を参照）。影響は設定を途中でやめた本人の端末だけに留まり、ブラウザを再起動すれば解消する。★ このプラグインが作った欠陥ではない：このコールバックが無かった以前は、確認から保存までの窓の間ずっと同じ状態が無防備に続いていたので、これは純粋な改善である。

### 5.4 拒否の記録

- 日時・ユーザー・接続元の IP・場面（ログイン時／ログイン後／REST／アプリケーションパスワード／BASIC）。**直近100件**。オプションに保存し **autoload しない**。
- 設定画面の「拒否の記録」タブに表示。uninstall で消す。

### 5.5 失敗したとき・戻し方

| 種類 | 例 | 動作 |
|---|---|---|
| プラグイン自身の故障 | 設定が読めない・壊れている、想定外の例外（`Throwable`） | **アクセス制限を止めて通す**。`manage_options` の人の管理画面に「アクセス制限は停止中」の警告を出す |
| 条件を満たさないアクセス | 一覧外の IP、資格情報が無い・違う、`REMOTE_ADDR` が読めない | **通さない**（拒否の記録を残す） |

- ★ **例外：判定そのものを行わない経路の例外は記録しない。** `ACGD_Basic_Auth::maybe_strip_confirmed_header()`（`determine_current_user` の優先度15）がそれで、ここは「確認済み・保存前の BASIC 資格情報を `$_SERVER` から剥がす」だけの経路であり、誰を通すかの判定には一切関与しない。上の表の「止めて通す＋警告」は記録（`record_fault()`）を伴い、それは **`is_disabled()` = `is_switch_disabled() || has_fault()`** という機能停止スイッチを引くことを意味する。**この経路の失敗の代償**は、確認から保存までの窓の間、その管理者のプロフィール画面に本体の赤い通知（「サイトでは Basic 認証が使われているようですが……」）が緑の隣に並ぶことに留まる。**REST の 401 はこの経路の失敗では起きない**：401 になるのは cookie でユーザーを特定できていないリクエストだけ（5.3 ★★★。本体の `wp_validate_application_password()` は `! empty( $input_user )` で先に戻る）で、そういうリクエストではこのコールバックは `$admin_id <= 0` で何もせず戻るため、例外があってもなくても結果は同じ。**スイッチを引く代償のほうが大きい**ので引かない。
  - この経路では **`WP_DEBUG` が真のときだけ `error_log()` に記録し、機能は止めない**（省略ではなく決定）。記録するのは例外のクラス名とメッセージだけで、**こちらから資格情報（ID・パスワード・ヘッダーの値）を載せることはしない**。★ 記録するメッセージの文面自体は例外を投げた側（本体・PHP）が作るものであり、このコード自身が作るものではないため、その文面に何が含まれるかまでは保証しない。何も残さないと、将来の `TypeError` やオブジェクトキャッシュの障害で 5.3 ★★★ の保護が黙って効かなくなったときに誰も気付けないため。
- **非常用スイッチ**：`wp-config.php` に `define( 'ACGD_DISABLE_RESTRICTION', true );` を書くと、**アクセス制限だけ**が止まる（ログイン名の保護はそのまま）。
  使い方そのものは **README・readme.txt・設定画面のアクセス制限タブ**の3か所に書く。
  ★ 当初はここにダッシュボードのウィジェットの注意事項も含めていたが、issue #13 でウィジェットを外したため、
  スイッチが実際に有効なときは代わりに `admin_notices` の警告（notice-warning。5.5・`acgd_access_restriction_admin_notices()`
  参照）が、設定画面以外の管理画面で状態を知らせ、アクセス制限タブへリンクする。ログイン画面には出さない。
- メールで復旧リンクを送る仕組みは作らない（新しい入口を作らない）。

### 5.6 画面

| 場所 | 中身 | 見られる人 |
|---|---|---|
| 設定 > ETBS Account Guard（タブ3つ） | ログイン名の保護／アクセス制限（権限ごとのモード、サイトの IP 一覧、いまの接続元、受信の診断、**結果として制限されるユーザーの一覧**、非常用スイッチの説明）／拒否の記録 | `manage_options` |
| ユーザーの編集画面（1区画） | モード、追加の IP、BASIC 認証の ID とパスワード（設定・変更のみ）、自分用の「確認」ボタン | 編集・閲覧とも `manage_options` のみ |
| ユーザー一覧 | 「アクセス制限」の列（最終的に効いているモード） | `manage_options` |
| 本人のプロフィール画面（`profile.php`） | `manage_options` を持たない人には何も出さない（許可されている場所の手がかりを与えない）。`manage_options` を持つ人には、他人用のユーザー編集画面と同じ区画を出す（設定画面で同じ情報を既に見られるため、新しい手がかりにはならない） | `manage_options` を持つ人のみ |

★ 「ユーザーの編集画面」の区画は、本体の `edit_user_profile`（他人を編集するとき）に加えて `show_user_profile`
（`profile.php` で本人を編集するとき）にも登録している（issue #4 の decision record。麗美の実機テストで、
`get_edit_profile_url()` を経由しない `user-edit.php?user_id=<自分のID>` への生のリンクを置く旧来の設計が
機能しないと判明したため：本体の `IS_PROFILE_PAGE` は対象ユーザー ID が本人かどうかだけで決まり、
`profile.php` を経由したかどうかとは無関係で、`user-edit.php` へ直接アクセスしても対象が自分自身なら
必ず本人用のフック（`show_user_profile`）が発火する）。表示・保存とも、他人用の画面と全く同じ条件
——`manage_options`——で限定しており、「本人かどうか」では限定していない。`manage_options` を持たない人
（実際に制限を受ける側）の本人のプロフィール画面には引き続き何も出ず、上の行の「何も出さない」は変わらない。
`manage_options` を持つ人が自分自身の BASIC 認証資格情報を設定・確認する（5.3 の「『確認』ボタンは
ID とパスワードの両方が入力されていればモードに関わらず押せる」）導線も、この本人用の画面（＝WordPress 標準の
「プロフィール」。ツールバー・管理画面メニューから辿れる）から行う。

### 受け入れ条件（1.1.0。B と C でそれぞれ該当分）

- [ ] IP：対象ユーザーが一覧外の IP からログインできない（文言は存在しないユーザー名と同じ）。一覧内からは入れる
- [ ] IP：一覧内でログインした後、一覧外の IP から同じ cookie でアクセスすると、そのセッションが破棄され未ログインになる（管理画面・admin-ajax・admin-post・REST のそれぞれ）
- [ ] IP：対象ユーザーのアプリケーションパスワードで REST を叩けない
- [ ] IP：**SiteGuard WP Plugin（既定設定）を入れた状態で**、ログインについて「存在しない名前／対象ユーザー・一覧外の IP（正しいパスワード・誤ったパスワード）／対象ユーザー・一覧内の IP（誤ったパスワード）／対象外のユーザー（誤ったパスワード）」×「画像認証なし相当／空欄・誤答」の画面（文言・ユーザー名欄の `value`・転送先）が、名前の有無と許可の有無で区別できない。
  あわせて SiteGuard の「ログイン詳細エラーメッセージの無効化」を OFF にした状態でも測り、区別できる組があれば README の既知の限界に書く。
  ★ 単体の緑は証拠にならない（1.0.0 で、SiteGuard と組んだときだけ有無が分かる欠陥を実際に踏んだ。`~/.claude/etbs-plugin-rules.md` 3節）。画像認証は解かない・回避しない（「通った場合」は該当の画像認証を OFF にして代用する）
- [ ] 対象外のユーザー・ログインしない訪問者・ログインなしの admin-ajax / admin-post は影響を受けない
- [ ] 保存時のチェック1〜3が効く（制限されていない管理者が0人になる設定、本人が満たさない設定、資格情報の無い BASIC モードは保存できない）
- [ ] 非常用スイッチで、アクセス制限だけが止まる
- [ ] 設定が壊れているとき、アクセス制限が止まって警告が出る
- [ ] BASIC：確認画面を通った後、管理画面・admin-ajax・REST（ブロックエディタ）が使える
- [ ] BASIC：ログアウト後もブラウザが資格情報を送り続ける状態で、公開の REST が 401 にならない（`$_SERVER` から消していることの確認）
- [ ] BASIC：別のユーザーの資格情報では通れない
- [ ] 受信の診断が、ヘッダが届かない状態・サーバ側 BASIC 認証が掛かっている状態を見分け、そのとき BASIC モードを保存させない
- [ ] 拒否の記録が100件で頭打ちになり、autoload されていない

---

## 6. 検証のしかた

- 検証サイトは Local の ai-wp-demo（`CLAUDE.local.md`）。プラグインはリポジトリへのシンボリックリンクで置く。
  ★ worktree で作業するときは、リンクの向き先を確認してから検証する（本体クローンを指したままだと、古いコードを検証することになる）。終わったら戻す。
- ブラウザを使わない検証：`wp-load.php` を CLI で読み、フィルタ・関数を直接叩く。Local の `php.ini` を `-c` で渡す（渡さないと DB 接続エラーになり、サイトが止まっているように見える）。
- HTTP の実測は `curl`。**HTTP のステータスだけで合否を決めない**（本文・`Location`・JSON の中身で判定する）。
- 配布物の検証：`git archive --format=tar HEAD | tar -t | sort` を取り、追跡している開発用ファイル（`CLAUDE.md` / `docs/` / `.github/` / `composer.*` / `.phpcs.xml.dist`）と
  `inc/plugin-update-checker/` 一式・`inc/update-checker.php` ・`inc/dashboard-widget.php`（issue #13 で外した。wordpress.org は独自の更新機構を認めず、審査で問われうる）が入っておらず、
  `languages/*.mo` が入っていることを確かめる。
