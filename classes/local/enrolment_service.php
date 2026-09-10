<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Enrolment service for the Gerenciamento de matrículas plugin.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gerenciamentomatriculas\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Service responsible for reading and updating user enrolments.
 */
class enrolment_service {
    /** @var string Action to fully unenrol a user. */
    public const ACTION_UNENROL = 'unenrol';

    /** @var string Action to suspend a user enrolment. */
    public const ACTION_SUSPEND = 'suspend';

    /**
     * Returns enrolments for a user with capability hints for the current operator.
     *
     * @param int $userid Target user id.
     * @return array
     */
    public static function get_user_enrolments(int $userid): array {
        $grouped = self::get_users_enrolments([$userid]);
        return $grouped[$userid] ?? [];
    }

    /**
     * Returns enrolments for several users in one bounded database query.
     *
     * @param int[] $userids Target user ids.
     * @return array<int, array>
     */
    public static function get_users_enrolments(array $userids): array {
        global $DB, $CFG;

        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        if (empty($userids)) {
            return [];
        }
        if (count($userids) > operation_guard::MAX_USER_BATCH_SIZE) {
            throw new \moodle_exception('safetyuserbatchlimit', 'local_gerenciamentomatriculas', '',
                operation_guard::MAX_USER_BATCH_SIZE);
        }

        $enrollib = $CFG->dirroot . '/lib/enrollib.php';
        if (!is_readable($enrollib)) {
            throw new \moodle_exception('safetyenrollibmissing', 'local_gerenciamentomatriculas');
        }
        require_once($enrollib);
        if (!function_exists('enrol_get_plugin')) {
            throw new \moodle_exception('safetyenrolfunctionmissing', 'local_gerenciamentomatriculas');
        }

        [$userinsql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'enroluser');
        $params['siteid'] = SITEID;
        $sql = "SELECT ue.id AS userenrolmentid,
                       ue.userid,
                       ue.status AS userenrolmentstatus,
                       ue.timestart,
                       ue.timeend,
                       e.id AS enrolid,
                       e.enrol,
                       e.status AS enrolinstancestatus,
                       e.courseid,
                       c.fullname,
                       c.shortname,
                       c.visible,
                       cc.name AS categoryname
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
             LEFT JOIN {course_categories} cc ON cc.id = c.category
                 WHERE ue.userid {$userinsql}
                   AND c.id <> :siteid
              ORDER BY ue.userid ASC, c.fullname ASC, e.enrol ASC";

        $records = $DB->get_records_sql($sql, $params);
        $grouped = array_fill_keys($userids, []);
        $instances = [];

        foreach ($records as $record) {
            $record->canunenrol = false;
            $record->canmanage = false;
            $record->pluginavailable = false;

            if (!array_key_exists((int) $record->enrolid, $instances)) {
                $instances[(int) $record->enrolid] = $DB->get_record('enrol', ['id' => $record->enrolid]) ?: false;
            }
            $instance = $instances[(int) $record->enrolid];
            if (!$instance) {
                $grouped[(int) $record->userid][(int) $record->userenrolmentid] = $record;
                continue;
            }

            $plugin = enrol_get_plugin($instance->enrol);
            if (!$plugin) {
                $grouped[(int) $record->userid][(int) $record->userenrolmentid] = $record;
                continue;
            }

            $record->pluginavailable = true;
            $ue = (object) [
                'id' => $record->userenrolmentid,
                'enrolid' => $record->enrolid,
                'userid' => $record->userid,
                'status' => $record->userenrolmentstatus,
                'timestart' => $record->timestart,
                'timeend' => $record->timeend,
            ];

            if (is_callable([$plugin, 'allow_unenrol_user'])) {
                $record->canunenrol = $plugin->allow_unenrol_user($instance, $ue);
            }
            if (is_callable([$plugin, 'allow_manage'])) {
                $record->canmanage = $plugin->allow_manage($instance);
            }
            $grouped[(int) $record->userid][(int) $record->userenrolmentid] = $record;
        }

        return $grouped;
    }

