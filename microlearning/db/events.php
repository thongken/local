<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => \core\event\course_deleted::class,
        'callback' => [\local_microlearning\local\narration_service::class, 'course_deleted'],
    ],
];
