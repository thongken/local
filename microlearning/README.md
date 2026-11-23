# Microlearning

Plugin `local_microlearning`, phiên bản **0.3.0** (`2026112300`) trên Moodle **5.3**.

Tài liệu hướng dẫn cài trên máy phát triển, chạy các tính năng đã hiện thực và cập nhật code. Chi tiết thay đổi nằm trong [MICROLEARNING_QUIZ_IMPLEMENTATION.md](MICROLEARNING_QUIZ_IMPLEMENTATION.md).

## 1. Các tính năng hiện có

| Luồng | Cách mở | Kết quả |
| --- | --- | --- |
| Công cụ học tập cá nhân | `/local/microlearning/` → Audio thành quiz hoặc Ghi âm thành quiz | Transcript, tóm tắt và câu hỏi để ôn tập; người dùng bấm **Lưu vào lịch sử** khi muốn lưu |
| Tạo Quiz trong khóa học | Bật chỉnh sửa khóa học → Thêm nội dung → Trắc nghiệm → Thêm | Chọn tạo theo cách thông thường, từ audio hoặc ghi âm; hai nhánh AI tự tạo hoạt động Quiz Moodle cùng câu hỏi, đáp án và chấm điểm |
| Điều hướng cho sinh viên | **AI hỗ trợ học tập**, ngay sau **Bảng điều khiển** | Mở trang công cụ cá nhân trong cùng tab |
| Thuyết minh bài giảng | Trong khóa học → **Thuyết minh bài giảng** | Nhập PPTX/PDF/TXT, duyệt kịch bản, chọn giọng và nghe hoặc tải WAV. Xem [cài dịch vụ giọng đọc](engine/README.md). |

Quiz do giáo viên tạo nằm trong khóa học, mặc định **ẩn với sinh viên** để giáo viên xem lại. Kết quả lưu trong lịch sử cá nhân là nội dung ôn tập, không tự trở thành hoạt động Quiz hay điểm số trong khóa học.

### Các thành phần cần chạy

- Bật **Apache và MySQL** trong Laragon để phục vụ Moodle và cơ sở dữ liệu.
- VAD/Whisper chạy trong trình duyệt: ưu tiên WebGPU, có WASM/CPU dự phòng. Cả hai dùng model `onnx-community/whisper-base`.
- Trình duyệt gửi transcript tới **Google Gemini** để sinh tóm tắt và câu hỏi. Cần Internet và Gemini API key có quyền dùng model đã chọn; lần đầu còn cần tải model Whisper từ Hugging Face.
- Moodle kiểm tra quyền, kiểm tra dữ liệu và lưu lịch sử hoặc tạo Quiz bằng API của Moodle. Luồng tạo Quiz gửi audio tới PHP để kiểm tra nguồn; audio upload mới/bản ghi chỉ nằm trong vùng upload tạm, không được lưu thành tài nguyên File.
- Không cần chạy terminal của `ai-platform`, `record-to-quiz`, Python/WSL hay `ml-service` cho các luồng hiện tại. Không cần giữ tiến trình Node.js chạy sau khi build.

## 2. Cây thư mục

Các đường dẫn dưới đây tính từ thư mục repository `moodle/public/local/microlearning/`:

