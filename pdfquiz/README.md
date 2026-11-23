# PDF Quiz AI — Moodle Local Plugin (`local_pdfquiz`)

Plugin **`local_pdfquiz`** (phiên bản **1.0.0**, build **`2026112000`**) tích hợp trực tiếp hệ thống xử lý PDF thông minh (**Process-PDF**: PyMuPDF đa cột + lọc lề 10% + diệt logo trùng lặp + Selective OCR + phân loại ngữ nghĩa Text/Mixed/Visual + tiền xử lý ảnh OpenCV + LangChain Chunking + LiteLLM/Gemini Exponential Backoff) vào **Moodle 5.3**.

Plugin tập trung **100% vào tài liệu PDF** (không chứa audio/record) và bao gồm trọn gói 3 vai trò: **Giảng viên**, **Sinh viên**, và **Quản trị viên (Admin)**.

---

## 1. Tính năng đã triển khai

### 1.1. Giảng viên — Tạo Trắc nghiệm Moodle thật từ file PDF
- Bật **Chế độ chỉnh sửa** trong khóa học → **Thêm nội dung (Add content)** → chọn **Trắc nghiệm (Quiz)** → **Thêm**.
- Hộp chọn mở ra 2 lựa chọn:
  1. **Theo cách thông thường**: mở form tạo Quiz chuẩn của Moodle.
  2. **Từ file PDF** — *"Tải lên file PDF mới hoặc chọn file PDF đã có sẵn."*
- Ở trang **Từ file PDF** (`/local/pdfquiz/quiz.php`):
  - Nhập tên bài trắc nghiệm, số câu hỏi (**1–30**).
  - Tải lên file `.pdf` mới **hoặc** chọn file `.pdf` đã có sẵn trong tài nguyên **File (`mod_resource`)** của khóa học.
  - Tùy chọn nâng cao: chọn khoảng trang (`Từ trang` – `Đến trang`), đổi model Gemini, nhập API key (nếu Admin chưa cài key dùng chung hoặc cho phép ghi đè).
  - Tự động xử lý PDF qua dịch vụ **Process-PDF**, hiển thị **Xem trước tóm tắt & câu hỏi** kèm **thanh yêu cầu AI chỉnh sửa** (*viết bằng tiếng Việt, câu hỏi chi tiết/khó hơn, tóm tắt dài hơn, giải thích kỹ hơn...* qua `POST /api/v1/refine-pdf`) để giảng viên tinh chỉnh nhiều lần trước khi bấm **Lưu thành bài Trắc nghiệm Moodle**.
  - Dùng API chính thức của Moodle (`add_moduleinfo`, `question_bank`, `quiz_add_quiz_question`) để tạo **bài Trắc nghiệm Moodle thật** (câu hỏi trắc nghiệm 4 phương án, 1 đáp án đúng, kèm giải thích, thang điểm 10, mỗi trang 1 câu, **mặc định ẩn với sinh viên** để giảng viên kiểm tra lại trước khi bật hiển thị).
  - Hỗ trợ khóa chống tạo trùng (idempotent `requestkey`) và nút **Thử lưu lại bài trắc nghiệm vào Moodle** nếu lỗi mạng ở bước lưu.

### 1.2. Sinh viên — AI hỗ trợ học tập (`PDF to quiz` + Lịch sử cá nhân)
- Trên thanh điều hướng chính của sinh viên (ngay sau **Bảng điều khiển / Dashboard**) xuất hiện mục **AI hỗ trợ học tập** (*AI study assistant*) qua hook chuẩn `core\hook\navigation\primary_extend` (không sửa core/theme).
- Trang chính (`/local/pdfquiz/`) hiển thị:
  - Thẻ **PDF to quiz** (`/local/pdfquiz/pdf.php`): tải lên PDF mới hoặc chọn PDF từ các khóa học đã ghi danh → trích xuất nội dung, tóm tắt, liệt kê ý chính, sinh câu hỏi ôn tập có mốc trang (`Trang X–Y`), xem đáp án/giải thích, và dùng **thanh yêu cầu AI chỉnh sửa** (*dài hơn, chi tiết hơn, viết bằng tiếng Việt...*) ngay trên kết quả trước khi lưu.
  - Nút **Lịch sử của tôi** (`/local/pdfquiz/history.php`): lưu kết quả vào bảng `local_pdfquiz_items`, xem lại (`view.php`), chỉnh sửa nội dung/câu hỏi/đáp án và quyền chia sẻ (`private` / `group` / `course` / `public`, có khóa `revision` chống ghi đè tại `edit.php`), hoặc xóa.

