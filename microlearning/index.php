<?php

use local_microlearning\local\study_assistant;

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
$canusemicrolearning = has_capability('local/microlearning:use', $context);
$canusepdfquiz = study_assistant::can_use_pdfquiz();
if (!$canusemicrolearning && !$canusepdfquiz) {
    require_capability('local/microlearning:use', $context);
}

$title = get_string('studentnav', 'local_microlearning');
$PAGE->set_context($context);
$PAGE->set_url(study_assistant::PATH);
$PAGE->set_title($title);
$PAGE->set_heading($title);

$tools = [];
if ($canusemicrolearning) {
    $tools = [
        ['/local/microlearning/audio.php', 'audio', 'local_microlearning'],
        ['/local/microlearning/record.php', 'record', 'local_microlearning'],
    ];
}
if ($canusepdfquiz) {
    $tools[] = ['/local/pdfquiz/pdf.php', 'pdf', 'local_pdfquiz'];
}

echo $OUTPUT->header();
echo html_writer::start_div('row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mb-3');
foreach ($tools as [$page, $key, $component]) {
    echo html_writer::start_div('col');
    echo html_writer::start_div('card h-100');
    echo html_writer::start_div('card-body d-flex flex-column align-items-start');
    echo html_writer::tag('h2', get_string($key . '_title', $component), ['class' => 'card-title h4']);
    echo html_writer::tag('p', get_string($key . '_desc', $component), ['class' => 'card-text']);
    echo html_writer::link(new moodle_url($page),
        get_string('open', $component), ['class' => 'btn btn-primary stretched-link mt-auto']);
    echo html_writer::end_div() . html_writer::end_div() . html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::start_div('d-flex flex-wrap gap-2');
if ($canusemicrolearning) {
    echo html_writer::link(new moodle_url('/local/microlearning/history.php'),
        get_string('studyhistoryaudio', 'local_microlearning'), ['class' => 'btn btn-secondary']);
}
if ($canusepdfquiz) {
    echo html_writer::link(new moodle_url('/local/pdfquiz/history.php'),
        get_string('studyhistorypdf', 'local_microlearning'), ['class' => 'btn btn-secondary']);
}
echo html_writer::end_div();
echo $OUTPUT->footer();
