<?php
namespace local_microlearning\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_microlearning\local\content_validator;

class update_content extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Item id'),
            'title' => new external_value(PARAM_TEXT, 'Tieu de'),
            'content' => new external_value(PARAM_RAW, 'JSON summary + segments + quiz'),
            'revision' => new external_value(PARAM_INT, 'Revision hien co (chong ghi de)'),
        ]);
    }

    public static function execute(int $id, string $title, string $content, int $revision): array {
        global $DB, $USER;
        $p = self::validate_parameters(self::execute_parameters(), compact('id', 'title', 'content', 'revision'));
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/microlearning:use', $context);

        $item = $DB->get_record('local_microlearning_items', ['id' => $p['id']], '*', MUST_EXIST);
        if ((int)$item->userid !== (int)$USER->id) {
            throw new \required_capability_exception($context, 'local/microlearning:use', 'nopermissions', '');
        }
        if ((int)$item->revision !== $p['revision']) {
            throw new \moodle_exception('conflict', 'local_microlearning');
        }
        $title = trim($p['title']);
        $decoded = content_validator::decode($p['content']);
        $errors = content_validator::validate_content($decoded);
        if ($title === '' || mb_strlen($title) > 255) { $errors[] = 'title'; }
        if ($errors) {
            throw new \invalid_parameter_exception('Invalid data: ' . implode(', ', array_slice($errors, 0, 5)));
        }
        $item->title = $title;
        $item->content = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        $item->revision = $item->revision + 1;
        $item->timemodified = time();
        $DB->update_record('local_microlearning_items', $item);
        return ['revision' => (int)$item->revision];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['revision' => new external_value(PARAM_INT, 'Revision moi')]);
    }
}