```text
microlearning/
├── .gitignore
├── README.md
├── MICROLEARNING_QUIZ_IMPLEMENTATION.md
├── version.php                         # Mã plugin, phiên bản và yêu cầu Moodle
├── lib.php                             # Dựng trang công cụ cá nhân, nạp loader/CSS
├── index.php                           # Trang chính: audio, ghi âm, lịch sử
├── audio.php                           # Công cụ audio cá nhân
├── record.php                          # Công cụ ghi âm cá nhân
├── history.php                         # Danh sách/xóa kết quả đã lưu
├── view.php                            # Xem kết quả theo quyền chia sẻ
├── edit.php                            # Chủ sở hữu sửa nội dung đã lưu
├── quiz.php                            # Trang AI tạo Quiz trong khóa học
├── prepare_quiz.php                    # Kiểm tra nguồn, tạo yêu cầu trước khi sinh Quiz
├── quiz_source.php                     # Đọc audio trong khóa học sau khi kiểm tra quyền
├── db/
│   ├── access.php                      # Khai báo capability
│   ├── hooks.php                       # Đăng ký hook điều hướng sinh viên
│   ├── install.xml                     # Hai bảng items và quizreq khi cài mới
│   ├── services.php                    # Đăng ký các hàm AJAX của plugin
│   └── upgrade.php                     # Migration cho máy đã cài plugin
├── classes/
│   ├── external/
│   │   ├── save_item.php               # Lưu kết quả cá nhân
│   │   ├── update_content.php          # Cập nhật kết quả cá nhân
│   │   └── create_quiz.php             # Tạo Quiz Moodle từ kết quả AI
│   ├── local/
│   │   ├── content_validator.php       # Kiểm tra cấu trúc/nội dung phía PHP
│   │   ├── visibility.php              # Quyền xem kết quả cá nhân
│   │   ├── audio_source.php            # Định dạng, dung lượng, audio trong khóa học
│   │   ├── quiz_access.php             # Quyền tạo Quiz, phần học và vị trí chèn
│   │   ├── quiz_request.php            # Metadata yêu cầu và kết quả đã tạo
│   │   ├── quiz_creator.php            # Tạo module, câu hỏi, đáp án, điểm; tránh tạo trùng
│   │   └── student_navigation.php      # Thêm liên kết AI hỗ trợ học tập
│   └── privacy/
│       └── provider.php                # Xuất/xóa dữ liệu cá nhân
├── lang/
│   ├── en/local_microlearning.php
│   └── vi/local_microlearning.php
├── templates/
│   └── quizchooser.mustache            # Ba lựa chọn khi thêm Trắc nghiệm
├── amd/
│   ├── src/
│   │   ├── loader.js                   # Nạp app.js hoặc coursequiz.js
│   │   └── quizchooser.js              # Điều hướng ba cách tạo Quiz
│   └── build/
│       ├── loader.min.js
│       ├── loader.min.js.map
│       ├── quizchooser.min.js
│       └── quizchooser.min.js.map
├── dist/                               # File chạy trên web; được commit cùng source
│   ├── .htaccess                       # MIME cho JavaScript/WASM
│   ├── app.js                          # Bundle công cụ cá nhân
│   ├── coursequiz.js                   # Bundle tạo Quiz trong khóa học
│   ├── stt.worker.js
│   ├── vad.worker.js
│   ├── mic-worklet.js
│   ├── style.css
│   ├── silero_vad_v5.onnx
│   └── ort/                            # Các file .mjs/.wasm của ONNX Runtime Web
├── frontend/
│   ├── package.json
│   ├── package-lock.json
│   ├── build.mjs                       # npm run build → dist/
│   ├── build-moodle-amd.mjs             # npm run build:amd → AMD plugin và core
│   ├── node_modules/                   # Tự cài bằng npm ci, không commit
│   ├── src/
│   │   ├── app.js                      # Điều phối công cụ cá nhân
│   │   ├── coursequiz.js               # Form, pipeline và lưu Quiz tự động
│   │   ├── quizplan.js                 # Phân bổ đúng tổng số câu hỏi yêu cầu
│   │   ├── config.js                   # Giới hạn, chia đoạn, model Gemini mặc định
│   │   ├── strings.js                  # Chuỗi giao diện phía trình duyệt
│   │   ├── audio.js                    # Giải mã audio, micro, kiểm tra file, tạo WAV
│   │   ├── chunker.js                  # Cắt audio theo khoảng có giọng nói
│   │   ├── segmenter.js                # Gom transcript thành các phần
│   │   ├── pipeline.js                 # Điều phối VAD/STT và hủy xử lý
│   │   ├── samplestore.js              # Bộ đệm mẫu âm thanh
│   │   ├── stt.worker.js               # Whisper qua Transformers.js
│   │   ├── vad.worker.js               # Silero VAD qua ONNX Runtime Web
│   │   ├── mic-worklet.js              # Thu mẫu âm thanh từ micro
│   │   ├── prompt.js                   # Prompt cho Gemini
│   │   ├── gemini.js                   # Gọi API, kiểm tra kết quả, thử lại
│   │   ├── validator.js                # Kiểm tra nội dung phía JavaScript
│   │   ├── keystore.js                 # API key trong bộ nhớ/localStorage tùy chọn
│   │   ├── modelmanager.js             # Xác nhận tải model
│   │   ├── progress.js                 # Tiến trình xử lý
│   │   ├── ajax.js                     # Gọi Moodle core/ajax
│   │   ├── dom.js                      # Tiện ích dựng giao diện
│   │   └── style.css
│   └── tests/
│       ├── core.test.js
│       ├── gemini.test.js
│       ├── pipeline.test.js
│       ├── coursequiz.test.js
│       ├── quizchooser.test.js
│       └── activitychooser.test.js
├── tests/
│   └── cli/smoke_quiz.php               # Kiểm tra tích hợp với Moodle
└── moodle-core/                         # Bản sao tùy biến core để bàn giao/cài đặt
    └── public/course/format/amd/
        ├── src/local/activitychooser/dialogue.js
        └── build/local/activitychooser/
            ├── dialogue.min.js
            └── dialogue.min.js.map
```

