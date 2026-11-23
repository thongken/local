<?php
use local_microlearning\form\voice_form;
use local_microlearning\local\narration_service;
use local_microlearning\local\narration_workspace;
use local_microlearning\local\speech_client;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$context = narration_workspace::authorise($courseid);
$url = narration_workspace::setup('voices', $context);
$error = '';
if (data_submitted() && optional_param('action', '', PARAM_ALPHA) === 'delete') {
    require_sesskey();
    try {
        narration_service::delete_voice($context, $USER->id, required_param('voiceid', PARAM_INT));
        redirect($url, get_string('voicedeleted', 'local_microlearning'));
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

$maxbytes = get_max_upload_file_size($CFG->maxbytes, $PAGE->course->maxbytes, speech_client::MAX_VOICE_UPLOAD_BYTES);
$form = new voice_form($url, ['maxbytes' => $maxbytes]);
if ($data = $form->get_data()) {
    try {
        $recording = $form->get_recording();
        if ($recording === null) {
            throw new moodle_exception('voiceformat', 'local_microlearning');
        }
        if ($recording->get_filesize() > $maxbytes) {
            throw new moodle_exception('voiceuploadlimit', 'local_microlearning');
        }
        $start = $data->start ?? '';
        narration_service::add_voice($context, $USER->id, $data->name, $recording, $start === '' ? null : (int)$start);
        $recording->delete();
        redirect($url, get_string('voicesaved', 'local_microlearning'));
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

echo $OUTPUT->header();
echo narration_workspace::tabs('voices', $courseid);
echo $OUTPUT->heading(get_string('narrationvoices', 'local_microlearning'), 2, 'mt-4');
echo html_writer::tag('p', get_string('narrationvoiceshelp', 'local_microlearning', display_size($maxbytes, 0)));
if ($error !== '') {
    echo $OUTPUT->notification(s($error), \core\output\notification::NOTIFY_ERROR);
}
$form->display();

$voices = narration_service::voices($courseid, $USER->id);
echo $OUTPUT->heading(get_string('voicemylist', 'local_microlearning'), 2, 'mt-5');
if (!$voices) {
    echo html_writer::tag('p', get_string('voiceemptylist', 'local_microlearning'));
}
foreach ($voices as $voice) {
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo $OUTPUT->heading(s($voice->name), 3, 'h5');
    $audio = moodle_url::make_pluginfile_url($context->id, 'local_microlearning', narration_service::SAMPLE_AREA,
        $voice->id, '/', narration_service::SAMPLE_FILENAME);
    echo html_writer::tag('audio', '', ['src' => $audio->out(false), 'preload' => 'none', 'controls' => true, 'class' => 'w-100']);
    $delete = new moodle_url($url, ['action' => 'delete', 'voiceid' => $voice->id, 'sesskey' => sesskey()]);
    $button = new \core\output\single_button($delete, get_string('delete'), 'post');
    $button->add_confirm_action(get_string('voicedeleteconfirm', 'local_microlearning'));
    echo $OUTPUT->render($button);
    echo html_writer::end_div() . html_writer::end_div();
}
echo $OUTPUT->footer();
