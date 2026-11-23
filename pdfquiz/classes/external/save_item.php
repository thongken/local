<?php
namespace local_pdfquiz\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_pdfquiz\local\content_validator;

class save_item extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'title' => new external_value(PARAM_TEXT, 'Item title'),
            'extractedtext' => new external_value(PARAM_RAW, 'Extracted PDF text'),
            'content' => new external_value(PARAM_RAW, 'JSON summary + segments + quiz'),
            'pagecount' => new external_value(PARAM_INT, 'Processed page count'),
        ]);
    }

    public static function execute(string $title, string $extractedtext, string $content, int $pagecount): array {
        global $DB, $USER;
        $p = self::validate_parameters(
            self::execute_parameters(),
            compact('title', 'extractedtext', 'content', 'pagecount')
        );
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/pdfquiz:use', $context);

        $title = trim($p['title']);
        $errors = content_validator::validate_meta($title, $p['extractedtext'], $p['pagecount']);
        $decoded = content_validator::decode($p['content']);
        $errors = array_merge($errors, content_validator::validate_content($decoded));
        if ($errors) {
            throw new \invalid_parameter_exception('Invalid data: ' . implode(', ', array_slice($errors, 0, 5)));
        }

        $now = time();
        $id = $DB->insert_record('local_pdfquiz_items', (object)[
            'userid' => $USER->id,
            'title' => $title,
            'extractedtext' => $p['extractedtext'],
            'content' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
            'pagecount' => $p['pagecount'],
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
        return new external_single_structure(['id' => new external_value(PARAM_INT, 'Saved item ID')]);
    }
}

