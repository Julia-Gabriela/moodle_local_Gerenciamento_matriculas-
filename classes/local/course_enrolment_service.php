<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Bulk course enrolment service.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gerenciamentomatriculas\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Finds courses by class code and enrols one user in selected courses.
 */
class course_enrolment_service {
    /** Minimum number of characters accepted in a grouped code search. */
    public const MIN_SEARCH_LENGTH = 2;

    /** Maximum number of course results shown for one search. */
    public const SEARCH_LIMIT = 200;

    /** Maximum number of grouped codes and individual courses in one search. */
    public const MAX_SEARCH_FILTERS = 10;

    /**
     * Searches fullname, shortname and idnumber for the supplied code.
     *
     * @param string $query Course/class code.
     * @param int $userid Target user id.
     * @return array{courses: array, truncated: bool}
     */
    public static function search_courses(string $query, int $userid): array {
        return self::search_courses_by_filters([$query], [], $userid);
    }

    /**
     * Returns one exact course selected from the autocomplete list.
     *
     * @param int $courseid Course id.
     * @param int $userid Target user id.
     * @return array{courses: array, truncated: bool}
     */
    public static function get_course_by_id(int $courseid, int $userid): array {
        return self::search_courses_by_filters([], [$courseid], $userid);
    }

    /**
     * Searches multiple grouped codes and exact courses in one bounded query.
     *
     * A course that matches more than one filter is returned only once because
     * every condition is combined in a single query keyed by the course id.
     *
     * @param string[] $queries Grouped code searches.
     * @param int[] $courseids Exact course ids.
     * @param int $userid Target user id.
     * @return array{courses: array, truncated: bool}
     */
    public static function search_courses_by_filters(array $queries, array $courseids, int $userid): array {
        return self::search_courses_by_filters_for_users($queries, $courseids, [$userid]);
    }

    /**
     * Searches courses and evaluates availability for several selected students.
     *
     * @param string[] $queries Grouped code searches.
     * @param int[] $courseids Exact course ids.
     * @param int[] $userids Target user ids.
     * @return array{courses: array, truncated: bool}
     */
    public static function search_courses_by_filters_for_users(
        array $queries,
        array $courseids,
        array $userids
    ): array {
        global $CFG, $DB;

        $enrollib = $CFG->dirroot . '/lib/enrollib.php';
        if (!is_readable($enrollib)) {
            throw new \moodle_exception('safetyenrollibmissing', 'local_gerenciamentomatriculas');
        }
        require_once($enrollib);

        $userids = array_values(array_unique(array_filter(
            array_map('intval', $userids),
            static fn(int $userid): bool => $userid > 0
        )));
        if (empty($userids)) {
            return ['courses' => [], 'truncated' => false];
        }
        if (count($userids) > operation_guard::MAX_USER_BATCH_SIZE) {
            throw new \moodle_exception(
                'safetyuserbatchlimit',
                'local_gerenciamentomatriculas',
                '',
                operation_guard::MAX_USER_BATCH_SIZE
            );
        }

        $normalizedqueries = [];
        $seenqueries = [];
        foreach ($queries as $query) {
            $query = trim((string) $query);
            if ($query === '') {
                continue;
            }
            if (\core_text::strlen($query) < self::MIN_SEARCH_LENGTH) {
                throw new \moodle_exception('searchcoursetooshort', 'local_gerenciamentomatriculas');
            }
            $querykey = \core_text::strtolower($query);
            if (!isset($seenqueries[$querykey])) {
                $seenqueries[$querykey] = true;
                $normalizedqueries[] = $query;
            }
        }

        $courseids = array_values(array_unique(array_filter(
            array_map('intval', $courseids),
            static fn(int $courseid): bool => $courseid > 0 && $courseid !== SITEID
        )));
        if (count($normalizedqueries) + count($courseids) > self::MAX_SEARCH_FILTERS) {
            throw new \moodle_exception(
                'searchfilterlimit',
                'local_gerenciamentomatriculas',
                '',
                self::MAX_SEARCH_FILTERS
            );
        }
        if (empty($normalizedqueries) && empty($courseids)) {
            return ['courses' => [], 'truncated' => false];
        }

        $params = ['siteid' => SITEID];
        $filterconditions = [];
        foreach ($normalizedqueries as $index => $query) {
            $like = '%' . $DB->sql_like_escape($query) . '%';
            $fullnameparam = 'fullname' . $index;
            $shortnameparam = 'shortname' . $index;
            $idnumberparam = 'idnumber' . $index;
            $filterconditions[] = '(' . implode(' OR ', [
                $DB->sql_like('c.fullname', ':' . $fullnameparam, false),
                $DB->sql_like('c.shortname', ':' . $shortnameparam, false),
                $DB->sql_like('c.idnumber', ':' . $idnumberparam, false),
            ]) . ')';
            $params[$fullnameparam] = $like;
            $params[$shortnameparam] = $like;
            $params[$idnumberparam] = $like;
        }

        if (!empty($courseids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'exactcourse');
            $filterconditions[] = 'c.id ' . $insql;
            $params += $inparams;
        }

        $sql = "SELECT c.id, c.fullname, c.shortname, c.idnumber, c.visible,
                       cc.name AS categoryname
                  FROM {course} c
             LEFT JOIN {course_categories} cc ON cc.id = c.category
                 WHERE c.id <> :siteid
                   AND (" . implode(' OR ', $filterconditions) . ")
              ORDER BY c.fullname ASC";
        $records = array_values($DB->get_records_sql($sql, $params, 0, self::SEARCH_LIMIT + 1));

        $truncated = count($records) > self::SEARCH_LIMIT;
        if ($truncated) {
            $records = array_slice($records, 0, self::SEARCH_LIMIT);
        }

        return self::prepare_search_result($records, $userids, $truncated);
    }

