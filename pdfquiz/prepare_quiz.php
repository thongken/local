<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

use local_pdfquiz\local\pdf_client;
use local_pdfquiz\local\pdf_source;
use local_pdfquiz\local\quiz_access;
use local_pdfquiz\local\quiz_request;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new invalid_parameter_exception('POST required.');
}
require_sesskey();

$courseid = required_param('courseid', PARAM_INT);
$section = required_param('section', PARAM_INT);
$beforemod = optional_param('beforemod', 0, PARAM_INT);
$title = trim(required_param('title', PARAM_TEXT));
$count = required_param('questioncount', PARAM_INT);
$key = required_param('requestkey', PARAM_ALPHANUM);
$fileid = optional_param('sourcefileid', 0, PARAM_INT);
$pagefrom = optional_param('pagefrom', 0, PARAM_INT);
$pageto = optional_param('pageto', 0, PARAM_INT);
$model = trim(optional_param('model', '', PARAM_TEXT));
$apikey = trim(optional_param('apikey', '', PARAM_RAW));

$course = quiz_access::course($courseid);
quiz_access::destination($course, $section, $beforemod);
quiz_request::validate_settings($title, $count, $key);

$lock = \core\lock\lock_config::get_lock_factory('local_pdfquiz')->get_lock('prepare_' . $key, 30);
if (!$lock) {
    throw new moodle_exception('quizbusy', 'local_pdfquiz');
}

$tempfile = null;
try {
    $existing = $DB->get_record(quiz_request::TABLE, ['requestkey' => $key]);
    if ($existing) {
        if ((int)$existing->userid !== (int)$USER->id || (int)$existing->courseid !== $courseid ||
                (int)$existing->sectionnum !== $section || (int)$existing->beforemod !== $beforemod ||
                $existing->title !== $title || (int)$existing->questioncount !== $count ||
                (int)$existing->sourcefileid !== $fileid) {
            throw new invalid_parameter_exception('This request belongs to another source or destination.');
        }
        if ($existing->cmid) {
            $result = [
                'requestid' => (int)$existing->id,
                'quiz' => quiz_request::result($existing),
            ];
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Resolve and validate the PDF file (either existing course resource or uploaded temp file).
    if ($fileid > 0) {
        $stored = pdf_source::course_file($courseid, $fileid);
        $hash = $stored->get_contenthash();
        $filename = $stored->get_filename();
        $tempdir = make_temp_directory('local_pdfquiz');
        $tempfile = tempnam($tempdir, 'pdf_');
        if ($tempfile === false || !$stored->copy_content_to($tempfile)) {
            throw new moodle_exception('invalidsource', 'local_pdfquiz');
        }
        $filepath = $tempfile;
    } else {
        $upload = $_FILES['source'] ?? null;
        if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
            throw new moodle_exception('pdfuploadfailed', 'local_pdfquiz');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $size = (int)filesize($upload['tmp_name']);
        pdf_source::validate($upload['name'], $mime ?: '', $size, $upload['tmp_name']);
        $maxbytes = min(pdf_source::max_bytes(), get_max_upload_file_size($CFG->maxbytes, $course->maxbytes));
        if ($size > $maxbytes) {
            throw new moodle_exception('pdfsize', 'local_pdfquiz', '', (int)ceil($maxbytes / 1048576));
        }
        $hash = sha1_file($upload['tmp_name']);
        $filename = $upload['name'];
        $filepath = $upload['tmp_name'];
    }

    if (!$existing) {
        $request = (object)[
            'requestkey' => $key,
            'userid' => $USER->id,
            'courseid' => $courseid,
            'sectionnum' => $section,
            'beforemod' => $beforemod,
            'title' => $title,
            'questioncount' => $count,
            'pagecount' => 0,
            'sourcefileid' => $fileid,
            'sourcehash' => $hash,
            'cmid' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $request->id = $DB->insert_record(quiz_request::TABLE, $request);
    } else {
        $request = $existing;
    }

    // Release prepare lock before the potentially long AI HTTP call.
    $lock->release();
    $lock = null;

    // Call Process-PDF Python service.
    $ai = pdf_client::process_pdf($filepath, $filename, $count, [
        'api_key' => $apikey,
        'model' => $model,
        'page_from' => $pagefrom,
        'page_to' => $pageto,
    ]);

    $pagecount = max(1, (int)($ai['page_count'] ?? 1));
    $DB->set_field(quiz_request::TABLE, 'pagecount', $pagecount, ['id' => $request->id]);

    $result = [
        'requestid' => (int)$request->id,
        'pagecount' => $pagecount,
        'extractedtext' => (string)($ai['extracted_text'] ?? ''),
        'content' => $ai['content'] ?? null,
        'routing_summary' => $ai['routing_summary'] ?? null,
        'ocr_pages_count' => (int)($ai['ocr_pages_count'] ?? 0),
        'processing_time_seconds' => (float)($ai['processing_time_seconds'] ?? 0),
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} finally {
    if ($tempfile && file_exists($tempfile)) {
        @unlink($tempfile);
    }
    if ($lock) {
        $lock->release();
    }
}

