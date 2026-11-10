=== 給与計算システム ===
Contributors: yourcompany
Tags: payroll, hr, attendance
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later

従業員マスタ管理・勤怠CSV取込・給与計算（支給/控除/社会保険/所得税）・給与明細PDF出力を行うプラグインの雛形です。
wp-admin画面に加えて、固定ページに埋め込めるフロント版（ショートコード）も提供します。

== 現在の実装状況（雛形段階） ==

* [済] DBテーブル設計・作成（活性化フックでdbDelta実行）
* [済] 管理画面メニュー構成（ダッシュボード／従業員／勤怠取込／計算実行／設定／明細／監査ログ）
* [済] 給与計算ロジック（PS_Payroll_Calculator）：支給項目・健保／厚年／雇用保険料・所得税の算出まで実装済み
* [済] 従業員登録・編集フォーム（CRUD、wp-admin版・フロント版の両方）
* [済] 勤怠CSVアップロード〜列マッピングUI〜取込確定処理（4ステップウィザード、wp-admin版）
* [済] フロント（固定ページ）版：専用ログインフォーム＋従業員管理画面
* [済] 料率マスタのCSVインポート・手動編集フォーム（健保／厚年／雇用保険／所得税額表／割増賃金率の5種別）
* [済] 給与計算実行画面からの実行〜確認〜確定フロー（PS_Payroll_Run_Controller、wp-admin版）
* [済] 監査ログの自動記録（作成/再計算/確定/訂正の全操作。PS_Audit_Logger）
* [済] 給与明細の「訂正」フロー（確定済み明細を理由付きで再計算・上書きするフロー。status=corrected）
* [済] フロント側での勤怠取込・給与計算実行・給与明細閲覧（[payroll_attendance_import] [payroll_run] [payroll_slips]。確定・訂正はwp-admin限定）
* [済] 給与明細PDF生成（mPDF導入。個別ダウンロード・複数選択でのZIP一括ダウンロードに対応。確定済み明細のみ）
* [済] ライセンス表記（LICENSE.txt）・免責事項（ダッシュボードに常時表示）・料率マスタの年次更新配布方法（CSVエクスポート機能＋readme.txtに運用手順を明記）
* [済] 従業員セルフサービス（本人ログイン・パスワード変更・自分の確定済み明細の閲覧とPDFダウンロード）。WordPressユーザーとは別の独自認証（[payroll_employee_login] [payroll_employee_change_password] [payroll_employee_slips]）
* [済] フロント側での給与担当者向け給与明細PDF出力（[payroll_slips]に個別・一括ダウンロードを追加）
* [済] プラグイン有効化時に必要な固定ページ（給与担当者用6＋従業員本人用3、計9ページ）を自動作成。ショートコード間のURL相互参照も自動設定（既存ページは上書きしない）
* [済] フロント版ダッシュボード（[payroll_dashboard]。wp-admin版と同じサマリーを給与担当者向け固定ページとして提供。他の給与担当者向けページへのメニュー導線つき）
* [済] 販売・展示用デモアカウント一括作成ツール（wp-admin側「デモアカウントを作成する」ボタン。実データを扱わないデモ専用サイト向け）
* [済] 給与明細書の項目大幅拡張（勤怠内訳・役職/家族手当・課税/非課税通勤費分離・介護保険料・振込先口座・年末調整等の手入力調整項目・当年累計額。詳細は「給与明細書の項目拡張について」参照）
* [済] 自動作成する9固定ページのCocoon「ページ設定」を、ショートコードのみを表示するLP的な見た目（本文のみ・フルワイド、タイトル/読了時間/目次を非表示）に自動設定（DB_VERSION 1.4.0。既存サイトにもmaybe_upgrade()経由で遡って適用）
* [済] 給与明細PDFをサーバーに残さない方式に変更（DB_VERSION 1.5.0）。ダウンロードのたびにランダムなファイル名で一時生成し、送信後に削除する（.htaccessが効かないnginx等でもURL推測で明細を取得されないため）。アップグレード時に旧バージョンが保存していたPDFは削除される
* [済] 再計算・訂正時に手入力の調整項目（年末調整の還付/徴収・社保料調整・調整控除・過年度調整・現金/現物支給額）を引き継ぐ（従来は0に戻っていた）
* [済] 介護保険料の対象判定を「実行日時点の年齢」から「対象年月」に変更（40歳に達した日＝誕生日の前日の属する月から、65歳に達した日の属する月の前月まで）
* [済] 健保・厚年・雇用保険の料率マスタや所得税額表に該当行が無い場合、0円で続行せずエラーにする（計算実行画面にエラーとして表示され、その従業員の明細は作成されない）

== フロント（固定ページ）機能の使い方 ==

他社への配布・販売を想定し、給与担当者・従業員のどちらもwp-adminを操作しなくても
使えるようにする機能。ログイン自体はWordPress標準の認証（wp_signon）をそのまま使い、
見た目だけを固定ページ上の独自デザインに統一している。担当者アカウントはWordPressの
通常ユーザーとして作成し、「給与担当者」ロール（capability: manage_payroll）を付与すること。

