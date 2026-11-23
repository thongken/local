<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

use local_pdfquiz\local\content_validator;
use local_pdfquiz\local\pdf_client;
use local_pdfquiz\local\quiz_access;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new invalid_parameter_exception('POST required.');
}
require_login();
require_sesskey();

$courseid = optional_param('courseid', 0, PARAM_INT);
if ($courseid > 0) {
    quiz_access::course($courseid);
} else {
    require_capability('local/pdfquiz:use', context_system::instance());
}

$feedback = trim(required_param('feedback', PARAM_TEXT));
if ($feedback === '' || \core_text::strlen($feedback) > 1000) {
    throw new invalid_parameter_exception('Feedback must be between 1 and 1000 characters.');
}
$extractedtext = required_param('extractedtext', PARAM_RAW);
$contentjson = required_param('content', PARAM_RAW);
$count = optional_param('questioncount', 5, PARAM_INT);
if ($count < 1 || $count > 30) {
    $count = 5;
}
$model = trim(optional_param('model', '', PARAM_TEXT));
$apikey = trim(optional_param('apikey', '', PARAM_RAW));

$currentcontent = content_validator::decode($contentjson);
if (!is_array($currentcontent)) {
    throw new invalid_parameter_exception('Invalid current content JSON.');
}

$refined = pdf_client::refine_pdf($extractedtext, $currentcontent, $feedback, $count, [
    'api_key' => $apikey,
    'model' => $model,
]);

$newcontent = $refined['content'] ?? null;
$errors = content_validator::validate_content(is_array($newcontent) ? $newcontent : null);
if ($errors) {
    throw new moodle_exception('serviceerror', 'local_pdfquiz', '',
        'Refined quiz failed validation: ' . implode(', ', array_slice($errors, 0, 5)));
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'content' => $newcontent,
    'processing_time_seconds' => (float)($refined['processing_time_seconds'] ?? 0),
], JSON_UNESCAPED_UNICODE);

