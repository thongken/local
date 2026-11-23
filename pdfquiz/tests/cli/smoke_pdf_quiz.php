<?php
/** Native Moodle integration smoke test for local_pdfquiz (all records rolled back). */
define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
if (!in_array('--run', $argv, true) && !in_array('--check', $argv, true)) {
    fwrite(STDERR, "Usage: php public/local/pdfquiz/tests/cli/smoke_pdf_quiz.php --check|--run\n");
    exit(1);
}
require_once(__DIR__ . '/../../../../config.php');

use local_pdfquiz\local\pdf_client;
use local_pdfquiz\local\pdf_source;
use local_pdfquiz\local\quiz_access;
use local_pdfquiz\local\quiz_creator;
use local_pdfquiz\local\quiz_request;

echo 'Plugin version: ' . get_config('local_pdfquiz', 'version') . "\n";
echo 'Items table: ' . ($DB->get_manager()->table_exists('local_pdfquiz_items') ? 'ready' : 'missing') . "\n";
echo 'QuizReq table: ' . ($DB->get_manager()->table_exists(quiz_request::TABLE) ? 'ready' : 'missing') . "\n";
echo 'Service URL: ' . pdf_client::base_url() . "\n";

if (in_array('--check', $argv, true)) {
    require($CFG->dirroot . '/version.php');
    echo 'Core DB/source versions: ' . $CFG->version . ' / ' . $version . "\n";
    exit(0);
}

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

function pq_assert(bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException('FAIL: ' . $msg);
    }
    echo 'PASS: ' . $msg . "\n";
}

