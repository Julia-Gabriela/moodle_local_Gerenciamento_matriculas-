// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Enhances the multiple-student field with Moodle's native autocomplete.
 *
 * @module     local_gerenciamentomatriculas/student_multi_selector
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {enhanceField} from 'core/form-autocomplete';

const FAILURE_EVENT = 'local_gerenciamentomatriculas:autocompletefailed';
const DATASOURCE = 'local_gerenciamentomatriculas/user_selector_repository';

/**
 * Initialise the multiple student selector and keep its safe fallback visible on failure.
 *
 * @param {String} selectId Select ID.
 * @param {String} fallbackId Fallback container ID.
 * @param {String} autocompleteRegionId Autocomplete container ID.
 * @param {String} placeholder Input placeholder.
 * @param {String} noSelectionString Empty-selection label.
 * @param {Number} maxSelections Maximum selected students.
 * @param {String} limitMessage Translated limit warning.
 */
export const init = async(
    selectId,
    fallbackId,
    autocompleteRegionId,
    placeholder,
    noSelectionString,
    maxSelections,
    limitMessage
) => {
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

    if (!autocompleteRegion.querySelector('[data-fieldtype="autocomplete"]')) {
        document.removeEventListener(FAILURE_EVENT, useFallback);
        return;
    }

    autocompleteRegion.classList.remove('d-none');
    fallback.classList.add('d-none');

    let previousValues = new Set(Array.from(select.selectedOptions, option => option.value));
    let correctingSelection = false;
    select.addEventListener('change', () => {
        if (correctingSelection) {
            return;
        }
        const selectedOptions = Array.from(select.selectedOptions);
        if (selectedOptions.length <= maxSelections) {
            previousValues = new Set(selectedOptions.map(option => option.value));
            return;
        }

        const newlySelected = selectedOptions.find(option => !previousValues.has(option.value));
        (newlySelected || selectedOptions[selectedOptions.length - 1]).selected = false;
        correctingSelection = true;
        select.dispatchEvent(new Event('change', {bubbles: true}));
        correctingSelection = false;
        previousValues = new Set(Array.from(select.selectedOptions, option => option.value));
        window.alert(limitMessage);
    });
};
