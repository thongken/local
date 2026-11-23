# Thuyết minh bài giảng

Trong khóa học, giảng viên có quyền chỉnh sửa và quản lý mở **Thuyết minh bài giảng**. **Nhập slide** trích xuất nội dung từ PPTX, PDF có lớp chữ hoặc TXT UTF-8. **Tạo lời giảng** cho phép nhập hoặc duyệt kịch bản, chọn giọng có sẵn hay mẫu cá nhân, rồi nghe và tải WAV khi tác vụ hoàn thành. Với PDF scan, nhập hoặc dán kịch bản vào trình soạn thảo.

Trong tab **Tạo lời giảng**, mục **Từ bản ghi âm** chép lời tiếng Việt từ AAC, FLAC, M4A, MP3, OGG hoặc WAV. Trình duyệt đọc bản ghi tối đa 500 MB và 30 phút bằng Whisper. Chờ chép lời xong, xem lại kịch bản rồi chọn giọng AI nam hoặc nữ miền Bắc hay miền Nam. Moodle gửi kịch bản đã duyệt đến VieNeu để tạo một file WAV trong nền. Một lời giảng hỗ trợ tối đa 80.000 ký tự, còn thao tác biên tập bằng Gemini hỗ trợ tối đa 12.000 ký tự.

**Giọng giảng viên** nhận bản ghi WAV, MP3, M4A, OGG, AAC hoặc FLAC tối đa 500 MB, tùy giới hạn tải lên của PHP và Moodle. Khi lưu, FFmpeg lấy tối đa 6 giây âm thanh làm mẫu cho VieNeu. Giảng viên có thể nhập vị trí lấy mẫu tính bằng giây hoặc để hệ thống tìm đoạn có âm thanh. Moodle giữ mẫu giọng và audio của bài giảng trong khóa học, chỉ chủ sở hữu có quyền xem. **Cấu hình AI** là tùy chọn để biên tập kịch bản với Gemini. Moodle mã hóa khóa cá nhân và chỉ gửi văn bản khi giảng viên chọn biên tập và đồng ý gửi.

## Chạy trên Windows với Laragon

Từ thư mục chứa hai thư mục **moodle** và **moodledata**, cài môi trường Python một lần. Khuyến nghị Python 3.12 hoặc 3.13.

~~~powershell
$project = (Get-Location).Path
$engine = Join-Path $project 'moodle\public\local\microlearning\engine'
$runtime = Join-Path $project 'moodledata\microlearning-speech'
python -m venv (Join-Path $runtime 'venv')
$voicePython = Join-Path $runtime 'venv\Scripts\python.exe'
& $voicePython -m pip install -r (Join-Path $engine 'requirements.txt')
~~~

Mở **Quản trị hệ thống → Plugins → Local plugins → Microlearning**, đặt URL `http://127.0.0.1:8010` và một token ngẫu nhiên dài ít nhất 32 ký tự. Dùng cùng token khi khởi động dịch vụ. Đoạn lệnh sau nhận token qua cửa sổ nhập mật khẩu của PowerShell và giữ tiến trình ở cửa sổ hiện tại.

~~~powershell
$project = (Get-Location).Path
$engine = Join-Path $project 'moodle\public\local\microlearning\engine'
$runtime = Join-Path $project 'moodledata\microlearning-speech'
$voicePython = Join-Path $runtime 'venv\Scripts\python.exe'
$env:MICROLEARNING_SPEECH_TOKEN = [System.Management.Automation.PSCredential]::new(
    'speech', (Read-Host 'Token dịch vụ thuyết minh' -AsSecureString)
).GetNetworkCredential().Password
$env:HF_HOME = Join-Path $runtime 'models'
$env:HF_HUB_DISABLE_TELEMETRY = '1'
$env:HF_HUB_DISABLE_IMPLICIT_TOKEN = '1'
& $voicePython -B (Join-Path $engine 'server.py')
~~~

Model VieNeu Nano được tải lần đầu khi sinh audio. Mạng cần truy cập Hugging Face trong lần tải này. Model và thư viện nằm trong `moodledata` ngoài thư mục web. Muốn dùng VieNeu Turbo, đặt `MICROLEARNING_SPEECH_MODEL=v3turbo` trong môi trường của dịch vụ trước khi khởi động. Mỗi instance chạy một worker.

## Nâng cấp Moodle và xử lý hàng đợi

Từ thư mục `moodle`, dùng PHP CLI của máy chủ để nâng cấp Moodle và xóa cache. Trên Laragon có thể thay `php` bằng đường dẫn đầy đủ đến `php.exe`.

~~~powershell
php .\admin\cli\upgrade.php --non-interactive
php .\admin\cli\purge_caches.php
~~~

Lịch Moodle cron thực thi các tác vụ thuyết minh trong nền. Trên máy phát triển có thể mở terminal thứ hai để chạy riêng tác vụ này trong một giờ.

~~~powershell
php .\admin\cli\adhoc_task.php --execute --classname='\local_microlearning\task\generate_narration' --keep-alive=3600
~~~

Sau khi bấm **Tạo lời giảng**, dùng **Làm mới trạng thái**. Nếu dịch vụ tạm dừng giữa chừng, khởi động lại rồi bấm **Thử lại** trên tác vụ đã báo lỗi.

## Kiểm tra

Trong thư mục `engine`, chạy `python -B -m unittest discover -s tests` bằng môi trường Python của dịch vụ để kiểm tra xác thực, nhập tài liệu và tách mẫu giọng từ bản ghi.

Từ thư mục `moodle`, chạy `php public/local/microlearning/tests/cli/smoke_narration.php --run` để kiểm tra Moodle kết nối được dịch vụ, tạo tác vụ và lưu WAV thật. Bài kiểm tra dùng dữ liệu tạm và rollback các bản ghi sau khi chạy.

## Docker

Từ thư mục `engine`, có thể build image và ánh xạ cổng cục bộ. Đặt biến `MICROLEARNING_SPEECH_TOKEN` trong môi trường shell trước khi chạy container. Volume `moodle-speech-models` giữ cache giữa các lần khởi động.

~~~powershell
docker build -t microlearning-speech .
docker run --name microlearning-speech --rm -p 127.0.0.1:8010:8010 -e MICROLEARNING_SPEECH_TOKEN -v moodle-speech-models:/models microlearning-speech
~~~

Các tài liệu và kịch bản đi qua dịch vụ này theo từng yêu cầu. Moodle dùng cổng `8010` cho thuyết minh và plugin `local_pdfquiz` tiếp tục dùng cổng `8002` cho Quiz PDF. Mã nguồn model và các lựa chọn giọng đọc được mô tả tại [VieNeu-TTS](https://pypi.org/project/vieneu/3.8.3/) và [VieNeu Nano](https://huggingface.co/pnnbao-ump/VieNeu-TTS-v3-Nano).
