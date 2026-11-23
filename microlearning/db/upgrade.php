<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_microlearning_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026110501) {
        $table = new xmldb_table('local_microlearning_items');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('transcript', XMLDB_TYPE_TEXT, 'big', null, XMLDB_NOTNULL, null, null);
            $table->add_field('content', XMLDB_TYPE_TEXT, 'big', null, XMLDB_NOTNULL, null, null);
            $table->add_field('durationsec', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('visibility', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'private');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $table->add_index('user_created', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
            $table->add_index('visibility_course', XMLDB_INDEX_NOTUNIQUE, ['visibility', 'courseid']);
            $table->add_index('groupid', XMLDB_INDEX_NOTUNIQUE, ['groupid']);

            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026110501, 'local', 'microlearning');
    }

    if ($oldversion < 2026110900) {
        $table = new xmldb_table('local_microlearning_quizreq');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('requestkey', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sectionnum', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('beforemod', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('questioncount', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, null);
            $table->add_field('durationsec', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourcefileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sourcehash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_index('requestkey', XMLDB_INDEX_UNIQUE, ['requestkey']);
            $table->add_index('usercourse', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026110900, 'local', 'microlearning');
    }
    if ($oldversion < 2026111000) {
        // The upgrade manager installs the student navigation capability from db/access.php.
        upgrade_plugin_savepoint(true, 2026111000, 'local', 'microlearning');
    }
    if ($oldversion < 2026112300) {
        $schema = new xmldb_file(__DIR__ . '/install.xml');
        if (!$schema->loadXMLStructure()) {
            throw new upgrade_exception('local_microlearning', 2026112300, 'Cannot read the narration schema');
        }
        foreach (['local_microlearning_voices', 'local_microlearning_narrjobs', 'local_microlearning_aikeys'] as $name) {
            $table = $schema->getStructure()->getTable($name);
            if (!$dbman->table_exists($table)) {
                $dbman->create_table($table);
            }
        }
        upgrade_plugin_savepoint(true, 2026112300, 'local', 'microlearning');
    }
    return true;
}
