<?php
namespace local_microlearning\form;

defined('MOODLE_INTERNAL') || die();

use local_microlearning\local\narration_ai;
require_once($CFG->libdir . '/formslib.php');

/** Personal key and model for the script editor. */
class narration_ai_form extends \moodleform {
    protected function definition(): void {
        $form = $this->_form;
        $form->addElement('passwordunmask', 'apikey', get_string('narrationapikey', 'local_microlearning'),
            ['autocomplete' => 'new-password']);
        $form->setType('apikey', PARAM_RAW_TRIMMED);
        $form->addElement('text', 'model', get_string('narrationmodel', 'local_microlearning'), ['maxlength' => 80]);
        $form->setType('model', PARAM_RAW_TRIMMED);
        $form->setDefault('model', narration_ai::DEFAULT_MODEL);
        $form->addRule('model', null, 'required', null, 'client');
        $this->add_action_buttons(false);
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!narration_ai::valid_model($data['model'])) {
            $errors['model'] = get_string('narrationmodelinvalid', 'local_microlearning');
        }
        $apikey = $data['apikey'] ?? '';
        if ($apikey === '' && !$this->_customdata['hasapikey']) {
            $errors['apikey'] = get_string('narrationaikeyrequired', 'local_microlearning');
        } else if ($apikey !== '' && !narration_ai::valid_apikey($apikey)) {
            $errors['apikey'] = get_string('narrationaikeyinvalid', 'local_microlearning');
        }
        return $errors;
    }
}
