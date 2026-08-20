{#
 # Copyright (C) 2026 pvols79
 # All rights reserved.
 # SPDX-License-Identifier: BSD-2-Clause
 #}

<script>
    $(document).ready(function () {

        /* ── Load current settings from the API into the form ── */
        mapDataToFormUI({
            'frm_GeneralSettings': '/api/splunkhec/service/get'
        }).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        /* ── Save / Apply ── */
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

                    {{-- ════════════════════════════════════════════
                         General Settings
                    ════════════════════════════════════════════ --}}
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th colspan="2">{{ lang._('General') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="width:30%">
                                    <label for="general.enabled">
                                        {{ lang._('Enable Exporter') }}
                                    </label>
                                </td>
                                <td>
                                    <input type="checkbox"
                                           id="general.enabled"
                                           name="general.enabled">
                                    <small class="text-muted">
                                        {{ lang._('Enable or disable the Splunk HEC log forwarder daemon.') }}
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <label for="general.endpoint">
                                        {{ lang._('HEC Endpoint URL') }}
                                    </label>
                                </td>
                                <td>
                                    <input class="form-control" type="text"
                                           id="general.endpoint"
                                           name="general.endpoint"
                                           placeholder="https://splunk.example.com:8088/services/collector/event">
                                    <small class="text-muted">
                                        {{ lang._('Full URL to the Splunk HTTP Event Collector. Use /services/collector/event for JSON events.') }}
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <label for="general.token">
                                        {{ lang._('HEC Token') }}
                                    </label>
                                </td>
                                <td>
                                    <input class="form-control" type="password"
                                           id="general.token"
                                           name="general.token"
                                           autocomplete="new-password">
                                    <small class="text-muted">
                                        {{ lang._('Authentication token (UUID) for the Splunk HEC input.') }}
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <label for="general.cache_size">
                                        {{ lang._('Cache Size (MB)') }}
                                    </label>
                                </td>
                                <td>
                                    <input class="form-control" type="number"
                                           id="general.cache_size"
                                           name="general.cache_size"
                                           min="1" max="10000">
                                    <small class="text-muted">
                                        {{ lang._('Maximum on-disk cache size before old payloads are purged.') }}
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <label for="general.cache_time">
                                        {{ lang._('Cache Retention (hours)') }}
                                    </label>
                                </td>
                                <td>
                                    <input class="form-control" type="number"
                                           id="general.cache_time"
                                           name="general.cache_time"
                                           min="1" max="720">
                                    <small class="text-muted">
                                        {{ lang._('Maximum age of cached payloads before purging.') }}
                                    </small>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    {{-- ════════════════════════════════════════════
                         Log Sources
                    ════════════════════════════════════════════ --}}
                    <table class="table table-striped table-condensed" style="margin-top:1.5em;">
                        <thead>
                            <tr>
                                <th style="width:30%">{{ lang._('Log Source') }}</th>
                                <th style="width:15%">{{ lang._('Enable') }}</th>
                                <th>{{ lang._('Path') }}</th>
                                <th>{{ lang._('Splunk Sourcetype') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>{{ lang._('System Log') }}</strong></td>
                                <td>
                                    <input type="checkbox"
                                           id="logs.system"
                                           name="logs.system">
                                </td>
                                <td><code>/var/log/system.log</code></td>
                                <td><code>opnsense:syslog</code></td>
                            </tr>
                            <tr>
                                <td><strong>{{ lang._('Firewall Filter Log') }}</strong></td>
                                <td>
                                    <input type="checkbox"
                                           id="logs.filter"
                                           name="logs.filter">
                                </td>
                                <td><code>/var/log/filter.log</code></td>
                                <td><code>opnsense:filterlog</code></td>
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