### 1.3. Quản trị viên (Admin) — Cấu hình tập trung & Kiểm tra sức khỏe dịch vụ
- Vào **Site administration → Plugins → Local plugins → PDF Quiz AI** (`/admin/settings.php?section=local_pdfquiz`):
  - `serviceurl`: URL dịch vụ Python Process-PDF (mặc định `http://127.0.0.1:8002`).
  - `apikey`: **Gemini API key dùng chung của hệ thống** (lưu bảo mật trên server; khi đặt sẵn, giảng viên & sinh viên không cần tự nhập key).
  - `allowuserapikey`: cho phép hoặc cấm người dùng nhập API key riêng.
  - `model`: model mặc định chuẩn LiteLLM (mặc định `gemini/gemini-3.5-flash-lite`).
  - `maxfilesize`: dung lượng PDF tối đa (MB, mặc định `50`).
  - `maxpages`: số trang PDF tối đa mỗi lượt xử lý (mặc định `100`).
  - `timeout`: thời gian chờ tối đa (giây, mặc định `300`).
  - `enablevision`: bật/tắt Vision LLM + OpenCV cho trang `MIXED`/`VISUAL_HEAVY`.
  - `enableocr`: bật/tắt Selective OCR (Tesseract) cho trang scan.
- Trang chẩn đoán nhanh **`/local/pdfquiz/admin_check.php`**: kiểm tra version plugin, 2 bảng DB, extension PHP (`fileinfo`, `curl`), và kết nối trực tiếp tới `/health` của dịch vụ Python Process-PDF.

---

## 2. Cấu trúc thư mục repository

```text
PDF_plugin/
├── .gitignore
├── README.md                              # Tài liệu này
├── version.php                            # local_pdfquiz, v1.0.0 (2026112000)
├── settings.php                           # Trang cài đặt Admin của plugin
├── admin_check.php                        # Trang kiểm tra sức khỏe plugin & Python service
├── lib.php                                # Tiện ích dùng chung
├── index.php                              # Trang chủ AI Study Assistant (1 nút PDF to quiz + My history)
├── pdf.php                                # Công cụ PDF to quiz cá nhân cho sinh viên/người dùng
├── process_pdf.php                        # AJAX POST xử lý PDF cho luồng cá nhân
├── history.php                            # Danh sách & xóa lịch sử PDF đã lưu
├── view.php                               # Xem tóm tắt, câu hỏi, văn bản trích xuất
├── edit.php                               # Chủ sở hữu chỉnh sửa nội dung & quyền chia sẻ
├── quiz.php                               # Trang giảng viên tạo Quiz Moodle từ PDF trong khóa học
├── prepare_quiz.php                       # AJAX POST kiểm tra PDF, tạo quizreq và gọi Process-PDF
├── styles.css                             # Giao diện bổ sung cho plugin
├── package.json                           # Cấu hình esbuild để build AMD
├── build-amd.mjs                          # Script build amd/build/*.min.js và moodle-core dialogue.min.js
├── db/
│   ├── access.php                         # 2 capability: local/pdfquiz:use & viewstudentnavigation
│   ├── hooks.php                          # Hook primary_extend cho menu AI hỗ trợ học tập
│   ├── install.xml                        # 2 bảng: local_pdfquiz_items & local_pdfquiz_quizreq
│   ├── services.php                       # 3 hàm AJAX: create_quiz, save_item, update_content
│   └── upgrade.php
├── classes/
│   ├── external/
│   │   ├── create_quiz.php                # Tạo Quiz Moodle ẩn qua API chính thức
│   │   ├── save_item.php                  # Lưu mục ôn tập PDF vào lịch sử
│   │   └── update_content.php             # Cập nhật nội dung có kiểm tra revision
│   ├── local/
│   │   ├── pdf_source.php                 # Kiểm tra đuôi .pdf, finfo MIME, magic header %PDF-, duyệt PDF trong khóa học
│   │   ├── pdf_client.php                 # HTTP client gọi Process-PDF service (/health, /api/v1/process-pdf)
│   │   ├── content_validator.php          # Kiểm tra cấu trúc JSON tóm tắt & câu hỏi 4 đáp án
│   │   ├── quiz_access.php                # Kiểm tra quyền tạo Quiz, sectionid Moodle 5.3 & beforemod
│   │   ├── quiz_request.php               # Metadata & kiểm tra sở hữu yêu cầu tạo Quiz
│   │   ├── quiz_creator.php               # Tạo Quiz ẩn, ngân hàng câu hỏi, slot, điểm trong transaction
│   │   ├── student_navigation.php         # Chèn mục AI hỗ trợ học tập sau Bảng điều khiển
│   │   └── visibility.php                 # Phân quyền xem private / group / course / public
│   └── privacy/
│       └── provider.php                   # Moodle Privacy API
├── lang/
│   ├── en/local_pdfquiz.php               # Gói ngôn ngữ Tiếng Anh
│   └── vi/local_pdfquiz.php               # Gói ngôn ngữ Tiếng Việt
├── templates/
│   └── quizchooser.mustache               # Hộp thoại 2 lựa chọn (Thông thường / Từ file PDF)
├── amd/
│   ├── src/
│   │   ├── quizchooser.js                 # Mở modal chọn cách tạo Quiz
│   │   ├── coursequiz.js                  # Giao diện tạo Quiz khóa học từ PDF
│   │   └── personalpdf.js                 # Giao diện PDF to quiz cá nhân
│   └── build/                             # Bản minify + sourcemap đã build sẵn
├── moodle-core/                           # Bản sao 3 file core tùy biến cho Moodle 5.3
│   └── public/course/format/amd/
│       ├── src/local/activitychooser/dialogue.js
│       └── build/local/activitychooser/
│           ├── dialogue.min.js
│           └── dialogue.min.js.map
├── python_service/                        # Dịch vụ FastAPI tích hợp từ Process-PDF
│   ├── .env.example
│   ├── requirements.txt
│   ├── server.py                          # Endpoint GET /health & POST /api/v1/process-pdf
│   └── src/
│       ├── ai_router.py                   # LiteLLM Gemini, Exponential Backoff, Anti-Hallucination
│       ├── extractors/pdf_parser.py       # PyMuPDF đa cột, cắt lề 10%, diệt logo, Selective OCR
│       └── core/
│           ├── classifier.py              # Phân loại TEXT_HEAVY / MIXED / VISUAL_HEAVY
│           ├── text_chunking.py           # LangChain RecursiveCharacterTextSplitter (3000/300)
│           └── vision_cv.py               # OpenCV blur detection, denoise, CLAHE
└── tests/
    └── cli/smoke_pdf_quiz.php             # Kiểm tra tích hợp Moodle (tự rollback dữ liệu thử)
```

