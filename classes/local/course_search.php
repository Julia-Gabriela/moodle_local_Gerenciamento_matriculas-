<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Lightweight course-code search used by the autocomplete field.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gerenciamentomatriculas\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds grouped and course-specific suggestions without changing data.
 */
final class course_search {
    /** Minimum number of characters required before searching. */
    public const MIN_QUERY_LENGTH = 2;

    /** Maximum number of suggestions returned to the browser. */
    public const SUGGESTION_LIMIT = 20;

    /** Bounded record scan used to obtain distinct codes. */
    private const SCAN_LIMIT = 100;

    /**
     * Suggests one grouped search followed by individual matching courses.
     *
     * The first result searches every course containing the typed code. Each
     * later result targets one exact course. Selecting either result never
     * creates an enrolment.
     *
     * @param string $query Partial course code.
     * @return array<int, array{value: string, label: string}>
     */
    public static function suggest_codes(string $query): array {
        global $DB;

        $query = trim($query);
        if (\core_text::strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $like = '%' . $DB->sql_like_escape($query) . '%';
        $conditions = [
            $DB->sql_like('c.fullname', ':fullname', false),
            $DB->sql_like('c.shortname', ':shortname', false),
            $DB->sql_like('c.idnumber', ':idnumber', false),
        ];
        $sql = "SELECT c.id, c.fullname, c.shortname, c.idnumber
                  FROM {course} c
                 WHERE c.id <> :siteid
                   AND (" . implode(' OR ', $conditions) . ")
              ORDER BY c.shortname ASC, c.fullname ASC";

        $courses = $DB->get_records_sql($sql, [
            'siteid' => SITEID,
            'fullname' => $like,
            'shortname' => $like,
            'idnumber' => $like,
        ], 0, self::SCAN_LIMIT);

        if (empty($courses)) {
            return [];
        }

        $suggestions = [[
            'value' => 'all:' . $query,
            'label' => s($query),
        ]];
        foreach ($courses as $course) {
            $code = self::select_code($course, $query);
            if ($code === '') {
                continue;
            }

            $coursename = format_string(
                $course->fullname,
                true,
                [
                    'context' => \context_course::instance((int) $course->id),
                    'escape' => false,
                ]
            );
            $coursename = trim(strip_tags($coursename));
            if ($coursename === '') {
                $label = $code;
            } else {
                $label = \core_text::strpos(
                    \core_text::strtolower($coursename),
                    \core_text::strtolower($code)
                ) === 0 ? $coursename : $code . ' - ' . $coursename;
            }
            $suggestions[] = [
                'value' => 'course:' . (int) $course->id,
                'label' => s($label),
            ];

            if (count($suggestions) >= self::SUGGESTION_LIMIT) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Chooses the most useful matching course code.
     *
     * ID number is preferred when it matches because institutions commonly
     * use it as the stable class code. Short name is the next choice.
     *
     * @param \stdClass $course Course record.
     * @param string $query Search text.
     * @return string
     */
    private static function select_code(\stdClass $course, string $query): string {
        $query = \core_text::strtolower($query);
        $idnumber = trim((string) ($course->idnumber ?? ''));
        $shortname = trim((string) ($course->shortname ?? ''));
        $fullname = trim((string) ($course->fullname ?? ''));

        foreach ([$idnumber, $shortname] as $candidate) {
            if ($candidate !== '' && \core_text::strpos(\core_text::strtolower($candidate), $query) !== false) {
                return $candidate;
            }
        }

        if ($idnumber !== '') {
            return $idnumber;
        }
        if ($shortname !== '') {
            return $shortname;
        }

        return $fullname;
    }
}
