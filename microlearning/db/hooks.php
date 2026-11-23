<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\navigation\primary_extend::class,
        'callback' => [\local_microlearning\local\student_navigation::class, 'extend'],
    ],
    [
        'hook' => \core\hook\navigation\secondary_extend::class,
        'callback' => [\local_microlearning\local\narration_workspace::class, 'extend_navigation'],
    ],
];
