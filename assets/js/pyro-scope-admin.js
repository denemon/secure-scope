jQuery(document).ready(function($) {
    'use strict';

    $('#pyro-scope-run-scan').on('click', function() {
        const scanButton = $(this);
        const log = $('#pyro-scope-scan-log');
        const progressBar = $('#pyro-scope-progress-bar');
        const progressContainer = $('#pyro-scope-progress-container');
        const results = $('#pyro-scope-results-container');
        let scanId = '';

        scanButton.prop('disabled', true);
        log.text('Scan initialized...\n');
        results.empty();
        progressContainer.show();
        progressBar.css('width', '0%').css('background-color', '#0a0').attr('aria-valuenow', '0');

        function request(step) {
            return $.ajax({
                url: PyroScopeAjax.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'pyro_scope_run_scan',
                    _ajax_nonce: PyroScopeAjax.nonce,
                    step: step,
                    scan_id: scanId
                }
            });
        }

        function appendLines(lines) {
            lines.forEach(function(line) {
                log[0].appendChild(document.createTextNode(line + '\n'));
            });
            log.scrollTop(log[0].scrollHeight);
        }

        function fail(jqXHR, textStatus, errorThrown) {
            const response = jqXHR.responseJSON;
            const message = response && response.data && response.data.message
                ? response.data.message
                : textStatus + (errorThrown ? ' - ' + errorThrown : '');

            appendLines(['Scan failed: ' + message]);
            progressBar.css('width', '100%').css('background-color', '#d9534f').attr('aria-valuenow', '100');
            if (scanId) {
                request('cancel').always(function() {
                    scanButton.prop('disabled', false);
                });
            } else {
                scanButton.prop('disabled', false);
            }
        }

        function finish() {
            request('finish').done(function(resp) {
                results.html(resp.data.html);
                appendLines([resp.data.incomplete ? '--- Scan Incomplete ---' : '--- Scan Complete ---']);
                progressBar.css('width', '100%').attr('aria-valuenow', '100');
                if (resp.data.incomplete) {
                    progressBar.css('background-color', '#d9534f');
                }
                scanButton.prop('disabled', false);
            }).fail(fail);
        }

        function runSteps(steps, index) {
            if (index >= steps.length) {
                finish();
                return;
            }

            request(steps[index]).done(function(resp) {
                appendLines(resp.data.log);
                const nextIndex = resp.data.step_complete ? index + 1 : index;
                const progress = Math.round(nextIndex / (steps.length + 1) * 100);
                progressBar.css('width', progress + '%').attr('aria-valuenow', progress);
                runSteps(steps, nextIndex);
            }).fail(fail);
        }

        request('start').done(function(resp) {
            scanId = resp.data.scan_id;
            runSteps(resp.data.steps, 0);
        }).fail(fail);
    });
});
