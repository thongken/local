<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'PDF Quiz AI';
$string['studentnav'] = 'AI hỗ trợ học tập';
$string['pdfquiz:viewstudentnavigation'] = 'Hiển thị liên kết AI hỗ trợ học tập trên thanh điều hướng';
$string['pdfquiz:use'] = 'Sử dụng PDF Quiz AI (PDF thành quiz và lịch sử cá nhân)';

// Hộp chọn tạo trắc nghiệm (2 lựa chọn: Thông thường + Từ file PDF).
$string['quizchoose'] = 'Bạn muốn tạo trắc nghiệm bằng cách nào?';
$string['quizchoosehelp'] = 'Chọn cách tạo. Cả hai cách đều tạo bài Trắc nghiệm Moodle trong phần học bạn đang chọn.';
$string['quizmanual'] = 'Theo cách thông thường';
$string['quizmanualhelp'] = 'Tạo bài rồi nhập câu hỏi, chọn từ ngân hàng câu hỏi hoặc nhập bộ câu hỏi.';
$string['quizpdf'] = 'Từ file PDF';
$string['quizpdfhelp'] = 'Tải lên file PDF mới hoặc chọn file PDF đã có sẵn.';

// Tạo Quiz trong khóa học (Giảng viên).
$string['coursequiz'] = 'Tạo trắc nghiệm từ file PDF';
$string['coursequizhelp'] = 'Hệ thống phân tích file PDF (văn bản, sơ đồ, bảng biểu), tự động sinh câu hỏi và lưu thành bài Trắc nghiệm Moodle đang ẩn với sinh viên. Hãy giữ trang mở đến khi xử lý xong rồi kiểm tra câu hỏi trước khi bật hiển thị.';
$string['backtocourse'] = 'Về khóa học';
$string['quizunavailable'] = 'Không thể tạo bài Trắc nghiệm trong khóa học này.';
$string['unsupportedsource'] = 'Chỉ hỗ trợ tệp PDF hợp lệ (.pdf).';
$string['pdfsize'] = 'File PDF phải có dữ liệu và không vượt quá giới hạn tải lên (tối đa {$a} MB).';
$string['invalidsource'] = 'Không tìm thấy file PDF, không có quyền truy cập hoặc file không nằm trong khóa học này.';
$string['pdfuploadfailed'] = 'Moodle không nhận được file PDF. Kiểm tra giới hạn tải lên của PHP/trang web rồi thử lại.';
$string['quizbusy'] = 'Yêu cầu tạo bài này đang được xử lý. Hãy thử lại sau ít giây.';
$string['generatedquizmissing'] = 'Bài Trắc nghiệm của yêu cầu này đã bị xóa. Hãy bắt đầu một yêu cầu mới.';
$string['quizsavefailed'] = 'Không lưu được câu hỏi thành bài Trắc nghiệm Moodle.';
$string['sourcechanged'] = 'File PDF nguồn đã thay đổi. Hãy tải lại trang và tạo trắc nghiệm lại.';
$string['serviceerror'] = 'Lỗi dịch vụ xử lý PDF: {$a}';
$string['missingapikey'] = 'Cần có Gemini API key. Hãy nhập API key của bạn hoặc nhờ Quản trị viên cấu hình khóa dùng chung.';

// AI study assistant (Sinh viên / Cá nhân).
$string['pdf_title'] = 'PDF to quiz';
$string['pdf_desc'] = 'Tải lên file PDF mới (hoặc chọn file PDF từ các khóa học của bạn) để tóm tắt và tạo câu hỏi trắc nghiệm ôn tập.';
$string['open'] = 'Mở';
$string['history'] = 'Lịch sử của tôi';
$string['back'] = 'Quay lại';
$string['nojs'] = 'Trang này cần bật JavaScript.';
$string['noaccess'] = 'Bạn không có quyền xem mục này.';
$string['noitems'] = 'Bạn chưa có mục nào được lưu.';

