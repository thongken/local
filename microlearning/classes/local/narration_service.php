<?php
namespace local_microlearning\local;

defined('MOODLE_INTERNAL') || die();

use local_microlearning\task\generate_narration;

/** Creates course-owned narration jobs and stores their private WAV files. */
class narration_service {
    public const JOBS = 'local_microlearning_narrjobs';
    public const VOICES = 'local_microlearning_voices';
    public const AUDIO_AREA = 'narration';
    public const AUDIO_FILENAME = 'narration.wav';
    public const SAMPLE_AREA = 'voice';
    public const SAMPLE_FILENAME = 'sample.wav';
    public const PRESETS = [
        'default' => '',
        'minhquan' => 'Minh Quân',
        'maianh' => 'Mai Anh',
        'adam' => 'Adam',
        'aihan' => 'Ái Hân',
    ];

    public static function voices(int $courseid, int $userid): array {
        global $DB;
        return $DB->get_records(self::VOICES, ['courseid' => $courseid, 'userid' => $userid], 'name, id');
    }

    public static function choices(int $courseid, int $userid): array {
        $choices = [];
        foreach (array_keys(self::PRESETS) as $preset) {
            $key = $preset === 'default' ? 'narrationdefaultvoice' : 'narrationvoice' . $preset;
            $choices[$preset] = get_string($key, 'local_microlearning');
        }
        foreach (self::voices($courseid, $userid) as $voice) {
            $choices['custom:' . $voice->id] = get_string('narrationcustomvoice', 'local_microlearning', $voice->name);
        }
        return $choices;
    }

    public static function jobs(int $courseid, int $userid, int $page): array {
        global $DB;
        return $DB->get_records(self::JOBS, ['courseid' => $courseid, 'userid' => $userid],
            'timecreated DESC, id DESC', 'id, voiceid, title, status, errorcode, timemodified', $page * 15, 15);
    }

    public static function count_jobs(int $courseid, int $userid): int {
        global $DB;
        return $DB->count_records(self::JOBS, ['courseid' => $courseid, 'userid' => $userid]);
    }

    public static function add_voice(
        \context_course $context,
        int $userid,
        string $name,
        \stored_file $recording,
        ?int $start = null,
    ): void {
        global $DB;
        $name = trim($name);
        if ($name === '' || \core_text::strlen($name) > 100) {
            throw new \moodle_exception('voicenameinvalid', 'local_microlearning');
        }
        $sample = speech_client::prepare_voice($recording, $start);
        $lock = self::workspace_lock($context->instanceid, $userid);
        try {
            $transaction = $DB->start_delegated_transaction();
            $id = $DB->insert_record(self::VOICES, [
                'courseid' => $context->instanceid, 'userid' => $userid, 'name' => $name, 'timecreated' => time(),
            ]);
            get_file_storage()->create_file_from_string(
                self::file_record($context->id, $userid, self::SAMPLE_AREA, $id, self::SAMPLE_FILENAME), $sample
            );
            $transaction->allow_commit();
        } catch (\Exception $error) {
            if (isset($transaction)) {
                $transaction->rollback($error);
            }
            throw $error;
        } finally {
            $lock->release();
        }
    }

    public static function delete_voice(\context_course $context, int $userid, int $id): void {
        global $DB;
        $courseid = $context->instanceid;
        $lock = self::workspace_lock($courseid, $userid);
        try {
            self::owned(self::VOICES, $courseid, $userid, $id);
            if ($DB->record_exists_select(self::JOBS, 'voiceid = :voiceid AND status IN (:queued, :running)',
                    ['voiceid' => $id, 'queued' => 'queued', 'running' => 'running'])) {
                throw new \moodle_exception('voicebusy', 'local_microlearning');
            }
            $transaction = $DB->start_delegated_transaction();
            get_file_storage()->delete_area_files($context->id, 'local_microlearning', self::SAMPLE_AREA, $id);
            $DB->delete_records(self::VOICES, ['id' => $id]);
            $transaction->allow_commit();
        } catch (\Exception $error) {
            if (isset($transaction)) {
                $transaction->rollback($error);
            }
            throw $error;
        } finally {
            $lock->release();
        }
    }

    public static function clean_script(string $script): string {
        $script = preg_replace('/^\h*---\h*$/mu', '', str_replace(["\r\n", "\r"], "\n", $script));
        return trim($script ?? '');
    }

