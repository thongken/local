<?php
use local_microlearning\form\narration_ai_form;
use local_microlearning\local\narration_ai;
use local_microlearning\local\narration_workspace;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$context = narration_workspace::authorise($courseid);
$url = narration_workspace::setup('ai_settings', $context);
$error = '';
if (data_submitted() && optional_param('action', '', PARAM_ALPHA) === 'remove') {
    require_sesskey();
    $DB->delete_records(narration_ai::TABLE, ['userid' => $USER->id]);
    redirect($url, get_string('narrationaikeyremoved', 'local_microlearning'));
}
$settings = narration_ai::settings($USER->id);
$form = new narration_ai_form($url, ['hasapikey' => $settings !== null]);
if ($settings) {
    $form->set_data(['model' => $settings->model]);
}
if ($data = $form->get_data()) {
    try {
        narration_ai::save($USER->id, $data->apikey, $data->model);
        redirect($url, get_string('narrationaisaved', 'local_microlearning'));
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

echo $OUTPUT->header();
echo narration_workspace::tabs('ai_settings', $courseid);
echo $OUTPUT->heading(get_string('narrationai', 'local_microlearning'), 2, 'mt-4');
echo html_writer::tag('p', get_string('narrationaihelp', 'local_microlearning'));
if ($error !== '') {
    echo $OUTPUT->notification(s($error), \core\output\notification::NOTIFY_ERROR);
}
if ($settings) {
    echo $OUTPUT->notification(get_string('narrationaikeyset', 'local_microlearning'), \core\output\notification::NOTIFY_INFO);
}
$form->display();
if ($settings) {
    $remove = new moodle_url($url, ['action' => 'remove', 'sesskey' => sesskey()]);
    $button = new \core\output\single_button($remove, get_string('narrationaikeyremove', 'local_microlearning'), 'post');
    $button->add_confirm_action(get_string('narrationaikeyconfirm', 'local_microlearning'));
    echo $OUTPUT->render($button);
}
echo $OUTPUT->footer();
