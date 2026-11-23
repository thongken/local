<?php
require_once(__DIR__ . '/../../config.php');

\local_microlearning\local\quiz_access::course(required_param('courseid', PARAM_INT));
require_sesskey();
$file = \local_microlearning\local\audio_source::course_file(required_param('courseid', PARAM_INT),
    required_param('fileid', PARAM_INT));
\core\session\manager::write_close();
send_stored_file($file, 0, 0, true, ['cacheability' => 'private']);
