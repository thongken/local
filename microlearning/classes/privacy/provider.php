<?php
namespace local_microlearning\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_microlearning\local\narration_ai;
use local_microlearning\local\narration_service;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    private const TABLE = 'local_microlearning_items';

    public static function get_metadata(collection $c): collection {
        $c->add_database_table(self::TABLE, [
            'userid' => 'privacy:metadata:userid',
            'title' => 'privacy:metadata:title',
            'transcript' => 'privacy:metadata:transcript',
            'content' => 'privacy:metadata:content',
            'visibility' => 'privacy:metadata:visibility',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:table');
        $c->add_database_table('local_microlearning_quizreq', [
            'userid' => 'privacy:metadata:userid',
            'title' => 'privacy:metadata:title',
            'courseid' => 'privacy:metadata:courseid',
            'cmid' => 'privacy:metadata:cmid',
            'sourcehash' => 'privacy:metadata:sourcehash',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:quizreq');
        $c->add_external_location_link('gemini', [
            'transcript' => 'privacy:metadata:gemini',
            'script' => 'privacy:metadata:narrationscript',
        ], 'privacy:metadata:geminisummary');
        $c->add_database_table(narration_service::JOBS, [
            'courseid' => 'privacy:metadata:courseid',
            'userid' => 'privacy:metadata:userid',
            'title' => 'privacy:metadata:title',
            'script' => 'privacy:metadata:narrationscript',
            'status' => 'privacy:metadata:narrationstatus',
        ], 'privacy:metadata:narrationjobs');
        $c->add_database_table(narration_service::VOICES, [
            'courseid' => 'privacy:metadata:courseid',
            'userid' => 'privacy:metadata:userid',
            'name' => 'privacy:metadata:title',
        ], 'privacy:metadata:narrationvoices');
        $c->add_database_table(narration_ai::TABLE, [
            'userid' => 'privacy:metadata:userid',
            'secret' => 'privacy:metadata:narrationsecret',
            'model' => 'privacy:metadata:narrationmodel',
        ], 'privacy:metadata:narrationaikeys');
        $c->add_subsystem_link('core_files', [], 'privacy:metadata:narrationvoices');
        $c->add_external_location_link('speechservice', [
            'input' => 'privacy:metadata:speechinput',
        ], 'privacy:metadata:speechservice');
        return $c;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $cl = new contextlist();
        $cl->add_from_sql(
            'SELECT ctx.id FROM {context} ctx WHERE ctx.contextlevel = :lvl
               AND (EXISTS (SELECT 1 FROM {' . self::TABLE . '} i WHERE i.userid = :userid)
                    OR EXISTS (SELECT 1 FROM {local_microlearning_quizreq} r WHERE r.userid = :requestuserid)
                    OR EXISTS (SELECT 1 FROM {' . narration_ai::TABLE . '} a WHERE a.userid = :aiuserid))',
            ['lvl' => CONTEXT_SYSTEM, 'userid' => $userid, 'requestuserid' => $userid, 'aiuserid' => $userid]
        );
        foreach ([narration_service::JOBS, narration_service::VOICES] as $table) {
            $cl->add_from_sql(
                'SELECT ctx.id FROM {context} ctx
                  JOIN {' . $table . '} n ON n.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :lvl AND n.userid = :userid',
                ['lvl' => CONTEXT_COURSE, 'userid' => $userid]
            );
        }
        return $cl;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_system) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {' . self::TABLE . '}', []);
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_microlearning_quizreq}', []);
            $userlist->add_from_sql('userid', 'SELECT userid FROM {' . narration_ai::TABLE . '}', []);
        } else if ($context instanceof \context_course) {
            foreach ([narration_service::JOBS, narration_service::VOICES] as $table) {
                $userlist->add_from_sql('userid', 'SELECT userid FROM {' . $table . '} WHERE courseid = :courseid',
                    ['courseid' => $context->instanceid]);
            }
        }
    }

    public static function export_user_data(approved_contextlist $cl): void {
        global $DB;
        $userid = $cl->get_user()->id;
        foreach ($cl->get_contexts() as $context) {
            if ($context instanceof \context_course) {
                self::export_narrations($context, $userid);
            }
            if (!($context instanceof \context_system)) { continue; }
            $items = $DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated ASC');
            $out = [];
            foreach ($items as $i) {
                $out[] = (object)[
                    'kind' => $i->kind,
                    'title' => $i->title,
                    'transcript' => $i->transcript,
                    'content' => $i->content,
                    'visibility' => $i->visibility,
                    'timecreated' => \core_privacy\local\request\transform::datetime($i->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime($i->timemodified),
                ];
            }
            $writer = writer::with_context($context);
            $writer->export_data(
                [get_string('pluginname', 'local_microlearning')], (object)['items' => $out,
                    'quizrequests' => array_values($DB->get_records('local_microlearning_quizreq', ['userid' => $userid]))]);
            if ($settings = narration_ai::settings($userid)) {
                $writer->export_data([get_string('narrationai', 'local_microlearning')], (object)[
                    'model' => $settings->model,
                    'timemodified' => \core_privacy\local\request\transform::datetime($settings->timemodified),
                ]);
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context instanceof \context_system) {
            $DB->delete_records(self::TABLE);
            $DB->delete_records('local_microlearning_quizreq');
            $DB->delete_records(narration_ai::TABLE);
        } else if ($context instanceof \context_course) {
            narration_service::purge_course($context->instanceid, $context->id);
        }
    }

    public static function delete_data_for_user(approved_contextlist $cl): void {
        global $DB;
        $userid = $cl->get_user()->id;
        foreach ($cl->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                $DB->delete_records(self::TABLE, ['userid' => $userid]);
                $DB->delete_records('local_microlearning_quizreq', ['userid' => $userid]);
                $DB->delete_records(narration_ai::TABLE, ['userid' => $userid]);
            } else if ($context instanceof \context_course) {
                narration_service::purge_owner($context->instanceid, $context->id, $userid);
            }
        }
    }

    public static function delete_data_for_users(approved_userlist $ul): void {
        global $DB;
        $context = $ul->get_context();
        $ids = $ul->get_userids();
        if (!$ids) { return; }
        if ($context instanceof \context_system) {
            [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $DB->delete_records_select(self::TABLE, "userid $in", $params);
            $DB->delete_records_select('local_microlearning_quizreq', "userid $in", $params);
            $DB->delete_records_select(narration_ai::TABLE, "userid $in", $params);
        } else if ($context instanceof \context_course) {
            foreach ($ids as $userid) {
                narration_service::purge_owner($context->instanceid, $context->id, $userid);
            }
        }
    }

    /** Export private audio alongside its script or voice label. */
    private static function export_narrations(\context_course $context, int $userid): void {
        global $DB;
        $writer = writer::with_context($context);
        $owner = ['courseid' => $context->instanceid, 'userid' => $userid];
        foreach ($DB->get_records(narration_service::JOBS, $owner, 'id') as $job) {
            $path = [get_string('narrationjobs', 'local_microlearning'), (string)$job->id];
            $writer->export_data($path, (object)[
                'title' => $job->title,
                'script' => $job->script,
                'status' => $job->status,
                'timecreated' => \core_privacy\local\request\transform::datetime($job->timecreated),
            ]);
            $writer->export_area_files($path, 'local_microlearning', narration_service::AUDIO_AREA, $job->id);
        }
        foreach ($DB->get_records(narration_service::VOICES, $owner, 'id') as $voice) {
            $path = [get_string('narrationvoices', 'local_microlearning'), (string)$voice->id];
            $writer->export_data($path, (object)['name' => $voice->name,
                'timecreated' => \core_privacy\local\request\transform::datetime($voice->timecreated)]);
            $writer->export_area_files($path, 'local_microlearning', narration_service::SAMPLE_AREA, $voice->id);
        }
    }
}
