<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Shared helpers for local_pdfquiz.
 */
function local_pdfquiz_format_bytes(int $bytes): string {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