必要な固定ページ（給与担当者用6ページ＋従業員本人用3ページ、計9ページ）は
プラグイン有効化時に自動作成される（PS_Activator::create_default_pages()）。
ショートコード同士が参照し合うURL（login_page属性など）もすべて自動的に
正しいURLで埋め込まれるため、手動でページを作る必要はない。作成されたページの
URLは、管理画面の「給与計算＞ダッシュボード」下部の一覧から確認できる
（各ページへの「編集」リンクもここから辿れる）。

作成される9ページはいずれもショートコードだけを表示する画面のため、Cocoonテーマの
「ページ設定」メタボックスも合わせて自動設定している（PS_Activator::apply_lp_page_settings()）。
具体的にはpage_typeをcontent_only_full_wide（本文のみ・フルワイド）にし、
the_page_title_novisible／the_page_read_time_novisible／the_page_toc_novisibleを
それぞれ1にすることで、記事タイトル・読了時間・目次を表示しないLP的な見た目にする。
post_contentと違い「既存ページの設定は上書きしない」という原則の対象外ではなく、
新規作成時にのみ設定する点はcontentと同じ。ただし1.3.0以前から運用している既存サイトの
9ページはこの設定が存在しなかったため、DB_VERSION 1.4.0へのアップグレード時に
maybe_upgrade()経由でPS_Activator::apply_lp_page_settings_to_existing_pages()が
既存の9ページにも遡って同じ設定を適用する（Cocoon以外のテーマではこれらのpost meta
キーは単に無視される）。

=== フロント版ダッシュボード（[payroll_dashboard]） ===

wp-admin側のダッシュボード（在籍従業員数・当月の計算未確定件数・直近の勤怠取込・
免責事項）と同じ内容を、給与担当者向け固定ページとしても提供する
（includes/class-ps-frontend-dashboard.php）。認証はPS_Frontend_Auth::guard()を
使う他の給与担当者向けページと同じ仕組みで、manage_payroll capabilityを持つ
WordPressユーザーのみ閲覧できる。固定ページURL一覧やデモアカウント作成ボタンの
ような管理・開発用ツールはフロント版には含めない（閲覧専用画面のため）。

有効化時に9番目の固定ページとして自動作成され、「給与担当者ログイン」の
ログイン後リダイレクト先も、このダッシュボードページに変更した
（旧バージョンからのアップグレード時はmaybe_upgrade()経由で自動的にこの
ページが追加作成される。page_specs()に1エントリ足しただけで既存サイトにも
反映される、という既存の固定ページ自動作成の設計をそのまま活用している）。

=== 販売・展示用デモアカウント作成ツール ===

他社へのプラグイン販売・展示にあたり、実際の給与データを入れずに一通りの
画面を見せられるデモサイトを想定した機能。wp-admin側「給与計算＞ダッシュボード」の
「デモアカウントを作成する」ボタン（PS_Activator::create_demo_account()）を押すと、
以下を一括で作成・更新する（何度実行しても安全＝冪等）：

* 従業員セルフサービス側：従業員コード「0123」・パスワード「0123」の従業員
  （当月分の確定済み給与明細を1件添えて作成し、デモとして成立するようにしている）
* 給与担当者（ダッシュボード）側：WordPressユーザー名「0123」・パスワード「0123」、
  ロールはadministratorではなくpayroll_manager（給与関連機能にのみ限定）

【重要】給与担当者側のデモアカウントはmanage_payroll capabilityを持ち、
システム内の全従業員のデータを閲覧できる。そのため、このボタンは実際の
給与データを保存している本番サイトでは絶対に実行しないこと。実データを
一切入れない、販売・デモ専用のサイトでのみ使用する前提の機能であり、
ダッシュボード画面上にもその旨を赤枠の警告として常時表示している。

=== maybe_upgrade()はplugins_loadedから呼んではいけない（重大なバグと修正） ===

ダッシュボード固定ページの追加（page_specs()に'dashboard'を1エントリ足しただけ）
に伴いPAYROLL_SYSTEM_DB_VERSIONを1.1.0→1.2.0に上げたところ、既に有効化済みの
サイト（＝maybe_upgrade()が実際に固定ページを新規作成する必要があるケース）で、
ページ読み込みのたびに"Call to a member function get_page_permastruct() on null"
という致命的エラーが発生する不具合が見つかった。

原因は、maybe_upgrade()（延いてはcreate_default_pages()内のwp_insert_post()→
内部的なget_permalink()呼び出し）が、payroll_system_init()からplugins_loaded
フック上で直接呼ばれていたこと。$wp_rewriteグローバル（get_page_permastruct()の
呼び出し先）はWordPressのinitフックまでに生成される実装になっており、
plugins_loaded時点ではまだ存在しない。バージョンが一致していて
maybe_upgrade()が何もしない（早期return）場合はこの問題が表面化しないため、
新規インストール時の自動有効化（register_activation_hookのactivate()経由。
こちらはinit後のadmin コンテキストで実行されるため無関係）や、バージョンが
既に一致している状態での動作確認だけでは発見できなかった。

さらに厄介なことに、create_default_pages()はforeachループを最後まで完了して
初めてpayroll_system_page_idsオプションを更新するため、ループ途中（今回は
'dashboard'の処理中）でこの致命的エラーが起きると、その回に新規作成された
固定ページのIDがオプションに記録されないまま終わる。結果、次のページ読み込みで
再度同じ処理が走り、また新しい重複ページが作られる…という「固定ページの
重複作成」バグ（本readme内の別項目）とよく似た副作用が発生することも確認した。

