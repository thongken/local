<?php
namespace local_microlearning\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Kiem tra du lieu server-side. Phai khop frontend/src/validator.js va config.js.
 */
class content_validator {
    public const MAX_SEGMENTS = 30;
    public const MAX_QUESTIONS = 30;
    public const MAX_TRANSCRIPT = 300000;
    public const MAX_CONTENT = 500000;
    public const MAX_DURATION = 1830; // 30'30"
    public const KINDS = ['audio', 'record'];

    private static function s($v): bool {
        return is_string($v) && trim($v) !== '';
    }

    /** @return string[] danh sach loi, rong = hop le */
    public static function validate_meta(string $kind, string $title, string $transcript, int $duration): array {
        $e = [];
        if (!in_array($kind, self::KINDS, true)) { $e[] = 'kind'; }
        if (!self::s($title) || mb_strlen($title) > 255) { $e[] = 'title'; }
        if (!self::s($transcript) || strlen($transcript) > self::MAX_TRANSCRIPT) { $e[] = 'transcript'; }
        if ($duration < 1 || $duration > self::MAX_DURATION) { $e[] = 'duration'; }
        return $e;
    }

    /** @return string[] */
    public static function validate_content(?array $c): array {
        $e = [];
        if (!is_array($c)) { return ['content']; }
        $sum = $c['summary'] ?? null;
        if (!is_array($sum)) {
            $e[] = 'summary';
        } else {
            $mp = $sum['main_points'] ?? null;
            if (!is_array($mp)) { $e[] = 'summary.main_points'; }
            else { foreach ($mp as $p) { if (!self::s($p)) { $e[] = 'summary.main_points'; break; } } }
            if (!self::s($sum['short_summary'] ?? null)) { $e[] = 'summary.short_summary'; }
        }
        $segs = $c['segments'] ?? null;
        if (!is_array($segs) || !$segs || count($segs) > self::MAX_SEGMENTS) { $e[] = 'segments'; return $e; }
        foreach ($segs as $i => $g) {
            if (!is_array($g) || !self::s($g['segment_summary'] ?? null)) { $e[] = "segments[$i].segment_summary"; continue; }
            $quiz = $g['quiz'] ?? null;
            if (!is_array($quiz) || !$quiz || count($quiz) > self::MAX_QUESTIONS) { $e[] = "segments[$i].quiz"; continue; }
            foreach ($quiz as $j => $q) {
                $p = "segments[$i].quiz[$j]";
                if (!is_array($q) || !self::s($q['question'] ?? null)) { $e[] = "$p.question"; continue; }
                $opts = $q['options'] ?? null;
                if (!is_array($opts) || count($opts) !== 4) { $e[] = "$p.options"; continue; }
                $ok = true; $seen = [];
                foreach ($opts as $o) {
                    if (!self::s($o)) { $ok = false; break; }
                    $seen[trim($o)] = true;
                }
                if (!$ok || count($seen) !== 4) { $e[] = "$p.options"; continue; }
                $ca = $q['correct_answer'] ?? null;
                if (!self::s($ca) || !isset($seen[trim($ca)])) { $e[] = "$p.correct_answer"; }
                if (!self::s($q['explanation'] ?? null)) { $e[] = "$p.explanation"; }
            }
        }
        return $e;
    }

    /** Giai ma JSON an toan; tra ve null neu loi hoac qua lon. */
    public static function decode(string $json): ?array {
        if (strlen($json) > self::MAX_CONTENT) { return null; }
        $d = json_decode($json, true);
        return is_array($d) ? $d : null;
    }
}
