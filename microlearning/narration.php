<?php
use local_microlearning\form\narration_form;
use local_microlearning\local\narration_ai;
use local_microlearning\local\narration_service;
use local_microlearning\local\narration_workspace;
use local_microlearning\local\speech_client;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$context = narration_workspace::authorise($courseid);
$url = narration_workspace::setup('narration', $context);
$page = max(0, min(1000000, optional_param('page', 0, PARAM_INT)));
$error = '';
$action = data_submitted() ? optional_param('action', '', PARAM_ALPHA) : '';
if (in_array($action, ['retry', 'delete'], true)) {
    require_sesskey();
    $id = required_param('jobid', PARAM_INT);
    try {
        if ($action === 'retry') {
            narration_service::retry($courseid, $USER->id, $id);
            redirect($url, get_string('narrationqueued', 'local_microlearning'));
        }
        narration_service::delete($context, $USER->id, $id);
        redirect($url, get_string('narrationdeleted', 'local_microlearning'));
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

$draft = narration_workspace::draft($courseid);
$aienabled = narration_ai::settings($USER->id) !== null;
$form = new narration_form($url, [
    'voices' => narration_service::choices($courseid, $USER->id),
    'aienabled' => $aienabled,
]);
if ($draft) {
    $form->set_data(['title' => $draft->title, 'script' => $draft->script, 'voice' => $draft->voice ?? 'default']);
}
if ($data = $form->get_data()) {
    try {
        if (!empty($data->rewrite)) {
            if (!$aienabled || empty($data->consent)) {
                throw new moodle_exception('narrationconsentrequired', 'local_microlearning');
            }
            $script = narration_ai::rewrite($USER->id, $data->script);
            narration_workspace::save_draft($courseid, [
                'title' => $data->title, 'script' => $script, 'voice' => $data->voice, 'rewritten' => true,
            ]);
            redirect($url);
        }
        narration_service::enqueue($courseid, $USER->id, $data);
        narration_workspace::clear_draft($courseid);
        redirect($url, get_string('narrationqueued', 'local_microlearning'));
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

$recordingstrings = [];
foreach (['choosefile', 'format', 'size', 'decoding', 'duration', 'downloaddeclined', 'loading', 'transcribing',
        'nospeech', 'replace', 'cancelled', 'ready', 'failed', 'loaderror'] as $key) {
    $recordingstrings[$key] = get_string('narrationrecording' . $key, 'local_microlearning');
}
$PAGE->requires->css('/local/microlearning/dist/style.css');
$PAGE->requires->js_call_amd('local_microlearning/loader', 'init', [[
    'kind' => 'narrationaudio',
    'distUrl' => (new moodle_url('/local/microlearning/dist/'))->out(false),
    'rev' => filemtime(__DIR__ . '/dist/narrationaudio.js'),
    'formats' => speech_client::VOICE_FORMATS,
    'maxbytes' => speech_client::MAX_VOICE_UPLOAD_BYTES,
    'strings' => $recordingstrings,
]]);

echo $OUTPUT->header();
echo narration_workspace::tabs('narration', $courseid);
echo $OUTPUT->heading(get_string('narrationcreate', 'local_microlearning'), 2, 'mt-4');
echo html_writer::tag('p', get_string('narrationcreatehelp', 'local_microlearning'));
if (!speech_client::health()) {
    echo $OUTPUT->notification(get_string('speechunavailable', 'local_microlearning'), \core\output\notification::NOTIFY_WARNING);
}
if ($error !== '') {
    echo $OUTPUT->notification(s($error), \core\output\notification::NOTIFY_ERROR);
}
if (!empty($draft->rewritten)) {
    echo $OUTPUT->notification(get_string('narrationrewritten', 'local_microlearning'), \core\output\notification::NOTIFY_INFO);
} else if (!empty($draft->sourceparts)) {
    echo $OUTPUT->notification(get_string('narrationimported', 'local_microlearning', $draft),
        \core\output\notification::NOTIFY_INFO);
}
if (!$aienabled) {
    echo html_writer::tag('p', html_writer::link(narration_workspace::url('ai_settings', $courseid),
        get_string('narrationenableai', 'local_microlearning')));
}

echo html_writer::start_div('border rounded p-3 my-3', ['data-kind' => 'narrationaudio']);
echo html_writer::tag('label', get_string('narrationrecording', 'local_microlearning'),
    ['for' => 'ml-narration-recording', 'class' => 'form-label fw-semibold']);
echo html_writer::tag('p', get_string('narrationrecordinghelp', 'local_microlearning'),
    ['id' => 'ml-narration-recording-help', 'class' => 'small mb-2']);
echo html_writer::start_div('d-flex flex-wrap align-items-center gap-2');
echo html_writer::empty_tag('input', [
    'type' => 'file',
    'id' => 'ml-narration-recording',
    'accept' => implode(',', array_map(static fn($kind) => '.' . $kind, speech_client::VOICE_FORMATS)),
    'class' => 'form-control w-auto',
    'aria-describedby' => 'ml-narration-recording-help',
]);
echo html_writer::tag('button', get_string('narrationrecordingstart', 'local_microlearning'),
    ['type' => 'button', 'class' => 'btn btn-outline-primary', 'data-transcribe' => '1']);
echo html_writer::tag('button', get_string('cancel'),
    ['type' => 'button', 'class' => 'btn btn-outline-secondary', 'data-cancel' => '1', 'hidden' => true]);
echo html_writer::end_div();
echo html_writer::div('', 'small mt-2', ['role' => 'status']);
echo html_writer::tag('noscript', get_string('nojs', 'local_microlearning'));
echo html_writer::end_div();

$form->display();

echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-center gap-2 mt-5 mb-3');
echo $OUTPUT->heading(get_string('narrationjobs', 'local_microlearning'), 2, 'mb-0');
echo html_writer::link(new moodle_url($url, ['page' => $page]), get_string('narrationrefresh', 'local_microlearning'),
    ['class' => 'btn btn-outline-secondary']);
echo html_writer::end_div();

$jobs = narration_service::jobs($courseid, $USER->id, $page);
$statuses = ['queued' => 'secondary', 'running' => 'info', 'done' => 'success', 'failed' => 'danger'];
foreach ($jobs as $job) {
    echo html_writer::start_tag('article', ['class' => 'card mb-3']);
    echo html_writer::start_div('card-body');
    echo $OUTPUT->heading(s($job->title), 3, 'h5');
    echo html_writer::tag('span', get_string('narrationstatus' . $job->status, 'local_microlearning'),
        ['class' => 'badge text-bg-' . $statuses[$job->status]]);
    echo html_writer::tag('p', userdate($job->timemodified), ['class' => 'text-muted small mt-2']);
    if ($job->errorcode !== '') {
        echo html_writer::tag('p', get_string($job->errorcode, 'local_microlearning'), ['class' => 'text-danger']);
    }
    if ($job->status === 'done') {
        $audio = moodle_url::make_pluginfile_url($context->id, 'local_microlearning', narration_service::AUDIO_AREA,
            $job->id, '/', narration_service::AUDIO_FILENAME);
        echo html_writer::tag('audio', '', ['src' => $audio->out(false), 'preload' => 'none', 'controls' => true, 'class' => 'w-100']);
    }
    echo html_writer::start_div('d-flex flex-wrap gap-2 mt-2');
    if ($job->status === 'done') {
        echo html_writer::link($audio, get_string('narrationdownload', 'local_microlearning'),
            ['download' => narration_service::AUDIO_FILENAME, 'class' => 'btn btn-outline-primary']);
    } else if ($job->status === 'failed') {
        $retry = new moodle_url($url, ['action' => 'retry', 'jobid' => $job->id, 'sesskey' => sesskey()]);
        echo $OUTPUT->single_button($retry, get_string('narrationretry', 'local_microlearning'), 'post');
    }
    $delete = new moodle_url($url, ['action' => 'delete', 'jobid' => $job->id, 'sesskey' => sesskey()]);
    $button = new \core\output\single_button($delete, get_string('delete'), 'post');
    $button->add_confirm_action(get_string('narrationdeleteconfirm', 'local_microlearning'));
    echo $OUTPUT->render($button);
    echo html_writer::end_div() . html_writer::end_div() . html_writer::end_tag('article');
}
if (!$jobs && $page === 0) {
    echo html_writer::tag('p', get_string('narrationemptylist', 'local_microlearning'));
}
echo $OUTPUT->paging_bar(narration_service::count_jobs($courseid, $USER->id), $page, 15, $url);
echo $OUTPUT->footer();