修正は、payroll-system.php側でmaybe_upgrade()をplugins_loadedから直接呼ぶのを
やめ、`add_action( 'init', array( 'PS_Activator', 'maybe_upgrade' ), 0 );`として
initフックに登録し直すことで対応した。既存サイトが1.1.0→1.2.0へアップグレード
される具体的なシナリオ（8ページ既存・dashboardページ未作成の状態）を再現して、
修正後は致命的エラーが起きず、重複も作られず、dashboardページが1件だけ
正しく追加されることを確認済み。

1. 作成される固定ページは以下の9つ（タイトル・ショートコードは自動設定済み）：

   給与担当者ログイン           … [payroll_login]
   給与計算ダッシュボード        … [payroll_logout] + [payroll_dashboard]
   従業員管理                    … [payroll_logout] + [payroll_employees]
   勤怠データ取込                … [payroll_attendance_import]
   給与計算実行                  … [payroll_run]
   給与明細一覧（給与担当者）    … [payroll_slips]
   従業員ログイン                … [payroll_employee_login]
   パスワード変更                … [payroll_employee_change_password]
   給与明細（従業員本人）        … [payroll_employee_logout] + [payroll_employee_slips]

   [payroll_attendance_import] は wp-admin版と同じ4ステップウィザード（アップロード〜
   列マッピング〜プレビュー〜確定）をそのまま提供する。[payroll_run] は対象年月・対象
   従業員を指定して計算を実行するのみで、確認・確定・訂正はwp-admin側で行う想定
   （フロントの利用者に不可逆な確定操作をさせない設計）。[payroll_slips] は一覧の
   閲覧と、確定済み明細のPDFダウンロード（個別・複数選択でのZIP一括）ができる。
   確定・訂正の操作リンクは表示しない。

2. ページのタイトル・レイアウト・本文中の説明文などは自由に編集してよい
   （ショートコード自体とその属性値は書き換えないよう注意する）。固定ページの
   URL（パーマリンク）を変更した場合、その固定ページを参照している他のページの
   login_page/redirect属性は手動で更新が必要（自動追従はしない）。

3. 既に存在する固定ページ（同じキーで以前作成済み、ゴミ箱に入っていないもの）は
   再作成・上書きされない。プラグイン更新時に新しいページ種別が追加された場合も、
   既存の8ページはそのまま維持され、新しいページだけが追加作成される。

4. ログイン可能なのは capability「manage_payroll」を持つWordPressユーザー
   （administrator、または有効化時に自動作成される「給与担当者(payroll_manager)」ロール）のみ。
   権限のないユーザーがログインを試みても、ログイン直後に自動的にログアウトされ、
   権限がない旨のメッセージのみが表示される（従業員データは一切見えない）。

デザインは public/css/frontend.css を編集することで、販売先企業ごとに配色や
フォントを差し替えられる。すべて .ps-frontend 配下のクラスに閉じているため、
設置先テーマのCSSと衝突しにくい設計にしている。

== 従業員セルフサービス（本人ログイン）の使い方 ==

給与担当者向けの[payroll_login]とは完全に別の、従業員本人用のログイン機能。
WordPressユーザー（wp_users）は一切使わず、wp_ps_employeesに追加した
password_hash列と、独自のセッション機構（wp_ps_employee_sessions、cookie名
ps_employee_auth）で認証する。従業員は自分の確定済み給与明細の閲覧・PDF
ダウンロードのみ行え、他の従業員のデータや管理機能には一切アクセスできない。

「従業員ログイン」「パスワード変更」「給与明細（従業員本人）」の3ページも、
他の固定ページと同様プラグイン有効化時に自動作成され、互いのURLも自動で
設定済みなので手動設置は不要（上の「フロント（固定ページ）機能の使い方」参照）。

1. [payroll_employee_login]（従業員ログインページ）：ログイン時、その従業員が
   まだ仮パスワードのまま（後述）であれば自動的にchange_password_page
   （パスワード変更ページ）へ、そうでなければredirect（給与明細ページ）へ遷移する。

2. [payroll_employee_change_password]（パスワード変更ページ）：現在のパスワード・
   新しいパスワード（8文字以上）・確認の3項目を入力する。変更に成功すると、
   その従業員の全セッション（他端末含む）を失効させ、ログインページへ
   「新しいパスワードで再度ログインしてください」と案内する。

3. [payroll_employee_slips]（給与明細ページ）：ログイン中の従業員自身の確定済み
   （confirmed/corrected）明細のみを一覧表示し、各行に個別PDFダウンロードリンクを
   出す。未確定（calculating/reviewing）の明細は従業員には一切見せない。

4. 従業員のログインアカウントは、給与担当者が「従業員管理」画面
   （wp-admin/views/employees.php、または[payroll_employees]）の編集画面から
   「従業員用ログインの仮パスワードを発行する」ボタンで発行する。生成された
   平文パスワードはその場で一度だけ表示される（DBにも保存されない・URLにも
   残らない）ので、担当者が従業員本人に直接（電話・対面等で）伝える運用を想定
   している（本プラグイン自体にメール送信機能は無い）。発行直後は
   must_change_password=1となり、従業員は次回ログイン時に必ずパスワード変更を
   求められる。

5. 仮パスワードの発行・再発行を行うと、その従業員の既存セッションはすべて
   失効する（発行前にログイン中だった端末は自動的にログアウト扱いになる）。

