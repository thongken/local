<?php
namespace local_microlearning\local;

defined('MOODLE_INTERNAL') || die();

/** Add the student shortcut to the shared study assistant. */
class student_navigation {
    public const KEY = 'local_microlearning_study';
    public const CAPABILITY = 'local/microlearning:viewstudentnavigation';

    public static function extend(\core\hook\navigation\primary_extend $hook): void {
        global $PAGE;

        if (!isloggedin() || isguestuser()) {
            return;
        }
        // A dashboard has no course context. Look for the student's permission in any course,
        // rather than relying on a role name, a fixed role ID or the site-admin bypass.
        $canseelink = (has_capability('local/microlearning:use', \context_system::instance())
                && get_user_capability_course(self::CAPABILITY, null, false, '', '', 1))
            || (study_assistant::can_use_pdfquiz()
                && get_user_capability_course('local/pdfquiz:viewstudentnavigation', null, false, '', '', 1));
        if (!$canseelink) {
            return;
        }

        $primary = $hook->get_primaryview();
        if ($primary->get(self::KEY)) {
            return;
        }
        $keys = $primary->get_children_key_list();
        $dashboardindex = array_search('myhome', $keys, true);
        $beforekey = $dashboardindex === false ? null : ($keys[$dashboardindex + 1] ?? null);
        $url = new \moodle_url(study_assistant::PATH);
        $node = \navigation_node::create(get_string('studentnav', 'local_microlearning'), $url,
            \navigation_node::TYPE_CUSTOM, null, self::KEY);
        $primary->add_node($node, $beforekey);

        $pagepath = $PAGE->url->get_path();
        if (str_starts_with($pagepath, $url->get_path()) ||
                str_starts_with($pagepath, (new \moodle_url('/local/pdfquiz/'))->get_path())) {
            $node->make_active();
            $PAGE->set_primary_active_tab(self::KEY);
        }
    }
}
