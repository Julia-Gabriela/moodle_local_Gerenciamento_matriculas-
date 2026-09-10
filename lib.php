<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Navigation callbacks for the Gerenciamento de matrículas plugin.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Checks whether the current operator may use the plugin.
 *
 * The institution requested the same audience that can view Site
 * administration. Site administrators always pass this capability check.
 *
 * @return bool
 */
function local_gerenciamentomatriculas_can_manage(): bool {
    if (!isloggedin() || isguestuser()) {
        return false;
    }

    return has_capability('moodle/site:configview', context_system::instance());
}

/**
 * Renders selected users in the same compact table across plugin pages.
 *
 * @param array $users User records keyed by id or in display order.
 * @return string
 */
function local_gerenciamentomatriculas_render_users_table(array $users): string {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table table-striped local-gerenciamentomatriculas-users-table';
    $table->head = [
        get_string('student', 'local_gerenciamentomatriculas'),
        get_string('username', 'local_gerenciamentomatriculas'),
        get_string('email', 'local_gerenciamentomatriculas'),
        get_string('accountstatus', 'local_gerenciamentomatriculas'),
    ];

    foreach ($users as $user) {
        $status = !empty($user->suspended)
            ? get_string('accountsuspended', 'local_gerenciamentomatriculas')
            : get_string('accountactive', 'local_gerenciamentomatriculas');
        $profilebutton = html_writer::link(
            new moodle_url('/user/profile.php', ['id' => $user->id]),
            get_string('viewprofile', 'local_gerenciamentomatriculas'),
            ['class' => 'btn btn-outline-secondary btn-sm ml-2']
        );

        $table->data[] = [
            fullname($user),
            s($user->username),
            s($user->email),
            html_writer::span($status) . $profilebutton,
        ];
    }

    return html_writer::div(html_writer::table($table), 'table-responsive');
}

/**
 * Adds the management action to the Administration section of the profile.
 *
 * @param core_user\output\myprofile\tree $tree Profile tree.
 * @param stdClass $user Profile user.
 * @param bool $iscurrentuser Whether this is the current user's own profile.
 * @param stdClass|null $course Current course.
 * @return void
 */
function local_gerenciamentomatriculas_myprofile_navigation(
    core_user\output\myprofile\tree $tree,
    $user,
    $iscurrentuser,
    $course
): void {
    if (!local_gerenciamentomatriculas_can_manage()) {
        return;
    }

    if (empty($user->id) || !empty($user->deleted) || isguestuser($user)) {
        return;
    }

    $url = new moodle_url('/local/gerenciamentomatriculas/index.php', ['userid' => $user->id]);
    $node = new core_user\output\myprofile\node(
        'administration',
        'local_gerenciamentomatriculas',
        get_string('profilelink', 'local_gerenciamentomatriculas'),
        null,
        $url,
        null,
        new pix_icon('i/enrolusers', '')
    );

    $tree->add_node($node);
}

/**
 * Adds a management link to the user's profile page.
 *
 * @param navigation_node $parentnode Profile navigation node.
 * @param stdClass $user Profile user.
 * @param context_user $context User context.
 * @param stdClass $course Current course.
 * @param context_course $coursecontext Course context.
 * @return void
 */
function local_gerenciamentomatriculas_extend_navigation_user(
    navigation_node $parentnode,
    stdClass $user,
    context_user $context,
    stdClass $course,
    $coursecontext
): void {
    if (!local_gerenciamentomatriculas_can_manage()) {
        return;
    }

    if (empty($user->id) || !empty($user->deleted) || isguestuser($user)) {
        return;
    }

    $url = new moodle_url('/local/gerenciamentomatriculas/index.php', ['userid' => $user->id]);

    $parentnode->add(
        get_string('profilelink', 'local_gerenciamentomatriculas'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_gerenciamentomatriculas',
        new pix_icon('i/enrolusers', '')
    );
}
