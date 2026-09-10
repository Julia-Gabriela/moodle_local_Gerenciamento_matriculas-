<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Adds several students to multiple courses selected by class code.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/tablelib.php');

use local_gerenciamentomatriculas\local\course_enrolment_service;
use local_gerenciamentomatriculas\local\operation_guard;

$legacyuserid = optional_param('userid', 0, PARAM_INT);
$userids = optional_param_array('userids', [], PARAM_INT);
if ($legacyuserid > 0) {
    array_unshift($userids, $legacyuserid);
}
$userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
$courseselections = optional_param_array('courseselections', [], PARAM_TEXT);
$legacycourseselection = optional_param('courseselection', '', PARAM_TEXT);
$selectedcode = optional_param('coursecode', '', PARAM_TEXT);
$query = optional_param('q', '', PARAM_TEXT);
$query = trim($query);
if (empty($courseselections) && $legacycourseselection !== '') {
    // Compatibility with the single autocomplete used by version 1.0.3.
    $courseselections[] = $legacycourseselection;
} else if (empty($courseselections) && $selectedcode !== '') {
    // Compatibility with the autocomplete value used by version 1.0.2.
    $courseselections[] = 'all:' . trim($selectedcode);
} else if (empty($courseselections) && $query !== '') {
    // Standard text-search fallback when JavaScript or AJAX is unavailable.
    $courseselections[] = 'all:' . $query;
}

$normalizedselections = [];
$groupqueries = [];
$specificcourseids = [];
$seenselections = [];
foreach ($courseselections as $courseselection) {
    $courseselection = trim((string) $courseselection);
    if (strpos($courseselection, 'all:') === 0) {
        $groupquery = trim(substr($courseselection, 4));
        if ($groupquery === '') {
            continue;
        }
        $selectionkey = 'all:' . core_text::strtolower($groupquery);
        if (!isset($seenselections[$selectionkey])) {
            $seenselections[$selectionkey] = true;
            $normalizedselections[] = 'all:' . $groupquery;
            $groupqueries[] = $groupquery;
        }
    } else if (strpos($courseselection, 'course:') === 0) {
        $courseidvalue = substr($courseselection, 7);
        if ($courseidvalue === '' || !ctype_digit($courseidvalue) || (int) $courseidvalue <= 0) {
            continue;
        }
        $courseid = (int) $courseidvalue;
        $selectionkey = 'course:' . $courseid;
        if (!isset($seenselections[$selectionkey])) {
            $seenselections[$selectionkey] = true;
            $normalizedselections[] = $selectionkey;
            $specificcourseids[] = $courseid;
        }
    }
}
$courseselections = $normalizedselections;
$selected = optional_param_array('courses', [], PARAM_INT);

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
$maxuserbatch = defined(operation_guard::class . '::MAX_USER_BATCH_SIZE')
    ? constant(operation_guard::class . '::MAX_USER_BATCH_SIZE')
    : 15;
if (count($userids) > $maxuserbatch) {
    redirect(
        new moodle_url('/local/gerenciamentomatriculas/enrol.php', ['userid' => reset($userids)]),
        $userbatcherror ?? get_string('safetyuserbatchlimit', 'local_gerenciamentomatriculas', $maxuserbatch),
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
    '*'
);
$users = [];
foreach ($userids as $userid) {
    if (!isset($userrecords[$userid]) || isguestuser($userrecords[$userid])) {
        throw new moodle_exception('invaliduser', 'local_gerenciamentomatriculas');
    }
    $users[$userid] = $userrecords[$userid];
}
$pageurl = new moodle_url('/local/gerenciamentomatriculas/enrol.php');
$removeurl = new moodle_url('/local/gerenciamentomatriculas/remove.php', ['userids' => $userids]);
$homeurl = new moodle_url('/local/gerenciamentomatriculas/index.php', ['userids' => $userids]);

$PAGE->set_context($systemcontext);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('addenrolments:title', 'local_gerenciamentomatriculas'));
$PAGE->set_heading(get_string('manage:title', 'local_gerenciamentomatriculas'));
$coursecodeselectid = 'local-gerenciamentomatriculas-coursecode-selector';
$coursecodefallbackid = 'local-gerenciamentomatriculas-coursecode-fallback';
$coursecodeautocompleteregionid = 'local-gerenciamentomatriculas-coursecode-autocomplete';
$PAGE->requires->js_call_amd('local_gerenciamentomatriculas/course_code_selector', 'init', [
    $coursecodeselectid,
    $coursecodefallbackid,
    $coursecodeautocompleteregionid,
    get_string('coursecodeplaceholder', 'local_gerenciamentomatriculas'),
    get_string('nocoursecodeselected', 'local_gerenciamentomatriculas'),
]);

