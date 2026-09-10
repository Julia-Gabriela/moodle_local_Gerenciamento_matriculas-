<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Processes one short, permission-protected enrolment batch.
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
use local_gerenciamentomatriculas\local\course_enrolment_service;
use local_gerenciamentomatriculas\local\operation_guard;

/**
 * Enrols one student in a small course chunk used by the browser queue.
 */
final class enrol_user_batch extends external_api {
    /**
     * Describes the method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Target user id'),
            'courseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course id')
            ),
        ]);
    }

    /**
     * Processes one bounded chunk without waiting for another request lock.
     *
     * @param int $userid Target user id.
     * @param int[] $courseids Course ids.
     * @return array{success: string[], skipped: string[], errors: string[]}
     */
    public static function execute(int $userid, array $courseids): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'courseids' => $courseids,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:configview', $context);

        $guardavailable = class_exists(operation_guard::class)
            && is_callable([operation_guard::class, 'validate_processing_chunk'])
            && is_callable([operation_guard::class, 'preflight'])
            && is_callable([operation_guard::class, 'acquire_user_lock'])
            && is_callable([operation_guard::class, 'release_user_lock']);
        if (!$guardavailable) {
            return [
                'success' => [],
                'skipped' => [],
                'errors' => [get_string('safetyguardmissing', 'local_gerenciamentomatriculas')],
            ];
        }

        $courseids = array_values(array_unique(array_filter(array_map('intval', $params['courseids']))));
        $chunkerror = operation_guard::validate_processing_chunk($courseids);
        if ($chunkerror !== null) {
            return ['success' => [], 'skipped' => [], 'errors' => [$chunkerror]];
        }

        $preflighterrors = operation_guard::preflight(operation_guard::FEATURE_ADD);
        if (!empty($preflighterrors)) {
            return ['success' => [], 'skipped' => [], 'errors' => $preflighterrors];
        }

        $lockdata = operation_guard::acquire_user_lock((int) $params['userid']);
        if (!empty($lockdata['error'])) {
            return ['success' => [], 'skipped' => [], 'errors' => [$lockdata['error']]];
        }

        try {
            return course_enrolment_service::enrol_in_courses((int) $params['userid'], $courseids);
        } catch (\Throwable $exception) {
            debugging($exception->getMessage(), DEBUG_DEVELOPER);
            return [
                'success' => [],
                'skipped' => [],
                'errors' => [get_string('safetyoperationfailed', 'local_gerenciamentomatriculas')],
            ];
        } finally {
            operation_guard::release_user_lock($lockdata['factory'], $lockdata['lock']);
        }
    }

    /**
     * Describes the returned per-course messages.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $messages = static fn(string $description): external_multiple_structure =>
            new external_multiple_structure(new external_value(PARAM_RAW, $description));

        return new external_single_structure([
            'success' => $messages('Successful enrolment messages'),
            'skipped' => $messages('Skipped enrolment messages'),
            'errors' => $messages('Controlled error messages'),
        ]);
    }
}
