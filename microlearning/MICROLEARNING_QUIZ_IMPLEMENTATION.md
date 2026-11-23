# Triển khai tạo Trắc nghiệm: thông thường / từ tài liệu / ghi âm

Ngày triển khai ban đầu: 09/11/2026. Cập nhật ngày 10/11/2026: plugin `local_microlearning` **0.2.1**, version **2026111000**.

## 1. Luồng đã triển khai

Trong khóa học, giáo viên chọn **Thêm nội dung → Trắc nghiệm → Thêm**. Hộp chọn hoạt động đóng lại rồi hiển thị ba cách tạo, không chồng hai hộp thoại:

1. **Theo cách thông thường**: đi đến đúng URL tạo Quiz ban đầu của Moodle, giữ các tham số của luồng chuẩn.
2. **Từ tài liệu**: upload một audio mới hoặc chọn một audio đã có trong tài nguyên File của khóa học.
3. **Ghi âm trực tiếp**: ghi bằng micro rồi dùng bản ghi để sinh câu hỏi.

Hai nhánh AI giữ khóa học, phần học và vị trí chèn (`beforemod`). Hỗ trợ cả `sectionid` của hộp chọn Moodle 5.3 và `section` dạng số thứ tự ở URL cũ; server kiểm tra section thuộc đúng khóa học và đổi ID sang số phần trước khi tạo module. Giáo viên nhập tên bài, số câu **1–30**, API key Gemini và bấm **Tạo trắc nghiệm**. Hệ thống xử lý audio, sinh câu hỏi và tự lưu thành **Quiz Moodle thật**, kèm ngân hàng câu hỏi, đáp án và giải thích. Bài mới có thang điểm **10**, mỗi câu trọng số 1, một câu mỗi trang, mặc định **ẩn với sinh viên**. Có các link xem bài, xem/sửa câu hỏi, cài đặt hiển thị và về khóa học.

Giáo viên không cần tạo Quiz rỗng hoặc nhập lại từng câu. Sau khi xem lại, bật hiển thị trong cài đặt bài hoặc menu hoạt động của Moodle.

## 2. Nguồn tài liệu và các giới hạn

- Bản này nhận **MP3, WAV, M4A, OGG**. Nhận diện phần mở rộng/MIME, kiểm tra dung lượng và giải mã âm thanh trước khi tải model.
- Giới hạn audio **30 phút**, **350 MB**, đồng thời tuân thủ mức tải lên PHP/site/khóa học nếu mức đó thấp hơn.
- PDF và MP4 chưa có nhánh tạo câu hỏi; giao diện thông báo rõ. Không đưa PDF vào Whisper.
- Danh sách audio có sẵn duyệt mọi attachment hợp lệ trong tài nguyên File của đúng khóa học, không dựa vào file upload đầu tiên hay file chính. Alias nguồn bên ngoài chưa được hỗ trợ.
- Server kiểm tra lại MIME thực tế của file upload bằng Fileinfo; với nguồn có sẵn, kiểm tra component, file area, context, khóa học và quyền truy cập.
- File upload mới/bản ghi được gửi tới PHP để kiểm tra nguồn và băm nội dung, chỉ tồn tại trong vùng upload tạm của PHP. **Không tự tạo thêm tài nguyên File và không giữ bản audio trên server**. File đã có trong khóa học được giữ nguyên.
- Thời lượng và khả năng giải mã được kiểm tra trong trình duyệt. PHP kiểm tra giới hạn metadata thời lượng; PHP không chạy một bộ giải mã/STT riêng.

## 3. Kiến trúc chạy