try {
    $guardavailable = class_exists(operation_guard::class)
        && is_callable([operation_guard::class, 'preflight'])
        && is_callable([operation_guard::class, 'validate_batch_size'])
        && is_callable([operation_guard::class, 'validate_add_course_batch_size'])
        && is_callable([operation_guard::class, 'validate_user_batch_size'])
        && is_callable([operation_guard::class, 'validate_processing_chunk'])
        && is_callable([operation_guard::class, 'acquire_user_lock'])
        && is_callable([operation_guard::class, 'release_user_lock']);
    $preflighterrors = $guardavailable
        ? operation_guard::preflight(operation_guard::FEATURE_ADD)
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

/**
 * Renders the plugin tabs.
 *
 * @param moodle_url $removeurl Remove/suspend URL.
 * @param moodle_url $addurl Add URL.
 * @param string $active Active tab name.
 * @return string
 */
function local_gerenciamentomatriculas_render_tabs(moodle_url $removeurl, moodle_url $addurl, string $active): string {
    $tabs = [
        new tabobject(
            'remove',
            $removeurl,
            get_string('tabremove', 'local_gerenciamentomatriculas')
        ),
        new tabobject(
            'add',
            $addurl,
            get_string('tabadd', 'local_gerenciamentomatriculas')
        ),
    ];

    return html_writer::div(print_tabs([$tabs], $active, null, null, true), 'mb-3');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $selected = array_values(array_unique(array_filter(array_map('intval', $selected))));

    if ($userbatcherror !== null) {
        redirect(
            new moodle_url('/local/gerenciamentomatriculas/enrol.php', ['userids' => $userids]),
            $userbatcherror,
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    if (empty($selected)) {
        redirect(
            new moodle_url('/local/gerenciamentomatriculas/enrol.php', ['userids' => $userids]),
            get_string('noselectionadd', 'local_gerenciamentomatriculas'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    $batcherror = operation_guard::validate_add_course_batch_size($selected);
    if ($batcherror !== null) {
        redirect(
            new moodle_url('/local/gerenciamentomatriculas/enrol.php', ['userids' => $userids]),
            $batcherror,
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    [$insql, $params] = $DB->get_in_or_equal($selected, SQL_PARAMS_NAMED, 'confirmcourse');
    try {
        $courses = $DB->get_records_select(
            'course',
            "id {$insql} AND id <> :siteid",
            $params + ['siteid' => SITEID],
            'fullname ASC',
            'id,fullname,shortname'
        );
    } catch (\Throwable $exception) {
        debugging($exception->getMessage(), DEBUG_DEVELOPER);
        redirect(
            new moodle_url('/local/gerenciamentomatriculas/enrol.php', ['userids' => $userids]),
            get_string('safetyreadfailed', 'local_gerenciamentomatriculas'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    if (count($courses) !== count($selected)) {
        redirect(
            new moodle_url('/local/gerenciamentomatriculas/enrol.php', ['userids' => $userids]),
            get_string('safetyreadfailed', 'local_gerenciamentomatriculas'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $studentconfig = [];
    foreach ($users as $selecteduser) {
        $studentconfig[] = [
            'id' => (int) $selecteduser->id,
            'name' => fullname($selecteduser),
        ];
    }
    $processorstrings = [
        'processing' => get_string('bulkprocessing', 'local_gerenciamentomatriculas'),
        'completed' => get_string('bulkcompleted', 'local_gerenciamentomatriculas'),
        'success' => get_string('summarysuccessadd', 'local_gerenciamentomatriculas'),
        'skipped' => get_string('summaryskippedadd', 'local_gerenciamentomatriculas'),
        'errors' => get_string('summaryerrors', 'local_gerenciamentomatriculas'),
        'transporterror' => get_string('bulktransporterror', 'local_gerenciamentomatriculas'),
        'interrupted' => get_string('bulkinterrupted', 'local_gerenciamentomatriculas'),
        'keepopen' => get_string('bulkkeepopen', 'local_gerenciamentomatriculas'),
    ];
    $processorbuttonid = 'local-gerenciamentomatriculas-confirm-bulk';
    $progressid = 'local-gerenciamentomatriculas-bulk-progress';
    $statusid = 'local-gerenciamentomatriculas-bulk-status';
    $resultsid = 'local-gerenciamentomatriculas-bulk-results';
    $completionactionsid = 'local-gerenciamentomatriculas-bulk-completion-actions';
    $PAGE->requires->js_call_amd('local_gerenciamentomatriculas/bulk_enrolment_processor', 'init', [
        $processorbuttonid,
        $progressid,
        $statusid,
        $resultsid,
        $completionactionsid,
        $studentconfig,
        $selected,
        operation_guard::PROCESSING_CHUNK_SIZE,
        $processorstrings,
    ]);

    echo $OUTPUT->header();
    echo local_gerenciamentomatriculas_render_tabs($removeurl, $pageurl, 'add');
    echo $OUTPUT->heading(get_string('confirmationadd', 'local_gerenciamentomatriculas'));
    $confirmdata = (object) [
        'students' => count($users),
        'courses' => count($selected),
        'operations' => count($users) * count($selected),
    ];
    echo $OUTPUT->notification(
        get_string('confirmaddbulktext', 'local_gerenciamentomatriculas', $confirmdata),
        \core\output\notification::NOTIFY_INFO
    );
    echo html_writer::tag('h4', get_string('selectedstudents', 'local_gerenciamentomatriculas'));
    echo local_gerenciamentomatriculas_render_users_table($users);

    echo html_writer::tag('h4', get_string('selectedcourses', 'local_gerenciamentomatriculas'));
    $coursetable = new html_table();
    $coursetable->attributes['class'] = 'generaltable table table-striped';
    $coursetable->head = [
        get_string('course', 'local_gerenciamentomatriculas'),
        get_string('shortname', 'local_gerenciamentomatriculas'),
    ];
    foreach ($selected as $courseid) {
        $coursecontext = context_course::instance($courseid);
        $coursetable->data[] = [
            format_string($courses[$courseid]->fullname, true, ['context' => $coursecontext]),
            s($courses[$courseid]->shortname),
        ];
    }
    echo html_writer::table($coursetable);

    echo $OUTPUT->notification(
        get_string('bulkprocessingwarning', 'local_gerenciamentomatriculas'),
        \core\output\notification::NOTIFY_WARNING
    );
    echo html_writer::tag('button', get_string('confirmenrol', 'local_gerenciamentomatriculas'), [
        'type' => 'button',
        'id' => $processorbuttonid,
        'class' => 'btn btn-primary',
        'disabled' => 'disabled',
    ]);
    echo ' ' . html_writer::link(
        new moodle_url('/local/gerenciamentomatriculas/enrol.php', ['userids' => $userids]),
        get_string('cancel'),
        ['class' => 'btn btn-secondary']
    );
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

$searchresult = ['courses' => [], 'truncated' => false];
$searcherror = null;
$hassearch = !empty($courseselections);
$hastooshortquery = false;
foreach ($groupqueries as $groupquery) {
    if (core_text::strlen($groupquery) < course_enrolment_service::MIN_SEARCH_LENGTH) {
        $hastooshortquery = true;
        break;
    }
}
if ($userbatcherror !== null) {
    $searcherror = $userbatcherror;
} else if (count($courseselections) > course_enrolment_service::MAX_SEARCH_FILTERS) {
    $searcherror = get_string(
        'searchfilterlimit',
        'local_gerenciamentomatriculas',
        course_enrolment_service::MAX_SEARCH_FILTERS
    );
} else if ($hastooshortquery) {
    $searcherror = get_string('searchcoursetooshort', 'local_gerenciamentomatriculas');
} else if ($hassearch) {
    try {
        $searchresult = course_enrolment_service::search_courses_by_filters_for_users(
            $groupqueries,
            $specificcourseids,
            $userids
        );
    } catch (\Throwable $exception) {
        debugging($exception->getMessage(), DEBUG_DEVELOPER);
        $searcherror = get_string('safetyreadfailed', 'local_gerenciamentomatriculas');
    }
}

$maxbatch = operation_guard::MAX_ADD_COURSE_BATCH_SIZE;
$batchwarningjson = json_encode(get_string('safetybatchclient', 'local_gerenciamentomatriculas', $maxbatch));
$PAGE->requires->js_init_code(<<<JS
require(['jquery'], function($) {
    var maxBatch = {$maxbatch};
    var batchWarning = {$batchwarningjson};
    var checks = $('.local-gerenciamentomatriculas-addcheck:not(:disabled)');

    $('#local-gerenciamentomatriculas-selectall-add').on('change', function() {
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
echo local_gerenciamentomatriculas_render_tabs($removeurl, $pageurl, 'add');
echo html_writer::tag('h3', get_string(
    'selectedstudentscount',
    'local_gerenciamentomatriculas',
    count($users)
));

$hassuspendedstudent = false;
foreach ($users as $selecteduser) {
    $hassuspendedstudent = $hassuspendedstudent || !empty($selecteduser->suspended);
}
echo local_gerenciamentomatriculas_render_users_table($users);

if ($hassuspendedstudent) {
    echo $OUTPUT->notification(
        get_string('suspendedaccountaddwarning', 'local_gerenciamentomatriculas'),
        \core\output\notification::NOTIFY_WARNING
    );
}

echo html_writer::link($homeurl, get_string('backtomanagement', 'local_gerenciamentomatriculas'), [
    'class' => 'btn btn-secondary mb-3',
]);

echo html_writer::tag('p', get_string(
    'operationlimits',
    'local_gerenciamentomatriculas',
    (object) ['courses' => operation_guard::MAX_ADD_COURSE_BATCH_SIZE, 'students' => $maxuserbatch]
), ['class' => 'text-muted']);
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $pageurl->out(false),
    'class' => 'mb-4 local-gerenciamentomatriculas-search-wrap',
]);
foreach ($userids as $selecteduserid) {
    echo html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'userids[]',
        'value' => $selecteduserid,
    ]);
}

echo html_writer::start_div('form-group d-none local-gerenciamentomatriculas-autocomplete', [
    'id' => $coursecodeautocompleteregionid,
]);
echo html_writer::tag('label', get_string('coursecode', 'local_gerenciamentomatriculas'), [
    'for' => $coursecodeselectid,
]);
$coursesbyid = [];
foreach ($searchresult['courses'] as $course) {
    $coursesbyid[(int) $course->id] = $course;
}
$coursecodeoptions = [];
foreach ($courseselections as $courseselection) {
    if (strpos($courseselection, 'all:') === 0) {
        $coursecodeoptions[$courseselection] = trim(substr($courseselection, 4));
        continue;
    }

    $courseid = (int) substr($courseselection, 7);
    if (!isset($coursesbyid[$courseid])) {
        $coursecodeoptions[$courseselection] = get_string(
            'courseidfallback',
            'local_gerenciamentomatriculas',
            $courseid
        );
        continue;
    }

    $selectedcourse = $coursesbyid[$courseid];
    $selectedcontext = context_course::instance($courseid);
    $selectedname = trim(strip_tags(format_string($selectedcourse->fullname, true, [
        'context' => $selectedcontext,
        'escape' => false,
    ])));
    $selectedcoursecode = trim((string) ($selectedcourse->idnumber ?? ''));
    if ($selectedcoursecode === '') {
        $selectedcoursecode = trim((string) ($selectedcourse->shortname ?? ''));
    }
    $coursecodeoptions[$courseselection] = $selectedcoursecode === ''
        ? $selectedname
        : $selectedcoursecode . ' - ' . $selectedname;
}
echo html_writer::select(
    $coursecodeoptions,
    'courseselections[]',
    $courseselections,
    [],
    [
        'id' => $coursecodeselectid,
        'class' => 'custom-select',
        'multiple' => 'multiple',
    ]
);
echo html_writer::end_div();

echo html_writer::div(
    html_writer::tag('label', get_string('coursecode', 'local_gerenciamentomatriculas'), [
        'for' => 'local-gerenciamentomatriculas-coursecode',
    ]) . html_writer::empty_tag('input', [
        'type' => 'text',
        'id' => 'local-gerenciamentomatriculas-coursecode',
        'name' => 'q',
        'value' => $query,
        'class' => 'form-control',
        'placeholder' => get_string('coursecodeplaceholder', 'local_gerenciamentomatriculas'),
        'autocomplete' => 'off',
    ]),
    'form-group',
    ['id' => $coursecodefallbackid]
);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('searchcourses', 'local_gerenciamentomatriculas'),
]);
echo html_writer::end_tag('form');

if ($hassearch) {
    if ($searcherror !== null) {
        echo $OUTPUT->notification($searcherror, \core\output\notification::NOTIFY_ERROR);
    } else if (empty($searchresult['courses'])) {
        echo $OUTPUT->notification(
            get_string('nocoursesfound', 'local_gerenciamentomatriculas'),
            \core\output\notification::NOTIFY_INFO
        );
    } else {
        if ($searchresult['truncated']) {
            echo $OUTPUT->notification(
                get_string('courseresultstruncated', 'local_gerenciamentomatriculas', course_enrolment_service::SEARCH_LIMIT),
                \core\output\notification::NOTIFY_WARNING
            );
        }

        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
        foreach ($userids as $selecteduserid) {
            echo html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'userids[]',
                'value' => $selecteduserid,
            ]);
        }
        foreach ($courseselections as $courseselection) {
            echo html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'courseselections[]',
                'value' => $courseselection,
            ]);
        }
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

        $table = new html_table();
        $table->attributes['class'] = 'generaltable table table-striped';
        $table->head = [
            html_writer::tag('label',
                html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'id' => 'local-gerenciamentomatriculas-selectall-add',
                ]) . ' ' . get_string('selectall', 'local_gerenciamentomatriculas')
            ),
            get_string('course', 'local_gerenciamentomatriculas'),
            get_string('shortname', 'local_gerenciamentomatriculas'),
            get_string('idnumber', 'local_gerenciamentomatriculas'),
            get_string('category', 'local_gerenciamentomatriculas'),
            get_string('visibility', 'local_gerenciamentomatriculas'),
            get_string('enrolstatus', 'local_gerenciamentomatriculas'),
        ];

        foreach ($searchresult['courses'] as $course) {
            $courseid = (int) $course->id;
            $coursecontext = context_course::instance($courseid);
            $checkboxattrs = [
                'type' => 'checkbox',
                'name' => 'courses[]',
                'value' => $courseid,
                'class' => 'local-gerenciamentomatriculas-addcheck',
            ];
            if (!$course->canselect) {
                $checkboxattrs['disabled'] = 'disabled';
            }

            if (empty($course->manualinstanceid)) {
                $status = get_string('nomanualinstance', 'local_gerenciamentomatriculas');
            } else if ((int) $course->existingcount >= (int) $course->selectedusercount) {
                $status = get_string('allstudentsalreadyenrolled', 'local_gerenciamentomatriculas');
            } else if ((int) $course->existingcount > 0) {
                $status = get_string('somestudentsalreadyenrolled', 'local_gerenciamentomatriculas', (object) [
                    'existing' => (int) $course->existingcount,
                    'total' => (int) $course->selectedusercount,
                ]);
            } else {
                $status = get_string('availabletoenrolall', 'local_gerenciamentomatriculas');
            }

            $courselink = html_writer::link(
                new moodle_url('/course/view.php', ['id' => $courseid]),
                format_string($course->fullname, true, ['context' => $coursecontext])
            );

            $table->data[] = [
                html_writer::empty_tag('input', $checkboxattrs),
                $courselink,
                s($course->shortname),
                s($course->idnumber ?? ''),
                s($course->categoryname ?? ''),
                !empty($course->visible)
                    ? get_string('visible', 'local_gerenciamentomatriculas')
                    : get_string('hidden', 'local_gerenciamentomatriculas'),
                $status,
            ];
        }

        echo html_writer::table($table);
        echo html_writer::div(
            html_writer::empty_tag('input', [
                'type' => 'submit',
                'class' => 'btn btn-primary',
                'value' => get_string('reviewenrolment', 'local_gerenciamentomatriculas'),
            ]),
            'mt-3'
        );
        echo html_writer::end_tag('form');
    }
}

echo $OUTPUT->footer();
