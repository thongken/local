<?php
require_once(__DIR__ . '/../../config.php');

use local_pdfquiz\local\pdf_client;
use local_pdfquiz\local\pdf_source;

require_login();
$context = context_system::instance();
require_capability('local/pdfquiz:use', $context);

$title = get_string('pdf_title', 'local_pdfquiz');
$PAGE->set_context($context);
$PAGE->set_url('/local/pdfquiz/pdf.php');
$PAGE->set_title($title);
$PAGE->set_heading($title);

$maxbytes = min(pdf_source::max_bytes(), get_max_upload_file_size($CFG->maxbytes));
$sources = pdf_source::user_choices();

$PAGE->requires->js_call_amd('local_pdfquiz/personalpdf', 'init', [[
    'processUrl' => (new moodle_url('/local/pdfquiz/process_pdf.php'))->out(false),
    'refineUrl' => (new moodle_url('/local/pdfquiz/refine_pdf.php'))->out(false),
    'viewBaseUrl' => (new moodle_url('/local/pdfquiz/view.php'))->out(false),
    'historyUrl' => (new moodle_url('/local/pdfquiz/history.php'))->out(false),
    'sesskey' => sesskey(),
    'maxFileBytes' => $maxbytes,
    'maxPages' => pdf_client::max_pages(),
    'defaultModel' => pdf_client::default_model(),
    'hasSystemKey' => pdf_client::has_system_key(),
    'allowUserKey' => pdf_client::allow_user_key(),
    'sources' => $sources,
]]);

echo $OUTPUT->header();
echo html_writer::div('', 'pdfquiz-app', ['id' => 'pdfquiz-personal-app']);
echo html_writer::tag('noscript', get_string('nojs', 'local_pdfquiz'));
echo html_writer::div(
    html_writer::link(new moodle_url('/local/pdfquiz/index.php'), get_string('back', 'local_pdfquiz'), ['class' => 'btn btn-secondary me-2'])
    . html_writer::link(new moodle_url('/local/pdfquiz/history.php'), get_string('history', 'local_pdfquiz'), ['class' => 'btn btn-outline-secondary']),
    'mt-3'
);
echo $OUTPUT->footer();

