<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/grouplib.php');

use local_pdfquiz\local\content_validator;
use local_pdfquiz\local\visibility;

require_login();
$context = context_system::instance();
require_capability('local/pdfquiz:use', $context);

$id = required_param('id', PARAM_INT);
$item = $DB->get_record('local_pdfquiz_items', ['id' => $id], '*', MUST_EXIST);
if ((int)$item->userid !== (int)$USER->id) {
    throw new moodle_exception('noaccess', 'local_pdfquiz');
}
$viewurl = new moodle_url('/local/pdfquiz/view.php', ['id' => $id]);

$PAGE->set_context($context);
$PAGE->set_url('/local/pdfquiz/edit.php', ['id' => $id]);
$PAGE->set_title(get_string('edit', 'local_pdfquiz'));
$PAGE->set_heading(get_string('edit', 'local_pdfquiz'));

$content = json_decode($item->content, true);
if (!is_array($content) || empty($content['segments']) || !is_array($content['segments'])) {
    throw new moodle_exception('noaccess', 'local_pdfquiz');
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $title = trim(required_param('title', PARAM_TEXT));
    $vis = required_param('visibility', PARAM_ALPHA);
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $groupid = optional_param('groupid', 0, PARAM_INT);
    $rev = required_param('revision', PARAM_INT);
    // Multi-dimensional form array: optional_param_array() only accepts 1D arrays in Moodle 5.3.
    $rawsegs = (isset($_POST['seg']) && is_array($_POST['seg'])) ? $_POST['seg'] : [];

    $new = $content;
    $new['summary']['short_summary'] = trim(optional_param('short_summary', '', PARAM_TEXT));
    $lines = preg_split('/\R/', optional_param('main_points', '', PARAM_TEXT));
    $new['summary']['main_points'] = array_values(array_filter(array_map('trim', $lines), 'strlen'));
    foreach (array_keys($new['segments']) as $i) {
        $in = (isset($rawsegs[$i]) && is_array($rawsegs[$i])) ? $rawsegs[$i] : [];
        $new['segments'][$i]['segment_summary'] = trim(clean_param((string)($in['summary'] ?? ''), PARAM_TEXT));
        $rawqs = (isset($in['q']) && is_array($in['q'])) ? $in['q'] : [];
        foreach (array_keys($new['segments'][$i]['quiz'] ?? []) as $j) {
            $qi = (isset($rawqs[$j]) && is_array($rawqs[$j])) ? $rawqs[$j] : [];
            $rawopts = (isset($qi['o']) && is_array($qi['o'])) ? array_values($qi['o']) : [];
            $opts = array_map(static fn($o) => trim(clean_param((string)$o, PARAM_TEXT)), $rawopts);
            $correctidx = isset($qi['c']) ? clean_param((string)$qi['c'], PARAM_INT) : 0;
            $new['segments'][$i]['quiz'][$j] = [
                'question' => trim(clean_param((string)($qi['t'] ?? ''), PARAM_TEXT)),
                'options' => $opts,
                'correct_answer' => $opts[$correctidx] ?? '',
                'explanation' => trim(clean_param((string)($qi['e'] ?? ''), PARAM_TEXT)),
            ];
        }
    }

    if ($vis === visibility::GROUP) {
        $g = $DB->get_record('groups', ['id' => $groupid], 'id, courseid');
        $courseid = $g ? (int)$g->courseid : 0;
    } else if ($vis === visibility::COURSE) {
        $groupid = 0;
    } else {
        $courseid = 0;
        $groupid = 0;
    }

    $errors = content_validator::validate_content($new);
    if ($title === '' || mb_strlen($title) > 255) {
        $errors[] = 'title';
    }
    if (!visibility::can_set($vis, $courseid, $groupid, (int)$USER->id)) {
        $errors[] = 'visibility';
    }
    $fresh = $DB->get_field('local_pdfquiz_items', 'revision', ['id' => $id], MUST_EXIST);
    if ((int)$fresh !== $rev) {
        $errors[] = 'conflict';
    }

    if (!$errors) {
        $item->title = $title;
        $item->content = json_encode($new, JSON_UNESCAPED_UNICODE);
        $item->visibility = $vis;
        $item->courseid = $courseid;
        $item->groupid = $groupid;
        $item->revision = (int)$item->revision + 1;
        $item->timemodified = time();
        $DB->update_record('local_pdfquiz_items', $item);
        redirect($viewurl, get_string('saved', 'local_pdfquiz'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    $content = $new;
    $item->title = $title;
    $item->visibility = in_array($vis, visibility::ALL, true) ? $vis : $item->visibility;
    $item->courseid = $courseid;
    $item->groupid = $groupid;
}

$courseopts = [0 => get_string('choose')];
$groupopts = [0 => get_string('choose')];
foreach (enrol_get_my_courses('id, fullname', 'fullname ASC', 50) as $c) {
    $courseopts[$c->id] = format_string($c->fullname);
    $gs = groups_get_user_groups($c->id, $USER->id);
    foreach ($gs[0] as $gid) {
        $groupopts[$gid] = format_string($c->fullname) . ' — ' . format_string(groups_get_group_name($gid));
    }
}
$visopts = [];
foreach (visibility::ALL as $v) {
    $visopts[$v] = get_string('vis_' . $v, 'local_pdfquiz');
}

echo $OUTPUT->header();
if ($errors) {
    echo $OUTPUT->notification(get_string('saveerror', 'local_pdfquiz', s(implode(', ', array_slice($errors, 0, 5)))), 'error');
}
$field = function (string $label, string $html): string {
    return html_writer::div(html_writer::tag('label', $label, ['class' => 'form-label fw-bold']) . $html, 'mb-3');
};
$sum = $content['summary'] ?? [];

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revision', 'value' => (int)$item->revision]);

echo $field(get_string('titlefield', 'local_pdfquiz'), html_writer::empty_tag('input',
    ['type' => 'text', 'name' => 'title', 'value' => $item->title, 'maxlength' => 255, 'class' => 'form-control']));
echo $field(get_string('visibility', 'local_pdfquiz'),
    html_writer::select($visopts, 'visibility', $item->visibility, false, ['class' => 'form-select']));
echo $field(get_string('course'), html_writer::select($courseopts, 'courseid',
    $item->visibility === visibility::COURSE ? $item->courseid : 0, false, ['class' => 'form-select'])
    . html_writer::tag('div', get_string('vis_course_help', 'local_pdfquiz'), ['class' => 'form-text']));
echo $field(get_string('group'), html_writer::select($groupopts, 'groupid',
    $item->visibility === visibility::GROUP ? $item->groupid : 0, false, ['class' => 'form-select'])
    . html_writer::tag('div', get_string('vis_group_help', 'local_pdfquiz'), ['class' => 'form-text']));

echo html_writer::tag('h3', get_string('summary', 'local_pdfquiz'));
echo $field(get_string('shortsummary', 'local_pdfquiz'), html_writer::tag('textarea',
    s($sum['short_summary'] ?? ''), ['name' => 'short_summary', 'rows' => 4, 'class' => 'form-control']));
echo $field(get_string('mainpoints', 'local_pdfquiz'), html_writer::tag('textarea',
    s(implode("\n", $sum['main_points'] ?? [])), ['name' => 'main_points', 'rows' => 6, 'class' => 'form-control'])
    . html_writer::tag('div', get_string('mainpoints_help', 'local_pdfquiz'), ['class' => 'form-text']));

foreach ($content['segments'] as $i => $seg) {
    echo html_writer::start_div('card mb-3') . html_writer::start_div('card-body');
    $segtitle = get_string('segment', 'local_pdfquiz') . ' ' . ($i + 1);
    if (!empty($seg['page_start']) || !empty($seg['page_end'])) {
        $segtitle .= ' (' . get_string('pagerange', 'local_pdfquiz', (object)[
            'start' => (int)($seg['page_start'] ?? 1),
            'end' => (int)($seg['page_end'] ?? ($seg['page_start'] ?? 1)),
        ]) . ')';
    }
    echo html_writer::tag('h4', s($segtitle));
    echo $field(get_string('segmentsummary', 'local_pdfquiz'), html_writer::tag('textarea',
        s($seg['segment_summary'] ?? ''), ['name' => "seg[$i][summary]", 'rows' => 4, 'class' => 'form-control']));
    foreach (($seg['quiz'] ?? []) as $j => $q) {
        $n = "seg[$i][q][$j]";
        echo html_writer::start_div('border rounded p-3 mb-3');
        echo $field(($j + 1) . '. ' . get_string('question', 'local_pdfquiz'), html_writer::empty_tag('input',
            ['type' => 'text', 'name' => $n . '[t]', 'value' => $q['question'] ?? '', 'class' => 'form-control']));
        $correct = array_search($q['correct_answer'] ?? '', $q['options'] ?? [], true);
        foreach (($q['options'] ?? []) as $k => $o) {
            echo html_writer::div(
                html_writer::empty_tag('input', ['type' => 'radio', 'name' => $n . '[c]', 'value' => $k,
                    'checked' => ($correct === $k) ? 'checked' : null, 'class' => 'form-check-input mt-2 me-2'])
                . html_writer::empty_tag('input', ['type' => 'text', 'name' => $n . "[o][$k]", 'value' => $o,
                    'class' => 'form-control']),
                'd-flex mb-2'
            );
        }
        echo html_writer::tag('div', get_string('correct_help', 'local_pdfquiz'), ['class' => 'form-text mb-2']);
        echo $field(get_string('explanation', 'local_pdfquiz'), html_writer::tag('textarea',
            s($q['explanation'] ?? ''), ['name' => $n . '[e]', 'rows' => 2, 'class' => 'form-control']));
        echo html_writer::end_div();
    }
    echo html_writer::end_div() . html_writer::end_div();
}

echo html_writer::tag('button', get_string('save', 'local_pdfquiz'),
    ['type' => 'submit', 'class' => 'btn btn-primary me-2']);
echo html_writer::link($viewurl, get_string('cancel'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();

