<?php
/*
* This file is part of Totara LMS
*
* Copyright (C) 2012 Totara Learning Solutions LTD
*
* This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 2 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* @author Ciaran Irvine <ciaran.irvine@totaralms.com>
* @package totara
* @subpackage program
* */

function xmldb_totara_program_install() {
    global $CFG, $DB;
    $dbman = $DB->get_manager();
    // Check if the 'programcount' field has been added to the 'course_categories'
    // table and add it if not
    $table = new xmldb_table('course_categories');
    $field = new xmldb_field('programcount');
    $field->set_attributes(XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'theme');

    if (!$dbman->field_exists($table, $field)) {
        // Launch add field programcount
        $dbman->add_field($table, $field);
    }

    // Set a config value to ensure that the program cron tasks are included
    // in the cron schedule
    if (!isset($CFG->totara_program_cron)) {
        // hack to get cron working via admin/cron.php
        set_config('totara_program_cron', 60);
    }

    prog_setup_initial_plan_settings();
}

/**
* This function is called both when Moodle/Totara is first installed or when
* the program module is installed into an existing Totara instance.
*
* The function adds default settings for the program component of the learning
* plans framework.
*/
function prog_setup_initial_plan_settings() {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/totara/plan/priorityscales/lib.php');

    // retrieve all the existing templates (if any exist)
    $templates = $DB->get_records('dp_template', null, 'id', 'id');

    // Create program settings for existing templates so they don't break
    // but disable programs by default in existing templates

    foreach ($templates as $t) {
        $transaction = $DB->start_delegated_transaction();
        if ($settings = $DB->get_record('dp_component_settings', array('templateid' => $t->id, 'component' => 'program'))) {
            $settings->enabled=0;
            $settings->sortorder = 1 + $DB->count_records('dp_component_settings', array('templateid' => $t->id));
            $DB->update_record('dp_component_settings', $settings);
        } else {
            $settings = new stdClass();
            $settings->templateid=$t->id;
            $settings->component='program';
            $settings->enabled=0;
            $settings->sortorder = 1 + $DB->count_records('dp_component_settings', array('templateid' => $t->id));
            $DB->insert_record('dp_component_settings', $settings);
        }
        $transaction->allow_commit();
    }

    // Fill in permissions and settings for programs in existing templates
    if (is_array($templates)) {
        $roles = array('learner','manager');
        $actions=array('updateprogram','setpriority','setduedate');

        $defaultduedatemode = DP_DUEDATES_OPTIONAL;
        $defaultprioritymode = DP_PRIORITY_NONE;
        if (!$defaultpriorityscaleid = dp_priority_default_scale_id()) {
            $defaultpriorityscaleid = 0;
        }

        $action_values = array(
            'learner' => array(
                'updateprogram' => DP_PERMISSION_REQUEST,
                'setpriority' => DP_PERMISSION_DENY,
                'setduedate' => DP_PERMISSION_DENY),
            'manager' => array(
                'updateprogram' => DP_PERMISSION_APPROVE,
                'setpriority' => DP_PERMISSION_ALLOW,
                'setduedate' => DP_PERMISSION_ALLOW));

        foreach ($templates as $t) {
            $transaction = $DB->start_delegated_transaction();

            $perm = new stdClass();
            $perm->templateid = $t->id;
            foreach ($action_values as $role => $actions) {
                foreach ($actions as $action => $permissionvalue) {
                    if ($rec = $DB->get_record_select('dp_permissions',
                                                         "templateid = ? AND role = ? AND component = 'program' AND action = ?",
                    array($perm->templateid, $role, $action))) {
                        $rec->value=$permissionvalue;
                        $DB->update_record('dp_permissions', $rec);
                    } else {
                        $perm->role = $role;
                        $perm->action = $action;
                        $perm->value = $permissionvalue;
                        $perm->component = 'program';
                        $DB->insert_record('dp_permissions', $perm);
                    }
                }
            }
            if ($progset = $DB->get_record_select('dp_program_settings', "templateid = ?", array($t->id))) {
                $progset->duedatemode = $defaultduedatemode;
                $progset->prioritymode = $defaultprioritymode;
                $progset->priorityscale = $defaultpriorityscaleid;
                $DB->update_record('dp_program_settings', $progset);
            } else {
                $progset = new stdClass();
                $progset->templateid = $t->id;
                $progset->duedatemode = $defaultduedatemode;
                $progset->prioritymode = $defaultprioritymode;
                $progset->priorityscale = $defaultpriorityscaleid;
                $DB->insert_record('dp_program_settings', $progset);
            }
            $transaction->allow_commit();
        }
    }
}