<?php
/** Native Moodle integration checks; every test record is rolled back. Run with --run or --check. */
define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
if (!in_array('--run', $argv, true) && !in_array('--check', $argv, true)) {
    fwrite(STDERR, "Usage: php public/local/microlearning/tests/cli/smoke_quiz.php --check|--run\n");
    exit(1);
}
fwrite(STDERR, "Bootstrapping Moodle...\n");
require_once(__DIR__ . '/../../../../config.php');

use local_microlearning\local\audio_source;
use local_microlearning\local\quiz_creator;
use local_microlearning\local\quiz_request;

echo 'Plugin version: ' . get_config('local_microlearning', 'version') . "\n";
echo 'Request table: ' . ($DB->get_manager()->table_exists(quiz_request::TABLE) ? 'ready' : 'missing') . "\n";
if (in_array('--check', $argv, true)) {
    require($CFG->dirroot . '/version.php');
    echo 'Core DB/source versions: ' . $CFG->version . ' / ' . $version . "\n";
    exit(0);
}
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

function ml_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS: ' . $message . "\n";
}

$originaluser = $USER;
\core\session\manager::set_user(get_admin());
$transaction = $DB->start_delegated_transaction();
$failure = null;
try {
    $nonce = bin2hex(random_bytes(6));
    $course = create_course((object)['fullname' => 'Microlearning smoke ' . $nonce, 'shortname' => 'ml-smoke-' . $nonce,
        'category' => $DB->get_field('course_categories', 'id', [], IGNORE_MULTIPLE), 'format' => 'topics',
        'numsections' => 1, 'enablecompletion' => 0]);
    course_create_sections_if_missing($course, [0, 1]);
    $coursecontext = context_course::instance($course->id);
    $teacher = (object)['username' => 'mlteacher' . $nonce, 'firstname' => 'Smoke', 'lastname' => 'Teacher',
        'email' => 'mlteacher' . $nonce . '@example.invalid', 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id, 'password' => 'not cached'];
    $teacher->id = user_create_user($teacher, false, false);
    $student = clone $teacher; unset($student->id);
    $student->username = 'mlstudent' . $nonce; $student->email = $student->username . '@example.invalid';
    $student->id = user_create_user($student, false, false);
    $teacherrole = $DB->get_field('role', 'id', ['archetype' => 'editingteacher'], IGNORE_MULTIPLE);
    $studentrole = $DB->get_field('role', 'id', ['archetype' => 'student'], IGNORE_MULTIPLE);
    $manual = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
    if (!$instance) {
        $instanceid = $manual->add_default_instance($course);
        $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }
    $manual->enrol_user($instance, $teacher->id, $teacherrole);
    $manual->enrol_user($instance, $student->id, $studentrole);
    \core\session\manager::set_user($DB->get_record('user', ['id' => $teacher->id]));
    ml_assert(\local_microlearning\local\quiz_access::course($course->id)->id == $course->id, 'editing teacher can create a course quiz');
    $destinationid = $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 1], MUST_EXIST);
    ml_assert(\local_microlearning\local\quiz_access::section_number($course, $destinationid) === 1,
        'Moodle 5.3 sectionid resolves to the correct relative section number');
    [, , , , $resource] = prepare_new_moduleinfo_data($course, 'resource', 1);
    unset($resource->introeditor);
    foreach (['name' => 'PDF main with audio attachment', 'intro' => '', 'introformat' => FORMAT_HTML,
        'files' => 0, 'display' => 0, 'filterfiles' => 0, 'legacyfiles' => 0,
        'printintro' => 0, 'showsize' => 0, 'showtype' => 1, 'showdate' => 0] as $key => $value) $resource->$key = $value;
    $resource = add_moduleinfo($resource, $course);
    $resourcecontext = context_module::instance($resource->coursemodule);
    $fileinfo = ['contextid' => $resourcecontext->id, 'component' => 'mod_resource',
        'filearea' => 'content', 'itemid' => 0, 'filepath' => '/'];
    $pdf = get_file_storage()->create_file_from_string($fileinfo +
        ['filename' => 'slides.pdf', 'mimetype' => 'application/pdf', 'sortorder' => 1], "%PDF-1.4\nSmoke");
    $wav = 'RIFF' . pack('V', 38) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) .
        'data' . pack('V', 2) . "\0\0";
    $audio = get_file_storage()->create_file_from_string($fileinfo +
        ['filename' => 'lesson.wav', 'mimetype' => 'audio/wav', 'sortorder' => 0], $wav);
    rebuild_course_cache($course->id, true);
    $choices = audio_source::choices($course);
    ml_assert(in_array($audio->get_id(), array_column($choices, 'id')) &&
        !in_array($pdf->get_id(), array_column($choices, 'id')), 'audio is selectable even when PDF is the main file');
    try {
        audio_source::course_file(SITEID, $audio->get_id());
        throw new RuntimeException('Cross-course source was accepted');
    } catch (moodle_exception $expected) { echo "PASS: cross-course file source rejected\n"; }
    $request = (object)['requestkey' => bin2hex(random_bytes(16)), 'userid' => $teacher->id, 'courseid' => $course->id,
        'sectionnum' => 1, 'beforemod' => $resource->coursemodule, 'kind' => 'audio', 'title' => 'Generated smoke quiz',
        'questioncount' => 2, 'durationsec' => 60, 'sourcefileid' => $audio->get_id(), 'sourcehash' => $audio->get_contenthash(),
        'cmid' => 0, 'timecreated' => time(), 'timemodified' => time()];
    $request->id = $DB->insert_record(quiz_request::TABLE, $request);
    $question = ['question' => 'Which answer is correct? <script>plain text</script>', 'options' => ['A', 'B', 'C', 'D'],
        'correct_answer' => 'B', 'explanation' => 'B is the correct answer.'];
    $content = ['summary' => ['main_points' => ['A point'], 'short_summary' => 'A short summary'],
        'segments' => [['segment_summary' => 'A section', 'quiz' => [$question, $question]]]];
    try {
        quiz_creator::create($request->id, 'A transcript', '{}');
        throw new RuntimeException('Malformed content was accepted');
    } catch (invalid_parameter_exception $expected) {
        echo "PASS: malformed content rejected before creating a Quiz\n";
    }
    $function = \core_external\external_api::external_function_info('local_microlearning_create_quiz');
    ml_assert((bool)$function->allowed_from_ajax && class_exists($function->classname), 'native Quiz service is registered for Moodle AJAX');
    // CLI explicitly disables Moodle cookies, so exercise the registered function/return contract directly.
    $result = \local_microlearning\external\create_quiz::execute($request->id, 'A transcript', json_encode($content));
    $result = \core_external\external_api::clean_returnvalue(
        \local_microlearning\external\create_quiz::execute_returns(), $result);
    ml_assert(isset($result['cmid'], $result['settingsurl']), 'registered Moodle AJAX function returns the native Quiz links');
    $cm = get_coursemodule_from_id('quiz', $result['cmid'], $course->id, false, MUST_EXIST);
    $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
    $section = $DB->get_record('course_sections', ['id' => $cm->section], '*', MUST_EXIST);
    ml_assert($cm->visible == 0 && $section->section == 1, 'Quiz is hidden and in the requested section');
    $sequence = explode(',', $section->sequence);
    ml_assert(array_search($cm->id, $sequence) < array_search($resource->coursemodule, $sequence),
        'beforemod insertion position is preserved');
    ml_assert($DB->count_records('quiz_slots', ['quizid' => $quiz->id]) == 2 && $quiz->sumgrades == 2,
        'two questions, slots and total marks created through Moodle APIs');
    $again = quiz_creator::create($request->id, 'A transcript', json_encode($content));
    ml_assert($again['cmid'] === $result['cmid'] && $DB->count_records('quiz', ['course' => $course->id]) == 1,
        'repeating a creation request does not duplicate the Quiz');
    \core\session\manager::set_user($DB->get_record('user', ['id' => $student->id]));
    try {
        \local_microlearning\local\quiz_access::course($course->id);
        throw new RuntimeException('Student was allowed to create a Quiz');
    } catch (required_capability_exception $expected) { echo "PASS: course capability checks reject students\n"; }
    try {
        quiz_creator::create($request->id, 'A transcript', json_encode($content));
        throw new RuntimeException('A student was allowed to access teacher request');
    } catch (moodle_exception $expected) {
        echo "PASS: a student cannot create a Quiz or access the teacher request\n";
    }
    \core\session\manager::set_user($DB->get_record('user', ['id' => $teacher->id]));
    $context = context_module::instance($cm->id);
    $questionrows = $DB->get_records_sql('SELECT q.* FROM {question} q
        JOIN {question_versions} qv ON qv.questionid = q.id
        JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
        JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid WHERE qc.contextid = ?', [$context->id]);
    ml_assert(count($questionrows) === 2, 'question-bank entries and versions are present');
    foreach ($questionrows as $row) {
        ml_assert($row->questiontextformat == FORMAT_PLAIN, 'AI text is stored as plain text');
        $answers = $DB->get_records('question_answers', ['question' => $row->id]);
        ml_assert(count($answers) == 4 && array_sum(array_column($answers, 'fraction')) == 1, 'four options with exactly one correct answer');
    }
    $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $context);
    $quba->set_preferred_behaviour('deferredfeedback');
    foreach ($questionrows as $row) $quba->add_question(question_bank::load_question($row->id), 1);
    $quba->start_all_questions();
    foreach ($quba->get_slots() as $slot) $quba->process_action($slot, $quba->get_question($slot)->get_correct_response());
    $quba->finish_all_questions();
    question_engine::save_questions_usage_by_activity($quba);
    ml_assert($quba->get_total_mark() == 2, 'Moodle question engine automatically grades the generated questions');
    audio_source::validate('lesson.mp3', 'audio/mpeg', 100);
    try {
        audio_source::validate('fake.mp3', 'application/pdf', 100);
        throw new RuntimeException('Mismatched audio MIME was accepted');
    } catch (moodle_exception $expected) { echo "PASS: server rejects a fake MP3 containing PDF content\n"; }
} catch (Throwable $error) {
    $failure = $error;
}
try {
    $transaction->rollback(new RuntimeException('Smoke test rollback'));
} catch (RuntimeException $expected) {
    // All course, user, quiz, question and request rows are deliberately rolled back.
}
\core\session\manager::set_user($originaluser);
if ($failure) {
    fwrite(STDERR, get_class($failure) . ': ' . $failure->getMessage() . "\n" . $failure->getTraceAsString() . "\n");
    exit(1);
}
echo "All integration checks passed; all test records rolled back.\n";