// Form & nhãn giao diện.
$string['titlefield'] = 'Tiêu đề';
$string['quiztitle'] = 'Tên bài trắc nghiệm';
$string['questioncount'] = 'Số câu hỏi (1–30)';
$string['pdfsource'] = 'Nguồn file PDF';
$string['uploadnewpdf'] = 'Tải lên file PDF mới';
$string['choosecoursepdf'] = 'Chọn file PDF đã có sẵn trong khóa học';
$string['nocoursepdfs'] = 'Chưa có file PDF nào trong tài nguyên của khóa học này.';
$string['advancedoptions'] = 'Tùy chọn nâng cao';
$string['pagefrom'] = 'Từ trang (tùy chọn)';
$string['pageto'] = 'Đến trang (tùy chọn)';
$string['geminimodel'] = 'Model Gemini';
$string['geminiapikey'] = 'Gemini API key';
$string['apikeyplaceholder'] = 'AIzaSy...';
$string['apikeysystemconfigured'] = 'Quản trị viên đã cấu hình Gemini API key dùng chung. Bạn có thể để trống để dùng khóa của hệ thống.';
$string['apikeysystemonly'] = 'Đang dùng Gemini API key dùng chung do Quản trị viên cấu hình.';
$string['rememberkey'] = 'Ghi nhớ API key trên trình duyệt này (localStorage)';
$string['generatequiz'] = 'Tạo trắc nghiệm từ PDF';
$string['generatepractice'] = 'Tạo tóm tắt & Quiz';
$string['processingpdf'] = 'Đang xử lý file PDF (trích xuất văn bản/sơ đồ, phân loại trang và sinh câu hỏi)... Vui lòng giữ trang mở.';
$string['savingquiz'] = 'Đang lưu câu hỏi thành bài Trắc nghiệm Moodle...';
$string['retrycreatequiz'] = 'Thử lưu lại bài trắc nghiệm vào Moodle';
$string['savehistory'] = 'Lưu vào lịch sử của tôi';
$string['savedtohistory'] = 'Đã lưu vào lịch sử của bạn.';
$string['savetomoodle'] = 'Lưu thành bài Trắc nghiệm Moodle';
$string['previewheading'] = 'Xem trước tóm tắt & câu hỏi đã tạo (chưa lưu vào khóa học)';
$string['refineheading'] = 'Yêu cầu AI chỉnh sửa lại tóm tắt & quiz';
$string['refineplaceholder'] = 'VD: Viết bằng tiếng Việt, câu hỏi chi tiết và khó hơn, tóm tắt dài hơn, giải thích kỹ hơn...';
$string['refinebutton'] = 'Chỉnh sửa bằng AI';
$string['refining'] = 'AI đang chỉnh sửa lại tóm tắt và câu hỏi theo yêu cầu của bạn...';
$string['refinedsuccess'] = 'AI đã cập nhật xong trong {$a} giây.';
$string['refineempty'] = 'Hãy nhập yêu cầu chỉnh sửa cho AI.';
$string['quizcreated'] = 'Đã tạo thành công bài Trắc nghiệm Moodle (đang ẩn với sinh viên)!';
$string['viewquiz'] = 'Xem bài trắc nghiệm';
$string['editquestions'] = 'Xem / Sửa câu hỏi';
$string['quizsettings'] = 'Cài đặt bài (bật hiển thị)';
$string['pages'] = 'Số trang';
$string['pagescount'] = '{$a} trang';
$string['pagerange'] = 'Trang {$a->start}–{$a->end}';
$string['routingstats'] = 'Phân tích: {$a->total} trang (Văn bản: {$a->text}, Hỗn hợp: {$a->mixed}, Hình ảnh: {$a->visual}, OCR: {$a->ocr}) trong {$a->time} giây.';

// Lịch sử / xem / sửa.
$string['visibility'] = 'Quyền xem';
$string['vis_private'] = 'Riêng tư';
$string['vis_group'] = 'Chỉ một lớp (nhóm)';
$string['vis_course'] = 'Chỉ một khóa học';
$string['vis_public'] = 'Công khai (mọi người dùng đã đăng nhập)';
$string['vis_course_help'] = 'Dùng khi quyền xem là "Chỉ một khóa học". Bạn phải đã ghi danh khóa học đó.';
$string['vis_group_help'] = 'Dùng khi quyền xem là "Chỉ một lớp". Bạn phải là thành viên của nhóm đó.';
$string['created'] = 'Ngày tạo';
$string['actions'] = 'Thao tác';
$string['edit'] = 'Sửa';
$string['delete'] = 'Xóa';
$string['confirmdelete'] = 'Xóa vĩnh viễn mục này?';
$string['deleted'] = 'Đã xóa.';
$string['save'] = 'Lưu thay đổi';
$string['saved'] = 'Đã lưu thay đổi.';
$string['saveerror'] = 'Không thể lưu: {$a}';
$string['conflict'] = 'Mục này đã bị thay đổi ở nơi khác. Hãy tải lại trang rồi thử lại.';
$string['summary'] = 'Tóm tắt';
$string['shortsummary'] = 'Tóm tắt ngắn';
$string['mainpoints'] = 'Ý chính';
$string['mainpoints_help'] = 'Mỗi ý một dòng.';
$string['segment'] = 'Phần';
$string['segmentsummary'] = 'Tóm tắt phần';
$string['question'] = 'Câu hỏi';
$string['correct_help'] = 'Chọn nút tròn cạnh đáp án đúng.';
$string['explanation'] = 'Giải thích';
$string['showanswer'] = 'Xem đáp án';
$string['answer'] = 'Đáp án';
$string['extractedtext'] = 'Văn bản trích xuất từ PDF (chỉ bạn thấy)';

