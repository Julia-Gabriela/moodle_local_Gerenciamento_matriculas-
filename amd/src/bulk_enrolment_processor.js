// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Processes a confirmed student/course matrix as short sequential requests.
 *
 * @module     local_gerenciamentomatriculas/bulk_enrolment_processor
 * @copyright  2026 Júlia Gabriela Gomes da Silva
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';

const METHOD_NAME = 'local_gerenciamentomatriculas_enrol_user_batch';

/**
 * Split a list into small fixed-size chunks.
 *
 * @param {Array} values Values to split.
 * @param {Number} size Maximum chunk size.
 * @return {Array<Array>}
 */
const chunkValues = (values, size) => {
    const chunks = [];
    for (let offset = 0; offset < values.length; offset += size) {
        chunks.push(values.slice(offset, offset + size));
    }
    return chunks;
};

/**
 * Append result details using text nodes only.
 *
 * @param {HTMLElement} container Results container.
 * @param {Object} student Student descriptor.
 * @param {Object} result Aggregated result arrays.
 * @param {Object} strings Translated labels.
 */
const appendStudentResult = (container, student, result, strings) => {
    const details = document.createElement('details');
    details.className = 'mb-2';
    const summary = document.createElement('summary');
    summary.textContent = `${student.name} — ${strings.success}: ${result.success.length}; `
        + `${strings.skipped}: ${result.skipped.length}; ${strings.errors}: ${result.errors.length}`;
    details.append(summary);

    [
        [strings.success, result.success],
        [strings.skipped, result.skipped],
        [strings.errors, result.errors],
    ].forEach(([label, messages]) => {
        if (!messages.length) {
            return;
        }
        const heading = document.createElement('strong');
        heading.textContent = label;
        details.append(heading);
        const list = document.createElement('ul');
        messages.forEach(message => {
            const item = document.createElement('li');
            item.textContent = message;
            list.append(item);
        });
        details.append(list);
    });

    container.append(details);
};

/**
 * Initialise the confirmation button.
 *
 * @param {String} buttonId Confirmation button ID.
 * @param {String} progressId Progress element ID.
 * @param {String} statusId Status text ID.
 * @param {String} resultsId Results container ID.
 * @param {String} completionActionsId Completion navigation container ID.
 * @param {Array<Object>} students Selected students ({id, name}).
 * @param {Array<Number>} courseIds Selected course IDs.
 * @param {Number} chunkSize Maximum course operations per request.
 * @param {Object} strings Translated UI strings.
 */
export const init = (
    buttonId,
    progressId,
    statusId,
    resultsId,
    completionActionsId,
    students,
    courseIds,
    chunkSize,
    strings
) => {
    const button = document.getElementById(buttonId);
    const progress = document.getElementById(progressId);
    const status = document.getElementById(statusId);
    const results = document.getElementById(resultsId);
    const completionActions = document.getElementById(completionActionsId);
    const normalizedStudents = students
        .map(student => ({id: Number.parseInt(student.id, 10), name: String(student.name)}))
        .filter(student => Number.isInteger(student.id) && student.id > 0);
    const normalizedCourses = [...new Set(courseIds.map(Number).filter(Number.isInteger))];
    const safeChunkSize = Math.max(1, Number.parseInt(chunkSize, 10) || 1);

    if (!button || !progress || !status || !results || !normalizedStudents.length || !normalizedCourses.length) {
        return;
    }

    button.disabled = false;
    button.addEventListener('click', async() => {
        button.disabled = true;
        results.replaceChildren();
        const total = normalizedStudents.length * normalizedCourses.length;
        let processed = 0;
        let transportFailed = false;
        progress.max = total;
        progress.value = 0;
        progress.classList.remove('d-none');
        status.textContent = `${strings.processing} 0/${total}. ${strings.keepopen}`;

        const preventNavigation = event => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', preventNavigation);

        try {
            studentLoop:
            for (const student of normalizedStudents) {
                const studentResult = {success: [], skipped: [], errors: []};
                for (const courseChunk of chunkValues(normalizedCourses, safeChunkSize)) {
                    try {
                        const response = await fetchMany([{
                            methodname: METHOD_NAME,
                            args: {userid: student.id, courseids: courseChunk},
                        }])[0];
                        studentResult.success.push(...(response.success || []));
                        studentResult.skipped.push(...(response.skipped || []));
                        studentResult.errors.push(...(response.errors || []));
                    } catch (error) {
                        studentResult.errors.push(strings.transporterror);
                        transportFailed = true;
                    }

                    processed += courseChunk.length;
                    progress.value = processed;
                    status.textContent = `${strings.processing} ${processed}/${total}. ${strings.keepopen}`;
                    if (transportFailed) {
                        appendStudentResult(results, student, studentResult, strings);
                        break studentLoop;
                    }
                }
                appendStudentResult(results, student, studentResult, strings);
            }
        } finally {
            window.removeEventListener('beforeunload', preventNavigation);
        }

        status.textContent = transportFailed
            ? strings.interrupted
            : `${strings.completed} ${processed}/${total}.`;
        if (completionActions) {
            completionActions.classList.remove('d-none');
        }
    }, {once: true});
};
