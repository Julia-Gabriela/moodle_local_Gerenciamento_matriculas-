<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX course-code suggestions for enrolment management.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026 Júlia Gabriela Gomes da Silva
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gerenciamentomatriculas\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_gerenciamentomatriculas\local\course_search;

/**
 * Returns a small, permission-protected list of matching course codes.
 */
final class search_course_codes extends external_api {
    /**
     * Describes the method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Partial course code'),
        ]);
    }

    /**
     * Searches course codes without changing enrolments.
     *
     * @param string $query Partial course code.
     * @return array<int, array{value: string, label: string}>
     */
    public static function execute(string $query): array {
        $params = self::validate_parameters(self::execute_parameters(), ['query' => $query]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:configview', $context);

        return course_search::suggest_codes($params['query']);
    }

    /**
     * Describes the method return value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'value' => new external_value(PARAM_TEXT, 'Encoded grouped search or exact course selection'),
                'label' => new external_value(PARAM_RAW, 'Escaped code and course label'),
            ])
        );
    }
}
