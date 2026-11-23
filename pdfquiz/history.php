<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/pdfquiz:use', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/pdfquiz/history.php');
$PAGE->set_title(get_string('history', 'local_pdfquiz'));
$PAGE->set_heading(get_string('history', 'local_pdfquiz'));

$delete = optional_param('delete', 0, PARAM_INT);
if ($delete && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $DB->delete_records('local_pdfquiz_items', ['id' => $delete, 'userid' => $USER->id]);
    redirect($PAGE->url, get_string('deleted', 'local_pdfquiz'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$items = $DB->get_records(
    'local_pdfquiz_items',
    ['userid' => $USER->id],
    'timecreated DESC',
    'id, title, visibility, pagecount, timecreated',
    0,
    200
);

echo $OUTPUT->header();
if (!$items) {
    echo $OUTPUT->notification(get_string('noitems', 'local_pdfquiz'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [
        get_string('titlefield', 'local_pdfquiz'),
        get_string('visibility', 'local_pdfquiz'),
        get_string('pages', 'local_pdfquiz'),
        get_string('created', 'local_pdfquiz'),
        get_string('actions', 'local_pdfquiz'),
    ];
    $confirm = addslashes_js(get_string('confirmdelete', 'local_pdfquiz'));
    foreach ($items as $it) {
        $view = new moodle_url('/local/pdfquiz/view.php', ['id' => $it->id]);
        $edit = new moodle_url('/local/pdfquiz/edit.php', ['id' => $it->id]);
        $form = html_writer::start_tag('form', [
                'method' => 'post',
                'class' => 'd-inline',
                'action' => $PAGE->url->out(false),
                'onsubmit' => "return confirm('$confirm');",
            ])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'delete', 'value' => $it->id])
            . html_writer::tag('button', get_string('delete', 'local_pdfquiz'),
                ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger'])
            . html_writer::end_tag('form');
        $table->data[] = [
            html_writer::link($view, s($it->title)),
            get_string('vis_' . $it->visibility, 'local_pdfquiz'),
            get_string('pagescount', 'local_pdfquiz', (int)$it->pagecount),
            userdate($it->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            html_writer::link($edit, get_string('edit', 'local_pdfquiz'), ['class' => 'btn btn-sm btn-outline-primary'])
                . ' ' . $form,
        ];
    }
    echo html_writer::table($table);
}
echo html_writer::link(
    new moodle_url('/local/pdfquiz/index.php'),
    get_string('back', 'local_pdfquiz'),
    ['class' => 'btn btn-secondary']
);
echo $OUTPUT->footer();