Moodle nạp core đang nằm tại `moodle/public/course/format/...`. Thư mục `moodle-core/` trong repo chỉ là bản sao để quản lý trên Git; Moodle không tự nạp hoặc tự áp dụng các file trong đó.

## 3. Cài đặt trên máy phát triển

### 3.1. Chuẩn bị Moodle và đường dẫn

Cần có Moodle 5.3 đã cài và truy cập được, Apache/MySQL hoạt động, PHP đáp ứng yêu cầu của Moodle và bật extension **Fileinfo** để kiểm tra upload. Cần Node.js/npm nếu muốn build hoặc chạy test frontend.

Cấu trúc Moodle cần có trên máy nhận code; tên thư mục gốc và ổ đĩa có thể khác nhau:

```text
<thu-muc-goc-Moodle>/
├── config.php                          # Cấu hình thực tế của site
├── admin/cli/                          # Các lệnh upgrade/purge cache
└── public/                             # Document root của Apache
    ├── config.php                      # Nạp cấu hình từ ../config.php
    ├── course/format/amd/               # Nơi áp dụng ba file core tùy biến
    └── local/microlearning/             # Clone repository plugin vào đây
```

Các lệnh dùng PowerShell và tự lấy đường dẫn từ vị trí mở terminal, không phụ thuộc đường dẫn của người viết. `<thu-muc-goc-Moodle>` là thư mục chứa `public/` và `admin/cli/`; không bắt buộc có tên `moodle` hay nằm trong `moodle-5.3/`. `moodledata` nằm ở vị trí do site của bạn cấu hình, ngoài repository plugin.

**Cài mới:** mở PowerShell ngay tại thư mục gốc Moodle trên máy của bạn, rồi chạy:

```powershell
$moodleRoot = (Get-Location).Path
$pluginRoot = Join-Path $moodleRoot 'public\local\microlearning'

git clone --branch feature/quiz-audio-record --single-branch https://github.com/thongken/plugin-microlearning.git "$pluginRoot"

Set-Location -LiteralPath $pluginRoot
```

Chỉ clone khi chưa có thư mục plugin. Nếu bạn clone repo ở nơi khác trước, đặt cả thư mục repo vào `public/local/microlearning/` của Moodle; tên thư mục plugin phải là **`microlearning`**. Không clone lồng thêm một thư mục `microlearning` bên trong plugin.

**Đã có repo và muốn pull:** lưu các thay đổi cục bộ trước, mở PowerShell ngay tại `public/local/microlearning/`, rồi chạy:

```powershell
$pluginRoot = (Get-Location).Path
$moodleRoot = (Resolve-Path (Join-Path $pluginRoot '..\..\..')).Path

git fetch origin
git switch feature/quiz-audio-record
git pull --ff-only origin feature/quiz-audio-record
```

Chạy từng lệnh; nếu Git báo lỗi thì xử lý trước khi tiếp tục. Sau clone/pull, thực hiện mục **3.3** để áp dụng core và mục **3.5** để nâng cấp/xóa cache. Mục **3.4** dành cho trường hợp cần build/test. Lệnh `git pull` chỉ cập nhật repo plugin và bản sao trong `moodle-core/`, không tự chép chúng vào core đang chạy.

Để chạy các lệnh PHP CLI phía dưới, dùng PHP đã được cấu hình trong `PATH` của máy nhận code:

```powershell
$phpExe = (Get-Command php -ErrorAction Stop).Source
& $phpExe --version
```

Nếu không tìm thấy `php`, mở terminal có môi trường PHP của Laragon hoặc thêm thư mục PHP phù hợp của máy bạn vào `PATH`, rồi chạy lại. Nếu dùng giao diện Moodle để nâng cấp, có thể bỏ qua phần khai báo PHP CLI.

### 3.2. Cấu hình HTTPS

- Apache phục vụ thư mục `moodle/public/`; ví dụ site local là `https://moodle.test`.
- Bật SSL cho site trong Laragon và cấu hình chứng chỉ local được hệ điều hành/trình duyệt tin tưởng.
- Trong **`moodle/config.php`**, đặt `$CFG->wwwroot` khớp URL thực tế, ví dụ:

```php
$CFG->wwwroot = 'https://moodle.test';
```

Ghi âm và cache model cần môi trường trình duyệt an toàn: HTTPS hoặc ngoại lệ localhost. Với tên miền `moodle.test`, sử dụng HTTPS. Nếu trình duyệt báo lỗi chứng chỉ, sửa cấu hình chứng chỉ local trước khi thử micro.

