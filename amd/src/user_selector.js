// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Enhances the administrative student search with Moodle's native autocomplete.
 *
 * @module     local_gerenciamentomatriculas/user_selector
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {enhanceField} from 'core/form-autocomplete';

const FAILURE_EVENT = 'local_gerenciamentomatriculas:autocompletefailed';
const DATASOURCE = 'local_gerenciamentomatriculas/user_selector_repository';

/**
 * Initialise the selector, preserving the standard search as a fallback.
 *
 * @param {String} selectId ID of the original select element.
 * @param {String} fallbackId ID of the normal search container.
 * @param {String} autocompleteRegionId ID of the autocomplete container.
 * @param {String} manageUrl Legacy base URL (kept for cache compatibility).
 * @param {String} placeholder Input placeholder.
 * @param {String} noSelectionString Empty-selection label.
 */
export const init = async(
    selectId,
    fallbackId,
    autocompleteRegionId,
    manageUrl,
    placeholder,
    noSelectionString
) => {
    const select = document.getElementById(selectId);
    const fallback = document.getElementById(fallbackId);
    const autocompleteRegion = document.getElementById(autocompleteRegionId);
    if (!select || !fallback || !autocompleteRegion) {
        return;
    }

    const useFallback = event => {
        if (event.detail?.selector !== `#${selectId}`) {
            return;
        }
        autocompleteRegion.classList.add('d-none');
        fallback.classList.remove('d-none');
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
        return;
    }

    // enhanceField handles some rendering failures internally. Only switch interfaces if its input exists.
    if (!autocompleteRegion.querySelector('[data-fieldtype="autocomplete"]')) {
        document.removeEventListener(FAILURE_EVENT, useFallback);
        return;
    }

    autocompleteRegion.classList.remove('d-none');
    fallback.classList.add('d-none');

    // Intentionally do not navigate on change. Selection is only submitted by an explicit button.
};