    public static function enqueue(int $courseid, int $userid, \stdClass $data): int {
        global $DB;
        $title = trim($data->title);
        $script = self::clean_script($data->script);
        if ($title === '' || \core_text::strlen($title) > 200 || $script === '' ||
                \core_text::strlen($script) > speech_client::MAX_SCRIPT ||
                !preg_match('/\A[a-f0-9]{32}\z/D', $data->requestkey)) {
            throw new \moodle_exception('scriptlimit', 'local_microlearning');
        }
        $lock = self::workspace_lock($courseid, $userid);
        try {
            $owner = ['courseid' => $courseid, 'userid' => $userid, 'requestkey' => $data->requestkey];
            if ($id = $DB->get_field(self::JOBS, 'id', $owner)) {
                return (int)$id;
            }
            $voiceid = 0;
            $preset = '';
            if (preg_match('/\Acustom:([1-9][0-9]*)\z/D', $data->voice, $matches)) {
                $voiceid = (int)$matches[1];
                self::owned(self::VOICES, $courseid, $userid, $voiceid);
            } else if (array_key_exists($data->voice, self::PRESETS)) {
                $preset = self::PRESETS[$data->voice];
            } else {
                throw new \moodle_exception('voicenotfound', 'local_microlearning');
            }
            $transaction = $DB->start_delegated_transaction();
            $id = $DB->insert_record(self::JOBS, $owner + [
                'title' => $title, 'script' => $script, 'voiceid' => $voiceid, 'preset' => $preset,
                'status' => 'queued', 'errorcode' => '', 'timecreated' => time(), 'timemodified' => time(),
            ]);
            self::queue_task($id);
            $transaction->allow_commit();
            return (int)$id;
        } catch (\Exception $error) {
            if (isset($transaction)) {
                $transaction->rollback($error);
            }
            throw $error;
        } finally {
            $lock->release();
        }
    }

    public static function retry(int $courseid, int $userid, int $id): void {
        global $DB;
        $lock = self::job_lock($id);
        try {
            $job = self::owned(self::JOBS, $courseid, $userid, $id);
            if ($job->status !== 'failed') {
                return;
            }
            $workspace = self::workspace_lock($courseid, $userid);
            try {
                if ($job->voiceid) {
                    self::owned(self::VOICES, $courseid, $userid, $job->voiceid);
                }
                $transaction = $DB->start_delegated_transaction();
                self::set_status($id, 'queued');
                self::queue_task($id);
                $transaction->allow_commit();
            } catch (\Exception $error) {
                if (isset($transaction)) {
                    $transaction->rollback($error);
                }
                throw $error;
            } finally {
                $workspace->release();
            }
        } finally {
            $lock->release();
        }
    }

    public static function delete(\context_course $context, int $userid, int $id): void {
        global $DB;
        $lock = self::job_lock($id);
        try {
            self::owned(self::JOBS, $context->instanceid, $userid, $id);
            $transaction = $DB->start_delegated_transaction();
            get_file_storage()->delete_area_files($context->id, 'local_microlearning', self::AUDIO_AREA, $id);
            $DB->delete_records(self::JOBS, ['id' => $id]);
            $transaction->allow_commit();
        } catch (\Exception $error) {
            if (isset($transaction)) {
                $transaction->rollback($error);
            }
            throw $error;
        } finally {
            $lock->release();
        }
    }

