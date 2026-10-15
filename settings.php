<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Site administration entry.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026 Júlia Gabriela Gomes da Silva
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if (has_capability('moodle/site:configview', context_system::instance())) {
    $ADMIN->add('users', new admin_externalpage(
        'local_gerenciamentomatriculas',
        get_string('pluginname', 'local_gerenciamentomatriculas'),
        new moodle_url('/local/gerenciamentomatriculas/selectuser.php'),
        'moodle/site:configview'
    ));
}