    /**
     * Processes the selected enrolments.
     *
     * @param int $userid Target user id.
     * @param array $userenrolmentids Selected user_enrolments ids.
     * @param string $action Action name.
     * @param bool $suspendaccount Whether to suspend the target account.
     * @return array{success: array, errors: array}
     */
    public static function process_selection(int $userid, array $userenrolmentids, string $action, bool $suspendaccount): array {
        global $DB, $CFG, $USER;

        require_once($CFG->dirroot . '/local/gerenciamentomatriculas/lib.php');
        if (!\local_gerenciamentomatriculas_can_manage()) {
            throw new \moodle_exception('nopermission', 'local_gerenciamentomatriculas');
        }

        $enrollib = $CFG->dirroot . '/lib/enrollib.php';
        $userlib = $CFG->dirroot . '/user/lib.php';
        if (!is_readable($enrollib) || !is_readable($userlib)) {
            return [
                'success' => [],
                'errors' => [get_string('safetydependencymissing', 'local_gerenciamentomatriculas')],
            ];
        }
        require_once($enrollib);
        require_once($userlib);

        $preflighterrors = operation_guard::preflight(operation_guard::FEATURE_REMOVE);
        if (!empty($preflighterrors)) {
            return ['success' => [], 'errors' => $preflighterrors];
        }

        if (!empty($userenrolmentids)) {
            $batcherror = operation_guard::validate_batch_size($userenrolmentids);
            if ($batcherror !== null) {
                return ['success' => [], 'errors' => [$batcherror]];
            }
        }

        if (!in_array($action, [self::ACTION_UNENROL, self::ACTION_SUSPEND], true)) {
            throw new \moodle_exception('invalidaction', 'local_gerenciamentomatriculas');
        }

        $success = [];
        $errors = [];

        $userenrolmentids = array_values(array_unique(array_filter(array_map('intval', $userenrolmentids))));

        if (!empty($userenrolmentids)) {
            list($insql, $params) = $DB->get_in_or_equal($userenrolmentids, SQL_PARAMS_NAMED, 'ueid');
            $params['userid'] = $userid;

            $sql = "SELECT ue.id,
                           ue.enrolid,
                           ue.userid,
                           ue.status,
                           ue.timestart,
                           ue.timeend,
                           e.enrol,
                           e.courseid,
                           c.fullname
                      FROM {user_enrolments} ue
                      JOIN {enrol} e ON e.id = ue.enrolid
                      JOIN {course} c ON c.id = e.courseid
                     WHERE ue.userid = :userid
                       AND ue.id {$insql}";

            $records = $DB->get_records_sql($sql, $params);

            foreach ($userenrolmentids as $ueid) {
                $coursename = get_string('enrolmentidfallback', 'local_gerenciamentomatriculas', $ueid);
                try {
                    if (empty($records[$ueid])) {
                        continue;
                    }

                    $ue = $records[$ueid];
                    $coursename = format_string(
                        $ue->fullname,
                        true,
                        ['context' => \context_course::instance($ue->courseid)]
                    );

                    $instance = $DB->get_record('enrol', ['id' => $ue->enrolid]);
                    if (!$instance) {
                        $errors[] = get_string('safetycourseoperationfailed', 'local_gerenciamentomatriculas', $coursename);
                        continue;
                    }

                    $plugin = enrol_get_plugin($instance->enrol);
                    if (!$plugin) {
                        $errors[] = get_string('pluginnavailable', 'local_gerenciamentomatriculas', $coursename);
                        continue;
                    }

                    if ($action === self::ACTION_UNENROL) {
                        if (!is_callable([$plugin, 'allow_unenrol_user'])
                                || !is_callable([$plugin, 'unenrol_user'])
                                || !$plugin->allow_unenrol_user($instance, $ue)) {
                            $errors[] = get_string('cannotunenrol', 'local_gerenciamentomatriculas', $coursename);
                            continue;
                        }

                        $plugin->unenrol_user($instance, $userid);
                        $success[] = get_string('unenrolledok', 'local_gerenciamentomatriculas', $coursename);
                    } else {
                        if (!is_callable([$plugin, 'allow_manage'])
                                || !is_callable([$plugin, 'update_user_enrol'])
                                || !$plugin->allow_manage($instance)) {
                            $errors[] = get_string('cannotsuspend', 'local_gerenciamentomatriculas', $coursename);
                            continue;
                        }

                        $plugin->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
                        $success[] = get_string('suspendedok', 'local_gerenciamentomatriculas', $coursename);
                    }
                } catch (\Throwable $exception) {
                    debugging($exception->getMessage(), DEBUG_DEVELOPER);
                    $errors[] = get_string('safetycourseoperationfailed', 'local_gerenciamentomatriculas', $coursename);
                }
            }
        }

        if ($suspendaccount) {
            if ((int) $USER->id === $userid) {
                $errors[] = get_string('cannotselfsuspend', 'local_gerenciamentomatriculas');
            } else {
                try {
                    if (!function_exists('user_update_user')) {
                        $errors[] = get_string('safetyaccountprocessmissing', 'local_gerenciamentomatriculas');
                    } else {
                        $targetuser = \core_user::get_user($userid, 'id,suspended', MUST_EXIST);
                        if (!empty($targetuser->suspended)) {
                            $success[] = get_string('alreadyaccountsuspended', 'local_gerenciamentomatriculas');
                        } else {
                            $updateuser = (object) [
                                'id' => $userid,
                                'suspended' => 1,
                            ];
                            user_update_user($updateuser, false, true);
                            $success[] = get_string('accountsuspendedok', 'local_gerenciamentomatriculas');
                        }
                    }
                } catch (\Throwable $exception) {
                    debugging($exception->getMessage(), DEBUG_DEVELOPER);
                    $errors[] = get_string('safetyaccountsuspendfailed', 'local_gerenciamentomatriculas');
                }
            }
        }

        return [
            'success' => $success,
            'errors' => $errors,
        ];
    }
}