- VAD/Whisper chạy trong Web Worker của trình duyệt; ưu tiên WebGPU và có WASM/CPU dự phòng.
- Model Whisper dùng `onnx-community/whisper-base`, có cache trình duyệt.
- Trình duyệt gọi Gemini bằng API key của giáo viên, gửi transcript. API key không đi vào request upload/luồng lưu Quiz của Moodle và không lưu trong DB; chỉ ghi nhớ trên trình duyệt khi giáo viên chọn.
- Số câu được phân bổ theo các phần transcript. Nếu số câu nhỏ hơn số phần, gộp các phần liền nhau mà không bỏ transcript. Kết quả thiếu/thừa số câu bị từ chối và thử lại.
- Moodle dùng API module/question để tạo Quiz, câu hỏi nhiều lựa chọn một đáp án đúng, phiên bản câu hỏi, slots và tổng điểm. Văn bản AI được lưu ở định dạng plain text.
- Tính năng không gọi `ai-platform`, `record-to-quiz` hoặc Python/WSL. Không chạy nền sau khi đóng trang. Giáo viên cần giữ trang mở đến khi hoàn tất.

## 4. Quyền và chống tạo trùng

- Kiểm tra đăng nhập và quyền `moodle/course:manageactivities`, `mod/quiz:addinstance`, `moodle/question:add` tại context khóa học; kiểm tra thêm quyền quản lý ở context Quiz mới.
- Chỉ chủ yêu cầu được lưu Quiz. Chặn nguồn từ khóa học khác và vị trí chèn sai phần học.
- Upload dùng POST + sesskey; tạo Quiz dùng Moodle core/ajax.
- Mỗi lần xử lý có request key riêng. Khóa Moodle bảo vệ việc chuẩn bị/lưu đồng thời; request đã tạo Quiz sẽ trả lại cùng `cmid` khi lưu lại. Không sinh Quiz trùng khi retry sau lỗi mạng.
- Khi lỗi lưu sau bước AI, có nút **Thử lưu bài trắc nghiệm**, dùng đúng request cũ và kết quả AI hiện có.
- Tạo module, câu hỏi và cập nhật request nằm trong một transaction DB.
- Đổi/xóa audio có sẵn trước khi lưu Quiz sẽ bị phát hiện bằng nguồn và content hash.
- Đóng/tải lại trang không tự gửi lại thao tác tạo Quiz. Một lần bấm tạo mới sau khi tải lại là một yêu cầu mới.

## 5. Những file đã sửa/thêm

Các đường dẫn bên dưới tính từ `moodle-5.3/moodle/`.

### Core Moodle — điểm tùy biến cần giữ khi nâng cấp

- **Sửa** `public/course/format/amd/src/local/activitychooser/dialogue.js`, hàm `submitAddSelectedModule()`: riêng `add=quiz` nạp `local_microlearning/quizchooser`; các module khác giữ chuyển hướng chuẩn. Nếu không nạp được plugin, quay về URL form Quiz chuẩn.
- **Build lại** `public/course/format/amd/build/local/activitychooser/dialogue.min.js` và sourcemap.
- Không sửa phần chấm điểm, lưu lượt làm bài hay cấu trúc DB core của Quiz.

### Plugin `public/local/microlearning/`

- **Thêm** `amd/src/quizchooser.js`, `templates/quizchooser.mustache`: hộp chọn ba cách tạo.
- **Sửa** `amd/src/loader.js`: nạp `dist/coursequiz.js` cho luồng tạo Quiz trong khóa học, giữ `dist/app.js` cho công cụ/lịch sử cũ.
- **Thêm** `quiz.php`: trang tạo Quiz AI trong context khóa học.
- **Thêm** `quiz_source.php`: đọc audio có sẵn sau khi kiểm tra quyền và nguồn.
- **Thêm** `prepare_quiz.php`: kiểm tra upload/nguồn, thiết lập và tạo metadata yêu cầu.
- **Thêm** `classes/local/quiz_access.php`, `audio_source.php`, `quiz_request.php`, `quiz_creator.php`: phân quyền, nguồn audio, idempotency và tạo Quiz bằng API Moodle.
- **Thêm** `classes/external/create_quiz.php`, đăng ký trong `db/services.php`.
- **Thêm** bảng `local_microlearning_quizreq` trong `db/install.xml` và migration trong `db/upgrade.php`. Bảng giữ chủ yêu cầu, nguồn/hash, khóa học/vị trí, thiết lập, Quiz đã tạo và thời điểm; không giữ audio hoặc API key.
- **Sửa** `version.php`, chuỗi ngôn ngữ Anh/Việt và privacy provider cho metadata yêu cầu mới.
- **Thêm** `frontend/src/coursequiz.js`, `quizplan.js`: giao diện, pipeline, số câu, tự lưu Quiz và retry.
- **Sửa** `frontend/src/audio.js`, `config.js`, `prompt.js`, `gemini.js`, `pipeline.js`: allowlist/WAV, số câu, kiểm tra đầu ra và hủy khi model đang khởi tạo.
- **Sửa** `frontend/build.mjs`, **thêm** `frontend/build-moodle-amd.mjs`, cập nhật npm scripts. Build dist có kiểm tra đường dẫn trước khi xóa thư mục đầu ra.
- Các bản trong `dist/` và `amd/build/` là sản phẩm build, không chỉnh tay.
- **Thêm** test frontend cho nguồn audio, WAV, phân bổ số câu, retry, hủy và hộp chọn AMD; thêm `tests/cli/smoke_quiz.php` để kiểm tra tích hợp Moodle trong transaction rồi rollback dữ liệu thử.

