<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX user search for the enrolment management selector.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gerenciamentomatriculas\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_gerenciamentomatriculas\local\user_search;

/**
 * Returns a small, permission-protected list of matching users.
 */
final class search_users extends external_api {
    /**
     * Describes the method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Name, username/ID or email'),
        ]);
    }

    /**
     * Searches for users available to the current administrator.
     *
     * @param string $query Search text.
     * @return array<int, array<string, int|string>>
     */
    public static function execute(string $query): array {
        $params = self::validate_parameters(self::execute_parameters(), ['query' => $query]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:configview', $context);

        $users = user_search::find($params['query'], user_search::AUTOCOMPLETE_LIMIT);
        $results = [];
        foreach ($users as $user) {
            $label = fullname($user) . ' — ' . $user->username;
            if ($user->email !== '') {
                $label .= ' — ' . $user->email;
            }
            if (!empty($user->suspended)) {
                $label .= ' (' . get_string('accountsuspended', 'local_gerenciamentomatriculas') . ')';
            }

            $results[] = [
                'value' => (int) $user->id,
                // Core autocomplete inserts labels into markup, so escape user-controlled identity fields.
                'label' => s($label),
            ];
        }

        return $results;
    }

    /**
     * Describes the method return value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'value' => new external_value(PARAM_INT, 'User ID'),
                'label' => new external_value(PARAM_RAW, 'Escaped user identity label'),
            ])
        );
    }
}
