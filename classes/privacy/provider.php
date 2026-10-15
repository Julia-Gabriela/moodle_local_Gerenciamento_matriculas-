<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Privacy provider.
 *
 * @package    local_gerenciamentomatriculas
 * @copyright  2026 Júlia Gabriela Gomes da Silva
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gerenciamentomatriculas\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider for this plugin.
 */
class provider implements \core_privacy\local\metadata\null_provider {
    /**
     * This plugin does not store personal data in its own tables.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