    /**
     * Enrols a user in all selected courses using an active manual instance.
     *
     * Existing enrolments are never duplicated. The service does not create or
     * enable enrolment methods because that would change course configuration.
     *
     * @param int $userid Target user id.
     * @param int[] $courseids Selected course ids.
     * @return array{success: array, skipped: array, errors: array}
     */
    public static function enrol_in_courses(int $userid, array $courseids): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/local/gerenciamentomatriculas/lib.php');

        if (!\local_gerenciamentomatriculas_can_manage()) {
            throw new \moodle_exception('nopermission', 'local_gerenciamentomatriculas');
        }

        $enrollib = $CFG->dirroot . '/lib/enrollib.php';
        if (!is_readable($enrollib)) {
            return [
                'success' => [],
                'skipped' => [],
                'errors' => [get_string('safetyenrollibmissing', 'local_gerenciamentomatriculas')],
            ];
        }
        require_once($enrollib);

        $preflighterrors = operation_guard::preflight(operation_guard::FEATURE_ADD);
        if (!empty($preflighterrors)) {
            return ['success' => [], 'skipped' => [], 'errors' => $preflighterrors];
        }

        $batcherror = operation_guard::validate_batch_size($courseids);
        if ($batcherror !== null) {
            return ['success' => [], 'skipped' => [], 'errors' => [$batcherror]];
        }

        $targetuser = \core_user::get_user($userid, 'id,username,deleted', MUST_EXIST);
        if (!empty($targetuser->deleted) || isguestuser($targetuser)) {
            throw new \moodle_exception('invaliduser', 'local_gerenciamentomatriculas');
        }

