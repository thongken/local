<?php
namespace local_pdfquiz\local;

defined('MOODLE_INTERNAL') || die();

/** Metadata and ownership for one idempotent native-quiz creation request from PDF. */
class quiz_request {
    public const TABLE = 'local_pdfquiz_quizreq';

    public static function owned(int $id): \stdClass {
        global $DB, $USER;
        $request = $DB->get_record(self::TABLE, ['id' => $id, 'userid' => $USER->id], '*', MUST_EXIST);
        quiz_access::course($request->courseid);
        return $request;
    }

    public static function validate_settings(string $title, int $count, string $key): void {
        if (trim($title) === '' || \core_text::strlen($title) > 255 ||
                $count < 1 || $count > 30 || !preg_match('/^[a-f0-9]{32}$/D', $key)) {
            throw new \invalid_parameter_exception('Invalid quiz creation settings.');
        }
    }

    public static function result(\stdClass $request): array {
        global $DB;
        if (!$request->cmid || !$DB->record_exists('course_modules', ['id' => $request->cmid, 'course' => $request->courseid])) {
            throw new \moodle_exception('generatedquizmissing', 'local_pdfquiz');
        }
        return [
            'cmid' => (int)$request->cmid,
            'questioncount' => (int)$request->questioncount,
            'viewurl' => (new \moodle_url('/mod/quiz/view.php', ['id' => $request->cmid]))->out(false),
            'editurl' => (new \moodle_url('/mod/quiz/edit.php', ['cmid' => $request->cmid]))->out(false),
            'settingsurl' => (new \moodle_url('/course/modedit.php', ['update' => $request->cmid, 'return' => 1]))->out(false),
            'courseurl' => (new \moodle_url('/course/view.php', ['id' => $request->courseid]))->out(false),
        ];
    }
}

