<?php
require_once(__DIR__ . '/../../config.php');

use local_microlearning\local\audio_source;
use local_microlearning\local\quiz_access;

$courseid = required_param('courseid', PARAM_INT);
$section = optional_param('section', 0, PARAM_INT);
$sectionid = optional_param('sectionid', 0, PARAM_INT);
$beforemod = optional_param('beforemod', 0, PARAM_INT);
$kind = optional_param('kind', 'audio', PARAM_ALPHA);
if (!in_array($kind, ['audio', 'record'], true)) {
    throw new invalid_parameter_exception('Invalid quiz source.');
}
$course = quiz_access::course($courseid);
$section = quiz_access::section_number($course, $sectionid, $section);
quiz_access::destination($course, $section, $beforemod);
$context = context_course::instance($courseid);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_url('/local/microlearning/quiz.php', compact('courseid', 'section', 'beforemod', 'kind'));
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('coursequiz', 'local_microlearning'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('coursequiz', 'local_microlearning'));
$PAGE->requires->css('/local/microlearning/dist/style.css');
$PAGE->requires->js_call_amd('local_microlearning/loader', 'init', [[
    'kind' => $kind,
    'distUrl' => (new moodle_url('/local/microlearning/dist/'))->out(false),
    'rev' => filemtime(__DIR__ . '/dist/app.js'),
    'maxSeconds' => 1800,
    'maxFileBytes' => min(audio_source::MAX_BYTES, get_max_upload_file_size($CFG->maxbytes, $course->maxbytes)),
    'courseid' => $courseid,
    'section' => $section,
    'beforemod' => $beforemod,
    'sources' => $kind === 'audio' ? audio_source::choices($course) : [],
    'prepareUrl' => (new moodle_url('/local/microlearning/prepare_quiz.php'))->out(false),
    'sourceUrl' => (new moodle_url('/local/microlearning/quiz_source.php'))->out(false),
    'sesskey' => sesskey(),
]]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($kind === 'audio' ? 'quizdocument' : 'quizrecord', 'local_microlearning'));
echo $OUTPUT->notification(get_string('coursequizhelp', 'local_microlearning'), 'info', false);
echo html_writer::div('', '', ['id' => 'ml-app', 'data-kind' => $kind]);
echo html_writer::tag('noscript', get_string('nojs', 'local_microlearning'));
echo html_writer::link(new moodle_url('/course/view.php', ['id' => $courseid]),
    get_string('backtocourse', 'local_microlearning'), ['class' => 'btn btn-link mt-3']);
echo $OUTPUT->footer();