### 3.3. Chép ba file core sau khi clone/pull

Bước này cần cho hộp chọn ba cách tạo Quiz. Cài riêng plugin chỉ thêm công cụ/trang AI và menu sinh viên, chưa thay đổi hành vi của nút Trắc nghiệm trong activity chooser.

Ba file core đã được commit trong thư mục **`microlearning/moodle-core/`**, nên người khác clone/pull đúng nhánh sẽ nhận được chúng. Chép **từng file** theo bảng dưới đây; không thay toàn bộ thư mục `course/` hay di chuyển cả `moodle-core/` vào core:

| File nguồn sau khi clone/pull, tính từ repo `microlearning/` | Chép tới, tính từ thư mục gốc Moodle trên máy nhận | Vai trò |
| --- | --- | --- |
| `moodle-core/public/course/format/amd/src/local/activitychooser/dialogue.js` | `public/course/format/amd/src/local/activitychooser/dialogue.js` | Source core mở ba cách tạo khi chọn Trắc nghiệm → Thêm |
| `moodle-core/public/course/format/amd/build/local/activitychooser/dialogue.min.js` | `public/course/format/amd/build/local/activitychooser/dialogue.min.js` | Bản JavaScript đã build mà Moodle nạp |
| `moodle-core/public/course/format/amd/build/local/activitychooser/dialogue.min.js.map` | `public/course/format/amd/build/local/activitychooser/dialogue.min.js.map` | Sourcemap tương ứng |

Ví dụ theo cấu trúc dự án `moodle-5.3/`, ba đích trên chính là `moodle/public/course/format/...`. Thư mục cha của người nhận có tên khác vẫn áp dụng cùng vị trí tương đối trong Moodle.

Bộ thay đổi ban đầu ngoài plugin gồm **ba file core trên và một file tài liệu** `MICROLEARNING_QUIZ_IMPLEMENTATION.md`. Tài liệu đã có bản sao ngay tại gốc repo `microlearning/`, đọc trực tiếp sau khi pull, không cần chép vào core. Bản tài liệu nằm cạnh thư mục Moodle trong workspace gốc chỉ là bản lưu thêm, không bắt buộc để chạy tính năng.

Bản sao core đi kèm dành cho source Moodle 5.3 của dự án. Nếu máy đích có tùy biến khác tại các file này, đối chiếu và sao lưu trước khi chép; khi đổi phiên bản Moodle, cần chuyển phần tùy biến sang source tương ứng.

Có thể chép bằng tay theo bảng hoặc chạy đoạn sau **từ thư mục `public/local/microlearning/`**. Các đường dẫn được tính tự động từ vị trí repo trên máy của bạn:

```powershell
$pluginRoot = (Get-Location).Path
$moodleRoot = (Resolve-Path (Join-Path $pluginRoot '..\..\..')).Path
$coreFiles = @(
    'public/course/format/amd/src/local/activitychooser/dialogue.js'
    'public/course/format/amd/build/local/activitychooser/dialogue.min.js'
    'public/course/format/amd/build/local/activitychooser/dialogue.min.js.map'
)

foreach ($relativePath in $coreFiles) {
    $source = Join-Path (Join-Path $pluginRoot 'moodle-core') $relativePath
    $destination = Join-Path $moodleRoot $relativePath

    New-Item -ItemType Directory -Force -Path (Split-Path $destination -Parent) | Out-Null
    Copy-Item -LiteralPath $source -Destination $destination
}
```

Sau khi chép, giữ nguyên các bản trong `moodle-core/` để Git tiếp tục quản lý. Tiếp tục build nếu cần, chạy upgrade/purge cache ở mục 3.5, rồi tải lại trang khóa học để thử **Trắc nghiệm → Thêm**.

### 3.4. Cài dependency, build và test khi phát triển

```powershell
Set-Location -LiteralPath (Join-Path $pluginRoot 'frontend')

npm ci
npm run build
npm run build:amd
npm test
```

Chạy từng lệnh và xử lý lỗi trước khi tiếp tục.

- `npm ci` cài dependency theo `package-lock.json`.
- `npm run build` sinh lại `dist/`, gồm hai app, worker, CSS, VAD model và ONNX Runtime.
- `npm run build:amd` build `amd/build/` của plugin và activity chooser **trong core Moodle đang chạy**. Phải áp dụng core tùy biến ở bước 3.3 trước; script không tự chép source từ `moodle-core/` ra ngoài.
- `npm test` chạy test JavaScript; test activity chooser cần cây thư mục Moodle và file core đã build ở đúng vị trí.