    public static function process(int $id): void {
        global $DB;
        $lock = self::job_lock($id, 10);
        $wav = null;
        try {
            $job = $DB->get_record(self::JOBS, ['id' => $id]);
            if (!$job || !in_array($job->status, ['queued', 'running'], true)) {
                return;
            }
            $context = \context_course::instance($job->courseid, IGNORE_MISSING);
            $user = \core_user::get_user($job->userid);
            if (!$context || !$user || $user->deleted || $user->suspended ||
                    !has_capability(narration_workspace::CAPABILITY, $context, $job->userid)) {
                self::set_status($id, 'failed', 'narrationpermission');
                return;
            }
            self::set_status($id, 'running');
            $storage = get_file_storage();
            $sample = null;
            if ($job->voiceid) {
                $voice = self::owned(self::VOICES, $job->courseid, $job->userid, $job->voiceid, false);
                $file = $voice ? $storage->get_file($context->id, 'local_microlearning', self::SAMPLE_AREA,
                    $voice->id, '/', self::SAMPLE_FILENAME) : false;
                if (!$file) {
                    self::set_status($id, 'failed', 'voicenotfound');
                    return;
                }
                $sample = $file->get_content();
            }
            try {
                $wav = make_request_directory() . '/narration.wav';
                speech_client::synthesize($job->script, $job->preset, $sample, $wav);
            } catch (\moodle_exception $error) {
                self::set_status($id, 'failed', $error->errorcode);
                return;
            }
            // Privacy requests and course removal can delete the job during synthesis.
            $workspace = self::workspace_lock($job->courseid, $job->userid);
            try {
                if (!$DB->record_exists(self::JOBS, ['id' => $id]) ||
                        !$DB->record_exists('context', ['id' => $context->id])) {
                    return;
                }
                $transaction = $DB->start_delegated_transaction();
                $storage->delete_area_files($context->id, 'local_microlearning', self::AUDIO_AREA, $id);
                $storage->create_file_from_pathname(
                    self::file_record($context->id, $job->userid, self::AUDIO_AREA, $id, self::AUDIO_FILENAME), $wav
                );
                self::set_status($id, 'done');
                $transaction->allow_commit();
            } catch (\Exception $error) {
                if (isset($transaction)) {
                    $transaction->rollback($error);
                }
                throw $error;
            } finally {
                $workspace->release();
            }
        } finally {
            if ($wav !== null && is_file($wav)) {
                unlink($wav);
            }
            $lock->release();
        }
    }

    public static function purge_owner(int $courseid, int $contextid, int $userid): void {
        global $DB;
        $lock = self::workspace_lock($courseid, $userid);
        try {
            $storage = get_file_storage();
            $conditions = ['courseid' => $courseid, 'userid' => $userid];
            foreach ([self::JOBS => self::AUDIO_AREA, self::VOICES => self::SAMPLE_AREA] as $table => $area) {
                foreach ($DB->get_fieldset_select($table, 'id', 'courseid = :courseid AND userid = :userid', $conditions) as $id) {
                    $storage->delete_area_files($contextid, 'local_microlearning', $area, $id);
                }
                $DB->delete_records($table, $conditions);
            }
        } finally {
            $lock->release();
        }
    }

    public static function purge_course(int $courseid, int $contextid): void {
        global $DB;
        $users = [];
        foreach ([self::JOBS, self::VOICES] as $table) {
            $users = array_merge($users, $DB->get_fieldset_select($table, 'DISTINCT userid', 'courseid = ?', [$courseid]));
        }
        foreach (array_unique($users) as $userid) {
            self::purge_owner($courseid, $contextid, $userid);
        }
    }

    public static function course_deleted(\core\event\course_deleted $event): void {
        self::purge_course($event->courseid, $event->contextid);
    }

    private static function job_lock(int $id, int $wait = 0): \core\lock\lock {
        return self::lock('narration-job-' . $id, $wait);
    }

    private static function workspace_lock(int $courseid, int $userid): \core\lock\lock {
        return self::lock("narration-workspace-$courseid-$userid", 5);
    }

    private static function lock(string $key, int $wait): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('local_microlearning')->get_lock($key, $wait);
        if (!$lock) {
            throw new \moodle_exception('speechbusy', 'local_microlearning');
        }
        return $lock;
    }

    private static function owned(string $table, int $courseid, int $userid, int $id, bool $required = true): ?\stdClass {
        global $DB;
        $result = $DB->get_record($table, ['id' => $id, 'courseid' => $courseid, 'userid' => $userid]);
        if (!$result && $required) {
            throw new \moodle_exception($table === self::VOICES ? 'voicenotfound' : 'narrationnotfound', 'local_microlearning');
        }
        return $result ?: null;
    }

    private static function queue_task(int $id): void {
        $task = new generate_narration();
        $task->set_custom_data(['id' => $id]);
        if (!\core\task\manager::queue_adhoc_task($task)) {
            throw new \moodle_exception('narrationqueuefailed', 'local_microlearning');
        }
    }

    private static function set_status(int $id, string $status, string $errorcode = ''): void {
        global $DB;
        $DB->update_record(self::JOBS, ['id' => $id, 'status' => $status, 'errorcode' => $errorcode, 'timemodified' => time()]);
    }

    private static function file_record(int $contextid, int $userid, string $area, int $id, string $filename): array {
        return [
            'contextid' => $contextid, 'component' => 'local_microlearning', 'filearea' => $area,
            'itemid' => $id, 'filepath' => '/', 'filename' => $filename, 'userid' => $userid,
            'mimetype' => 'audio/wav',
        ];
    }
}
