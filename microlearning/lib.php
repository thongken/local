<?php
use local_microlearning\local\narration_service;
use local_microlearning\local\narration_workspace;

defined('MOODLE_INTERNAL') || die();

/**
 * Hien trang cong cu (audio hoac record). Phan xu ly nang chay o trinh duyet,
 * module AMD "loader" se nap bo JS trong thu muc dist/.
 */
function local_microlearning_render_tool(string $kind): void {
    global $PAGE, $OUTPUT;
    if (!in_array($kind, ['audio', 'record'], true)) {
        throw new coding_exception('Invalid kind');
    }
    require_login();
    $context = context_system::instance();
    require_capability('local/microlearning:use', $context);

    $title = get_string($kind . '_title', 'local_microlearning');
    $PAGE->set_context($context);
    $PAGE->set_url('/local/microlearning/' . $kind . '.php');
    $PAGE->set_title($title);
    $PAGE->set_heading($title);
    $PAGE->requires->css('/local/microlearning/dist/style.css');
    $appfile = __DIR__ . '/dist/app.js';
    $rev = file_exists($appfile) ? filemtime($appfile) : time();
    $PAGE->requires->js_call_amd('local_microlearning/loader', 'init', [[
        'kind' => $kind,
        'distUrl' => (new moodle_url('/local/microlearning/dist/'))->out(false),
        'maxSeconds' => 1800,
        'rev' => $rev,
    ]]);

    echo $OUTPUT->header();
    echo html_writer::div('', '', ['id' => 'ml-app', 'data-kind' => $kind]);
    echo html_writer::tag('noscript', get_string('nojs', 'local_microlearning'));
    echo $OUTPUT->footer();
}

/** Serve private narration audio and reference clips to their owners in the course. */
function local_microlearning_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    if (!$context instanceof context_course || (int)$course->id !== (int)$context->instanceid ||
            $course->id == SITEID || count($args) !== 2 || !ctype_digit($args[0]) ||
            !in_array($filearea, [narration_service::AUDIO_AREA, narration_service::SAMPLE_AREA], true)) {
        return false;
    }
    require_login($course, false);
    if (!has_capability(narration_workspace::CAPABILITY, $context)) {
        return false;
    }
    [$id, $filename] = $args;
    $conditions = ['id' => (int)$id, 'courseid' => $course->id, 'userid' => $USER->id];
    if ($filearea === narration_service::AUDIO_AREA) {
        $table = narration_service::JOBS;
        $expected = narration_service::AUDIO_FILENAME;
        $conditions['status'] = 'done';
    } else {
        $table = narration_service::VOICES;
        $expected = narration_service::SAMPLE_FILENAME;
    }
    if ($filename !== $expected || !$DB->record_exists($table, $conditions)) {
        return false;
    }
    $file = get_file_storage()->get_file($context->id, 'local_microlearning', $filearea, (int)$id, '/', $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}