Repo đã kèm `dist/` và `amd/build/`. Nếu chỉ chạy đúng bản đã commit và không sửa JavaScript, có thể bỏ qua bước cài Node/build; vẫn cần áp dụng ba file core và nâng cấp plugin.

### 3.5. Nâng cấp plugin và xóa cache

Bật MySQL, sau đó chạy từ **`moodle/`**, không phải `moodle/public/`:

```powershell
Set-Location -LiteralPath $moodleRoot

& $phpExe admin/cli/upgrade.php --non-interactive
& $phpExe admin/cli/purge_caches.php
```

Cũng có thể đăng nhập admin → **Site administration → Notifications** để hoàn tất cài đặt/nâng cấp, rồi **Development → Purge caches**.

Kiểm tra plugin đã lên version **`2026112300` / `0.3.0`**. Moodle cập nhật các quyền và thêm bảng cho lời giảng, mẫu giọng và khóa Gemini cá nhân. Tải lại trang sau khi nâng cấp.

## 4. Chạy tính năng giáo viên: tạo Quiz trong khóa học

### 4.1. Mở hộp chọn

1. Đăng nhập bằng tài khoản giáo viên có quyền chỉnh sửa trong khóa học.
2. Mở khóa học và bật **Chế độ chỉnh sửa**.
3. Tại phần học muốn đặt Quiz, chọn **Thêm nội dung/Thêm một hoạt động hoặc tài nguyên**.
4. Chọn **Trắc nghiệm → Thêm**. Hộp chọn hoạt động đóng lại và mở ba cách tạo:
   - **Theo cách thông thường**: mở form Quiz chuẩn của Moodle.
   - **Từ tài liệu**: tải audio mới hoặc chọn audio có sẵn trong khóa học.
   - **Ghi âm trực tiếp**: thu nội dung từ micro rồi tạo Quiz.

Tên **Từ tài liệu** hiện chỉ xử lý audio; PDF và MP4 chưa có pipeline tạo câu hỏi.

### 4.2. Tạo từ audio

1. Chọn **Từ tài liệu**.
2. Nhập tên bài trắc nghiệm và số câu hỏi **1–30**.
3. Chọn **Tải một tệp audio mới**, rồi chọn file MP3/WAV/M4A/OGG; hoặc chọn một audio có sẵn trong danh sách nguồn.
4. Nhập Gemini API key. Có thể đổi model ở **Nâng cao → Model Gemini**.
5. Bấm **Tạo trắc nghiệm**; đồng ý tải model nhận dạng nếu được hỏi.
6. Giữ trang mở trong lúc giải mã audio → nhận dạng giọng nói → sinh câu hỏi → tạo Quiz và lưu vào Moodle.
7. Khi thành công, dùng các nút xem bài, xem/sửa câu hỏi, cài đặt hoặc quay về khóa học.

Danh sách nguồn kiểm tra các audio đính kèm hợp lệ trong tài nguyên **File** của đúng khóa học, không chỉ file đầu tiên hay file chính. Ví dụ một tài nguyên có PDF là file chính và M4A là tệp đính kèm thì audio vẫn có thể xuất hiện trong danh sách nguồn.

### 4.3. Tạo từ ghi âm

1. Chọn **Ghi âm trực tiếp**, nhập tên bài và số câu hỏi.
2. Bấm **Bắt đầu ghi âm**, cấp quyền micro cho website.
3. Bấm **Dừng ghi âm** khi xong; ghi âm tự dừng khi đủ **30 phút**. Nút ghi lại sẽ thay bản ghi trước.
4. Nhập API key, bấm **Tạo trắc nghiệm** và chờ lưu hoàn tất.

### 4.4. Kiểm tra và cho sinh viên làm bài

Quiz mới có câu hỏi nhiều lựa chọn, mỗi câu bốn phương án và một đáp án đúng, kèm giải thích. Mặc định thang điểm **10**, mỗi câu trọng số **1**, một câu mỗi trang và **ẩn với sinh viên**.

Giáo viên xem lại nội dung/đáp án, điều chỉnh cấu hình bài nếu cần, rồi bật hiển thị trong cài đặt hoặc menu hoạt động. Sau đó sinh viên làm bài qua hoạt động Trắc nghiệm chuẩn của Moodle và được chấm điểm bằng Moodle.

Nếu câu hỏi đã sinh nhưng chưa xác nhận được kết quả lưu, dùng **Thử lưu bài trắc nghiệm** ngay trên trang. Nút này dùng lại cùng yêu cầu; nếu Quiz đã tạo thành công, server trả lại bài đó để tránh tạo trùng. Tải lại trang rồi bấm tạo mới là một yêu cầu mới.

