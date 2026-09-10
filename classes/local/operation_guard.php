<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Fail-safe guard for enrolment management operations.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gerenciamentomatriculas\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates dependencies and prevents concurrent or oversized operations.
 */
class operation_guard {
    /** Addition feature identifier. */
    public const FEATURE_ADD = 'add';

    /** Removal/suspension feature identifier. */
    public const FEATURE_REMOVE = 'remove';

    /**
     * Maximum number of selected courses in one confirmed operation.
     *
     * Keeping the batch deliberately small prevents long-running requests and
     * Gateway Timeout errors. Larger workloads must be submitted in chunks.
     */
    public const MAX_BATCH_SIZE = 20;

    /** Maximum number of courses selected for one multi-student addition. */
    public const MAX_ADD_COURSE_BATCH_SIZE = 60;

    /** Maximum number of enrolment removals/suspensions selected in one operation. */
    public const MAX_REMOVE_BATCH_SIZE = 300;

    /** Maximum number of students selected for the same enrolment operation. */
    public const MAX_USER_BATCH_SIZE = 15;

    /** Maximum number of enrolments executed by one internal AJAX request. */
    public const PROCESSING_CHUNK_SIZE = 15;

    /** Lock factory type. */
    private const LOCK_TYPE = 'local_gerenciamentomatriculas';

    /** Maximum lifetime supplied to lock backends, in seconds. */
    private const LOCK_MAX_LIFETIME = 120;

    /**
     * Runs a read-only dependency check before a feature is displayed or used.
     *
     * @param string $feature One of the FEATURE_* constants.
     * @return string[] Human-readable errors. Empty means ready.
     */
    public static function preflight(string $feature): array {
        global $CFG;

        $errors = [];
        if (!in_array($feature, [self::FEATURE_ADD, self::FEATURE_REMOVE], true)) {
            return [get_string('safetyinvalidfeature', 'local_gerenciamentomatriculas')];
        }

        try {
            $enrollib = $CFG->dirroot . '/lib/enrollib.php';
            if (!is_readable($enrollib)) {
                return [get_string('safetyenrollibmissing', 'local_gerenciamentomatriculas')];
            }

            require_once($enrollib);
            if (!function_exists('enrol_get_plugin')) {
                $errors[] = get_string('safetyenrolfunctionmissing', 'local_gerenciamentomatriculas');
            }

            if (!class_exists('\core\lock\lock_config')
                    || !is_callable(['\core\lock\lock_config', 'get_lock_factory'])) {
                $errors[] = get_string('safetylockmissing', 'local_gerenciamentomatriculas');
            }

            if ($feature === self::FEATURE_ADD) {
                $service = course_enrolment_service::class;
                if (!class_exists($service)
                        || !is_callable([$service, 'search_courses'])
                        || !is_callable([$service, 'get_course_by_id'])
                        || !is_callable([$service, 'search_courses_by_filters'])
                        || !is_callable([$service, 'search_courses_by_filters_for_users'])
                        || !is_callable([$service, 'enrol_in_courses'])) {
                    $errors[] = get_string('safetyaddservicemissing', 'local_gerenciamentomatriculas');
                }

                $batchprocessor = '\\local_gerenciamentomatriculas\\external\\enrol_user_batch';
                if (!class_exists($batchprocessor) || !is_callable([$batchprocessor, 'execute'])) {
                    $errors[] = get_string('safetybatchprocessormissing', 'local_gerenciamentomatriculas');
                }

                if (function_exists('enrol_get_plugin')) {
                    $manualplugin = enrol_get_plugin('manual');
                    if (!$manualplugin
                            || !is_callable([$manualplugin, 'enrol_user'])
                            || !is_callable([$manualplugin, 'allow_enrol'])) {
                        $errors[] = get_string('safetymanualmissing', 'local_gerenciamentomatriculas');
                    }
                }
            }

            if ($feature === self::FEATURE_REMOVE) {
                $service = enrolment_service::class;
                if (!class_exists($service)
                        || !is_callable([$service, 'get_user_enrolments'])
                        || !is_callable([$service, 'get_users_enrolments'])
                        || !is_callable([$service, 'process_selection'])) {
                    $errors[] = get_string('safetyremoveservicemissing', 'local_gerenciamentomatriculas');
                }

                $batchprocessor = '\\local_gerenciamentomatriculas\\external\\process_enrolment_batch';
                if (!class_exists($batchprocessor) || !is_callable([$batchprocessor, 'execute'])) {
                    $errors[] = get_string('safetyremoveprocessormissing', 'local_gerenciamentomatriculas');
                }
            }

            return array_values(array_unique($errors));
        } catch (\Throwable $exception) {
            debugging($exception->getMessage(), DEBUG_DEVELOPER);
            return [get_string('safetypreflightfailed', 'local_gerenciamentomatriculas')];
        }
    }

    /**
     * Validates the size of a requested batch without truncating it silently.
     *
     * @param array $ids Selected ids.
     * @return string|null Error message, or null when accepted.
     */
    public static function validate_batch_size(array $ids): ?string {
        $count = count(array_unique(array_filter(array_map('intval', $ids))));
        if ($count < 1) {
            return get_string('safetynoselection', 'local_gerenciamentomatriculas');
        }
        if ($count > self::MAX_BATCH_SIZE) {
            return get_string('safetybatchlimit', 'local_gerenciamentomatriculas', self::MAX_BATCH_SIZE);
        }

        return null;
    }

