<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_microlearning', get_string('pluginname', 'local_microlearning'));
    $ADMIN->add('localplugins', $settings);
    $settings->add(new admin_setting_configtext(
        'local_microlearning/narrationserviceurl',
        get_string('speechurl', 'local_microlearning'),
        get_string('speechurl_desc', 'local_microlearning'),
        'http://127.0.0.1:8010',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_microlearning/narrationservicetoken',
        get_string('speechtoken', 'local_microlearning'),
        get_string('speechtoken_desc', 'local_microlearning'),
        ''
    ));
}
