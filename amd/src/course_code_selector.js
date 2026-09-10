// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Enhances course search with Moodle's native autocomplete.
 *
 * @module     local_gerenciamentomatriculas/course_code_selector
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {enhanceField} from 'core/form-autocomplete';

const FAILURE_EVENT = 'local_gerenciamentomatriculas:courseautocompletefailed';
const DATASOURCE = 'local_gerenciamentomatriculas/course_code_repository';

/**
 * Initialise course-code autocomplete with the normal search as fallback.
 *
 * @param {String} selectId Autocomplete select ID.
 * @param {String} fallbackId Standard search container ID.
 * @param {String} autocompleteRegionId Autocomplete container ID.
 * @param {String} placeholder Input placeholder.
 * @param {String} noSelectionString Empty-selection label.
 */
export const init = async(selectId, fallbackId, autocompleteRegionId, placeholder, noSelectionString) => {
    const select = document.getElementById(selectId);
    const fallback = document.getElementById(fallbackId);
    const autocompleteRegion = document.getElementById(autocompleteRegionId);
    if (!select || !fallback || !autocompleteRegion || !select.form) {
        return;
    }

    const useFallback = event => {
        if (event.detail?.selector !== `#${selectId}`) {
            return;
        }
        autocompleteRegion.classList.add('d-none');
        fallback.classList.remove('d-none');
        select.disabled = true;
    };
    document.addEventListener(FAILURE_EVENT, useFallback, {once: true});

    try {
        await enhanceField(
            `#${selectId}`,
            false,
            DATASOURCE,
            placeholder,
            false,
            true,
            noSelectionString,
            true
        );
    } catch (error) {
        document.removeEventListener(FAILURE_EVENT, useFallback);
        select.disabled = true;
        return;
    }

    if (!autocompleteRegion.querySelector('[data-fieldtype="autocomplete"]')) {
        document.removeEventListener(FAILURE_EVENT, useFallback);
        select.disabled = true;
        return;
    }

    autocompleteRegion.classList.remove('d-none');
    fallback.classList.add('d-none');

};
