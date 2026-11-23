<?php
require_once(__DIR__ . '/../../config.php');

use local_pdfquiz\local\pdf_client;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/pdfquiz/admin_check.php'));
$PAGE->set_title(get_string('admincheck', 'local_pdfquiz'));
$PAGE->set_heading(get_string('admincheck', 'local_pdfquiz'));

echo $OUTPUT->header();

$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_pdfquiz']);
echo html_writer::link($settingsurl, '← ' . get_string('pluginname', 'local_pdfquiz'), ['class' => 'btn btn-secondary mb-3']);

// 1. Database tables check.
$dbman = $DB->get_manager();
$itemstable = $dbman->table_exists('local_pdfquiz_items');
$reqtable = $dbman->table_exists('local_pdfquiz_quizreq');

echo html_writer::tag('h4', '1. Moodle Plugin & Database');
$table = new html_table();
$table->head = ['Check', 'Status'];
$table->data = [
    ['Plugin version', s((string)get_config('local_pdfquiz', 'version'))],
    ['Table local_pdfquiz_items', $itemstable ? 'OK' : 'MISSING'],
    ['Table local_pdfquiz_quizreq', $reqtable ? 'OK' : 'MISSING'],
    ['PHP Fileinfo extension', class_exists('finfo') ? 'OK' : 'MISSING'],
    ['PHP cURL extension', function_exists('curl_init') ? 'OK' : 'MISSING'],
    ['Service URL', s(pdf_client::base_url())],
    ['Default Gemini model', s(pdf_client::default_model())],
    ['System Gemini API key', pdf_client::has_system_key() ? 'Configured' : 'Not set (users provide key or .env)'],
];
echo html_writer::table($table);

// 2. Python Process-PDF service health check.
echo html_writer::tag('h4', '2. Process-PDF Python Service', ['class' => 'mt-4']);
try {
    $health = pdf_client::health();
    echo $OUTPUT->notification('Connected successfully to Process-PDF service at ' . s(pdf_client::base_url()), 'success');
    $htable = new html_table();
    $htable->head = ['Component', 'Value'];
    foreach ($health as $k => $v) {
        $htable->data[] = [s((string)$k), s(is_bool($v) ? ($v ? 'Yes' : 'No') : (is_scalar($v) ? (string)$v : json_encode($v)))];
    }
    echo html_writer::table($htable);
} catch (\Throwable $e) {
    echo $OUTPUT->notification($e->getMessage(), 'error');
}

echo $OUTPUT->footer();

