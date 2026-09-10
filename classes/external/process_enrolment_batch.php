<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Processes one short removal/suspension batch.
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
use local_gerenciamentomatriculas\local\enrolment_service;
use local_gerenciamentomatriculas\local\operation_guard;

/**
 * Applies a bounded removal/suspension request to one student.
 */
final class process_enrolment_batch extends external_api {
    /** @return external_function_parameters */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Target user id'),
            'enrolmentids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'User enrolment id')
            ),
            'action' => new external_value(PARAM_ALPHA, 'unenrol or suspend'),
            'suspendaccount' => new external_value(PARAM_BOOL, 'Suspend the user account'),
        ]);
    }

    /**
     * @param int $userid Target user id.
     * @param int[] $enrolmentids User enrolment ids.
     * @param string $action Requested action.
     * @param bool $suspendaccount Whether to suspend the account.
     * @return array{success: string[], errors: string[]}
     */
    public static function execute(
        int $userid,
        array $enrolmentids,
        string $action,
        bool $suspendaccount
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'enrolmentids' => $enrolmentids,
            'action' => $action,
            'suspendaccount' => $suspendaccount,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:configview', $context);

        $ids = array_values(array_unique(array_filter(array_map('intval', $params['enrolmentids']))));
        if (count($ids) > operation_guard::PROCESSING_CHUNK_SIZE) {
            return [
                'success' => [],
                'errors' => [get_string(
                    'safetyprocessingchunklimit',
                    'local_gerenciamentomatriculas',
                    operation_guard::PROCESSING_CHUNK_SIZE
                )],
            ];
        }
        if (empty($ids) && empty($params['suspendaccount'])) {
            return [
                'success' => [],
                'errors' => [get_string('safetynoselection', 'local_gerenciamentomatriculas')],
            ];
        }

        $user = \core_user::get_user((int) $params['userid'], 'id,username,deleted', MUST_EXIST);
        if (!empty($user->deleted) || isguestuser($user)) {
            return [
                'success' => [],
                'errors' => [get_string('invaliduser', 'local_gerenciamentomatriculas')],
            ];
        }

        $preflighterrors = operation_guard::preflight(operation_guard::FEATURE_REMOVE);
        if (!empty($preflighterrors)) {
            return ['success' => [], 'errors' => $preflighterrors];
        }

        $lockdata = operation_guard::acquire_user_lock((int) $params['userid']);
        if (!empty($lockdata['error'])) {
            return ['success' => [], 'errors' => [$lockdata['error']]];
        }

        try {
            return enrolment_service::process_selection(
                (int) $params['userid'],
                $ids,
                $params['action'],
                (bool) $params['suspendaccount']
            );
        } catch (\Throwable $exception) {
            debugging($exception->getMessage(), DEBUG_DEVELOPER);
            return [
                'success' => [],
                'errors' => [get_string('safetyoperationfailed', 'local_gerenciamentomatriculas')],
            ];
        } finally {
            operation_guard::release_user_lock($lockdata['factory'], $lockdata['lock']);
        }
    }

    /** @return external_single_structure */
    public static function execute_returns(): external_single_structure {
        $messages = static fn(string $description): external_multiple_structure =>
            new external_multiple_structure(new external_value(PARAM_RAW, $description));

        return new external_single_structure([
            'success' => $messages('Successful operation messages'),
            'errors' => $messages('Controlled error messages'),
        ]);
    }
}