$origuser = $USER;
\core\session\manager::set_user(get_admin());
$transaction = $DB->start_delegated_transaction();
$failure = null;
try {
    $nonce = bin2hex(random_bytes(6));
    $course = create_course((object)[
        'fullname' => 'PDF Quiz smoke ' . $nonce,
        'shortname' => 'pq-smoke-' . $nonce,
        'category' => $DB->get_field('course_categories', 'id', [], IGNORE_MULTIPLE),
        'format' => 'topics',
        'numsections' => 1,
        'enablecompletion' => 0,
    ]);
    course_create_sections_if_missing($course, [0, 1]);

    $teacher = (object)[
        'username' => 'pqteacher' . $nonce, 'firstname' => 'Smoke', 'lastname' => 'Teacher',
        'email' => 'pqteacher' . $nonce . '@example.invalid', 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id, 'password' => 'not cached',
    ];
    $teacher->id = user_create_user($teacher, false, false);
    $student = clone $teacher;
    unset($student->id);
    $student->username = 'pqstudent' . $nonce;
    $student->email = $student->username . '@example.invalid';
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
    pq_assert(quiz_access::course($course->id)->id == $course->id, 'editing teacher can create a PDF course quiz');
    $destsectionid = $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 1], MUST_EXIST);
    pq_assert(quiz_access::section_number($course, $destsectionid) === 1, 'Moodle 5.3 sectionid resolves correctly');

    // Create a mod_resource with a PDF file and a non-PDF file to test source filtering.
    [, , , , $resource] = prepare_new_moduleinfo_data($course, 'resource', 1);
    unset($resource->introeditor);
    foreach (['name' => 'PDF Lecture Slides', 'intro' => '', 'introformat' => FORMAT_HTML,
        'files' => 0, 'display' => 0, 'filterfiles' => 0, 'legacyfiles' => 0,
        'printintro' => 0, 'showsize' => 0, 'showtype' => 1, 'showdate' => 0] as $k => $v) {
        $resource->$k = $v;
    }
    $resource = add_moduleinfo($resource, $course);
    $resctx = context_module::instance($resource->coursemodule);
    $finfo = ['contextid' => $resctx->id, 'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0, 'filepath' => '/'];
    $pdf = get_file_storage()->create_file_from_string($finfo + ['filename' => 'chapter1.pdf', 'mimetype' => 'application/pdf', 'sortorder' => 1], "%PDF-1.4\nDemo");
    $mp3 = get_file_storage()->create_file_from_string($finfo + ['filename' => 'audio.mp3', 'mimetype' => 'audio/mpeg', 'sortorder' => 0], "ID3dummy");
    rebuild_course_cache($course->id, true);

    $choices = pdf_source::choices($course);
    pq_assert(in_array($pdf->get_id(), array_column($choices, 'id')) && !in_array($mp3->get_id(), array_column($choices, 'id')),
        'only PDF files are listed as course PDF choices (audio excluded)');

    try {
        pdf_source::validate('fake.mp3', 'audio/mpeg', 100);
        throw new RuntimeException('Non-PDF accepted');
    } catch (moodle_exception $expected) {
        echo "PASS: non-PDF source rejected\n";
    }

    try {
        pdf_source::course_file(SITEID, $pdf->get_id());
        throw new RuntimeException('Cross-course PDF accepted');
    } catch (moodle_exception $expected) {
        echo "PASS: cross-course PDF source rejected\n";
    }

    $request = (object)[
        'requestkey' => bin2hex(random_bytes(16)), 'userid' => $teacher->id, 'courseid' => $course->id,
        'sectionnum' => 1, 'beforemod' => $resource->coursemodule, 'title' => 'Generated PDF smoke quiz',
        'questioncount' => 2, 'pagecount' => 5, 'sourcefileid' => $pdf->get_id(),
        'sourcehash' => $pdf->get_contenthash(), 'cmid' => 0, 'timecreated' => time(), 'timemodified' => time(),
    ];
    $request->id = $DB->insert_record(quiz_request::TABLE, $request);

    $q = [
        'question' => 'What is PDF microlearning?',
        'options' => ['Short focused modules', 'A 500-page book', 'Audio only', 'No quizzes'],
        'correct_answer' => 'Short focused modules',
        'explanation' => 'Microlearning breaks PDF lectures into focused chunks with quick quizzes.',
    ];
    $content = [
        'summary' => ['short_summary' => 'Overview of PDF microlearning.', 'main_points' => ['Point 1', 'Point 2']],
        'segments' => [['page_start' => 1, 'page_end' => 5, 'segment_summary' => 'Pages 1-5 summary', 'quiz' => [$q, $q]]],
    ];

    $fn = \core_external\external_api::external_function_info('local_pdfquiz_create_quiz');
    pq_assert((bool)$fn->allowed_from_ajax && class_exists($fn->classname), 'local_pdfquiz_create_quiz registered for AJAX');

    $res = \local_pdfquiz\external\create_quiz::execute($request->id, 'Extracted PDF text sample', json_encode($content), 5);
    $res = \core_external\external_api::clean_returnvalue(\local_pdfquiz\external\create_quiz::execute_returns(), $res);
    pq_assert(isset($res['cmid'], $res['editurl'], $res['settingsurl']), 'create_quiz returns valid native Moodle Quiz links');

    $cm = get_coursemodule_from_id('quiz', $res['cmid'], $course->id, false, MUST_EXIST);
    $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
    $section = $DB->get_record('course_sections', ['id' => $cm->section], '*', MUST_EXIST);
    pq_assert($cm->visible == 0 && $section->section == 1, 'created Quiz is hidden and placed in requested section');
    pq_assert($DB->count_records('quiz_slots', ['quizid' => $quiz->id]) == 2 && $quiz->sumgrades == 2, '2 questions and grades created via Moodle API');

    $again = quiz_creator::create($request->id, 'Extracted PDF text sample', json_encode($content), 5);
    pq_assert($again['cmid'] === $res['cmid'] && $DB->count_records('quiz', ['course' => $course->id]) == 1, 'idempotent retry does not duplicate Quiz');

    // Test student permissions & personal history save.
    \core\session\manager::set_user($DB->get_record('user', ['id' => $student->id]));
    try {
        quiz_access::course($course->id);
        throw new RuntimeException('Student allowed to create course quiz');
    } catch (required_capability_exception $expected) {
        echo "PASS: student blocked from creating course quiz\n";
    }
    $saved = \local_pdfquiz\external\save_item::execute('Student PDF Study', 'Extracted text', json_encode($content), 5);
    pq_assert(!empty($saved['id']), 'student can save personal PDF quiz to history');
} catch (Throwable $err) {
    $failure = $err;
}
try {
    $transaction->rollback(new RuntimeException('Smoke rollback'));
} catch (RuntimeException $expected) {
    // Intentional rollback.
}
\core\session\manager::set_user($origuser);
if ($failure) {
    fwrite(STDERR, get_class($failure) . ': ' . $failure->getMessage() . "\n");
    exit(1);
}
echo "All local_pdfquiz integration checks passed; test data rolled back.\n";

