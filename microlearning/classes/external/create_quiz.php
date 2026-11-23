<?php
namespace local_microlearning\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_microlearning\local\quiz_creator;
use local_microlearning\local\quiz_request;

class create_quiz extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'Prepared quiz creation request'),
            'transcript' => new external_value(PARAM_RAW, 'Transcript recognised in the browser'),
            'content' => new external_value(PARAM_RAW, 'Validated summary and questions JSON'),
        ]);
    }

    public static function execute(int $requestid, string $transcript, string $content): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('requestid', 'transcript', 'content'));
        $request = quiz_request::owned($params['requestid']);
        self::validate_context(\context_course::instance($request->courseid));
        return quiz_creator::create($params['requestid'], $params['transcript'], $params['content']);
    }

    public static function execute_returns(): external_single_structure {
        $fields = [
            'cmid' => new external_value(PARAM_INT, 'New Quiz course module ID'),
            'questioncount' => new external_value(PARAM_INT, 'Number of questions'),
        ];
        foreach (['viewurl', 'editurl', 'settingsurl', 'courseurl'] as $key) {
            $fields[$key] = new external_value(PARAM_URL, $key);
        }
        return new external_single_structure($fields);
    }
}
