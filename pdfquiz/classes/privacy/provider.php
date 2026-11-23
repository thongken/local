<?php
namespace local_pdfquiz\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    private const TABLE = 'local_pdfquiz_items';
    private const REQTABLE = 'local_pdfquiz_quizreq';

    public static function get_metadata(collection $c): collection {
        $c->add_database_table(self::TABLE, [
            'userid' => 'privacy:metadata:userid',
            'title' => 'privacy:metadata:title',
            'extractedtext' => 'privacy:metadata:extractedtext',
            'content' => 'privacy:metadata:content',
            'visibility' => 'privacy:metadata:visibility',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:items');
        $c->add_database_table(self::REQTABLE, [
            'userid' => 'privacy:metadata:userid',
            'title' => 'privacy:metadata:title',
            'courseid' => 'privacy:metadata:courseid',
            'cmid' => 'privacy:metadata:cmid',
            'sourcehash' => 'privacy:metadata:sourcehash',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:quizreq');
        $c->add_external_location_link('pdfservice', [
            'pdfcontent' => 'privacy:metadata:pdfcontent',
        ], 'privacy:metadata:pdfservice');
        return $c;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $cl = new contextlist();
        $cl->add_from_sql(
            'SELECT ctx.id FROM {context} ctx WHERE ctx.contextlevel = :lvl
               AND (EXISTS (SELECT 1 FROM {' . self::TABLE . '} i WHERE i.userid = :userid)
                    OR EXISTS (SELECT 1 FROM {' . self::REQTABLE . '} r WHERE r.userid = :requestuserid))',
            ['lvl' => CONTEXT_SYSTEM, 'userid' => $userid, 'requestuserid' => $userid]
        );
        return $cl;
    }

    public static function get_users_in_context(userlist $userlist): void {
        if ($userlist->get_context() instanceof \context_system) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {' . self::TABLE . '}', []);
            $userlist->add_from_sql('userid', 'SELECT userid FROM {' . self::REQTABLE . '}', []);
        }
    }

    public static function export_user_data(approved_contextlist $cl): void {
        global $DB;
        $userid = $cl->get_user()->id;
        foreach ($cl->get_contexts() as $context) {
            if (!($context instanceof \context_system)) {
                continue;
            }
            $items = $DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated ASC');
            $out = [];
            foreach ($items as $i) {
                $out[] = (object)[
                    'title' => $i->title,
                    'extractedtext' => $i->extractedtext,
                    'content' => $i->content,
                    'pagecount' => $i->pagecount,
                    'visibility' => $i->visibility,
                    'timecreated' => \core_privacy\local\request\transform::datetime($i->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime($i->timemodified),
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_pdfquiz')],
                (object)[
                    'items' => $out,
                    'quizrequests' => array_values($DB->get_records(self::REQTABLE, ['userid' => $userid])),
                ]
            );
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context instanceof \context_system) {
            $DB->delete_records(self::TABLE);
            $DB->delete_records(self::REQTABLE);
        }
    }

    public static function delete_data_for_user(approved_contextlist $cl): void {
        global $DB;
        foreach ($cl->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                $DB->delete_records(self::TABLE, ['userid' => $cl->get_user()->id]);
                $DB->delete_records(self::REQTABLE, ['userid' => $cl->get_user()->id]);
            }
        }
    }

    public static function delete_data_for_users(approved_userlist $ul): void {
        global $DB;
        if (!($ul->get_context() instanceof \context_system)) {
            return;
        }
        $ids = $ul->get_userids();
        if (!$ids) {
            return;
        }
        [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $DB->delete_records_select(self::TABLE, "userid $in", $params);
        $DB->delete_records_select(self::REQTABLE, "userid $in", $params);
    }
}

