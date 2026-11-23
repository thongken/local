<?php
namespace local_microlearning\form;

defined('MOODLE_INTERNAL') || die();

use local_microlearning\local\narration_service;
use local_microlearning\local\speech_client;
require_once($CFG->libdir . '/formslib.php');

/** Review the script and choose how it will be narrated. */
class narration_form extends \moodleform {
    protected function definition(): void {
        $form = $this->_form;
        $form->addElement('hidden', 'requestkey');
        $form->setType('requestkey', PARAM_ALPHANUM);
        $form->setDefault('requestkey', bin2hex(random_bytes(16)));
        $form->addElement('text', 'title', get_string('narrationtitle', 'local_microlearning'), ['maxlength' => 200]);
        $form->setType('title', PARAM_TEXT);
        $form->addRule('title', null, 'required', null, 'client');
        $form->addElement('textarea', 'script', get_string('narrationscript', 'local_microlearning'), [
            'rows' => 13, 'cols' => 75, 'class' => 'w-100',
        ]);
        $form->setType('script', PARAM_RAW);
        $form->addRule('script', null, 'required', null, 'client');
        $form->addElement('select', 'voice', get_string('narrationvoice', 'local_microlearning'), $this->_customdata['voices']);
        if ($this->_customdata['aienabled']) {
            $form->addElement('advcheckbox', 'consent', get_string('narrationaiconsentlabel', 'local_microlearning'),
                get_string('narrationaiconsent', 'local_microlearning'));
        }
        $buttons = [$form->createElement('submit', 'submitbutton', get_string('narrationgenerate', 'local_microlearning'))];
        if ($this->_customdata['aienabled']) {
            $buttons[] = $form->createElement('submit', 'rewrite', get_string('narrationrewrite', 'local_microlearning'),
                ['class' => 'btn-secondary']);
        }
        $form->addGroup($buttons, 'buttonar', '', ' ', false);
        $form->closeHeaderBefore('buttonar');
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $script = narration_service::clean_script($data['script']);
        if ($script === '' || \core_text::strlen($script) > speech_client::MAX_SCRIPT) {
            $errors['script'] = get_string('scriptlimit', 'local_microlearning');
        }
        if (!empty($data['rewrite']) && empty($data['consent'])) {
            $errors['consent'] = get_string('narrationconsentrequired', 'local_microlearning');
        }
        return $errors;
    }
}
