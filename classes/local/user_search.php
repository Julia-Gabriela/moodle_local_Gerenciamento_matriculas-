<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Limited user search shared by the normal search and autocomplete.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026 Júlia Gabriela Gomes da Silva
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gerenciamentomatriculas\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Searches manageable Moodle users with strict minimum and result limits.
 */
final class user_search {
    /** Minimum number of characters required before searching. */
    public const MIN_QUERY_LENGTH = 2;

    /** Maximum number of autocomplete suggestions. */
    public const AUTOCOMPLETE_LIMIT = 20;

    /** Maximum number of rows on the non-JavaScript fallback page. */
    public const FALLBACK_LIMIT = 50;

    /**
     * Finds users by name, username/ID or email.
     *
     * @param string $query Search text.
     * @param int $limit Requested result limit.
     * @return array<int, \stdClass>
     */
    public static function find(string $query, int $limit = self::AUTOCOMPLETE_LIMIT): array {
        global $CFG, $DB;

        $query = trim($query);
        if (\core_text::strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $limit = max(1, min($limit, self::FALLBACK_LIMIT));
        $like = '%' . $DB->sql_like_escape($query) . '%';
        $firstnameandlastname = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
        $lastnameandfirstname = $DB->sql_concat('u.lastname', "' '", 'u.firstname');
        $conditions = [
            $DB->sql_like('u.username', ':username', false),
            $DB->sql_like('u.firstname', ':firstname', false),
            $DB->sql_like('u.lastname', ':lastname', false),
            $DB->sql_like('u.email', ':email', false),
            $DB->sql_like($firstnameandlastname, ':fullnamereverseoff', false),
            $DB->sql_like($lastnameandfirstname, ':fullnamereverseon', false),
        ];

        $sql = "SELECT u.id, u.firstname, u.lastname, u.username, u.email, u.suspended
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.id <> :guestid
                   AND (" . implode(' OR ', $conditions) . ")
              ORDER BY u.firstname ASC, u.lastname ASC, u.username ASC";

        $users = $DB->get_records_sql($sql, [
            'guestid' => (int) $CFG->siteguest,
            'username' => $like,
            'firstname' => $like,
            'lastname' => $like,
            'email' => $like,
            'fullnamereverseoff' => $like,
            'fullnamereverseon' => $like,
        ], 0, $limit);

        return array_values($users);
    }
}
