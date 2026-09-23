# ETBS Account Guard 開発時の制約

サイトを管理するアカウントを守る WordPress プラグイン（ログイン名の保護／アクセス制限）。
**確定仕様は [`docs/spec.md`](docs/spec.md) が正本。**

etbs のプラグイン共通ルールと既知の罠は `~/.claude/etbs-plugin-rules.md` にある。**着手前に必ず読むこと。**
ローカル環境（検証サイト・PHP バイナリのパスなど）は `CLAUDE.local.md` にある（git 管理外）。

## ★ この repo は public

施設名・顧客名・IP アドレス・実在のアカウント名を、ファイル・コミット・PR・issue に書かない。
導入先ごとの事情は非公開の台帳（`etbsjp/task-queue`）にだけ残す。
検証の出力を PR 本文に貼るときも、ホスト名・ユーザー名が検証用の中立な値になっているか確かめてから貼る。

## 名前とバージョン

| 項目 | 値 |
|---|---|
| 名称 | ETBS Account Guard |
| スラッグ・フォルダ・テキストドメイン | `etbs-account-guard` |
| 関数・オプション・フック・ユーザーメタのプレフィックス | `acgd_` |
| 定数・クラスのプレフィックス | `ACGD_`（自プラグイン判定用に `ACGD_PLUGIN_FILE` を持つ） |
| 設定画面の画面ID | `settings_page_etbs-account-guard` |
| リポジトリ | `etbsjp/etbs-account-guard` |
| ブランチ | **既定ブランチは `wporg`。作業・PR は `wporg` 向け。** `dist` は既存の自社配布サイト（更新チェッカー同梱版）への配信元として凍結しており、**触らない（push・マージ・改名・削除のいずれも禁止）**。更新チェッカーを外した版が `dist` に入ると、その版を見ている既存サイトが公式ディレクトリへ更新を問い合わせ始め、スラッグを確保する前に他人のプラグインを「更新」として受け取りうるため |

**版数の置き場**：本体ヘッダの `Version:` と `readme.txt` の `Stable tag:`。
★ 置き場を増やしたら（JS/CSS の読み込みで `ACGD_VERSION` を作るなど）この節を直すこと。
★ JS/CSS のキャッシュ用の版数は **`filemtime()`（ファイルの更新時刻）** を使う（仕様書 3.7）。`ACGD_VERSION` は作らないので、置き場は上の2つのまま。

★★ **版数は実装の PR で上げない。** 公式ディレクトリへは SVN へ commit した瞬間に配信される。その commit は人が行う。
版数上げは人が判断して行う（etbs-plugin-rules.md の運用）。

## 言語・コメント

- UI の原文は英語、日本語訳を `languages/` に同梱する。**`init` で `load_plugin_textdomain()` を呼ぶことは必須**（WP 7.1 の罠。仕様書 3.1）
- `readme.txt` は英語。したがって **PHPDoc・インラインコメントは英日併記**（vk-agents の coding-rules の判定・分岐3）
- `.po` を変えたら `.mo` を作り直す

## 動作要件

- `Requires at least` / `Requires PHP` は**実在する下限があるときだけ書く。無ければ書かない**（ヘッダにも readme にも）
- **構文は PHP 7.3 互換**（アロー関数・型付きプロパティ・`??=`・`match`・`?->`・名前付き引数・`str_contains` などは不可）
- 実装後は **PHP 7.3.5 / 7.4.30 / 8.3.17 の `php -l`** を全ファイル（PUC を除く）に通す（パスは `CLAUDE.local.md`）

## アンインストール

★ `uninstall.php` の方針は**案A**（task-queue #108）。

| 利用者が作ったコンテンツ | 利用者が設定した値 | 一時状態・自分が仕掛けた cron |
|---|---|---|
| **消さない** | **消さない** | **消す** |

当てはめは仕様書 3.6。★★★ **検証は管理画面の「削除」でやらない**（シンボリックリンク越しにこのリポジトリが空になる）。
`uninstall_plugin()` を CLI で呼ぶ。

