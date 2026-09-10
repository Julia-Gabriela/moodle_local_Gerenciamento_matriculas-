<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * External function declarations.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_gerenciamentomatriculas_search_users' => [
        'classname' => 'local_gerenciamentomatriculas\external\search_users',
        'description' => 'Returns a limited list of users for the enrolment management autocomplete.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/site:configview',
    ],
    'local_gerenciamentomatriculas_search_course_codes' => [
        'classname' => 'local_gerenciamentomatriculas\external\search_course_codes',
        'description' => 'Returns a limited list of course codes for the enrolment management autocomplete.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/site:configview',
    ],
    'local_gerenciamentomatriculas_enrol_user_batch' => [
        'classname' => 'local_gerenciamentomatriculas\external\enrol_user_batch',
        'description' => 'Enrols one user in a short, bounded course batch.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/site:configview',
    ],
    'local_gerenciamentomatriculas_process_enrolment_batch' => [
        'classname' => 'local_gerenciamentomatriculas\external\process_enrolment_batch',
        'description' => 'Processes one short, bounded removal or suspension batch.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/site:configview',
    ],
];
