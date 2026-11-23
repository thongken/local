<?php

use local_microlearning\local\study_assistant;
use local_pdfquiz\local\student_navigation;

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/pdfquiz:use', $context);

if (student_navigation::has_shared_home()) {
    redirect(new moodle_url(study_assistant::PATH));
}

$PAGE->set_context($context);
$PAGE->set_url('/local/pdfquiz/index.php');
$PAGE->set_title(get_string('studentnav', 'local_pdfquiz'));
$PAGE->set_heading(get_string('studentnav', 'local_pdfquiz'));

echo $OUTPUT->header();
echo html_writer::start_div('row');
echo html_writer::start_div('col-md-6 mb-3');
echo html_writer::start_div('card h-100');
echo html_writer::start_div('card-body');
echo html_writer::tag('h4', get_string('pdf_title', 'local_pdfquiz'), ['class' => 'card-title']);
echo html_writer::tag('p', get_string('pdf_desc', 'local_pdfquiz'), ['class' => 'card-text']);
echo html_writer::link(
    new moodle_url('/local/pdfquiz/pdf.php'),
    get_string('open', 'local_pdfquiz'),
    ['class' => 'btn btn-primary stretched-link']
);
echo html_writer::end_div() . html_writer::end_div() . html_writer::end_div();
echo html_writer::end_div();

echo html_writer::link(
    new moodle_url('/local/pdfquiz/history.php'),
    get_string('history', 'local_pdfquiz'),
    ['class' => 'btn btn-secondary']
);
echo $OUTPUT->footer();

