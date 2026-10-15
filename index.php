<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Gerenciamento de matrículas feature chooser.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026 Júlia Gabriela Gomes da Silva
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/tablelib.php');

use local_gerenciamentomatriculas\local\operation_guard;

$legacyuserid = optional_param('userid', 0, PARAM_INT);
$userids = optional_param_array('userids', [], PARAM_INT);
if ($legacyuserid > 0) {
    array_unshift($userids, $legacyuserid);
}
$userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
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

$maxuserbatch = defined(operation_guard::class . '::MAX_USER_BATCH_SIZE')
    ? constant(operation_guard::class . '::MAX_USER_BATCH_SIZE')
    : 15;
$userbatcherror = null;
try {
    if (class_exists(operation_guard::class)
            && is_callable([operation_guard::class, 'validate_user_batch_size'])) {
        $userbatcherror = operation_guard::validate_user_batch_size($userids);
    } else {
        $userbatcherror = get_string('safetyguardmissing', 'local_gerenciamentomatriculas');
    }
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

[$userinsql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'selecteduser');
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

$primaryuser = reset($users);
$primaryuserid = (int) $primaryuser->id;
$multipleusers = count($users) > 1;
$pageurl = new moodle_url('/local/gerenciamentomatriculas/index.php');
$removeurl = new moodle_url('/local/gerenciamentomatriculas/remove.php', ['userid' => $primaryuserid]);
$selectuserurl = new moodle_url('/local/gerenciamentomatriculas/selectuser.php');

$PAGE->set_context($systemcontext);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('manage:title', 'local_gerenciamentomatriculas'));
$PAGE->set_heading(get_string('manage:title', 'local_gerenciamentomatriculas'));

try {
    $guardavailable = class_exists(operation_guard::class)
        && is_callable([operation_guard::class, 'preflight']);
    if ($guardavailable) {
        $adderrors = operation_guard::preflight(operation_guard::FEATURE_ADD);
        $removeerrors = operation_guard::preflight(operation_guard::FEATURE_REMOVE);
    } else {
        $adderrors = [get_string('safetyguardmissing', 'local_gerenciamentomatriculas')];
        $removeerrors = $adderrors;
    }
} catch (\Throwable $exception) {
    debugging($exception->getMessage(), DEBUG_DEVELOPER);
    $adderrors = [get_string('safetyguardmissing', 'local_gerenciamentomatriculas')];
    $removeerrors = $adderrors;
}

echo $OUTPUT->header();
echo html_writer::tag('h3', $multipleusers
    ? get_string('selectedstudentscount', 'local_gerenciamentomatriculas', count($users))
    : fullname($primaryuser));

echo local_gerenciamentomatriculas_render_users_table($users);

echo html_writer::tag('p', get_string(
    $multipleusers ? 'chooseoperationbulk' : 'chooseoperation',
    'local_gerenciamentomatriculas'
));
echo html_writer::start_div('row');

$addbody = html_writer::tag('h3', get_string('operationadd:title', 'local_gerenciamentomatriculas'), [
    'class' => 'h5 card-title',
]);
$addbody .= html_writer::tag('p', get_string(
    $multipleusers ? 'operationadd:descriptionbulk' : 'operationadd:description',
    'local_gerenciamentomatriculas',
    count($users)
), ['class' => 'card-text']);
if (empty($adderrors)) {
    $addbody .= html_writer::start_tag('form', [
        'method' => 'get',
        'action' => (new moodle_url('/local/gerenciamentomatriculas/enrol.php'))->out(false),
    ]);
    foreach (array_keys($users) as $userid) {
        $addbody .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'userids[]',
            'value' => $userid,
        ]);
    }
    $addbody .= html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('operationadd:button', 'local_gerenciamentomatriculas'),
    ]);
    $addbody .= html_writer::end_tag('form');
} else {
    $addbody .= $OUTPUT->notification(
        get_string('safetyfeatureblocked', 'local_gerenciamentomatriculas') . html_writer::alist($adderrors),
        \core\output\notification::NOTIFY_ERROR
    );
}
echo html_writer::div(
    html_writer::div($addbody, 'card-body'),
    'col-md-6 mb-3 card border local-gerenciamentomatriculas-action-card'
);

$removebody = html_writer::tag('h3', get_string('operationremove:title', 'local_gerenciamentomatriculas'), [
    'class' => 'h5 card-title',
]);
if ($multipleusers) {
    $removebody .= html_writer::tag('p', get_string('operationremove:descriptionbulk', 'local_gerenciamentomatriculas'), [
        'class' => 'card-text',
    ]);
    $removebody .= $OUTPUT->notification(
        get_string('operationremove:singleonly', 'local_gerenciamentomatriculas'),
        \core\output\notification::NOTIFY_WARNING
    );
    if (empty($removeerrors)) {
        $removebody .= html_writer::start_tag('form', [
            'method' => 'get',
            'action' => (new moodle_url('/local/gerenciamentomatriculas/remove.php'))->out(false),
        ]);
        foreach (array_keys($users) as $userid) {
            $removebody .= html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'userids[]',
                'value' => $userid,
            ]);
        }
        $removebody .= html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-secondary',
            'value' => get_string('operationremove:continuebulk', 'local_gerenciamentomatriculas'),
        ]);
        $removebody .= html_writer::end_tag('form');
    } else {
        $removebody .= $OUTPUT->notification(
            get_string('safetyfeatureblocked', 'local_gerenciamentomatriculas') . html_writer::alist($removeerrors),
            \core\output\notification::NOTIFY_ERROR
        );
    }
} else {
    $removebody .= html_writer::tag('p', get_string('operationremove:description', 'local_gerenciamentomatriculas'), [
        'class' => 'card-text',
    ]);
    if (empty($removeerrors)) {
        $removebody .= html_writer::link(
            $removeurl,
            get_string('operationremove:button', 'local_gerenciamentomatriculas'),
            ['class' => 'btn btn-secondary']
        );
    } else {
        $removebody .= $OUTPUT->notification(
            get_string('safetyfeatureblocked', 'local_gerenciamentomatriculas') . html_writer::alist($removeerrors),
            \core\output\notification::NOTIFY_ERROR
        );
    }
}
echo html_writer::div(
    html_writer::div($removebody, 'card-body'),
    'col-md-6 mb-3 card border local-gerenciamentomatriculas-action-card'
);

echo html_writer::end_div();
echo html_writer::link($selectuserurl, get_string('backtoselectusers', 'local_gerenciamentomatriculas'), [
    'class' => 'btn btn-secondary mr-2',
]);
echo $OUTPUT->footer();
