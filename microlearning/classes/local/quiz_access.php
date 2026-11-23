<?php
namespace local_microlearning\local;

defined('MOODLE_INTERNAL') || die();

/** Course-scoped authorisation shared by the page, uploads and web services. */
class quiz_access {
    public static function course(int $courseid): \stdClass {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        require_login($course);
        $context = \context_course::instance($courseid);
        require_capability('moodle/course:manageactivities', $context);
        require_capability('mod/quiz:addinstance', $context);
        require_capability('moodle/question:add', $context);
        if ($courseid == SITEID || !isset(\core\plugininfo\mod::get_enabled_plugins()['quiz'])) {
            throw new \moodle_exception('quizunavailable', 'local_microlearning');
        }
        return $course;
    }

    /** Moodle 5.3 uses sectionid in the chooser; older links use a relative section number. */
    public static function section_number(\stdClass $course, int $sectionid, int $legacysection = 0): int {
        global $DB;
        if ($sectionid) {
            return (int)$DB->get_field('course_sections', 'section', ['id' => $sectionid, 'course' => $course->id], MUST_EXIST);
        }
        return $legacysection;
    }

    public static function destination(\stdClass $course, int $section, int $beforemod): void {
        global $DB;
        if ($section < 0 || !$DB->record_exists('course_sections', ['course' => $course->id, 'section' => $section])) {
            throw new \invalid_parameter_exception('Invalid destination section.');
        }
        if ($beforemod) {
            $cm = get_coursemodule_from_id('', $beforemod, $course->id, false, MUST_EXIST);
            $sectionid = $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => $section], MUST_EXIST);
            if ((int)$cm->section !== (int)$sectionid) {
                throw new \invalid_parameter_exception('The destination activity is in another section.');
            }
        }
    }
}
