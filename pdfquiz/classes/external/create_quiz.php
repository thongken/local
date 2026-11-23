<?php
namespace local_pdfquiz\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_pdfquiz\local\quiz_creator;
use local_pdfquiz\local\quiz_request;

class create_quiz extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'Prepared quiz creation request ID'),
            'extractedtext' => new external_value(PARAM_RAW, 'Extracted text from PDF'),
            'content' => new external_value(PARAM_RAW, 'Validated summary and questions JSON'),
            'pagecount' => new external_value(PARAM_INT, 'Processed PDF page count', VALUE_DEFAULT, 1),
        ]);
    }

    public static function execute(int $requestid, string $extractedtext, string $content, int $pagecount = 1): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            compact('requestid', 'extractedtext', 'content', 'pagecount')
        );
        $request = quiz_request::owned($params['requestid']);
        self::validate_context(\context_course::instance($request->courseid));
        return quiz_creator::create(
            $params['requestid'],
            $params['extractedtext'],
            $params['content'],
            $params['pagecount']
        );
    }

    public static function execute_returns(): external_single_structure {
        $fields = [
            'cmid' => new external_value(PARAM_INT, 'New Quiz course module ID'),
            'questioncount' => new external_value(PARAM_INT, 'Number of questions created'),
        ];
        foreach (['viewurl', 'editurl', 'settingsurl', 'courseurl'] as $key) {
            $fields[$key] = new external_value(PARAM_URL, $key);
        }
        return new external_single_structure($fields);
    }
}

