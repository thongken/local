<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/pdfquiz:viewstudentnavigation' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => ['student' => CAP_ALLOW],
    ],
    'local/pdfquiz:use' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => ['user' => CAP_ALLOW],
    ],
];