## 6. Build và cài đặt khi đưa code sang máy khác

Từ `moodle/public/local/microlearning/frontend`:

```powershell
npm install
npm run build
npm run build:amd
npm test
```

`build:amd` dùng esbuild cục bộ để đóng gói đúng các module AMD đã tùy biến; không cần cài toàn bộ dependency frontend của Moodle. Script chuyển import động của module activity chooser sang AMD require để giữ hành vi Moodle.

Từ `moodle-5.3/moodle`, dùng PHP của Laragon (hoặc PHP phù hợp trên máy):

```powershell
& 'C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe' admin/cli/upgrade.php --non-interactive
& 'C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe' admin/cli/purge_caches.php
```

Cũng có thể nâng cấp plugin qua Site administration rồi Purge caches. Cần nâng cấp đến version `2026111000`: bản `2026110900` đăng ký AJAX function/tạo bảng request và bản `2026111000` bổ sung quyền cho mục điều hướng sinh viên. Trình duyệt cần tải lại trang sau khi cập nhật.

Chạy kiểm tra tích hợp (dữ liệu thử được rollback, không gọi Gemini):

```powershell
& 'C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe' public/local/microlearning/tests/cli/smoke_quiz.php --check
& 'C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe' public/local/microlearning/tests/cli/smoke_quiz.php --run
```

## 7. Kết quả kiểm tra

- **Frontend: 42/42 test qua**, gồm cả module AMD core đã build: chỉ chặn Quiz, không đổi luồng hoạt động khác, fallback khi thiếu plugin, ba lựa chọn và tham số khóa học/phần học/vị trí chèn, kể cả URL dùng `sectionid` của Moodle 5.3.
- **Test tích hợp Moodle: 20 kiểm tra qua**, gồm giáo viên có quyền/sinh viên bị chặn, chuyển `sectionid` đúng phần học, audio đính kèm sau PDF chính, chặn nguồn khác khóa học, dữ liệu sai bị từ chối, AJAX function đã đăng ký và đúng kiểu dữ liệu trả về, Quiz ẩn đúng vị trí, ngân hàng câu hỏi/đáp án/điểm, retry không trùng và bộ máy Moodle tự chấm đúng.
- Test tích hợp chạy với khóa học, tài khoản và dữ liệu thử trong transaction rồi rollback. Không giữ bài thử trong khóa học thật; không gọi Gemini trong bộ test này.
- Kiểm tra cú pháp PHP, JavaScript và build frontend/AMD đã thực hiện.
- Đã chạy migration bằng CLI trên máy hiện tại: version cài đặt **2026110900**, bảng request sẵn sàng. Đã purge cache Moodle.
- HTTP của trang đăng nhập trả 200 qua HTTPS với chứng chỉ local hợp lệ.
- **Chưa kiểm tra trực quan và chưa chạy một lượt audio/ghi âm thật → Gemini trên trình duyệt**: công cụ điều khiển trình duyệt trong phiên làm việc không kết nối được. Test Gemini tự động dùng phản hồi giả lập để kiểm tra logic và retry, không chứng minh chất lượng câu hỏi của model thật.