== ディレクトリ構成 ==

payroll-system.php                                  … エントリポイント
uninstall.php                                        … 削除時処理（デフォルトは無効化済み）
includes/class-ps-activator.php                      … 有効化時のテーブル作成・ロール登録・固定ページ自動作成
includes/class-ps-deactivator.php                    … 無効化時処理
includes/class-ps-core.php                           … 管理画面・フロント両方のフック登録を集約
includes/class-ps-employee.php                       … 従業員マスタのCRUDリポジトリ（wp-admin/フロント共通）
includes/class-ps-employee-controller.php            … 従業員フォーム送信処理（wp-admin/フロント共通）。仮パスワード発行も含む
includes/class-ps-attendance.php                     … 勤怠データのリポジトリ
includes/class-ps-import-profile.php                 … 勤怠CSV列マッピングの保存
includes/class-ps-attendance-importer.php            … CSVパース・突合・検証ロジック
includes/class-ps-attendance-controller.php          … 勤怠取込ウィザードの制御（wp-admin/フロント共通。_ps_redirect_baseで戻り先を切替）
includes/class-ps-frontend-auth.php                  … フロント専用ログイン/ログアウト（[payroll_login] [payroll_logout]）
includes/class-ps-frontend-employees.php             … フロント従業員管理画面（[payroll_employees]）
includes/class-ps-frontend-attendance-import.php     … フロント勤怠取込ウィザード（[payroll_attendance_import]）
includes/class-ps-frontend-payroll-run.php           … フロント給与計算実行（[payroll_run]。実行のみ、確認・確定はwp-admin側）
includes/class-ps-frontend-payroll-slips.php         … フロント給与明細閲覧＋PDF出力（[payroll_slips]。確定・訂正は不可）
includes/class-ps-employee-auth.php                  … 従業員本人用ログインの認証コア（パスワードハッシュ・独自セッション。WordPressユーザーは使わない）
includes/class-ps-frontend-employee-auth.php         … 従業員本人用ログイン/ログアウト/パスワード変更（[payroll_employee_login] [payroll_employee_logout] [payroll_employee_change_password]）
includes/class-ps-frontend-employee-slips.php        … 従業員本人の確定済み明細のみ閲覧＋PDF出力（[payroll_employee_slips]）
includes/calculation/class-ps-payroll-calculator.php … 給与計算オーケストレーター
includes/calculation/class-ps-rate-repository.php    … 料率マスタ参照（計算時：対象年月時点の有効行を取得）
includes/class-ps-rate-master.php                    … 料率マスタのCRUD・バリデーション（設定画面：全5種別共通）
includes/class-ps-rate-importer.php                  … 料率マスタCSV取込（アップロード〜プレビュー〜確定）
includes/class-ps-rate-master-controller.php         … 給与設定（マスタ）画面の送信処理
includes/class-ps-payroll-slip.php                   … 給与明細（wp_ps_payroll_slips）のCRUD（保存＝新規作成/再計算、確定）
includes/class-ps-payroll-run-controller.php         … 給与計算実行画面の送信処理（実行・個別確定・一括確定・訂正）。実行のみ_ps_redirect_base対応
includes/class-ps-audit-logger.php                   … 監査ログ（wp_ps_audit_log）への記録
includes/class-ps-payroll-slip-pdf.php               … 給与明細PDF生成（mPDF）。vendor/autoload.phpは生成時のみ遅延読み込み
includes/class-ps-payroll-pdf-controller.php         … PDFダウンロード処理（給与担当者：個別・ZIP一括／従業員本人：自分の明細のみ・nopriv対応）
vendor/                                              … composerで導入した外部ライブラリ（mpdf/mpdf 等）。配布zipに含める前提
composer.json, composer.lock                         … PHP依存関係の定義（`composer install`で再構築可能）
admin/class-ps-admin-menu.php                        … 管理画面メニュー登録
admin/views/*.php                                    … 各画面テンプレート（wp-admin版）
admin/css/admin.css, admin/js/admin.js               … 管理画面アセット
public/css/frontend.css                              … フロント（固定ページ）用アセット

== 設計メモ：wp-admin版とフロント版でロジックを共有する仕組み ==

PS_Employee/PS_Employee_Controller、PS_Attendance_Importer/PS_Attendance_Controller、
PS_Payroll_Calculator/PS_Payroll_Run_Controllerは、いずれもwp-admin版・フロント版の
どちらからも同じものを呼び出している。フォーム側が隠しフィールド `_ps_redirect_base` に
「処理後に戻りたいURL」を渡すことで、コントローラーは呼び出し元がwp-adminか固定ページかを
意識せず正しい画面にリダイレクトできる（指定が無ければwp-admin側にフォールバックする）。
PS_Attendance_Controllerのように複数ステップにまたがる処理は、redirect_baseを
transient状態（$state['redirect_base']）に含めてステップ間で引き継いでいる
（clear_state()で状態を消す直前に値を取り出してredirect_toへ明示的に渡す必要がある点に注意）。

この設計のおかげで、フロント版の給与計算実行（[payroll_run]）・給与明細閲覧
（[payroll_slips]）・勤怠取込（[payroll_attendance_import]）は、admin/views配下の
対応する画面と同じコントローラー・同じバリデーション・同じ計算ロジックを共有しており、
ロジックの二重実装は発生していない。ただし確定・訂正の操作リンクはフロント側には
一切出していない（フロントのPS_Frontend_Payroll_SlipsはPDF出力までで、確定・訂正の
操作は出さない）。不可逆な金額確定操作は、capability自体はwp-adminとフロントで
同じmanage_payrollだが、画面を分けることでフロント利用者の誤操作リスクを下げる
設計判断による。

== 固定ページ自動作成の仕組み ==

PS_Activator::create_default_pages()は「どのページがどのキーに対応するか」を
optionsのpayroll_system_page_idsに保持し、そのIDのページが存在する（ゴミ箱に
無い）限り再作成しない。実行の流れは2段階：

1. page_specs()に定義された8ページ分、まだ存在しないものだけを空の本文で
   wp_insert_post()する（この時点でIDとパーマリンクが確定する）。
2. 全ページのURLが出揃ったところで、今回新規作成した分の本文だけに
   ショートコード（相互参照するURLを埋め込んだもの）を書き込む。

既存ページの本文は絶対に上書きしない＝管理者がページ内容やレイアウトを
編集していても、プラグインの更新やmaybe_upgrade()の再実行で消えることはない。
新しいページ種別を将来追加する場合も、page_specs()に1つ足すだけで、
既存サイトでは（maybe_upgrade()経由で）その1ページだけが追加作成される。

=== 固定ページの重複作成バグと修正（本番環境での実例） ===

maybe_upgrade()はplugins_loadedのたびに（＝リクエストのたびに）呼ばれる設計のため、
「バージョン不一致→固定ページ作成→バージョン更新」の一連の処理が完了する前に、
別のリクエストが同時に同じ「バージョン不一致」を検知してしまうと、両方が
互いの作成結果を知らないまま8ページ分をそれぞれ新規作成してしまう競合状態が
存在した。実際に本番環境（有効化直後、wp-adminのハートビートAPIやダッシュボード
ウィジェットのAjaxが並行して走るタイミング）で「給与担当者ログイン」等の固定
ページが複数重複作成される不具合として顕在化した。ローカルの単一リクエストの
動作確認だけではこの種の競合は再現しないため、それまでの検証では発見できて
いなかった。

対策として、maybe_upgrade()の本体をadd_option()ベースの排他ロックで囲んだ
（payroll_system_upgrade_lockオプション）。add_option()はoption_nameに対する
MySQLのUNIQUE制約により、複数プロセスが同時に呼んでも最初の1つだけが成功する
ことが保証される（＝WordPress自身がwp_options上で提供するアトミックな排他制御
プリミティブ）。これにより、同時に複数のリクエストが「バージョン不一致」を
検知しても、実際にcreate_default_pages()を実行するのは1プロセスだけになる
（ロックを取得できなかった側は何もせず終了し、次回以降のリクエストで
改めて判定される）。ロックは処理完了後に必ず解放し、異常終了等でロックが
残った場合に備えて60秒以上前のロックは古いものとみなして自動的に破棄する。

なお、既に重複作成されてしまった固定ページ自体はこの修正では自動的には
片付かない。給与計算＞ダッシュボードの「フロント（固定ページ）URL一覧」に
表示されているIDが現在optionsに登録されている正しいページなので、固定ページ
一覧でそれ以外の同名重複ページを手動でゴミ箱に移動する。

=== 固定ページ本文の再生成機能（重複作成バグの副作用への対処） ===

固定ページの重複作成バグの影響で、削除せずに残った側のページの本文（ショート
コード）が、既に削除した側の重複ページのURLを参照したまま残ってしまうケースが
ある（例：従業員管理ページのログアウトリンクが、削除済みの「給与担当者
ログイン-19」のような重複ページのURLを指したままになる）。8ページが複数回に
分けて競合的に作成された場合、どのページとどのページが「同じバッチ」で
作られたかが入り乱れるため、最終的にoptionsに残るpayroll_system_page_idsの
組み合わせと、各ページの本文に実際に埋め込まれているURLとが食い違うことがある。

この状態を1件ずつ手動でショートコードを編集して直すのは間違いが起きやすいため、
ダッシュボードに「固定ページの本文を再生成する」ボタンを追加した
（PS_Activator::resync_shortcode_content()）。実行すると、現在optionsに
登録されている8ページ全ての本文を、現在の実際のURLに基づくショートコードで
一括して上書きする。create_default_pages()の「既存ページの本文は上書きしない」
という原則の明示的な例外であり、管理者が意図して実行する復旧操作という位置づけ。
ショートコード以外にページ本文へ独自の文章やレイアウトを追記している場合は
その内容が失われるため、実行前に確認ダイアログを表示している。

== 料率マスタ機能について ==

includes/class-ps-rate-master.php の get_type_defs() が5種別（健保／厚年／雇用保険／
所得税額表／割増賃金率）のフィールド構成・CSV列名・バリデーションルールを一元管理する
唯一の情報源。画面（admin/views/rates.php）・コントローラー（class-ps-rate-master-controller.php）・
CSVインポーター（class-ps-rate-importer.php）はすべてここを参照して動作するため、
新しい料率マスタを追加する場合もget_type_defs()に定義を1つ足すだけでよい設計にしている。

CSVは「適用開始年月＋区分（都道府県・等級など natural_key）」が既存行と一致する場合は
新規追加ではなく上書き更新（upsert）する。手動フォームでは同じ組み合わせの重複登録は
エラーとして拒否する（PS_Rate_Master::validate()の$check_duplicate引数で挙動を切替）。

== 雇用保険料計算について ==

健康保険・厚生年金は標準報酬月額等級（従業員ごと）を基準に算出するのに対し、雇用保険料は
「総支給額（通勤手当込みのtotal_gross）× 雇用保険料率マスタの被保険者負担率」で算出する
（PS_Payroll_Calculator::calculate_social_insurance()）。

事業区分（一般／農林水産・清酒製造／建設）は従業員ごとではなく会社単位の設定のため、
従業員マスタには持たせず、オプション payroll_system_business_type（デフォルト:
general）として保持する。給与設定（マスタ）＞雇用保険料率タブの上部から変更できる
（PS_Rate_Master_Controller::handle_save_business_type()）。

== 給与明細書の項目拡張について ==

市販の給与ソフト（弥生給与等）の明細書テンプレートを参考に、項目を大幅に拡張した
（DB_VERSION 1.3.0）。自動計算で算出する項目と、法令に基づく自動計算エンジンを
新規に作るのが非現実的なため意図的に「手入力の調整項目」とした項目とに分かれる。

=== 自動計算に追加した項目 ===

* 従業員マスタに追加：役職手当・家族手当・課税通勤費（従来のcommuting_allowanceは
  非課税通勤費の扱いに変更）・有休付与日数（年間）・振込先口座（金融機関名／支店名／
  口座種別／口座番号／口座名義、1口座のみ対応）
* 勤怠データ（CSV取込）に追加：有休取得日数・遅刻早退時間。取込済みデータから
  「有休残日数＝有休付与日数－当年の取得日数合計」を明細生成時に自動算出する
  （PS_Attendance::get_paid_leave_used_total()）。
* 介護保険料を健康保険料から独立した明細項目にした。従来は40〜64歳の場合
  health_insuranceに「介護保険込みの金額（employee_amount_with_care）」を
  そのまま入れていたが、health_insuranceは常に介護保険なしの基本額とし、
  差額（employee_amount_with_care－employee_amount）をnursing_care_insurance
  として別項目にした。両者の合計は従来と同じ金額になる（40歳未満・65歳以上は0円）。
  料率マスタ（健康保険料率）側の変更は不要。
* 累計額（課税支給累計・社会保険累計・所得税累計）：対象年月と同じ年の1月〜対象月の
  確定済み（confirmed/corrected）明細を合算して算出する（PS_Payroll_Slip::get_ytd_totals()）。
* 会社名・支給日（毎月何日か）：会社単位の設定。給与設定（マスタ）＞健康保険料率タブの
  上部から保存する（オプション payroll_system_company_name / payroll_system_payment_day、
  PS_Rate_Master_Controller::handle_save_company_settings()）。給与明細PDFのヘッダーに使用。

=== 手入力の調整項目（自動計算しない） ===

年末調整還付・年末調整徴収・過年度調整・社保料調整・調整控除・現金支給額・現物支給額の
7項目は、日本の税法に基づく自動計算エンジンを実装する代わりに、給与明細一覧画面の
「調整」リンクから担当者が直接金額を入力する方式にした（PS_Payroll_Slip::update_adjustments()、
admin-post アクション ps_save_slip_adjustments）。マイナスの値も入力できる。
何度保存し直しても、自動計算で算出した基本項目（健保／厚年／雇用保険／所得税／住民税／
その他）＋最新の調整項目の値、から毎回total_deduction・net_amountを計算し直すため
二重加算にはならない（冪等）。現金支給額・現物支給額は明細の表示のみに使い、
差引支給額・振込支給額1の計算には使う（振込支給額1＝差引支給額－現金支給額－現物支給額）が、
支給額の合計（total_gross）自体には含めていない。

振込先口座は1口座のみの対応（明細上の「振込支給額2」「振込支給額3」欄は常に0円で表示する。
複数口座への分割振込機能そのものは実装していない）。

== 給与計算実行〜確認〜確定フローについて ==

「給与計算実行」画面（admin/views/run.php）は対象年月・対象従業員（全員／個別選択）を
指定して実行するだけの画面。実際の計算はPS_Payroll_Calculatorが行い、結果は
PS_Payroll_Slip::save_calculation()でwp_ps_payroll_slipsへ保存される
（status=reviewing。既存明細があれば再計算として上書き、無ければ新規作成）。

確認・確定は「給与明細・帳票出力」画面（admin/views/slips.php）で行う。一覧から
個別に「確定」するか、チェックボックスで複数選択して一括確定できる
（PS_Payroll_Run_Controller::handle_confirm() / handle_confirm_selected()）。

確定済み（status=confirmed/corrected）の明細はPS_Payroll_Slip::save_calculation()が
上書きを拒否するため、同じ従業員・同じ月を通常の「給与計算実行」で再度計算しても
エラーとしてスキップされる（実行結果画面にスキップ理由が一覧表示される）。

作成・再計算・確定・訂正の各操作は、そのつどPS_Audit_Logger::log()でwp_ps_audit_logに
変更前後の値ごと記録される。「監査ログ」画面（admin/views/audit-log.php）で確認できる。

== 訂正フローについて ==

確定済み（confirmed/corrected）の明細のみ、給与明細一覧の「訂正」リンクから
admin.php?page=payroll-system-slips&correct_id=<ID> に遷移して訂正できる
（PS_Payroll_Slip::correct()）。従業員マスタ・勤怠データ・料率マスタ側の誤りを
先に直してもらった上で、PS_Payroll_Calculatorを再実行し結果を上書きする仕組みで、
金額を手入力で直接書き換える機能ではない。

訂正理由は必須項目だが、wp_ps_payroll_slipsには専用の列を持たせておらず、
監査ログのafter_json（PS_Audit_Logger::log()の$after引数）に
correction_reasonとして含めて記録する設計にした（DBスキーマ変更を避けるため）。
これにより「旧データの保持」はbefore_json、「訂正理由の記録」はafter_jsonの
correction_reasonで、それぞれ監査ログ側の責務としてまかなっている。

== 給与明細PDF生成について ==

composer require mpdf/mpdf（vendor/以下、配布zipに含める前提）で導入。
PS_Payroll_Slip_Pdf::generate()がwp_ps_payroll_slipsのgross_amounts_json /
deduction_amounts_jsonからHTMLの明細を組み立て、mPDFでPDF化してwp-content/uploads/
payroll/slips/に保存する（ファイルパスはpdf_file_pathに記録し、既に生成済みならmPDFの
実行自体をスキップして使い回す）。composerのオートローダー（vendor/autoload.php）は
generate()内でのみ遅延読み込みし、PDFを生成しない大多数のリクエストでは読み込まない。

PDF化は確定済み（confirmed/corrected）の明細のみ許可する
（PS_Payroll_Pdf_Controller::is_confirmed_status()）。計算中・確認中の未確定な明細を
誤って配布できないようにするための制約であり、勤怠取込・料率マスタ等の他の機能と
同じく「確定していないデータは対外的に出さない」という一貫した設計方針に沿っている。

個別ダウンロード（給与明細一覧の「PDF出力」リンク）と、チェックボックスで複数選択
してのZIP一括ダウンロード（ZipArchiveを使用。サーバーにzip拡張が無い場合はエラー
メッセージを表示し個別ダウンロードを案内する）の両方に対応する。

なお、この開発環境には元々PHP/Composerが無く（Node.jsのみ）、`sudo apt install
php-cli php-mbstring php-xml php-zip php-gd` でPHP CLIを、`~/.local/bin/composer`
に（root権限不要で）Composerを導入してから実装・動作確認した。

=== 日本語フォント表示について（重要・過去の不具合と修正） ===

mPDFはWriteHTML()のmode=>'utf-8'を指定しても、CSSでfont-familyを明示しない限り
日本語のグリフ（字形）を持たないDejaVu系フォントにフォールバックすることがある。
この場合、生成されたPDFのテキストレイヤー（ToUnicode CMap）自体は正しい文字コードを
保持しているため、PDF内のテキストをコピー＆ペーストしたり、一部のプレビューツール
（テキストレイヤーを独自に再描画するもの）で見ると日本語が正しく表示されている
ように見えてしまう。しかし実際のグリフ（字形）データが埋め込まれていないため、
Adobe Acrobat等の一般的なPDFビューアで開くと日本語部分が豆腐（□）や空白として
表示される、という発見しづらい不具合があった。

この問題を検証・修正するにあたり、独立した検証ツール（poppler-utilsの pdffonts /
pdftoppm）を使い、実際に埋め込まれているフォント名とラスタライズ結果を確認する
ことで、単一のプレビュー手段だけに頼った検証では見抜けない不具合であることが
判明した。修正は、明細HTMLのbody要素にmPDF同梱のUnicode対応フォントを明示的に
指定することで行った（includes/class-ps-payroll-slip-pdf.php の
`font-family: sun-exta;`）。この修正後、pdffontsで埋め込みフォントが
`MPDFAA+Sun-ExtA`（CID TrueType, embedded）になっていること、pdftoppmで
ラスタライズした画像で日本語（漢字・ひらがな・カタカナ）が正しく表示される
ことを確認済み。

なお、font-familyを明示的にsun-extaに固定したことで、mPDF同梱フォントのうち
実際に使用するのはSun-ExtA.ttf（日本語含む）と、mPDFが内部初期化時に必須で
要求するDejaVu系フォント一式（body要素のデフォルトフォント解決に使われる。
これが無いと"Cannot find TTF TrueType font file DejaVuSerifCondensed.ttf"の
ようなエラーでPDF生成自体が失敗する）のみであることを確認した。そのため、
vendor/mpdf/mpdf/ttfonts/ から日本語明細書の表示に不要な言語（韓国語・
チベット語・古代エジプト語・クメール語等）のフォントファイルを削除し、
Sun-ExtA.ttfとDejaVu*.ttf一式のみを残した（vendorが約95MBから約39MBに縮小）。
これは配布zipのファイルサイズ制限（レンタルサーバーのファイルマネージャー等）
対策も兼ねている。

注意: composer install を再実行すると、composer.lockの内容に従って
mPDFパッケージ全体（削除前の全フォント、約88MB）が再ダウンロードされる。
配布用に軽量化した状態を維持する場合は、composer install後に再度
vendor/mpdf/mpdf/ttfonts/ 内の不要フォントを削除すること
（Sun-ExtA.ttfとDejaVu*.ttf以外を削除すればよい）。

== 従業員セルフサービス認証の設計 ==

給与担当者（PS_Frontend_Auth）はWordPressユーザー・wp_signon()をそのまま使うのに対し、
従業員本人（PS_Employee_Auth）は完全に独立した認証を持つ。理由は、従業員1人1人に
WordPressユーザーを作ると人数分のアカウント管理・ロール設計が煩雑になること、
また両者は権限もアクセス範囲もまったく異なる（従業員は自分の明細以外に一切触れない）
ため、混同を防ぐ意味でも分離した。

* パスワードのハッシュ化自体はWordPressコアのwp_hash_password()/wp_check_password()
  （phpassベース）をそのまま使う。ハッシュ処理を独自実装するのは避けている。
* ログイン状態はwp_ps_employee_sessionsテーブル＋専用cookie「ps_employee_auth」
  （ランダム32byteトークンをhex化した値、httponly・secure（SSL時）・SameSite=Lax）
  で管理する。DBにはトークンそのものではなくSHA-256ハッシュだけを保存するため、
  DBが漏洩してもcookieの値を再現できない。
* admin-post.phpのアクションハンドラは、従業員がWordPressにログインしていない
  （nopriv）状態でも動く必要があるため、PS_Payroll_Pdf_Controllerの
  handle_employee_download()はadmin_post_とadmin_post_nopriv_の両方に
  フックを登録している（wp-adminからの操作は元々ログイン前提のため片方のみでよい）。
* 明細PDFダウンロードは、ログイン中の従業員IDと明細のemployee_idが一致する場合のみ
  許可する（handle_employee_download()内で厳密にチェック）。他の従業員の明細IDを
  URLに直接指定しても取得できない。
* パスワード変更・仮パスワード発行の後は、その従業員の全セッションを
  revoke_all_sessions()で失効させる。変更前のパスワードを知っている別端末が
  ログインしたままになる状態を防ぐため。
* 仮パスワードの平文は、発行直後にget_transient()で一度だけ管理画面に表示し、
  DBにもURLにも残さない（wp_ps_employeesに保存するのはハッシュ済みの値のみ）。
* 仮パスワード発行（ランダム生成・次回ログイン時に変更を強制）とは別に、
  給与担当者が任意の文字列をそのままパスワードとして直接設定できる機能を追加した
  （PS_Employee_Auth::set_password_by_admin()、従業員管理画面の
  「パスワードを直接指定する」フォーム）。設定したパスワードはそのまま
  （変更を求められずに）即ログインに使える。社内で分かりやすい合言葉を
  各従業員に割り振りたい、といった運用を想定している。

この機能追加でwp_ps_employeesにpassword_hash等の列を、新規にwp_ps_employee_sessions
テーブルを追加した（PAYROLL_SYSTEM_DB_VERSIONを1.1.0へ更新）。既に有効化済みのサイトにも
反映されるよう、PS_Activator::maybe_upgrade()をplugins_loadedのたびに呼び、optionsに
記録されたDBバージョンが定数と異なればdbDeltaを再実行する（差分が無ければ何もしない）。
プラグイン更新時にユーザー側で追加の操作は不要。

== ライセンス・免責事項 ==

本体はGPLv2（またはそれ以降）で配布する（LICENSE.txt参照）。vendor/以下の
third-partyライブラリ（mPDF等）はcomposer.json/composer.lockに記載の通り、
それぞれ独自のオープンソースライセンスの下にある。再配布・改変時は各ライブラリの
ライセンス条件も確認すること。

給与計算・社会保険料・所得税額の算出結果は、あくまで登録された料率マスタ・
従業員情報・勤怠データに基づく計算結果であり、正確性・法令適合性を保証するもの
ではない。実際の給与支払いに使う前に社会保険労務士・税理士等の専門家による確認を
必須とする旨を、管理画面ダッシュボード（admin/views/dashboard.php）に常時表示する
免責事項として明記している。

== 料率マスタの年次更新配布について ==

健康保険料率・厚生年金保険料率・雇用保険料率・所得税額表は、毎年（雇用保険料率は
年度単位、健保/厚年は原則毎年3月改定、所得税額表は税制改正のタイミングで）
改定される。この改定を配布・反映する運用は以下を想定している。

1. 開発元（プラグイン提供者）が最新の公的な料率表をもとに、種別ごとのCSV
   （effective_year_month等の列名はPS_Rate_Master::get_type_defs()で固定。
   「テンプレートをダウンロード」で列名を確認できる）を作成する。
2. 各クライアントの給与設定（マスタ）＞対象タブ＞CSVインポートから取り込む。
   effective_year_month＋区分（都道府県・等級など）が既存行と一致する場合は
   上書き更新（upsert）されるため、同じCSVを翌年また取り込んでも重複しない。
   新しい年月の行を追加する分には、過去の行はそのまま履歴として残る
   （PS_Payroll_Calculatorは対象年月時点で有効な＝最新の行を自動的に参照する）。
3. 更新前に「現在のデータをCSVエクスポート」（各タブのCSVインポート欄）で
   現行データをバックアップしておくことを推奨する。

== 次のステップ ==

1. 確定済み明細の「訂正」フロー（readme.txt「訂正フローについて」参照）は再計算による
   上書きのみに対応している。金額の直接編集や、訂正前後を並べて比較する画面が必要に
   なった場合は別途検討する
