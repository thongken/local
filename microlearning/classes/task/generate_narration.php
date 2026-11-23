<?php
namespace local_microlearning\task;

defined('MOODLE_INTERNAL') || die();

/** Run one lecture narration job in the Moodle ad hoc task runner. */
class generate_narration extends \core\task\adhoc_task {
    public function execute(): void {
        \local_microlearning\local\narration_service::process((int)$this->get_custom_data()->id);
    }

    protected function get_default_concurrency_limit(): int {
        return 1;
    }
}