## 5. Chạy công cụ học tập cá nhân và menu sinh viên

### 5.1. Điều hướng

Sinh viên có quyền phù hợp sẽ thấy **AI hỗ trợ học tập** ngay sau **Bảng điều khiển**. Bấm vào mở `/local/microlearning/` trong cùng tab; nếu Dashboard bị ẩn trong cấu hình Moodle, mục AI nằm cuối các mục điều hướng hiện có.

Cũng có thể truy cập trực tiếp các URL dưới đây, thay tên miền theo site của bạn:

| Trang | URL ví dụ |
| --- | --- |
| Trang chính | `https://moodle.test/local/microlearning/` |
| Audio cá nhân | `https://moodle.test/local/microlearning/audio.php` |
| Ghi âm cá nhân | `https://moodle.test/local/microlearning/record.php` |
| Lịch sử | `https://moodle.test/local/microlearning/history.php` |

### 5.2. Tạo và lưu nội dung ôn tập

1. Chọn **Audio thành quiz** hoặc **Ghi âm thành quiz** trên trang chính.
2. Nhập Gemini API key và tiêu đề.
3. Chọn audio hoặc bấm bắt đầu/dừng ghi âm.
4. Bấm **Xử lý**, đồng ý tải model nếu được hỏi và giữ trang mở đến khi có kết quả.
5. Xem tóm tắt và câu hỏi; có thể **Thử lại** hoặc **Sinh lại kèm phản hồi** để điều chỉnh nội dung.
6. Bấm **Lưu vào lịch sử** để lưu transcript, tóm tắt và câu hỏi trên Moodle.
7. Mở **Lịch sử của tôi** để xem, sửa hoặc xóa kết quả thuộc sở hữu của mình. Form sửa hỗ trợ quyền xem riêng tư, theo nhóm, theo khóa học hoặc mọi người đã đăng nhập.

Luồng cá nhân sinh câu hỏi theo các phần transcript. Ô chọn tổng số câu **1–30** và cơ chế tự tạo hoạt động Quiz thuộc luồng giáo viên trong khóa học.

### 5.3. Quyền truy cập

| Chức năng | Điều kiện |
| --- | --- |
| Trang công cụ/lịch sử cá nhân | Đăng nhập và có `local/microlearning:use` tại context hệ thống; mặc định cấp cho người dùng đã đăng nhập |
| Menu AI hỗ trợ học tập | Không phải guest, có quyền dùng công cụ và có `local/microlearning:viewstudentnavigation` trong ít nhất một khóa học; capability này mặc định cấp cho archetype `student` |
| Tạo Quiz trong khóa học | Có `moodle/course:manageactivities`, `mod/quiz:addinstance`, `moodle/question:add` tại khóa học; khi tạo còn kiểm tra quyền quản lý Quiz/câu hỏi ở context module |

Plugin kiểm tra capability, không dựa vào tên role hay ID role cố định. Một tài khoản có thể là sinh viên ở khóa học này và giáo viên ở khóa học khác. Admin không tự được thêm menu sinh viên chỉ vì là site admin; các tài khoản có quyền dùng công cụ vẫn có thể truy cập URL trực tiếp.

## 6. Giới hạn và dữ liệu

- Audio tối đa **30 phút**, **350 MB**. Với luồng tạo Quiz khóa học, giới hạn upload còn phụ thuộc mức PHP/site/khóa học nếu thấp hơn; mức được áp dụng hiển thị ngay trong form.
- Luồng tạo Quiz khóa học chỉ nhận **MP3, WAV, M4A, OGG**; kiểm tra phần mở rộng/MIME, dung lượng và khả năng giải mã, đồng thời kiểm tra MIME thực tế trên PHP cho upload mới.
- PDF, video MP4, OCR và checkbox tạo Quiz trong form upload tài nguyên File chưa được hiện thực. Muốn tạo Quiz từ audio đã upload vào File, chọn lại nguồn đó qua nhánh **Từ tài liệu**.
- Alias tới file bên ngoài chưa được hỗ trợ làm nguồn Quiz. File nguồn phải thuộc tài nguyên File trong đúng khóa học và người dùng phải có quyền xem.
- Ghi âm tự dừng ở 30 phút theo cấu hình hiện tại. Riêng dòng trợ giúp trong công cụ ghi âm cá nhân vẫn còn ghi “20 phút”; đây là chuỗi giao diện cũ trong `frontend/src/strings.js`, chưa khớp giới hạn xử lý thực tế.
- API key mặc định giữ trong bộ nhớ trình duyệt; chỉ lưu vào `localStorage` khi chọn **Ghi nhớ khóa trên máy này**. Key gửi tới Gemini, không lưu trong DB Moodle.
- Luồng cá nhân lưu transcript/nội dung vào `local_microlearning_items`. Luồng Quiz lưu hoạt động/câu hỏi chuẩn Moodle và metadata liên kết tại `local_microlearning_quizreq`.
- Upload mới/bản ghi trong luồng Quiz được PHP kiểm tra và băm nội dung, không tự tạo tài nguyên File và không giữ audio trên server sau upload tạm. Audio vốn có trong khóa học được giữ nguyên.
- Chưa có xử lý nền trên server; đóng tab có thể dừng quá trình. Tốc độ STT phụ thuộc máy người dùng, WASM hiện chạy một luồng. Ưu tiên kiểm tra trên Chrome/Edge máy tính; chưa xác nhận đầy đủ trên điện thoại và các trình duyệt khác.