---

## 3. Thay đổi đối với mã nguồn gốc Moodle (Core Patch)

Xem chi tiết đầy đủ từng dòng code (diff), cơ chế fallback và cách hoàn tác tại **[MOODLE_CORE_CHANGES.md](MOODLE_CORE_CHANGES.md)**.

Tóm tắt: Plugin chỉ chỉnh sửa **đúng 1 điểm duy nhất trong core Moodle 5.3** (gồm 1 file nguồn + 2 file build/sourcemap trong `moodle-core/`):

- **File:** `public/course/format/amd/src/local/activitychooser/dialogue.js` (hàm `submitAddSelectedModule()`).
- **Nội dung thay đổi:** khi giảng viên chọn hoạt động có tham số `add=quiz` trong Activity Chooser, gọi `getPlugin('local_pdfquiz/quizchooser')` để mở modal 2 lựa chọn (*Theo cách thông thường* / *Từ file PDF*). Nếu plugin không tồn tại hoặc không nạp được, tự động fallback về form tạo Quiz gốc của Moodle. Mọi hoạt động khác (Assignment, Forum, Resource...) và toàn bộ cơ chế chấm điểm / DB gốc của Quiz **không thay đổi**.
- **Menu sinh viên** dùng hook chuẩn `core\hook\navigation\primary_extend` trong plugin, **không** sửa core hay theme.

---

## 4. Hướng dẫn người mới tích hợp vào Moodle 5.3 gốc từ đầu

### Yêu cầu môi trường
- **Moodle 5.3** đã cài và chạy được (Apache/Nginx + MySQL 8.4+ / MariaDB 11.4+ + PHP 8.3/8.4 bật `curl`, `fileinfo`, `mbstring`).
- **Python 3.10+** (khuyến nghị Python 3.12) để chạy dịch vụ `python_service/`.
- **Google Gemini API key** (từ Google AI Studio).
- *(Tùy chọn)* **Tesseract-OCR** nếu muốn chạy Selective OCR cho các trang PDF scan dạng ảnh; nếu không cài Tesseract, hệ thống vẫn hoạt động bình thường với PDF có chữ và Vision LLM.
- *(Chỉ cần khi sửa JS)* **Node.js 20+**.

