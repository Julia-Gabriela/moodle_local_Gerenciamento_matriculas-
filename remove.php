<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Removal and suspension page for one or more students.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/tablelib.php');

use local_gerenciamentomatriculas\local\enrolment_service;
use local_gerenciamentomatriculas\local\operation_guard;

$legacyuserid = optional_param('userid', 0, PARAM_INT);
$userids = optional_param_array('userids', [], PARAM_INT);
if ($legacyuserid > 0) {
    array_unshift($userids, $legacyuserid);
}
$userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
$action = optional_param('action', enrolment_service::ACTION_UNENROL, PARAM_ALPHA);
$suspendaccount = optional_param('suspendaccount', 0, PARAM_BOOL);
$rawselections = optional_param_array('selections', [], PARAM_TEXT);

require_login();

$systemcontext = context_system::instance();
if (!local_gerenciamentomatriculas_can_manage()) {
    throw new required_capability_exception($systemcontext, 'moodle/site:configview', 'nopermissions', 'error');
}
if (empty($userids)) {
    redirect(
        new moodle_url('/local/gerenciamentomatriculas/selectuser.php'),
        get_string('safetynouserselection', 'local_gerenciamentomatriculas'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$userbatcherror = null;
try {
    $userbatcherror = operation_guard::validate_user_batch_size($userids);
} catch (\Throwable $exception) {
    debugging($exception->getMessage(), DEBUG_DEVELOPER);
    $userbatcherror = get_string('safetyguardmissing', 'local_gerenciamentomatriculas');
}
if ($userbatcherror !== null) {
    redirect(
        new moodle_url('/local/gerenciamentomatriculas/selectuser.php'),
        $userbatcherror,
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

[$userinsql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'removeuser');
$userrecords = $DB->get_records_select(
    'user',
    "id {$userinsql} AND deleted = 0",
    $userparams,
    '',
    'id,username,firstname,lastname,email,suspended,deleted'
);
$users = [];
foreach ($userids as $userid) {
    if (!isset($userrecords[$userid]) || isguestuser($userrecords[$userid])) {
        throw new moodle_exception('invaliduser', 'local_gerenciamentomatriculas');
    }
    $users[$userid] = $userrecords[$userid];
}

$pageurl = new moodle_url('/local/gerenciamentomatriculas/remove.php', ['userids' => $userids]);
$addurl = new moodle_url('/local/gerenciamentomatriculas/enrol.php', ['userids' => $userids]);
$homeurl = new moodle_url('/local/gerenciamentomatriculas/index.php', ['userids' => $userids]);

$PAGE->set_context($systemcontext);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('manage:title', 'local_gerenciamentomatriculas'));
$PAGE->set_heading(get_string('manage:title', 'local_gerenciamentomatriculas'));

try {
    $guardavailable = class_exists(operation_guard::class)
        && is_callable([operation_guard::class, 'preflight'])
        && is_callable([operation_guard::class, 'validate_remove_batch_size'])
        && is_callable([operation_guard::class, 'validate_user_batch_size'])
        && is_callable([operation_guard::class, 'acquire_user_lock'])
        && is_callable([operation_guard::class, 'release_user_lock']);
    $preflighterrors = $guardavailable
        ? operation_guard::preflight(operation_guard::FEATURE_REMOVE)
        : [get_string('safetyguardmissing', 'local_gerenciamentomatriculas')];
} catch (\Throwable $exception) {
    debugging($exception->getMessage(), DEBUG_DEVELOPER);
    $preflighterrors = [get_string('safetyguardmissing', 'local_gerenciamentomatriculas')];
}

if (!empty($preflighterrors)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('safetyfeatureblocked', 'local_gerenciamentomatriculas') . html_writer::alist($preflighterrors),
        \core\output\notification::NOTIFY_ERROR
    );
    echo html_writer::link($homeurl, get_string('backtomanagement', 'local_gerenciamentomatriculas'), [
        'class' => 'btn btn-secondary',
    ]);
    echo $OUTPUT->footer();
    exit;
}

try {
    $enrolmentsbyuser = enrolment_service::get_users_enrolments($userids);
} catch (\Throwable $exception) {
    debugging($exception->getMessage(), DEBUG_DEVELOPER);
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('safetyreadfailed', 'local_gerenciamentomatriculas'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo html_writer::link($homeurl, get_string('backtomanagement', 'local_gerenciamentomatriculas'), [
        'class' => 'btn btn-secondary',
    ]);
    echo $OUTPUT->footer();
    exit;
}

$selectedbyuser = [];
$flatselected = [];
foreach ($rawselections as $rawselection) {
    if (!preg_match('/^(\d+):(\d+)$/', trim((string) $rawselection), $matches)) {
        continue;
    }
    $userid = (int) $matches[1];
    $enrolmentid = (int) $matches[2];
    if (!isset($users[$userid]) || !isset($enrolmentsbyuser[$userid][$enrolmentid])) {
        continue;
    }
    $selectedbyuser[$userid][$enrolmentid] = $enrolmentid;
    $flatselected[$enrolmentid] = $enrolmentid;
}

if (!in_array($action, [enrolment_service::ACTION_UNENROL, enrolment_service::ACTION_SUSPEND], true)) {
    $action = enrolment_service::ACTION_UNENROL;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (empty($flatselected) && !$suspendaccount) {
        redirect($pageurl, get_string('noselection', 'local_gerenciamentomatriculas'), null,
            \core\output\notification::NOTIFY_WARNING);
    }
    if (!empty($flatselected)) {
        $batcherror = operation_guard::validate_remove_batch_size(array_values($flatselected));
        if ($batcherror !== null) {
            redirect($pageurl, $batcherror, null, \core\output\notification::NOTIFY_WARNING);
        }
    }

    $studentoperations = [];
    foreach ($users as $userid => $user) {
        $studentids = array_values($selectedbyuser[$userid] ?? []);
        if (empty($studentids) && !$suspendaccount) {
            continue;
        }
        $studentoperations[] = [
            'id' => $userid,
            'name' => fullname($user),
            'enrolmentids' => $studentids,
        ];
    }

    $buttonid = 'local-gerenciamentomatriculas-confirm-removal';
    $progressid = 'local-gerenciamentomatriculas-removal-progress';
    $statusid = 'local-gerenciamentomatriculas-removal-status';
    $resultsid = 'local-gerenciamentomatriculas-removal-results';
    $completionactionsid = 'local-gerenciamentomatriculas-removal-completion-actions';
    $processorstrings = [
        'processing' => get_string('bulkremovalprocessing', 'local_gerenciamentomatriculas'),
        'completed' => get_string('bulkremovalcompleted', 'local_gerenciamentomatriculas'),
        'success' => get_string('summarysuccess', 'local_gerenciamentomatriculas'),
        'errors' => get_string('summaryerrors', 'local_gerenciamentomatriculas'),
        'transporterror' => get_string('bulktransporterror', 'local_gerenciamentomatriculas'),
        'interrupted' => get_string('bulkinterrupted', 'local_gerenciamentomatriculas'),
        'keepopen' => get_string('bulkkeepopen', 'local_gerenciamentomatriculas'),
    ];
    $PAGE->requires->js_call_amd('local_gerenciamentomatriculas/bulk_removal_processor', 'init', [
        $buttonid,
        $progressid,
        $statusid,
        $resultsid,
        $completionactionsid,
        $studentoperations,
        $action,
        (bool) $suspendaccount,
        operation_guard::PROCESSING_CHUNK_SIZE,
        $processorstrings,
    ]);

    echo $OUTPUT->header();
    $tabs = [[
        new tabobject('remove', $pageurl, get_string('tabremove', 'local_gerenciamentomatriculas')),
        new tabobject('add', $addurl, get_string('tabadd', 'local_gerenciamentomatriculas')),
    ]];
    echo html_writer::div(print_tabs($tabs, 'remove', null, null, true), 'mb-3');
    echo $OUTPUT->heading(get_string('confirmation', 'local_gerenciamentomatriculas'));
    echo $OUTPUT->notification(
        get_string('confirmremovebulktext', 'local_gerenciamentomatriculas', (object) [
            'students' => count($studentoperations),
            'enrolments' => count($flatselected),
        ]),
        \core\output\notification::NOTIFY_INFO
    );
    echo html_writer::tag('h4', get_string('selectedstudents', 'local_gerenciamentomatriculas'));
    $confirmationusers = [];
    foreach ($studentoperations as $operation) {
        $confirmationusers[$operation['id']] = $users[$operation['id']];
    }
    echo local_gerenciamentomatriculas_render_users_table($confirmationusers);

    if (!empty($flatselected)) {
        echo html_writer::tag('h4', get_string('selectedenrolments', 'local_gerenciamentomatriculas'));
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table table-striped';
        $table->head = [
            get_string('student', 'local_gerenciamentomatriculas'),
            get_string('course', 'local_gerenciamentomatriculas'),
            get_string('shortname', 'local_gerenciamentomatriculas'),
        ];
        foreach ($selectedbyuser as $userid => $selectedids) {
            foreach ($selectedids as $enrolmentid) {
                $enrolment = $enrolmentsbyuser[$userid][$enrolmentid];
                $coursecontext = context_course::instance($enrolment->courseid);
                $table->data[] = [
                    fullname($users[$userid]),
                    format_string($enrolment->fullname, true, ['context' => $coursecontext]),
                    s($enrolment->shortname),
                ];
            }
        }
        echo html_writer::table($table);
    }
    if ($suspendaccount) {
        echo $OUTPUT->notification(
            get_string('suspendaccountsbulk', 'local_gerenciamentomatriculas'),
            \core\output\notification::NOTIFY_WARNING
        );
    }
    if ($action === enrolment_service::ACTION_UNENROL) {
        echo $OUTPUT->notification(
            get_string('warning_unenrol', 'local_gerenciamentomatriculas'),
            \core\output\notification::NOTIFY_WARNING
        );
    }

    echo html_writer::tag('button', get_string('submit', 'local_gerenciamentomatriculas'), [
        'type' => 'button',
        'id' => $buttonid,
        'class' => 'btn btn-primary',
        'disabled' => 'disabled',
    ]);
    echo ' ' . html_writer::link($pageurl, get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::tag('progress', '', [
        'id' => $progressid,
        'class' => 'd-none w-100 mt-3',
        'value' => 0,
        'max' => 1,
    ]);
    echo html_writer::div('', 'mt-2', ['id' => $statusid, 'aria-live' => 'polite']);
    echo html_writer::div('', 'mt-3', ['id' => $resultsid]);
    echo html_writer::div(
        html_writer::link($homeurl, get_string('backtomanagement', 'local_gerenciamentomatriculas'), [
            'class' => 'btn btn-primary mr-2',
        ]) . html_writer::link(
            new moodle_url('/local/gerenciamentomatriculas/selectuser.php'),
            get_string('startnewoperation', 'local_gerenciamentomatriculas'),
            ['class' => 'btn btn-secondary']
        ),
        'd-none mt-4',
        ['id' => $completionactionsid]
    );
    echo $OUTPUT->footer();
    exit;
}

$maxbatch = operation_guard::MAX_REMOVE_BATCH_SIZE;
$batchwarningjson = json_encode(get_string('safetyremovebatchclient', 'local_gerenciamentomatriculas', $maxbatch));
$PAGE->requires->js_init_code(<<<JS
require(['jquery'], function($) {
    var maxBatch = {$maxbatch};
    var batchWarning = {$batchwarningjson};
    var checks = $('.local-gerenciamentomatriculas-coursecheck:not(:disabled)');

    $('#local-gerenciamentomatriculas-selectall').on('change', function() {
        checks.prop('checked', false);
        if ($(this).prop('checked')) {
            checks.slice(0, maxBatch).prop('checked', true);
            if (checks.length > maxBatch) {
                window.alert(batchWarning);
            }
        }
    });
    checks.on('change', function() {
        if ($(this).prop('checked') && checks.filter(':checked').length > maxBatch) {
            $(this).prop('checked', false);
            window.alert(batchWarning);
        }
    });
});
JS);

echo $OUTPUT->header();
$tabs = [[
    new tabobject('remove', $pageurl, get_string('tabremove', 'local_gerenciamentomatriculas')),
    new tabobject('add', $addurl, get_string('tabadd', 'local_gerenciamentomatriculas')),
]];
echo html_writer::div(print_tabs($tabs, 'remove', null, null, true), 'mb-3');
echo html_writer::tag('h3', get_string('selectedstudentscount', 'local_gerenciamentomatriculas', count($users)));
echo local_gerenciamentomatriculas_render_users_table($users);
echo html_writer::link($homeurl, get_string('backtomanagement', 'local_gerenciamentomatriculas'), [
    'class' => 'btn btn-secondary mb-3',
]);
if (count($users) > 1) {
    echo $OUTPUT->notification(
        get_string('operationremove:bulknotice', 'local_gerenciamentomatriculas'),
        \core\output\notification::NOTIFY_WARNING
    );
}
echo html_writer::tag('p', get_string('removallimits', 'local_gerenciamentomatriculas', (object) [
    'enrolments' => $maxbatch,
    'students' => operation_guard::MAX_USER_BATCH_SIZE,
]), ['class' => 'text-muted']);

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
foreach ($userids as $userid) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userids[]', 'value' => $userid]);
}
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

$actionoptions = [
    enrolment_service::ACTION_UNENROL => get_string('action_unenrol', 'local_gerenciamentomatriculas'),
    enrolment_service::ACTION_SUSPEND => get_string('action_suspend', 'local_gerenciamentomatriculas'),
];
echo html_writer::div(
    html_writer::tag('label', get_string('action', 'local_gerenciamentomatriculas'), [
        'for' => 'local-gerenciamentomatriculas-action',
    ]) . html_writer::select($actionoptions, 'action', enrolment_service::ACTION_UNENROL, false, [
        'id' => 'local-gerenciamentomatriculas-action',
        'class' => 'form-control',
    ]),
    'form-group local-gerenciamentomatriculas-search-wrap'
);
echo html_writer::div(
    html_writer::tag('label',
        html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'suspendaccount', 'value' => 1])
        . ' ' . get_string('suspendaccountsselected', 'local_gerenciamentomatriculas')
    ),
    'form-group'
);

$table = new html_table();
$table->attributes['class'] = 'generaltable table table-striped';
$table->head = [
    html_writer::tag('label',
        html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'id' => 'local-gerenciamentomatriculas-selectall',
        ]) . ' ' . get_string('selectall', 'local_gerenciamentomatriculas')
    ),
    get_string('student', 'local_gerenciamentomatriculas'),
    get_string('course', 'local_gerenciamentomatriculas'),
    get_string('shortname', 'local_gerenciamentomatriculas'),
    get_string('enrolmethod', 'local_gerenciamentomatriculas'),
    get_string('enrolstatus', 'local_gerenciamentomatriculas'),
    get_string('actionsavailable', 'local_gerenciamentomatriculas'),
];
$hasenrolments = false;
foreach ($users as $userid => $user) {
    foreach ($enrolmentsbyuser[$userid] ?? [] as $enrolment) {
        $hasenrolments = true;
        $actions = [];
        if ($enrolment->canunenrol) {
            $actions[] = get_string('canunenrol', 'local_gerenciamentomatriculas');
        }
        if ($enrolment->canmanage) {
            $actions[] = get_string('canmanage', 'local_gerenciamentomatriculas');
        }
        if (empty($actions)) {
            $actions[] = get_string('notavailable', 'local_gerenciamentomatriculas');
        }
        $checkboxattrs = [
            'type' => 'checkbox',
            'name' => 'selections[]',
            'value' => $userid . ':' . $enrolment->userenrolmentid,
            'class' => 'local-gerenciamentomatriculas-coursecheck',
        ];
        if (!$enrolment->canunenrol && !$enrolment->canmanage) {
            $checkboxattrs['disabled'] = 'disabled';
        }
        $coursecontext = context_course::instance($enrolment->courseid);
        $courselink = html_writer::link(
            new moodle_url('/course/view.php', ['id' => $enrolment->courseid]),
            format_string($enrolment->fullname, true, ['context' => $coursecontext])
        );
        $status = ((int) $enrolment->userenrolmentstatus === ENROL_USER_SUSPENDED)
            ? get_string('suspended', 'local_gerenciamentomatriculas')
            : get_string('active', 'local_gerenciamentomatriculas');
        $managedwarning = !in_array($enrolment->enrol, ['manual', 'self'], true)
            ? html_writer::tag('div', get_string('managedexternally', 'local_gerenciamentomatriculas'), [
                'class' => 'text-muted small',
            ])
            : '';
        $table->data[] = [
            html_writer::empty_tag('input', $checkboxattrs),
            fullname($user),
            $courselink,
            s($enrolment->shortname),
            s($enrolment->enrol) . $managedwarning,
            $status,
            implode(', ', $actions),
        ];
    }
}
if ($hasenrolments) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nocoursesbulk', 'local_gerenciamentomatriculas'),
        \core\output\notification::NOTIFY_INFO);
}
echo html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('reviewremoval', 'local_gerenciamentomatriculas'),
    ]),
    'mt-3'
);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
