<?php
/** Exercise course permissions, the Moodle task, and the real local speech service. */
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');

use local_microlearning\local\narration_ai;
use local_microlearning\local\narration_service;
use local_microlearning\local\narration_workspace;
use local_microlearning\local\speech_client;
use local_microlearning\task\generate_narration;

if (!in_array('--run', $argv, true)) {
    fwrite(STDERR, "Usage: php public/local/microlearning/tests/cli/smoke_narration.php --run\n");
    exit(1);
}
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/local/microlearning/lib.php');

function narration_check(bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}

function narration_expect_error(callable $call, string $code): void {
    try {
        $call();
    } catch (moodle_exception $error) {
        narration_check($error->errorcode === $code, "Rejected with $code");
        return;
    }
    throw new RuntimeException("Expected $code");
}

$originaluser = $USER;
$transaction = $DB->start_delegated_transaction();
$failure = null;
try {
    narration_check(speech_client::health(), 'Moodle can connect to the configured speech service');
    $slides = speech_client::extract('txt', "Chào mừng các bạn đến với bài giảng.\n---\nHôm nay chúng ta sẽ học về dữ liệu.");
    narration_check(count($slides) === 2 && str_starts_with($slides[1]['text'], 'Hôm nay'), 'Extracted UTF-8 TXT sections');

    \core\session\manager::set_user(get_admin());
    $nonce = bin2hex(random_bytes(6));
    $course = create_course((object)[
        'fullname' => "Narration smoke $nonce", 'shortname' => "narration-smoke-$nonce",
        'category' => $DB->get_field('course_categories', 'id', [], IGNORE_MULTIPLE), 'format' => 'topics',
    ]);
    $context = context_course::instance($course->id);
    $profile = (object)[
        'username' => "narrteacher$nonce", 'firstname' => 'Smoke', 'lastname' => 'Teacher',
        'email' => "narrteacher$nonce@example.invalid", 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id, 'password' => 'not cached',
    ];
    $teacherid = user_create_user($profile, false, false);
    $profile->username = "narrstudent$nonce";
    $profile->lastname = 'Student';
    $profile->email = "narrstudent$nonce@example.invalid";
    $studentid = user_create_user($profile, false, false);
    $teacherrole = $DB->get_field('role', 'id', ['archetype' => 'editingteacher'], IGNORE_MULTIPLE);
    $studentrole = $DB->get_field('role', 'id', ['archetype' => 'student'], IGNORE_MULTIPLE);
    $manual = enrol_get_plugin('manual');
    foreach (enrol_get_instances($course->id, true) as $instance) {
        if ($instance->enrol === 'manual') {
            $manual->enrol_user($instance, $teacherid, $teacherrole);
            $manual->enrol_user($instance, $studentid, $studentrole);
            break;
        }
    }
    narration_check(
        has_capability(narration_workspace::CAPABILITY, $context, $teacherid) &&
            !has_capability(narration_workspace::CAPABILITY, $context, $studentid),
        'Editing teachers can narrate and students cannot'
    );

    \core\session\manager::set_user(get_complete_user_data('id', $teacherid));
    narration_workspace::authorise($course->id);
    $draft = (object)[
        'title' => 'Kiểm tra lời giảng', 'script' => implode("\n\n", array_column($slides, 'text')),
        'voice' => 'maianh', 'requestkey' => bin2hex(random_bytes(16)),
    ];
    $jobid = narration_service::enqueue($course->id, $teacherid, $draft);
    narration_check(narration_service::enqueue($course->id, $teacherid, $draft) === $jobid, 'Duplicate submit reuses the job');
    $taskrecord = $DB->get_record('task_adhoc', [
        'classname' => '\\' . generate_narration::class, 'customdata' => json_encode(['id' => $jobid]),
    ], '*', MUST_EXIST);
    $task = \core\task\manager::get_adhoc_task($taskrecord->id);
    narration_check($task instanceof generate_narration, 'Moodle registered the queued task');
    try {
        $task->execute();
    } finally {
        $task->release_concurrency_lock();
        $task->get_lock()->release();
    }
    $job = $DB->get_record(narration_service::JOBS, ['id' => $jobid], '*', MUST_EXIST);
    narration_check($job->status === 'done', 'Moodle task completed' . ($job->errorcode ? ' (' . $job->errorcode . ')' : ''));
    $file = get_file_storage()->get_file($context->id, 'local_microlearning', narration_service::AUDIO_AREA,
        $jobid, '/', narration_service::AUDIO_FILENAME);
    narration_check($file && $file->get_filesize() > 1000 && str_starts_with($file->get_content(), 'RIFF'),
        'Generated a real WAV in the Moodle course file area');

    \core\session\manager::set_user(get_admin());
    narration_check(local_microlearning_pluginfile($course, null, $context, narration_service::AUDIO_AREA,
        [(string)$jobid, narration_service::AUDIO_FILENAME], false) === false,
        'Another user with course permissions cannot download the WAV');
    narration_expect_error(
        fn() => narration_service::delete($context, $studentid, $jobid), 'narrationnotfound'
    );

    $standardkey = 'AIza' . str_repeat('a', 35);
    $authkey = 'AQ.' . str_repeat('b', 49);
    foreach ([$standardkey, $authkey] as $fakekey) {
        narration_ai::save($teacherid, $fakekey, narration_ai::DEFAULT_MODEL);
        $stored = $DB->get_field(narration_ai::TABLE, 'secret', ['userid' => $teacherid], MUST_EXIST);
        narration_check($stored !== $fakekey && \core\encryption::decrypt($stored) === $fakekey,
            'Moodle accepts and encrypts the ' . ($fakekey === $authkey ? 'authorization' : 'standard') . ' key format');
    }
    narration_expect_error(
        fn() => narration_ai::save($teacherid, $authkey . ' extra', narration_ai::DEFAULT_MODEL), 'narrationaikeyinvalid'
    );
    narration_ai::save($teacherid, '', narration_ai::DEFAULT_MODEL);
    $stored = $DB->get_field(narration_ai::TABLE, 'secret', ['userid' => $teacherid], MUST_EXIST);
    narration_check(\core\encryption::decrypt($stored) === $authkey &&
        !property_exists(narration_ai::settings($teacherid), 'secret'), 'A blank key keeps the saved secret and hides it from the UI');

    $contexts = \local_microlearning\privacy\provider::get_contexts_for_userid($teacherid)->get_contextids();
    narration_check(in_array($context->id, $contexts) && in_array(context_system::instance()->id, $contexts),
        'Privacy discovery includes the course and personal AI settings');
    narration_service::purge_owner($course->id, $context->id, $teacherid);
    narration_check(!$DB->record_exists(narration_service::JOBS, ['id' => $jobid]) &&
        !get_file_storage()->get_file($context->id, 'local_microlearning', narration_service::AUDIO_AREA,
            $jobid, '/', narration_service::AUDIO_FILENAME),
        'Course data removal purges both narration metadata and WAV');
} catch (Throwable $error) {
    $failure = $error;
}

try {
    $transaction->rollback(new RuntimeException('Roll back narration smoke records'));
} catch (RuntimeException) {
    // All test rows are removed.
}
\core\session\manager::set_user($originaluser);
if ($failure) {
    fwrite(STDERR, get_class($failure) . ': ' . $failure->getMessage() . "\n" . $failure->getTraceAsString() . "\n");
    exit(1);
}
echo "Narration smoke passed; test records rolled back.\n";
