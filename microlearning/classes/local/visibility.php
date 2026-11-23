<?php
namespace local_microlearning\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Quy tac xem item. "Lop" = nhom (group) Moodle trong mot khoa hoc.
 */
class visibility {
    public const PRIVATE = 'private';
    public const GROUP = 'group';
    public const COURSE = 'course';
    public const PUBLIC = 'public';
    public const ALL = [self::PRIVATE, self::GROUP, self::COURSE, self::PUBLIC];

    public static function can_view(\stdClass $item, int $userid): bool {
        global $CFG;
        require_once($CFG->dirroot . '/lib/grouplib.php');
        if ((int)$item->userid === $userid) { return true; }
        if ($userid <= 0 || isguestuser($userid)) { return false; }
        switch ($item->visibility) {
            case self::PUBLIC:
                return true;
            case self::COURSE:
                $ctx = \context_course::instance((int)$item->courseid, IGNORE_MISSING);
                return $ctx && is_enrolled($ctx, $userid);
            case self::GROUP:
                $ctx = \context_course::instance((int)$item->courseid, IGNORE_MISSING);
                return $ctx && is_enrolled($ctx, $userid) && groups_is_member((int)$item->groupid, $userid);
        }
        return false;
    }

    /**
     * Kiem tra chu so huu co quyen dat che do nay khong
     * (phai la thanh vien khoa hoc / nhom do).
     */
    public static function can_set(string $vis, int $courseid, int $groupid, int $userid): bool {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/grouplib.php');
        if (!in_array($vis, self::ALL, true)) { return false; }
        if ($vis === self::PRIVATE || $vis === self::PUBLIC) { return true; }
        $ctx = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$ctx || !is_enrolled($ctx, $userid)) { return false; }
        if ($vis === self::COURSE) { return true; }
        $grp = $DB->get_record('groups', ['id' => $groupid, 'courseid' => $courseid], 'id');
        return $grp && groups_is_member($groupid, $userid);
    }
}