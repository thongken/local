<?php
namespace local_microlearning\local;

defined('MOODLE_INTERNAL') || die();

/** Shared entry point for the study tools. */
class study_assistant {
    public const PATH = '/local/microlearning/';

    /** Whether the user can open the optional PDF quiz tool. */
    public static function can_use_pdfquiz(): bool {
        return get_config('local_pdfquiz', 'version')
            && has_capability('local/pdfquiz:use', \context_system::instance());
    }
}
