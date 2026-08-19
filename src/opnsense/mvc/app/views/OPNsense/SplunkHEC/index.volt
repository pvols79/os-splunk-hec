{#
 # Copyright (C) 2026 pvols79
 # All rights reserved.
 # SPDX-License-Identifier: BSD-2-Clause
 #}

<script>
    $(document).ready(function () {

        /* Load current settings from the API into the form */
        mapDataToFormUI({'frm_GeneralSettings': '/api/splunkhec/service/get'}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        /* Save button — collects form and posts to the set endpoint */
        $('#saveAct').SimpleActionButton({
            onPreAction: function () {
                return saveFormToEndpoint(
                    '/api/splunkhec/service/set',
                    'frm_GeneralSettings',
                    function () {},
                    true
                );
            }
        });

    });
</script>

<div class="content-box" style="padding-bottom: 1.5em;">
    <div class="content-box-main">
        <div class="table-responsive">
            <div class="col-md-12">
                <form id="frm_GeneralSettings">
                    <table class="table table-striped table-condensed">
                        <tbody>
                            <tr>
                                <td colspan="2">
                                    <strong>{{ lang._('Splunk HEC Exporter') }}</strong>
                                </td>
                            </tr>
                            <tr>
                                <td style="width:30%">
                                    <label for="general.enabled">{{ lang._('Enable Exporter') }}</label>
                                </td>
                                <td>
                                    <input type="checkbox" id="general.enabled" name="general.enabled">
                                    <small class="text-muted">
                                        {{ lang._('Enable or disable the Splunk HEC log forwarder.') }}
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <label for="general.endpoint">{{ lang._('HEC Endpoint URL') }}</label>
                                </td>
                                <td>
                                    <input class="form-control" type="text"
                                           id="general.endpoint" name="general.endpoint"
                                           placeholder="https://splunk.example.com:8088/services/collector">
                                    <small class="text-muted">
                                        {{ lang._('Full URL to the Splunk HTTP Event Collector.') }}
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <label for="general.token">{{ lang._('HEC Token') }}</label>
                                </td>
                                <td>
                                    <input class="form-control" type="password"
                                           id="general.token" name="general.token">
                                    <small class="text-muted">
                                        {{ lang._('Authentication token for the Splunk HEC input.') }}
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <label for="general.logs">{{ lang._('Log Files') }}</label>
                                </td>
                                <td>
                                    <input class="form-control" type="text"
                                           id="general.logs" name="general.logs"
                                           placeholder="/var/log/system.log,/var/log/filter.log">
                                    <small class="text-muted">
                                        {{ lang._('Comma-separated list of log file paths to forward.') }}
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <label for="general.cache_size">{{ lang._('Cache Size (MB)') }}</label>
                                </td>
                                <td>
                                    <input class="form-control" type="number"
                                           id="general.cache_size" name="general.cache_size">
                                    <small class="text-muted">
                                        {{ lang._('Maximum on-disk cache size before purging.') }}
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <label for="general.cache_time">{{ lang._('Cache Retention (hours)') }}</label>
                                </td>
                                <td>
                                    <input class="form-control" type="number"
                                           id="general.cache_time" name="general.cache_time">
                                    <small class="text-muted">
                                        {{ lang._('Maximum age of cached payloads before purging.') }}
                                    </small>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </form>
            </div>
        </div>
    </div>
</div>

<section class="page-content-main">
    <div class="content-box">
        <div class="col-md-12" style="padding: 1em;">
            <button class="btn btn-primary" id="saveAct" type="button">
                <b>{{ lang._('Apply') }}</b>
                <i id="saveAct_progress"></i>
            </button>
        </div>
    </div>
</section>
