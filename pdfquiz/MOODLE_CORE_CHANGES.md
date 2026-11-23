# Chi Tiết Các Thay Đổi Trong Mã Nguồn Gốc Moodle (`MOODLE_CORE_CHANGES`)

Tài liệu này liệt kê **chính xác và đầy đủ mọi thay đổi** đối với mã nguồn gốc **Moodle 5.3** khi tích hợp plugin **`local_pdfquiz`** (`PDF_plugin`), dùng để đối chiếu khi cài đặt trên máy mới, kiểm tra code review, hoàn tác (revert) hoặc nâng cấp phiên bản Moodle về sau.

---

## 1. Tóm tắt phạm vi tác động lên Moodle Core

| Hạng mục trong Moodle gốc | Có sửa không? | Chi tiết |
| --- | :---: | --- |
| **PHP Core** (`lib/`, `course/`, `mod/quiz/`, `question/`, `admin/`...) | **Không** | 0 file PHP gốc bị chỉnh sửa. |
| **Cơ sở dữ liệu gốc** (`mdl_course`, `mdl_quiz`, `mdl_question`, `mdl_user`...) | **Không** | 0 bảng gốc bị sửa schema. Plugin chỉ tạo 2 bảng riêng (`mdl_local_pdfquiz_items`, `mdl_local_pdfquiz_quizreq`) qua `db/install.xml`. |
| **Theme & Template gốc** (`public/theme/boost/...`) | **Không** | Menu sinh viên (**AI hỗ trợ học tập**) dùng hook chuẩn `core\hook\navigation\primary_extend` trong `db/hooks.php`, không sửa theme. |
| **JavaScript AMD Core** (`public/course/format/amd/.../activitychooser/dialogue.*`) | **Có (1 điểm duy nhất)** | Sửa đúng 1 hàm `submitAddSelectedModule()` trong `dialogue.js` (+ 2 file build `.min.js` và `.min.js.map` tương ứng). |

---

## 2. Danh sách chính xác 3 file Core bị thay đổi

Tất cả 3 file đã được lưu bản sao trong thư mục `moodle-core/` của repository này để Git quản lý. Đường dẫn đích tính từ thư mục gốc Moodle (`<moodle-root>/`):

| # | File trong repo `PDF_plugin` | Đường dẫn đích trong Moodle 5.3 gốc | Vai trò |
| :-: | --- | --- | --- |
| 1 | `moodle-core/public/course/format/amd/src/local/activitychooser/dialogue.js` | `public/course/format/amd/src/local/activitychooser/dialogue.js` | Mã nguồn AMD của hộp chọn hoạt động (Activity Chooser). |
| 2 | `moodle-core/public/course/format/amd/build/local/activitychooser/dialogue.min.js` | `public/course/format/amd/build/local/activitychooser/dialogue.min.js` | File JavaScript đã biên dịch & nén (AMD) mà Moodle tải lên trình duyệt. |
| 3 | `moodle-core/public/course/format/amd/build/local/activitychooser/dialogue.min.js.map` | `public/course/format/amd/build/local/activitychooser/dialogue.min.js.map` | Sourcemap phục vụ debug trên trình duyệt. |

---

## 3. Diff chi tiết trong `dialogue.js`

Vị trí duy nhất thay đổi: phương thức **`submitAddSelectedModule(newSelectedModule = null)`** (dòng 439–466) trong `public/course/format/amd/src/local/activitychooser/dialogue.js`:

```diff
     submitAddSelectedModule(newSelectedModule = null) {
         if (newSelectedModule) {
             this.handleOptionSelection(newSelectedModule);
         }
         if (this.selectedModule === null) {
             return;
         }
         const targetUrl = this.dialogueDom.getChooserOptionUrl(this.selectedModule);
+        const target = new URL(targetUrl, window.location.href);
+        // PDF Quiz AI customisation: keep other activities on the standard Moodle path.
+        if (target.searchParams.get('add') === 'quiz') {
+            if (this.openingQuizChooser) {
+                return;
+            }
+            this.openingQuizChooser = true;
+            getPlugin('local_pdfquiz/quizchooser')
+                .then(plugin => plugin.open(targetUrl, this.modal))
+                // The normal Quiz form remains usable if the optional plugin cannot load.
+                .catch(() => {
+                    window.location.href = targetUrl;
+                })
+                .finally(() => {
+                    this.openingQuizChooser = false;
+                });
+            return;
+        }
         window.location.href = targetUrl;
     }
```

