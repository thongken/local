<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_pdfquiz_create_quiz' => [
        'classname' => 'local_pdfquiz\\external\\create_quiz',
        'description' => 'Create a hidden native Moodle Quiz from PDF-generated questions.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities,mod/quiz:addinstance,moodle/question:add',
    ],
    'local_pdfquiz_save_item' => [
        'classname' => 'local_pdfquiz\\external\\save_item',
        'description' => 'Save personal PDF extracted text, summary and quiz to history.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/pdfquiz:use',
    ],
    'local_pdfquiz_update_content' => [
        'classname' => 'local_pdfquiz\\external\\update_content',
        'description' => 'Update title, summary or quiz of a saved PDF item (owner only).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/pdfquiz:use',
    ],
];

