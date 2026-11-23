<?php
namespace local_pdfquiz\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Validate PDF sources and list accessible PDF files in courses.
 * Uploaded PDFs are inspected in PHP's temporary area and sent directly to the PDF service without storing permanently.
 */
class pdf_source {
    public const ALLOWED_MIMES = [
        'application/pdf',
        'application/x-pdf',
    ];

    /** Configured maximum PDF file size in bytes (default 50 MB). */
    public static function max_bytes(): int {
        $mb = (int)get_config('local_pdfquiz', 'maxfilesize');
        if ($mb < 1) {
            $mb = 50;
        }
        return $mb * 1024 * 1024;
    }

    /** Configured maximum PDF size in MB for messages. */
    public static function max_mb(): int {
        return (int)ceil(self::max_bytes() / (1024 * 1024));
    }

    /** Validate filename extension, MIME type, file size, and optionally %PDF- magic header. */
    public static function validate(string $filename, string $mimetype, int $size, ?string $filepath = null): void {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = strtolower(trim(explode(';', $mimetype)[0]));
        if ($extension !== 'pdf' || !in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \moodle_exception('unsupportedsource', 'local_pdfquiz');
        }
        if ($size < 5 || $size > self::max_bytes()) {
            throw new \moodle_exception('pdfsize', 'local_pdfquiz', '', self::max_mb());
        }
        if ($filepath !== null) {
            $fh = @fopen($filepath, 'rb');
            $header = $fh ? fread($fh, 1024) : '';
            if ($fh) {
                fclose($fh);
            }
            if (!is_string($header) || strpos($header, '%PDF-') === false) {
                throw new \moodle_exception('unsupportedsource', 'local_pdfquiz');
            }
        }
    }

    /** Verify a stored PDF file belongs to a resource activity in the requested course and is viewable. */
    public static function course_file(int $courseid, int $fileid): \stored_file {
        $file = get_file_storage()->get_file_by_id($fileid);
        if (!$file || $file->is_directory() || $file->get_component() !== 'mod_resource' ||
                $file->get_filearea() !== 'content' || $file->is_external_file()) {
            throw new \moodle_exception('invalidsource', 'local_pdfquiz');
        }
        $context = \context::instance_by_id($file->get_contextid(), MUST_EXIST);
        if ($context->contextlevel !== CONTEXT_MODULE) {
            throw new \moodle_exception('invalidsource', 'local_pdfquiz');
        }
        get_coursemodule_from_id('resource', $context->instanceid, $courseid, false, MUST_EXIST);
        require_capability('mod/resource:view', $context);
        self::validate($file->get_filename(), $file->get_mimetype(), $file->get_filesize());
        return $file;
    }

    /** Verify a stored PDF file is readable by the current user across any course they are enrolled in. */
    public static function user_accessible_file(int $fileid): \stored_file {
        $file = get_file_storage()->get_file_by_id($fileid);
        if (!$file || $file->is_directory() || $file->get_component() !== 'mod_resource' ||
                $file->get_filearea() !== 'content' || $file->is_external_file()) {
            throw new \moodle_exception('invalidsource', 'local_pdfquiz');
        }
        $context = \context::instance_by_id($file->get_contextid(), MUST_EXIST);
        if ($context->contextlevel !== CONTEXT_MODULE) {
            throw new \moodle_exception('invalidsource', 'local_pdfquiz');
        }
        $coursecontext = $context->get_course_context(true);
        $course = get_course($coursecontext->instanceid);
        require_login($course, false);
        get_coursemodule_from_id('resource', $context->instanceid, $course->id, false, MUST_EXIST);
        require_capability('mod/resource:view', $context);
        self::validate($file->get_filename(), $file->get_mimetype(), $file->get_filesize());
        return $file;
    }

    /** List all valid PDF files inside mod_resource activities of a course. */
    public static function choices(\stdClass $course): array {
        $result = [];
        $modules = get_fast_modinfo($course)->get_instances_of('resource');
        foreach ($modules as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $context = \context_module::instance($cm->id);
            if (!has_capability('mod/resource:view', $context)) {
                continue;
            }
            $files = get_file_storage()->get_area_files($context->id, 'mod_resource', 'content', 0, 'filename', false);
            foreach ($files as $file) {
                try {
                    self::course_file($course->id, $file->get_id());
                    $result[] = [
                        'id' => (int)$file->get_id(),
                        'filename' => $file->get_filename(),
                        'filesize' => (int)$file->get_filesize(),
                        'label' => format_string($cm->name, true, ['context' => $context]) . ' / ' . $file->get_filename(),
                    ];
                } catch (\moodle_exception $e) {
                    // Non-PDF, oversized, external aliases or inaccessible files are skipped.
                }
            }
        }
        return $result;
    }

    /** List accessible PDF files across all courses the current user is enrolled in (for personal study tool). */
    public static function user_choices(): array {
        global $CFG;
        require_once($CFG->libdir . '/enrollib.php');
        $result = [];
        foreach (enrol_get_my_courses('id, fullname', 'fullname ASC', 30) as $course) {
            $coursename = format_string($course->fullname);
            foreach (self::choices($course) as $item) {
                $item['label'] = $coursename . ' — ' . $item['label'];
                $result[] = $item;
            }
        }
        return $result;
    }
}

