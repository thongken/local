<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_microlearning_create_quiz' => [
        'classname' => 'local_microlearning\\external\\create_quiz',
        'description' => 'Create a hidden native Moodle Quiz from browser-generated questions.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities,mod/quiz:addinstance,moodle/question:add',
    ],
    'local_microlearning_save_item' => [
        'classname'   => 'local_microlearning\external\save_item',
        'description' => 'Luu transcript, summary va quiz do nguoi dung tao.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'local/microlearning:use',
    ],
    'local_microlearning_update_content' => [
        'classname'   => 'local_microlearning\external\update_content',
        'description' => 'Cap nhat tieu de/summary/quiz (chi chu so huu).',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'local/microlearning:use',
    ],
];
