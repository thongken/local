<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

use local_pdfquiz\local\pdf_client;
use local_pdfquiz\local\pdf_source;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new invalid_parameter_exception('POST required.');
}
require_login();
require_sesskey();
$context = context_system::instance();
require_capability('local/pdfquiz:use', $context);

$count = optional_param('questioncount', 5, PARAM_INT);
if ($count < 1 || $count > 30) {
    throw new invalid_parameter_exception('Question count must be between 1 and 30.');
}
$fileid = optional_param('sourcefileid', 0, PARAM_INT);
$pagefrom = optional_param('pagefrom', 0, PARAM_INT);
$pageto = optional_param('pageto', 0, PARAM_INT);
$model = trim(optional_param('model', '', PARAM_TEXT));
$apikey = trim(optional_param('apikey', '', PARAM_RAW));

$tempfile = null;
try {
    if ($fileid > 0) {
        $stored = pdf_source::user_accessible_file($fileid);
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
        $maxbytes = min(pdf_source::max_bytes(), get_max_upload_file_size($CFG->maxbytes));
        if ($size > $maxbytes) {
            throw new moodle_exception('pdfsize', 'local_pdfquiz', '', (int)ceil($maxbytes / 1048576));
        }
        $filename = $upload['name'];
        $filepath = $upload['tmp_name'];
    }

    $ai = pdf_client::process_pdf($filepath, $filename, $count, [
        'api_key' => $apikey,
        'model' => $model,
        'page_from' => $pagefrom,
        'page_to' => $pageto,
    ]);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'filename' => $filename,
        'pagecount' => max(1, (int)($ai['page_count'] ?? 1)),
        'extractedtext' => (string)($ai['extracted_text'] ?? ''),
        'content' => $ai['content'] ?? null,
        'routing_summary' => $ai['routing_summary'] ?? null,
        'ocr_pages_count' => (int)($ai['ocr_pages_count'] ?? 0),
        'processing_time_seconds' => (float)($ai['processing_time_seconds'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if ($tempfile && file_exists($tempfile)) {
        @unlink($tempfile);
    }
}