    /**
     * Validates the larger course selection accepted by the chunked add flow.
     *
     * @param array $courseids Selected course ids.
     * @return string|null Error message, or null when accepted.
     */
    public static function validate_add_course_batch_size(array $courseids): ?string {
        $count = count(array_unique(array_filter(array_map('intval', $courseids))));
        if ($count < 1) {
            return get_string('safetynoselection', 'local_gerenciamentomatriculas');
        }
        if ($count > self::MAX_ADD_COURSE_BATCH_SIZE) {
            return get_string(
                'safetybatchlimit',
                'local_gerenciamentomatriculas',
                self::MAX_ADD_COURSE_BATCH_SIZE
            );
        }

        return null;
    }

    /**
     * Validates the number of removals/suspensions selected across all students.
     *
     * @param array $ids Selected user_enrolments ids.
     * @return string|null Error message, or null when accepted.
     */
    public static function validate_remove_batch_size(array $ids): ?string {
        $count = count(array_unique(array_filter(array_map('intval', $ids))));
        if ($count < 1) {
            return get_string('safetynoselection', 'local_gerenciamentomatriculas');
        }
        if ($count > self::MAX_REMOVE_BATCH_SIZE) {
            return get_string(
                'safetyremovebatchlimit',
                'local_gerenciamentomatriculas',
                self::MAX_REMOVE_BATCH_SIZE
            );
        }

        return null;
    }

    /**
     * Validates how many students will receive the same selected courses.
     *
     * @param array $userids Selected user ids.
     * @return string|null Error message, or null when accepted.
     */
    public static function validate_user_batch_size(array $userids): ?string {
        $count = count(array_unique(array_filter(array_map('intval', $userids))));
        if ($count < 1) {
            return get_string('safetynouserselection', 'local_gerenciamentomatriculas');
        }
        if ($count > self::MAX_USER_BATCH_SIZE) {
            return get_string('safetyuserbatchlimit', 'local_gerenciamentomatriculas', self::MAX_USER_BATCH_SIZE);
        }

        return null;
    }

    /**
     * Validates one short processing request used by the browser queue.
     *
     * @param array $courseids Course ids in this internal request.
     * @return string|null Error message, or null when accepted.
     */
    public static function validate_processing_chunk(array $courseids): ?string {
        $count = count(array_unique(array_filter(array_map('intval', $courseids))));
        if ($count < 1) {
            return get_string('safetynoselection', 'local_gerenciamentomatriculas');
        }
        if ($count > self::PROCESSING_CHUNK_SIZE) {
            return get_string(
                'safetyprocessingchunklimit',
                'local_gerenciamentomatriculas',
                self::PROCESSING_CHUNK_SIZE
            );
        }

        return null;
    }

    /**
     * Obtains a non-blocking per-user lock.
     *
     * This method never waits for another operation. It either returns a lock
     * immediately or reports a controlled error.
     *
     * @param int $userid Target user id.
     * @return array{factory: mixed, lock: mixed, error: string|null}
     */
    public static function acquire_user_lock(int $userid): array {
        try {
            if (!class_exists('\core\lock\lock_config')
                    || !is_callable(['\core\lock\lock_config', 'get_lock_factory'])) {
                return ['factory' => null, 'lock' => null, 'error' => get_string(
                    'safetylockmissing',
                    'local_gerenciamentomatriculas'
                )];
            }

            $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
            if (!$factory || !is_callable([$factory, 'get_lock'])) {
                return ['factory' => null, 'lock' => null, 'error' => get_string(
                    'safetylockmissing',
                    'local_gerenciamentomatriculas'
                )];
            }

            if (is_callable([$factory, 'is_available']) && !$factory->is_available()) {
                return ['factory' => null, 'lock' => null, 'error' => get_string(
                    'safetylockunavailable',
                    'local_gerenciamentomatriculas'
                )];
            }

            $lock = $factory->get_lock('userid_' . $userid, 0, self::LOCK_MAX_LIFETIME);
            if (!$lock) {
                return ['factory' => null, 'lock' => null, 'error' => get_string(
                    'safetyoperationrunning',
                    'local_gerenciamentomatriculas'
                )];
            }

            return ['factory' => $factory, 'lock' => $lock, 'error' => null];
        } catch (\Throwable $exception) {
            debugging($exception->getMessage(), DEBUG_DEVELOPER);
            return ['factory' => null, 'lock' => null, 'error' => get_string(
                'safetylockerror',
                'local_gerenciamentomatriculas'
            )];
        }
    }

    /**
     * Releases a lock without allowing cleanup errors to break the response.
     *
     * @param mixed $factory Lock factory.
     * @param mixed $lock Lock object.
     * @return void
     */
    public static function release_user_lock($factory, $lock): void {
        if (!$factory || !$lock || !is_callable([$factory, 'release_lock'])) {
            return;
        }

        try {
            $factory->release_lock($lock);
        } catch (\Throwable $exception) {
            debugging($exception->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
