<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

use local_microlearning\local\audio_source;
use local_microlearning\local\quiz_access;
use local_microlearning\local\quiz_request;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new invalid_parameter_exception('POST required.');
}
require_sesskey();
$courseid = required_param('courseid', PARAM_INT);
$section = required_param('section', PARAM_INT);
$beforemod = optional_param('beforemod', 0, PARAM_INT);
$kind = required_param('kind', PARAM_ALPHA);
$title = trim(required_param('title', PARAM_TEXT));
$count = required_param('questioncount', PARAM_INT);
$duration = required_param('durationsec', PARAM_INT);
$key = required_param('requestkey', PARAM_ALPHANUM);
$course = quiz_access::course($courseid);
quiz_access::destination($course, $section, $beforemod);
quiz_request::validate_settings($kind, $title, $count, $duration, $key);
$fileid = optional_param('sourcefileid', 0, PARAM_INT);
$lock = \core\lock\lock_config::get_lock_factory('local_microlearning')->get_lock('prepare_' . $key, 10);
if (!$lock) {
    throw new moodle_exception('quizbusy', 'local_microlearning');
}
try {
    $existing = $DB->get_record(quiz_request::TABLE, ['requestkey' => $key]);
    if ($existing) {
        if ((int)$existing->userid !== (int)$USER->id || (int)$existing->courseid !== $courseid ||
                (int)$existing->sectionnum !== $section || (int)$existing->beforemod !== $beforemod ||
                $existing->kind !== $kind || $existing->title !== $title || (int)$existing->questioncount !== $count ||
                (int)$existing->durationsec !== $duration || (int)$existing->sourcefileid !== $fileid) {
            throw new invalid_parameter_exception('This request belongs to another source or destination.');
        }
        $result = ['requestid' => (int)$existing->id];
        if ($existing->cmid) {
            $result['quiz'] = quiz_request::result($existing);
        }
    } else {
        if ($fileid) {
            if ($kind !== 'audio') {
                throw new invalid_parameter_exception('A recording cannot reference a course file.');
            }
            $file = audio_source::course_file($courseid, $fileid);
            $hash = $file->get_contenthash();
        } else {
            $upload = $_FILES['source'] ?? null;
            if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
                throw new moodle_exception('audiouploadfailed', 'local_microlearning');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            $size = filesize($upload['tmp_name']);
            audio_source::validate($upload['name'], $mime ?: '', $size);
            if ($kind === 'record' && strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'wav') {
                throw new invalid_parameter_exception('Recordings must use WAV audio.');
            }
            if ($size > get_max_upload_file_size($CFG->maxbytes, $course->maxbytes)) {
                throw new moodle_exception('audiosize', 'local_microlearning');
            }
            $hash = sha1_file($upload['tmp_name']);
        }
        $request = (object)[
            'requestkey' => $key, 'userid' => $USER->id, 'courseid' => $courseid,
            'sectionnum' => $section, 'beforemod' => $beforemod, 'kind' => $kind,
            'title' => $title, 'questioncount' => $count, 'durationsec' => $duration,
            'sourcefileid' => $fileid, 'sourcehash' => $hash, 'cmid' => 0,
            'timecreated' => time(), 'timemodified' => time(),
        ];
        $request->id = $DB->insert_record(quiz_request::TABLE, $request);
        $result = ['requestid' => (int)$request->id];
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($result);
} finally {
    $lock->release();
}
