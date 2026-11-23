<?php
namespace local_pdfquiz\local;

defined('MOODLE_INTERNAL') || die();

/** Add the student "AI study assistant" shortcut to primary navigation right after Dashboard. */
class student_navigation {
    public const KEY = 'local_pdfquiz_study';
    public const CAPABILITY = 'local/pdfquiz:viewstudentnavigation';

    /** Whether Microlearning provides the shared study assistant on this site. */
    public static function has_shared_home(): bool {
        return get_config('local_microlearning', 'version')
            && class_exists(\local_microlearning\local\study_assistant::class);
    }

    public static function extend(\core\hook\navigation\primary_extend $hook): void {
        global $PAGE;

        if (self::has_shared_home() || !isloggedin() || isguestuser() ||
                !has_capability('local/pdfquiz:use', \context_system::instance())) {
            return;
        }
        // Check student capability in at least one course (without relying on role name/ID or site-admin bypass).
        if (!get_user_capability_course(self::CAPABILITY, null, false, '', '', 1)) {
            return;
        }

        $primary = $hook->get_primaryview();
        if ($primary->get(self::KEY)) {
            return;
        }
        $keys = $primary->get_children_key_list();
        $dashboardindex = array_search('myhome', $keys, true);
        $beforekey = $dashboardindex === false ? null : ($keys[$dashboardindex + 1] ?? null);
        $url = new \moodle_url('/local/pdfquiz/');
        $node = \navigation_node::create(
            get_string('studentnav', 'local_pdfquiz'),
            $url,
            \navigation_node::TYPE_CUSTOM,
            null,
            self::KEY
        );
        $primary->add_node($node, $beforekey);

        if (str_starts_with($PAGE->url->get_path(), $url->get_path())) {
            $node->make_active();
            $PAGE->set_primary_active_tab(self::KEY);
        }
    }
}

