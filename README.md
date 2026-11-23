# Microlearning và PDF Quiz AI cho Moodle 5.3

Hướng dẫn này cài hai plugin trên Windows bằng Laragon. Sau khi cài, sinh viên có một mục **AI hỗ trợ học tập** để tạo quiz từ audio, ghi âm hoặc PDF. Giảng viên có thể tạo bài Trắc nghiệm trong khóa học và tạo lời giảng bằng giọng AI.

## 1. Giải nén Moodle

1. Tải gói ZIP Moodle 5.3, build `20261005`, rồi chọn **Extract All** để giải nén. Bộ file Activity Chooser trong repo đi cùng bản Moodle này. [Tải Moodle 5.3 ZIP](https://download.moodle.org/download.php/stable503/moodle-5.3.zip)
2. Cài Laragon vào `C:\laragon` nếu máy chưa có. Chuyển thư mục `moodle` vừa giải nén vào `C:\laragon\www`. Kết quả phải có file `C:\laragon\www\moodle\public\index.php`. [Tải Laragon](https://laragon.org/download)
3. Cài Git for Windows để dùng lệnh `git clone`. Các lệnh bên dưới dùng **PowerShell**. Nếu bạn đặt Laragon ở thư mục khác, sửa các đường dẫn mẫu cho khớp. [Tải Git for Windows](https://git-scm.com/download/win)

## 2. Thay thư mục local bằng repo này

1. Mở `C:\laragon\www\moodle\public` trong File Explorer.
2. Xóa thư mục `local` **của bản Moodle vừa giải nén**. Thư mục gốc trong ZIP trên có hai file `readme.txt` và `upgrade.txt`. Nếu đang tích hợp vào một Moodle đã có plugin riêng trong `public\local`, giữ thư mục đó và đưa hai thư mục plugin từ một bản clone riêng vào.
3. Mở PowerShell rồi chạy các lệnh sau để clone repo vào đúng vị trí.

~~~powershell
Set-Location -LiteralPath 'C:\laragon\www\moodle\public'
git clone https://github.com/thongken/local.git local
Get-Item -LiteralPath '.\local\microlearning\version.php', '.\local\pdfquiz\version.php'
~~~

Sau khi clone, hai đường dẫn phải là `moodle\public\local\microlearning` và `moodle\public\local\pdfquiz`. Repo đã kèm các file JavaScript đã build để dùng ngay khi Moodle cài xong.

## 3. Di chuyển ba file Activity Chooser

Thực hiện bước này trên bản clone đang cài. Lệnh tương đương **Ctrl+X → Ctrl+V** và ghi đè đúng ba file của Moodle. Bộ file lấy từ `microlearning\moodle-core` để hộp **Trắc nghiệm → Thêm** mở đủ lựa chọn thông thường, audio, ghi âm và PDF.

~~~powershell
$moodlePublic = 'C:\laragon\www\moodle\public'
$patchRoot = Join-Path $moodlePublic 'local\microlearning\moodle-core\public'
$coreFiles = @(
    'course\format\amd\src\local\activitychooser\dialogue.js',
    'course\format\amd\build\local\activitychooser\dialogue.min.js',
    'course\format\amd\build\local\activitychooser\dialogue.min.js.map'
)

foreach ($file in $coreFiles) {
    $source = Join-Path $patchRoot $file
    $destination = Join-Path $moodlePublic $file
    if (!(Test-Path -LiteralPath $source -PathType Leaf)) {
        throw "Không tìm thấy file nguồn $source"
    }
    if (!(Test-Path -LiteralPath $destination -PathType Leaf)) {
        throw "Không tìm thấy file đích $destination"
    }
}

foreach ($file in $coreFiles) {
    Move-Item -LiteralPath (Join-Path $patchRoot $file) `
        -Destination (Join-Path $moodlePublic $file) -Force -ErrorAction Stop
}
~~~

Sau khi di chuyển, Git trong `public\local` sẽ thấy ba file nguồn đã được lấy ra. Ba file đích nằm trong `moodle\public\course\format\amd`.

## 4. Cấu hình Laragon và cài Moodle

### Chọn PHP và MySQL

Trong Laragon, chọn **Apache**, **PHP 8.3 x64** và **MySQL 8.4**. Dùng menu **PHP → Version** và **MySQL → Version** để chọn phiên bản đã cài. Mở `php.ini` từ menu PHP và đặt `max_input_vars = 5000` hoặc cao hơn. Bật các extension mà trình cài Moodle yêu cầu, gồm `mysqli`, `intl`, `curl`, `sodium`, `gd`, `zip` và `mbstring`. [Yêu cầu của Moodle 5.3](https://moodledev.io/general/releases/5.3)

Nhấn **Start All**. Kiểm tra Apache và MySQL đều chạy. Trong **Terminal** của Laragon, gõ `powershell` nếu cửa sổ đang dùng Command Prompt, rồi chạy `php -v` để xác nhận đúng phiên bản PHP sẽ dùng cho các lệnh Moodle.

### Trỏ moodle.test vào thư mục public

1. Bật **Auto Virtual Hosts** với đuôi `.test` trong phần **Preferences** nếu chưa bật. Nhấn **Reload** để Laragon tạo site `moodle.test`. Bật HTTPS bằng biểu tượng ổ khóa trên Laragon. [Auto Virtual Hosts](https://laragon.org/docs/pretty-urls) · [Auto SSL](https://laragon.org/docs/auto-ssl)
2. Mở `C:\laragon\etc\apache2\sites-enabled`. Tìm file cấu hình chứa `ServerName moodle.test`. Nếu tên bắt đầu bằng `auto.`, bỏ tiền tố đó để Laragon giữ nội dung bạn sửa.
3. Trong các khối `<VirtualHost>` của `moodle.test`, cập nhật `DocumentRoot` và `<Directory>` theo mẫu dưới đây. Áp dụng cho cả HTTP và HTTPS. Giữ các dòng SSL do Laragon tạo trong khối cổng `443`. [Cấu trúc thư mục web của Moodle](https://moodledev.io/docs/5.3/guides/restructure)

~~~apache
DocumentRoot "C:/laragon/www/moodle/public"

<Directory "C:/laragon/www/moodle/public">
    Options FollowSymLinks
    AllowOverride None
    Require all granted
    DirectoryIndex index.php
    AcceptPathInfo On
    FallbackResource /r.php
    AddType application/wasm .wasm
</Directory>
~~~

4. Nhấn **Reload**. Nếu trình duyệt báo chứng chỉ chưa được tin cậy, chọn **Menu → Apache → SSL → Add laragon.crt to Trust Store**, rồi mở lại `https://moodle.test`. HTTPS cho phép trình duyệt cấp quyền micro trên địa chỉ này. [Tin cậy chứng chỉ Laragon](https://laragon.org/docs/auto-ssl#add-laragoncrt-to-trust-store)

### Tạo cơ sở dữ liệu và chạy trình cài đặt

1. Mở **Database** trên Laragon để vào HeidiSQL. Kết nối MySQL bằng tài khoản của máy bạn và chạy lệnh sau để tạo cơ sở dữ liệu trống.

~~~sql
CREATE DATABASE moodle53 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
~~~

2. Tạo thư mục `C:\laragon\moodledata-moodle53` bằng File Explorer. Đây là nơi Moodle lưu tệp tải lên và dữ liệu chạy. Đặt thư mục này ngoài thư mục web. [Thư mục dữ liệu Moodle](https://docs.moodle.org/503/en/Installing_Moodle)
3. Mở **https://moodle.test** và đi qua trình cài đặt. Chọn trình điều khiển **MySQL (mysqli)** và dùng các giá trị dưới đây.

| Mục | Giá trị mẫu |
| --- | --- |
| Địa chỉ web | `https://moodle.test` |
| Thư mục dữ liệu | `C:\laragon\moodledata-moodle53` |
| Máy chủ cơ sở dữ liệu | `127.0.0.1` |
| Cổng MySQL | `3306` |
| Tên cơ sở dữ liệu | `moodle53` |
| Tên đăng nhập và mật khẩu | Tài khoản MySQL đã dùng để kết nối HeidiSQL |
| Tiền tố bảng | `mdl_` |

4. Moodle tạo `C:\laragon\www\moodle\config.php` từ thông tin bạn điền. Nếu hiện màn hình yêu cầu lưu thủ công, dùng nội dung mà trình cài cung cấp để tạo `config.php` tại vị trí đó, rồi tải lại trang.
5. Tạo tài khoản quản trị và đặt tên site. Trong **Quản trị hệ thống → Thông báo** (**Site administration → Notifications**), hoàn tất cài **Microlearning** và **PDF Quiz AI** nếu Moodle còn hiện bước cập nhật plugin.

Sau khi cài hoàn tất, xóa cache Moodle từ Terminal của Laragon. Với site đã cài Moodle trước đó, chạy thêm `php .\admin\cli\upgrade.php` trước lệnh xóa cache để Moodle cài hoặc nâng cấp plugin.

~~~powershell
Set-Location -LiteralPath 'C:\laragon\www\moodle'
php .\admin\cli\purge_caches.php
~~~

Trở lại trình duyệt và nhấn **Ctrl+F5**.

## 5. Khởi động hai dịch vụ Python

Cài **Python 3.13 x64** và cho phép trình cài thêm Python vào `PATH`. Mở PowerShell mới và kiểm tra bằng `python --version`. Mỗi dịch vụ có môi trường Python riêng trong `moodledata-moodle53`. Giữ cửa sổ của dịch vụ mở trong khi dùng tính năng tương ứng. [Tải Python cho Windows](https://www.python.org/downloads/windows/)

### PDF Quiz AI trên cổng 8002

Mở một cửa sổ PowerShell và chạy khối lệnh sau. Khi cần bật lại dịch vụ, chạy lại hai dòng gán đường dẫn và dòng chạy `server.py`.

~~~powershell
$pdfService = 'C:\laragon\www\moodle\public\local\pdfquiz\python_service'
$pdfVenv = 'C:\laragon\moodledata-moodle53\pdfquiz-service\venv'
python -m venv $pdfVenv
& "$pdfVenv\Scripts\python.exe" -m pip install -r "$pdfService\requirements.txt"
& "$pdfVenv\Scripts\python.exe" "$pdfService\server.py"
~~~

1. Mở `http://127.0.0.1:8002/health`. Nếu trình duyệt trả về JSON có trạng thái `ok`, dịch vụ đã sẵn sàng nhận yêu cầu.
2. Đăng nhập quản trị Moodle và vào **Quản trị hệ thống → Plugins → Local plugins → PDF Quiz AI**. Đặt **URL dịch vụ xử lý PDF** thành `http://127.0.0.1:8002`.
3. Nhập **Gemini API key dùng chung của hệ thống**, hoặc cho phép người dùng nhập khóa riêng trên form. Chọn model mà dự án Google của bạn có quyền dùng, theo định dạng `gemini/<mã-model>` trong phần thiết lập PDF Quiz. [Tạo Gemini API key](https://ai.google.dev/gemini-api/docs/api-key)
4. Lưu cấu hình và mở **Kiểm tra kết nối & trạng thái dịch vụ** tại `https://moodle.test/local/pdfquiz/admin_check.php`.

### Thuyết minh bài giảng trên cổng 8010

1. Vào **Quản trị hệ thống → Plugins → Local plugins → Microlearning**. Đặt URL dịch vụ thuyết minh thành `http://127.0.0.1:8010`. Tạo token ngẫu nhiên bằng PowerShell và dán kết quả vào ô token của Moodle. Giữ token để nhập lại khi bật dịch vụ Python.

~~~powershell
$tokenBytes = New-Object byte[] 32
$generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$generator.GetBytes($tokenBytes)
$generator.Dispose()
[Convert]::ToBase64String($tokenBytes)
~~~

2. Mở cửa sổ PowerShell thứ hai. Cài môi trường một lần bằng khối lệnh sau.

~~~powershell
$speechEngine = 'C:\laragon\www\moodle\public\local\microlearning\engine'
$speechRuntime = 'C:\laragon\moodledata-moodle53\microlearning-speech'
$speechVenv = Join-Path $speechRuntime 'venv'
python -m venv $speechVenv
& "$speechVenv\Scripts\python.exe" -m pip install -r "$speechEngine\requirements.txt"
~~~

3. Khởi động dịch vụ. Nhập token đã lưu trong Moodle khi PowerShell hỏi. Khi mở một cửa sổ mới để bật lại dịch vụ, chạy lại ba dòng gán đường dẫn ở trên trước khối lệnh này.

~~~powershell
$env:MICROLEARNING_SPEECH_TOKEN = [System.Management.Automation.PSCredential]::new(
    'speech', (Read-Host 'Token dich vu thuyet minh' -AsSecureString)
).GetNetworkCredential().Password
$env:HF_HOME = Join-Path $speechRuntime 'models'
& "$speechVenv\Scripts\python.exe" -B "$speechEngine\server.py"
~~~

VieNeu tải model từ Hugging Face ở lần tổng hợp giọng đầu tiên. Chờ tải xong trước khi đánh giá thời gian tạo WAV. [Tài liệu dịch vụ thuyết minh](microlearning/engine/README.md)

## 6. Chạy thử các tính năng

Tạo một khóa học mẫu. Dùng tài khoản giảng viên có quyền chỉnh sửa để tạo hoạt động và ghi danh một tài khoản sinh viên để kiểm tra navbar.

### Thêm trắc nghiệm vào khóa học

1. Mở khóa học và bật **Chế độ chỉnh sửa**. Trong một mục của khóa học, bấm **Thêm nội dung** hoặc **Thêm hoạt động hoặc tài nguyên**, rồi chọn **Trắc nghiệm → Thêm**.
2. Hộp chọn hiện **Theo cách thông thường**, **Từ tài liệu** (audio), **Ghi âm trực tiếp** và **Từ file PDF**.
3. Thử **Từ file PDF** với một PDF nhỏ có lớp chữ. Đặt tên bài, chọn số câu hỏi và nhấn **Tạo trắc nghiệm từ PDF**. Giữ trang mở đến khi tạo xong. Bài trắc nghiệm được tạo ở trạng thái ẩn với sinh viên để giảng viên kiểm tra câu hỏi trước khi bật hiển thị.
4. Thử **Từ tài liệu** với một MP3 ngắn, hoặc **Ghi âm trực tiếp** rồi cấp quyền micro. Nhập khóa Gemini cá nhân trong form audio hoặc ghi âm. Lần đầu trình duyệt sẽ tải model Whisper để chép lời.

### Dùng AI hỗ trợ học tập với tài khoản sinh viên

Đăng nhập tài khoản đã ghi danh vào khóa học. Nhấn **AI hỗ trợ học tập** trên thanh điều hướng. Trang này mở ba công cụ ôn tập **Audio thành quiz**, **Ghi âm thành quiz** và **PDF to quiz** cùng các liên kết lịch sử.

### Tạo WAV lời giảng

1. Trong khóa học, mở **Xem thêm → Thuyết minh bài giảng** bằng tài khoản có quyền chỉnh sửa.
2. Chọn **Nhập slide** để lấy nội dung từ PPTX, PDF có lớp chữ hoặc TXT. Bạn cũng có thể mở **Tạo lời giảng → Từ bản ghi âm** và tải AAC, FLAC, M4A, MP3, OGG hoặc WAV để Whisper chép lời tiếng Việt trong trình duyệt. Luồng bản ghi âm hỗ trợ tối đa 30 phút và 500 MB.
3. Duyệt hoặc chỉnh kịch bản, chọn giọng đọc AI miền Bắc hoặc miền Nam rồi bấm **Tạo lời giảng**. Nếu muốn Gemini giúp biên tập kịch bản, thêm khóa cá nhân và model trong **Cấu hình AI**, rồi bật đồng ý gửi kịch bản và chọn **Biên tập bằng Gemini**.
4. Giữ dịch vụ giọng đọc chạy. Khi bài hiện **Đang chờ Moodle cron**, mở Terminal Laragon và chạy lệnh dưới đây để Moodle xử lý hàng đợi. Sau đó chọn **Làm mới trạng thái** để nghe hoặc tải WAV.

~~~powershell
Set-Location -LiteralPath 'C:\laragon\www\moodle'
php .\admin\cli\cron.php
~~~

Khi chạy Moodle thường xuyên, dùng **Windows Task Scheduler** để gọi `php.exe` với đối số `C:\laragon\www\moodle\admin\cli\cron.php` mỗi phút. Chọn đúng file `php.exe` của phiên bản PHP đang dùng trong Laragon. [Cấu hình Moodle cron](https://docs.moodle.org/503/en/Cron)

## 7. Khởi động lại và xử lý lỗi thường gặp

Khi mở lại máy, nhấn **Start All** trong Laragon, vào `https://moodle.test`, rồi khởi động dịch vụ Python theo tính năng cần dùng. Tạo bài quiz từ PDF cần dịch vụ `8002`. Tạo WAV lời giảng cần dịch vụ `8010` và Moodle cron. Audio và ghi âm thành quiz dùng trình duyệt và Gemini.

| Hiện tượng | Kiểm tra |
| --- | --- |
| `moodle.test` mở sai trang hoặc thiếu CSS | Xem lại `DocumentRoot` của cả HTTP và HTTPS, rồi **Reload** Apache. |
| **Trắc nghiệm → Thêm** đi thẳng vào form mặc định | Kiểm tra cả ba file ở bước 3, chạy `purge_caches.php` rồi nhấn **Ctrl+F5**. |
| Sinh viên thiếu mục **AI hỗ trợ học tập** | Kiểm tra tài khoản đã ghi danh với vai trò sinh viên và hai plugin đã cài xong. |
| Trình duyệt chưa dùng được micro | Mở `https://moodle.test` bằng chứng chỉ đã tin cậy và cấp quyền micro cho site. |
| PDF báo không kết nối được `127.0.0.1:8002` | Chạy lại `pdfquiz\python_service\server.py` trong môi trường PDF và thử endpoint `/health`. |
| Lời giảng giữ trạng thái **Đang chờ Moodle cron** | Bật dịch vụ thuyết minh và chạy `admin\cli\cron.php` sau khi gửi yêu cầu. |
| Dịch vụ thuyết minh báo lỗi xác thực | So sánh token trong cấu hình Moodle và token đã nhập khi chạy Python. |
| Gemini báo lỗi khóa, model hoặc hạn mức | Kiểm tra khóa, quyền dùng model và hạn mức của dự án trong Google AI Studio. Thử lại với PDF ít trang hoặc kịch bản ngắn hơn. |
| Moodle từ chối tệp tải lên lớn | Kiểm tra `upload_max_filesize` và `post_max_size` trong `php.ini` cùng giới hạn tải lên của Moodle và plugin. Nếu chỉnh PHP, khởi động lại Apache. |
