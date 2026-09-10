<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Administrative multiple-student search page.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/tablelib.php');

use local_gerenciamentomatriculas\local\operation_guard;

$query = optional_param('q', '', PARAM_TEXT);
require_login();

$systemcontext = context_system::instance();
if (!local_gerenciamentomatriculas_can_manage()) {
    throw new required_capability_exception($systemcontext, 'moodle/site:configview', 'nopermissions', 'error');
}

$pageurl = new moodle_url('/local/gerenciamentomatriculas/selectuser.php');
$manageurl = new moodle_url('/local/gerenciamentomatriculas/index.php');
$PAGE->set_context($systemcontext);
$PAGE->set_url($pageurl, $query === '' ? [] : ['q' => $query]);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('selectuser:title', 'local_gerenciamentomatriculas'));
$PAGE->set_heading(get_string('pluginname', 'local_gerenciamentomatriculas'));

$selectid = 'local-gerenciamentomatriculas-userselector';
$fallbackid = 'local-gerenciamentomatriculas-usersearch-fallback';
$autocompleteregionid = 'local-gerenciamentomatriculas-usersearch-autocomplete';
$maxuserbatch = defined(operation_guard::class . '::MAX_USER_BATCH_SIZE')
    ? constant(operation_guard::class . '::MAX_USER_BATCH_SIZE')
    : 15;
$PAGE->requires->js_call_amd('local_gerenciamentomatriculas/student_multi_selector', 'init', [
    $selectid,
    $fallbackid,
    $autocompleteregionid,
    get_string('searchuserplaceholder', 'local_gerenciamentomatriculas'),
    get_string('noselectedusers', 'local_gerenciamentomatriculas'),
    $maxuserbatch,
    get_string('safetyuserbatchclient', 'local_gerenciamentomatriculas', $maxuserbatch),
]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('selectuser:title', 'local_gerenciamentomatriculas'));
echo html_writer::tag('p', get_string('selectuser:multihelp', 'local_gerenciamentomatriculas', $maxuserbatch), [
    'class' => 'text-muted',
]);

// JavaScript-enhanced multiple autocomplete. Clicking a suggestion only adds a chip.
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $manageurl->out(false),
    'class' => 'mb-4 local-gerenciamentomatriculas-search-wrap',
]);
echo html_writer::start_div('form-group d-none local-gerenciamentomatriculas-autocomplete', [
    'id' => $autocompleteregionid,
]);
echo html_writer::tag('label', get_string('searchusersfield', 'local_gerenciamentomatriculas'), ['for' => $selectid]);
echo html_writer::select([], 'userids[]', [], false, [
    'id' => $selectid,
    'class' => 'custom-select',
    'multiple' => 'multiple',
]);
echo html_writer::start_div('mt-3', ['id' => 'local-gerenciamentomatriculas-user-submit']);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('continueselectedusers', 'local_gerenciamentomatriculas'),
]);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_tag('form');

// No-JavaScript/AJAX fallback. It remains visible unless autocomplete is successfully initialised.
echo html_writer::start_div('local-gerenciamentomatriculas-search-wrap', ['id' => $fallbackid]);
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $pageurl->out(false), 'class' => 'mb-4']);
echo html_writer::div(
    html_writer::tag('label', get_string('searchuser', 'local_gerenciamentomatriculas'), [
        'for' => 'local-gerenciamentomatriculas-userquery',
    ]) . html_writer::empty_tag('input', [
        'type' => 'text',
        'id' => 'local-gerenciamentomatriculas-userquery',
        'name' => 'q',
        'value' => $query,
        'class' => 'form-control',
        'placeholder' => get_string('searchuserplaceholder', 'local_gerenciamentomatriculas'),
        'autocomplete' => 'off',
    ]),
    'form-group'
);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('searchusers', 'local_gerenciamentomatriculas'),
]);
echo html_writer::end_tag('form');

if ($query !== '') {
    if (core_text::strlen(trim($query)) < 2) {
        echo $OUTPUT->notification(
            get_string('searchusertooshort', 'local_gerenciamentomatriculas'),
            \core\output\notification::NOTIFY_WARNING
        );
    } else {
        $users = \local_gerenciamentomatriculas\local\user_search::find(
            $query,
            \local_gerenciamentomatriculas\local\user_search::FALLBACK_LIMIT
        );
        if (empty($users)) {
            echo $OUTPUT->notification(
                get_string('nousersfound', 'local_gerenciamentomatriculas'),
                \core\output\notification::NOTIFY_INFO
            );
        } else {
            echo html_writer::start_tag('form', ['method' => 'get', 'action' => $manageurl->out(false)]);
            $table = new html_table();
            $table->attributes['class'] = 'generaltable table table-striped';
            $table->head = [
                get_string('select', 'local_gerenciamentomatriculas'),
                get_string('student', 'local_gerenciamentomatriculas'),
                get_string('username', 'local_gerenciamentomatriculas'),
                get_string('email', 'local_gerenciamentomatriculas'),
                get_string('accountstatus', 'local_gerenciamentomatriculas'),
            ];
            foreach ($users as $founduser) {
                $checkboxid = 'local-gerenciamentomatriculas-user-' . $founduser->id;
                $table->data[] = [
                    html_writer::empty_tag('input', [
                        'type' => 'checkbox',
                        'id' => $checkboxid,
                        'name' => 'userids[]',
                        'value' => $founduser->id,
                    ]),
                    html_writer::tag('label', fullname($founduser), ['for' => $checkboxid]),
                    s($founduser->username),
                    s($founduser->email),
                    !empty($founduser->suspended)
                        ? get_string('accountsuspended', 'local_gerenciamentomatriculas')
                        : get_string('accountactive', 'local_gerenciamentomatriculas'),
                ];
            }
            echo html_writer::table($table);
            echo html_writer::empty_tag('input', [
                'type' => 'submit',
                'class' => 'btn btn-primary',
                'value' => get_string('continueselectedusers', 'local_gerenciamentomatriculas'),
            ]);
            echo html_writer::end_tag('form');
        }
    }
}
echo html_writer::end_div();
echo $OUTPUT->footer();