## 絶対にやってはいけないこと

- **`.htaccess` を自動で書き換えない**（表示に留める）
- **設定済みのパスワード（BASIC 認証）を画面に再表示しない**
- **`X-Forwarded-For` などのヘッダで接続元を判定しない**（`REMOTE_ADDR` だけ）
- **JavaScript で判定しない**（止めれば回避できる。表示用途だけ）
- **`-old` などのバックアップファイルを作らない**
- **`.gitattributes` に `/languages/` を入れない。新しいパターンは必ず先頭 `/` でアンカーする**
- **`phpcbf` を走らせない**

## PR の書き方

- タイトルは日本語、分類は `[ 機能追加 ]` などの既定の分類から（vk-agents の change-title.md）
- この repo の issue は `Closes #<番号>` で閉じてよい（**同じ repo の issue に限る**。閉じるキーワードは repo をまたいで効くので、task-queue の issue には付けない）
- task-queue 側の台帳は、**完全な URL を参照だけ**で書く：`対象 issue: https://github.com/etbsjp/task-queue/issues/258`
- 検証の生出力（`curl`・CLI・`php -l`・`git archive` の突合）を本文に貼る

## レビュー工程に大（シニアエンジニア）を追加する

このリポジトリでは、安藤（`vk-code-reviewer`）のレビューのあと、**PR を作成する前に**
大（`etbs-senior-wp`）の監査を必ず通すこと。大は etbs の申し送りと過去に踏んだ罠に照らして
「リリースできる形になっているか」を見る担当で、安藤の一般的なコード品質レビューとは層が違う。
認証まわりのプラグインという性質上、コード品質だけでなく `etbs-plugin-rules.md` の既知の罠に照らした監査を必ず挟むこと。

- `Agent` ツールで `subagent_type: etbs-senior-wp`、`name: etbs-senior-wp`、
  **`run_in_background: false`** で起動する
- **`isolation: "worktree"` は使えるなら付ける**（付けないと起動応答は「成功」と返るのに
  一度も作業せず待機状態に入ることがある）。ただし ★★ **作業ディレクトリが git リポジトリでないと
  使えない**。その場合は **isolation なしで起動してよい**。
  **見分け方は起動応答の形**——`output_file` 付きの正常形なら動いている
- prompt には対象リポジトリ・ブランチ・差分（または PR 番号）を渡す
- 大には **出力の末尾に `監査結果: PASS` または `監査結果: FAIL` を必ず書くよう指示する**
  （★ 大の定義ファイルには出力形式の指定が無いため、指示しないと合否を機械判定できない）
- 大には**監査の本文を PR コメントとして残させ、対象の commit を明記させる**（HEAD と一致しない監査は監査ではない）
- `監査結果: PASS` を受け取るまで PR を作成しない。`FAIL` なら和田へ差し戻して再監査する

★ 大は vk-agents のメンバー表に登録されていないため、指示が無いと**永久に呼ばれない**。

## CI

**共通ルールは `~/.claude/etbs-plugin-rules.md` の 2.7 節。そちらの内容はここに転記しない。**
ここに置くのは **このリポジトリでしか決まらない値**だけ。

- 定義は `.github/workflows/ci.yml`（原本 widget-shortcode-tools と byte 一致ではなくなった。`push.branches` に
  `wporg` を足しているため。issue #13）。PR ごとに `php -l`（PHP 7.4 / 8.3）と
  `PHPCS (WordPress-Extra, changed lines)` が走る。`wporg` / `dist` への直 push では `php -l` の2つだけ走る
  （`dist` は版数上げを人が直接 push する運用のため、`wporg` は版数上げの直 push でも構文チェックが走るようにするため）
