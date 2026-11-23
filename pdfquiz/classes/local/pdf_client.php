<?php
namespace local_pdfquiz\local;

defined('MOODLE_INTERNAL') || die();

/**
 * HTTP client communicating with the Process-PDF Python FastAPI service.
 */
class pdf_client {
    public static function base_url(): string {
        $url = trim((string)get_config('local_pdfquiz', 'serviceurl'));
        if ($url === '') {
            $url = 'http://127.0.0.1:8002';
        }
        return rtrim($url, '/');
    }

    public static function timeout(): int {
        $timeout = (int)get_config('local_pdfquiz', 'timeout');
        return $timeout >= 15 ? $timeout : 300;
    }

    public static function default_model(): string {
        $model = trim((string)get_config('local_pdfquiz', 'model'));
        return $model !== '' ? $model : 'gemini/gemini-3.5-flash-lite';
    }

    public static function max_pages(): int {
        $max = (int)get_config('local_pdfquiz', 'maxpages');
        return $max >= 1 ? $max : 100;
    }

    public static function has_system_key(): bool {
        return trim((string)get_config('local_pdfquiz', 'apikey')) !== '';
    }

    public static function allow_user_key(): bool {
        $val = get_config('local_pdfquiz', 'allowuserapikey');
        return $val === false ? true : (bool)$val;
    }

    /** Resolve the effective Gemini API key (user override if allowed, otherwise admin system key). */
    public static function resolve_api_key(string $userkey = ''): string {
        $userkey = trim($userkey);
        if ($userkey !== '' && self::allow_user_key()) {
            return $userkey;
        }
        $syskey = trim((string)get_config('local_pdfquiz', 'apikey'));
        if ($syskey !== '') {
            return $syskey;
        }
        return '';
    }

    /** Check health of the Python Process-PDF service. */
    public static function health(): array {
        if (!function_exists('curl_init')) {
            throw new \moodle_exception('serviceerror', 'local_pdfquiz', '', 'PHP cURL extension is not enabled.');
        }
        $ch = curl_init(self::base_url() . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \moodle_exception('serviceerror', 'local_pdfquiz', '', $err);
        }
        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new \moodle_exception('serviceerror', 'local_pdfquiz', '', 'HTTP ' . $status);
        }
        return $decoded;
    }

    /**
     * Send a PDF file (local path) to Process-PDF service and return extracted summary + quiz JSON.
     *
     * @param string $filepath Absolute path to PDF file on disk
     * @param string $filename Original filename (e.g. lecture.pdf)
     * @param int $numquestions Desired total number of questions (1-30)
     * @param array $options Optional keys: api_key, model, page_from, page_to
     * @return array Decoded JSON response from service
     */
    public static function process_pdf(string $filepath, string $filename, int $numquestions, array $options = []): array {
        if (!function_exists('curl_init')) {
            throw new \moodle_exception('serviceerror', 'local_pdfquiz', '', 'PHP cURL extension is not enabled.');
        }
        if (!is_readable($filepath)) {
            throw new \moodle_exception('invalidsource', 'local_pdfquiz');
        }

        $apikey = self::resolve_api_key((string)($options['api_key'] ?? ''));
        $model = trim((string)($options['model'] ?? ''));
        if ($model === '') {
            $model = self::default_model();
        }
        $pagefrom = max(0, (int)($options['page_from'] ?? 0));
        $pageto = max(0, (int)($options['page_to'] ?? 0));
        $enablevision = get_config('local_pdfquiz', 'enablevision');
        $enableocr = get_config('local_pdfquiz', 'enableocr');

        $postfields = [
            'file' => new \CURLFile($filepath, 'application/pdf', $filename),
            'num_questions' => (string)$numquestions,
            'model' => $model,
            'max_pages' => (string)self::max_pages(),
            'enable_vision' => ($enablevision === false || $enablevision) ? '1' : '0',
            'enable_ocr' => ($enableocr === false || $enableocr) ? '1' : '0',
        ];
        if ($apikey !== '') {
            $postfields['api_key'] = $apikey;
        }
        if ($pagefrom > 0) {
            $postfields['page_from'] = (string)$pagefrom;
        }
        if ($pageto > 0) {
            $postfields['page_to'] = (string)$pageto;
        }

        $ch = curl_init(self::base_url() . '/api/v1/process-pdf');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::timeout(),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \moodle_exception('serviceerror', 'local_pdfquiz', '',
                'Cannot connect to ' . self::base_url() . ' (' . $err . ')');
        }
        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $detail = is_array($decoded) ? ($decoded['detail'] ?? 'Invalid response') : 'Invalid response';
            if (!is_string($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
            }
            throw new \moodle_exception('serviceerror', 'local_pdfquiz', '',
                'HTTP ' . $status . ': ' . \core_text::substr($detail, 0, 350));
        }
        return $decoded;
    }

    /**
     * Refine an already-generated summary & quiz using natural-language user feedback.
     */
    public static function refine_pdf(
        string $extractedtext,
        array $content,
        string $feedback,
        int $numquestions,
        array $options = []
    ): array {
        if (!function_exists('curl_init')) {
            throw new \moodle_exception('serviceerror', 'local_pdfquiz', '', 'PHP cURL extension is not enabled.');
        }
        $apikey = self::resolve_api_key((string)($options['api_key'] ?? ''));
        $model = trim((string)($options['model'] ?? ''));
        if ($model === '') {
            $model = self::default_model();
        }
        $payload = [
            'extracted_text' => $extractedtext,
            'content' => $content,
            'feedback' => trim($feedback),
            'num_questions' => max(1, min(30, $numquestions)),
            'model' => $model,
            'api_key' => $apikey,
        ];
        $ch = curl_init(self::base_url() . '/api/v1/refine-pdf');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::timeout(),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \moodle_exception('serviceerror', 'local_pdfquiz', '',
                'Cannot connect to ' . self::base_url() . ' (' . $err . ')');
        }
        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $detail = is_array($decoded) ? ($decoded['detail'] ?? 'Invalid response') : 'Invalid response';
            if (!is_string($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
            }
            throw new \moodle_exception('serviceerror', 'local_pdfquiz', '',
                'HTTP ' . $status . ': ' . \core_text::substr($detail, 0, 350));
        }
        return $decoded;
    }
}

