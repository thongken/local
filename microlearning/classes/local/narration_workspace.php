<?php
namespace local_microlearning\local;

defined('MOODLE_INTERNAL') || die();

/** Authorisation, course navigation and short-lived draft for the narration workspace. */
class narration_workspace {
    public const CAPABILITY = 'local/microlearning:managenarration';
    public const NAVKEY = 'local_microlearning_narration';

    private const PAGES = [
        'import_content' => 'narrationimport',
        'narration' => 'narrationcreate',
        'voices' => 'narrationvoices',
        'ai_settings' => 'narrationai',
    ];

    public static function authorise(int $courseid): \context_course {
        if ($courseid === (int)SITEID) {
            throw new \moodle_exception('invalidcourseid');
        }
        require_login(get_course($courseid), false);
        $context = \context_course::instance($courseid);
        require_capability(self::CAPABILITY, $context);
        return $context;
    }

    public static function url(string $page, int $courseid): \moodle_url {
        return new \moodle_url('/local/microlearning/' . $page . '.php', ['courseid' => $courseid]);
    }

    public static function extend_navigation(\core\hook\navigation\secondary_extend $hook): void {
        global $PAGE;
        $courseid = (int)($PAGE->course->id ?? SITEID);
        if ($courseid === (int)SITEID || !isloggedin() || isguestuser() ||
                !has_capability(self::CAPABILITY, \context_course::instance($courseid))) {
            return;
        }
        $hook->get_secondaryview()->add(
            get_string('narrationworkspace', 'local_microlearning'),
            self::url('narration', $courseid),
            \navigation_node::TYPE_CUSTOM,
            null,
            self::NAVKEY
        );
    }

    public static function setup(string $page, \context_course $context): \moodle_url {
        global $PAGE;
        $url = self::url($page, $context->instanceid);
        $PAGE->set_context($context);
        $PAGE->set_url($url);
        $PAGE->set_pagelayout('incourse');
        $PAGE->set_secondary_active_tab(self::NAVKEY);
        $PAGE->set_title(get_string(self::PAGES[$page], 'local_microlearning'));
        $PAGE->set_heading(get_string('narrationworkspace', 'local_microlearning'));
        return $url;
    }

    public static function tabs(string $page, int $courseid): string {
        global $OUTPUT;
        $tabs = [];
        foreach (self::PAGES as $key => $label) {
            $tabs[] = new \core\output\tabobject($key, self::url($key, $courseid), get_string($label, 'local_microlearning'));
        }
        return $OUTPUT->render(new \core\output\tabtree($tabs, $page));
    }

    public static function draft(int $courseid): ?\stdClass {
        global $SESSION;
        $draft = $SESSION->microlearning_narrationdraft ?? null;
        return $draft && $draft->courseid === $courseid && $draft->expires > time() ? $draft : null;
    }

    public static function save_draft(int $courseid, array $data): void {
        global $SESSION;
        $SESSION->microlearning_narrationdraft = (object)($data + ['courseid' => $courseid, 'expires' => time() + 7200]);
    }

    public static function clear_draft(int $courseid): void {
        global $SESSION;
        if (self::draft($courseid)) {
            unset($SESSION->microlearning_narrationdraft);
        }
    }
}
