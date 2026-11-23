<?php
namespace local_microlearning\form;

defined('MOODLE_INTERNAL') || die();

use local_microlearning\local\speech_client;
require_once($CFG->libdir . '/formslib.php');

/** Upload a recording to prepare a private voice sample. */
class voice_form extends \moodleform {
    protected function definition(): void {
        $form = $this->_form;
        $form->addElement('text', 'name', get_string('voicename', 'local_microlearning'), ['maxlength' => 100]);
        $form->setType('name', PARAM_TEXT);
        $form->addRule('name', null, 'required', null, 'client');
        $form->addElement('filepicker', 'sample', get_string('voicesample', 'local_microlearning'), null, [
            'accepted_types' => array_map(static fn($kind) => '.' . $kind, speech_client::VOICE_FORMATS),
            'maxbytes' => $this->_customdata['maxbytes'],
        ]);
        $form->addRule('sample', null, 'required', null, 'client');
        $form->addElement('text', 'start', get_string('voicestart', 'local_microlearning'),
            ['inputmode' => 'numeric', 'maxlength' => 8]);
        $form->setType('start', PARAM_RAW_TRIMMED);
        $form->addHelpButton('start', 'voicestart', 'local_microlearning');
        $form->addElement('advcheckbox', 'consent', get_string('voiceconsentlabel', 'local_microlearning'),
            get_string('voiceconsent', 'local_microlearning'));
        $this->add_action_buttons(false, get_string('voicesave', 'local_microlearning'));
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $start = $data['start'] ?? '';
        if ($start !== '' && !preg_match('/\A[0-9]{1,8}\z/', $start)) {
            $errors['start'] = get_string('voicestartinvalid', 'local_microlearning');
        }
        if (empty($data['consent'])) {
            $errors['consent'] = get_string('narrationconsentrequired', 'local_microlearning');
        }
        return $errors;
    }

    public function get_recording(): ?\stored_file {
        $files = $this->get_draft_files('sample');
        return $files ? reset($files) : null;
    }
}
