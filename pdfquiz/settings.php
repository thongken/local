<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_pdfquiz', get_string('pluginname', 'local_pdfquiz'));

    $checkurl = (new moodle_url('/local/pdfquiz/admin_check.php'))->out(false);
    $settings->add(new admin_setting_heading(
        'local_pdfquiz/heading',
        get_string('settingsheading', 'local_pdfquiz'),
        get_string('settingsdesc', 'local_pdfquiz', $checkurl)
    ));

    $settings->add(new admin_setting_configtext(
        'local_pdfquiz/serviceurl',
        get_string('setting_serviceurl', 'local_pdfquiz'),
        get_string('setting_serviceurl_desc', 'local_pdfquiz'),
        'http://127.0.0.1:8002',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_pdfquiz/apikey',
        get_string('setting_apikey', 'local_pdfquiz'),
        get_string('setting_apikey_desc', 'local_pdfquiz'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_pdfquiz/allowuserapikey',
        get_string('setting_allowuserapikey', 'local_pdfquiz'),
        get_string('setting_allowuserapikey_desc', 'local_pdfquiz'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_pdfquiz/model',
        get_string('setting_model', 'local_pdfquiz'),
        get_string('setting_model_desc', 'local_pdfquiz'),
        'gemini/gemini-3.5-flash-lite',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_pdfquiz/maxfilesize',
        get_string('setting_maxfilesize', 'local_pdfquiz'),
        get_string('setting_maxfilesize_desc', 'local_pdfquiz'),
        '50',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_pdfquiz/maxpages',
        get_string('setting_maxpages', 'local_pdfquiz'),
        get_string('setting_maxpages_desc', 'local_pdfquiz'),
        '100',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_pdfquiz/timeout',
        get_string('setting_timeout', 'local_pdfquiz'),
        get_string('setting_timeout_desc', 'local_pdfquiz'),
        '300',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_pdfquiz/enablevision',
        get_string('setting_enablevision', 'local_pdfquiz'),
        get_string('setting_enablevision_desc', 'local_pdfquiz'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_pdfquiz/enableocr',
        get_string('setting_enableocr', 'local_pdfquiz'),
        get_string('setting_enableocr_desc', 'local_pdfquiz'),
        1
    ));

    $ADMIN->add('localplugins', $settings);
}