## 7. Kiểm tra sau khi cài

### Test tự động

Tại thư mục `frontend/` bên trong cây Moodle đã áp dụng core và build AMD:

```powershell
Set-Location -LiteralPath (Join-Path $pluginRoot 'frontend')
npm test
```

Test kiểm tra xử lý đoạn audio/transcript, validator, gọi Gemini giả lập, phân bổ câu hỏi, hủy pipeline và điều hướng activity chooser. Test Gemini không gọi API thật và không chứng minh chất lượng câu hỏi từ model thật.

Kiểm tra cài đặt và tích hợp với DB Moodle:

```powershell
Set-Location -LiteralPath $moodleRoot

& $phpExe public/local/microlearning/tests/cli/smoke_quiz.php --check
& $phpExe public/local/microlearning/tests/cli/smoke_quiz.php --run
```

`--check` in phiên bản cài đặt, trạng thái bảng yêu cầu và phiên bản core. `--run` tạo dữ liệu thử trong transaction rồi rollback, kiểm tra quyền, nguồn audio, Quiz ẩn, câu hỏi/đáp án, chấm điểm và lưu lại cùng yêu cầu. Chạy trên DB phát triển có dữ liệu nền Moodle và role chuẩn; bài kiểm tra không gọi Gemini.

### Kiểm tra bằng trình duyệt

- Với giáo viên: mở ba lựa chọn, thử nhánh thông thường, tạo Quiz từ một MP3 ngắn có lời nói và thử ghi âm thật với API key hợp lệ.
- Kiểm tra Quiz nằm đúng phần học/vị trí, đủ số câu, có đáp án và đang ẩn; xem lại rồi bật hiển thị.
- Thử một tài nguyên File có PDF làm file chính và audio làm tệp đính kèm; xác nhận audio vẫn chọn được trong danh sách nguồn.
- Với sinh viên đã ghi danh khóa học: kiểm tra menu AI, điều hướng cùng tab, tạo nội dung ôn tập và lưu lịch sử. Sinh viên không có quyền tạo Quiz khóa học.
- Sau khi giáo viên cho hiện Quiz: kiểm tra sinh viên mở được bài, nộp bài và có điểm theo cơ chế Moodle.

## 8. Quy trình sửa code và đưa lên Git

| Phần thay đổi | Việc cần làm |
| --- | --- |
| `frontend/src/` | Chạy `npm run build` tại `frontend/`, chạy test và commit source cùng `dist/` |
| `amd/src/` hoặc core `dialogue.js` | Chạy `npm run build:amd`, chạy test; giữ bản source và bản build đồng bộ |
| Schema DB | Cập nhật `db/install.xml`, thêm migration vào `db/upgrade.php`, tăng version và chạy upgrade |
| Capability/hook/hàm AJAX | Cập nhật khai báo liên quan, tăng version khi cần Moodle nhận thay đổi, chạy upgrade và purge cache |
| Ngôn ngữ/template/điều hướng hoặc file build đã thay | Purge cache Moodle và tải lại trang để kiểm tra |

Không sửa tay `dist/` hoặc `amd/build/`. `build:amd` đã có script riêng, không cần viết lại file `.min.js` thủ công. Logic validator JavaScript và PHP cần khớp nhau.

Các file web có tham số `rev`/`v` để hỗ trợ chống cache; PHP lấy thời điểm sửa `dist/app.js` làm revision. Nếu vẫn thấy giao diện cũ sau khi build, purge cache Moodle, Ctrl+F5 hoặc dùng DevTools → Network → Disable cache rồi tải lại.

### Đồng bộ bản sao core trước khi commit