- **既存指摘の基準値: 0 ERROR / 0 WARNING**（issue #17 で `vendor/bin/phpcs --standard=./.phpcs.xml.dist --report=summary $(git ls-files '*.php')` を実測、9ファイル）
  ★★ **この基準値は issue #17 より前は入力のサニタイズを検査していなかった。** `WordPress-Extra` が参照する
  `WordPress.Security.*` は EscapeOutput / SafeRedirect / NonceVerification / PluginMenuSlug の4つだけで、
  `ValidatedSanitizedInput` が入っていない。実際、wordpress.org の審査で指摘されるまで未サニタイズが8件あり、
  phpcs は緑のままだった。#17 で `.phpcs.xml.dist` に明示的に足してある。**同種の穴が無いかは、sniff を名指しで
  走らせた陽性対照でしか分からない**（`--sniffs=<Sniff名>` で撃つ）
- ★★★ **Plugin Check は `--categories` を絞らない。** 省略すると general / plugin_repo / security /
  performance / accessibility の5カテゴリすべてが走る。`--categories=plugin_repo` は4カテゴリを明示的に
  落とした測り方で、その「0 ERROR」は審査員が見る数字ではない。#17 で全カテゴリに広げたところ、
  **`phpcs:ignore` の sniff 名の綴り誤り**（`slow_query_meta_query` ← 正しくは `slow_db_query_meta_query`）が
  初めて見えた。綴りが違う注釈は「指摘が出ない」という形でしか現れないので、phpcs 側では永久に検出できない
- ★★ **Plugin Check は対象がシンボリックリンクだと `.claude/worktrees/` の中まで走査する。** 必ず
  `git archive` の展開物を、配布時と同じフォルダ名（`etbs-account-guard`）で置いて測る。フォルダ名が
  違うとテキストドメイン不一致が大量に出て、本物の指摘が埋もれる
- `composer.json` / `composer.lock` は原本に `phpunit/phpunit ^9.6` と `config.platform.php = 7.3.0` を足したもの
  （`name` は原本の `etbsjp/widget-shortcode-tools` のまま。**lock だけ差し替えない**。PHPUnit を足したときも
  既存の開発依存の版は1つも動かしていない）。`platform` は、宣言した下限 PHP 7.3 でもテストを走らせられる版に lock を揃えるため
- **CI は PHPUnit を走らせない**（`ci.yml` は7本の repo で共有しているため、この repo だけの手順を足していない）。
  `.php` を変えたら手元で末尾の「PHPUnit」節の手順を実行し、結果を PR 本文に貼る
- **`Requires PHP` の宣言は 7.3**（#17 で宣言。柔軟なヒアドキュメントが唯一の拘束＝これが実在する下限。仕様書 5.2）。
  CI の matrix は `['7.3','7.4','8.3']`（#19 で 7.3 を追加。**宣言した下限を CI が検査していなかった**ため）。
  ★ 7.3 のジョブだけ `runs-on: ubuntu-22.04`（新しい runner では 7.3 を用意できないことがあるため）。
  ★★ **`ci.yml` は7本の repo にコピーされる共有ファイルで、原本の matrix は `['7.4','8.3']`。同期のときに 7.3 を落とさないこと**
- **`Requires at least` は無宣言。** WordPress 側に実在する下限を特定していないため（「実在する下限があるときだけ書く」）

## PHPUnit

- 実行は `composer install` のあと `vendor/bin/phpunit`（設定は `phpunit.xml.dist`、テストは `tests/phpunit/*Test.php`）
- ★ **WordPress のテストスイート（wp-env）は使っていない。** `tests/phpunit/bootstrap.php` が `get_option()` などを
  最小限のスタブとして定義し、依存の薄いロジックだけを検査する。
  **実際の WordPress の中での動きはスタブでは確かめられない**ので、検証サイトでの実測を別に行う
- 宣言した下限でも走らせる：`"$PHP73" vendor/bin/phpunit`（パスは `CLAUDE.local.md`）
- `tests/` と `phpunit.xml.dist` は `.gitattributes` で配布 zip から外し、`.phpcs.xml.dist` でも対象外にしている
