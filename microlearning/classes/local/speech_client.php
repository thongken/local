<?php
namespace local_microlearning\local;

defined('MOODLE_INTERNAL') || die();

/** Client for the site-configured narration service. */
class speech_client {
    public const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;
    public const MAX_VOICE_UPLOAD_BYTES = 500 * 1024 * 1024;
    public const VOICE_FORMATS = ['wav', 'mp3', 'm4a', 'ogg', 'aac', 'flac'];
    public const MAX_SCRIPT = 80000;
    public const DEFAULT_URL = 'http://127.0.0.1:8010';

    private const MAX_WAV_BYTES = 256 * 1024 * 1024;
    private const SERVICE_ERRORS = [
        'speechauth', 'speechbusy', 'speechgenerationfailed', 'documentformat', 'documentempty', 'documentlimit',
        'voiceformat', 'voicenotfound', 'voiceuploadlimit', 'voicetooshort', 'voicequiet', 'voicepreparetimeout',
        'voicepreparefailed', 'scriptlimit', 'narrationwavlimit',
    ];

    public static function health(): bool {
        try {
            $result = json_decode(self::request('/v1/health', null, 3), true);
            return ($result['status'] ?? '') === 'ok';
        } catch (\moodle_exception) {
            return false;
        }
    }

    public static function extract(string $kind, string $content): array {
        if (!in_array($kind, ['pptx', 'pdf', 'txt'], true) || $content === '') {
            throw new \moodle_exception('documentformat', 'local_microlearning');
        }
        if (strlen($content) > self::MAX_DOCUMENT_BYTES) {
            throw new \moodle_exception('documentlimit', 'local_microlearning');
        }
        $reply = json_decode(self::request('/v1/documents/extract', [
            'kind' => $kind,
            'content' => base64_encode($content),
        ], 60), true);
        $slides = $reply['slides'] ?? null;
        if (!is_array($slides) || !array_is_list($slides) || !$slides || count($slides) > 120) {
            throw new \moodle_exception('speechunavailable', 'local_microlearning');
        }
        $length = 0;
        foreach ($slides as $slide) {
            if (!is_string($slide['title'] ?? null) || !is_string($slide['text'] ?? null)
                    || !is_string($slide['notes'] ?? null)) {
                throw new \moodle_exception('speechunavailable', 'local_microlearning');
            }
            $length += \core_text::strlen($slide['text']) + \core_text::strlen($slide['notes']);
        }
        if ($length > 60000) {
            throw new \moodle_exception('documentlimit', 'local_microlearning');
        }
        return $slides;
    }

    public static function prepare_voice(\stored_file $recording, ?int $start): string {
        $kind = strtolower(pathinfo($recording->get_filename(), PATHINFO_EXTENSION));
        if (!in_array($kind, self::VOICE_FORMATS, true) || !$recording->get_filesize()) {
            throw new \moodle_exception('voiceformat', 'local_microlearning');
        }
        if ($recording->get_filesize() > self::MAX_VOICE_UPLOAD_BYTES) {
            throw new \moodle_exception('voiceuploadlimit', 'local_microlearning');
        }
        $path = '/v1/voices/prepare/' . $kind;
        if ($start !== null) {
            $path .= '?start=' . $start;
        }
        $wav = self::request($path, null, 300, $recording);
        if (strlen($wav) <= 44 || strlen($wav) > 2 * 1024 * 1024 ||
                !str_starts_with($wav, 'RIFF') || substr($wav, 8, 4) !== 'WAVE') {
            throw new \moodle_exception('voicepreparefailed', 'local_microlearning');
        }
        return $wav;
    }

    public static function synthesize(string $script, string $preset, ?string $sample, string $destination): void {
        $payload = ['text' => $script, 'voice' => $preset];
        if ($sample !== null) {
            $payload['reference'] = base64_encode($sample);
        }
        $file = fopen($destination, 'w+b');
        if ($file === false) {
            throw new \moodle_exception('speechgenerationfailed', 'local_microlearning');
        }
        try {
            self::request('/v1/synthesize', $payload, 1800, null, $file);
            $size = fstat($file)['size'] ?? 0;
            rewind($file);
            $header = fread($file, 12);
            if ($size <= 44 || $size > self::MAX_WAV_BYTES || !$header ||
                    !str_starts_with($header, 'RIFF') || substr($header, 8, 4) !== 'WAVE') {
                throw new \moodle_exception('speechgenerationfailed', 'local_microlearning');
            }
        } finally {
            fclose($file);
        }
    }

    private static function request(
        string $path,
        ?array $payload,
        int $timeout,
        ?\stored_file $upload = null,
        $download = null,
    ): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $base = rtrim(get_config('local_microlearning', 'narrationserviceurl') ?: self::DEFAULT_URL, '/');
        $parts = parse_url($base);
        $host = $parts['host'] ?? '';
        $scheme = $parts['scheme'] ?? '';
        $loopback = in_array($host, ['127.0.0.1', '[::1]'], true);
        if ($host === '' || ($scheme !== 'https' && !($scheme === 'http' && $loopback)) ||
                isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \moodle_exception('speechurlinvalid', 'local_microlearning');
        }
        $token = get_config('local_microlearning', 'narrationservicetoken');
        if (!is_string($token) || !preg_match('/\A[\x21-\x7e]{32,256}\z/D', $token)) {
            throw new \moodle_exception('speechauth', 'local_microlearning');
        }

        // A configured literal loopback address is used directly, without an HTTP proxy.
        $curl = new \curl($loopback ? ['ignoresecurity' => true, 'proxy' => false] : []);
        $curl->setHeader(['Authorization: Bearer ' . $token,
            'Content-Type: ' . ($upload === null ? 'application/json' : 'application/octet-stream')]);
        $options = ['CURLOPT_CONNECTTIMEOUT' => min($timeout, 5), 'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_FOLLOWLOCATION' => false];
        if ($loopback) {
            $options['CURLOPT_PROXY'] = '';
        }
        if ($download !== null) {
            $options['CURLOPT_RETURNTRANSFER'] = false;
            $options['CURLOPT_FILE'] = $download;
            $options['CURLOPT_MAXFILESIZE'] = self::MAX_WAV_BYTES;
        }
        $url = $base . $path;
        if ($upload !== null) {
            $filepath = get_file_storage()->get_file_system()->get_local_path_from_storedfile($upload, true);
            if (!$filepath) {
                throw new \moodle_exception('voicepreparefailed', 'local_microlearning');
            }
            $options['CURLOPT_USERPWD'] = false;
            $options['CURLOPT_SSL_VERIFYPEER'] = true;
            $data = $curl->put($url, ['file' => $filepath], $options);
        } else {
            $data = $payload === null
                ? $curl->get($url, [], $options)
                : $curl->post($url, json_encode($payload, JSON_THROW_ON_ERROR), $options);
        }
        if ($curl->get_errno() || ($download === null ? !is_string($data) : $data !== true)) {
            throw new \moodle_exception('speechunavailable', 'local_microlearning');
        }
        $status = (int)($curl->get_info()['http_code'] ?? 0);
        if ($status !== 200) {
            if ($download !== null) {
                rewind($download);
                $data = stream_get_contents($download, 8192);
            }
            $reply = json_decode($data, true);
            $detail = $reply['detail'] ?? '';
            $error = in_array($detail, self::SERVICE_ERRORS, true) ? $detail : 'speechunavailable';
            throw new \moodle_exception($error, 'local_microlearning');
        }
        return $download === null ? $data : '';
    }
}