### Giải thích cơ chế an toàn của đoạn patch trên
1. **Chỉ can thiệp đúng hoạt động Quiz (`add=quiz`):** mọi hoạt động/tài nguyên khác (Assignment, Forum, Resource, Page, Folder...) chạy thẳng xuống `window.location.href = targetUrl;` y như Moodle gốc.
2. **Chống bấm đúp (`this.openingQuizChooser`):** tránh mở 2 modal trùng nhau khi người dùng click nhanh hoặc double-click.
3. **Tự động Fallback khi gỡ hoặc lỗi plugin (`.catch(...)`):** nếu `local_pdfquiz` chưa cài, bị vô hiệu hóa hoặc lỗi nạp JS, trình duyệt tự chuyển hướng về `targetUrl` (form tạo Quiz chuẩn của Moodle), không bao giờ làm hỏng nút thêm Trắc nghiệm.
4. **Không chồng focus trap:** `local_pdfquiz/quizchooser` chờ modal Activity Chooser đóng hẳn (`ModalEvents.hidden`) rồi mới mở modal 2 lựa chọn (*Theo cách thông thường* / *Từ file PDF*), giữ nguyên các tham số `id` (courseid), `sectionid`/`section` và `beforemod`.

---

## 4. Cách biên dịch lại `dialogue.min.js` từ source

Script `build-amd.mjs` dùng `esbuild` cục bộ để biên dịch `dialogue.js` sang định dạng AMD chuẩn của Moodle:
- Tự động đổi `import(pluginName)` sang AMD dynamic `require`:
  `new Promise((resolve, reject) => require([pluginName], resolve, reject))`
- Đóng gói dưới tên module AMD chuẩn `core_courseformat/local/activitychooser/dialogue` kèm danh sách dependency ngoại (`jquery`, `core/modal`, `core/templates`, ...).

Lệnh build lại (chạy tại thư mục `PDF_plugin`):
```powershell
npm install
npm run build:amd
```

---

## 5. Cách áp dụng và hoàn tác (Revert) trên Moodle gốc

### Áp dụng 3 file core vào Moodle
Chạy PowerShell tại `public/local/pdfquiz/`:
```powershell
$pluginRoot = (Get-Location).Path
$moodleRoot = (Resolve-Path (Join-Path $pluginRoot '..\..\..')).Path
$coreFiles = @(
    'public\course\format\amd\src\local\activitychooser\dialogue.js',
    'public\course\format\amd\build\local\activitychooser\dialogue.min.js',
    'public\course\format\amd\build\local\activitychooser\dialogue.min.js.map'
)
foreach ($rel in $coreFiles) {
    Copy-Item -LiteralPath (Join-Path (Join-Path $pluginRoot 'moodle-core') $rel) `
              -Destination (Join-Path $moodleRoot $rel) -Force
}
php (Join-Path $moodleRoot 'admin\cli\purge_caches.php')
```

### Hoàn tác về Moodle 5.3 nguyên bản
1. Khôi phục lại 3 file `public/course/format/amd/{src,build}/local/activitychooser/dialogue.*` từ gói cài đặt gốc Moodle 5.3 (hoặc `git checkout -- public/course/format/amd/` nếu Moodle gốc được quản lý bằng Git).
2. Chạy `php admin/cli/purge_caches.php` và nhấn **Ctrl + F5** trên trình duyệt.

---

## 6. Ghi chú về cấu hình môi trường (không phải thay đổi mã nguồn Core)

Khi triển khai Moodle 5.3 trên Laragon/Apache để chạy cùng plugin, các file cấu hình máy chủ sau thường được tinh chỉnh theo chuẩn Moodle 5.3 (không nằm trong mã nguồn core):
- **`php.ini`:** bật các extension chuẩn Moodle (`curl`, `fileinfo`, `mbstring`, `intl`, `mysqli`, `openssl`, `soap`, `sodium`, `gd`, `exif`, `xsl`, `zip`) và `max_input_vars = 5000`.
- **`config.php`:** đặt `$CFG->wwwroot`, `$CFG->dataroot`, cấu hình DB và `$CFG->routerconfigured = true;`.
- **`public/.htaccess`:** dùng `mod_rewrite` (`RewriteCond %{REQUEST_FILENAME} !-f`, `RewriteCond %{REQUEST_FILENAME} !-d`, `RewriteRule ^ /r.php [L]`) để kích hoạt Moodle 5.3 Router mà không ảnh hưởng các thư mục thật như `/my/` hay `/admin/`.
