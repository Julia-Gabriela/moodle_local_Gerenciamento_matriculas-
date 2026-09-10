// This file is part of Moodle - http://moodle.org/

/**
 * Processes confirmed removals/suspensions as short sequential requests.
 *
 * @module local_gerenciamentomatriculas/bulk_removal_processor
 */

import {call as fetchMany} from 'core/ajax';

const METHOD_NAME = 'local_gerenciamentomatriculas_process_enrolment_batch';

const chunkValues = (values, size) => {
    const chunks = [];
    for (let offset = 0; offset < values.length; offset += size) {
        chunks.push(values.slice(offset, offset + size));
    }
    return chunks;
};

const appendResult = (container, student, result, strings) => {
    const details = document.createElement('details');
    details.className = 'mb-2';
    const summary = document.createElement('summary');
    summary.textContent = `${student.name} — ${strings.success}: ${result.success.length}; `
        + `${strings.errors}: ${result.errors.length}`;
    details.append(summary);

    [[strings.success, result.success], [strings.errors, result.errors]].forEach(([label, messages]) => {
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
 * @param {String} buttonId Confirmation button ID.
 * @param {String} progressId Progress ID.
 * @param {String} statusId Status region ID.
 * @param {String} resultsId Results region ID.
 * @param {String} completionActionsId Completion buttons region ID.
 * @param {Array<Object>} students Student operations.
 * @param {String} action unenrol or suspend.
 * @param {Boolean} suspendAccount Whether each selected account must be suspended.
 * @param {Number} chunkSize Maximum enrolments per request.
 * @param {Object} strings Translated strings.
 */
export const init = (
    buttonId,
    progressId,
    statusId,
    resultsId,
    completionActionsId,
    students,
    action,
    suspendAccount,
    chunkSize,
    strings
) => {
    const button = document.getElementById(buttonId);
    const progress = document.getElementById(progressId);
    const status = document.getElementById(statusId);
    const results = document.getElementById(resultsId);
    const completionActions = document.getElementById(completionActionsId);
    const safeChunkSize = Math.max(1, Number.parseInt(chunkSize, 10) || 1);
    const normalizedStudents = students.map(student => ({
        id: Number.parseInt(student.id, 10),
        name: String(student.name),
        enrolmentids: [...new Set((student.enrolmentids || []).map(Number).filter(Number.isInteger))],
    })).filter(student => Number.isInteger(student.id) && student.id > 0);

    if (!button || !progress || !status || !results || !normalizedStudents.length) {
        return;
    }

    button.disabled = false;
    button.addEventListener('click', async() => {
        button.disabled = true;
        results.replaceChildren();
        const total = normalizedStudents.reduce((sum, student) =>
            sum + Math.max(student.enrolmentids.length, suspendAccount ? 1 : 0), 0);
        let processed = 0;
        let transportFailed = false;
        progress.max = Math.max(total, 1);
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
                const chunks = student.enrolmentids.length
                    ? chunkValues(student.enrolmentids, safeChunkSize)
                    : [[]];
                const studentResult = {success: [], errors: []};
                for (let index = 0; index < chunks.length; index++) {
                    const chunk = chunks[index];
                    try {
                        const response = await fetchMany([{
                            methodname: METHOD_NAME,
                            args: {
                                userid: student.id,
                                enrolmentids: chunk,
                                action,
                                suspendaccount: Boolean(suspendAccount && index === chunks.length - 1),
                            },
                        }])[0];
                        studentResult.success.push(...(response.success || []));
                        studentResult.errors.push(...(response.errors || []));
                    } catch (error) {
                        studentResult.errors.push(strings.transporterror);
                        transportFailed = true;
                    }

                    processed += Math.max(chunk.length, suspendAccount && !student.enrolmentids.length ? 1 : 0);
                    progress.value = processed;
                    status.textContent = `${strings.processing} ${processed}/${total}. ${strings.keepopen}`;
                    if (transportFailed) {
                        appendResult(results, student, studentResult, strings);
                        break studentLoop;
                    }
                }
                appendResult(results, student, studentResult, strings);
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
