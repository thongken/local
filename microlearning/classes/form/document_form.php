<?php
namespace local_microlearning\form;

defined('MOODLE_INTERNAL') || die();

use local_microlearning\local\speech_client;
require_once($CFG->libdir . '/formslib.php');

/** Select a teaching document to extract into an editable script. */
class document_form extends \moodleform {
    protected function definition(): void {
        $form = $this->_form;
        $form->addElement('text', 'title', get_string('narrationtitle', 'local_microlearning'), ['maxlength' => 200]);
        $form->setType('title', PARAM_TEXT);
        $form->addElement('filepicker', 'document', get_string('narrationdocument', 'local_microlearning'), null, [
            'accepted_types' => ['.pptx', '.pdf', '.txt'],
            'maxbytes' => speech_client::MAX_DOCUMENT_BYTES,
        ]);
        $form->addRule('document', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('narrationextract', 'local_microlearning'));
    }
}