        $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids))));
        $success = [];
        $skipped = [];
        $errors = [];
        $roleid = self::get_student_role_id();
        $manualplugin = enrol_get_plugin('manual');

        if (!$manualplugin) {
            return [
                'success' => [],
                'skipped' => [],
                'errors' => [get_string('manualpluginmissing', 'local_gerenciamentomatriculas')],
            ];
        }

        if (!$roleid) {
            return [
                'success' => [],
                'skipped' => [],
                'errors' => [get_string('studentrolemissing', 'local_gerenciamentomatriculas')],
            ];
        }

        [$courseinsql, $courseparams] = $DB->get_in_or_equal(
            $courseids,
            SQL_PARAMS_NAMED,
            'enrolcourse'
        );
        $courserecords = $DB->get_records_select(
            'course',
            "id {$courseinsql} AND id <> :siteid",
            $courseparams + ['siteid' => SITEID],
            '',
            'id,fullname,shortname'
        );
        $existingstatuses = self::get_existing_enrolment_statuses($courseids, $userid);
        $manualinstances = self::get_active_manual_instances($courseids);

        foreach ($courseids as $courseid) {
            $coursename = get_string('courseidfallback', 'local_gerenciamentomatriculas', $courseid);
            try {
                if (!isset($courserecords[$courseid])) {
                    $errors[] = get_string('courseinvalid', 'local_gerenciamentomatriculas', $courseid);
                    continue;
                }
                $course = $courserecords[$courseid];

                $coursename = format_string(
                    $course->fullname,
                    true,
                    ['context' => \context_course::instance($courseid)]
                );

                if (isset($existingstatuses[$courseid])) {
                    $skipped[] = get_string('alreadyenrolledskip', 'local_gerenciamentomatriculas', $coursename);
                    continue;
                }

                if (empty($manualinstances[$courseid])) {
                    $errors[] = get_string('nomanualinstancecourse', 'local_gerenciamentomatriculas', $coursename);
                    continue;
                }

                $instance = $manualinstances[$courseid];
                if (!is_callable([$manualplugin, 'allow_enrol'])
                        || !is_callable([$manualplugin, 'enrol_user'])
                        || !$manualplugin->allow_enrol($instance)) {
                    $errors[] = get_string('manualnotallowedcourse', 'local_gerenciamentomatriculas', $coursename);
                    continue;
                }

                $manualplugin->enrol_user(
                    $instance,
                    $userid,
                    $roleid,
                    time(),
                    0,
                    ENROL_USER_ACTIVE,
                    false
                );
                $success[] = get_string('enrolledok', 'local_gerenciamentomatriculas', $coursename);
            } catch (\Throwable $exception) {
                debugging($exception->getMessage(), DEBUG_DEVELOPER);
                $errors[] = get_string('enrolfailedcourse', 'local_gerenciamentomatriculas', $coursename);
            }
        }

        return ['success' => $success, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Adds enrolment availability information to course search records.
     *
     * @param array<int, \stdClass> $records Course records.
     * @param int[] $userids Target user ids.
     * @param bool $truncated Whether the originating query was truncated.
     * @return array{courses: array, truncated: bool}
     */
    private static function prepare_search_result(array $records, array $userids, bool $truncated): array {
        if (empty($records)) {
            return ['courses' => [], 'truncated' => $truncated];
        }

        $courseids = array_map(static fn($record): int => (int) $record->id, $records);
        $existingcounts = self::get_existing_enrolment_counts($courseids, $userids);
        $manualinstances = self::get_active_manual_instances($courseids);
        $selectedusercount = count($userids);

        foreach ($records as $record) {
            $courseid = (int) $record->id;
            $record->existingcount = $existingcounts[$courseid] ?? 0;
            $record->selectedusercount = $selectedusercount;
            $record->existingstatus = $selectedusercount === 1 && $record->existingcount === 1
                ? ENROL_USER_ACTIVE
                : null;
            $record->manualinstanceid = isset($manualinstances[$courseid])
                ? (int) $manualinstances[$courseid]->id
                : 0;
            $record->canselect = $record->existingcount < $selectedusercount && $record->manualinstanceid > 0;
        }

        return ['courses' => array_values($records), 'truncated' => $truncated];
    }

    /**
     * Counts distinct selected students who already have an enrolment per course.
     *
     * @param int[] $courseids Course ids.
     * @param int[] $userids User ids.
     * @return array<int, int>
     */
    private static function get_existing_enrolment_counts(array $courseids, array $userids): array {
        global $DB;

        if (empty($courseids) || empty($userids)) {
            return [];
        }

        [$courseinsql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'countcourse');
        [$userinsql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'countuser');
        $params += $userparams;
        $sql = "SELECT e.courseid, COUNT(DISTINCT ue.userid) AS existingcount
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid {$userinsql}
                   AND e.courseid {$courseinsql}
              GROUP BY e.courseid";

        $counts = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $counts[(int) $record->courseid] = (int) $record->existingcount;
        }

        return $counts;
    }

    /**
     * Returns existing enrolment status per course (active wins over suspended).
     *
     * @param int[] $courseids Course ids.
     * @param int $userid User id.
     * @return array<int, int>
     */
    private static function get_existing_enrolment_statuses(array $courseids, int $userid): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'course');
        $params['userid'] = $userid;
        $sql = "SELECT ue.id, e.courseid, ue.status
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :userid
                   AND e.courseid {$insql}";

        $statuses = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $courseid = (int) $record->courseid;
            if (!isset($statuses[$courseid]) || (int) $record->status === ENROL_USER_ACTIVE) {
                $statuses[$courseid] = (int) $record->status;
            }
        }

        return $statuses;
    }

    /**
     * Returns the first active manual enrolment instance for each course.
     *
     * @param int[] $courseids Course ids.
     * @return array<int, stdClass>
     */
    private static function get_active_manual_instances(array $courseids): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'manualcourse');
        $params['enrol'] = 'manual';
        $params['status'] = ENROL_INSTANCE_ENABLED;
        $sql = "SELECT e.*
                  FROM {enrol} e
                 WHERE e.courseid {$insql}
                   AND e.enrol = :enrol
                   AND e.status = :status
              ORDER BY e.sortorder ASC, e.id ASC";

        $instances = [];
        foreach ($DB->get_records_sql($sql, $params) as $instance) {
            $courseid = (int) $instance->courseid;
            if (!isset($instances[$courseid])) {
                $instances[$courseid] = $instance;
            }
        }

        return $instances;
    }

    /**
     * Finds the institution's student role safely.
     *
     * @return int Role id, or zero when no student role can be resolved.
     */
    private static function get_student_role_id(): int {
        global $CFG, $DB;

        if (!empty($CFG->defaultcourseroleid)
                && $DB->record_exists('role', ['id' => (int) $CFG->defaultcourseroleid])) {
            return (int) $CFG->defaultcourseroleid;
        }

        $roles = $DB->get_records('role', ['archetype' => 'student'], 'sortorder ASC', 'id');
        if (empty($roles)) {
            return 0;
        }

        $role = reset($roles);
        return (int) $role->id;
    }
}
