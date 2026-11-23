<?php
require_once(__DIR__ . '/../../config.php');

use local_microlearning\local\visibility;

require_login();
$context = context_system::instance();
require_capability('local/microlearning:use', $context);

$id = required_param('id', PARAM_INT);
$item = $DB->get_record('local_microlearning_items', ['id' => $id], '*', MUST_EXIST);
if (!visibility::can_view($item, (int)$USER->id)) {
    throw new moodle_exception('noaccess', 'local_microlearning');
}
$isowner = ((int)$item->userid === (int)$USER->id);
$content = json_decode($item->content, true);
if (!is_array($content)) {
    $content = [];
}

$PAGE->set_context($context);
$PAGE->set_url('/local/microlearning/view.php', ['id' => $id]);
$PAGE->set_title(s($item->title));
$PAGE->set_heading(s($item->title));

echo $OUTPUT->header();

$meta = get_string('vis_' . $item->visibility, 'local_microlearning') . ' · ' . format_time($item->durationsec);
$owner = core_user::get_user((int)$item->userid, '*', IGNORE_MISSING);
if ($owner && !$isowner) {
    $meta = fullname($owner) . ' · ' . $meta;
}
echo html_writer::tag('p', s($meta), ['class' => 'text-muted']);
if ($isowner) {
    echo html_writer::link(new moodle_url('/local/microlearning/edit.php', ['id' => $id]),
        get_string('edit', 'local_microlearning'), ['class' => 'btn btn-primary mb-3 me-2']);
}
echo html_writer::link(new moodle_url('/local/microlearning/history.php'),
    get_string('history', 'local_microlearning'), ['class' => 'btn btn-secondary mb-3']);

$sum = $content['summary'] ?? [];
echo html_writer::tag('h3', get_string('summary', 'local_microlearning'));
echo html_writer::tag('p', s($sum['short_summary'] ?? ''));
if (!empty($sum['main_points']) && is_array($sum['main_points'])) {
    echo html_writer::tag('h5', get_string('mainpoints', 'local_microlearning'));
    echo html_writer::start_tag('ul');
    foreach ($sum['main_points'] as $p) {
        echo html_writer::tag('li', s((string)$p));
    }
    echo html_writer::end_tag('ul');
}

foreach (($content['segments'] ?? []) as $i => $seg) {
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h4', s(get_string('segment', 'local_microlearning') . ' ' . ($i + 1)
        . ' (' . ($seg['start_time'] ?? '') . ' - ' . ($seg['end_time'] ?? '') . ')'));
    echo html_writer::tag('p', s($seg['segment_summary'] ?? ''));
    foreach (($seg['quiz'] ?? []) as $j => $q) {
        echo html_writer::tag('h6', s(($j + 1) . '. ' . ($q['question'] ?? '')), ['class' => 'mt-3']);
        echo html_writer::start_tag('ol', ['type' => 'A']);
        foreach (($q['options'] ?? []) as $o) {
            echo html_writer::tag('li', s((string)$o));
        }
        echo html_writer::end_tag('ol');
        echo html_writer::tag('details',
            html_writer::tag('summary', get_string('showanswer', 'local_microlearning'))
            . html_writer::tag('p', html_writer::tag('strong', get_string('answer', 'local_microlearning') . ': ')
                . s($q['correct_answer'] ?? ''))
            . html_writer::tag('p', s($q['explanation'] ?? '')));
    }
    echo html_writer::end_div() . html_writer::end_div();
}

if ($isowner) {
    echo html_writer::tag('details',
        html_writer::tag('summary', get_string('transcript', 'local_microlearning'))
        . html_writer::tag('pre', s($item->transcript), ['style' => 'white-space:pre-wrap']));
}
echo $OUTPUT->footer();