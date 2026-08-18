<?php

/**
 * OPNsense Splunk HEC Plugin – General (UI) Controller
 *
 * Renders the settings page and passes model field definitions to the
 * Volt template so the standard OPNsense form helpers can build the UI.
 *
 * @license BSD-2-Clause
 */

namespace OPNsense\SplunkHEC;

use OPNsense\Base\IndexController;

class GeneralController extends IndexController
{
    /**
     * Render the main settings page.
     */
    public function indexAction()
    {
        $this->view->title = gettext('Splunk HEC');

        $this->view->generalFields = [
            [
                'id'    => 'general.enabled',
                'label' => gettext('Enable Exporter'),
                'type'  => 'checkbox',
                'help'  => gettext('Enable or disable the Splunk HEC log exporter daemon.'),
            ],
            [
                'id'    => 'general.endpoint',
                'label' => gettext('HEC Endpoint URL'),
                'type'  => 'text',
                'help'  => gettext('Full URL of the Splunk HTTP Event Collector (e.g. https://splunk.example.com:8088/services/collector).'),
            ],
            [
                'id'    => 'general.token',
                'label' => gettext('HEC Token'),
                'type'  => 'password',
                'help'  => gettext('The authentication token for the Splunk HEC input.'),
            ],
            [
                'id'    => 'general.logs',
                'label' => gettext('Log Files'),
                'type'  => 'text',
                'help'  => gettext('Comma-separated list of log file paths to forward.'),
            ],
            [
                'id'    => 'general.cache_size',
                'label' => gettext('Cache Size (MB)'),
                'type'  => 'text',
                'help'  => gettext('Maximum size of the on-disk payload cache in megabytes.'),
            ],
            [
                'id'    => 'general.cache_time',
                'label' => gettext('Cache Retention (hours)'),
                'type'  => 'text',
                'help'  => gettext('Maximum age of cached payloads before they are purged.'),
            ],
        ];

        $this->view->pick('OPNsense/SplunkHEC/index');
    }
}
