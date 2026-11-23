<?php
namespace local_microlearning\local;

defined('MOODLE_INTERNAL') || die();

/** Validate audio sources. Uploaded audio is inspected in PHP's temporary area and is not retained. */
class audio_source {
    public const MAX_BYTES = 350 * 1024 * 1024;
    public const TYPES = [
        'mp3' => ['audio/mpeg', 'audio/mp3', 'audio/x-mp3'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'audio/m4a', 'video/mp4', 'application/mp4'],
        'ogg' => ['audio/ogg', 'application/ogg', 'audio/vorbis'],
    ];

    public static function validate(string $filename, string $mimetype, int $size): void {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimetype = strtolower(trim(explode(';', $mimetype)[0]));
        if (!isset(self::TYPES[$extension]) || !in_array($mimetype, self::TYPES[$extension], true)) {
            throw new \moodle_exception('unsupportedsource', 'local_microlearning');
        }
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new \moodle_exception('audiosize', 'local_microlearning');
        }
    }

    public static function course_file(int $courseid, int $fileid): \stored_file {
        $file = get_file_storage()->get_file_by_id($fileid);
        if (!$file || $file->is_directory() || $file->get_component() !== 'mod_resource' ||
                $file->get_filearea() !== 'content' || $file->is_external_file()) {
            throw new \moodle_exception('invalidsource', 'local_microlearning');
        }
        $context = \context::instance_by_id($file->get_contextid(), MUST_EXIST);
        if ($context->contextlevel !== CONTEXT_MODULE) {
            throw new \moodle_exception('invalidsource', 'local_microlearning');
        }
        $cm = get_coursemodule_from_id('resource', $context->instanceid, $courseid, false, MUST_EXIST);
        require_capability('mod/resource:view', $context);
        self::validate($file->get_filename(), $file->get_mimetype(), $file->get_filesize());
        return $file;
    }

    /** Include every supported attachment, not just the resource's main file. */
    public static function choices(\stdClass $course): array {
        $result = [];
        $modules = get_fast_modinfo($course)->get_instances_of('resource');
        foreach ($modules as $cm) {
            $context = \context_module::instance($cm->id);
            if (!has_capability('mod/resource:view', $context)) {
                continue;
            }
            $files = get_file_storage()->get_area_files($context->id, 'mod_resource', 'content', 0, 'filename', false);
            foreach ($files as $file) {
                try {
                    self::course_file($course->id, $file->get_id());
                    $result[] = ['id' => (int)$file->get_id(), 'filename' => $file->get_filename(),
                        'mimetype' => $file->get_mimetype(),
                        'label' => format_string($cm->name, true, ['context' => $context]) . ' / ' . $file->get_filename()];
                } catch (\moodle_exception $e) {
                    // PDF, video, external aliases and inaccessible files are not audio choices.
                }
            }
        }
        return $result;
    }
}