// Cài đặt Admin & kiểm tra kết nối.
$string['settingsheading'] = 'Cấu hình dịch vụ xử lý PDF & AI';
$string['settingsdesc'] = 'Cấu hình kết nối tới dịch vụ Python Process-PDF, model AI mặc định và giới hạn file PDF. <a href="{$a}">Kiểm tra kết nối & trạng thái dịch vụ</a>.';
$string['setting_serviceurl'] = 'URL dịch vụ xử lý PDF';
$string['setting_serviceurl_desc'] = 'Địa chỉ gốc của dịch vụ FastAPI Process-PDF (ví dụ: http://127.0.0.1:8002).';
$string['setting_apikey'] = 'Gemini API key dùng chung của hệ thống';
$string['setting_apikey_desc'] = 'Khóa Gemini API lưu trên server để dịch vụ xử lý PDF sử dụng. Nếu đã điền, giảng viên và sinh viên không cần tự nhập key.';
$string['setting_allowuserapikey'] = 'Cho phép người dùng nhập API key riêng';
$string['setting_allowuserapikey_desc'] = 'Khi bật, người dùng có thể nhập Gemini API key riêng trên giao diện (ghi đè khóa hệ thống cho lượt xử lý đó).';
$string['setting_model'] = 'Model Gemini mặc định';
$string['setting_model_desc'] = 'Tên model chuẩn LiteLLM cho tóm tắt, quiz và phân tích hình ảnh (ví dụ: gemini/gemini-3.5-flash-lite hoặc gemini/gemini-2.5-flash).';
$string['setting_maxfilesize'] = 'Dung lượng PDF tối đa (MB)';
$string['setting_maxfilesize_desc'] = 'Dung lượng tối đa của mỗi file PDF tính bằng MB (đồng thời tuân thủ giới hạn PHP và khóa học).';
$string['setting_maxpages'] = 'Số trang PDF tối đa mỗi lượt xử lý';
$string['setting_maxpages_desc'] = 'Giới hạn số trang PDF được xử lý trong một lần gửi.';
$string['setting_timeout'] = 'Thời gian chờ tối đa (giây)';
$string['setting_timeout_desc'] = 'Số giây tối đa PHP chờ dịch vụ xử lý PDF trích xuất và sinh câu hỏi.';
$string['setting_enablevision'] = 'Bật Vision LLM phân tích sơ đồ / hình ảnh';
$string['setting_enablevision_desc'] = 'Tiền xử lý ảnh bằng OpenCV và gọi Vision LLM cho các trang hỗn hợp (MIXED) hoặc nhiều hình ảnh (VISUAL_HEAVY).';
$string['setting_enableocr'] = 'Bật Selective OCR (Tesseract)';
$string['setting_enableocr_desc'] = 'Tự động chạy Tesseract OCR cho các trang PDF scan không có lớp văn bản.';
$string['admincheck'] = 'Kiểm tra trạng thái dịch vụ PDF';

// Privacy API.
$string['privacy:metadata:items'] = 'Lưu văn bản trích xuất từ PDF, tóm tắt và quiz do người dùng tạo.';
$string['privacy:metadata:quizreq'] = 'Thông tin liên kết yêu cầu tạo Quiz từ PDF của người dùng với bài Trắc nghiệm trong khóa học. Không lưu file PDF gốc hay API key.';
$string['privacy:metadata:userid'] = 'ID chủ sở hữu.';
$string['privacy:metadata:title'] = 'Tiêu đề bài trắc nghiệm hoặc bản tóm tắt.';
$string['privacy:metadata:extractedtext'] = 'Văn bản trích xuất từ PDF.';
$string['privacy:metadata:content'] = 'Nội dung tóm tắt và câu hỏi trắc nghiệm (JSON).';
$string['privacy:metadata:visibility'] = 'Chế độ chia sẻ.';
$string['privacy:metadata:courseid'] = 'Khóa học đích.';
$string['privacy:metadata:cmid'] = 'ID hoạt động Trắc nghiệm đã tạo.';
$string['privacy:metadata:sourcehash'] = 'Mã băm nội dung để phát hiện PDF nguồn thay đổi.';
$string['privacy:metadata:timecreated'] = 'Thời điểm tạo.';
$string['privacy:metadata:pdfservice'] = 'File PDF được gửi tạm thời tới dịch vụ Process-PDF và Gemini API đã cấu hình để trích xuất nội dung và tạo câu hỏi.';
$string['privacy:metadata:pdfcontent'] = 'Nội dung file PDF gửi để phân tích và tạo câu hỏi.';

