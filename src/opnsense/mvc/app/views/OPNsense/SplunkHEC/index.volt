{#
 OPNsense Splunk HEC Plugin — Settings Page

 @license BSD-2-Clause
#}

<script>
    $(document).ready(function () {
        mapDataToFormUI({'frm_splunkhec': '/api/splunkhec/service/get'}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        // Save button handler
        $('#btn_save').unbind('click').click(function () {
            saveFormToEndpoint('/api/splunkhec/service/set', 'frm_splunkhec', function (data) {
                if (data.result && data.result !== 'failed') {
                    BootstrapDialog.show({
                        type: BootstrapDialog.TYPE_SUCCESS,
                        title: '{{ lang._('Settings saved') }}',
                        message: '{{ lang._('Configuration has been saved and the service restarted.') }}',
                        buttons: [{
                            label: '{{ lang._('Close') }}',
                            action: function (dialog) {
                                dialog.close();
                            }
                        }]
                    });
                }
            });
        });

        // Status check
        function updateStatus() {
            ajaxGet('/api/splunkhec/service/status', {}, function (data) {
                var badge = data.status === 'running'
                    ? '<span class="label label-success">Running</span>'
                    : '<span class="label label-danger">Stopped</span>';
                $('#service_status').html(badge);
            });
        }
        updateStatus();
        setInterval(updateStatus, 15000);
    });
</script>

<div class="content-box">
    <div class="table-responsive">
        <table class="table table-striped">
            <colgroup>
                <col class="col-md-3"/>
                <col class="col-md-9"/>
            </colgroup>
            <thead>
                <tr>
                    <th colspan="2">
                        {{ lang._('Splunk HEC Exporter Settings') }}
                        &nbsp;
                        <span id="service_status"></span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <div id="frm_splunkhec">
                            {{ partial('layout_partials/base_form', ['fields': generalFields, 'id': 'frm_splunkhec']) }}
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="row">
        <div class="col-md-12">
            <button class="btn btn-primary" id="btn_save" type="button">
                <b>{{ lang._('Save & Apply') }}</b>
                <i id="btn_save_progress" class=""></i>
            </button>
        </div>
    </div>
</div>
