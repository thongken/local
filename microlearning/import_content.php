<?php
use local_microlearning\form\document_form;
use local_microlearning\local\narration_workspace;
use local_microlearning\local\speech_client;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$context = narration_workspace::authorise($courseid);
$url = narration_workspace::setup('import_content', $context);
$form = new document_form($url);
$error = '';
if ($data = $form->get_data()) {
    try {
        $filename = (string)$form->get_new_filename('document');
        $kind = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $slides = speech_client::extract($kind, (string)$form->get_file_content('document'));
        $parts = [];
        foreach ($slides as $slide) {
            $part = trim($slide['notes']) ?: trim($slide['text']);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        if (!$parts) {
            throw new moodle_exception('documentempty', 'local_microlearning');
        }
        $title = trim($data->title ?? '') ?: clean_param(pathinfo($filename, PATHINFO_FILENAME), PARAM_TEXT);
        narration_workspace::save_draft($courseid, [
            'title' => core_text::substr($title ?: get_string('narrationuntitled', 'local_microlearning'), 0, 200),
            'script' => implode("\n\n---\n\n", $parts),
            'sourceparts' => count($slides),
            'emptyparts' => count($slides) - count($parts),
            'rewritten' => false,
        ]);
        redirect(narration_workspace::url('narration', $courseid));
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

echo $OUTPUT->header();
echo narration_workspace::tabs('import_content', $courseid);
echo $OUTPUT->heading(get_string('narrationimport', 'local_microlearning'), 2, 'mt-4');
echo html_writer::tag('p', get_string('narrationimporthelp', 'local_microlearning'));
if ($error !== '') {
    echo $OUTPUT->notification(s($error), \core\output\notification::NOTIFY_ERROR);
}
$form->display();
echo $OUTPUT->footer();