Sau khi sửa/build activity chooser trong Moodle, cập nhật bản sao trong repo để người khác nhận được thay đổi. Mở PowerShell ngay tại `public/local/microlearning/` và chạy đoạn sau. Đây là chiều chép **core đang chạy → bản sao trong repo**, ngược với bước cài đặt 3.3:

```powershell
$pluginRoot = (Get-Location).Path
$moodleRoot = (Resolve-Path (Join-Path $pluginRoot '..\..\..')).Path
$coreFiles = @(
    'public/course/format/amd/src/local/activitychooser/dialogue.js'
    'public/course/format/amd/build/local/activitychooser/dialogue.min.js'
    'public/course/format/amd/build/local/activitychooser/dialogue.min.js.map'
)

foreach ($relativePath in $coreFiles) {
    $source = Join-Path $moodleRoot $relativePath
    $destination = Join-Path (Join-Path $pluginRoot 'moodle-core') $relativePath

    New-Item -ItemType Directory -Force -Path (Split-Path $destination -Parent) | Out-Null
    Copy-Item -LiteralPath $source -Destination $destination
}
```

### File được commit và file được bỏ qua

- **Commit:** source PHP/JS, template, test, tài liệu, `frontend/package.json`, `frontend/package-lock.json`, toàn bộ `dist/`, `amd/build/` gồm sourcemap và ba file core trong `moodle-core/`.
- **Bỏ qua theo `.gitignore`:** `node_modules/`, `.env`/`.env.*` trừ `.env.example`, log, file tạm, `.cache/`, `coverage/` và cấu hình editor cá nhân.
- `moodle/config.php`, `moodledata/` và DB của site nằm ngoài repo plugin; repo không mang theo cấu hình site, tài khoản hay các Quiz đã tạo trong DB.
- Khi nâng cấp Moodle, đối chiếu và áp dụng lại phần sửa activity chooser, rồi build AMD và cập nhật bản sao core. Menu sinh viên dùng hook trong plugin, không cần sửa thêm core/theme.

## 9. Lỗi thường gặp

| Hiện tượng | Kiểm tra/cách xử lý |
| --- | --- |
| Bấm Trắc nghiệm vẫn vào form Moodle thông thường | Kiểm tra ba file core đã được áp dụng ra `moodle/public/course/format/...`, các file `amd/build/quizchooser.*` của plugin có đủ, rồi purge cache. Nếu module plugin không nạp được, chooser chủ động quay về form chuẩn |
| Sinh viên không thấy menu AI | Kiểm tra đã upgrade lên `2026111000`, purge cache, tài khoản có quyền dùng công cụ và capability menu trong ít nhất một khóa học; không chỉ đổi tên hiển thị role |
| Báo không có quyền tạo Quiz | Kiểm tra role/capability tại đúng khóa học, đặc biệt giáo viên phụ hoặc role tùy chỉnh; kiểm tra module Quiz được bật |
| Không ghi âm được | Kiểm tra HTTPS/chứng chỉ được tin tưởng, quyền micro của site và thiết bị đầu vào |
| Không tải/cache được model | Kiểm tra HTTPS, quyền lưu trữ của trình duyệt, kết nối tới Hugging Face; lần đầu cần đợi tải model |
| Lỗi 404 khi tải app/worker/WASM | Kiểm tra `dist/` và `dist/ort/` đã có đủ file; chạy lại build nếu thiếu và kiểm tra Document root là `moodle/public/` |
| File lớn bị từ chối hoặc lỗi upload 413 | Kiểm tra giới hạn PHP `upload_max_filesize`/`post_max_size`, giới hạn web server, site và khóa học; giới hạn thực tế có thể nhỏ hơn 350 MB |
| PDF/MP4 không tạo được Quiz | Hiện chỉ nhánh audio được hỗ trợ; dùng MP3/WAV/M4A/OGG |
| Gemini báo 400/401/403, 404 hoặc 429 | Đọc chi tiết lỗi: kiểm tra API key/quyền dùng model, tên model hoặc hạn mức. Model mặc định nằm trong `frontend/src/config.js`; luồng khóa học cho nhập model ở Nâng cao, luồng cá nhân có danh sách model trong `frontend/src/app.js` |
| AI đã sinh câu hỏi nhưng lưu Quiz báo lỗi | Giữ trang mở, kiểm tra phiên đăng nhập/quyền và dùng **Thử lưu bài trắc nghiệm** thay vì tạo yêu cầu mới |
| Test/build AMD lỗi thiếu file core | Đặt plugin đúng `moodle/public/local/microlearning/`, áp dụng core tùy biến trước rồi chạy `npm run build:amd`; checkout plugin đứng riêng chưa đủ cho phần này |
