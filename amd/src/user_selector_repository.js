// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX data source for the user selector.
 *
 * @module     local_gerenciamentomatriculas/user_selector_repository
 * @copyright  2026 Júlia Gabriela Gomes da Silva
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';

const FAILURE_EVENT = 'local_gerenciamentomatriculas:autocompletefailed';
const MIN_QUERY_LENGTH = 2;

/**
 * Fetch matching users for core/form-autocomplete.
 *
 * Moodle's autocomplete component applies its own 300 ms throttle before calling this function.
 *
 * @param {String} selector Original select selector.
 * @param {String} query Current query.
 * @param {Function} success Success callback.
 */
export const transport = (selector, query, success) => {
    const normalizedQuery = query.trim();
    if (normalizedQuery.length < MIN_QUERY_LENGTH) {
        success([]);
        return;
    }

    fetchMany([{
        methodname: 'local_gerenciamentomatriculas_search_users',
        args: {query: normalizedQuery},
    }])[0]
    .then(success)
    .catch(() => {
        // Fail closed and restore the server-rendered search instead of breaking the page.
        document.dispatchEvent(new CustomEvent(FAILURE_EVENT, {detail: {selector}}));
        success([]);
    });
};

/**
 * Normalise the external function result for core/form-autocomplete.
 *
 * @param {String} selector Original select selector.
 * @param {*} results External function result.
 * @return {Array}
 */
export const processResults = (selector, results) => Array.isArray(results) ? results : [];
