<?php
namespace local_microlearning\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_microlearning\local\content_validator;

class save_item extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'kind' => new external_value(PARAM_ALPHA, 'audio | record'),
            'title' => new external_value(PARAM_TEXT, 'Tieu de'),
            'transcript' => new external_value(PARAM_RAW, 'Transcript day du'),
            'content' => new external_value(PARAM_RAW, 'JSON summary + segments + quiz'),
            'durationsec' => new external_value(PARAM_INT, 'Do dai (giay)'),
        ]);
    }

    public static function execute(string $kind, string $title, string $transcript, string $content, int $durationsec): array {
        global $DB, $USER;
        $p = self::validate_parameters(self::execute_parameters(), compact('kind', 'title', 'transcript', 'content', 'durationsec'));
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/microlearning:use', $context);

        $title = trim($p['title']);
        $errors = content_validator::validate_meta($p['kind'], $title, $p['transcript'], $p['durationsec']);
        $decoded = content_validator::decode($p['content']);
        $errors = array_merge($errors, content_validator::validate_content($decoded));
        if ($errors) {
            throw new \invalid_parameter_exception('Invalid data: ' . implode(', ', array_slice($errors, 0, 5)));
        }

        $now = time();
        $id = $DB->insert_record('local_microlearning_items', (object)[
            'userid' => $USER->id,
            'kind' => $p['kind'],
            'title' => $title,
            'transcript' => $p['transcript'],
            'content' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
            'durationsec' => $p['durationsec'],
            'visibility' => 'private',
            'courseid' => 0,
            'groupid' => 0,
            'revision' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return ['id' => (int)$id];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['id' => new external_value(PARAM_INT, 'Item id')]);
    }
}