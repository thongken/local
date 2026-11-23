<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/microlearning:use', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/microlearning/history.php');
$PAGE->set_title(get_string('history', 'local_microlearning'));
$PAGE->set_heading(get_string('history', 'local_microlearning'));

$delete = optional_param('delete', 0, PARAM_INT);
if ($delete && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $DB->delete_records('local_microlearning_items', ['id' => $delete, 'userid' => $USER->id]);
    redirect($PAGE->url, get_string('deleted', 'local_microlearning'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$items = $DB->get_records('local_microlearning_items', ['userid' => $USER->id], 'timecreated DESC',
    'id, kind, title, visibility, durationsec, timecreated', 0, 200);

echo $OUTPUT->header();
if (!$items) {
    echo $OUTPUT->notification(get_string('noitems', 'local_microlearning'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [
        get_string('titlefield', 'local_microlearning'),
        get_string('kind', 'local_microlearning'),
        get_string('visibility', 'local_microlearning'),
        get_string('duration', 'local_microlearning'),
        get_string('created', 'local_microlearning'),
        get_string('actions', 'local_microlearning'),
    ];
    $confirm = addslashes_js(get_string('confirmdelete', 'local_microlearning'));
    foreach ($items as $it) {
        $view = new moodle_url('/local/microlearning/view.php', ['id' => $it->id]);
        $edit = new moodle_url('/local/microlearning/edit.php', ['id' => $it->id]);
        $form = html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline',
            'action' => $PAGE->url->out(false), 'onsubmit' => "return confirm('$confirm');"])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'delete', 'value' => $it->id])
            . html_writer::tag('button', get_string('delete', 'local_microlearning'),
                ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger'])
            . html_writer::end_tag('form');
        $table->data[] = [
            html_writer::link($view, s($it->title)),
            get_string('kind_' . $it->kind, 'local_microlearning'),
            get_string('vis_' . $it->visibility, 'local_microlearning'),
            format_time($it->durationsec),
            userdate($it->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            html_writer::link($edit, get_string('edit', 'local_microlearning'), ['class' => 'btn btn-sm btn-outline-primary'])
                . ' ' . $form,
        ];
    }
    echo html_writer::table($table);
}
echo html_writer::link(new moodle_url('/local/microlearning/index.php'),
    get_string('back', 'local_microlearning'), ['class' => 'btn btn-secondary']);
echo $OUTPUT->footer();