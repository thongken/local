<?php
require_once(__DIR__ . '/../../config.php');

use local_pdfquiz\local\pdf_client;
use local_pdfquiz\local\pdf_source;
use local_pdfquiz\local\quiz_access;

$courseid = required_param('courseid', PARAM_INT);
$section = optional_param('section', 0, PARAM_INT);
$sectionid = optional_param('sectionid', 0, PARAM_INT);
$beforemod = optional_param('beforemod', 0, PARAM_INT);

$course = quiz_access::course($courseid);
$section = quiz_access::section_number($course, $sectionid, $section);
quiz_access::destination($course, $section, $beforemod);

$context = context_course::instance($courseid);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_url('/local/pdfquiz/quiz.php', compact('courseid', 'section', 'beforemod'));
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('coursequiz', 'local_pdfquiz'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('coursequiz', 'local_pdfquiz'));

$maxbytes = min(pdf_source::max_bytes(), get_max_upload_file_size($CFG->maxbytes, $course->maxbytes));

$PAGE->requires->js_call_amd('local_pdfquiz/coursequiz', 'init', [[
    'courseid' => $courseid,
    'section' => $section,
    'beforemod' => $beforemod,
    'maxFileBytes' => $maxbytes,
    'maxPages' => pdf_client::max_pages(),
    'defaultModel' => pdf_client::default_model(),
    'hasSystemKey' => pdf_client::has_system_key(),
    'allowUserKey' => pdf_client::allow_user_key(),
    'sources' => pdf_source::choices($course),
    'prepareUrl' => (new moodle_url('/local/pdfquiz/prepare_quiz.php'))->out(false),
    'refineUrl' => (new moodle_url('/local/pdfquiz/refine_pdf.php'))->out(false),
    'sesskey' => sesskey(),
]]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('quizpdf', 'local_pdfquiz'));
echo $OUTPUT->notification(get_string('coursequizhelp', 'local_pdfquiz'), 'info', false);
echo html_writer::div('', 'pdfquiz-app', ['id' => 'pdfquiz-course-app']);
echo html_writer::tag('noscript', get_string('nojs', 'local_pdfquiz'));
echo html_writer::link(
    new moodle_url('/course/view.php', ['id' => $courseid]),
    get_string('backtocourse', 'local_pdfquiz'),
    ['class' => 'btn btn-link mt-3']
);
echo $OUTPUT->footer();