Kiểm tra thủ công sau khi tải lại trang khóa học: chọn Trắc nghiệm → Thêm, thử ba lựa chọn; ở nhánh AI nhập API key hợp lệ, chọn MP3 hoặc ghi âm, chọn số câu, tạo bài rồi xem câu hỏi và trạng thái ẩn. Khi thử micro lần đầu, giáo viên tự cấp quyền micro cho website.

## 8. Những việc để giai đoạn sau

- Tạo câu hỏi từ PDF có chữ, OCR cho PDF scan, video MP4.
- Xử lý nền trên server khi giáo viên rời trang.
- Checkbox tạo quiz tại màn hình upload tài nguyên File.
- Chọn mức độ khó, tự đăng audio thành tài nguyên học tập nếu cần.

Khi nâng cấp source Moodle, phải đối chiếu và áp dụng lại thay đổi tại activity chooser, rồi build AMD lại; plugin riêng không tự giữ được một patch core bị ghi đè.

Checkout hiện tại có Git riêng ở `public/local/microlearning`. Nếu chỉ commit repository plugin, cần quản lý/copy kèm patch core và tài liệu này vì chúng nằm ngoài thư mục repository plugin.

## 9. Cập nhật 10/11/2026 — menu sinh viên

- Thêm mục **AI hỗ trợ học tập** ngay sau **Bảng điều khiển** trên thanh điều hướng chính. Nếu Dashboard bị ẩn trong cấu hình giao diện, thêm vào cuối các mục hiện có.
- Đây là liên kết điều hướng trong cùng tab tới `/local/microlearning/`, tức trang hiện có với Audio thành quiz, Ghi âm thành quiz và Lịch sử của tôi. Không thêm màn hình AI mới.
- Dùng hook chuẩn `core\hook\navigation\primary_extend` của Moodle; thay đổi này nằm trong plugin, không sửa thêm core hay theme.
- Quyền mới `local/microlearning:viewstudentnavigation` mặc định cấp cho role có archetype `student`. Vì dashboard không thuộc một khóa học cụ thể, hook kiểm tra quyền này trong ít nhất một khóa học. Không dựa vào tên hiển thị/ID role cố định. Admin không tự được hiển thị chỉ vì là site admin.
- Người dùng cũng phải đăng nhập, không phải guest và có quyền `local/microlearning:use`. Quyền truy cập các trang Microlearning hiện có được giữ theo cơ chế của chúng.
- Khi vào các trang dưới `/local/microlearning/`, mục AI được đánh dấu đang chọn. Menu dùng đầu ra điều hướng chuẩn của Moodle cho cả desktop và mobile.

File mới (tính từ `moodle/public/local/microlearning/`):

```text
db/hooks.php
classes/local/student_navigation.php
```

File cập nhật thêm trong lần này:

```text
db/access.php
db/upgrade.php
lang/en/local_microlearning.php
lang/vi/local_microlearning.php
version.php
```

Version hiện tại **2026111000 / 0.2.1**. Phần menu không cần build JavaScript; sau khi nâng cấp plugin chỉ cần purge cache và tải lại trang.

Đã cập nhật plugin trên máy hiện tại lên `2026111000`. Kiểm tra cú pháp PHP cho 7 file của thay đổi này và 16 kiểm tra chỉ đọc trên Moodle đã qua: sinh viên thấy mục AI đúng vị trí/URL; không trùng mục; dữ liệu render desktop React/mobile có liên kết; trạng thái active đúng ở trang chính/audio/record/history; giáo viên không có quyền sinh viên, site admin, guest và người chưa đăng nhập không tự được hiển thị mục này. Không thay đổi tài khoản, khóa học hoặc gán role khi kiểm tra.

Chưa xác minh trực quan bằng trình duyệt vì công cụ browser/computer use của phiên làm việc không kết nối được. Việc kiểm tra menu thực hiện qua chính hook và dữ liệu render của Moodle. Tải lại trang sinh viên để xem mục **AI hỗ trợ học tập**.