### Bước 1: Clone plugin vào đúng vị trí trong Moodle
Mở PowerShell tại thư mục gốc Moodle trên máy bạn (thư mục chứa `public/` và `admin/cli/`, ví dụ `C:\laragon\www\moodle`):

```powershell
$moodleRoot = (Get-Location).Path
$pluginRoot = Join-Path $moodleRoot 'public\local\pdfquiz'

git clone https://github.com/leduc2005/PDF_plugin.git "$pluginRoot"
```

> **Lưu ý:** Tên thư mục đích bắt buộc là **`pdfquiz`** tại `public\local\pdfquiz`.

### Bước 2: Áp dụng 3 file core Activity Chooser
Chạy tại thư mục `public\local\pdfquiz`:

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
```

### Bước 3: Cài đặt và khởi chạy dịch vụ Python Process-PDF
```powershell
Set-Location (Join-Path $pluginRoot 'python_service')
python -m venv .venv
.\.venv\Scripts\pip.exe install -r requirements.txt

# (Tùy chọn) Tạo file .env nếu muốn đặt sẵn GEMINI_API_KEY phía server
Copy-Item .env.example .env

# Chạy dịch vụ (mặc định lắng nghe http://127.0.0.1:8002)
.\.venv\Scripts\python.exe server.py
```
Kiểm tra nhanh dịch vụ bằng trình duyệt hoặc lệnh: `curl http://127.0.0.1:8002/health`.

### Bước 4: Cài đặt plugin vào cơ sở dữ liệu Moodle & xóa cache
Mở terminal khác tại thư mục gốc Moodle (`$moodleRoot`):

```powershell
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```
*(Hoặc đăng nhập **Admin → Site administration → Notifications** để bấm hoàn tất cài đặt, rồi **Development → Purge caches**.)*

### Bước 5: Cấu hình trong trang Admin Moodle
1. Đăng nhập tài khoản **Admin**.
2. Vào **Site administration → Plugins → Local plugins → PDF Quiz AI**.
3. Kiểm tra **URL dịch vụ xử lý PDF** (`http://127.0.0.1:8002`), chọn model Gemini mặc định, giới hạn dung lượng/số trang, và (khuyến nghị) nhập **Gemini API key dùng chung của hệ thống**.
4. Bấm **Kiểm tra kết nối & trạng thái dịch vụ** (`/local/pdfquiz/admin_check.php`) để xác nhận toàn bộ kiểm tra báo `OK` / `Connected`.

---

## 5. Kiểm tra tích hợp & Build lại AMD khi phát triển

### Chạy smoke test Moodle (không làm bẩn DB)
```powershell
Set-Location $moodleRoot
php public/local/pdfquiz/tests/cli/smoke_pdf_quiz.php --check
php public/local/pdfquiz/tests/cli/smoke_pdf_quiz.php --run
```

### Build lại AMD sau khi sửa file trong `amd/src/` hoặc `moodle-core/.../dialogue.js`
```powershell
Set-Location $pluginRoot
npm install
npm run build:amd
```
Sau đó chép lại 3 file từ `moodle-core/` ra `public/course/format/amd/...` (nếu sửa `dialogue.js`) và chạy `php admin/cli/purge_caches.php` + **Ctrl+F5** trên trình duyệt.

---

## 6. Xử lý lỗi thường gặp

| Hiện tượng | Cách khắc phục |
| --- | --- |
| Bấm **Trắc nghiệm → Thêm** vẫn mở thẳng form Moodle cũ | Kiểm tra đã chép đủ 3 file từ `moodle-core/` vào `public/course/format/amd/...`, chạy `purge_caches.php` và nhấn **Ctrl+F5**. |
| Báo `Cannot connect to http://127.0.0.1:8002` | Dịch vụ Python `python_service/server.py` chưa chạy; khởi động lại bằng `.\.venv\Scripts\python.exe server.py` và kiểm tra `/local/pdfquiz/admin_check.php`. |
| Báo `Missing Gemini API key` | Nhập Gemini API key trong trang Admin (**PDF Quiz AI**), trong `python_service/.env`, hoặc nhập trực tiếp trên form. |
| Sinh viên không thấy mục **AI hỗ trợ học tập** | Đảm bảo tài khoản đã được ghi danh vai trò `student` vào ít nhất 1 khóa học và đã chạy `purge_caches.php`. |
| Lỗi `429 Rate Limit` từ Google Gemini | `ai_router.py` tự động chờ (Exponential Backoff) và thử lại; nếu vẫn hết hạn mức, kiểm tra quota API key hoặc giảm số trang chọn qua `Từ trang` – `Đến trang`. |

