define(['jquery', 'jqueryui'], function($) {
    const edit = {};

    function log(...args) {
        const now = new Date().toISOString().split('T')[1];
        // debug logging removed for production
    }

    function syncDateInputs(row) {
        const outcome = row.find('.tp-outcome').val();
        const isNotApplicable = outcome === 'NA';
        const $start = row.find('.tp-start');
        const $end = row.find('.tp-end');

        if (isNotApplicable) {
            $start.val('');
            $end.val('');
        }

        $start.prop('disabled', isNotApplicable);
        $end.prop('disabled', isNotApplicable);
    }

    /** ----------------------------
     * Fetch the plan for a specific cohort
     * ---------------------------- */
    function fetchCohortCourses(userid, cohortid) {
        log('➡️ fetchCohortCourses called', { userid, cohortid });

        if (!cohortid || !userid) {
            log('❌ Missing userid or cohortid', { userid, cohortid });
            $('#tp-edit-table tbody').html('<tr><td colspan="7" class="text-muted">No cohort selected.</td></tr>');
            return;
        }

        const url = `${M.cfg.wwwroot}/blocks/trainingplan/ajax.php`;
        log('📡 Sending AJAX request to', url);

        $.get(url, {
            action: 'get_user_plan',
            sesskey: M.cfg.sesskey,
            userid,
            cohortid
        })
        .done(data => {
            log('✅ AJAX success', data);

            try {
                if (typeof data === 'string') data = JSON.parse(data);
            } catch (e) {
                log('❌ JSON parse failed', e);
                $('#tp-edit-table tbody').html('<tr><td colspan="7" class="text-danger">Invalid JSON from server.</td></tr>');
                return;
            }

            if (!data || data.status !== 'ok') {
                $('#tp-edit-table tbody').html('<tr><td colspan="7" class="text-danger">Server returned invalid response.</td></tr>');
                return;
            }

            if (!data.rows || !data.rows.length) {
                $('#tp-edit-table tbody').html('<tr><td colspan="7" class="text-muted">No courses found for this cohort.</td></tr>');
                log('⚠️ No rows found', data);
                return;
            }

            renderTable(data.rows);
        })
        .fail((xhr, status, err) => {
            log('❌ AJAX fail', { status, err, response: xhr.responseText });
            $('#tp-edit-table tbody').html('<tr><td colspan="7" class="text-danger">AJAX error — check console.</td></tr>');
        });
    }
        
        function markOrderChanged() {
            $('#tp-save-order').removeClass('d-none');
        }
    /** ----------------------------
     * Render table with drag-drop + arrows
     * ---------------------------- */
    function renderTable(rows) {
        log('🧱 renderTable called with', rows.length, 'rows');

        const $tb = $('#tp-edit-table tbody');
        $tb.empty();

        rows.forEach((r) => {
            const tr = `
                <tr data-courseid="${r.courseid}">
                    <td class="text-center drag-handle" style="cursor:move;">☰</td>
                    <td>${r.coursename}</td>
                    <td>
                        <select class="form-select form-select-sm tp-type">
                            <option value="core" ${r.unittype === 'core' ? 'selected' : ''}>Core</option>
                            <option value="elective" ${r.unittype === 'elective' ? 'selected' : ''}>Elective</option>
                        </select>
                    </td>
                    <td><input type="date" class="form-control form-control-sm tp-start" value="${r.startdate}" /></td>
                    <td><input type="date" class="form-control form-control-sm tp-end" value="${r.enddate}" /></td>
                    <td>
                        <select class="form-select form-select-sm tp-outcome">
                            <option value="NYS" ${r.outcome === 'NYS' ? 'selected' : ''}>NYS</option>
                            <option value="IP"  ${r.outcome === 'IP'  ? 'selected' : ''}>IP</option>
                            <option value="C"   ${r.outcome === 'C'   ? 'selected' : ''}>C</option>
                            <option value="CT"  ${r.outcome === 'CT'  ? 'selected' : ''}>CT</option>
                            <option value="RPL" ${r.outcome === 'RPL' ? 'selected' : ''}>RPL</option>
                            <option value="NA"  ${r.outcome === 'NA'  ? 'selected' : ''}>N/A</option>
                        </select>
                        <div class="form-check mt-1">
                            <input type="checkbox" class="form-check-input tp-override" 
                                ${r.manualoverride ? 'checked' : ''}>
                            <label class="form-check-label small">Manual</label>
                        </div>
                    </td>
                    <td>
                        <div class="btn-group">
                            <button class="btn btn-light btn-sm tp-up" title="Move Up">▲</button>
                            <button class="btn btn-light btn-sm tp-down" title="Move Down">▼</button>
                            <button class="btn btn-success btn-sm tp-save">Save</button>
                        </div>
                    </td>
                </tr>`;
            $tb.append(tr);
        });

        $('#tp-edit-table tbody tr').each(function() {
            syncDateInputs($(this));
        });

        log('✅ Table render complete — enabling sortable');

            // Enable drag-sort
        try {
            $('#tp-edit-table tbody').sortable({
                axis: 'y',
                handle: '.drag-handle',
                update: function() {
                    log('🔁 Sort order changed — swapping date slots instead of recalculating');
                    markOrderChanged();

                    // Collect current start/end date slot values in their original visual order
                    const slots = [];
                    $('#tp-edit-table tbody tr').each(function() {
                        slots.push({
                            start: $(this).find('.tp-start').val() || $(this).find('.tp-start').text(),
                            end: $(this).find('.tp-end').val() || $(this).find('.tp-end').text()
                        });
                    });

                    // After reordering, reapply the *original* date slots to new positions
                    $('#tp-edit-table tbody tr').each(function(i) {
                        const slot = slots[i];
                        if (!slot) return;
                        $(this).find('.tp-start').val(slot.start).text(slot.start);
                        $(this).find('.tp-end').val(slot.end).text(slot.end);
                    });

                    log('✅ Date slots reapplied successfully (fixed timeline mode)');
                }
            });

        } catch (e) {
            log('⚠️ Sortable init failed', e);
        }
    }


    /** ----------------------------
     * Save a single row (dates/outcome)
     * ---------------------------- */
    function saveRow(userid, cohortid, row, onSuccess) {
        const data = {
            action: 'save_user_plan',
            sesskey: M.cfg.sesskey,
            userid,
            cohortid,
            courseid: row.data('courseid'),
            startdate: row.find('.tp-start').val(),
            enddate: row.find('.tp-end').val(),
            outcome: row.find('.tp-outcome').val(),
            unittype: row.find('.tp-type').val(),
            manualoverride: row.find('.tp-override').is(':checked') ? 1 : 0
        };
        log('💾 saveRow triggered', data);

        $.post(`${M.cfg.wwwroot}/blocks/trainingplan/ajax.php`, data)
            .done(res => {
                let response;
                try {
                    response = typeof res === 'string' ? JSON.parse(res) : res;
                } catch (e) {
                    log('❌ Invalid JSON response', res);
                    alert('An unexpected error occurred while saving.');
                    return;
                }

                if (response.status === 'ok') {
                    log('✅ Save OK', response);
                    if (typeof onSuccess === 'function') {
                        onSuccess();
                    }
                } else if (response.status === 'error') {
                    log('⚠️ Save validation error', response);
                    alert(response.message || 'Invalid data. Please check your inputs.');
                }
            })
            .fail(err => {
                log('❌ Save failed', err);
                alert('Failed to save training plan. Please try again.');
           });

    }

    /** ----------------------------
     * Save the new order for all rows
     * ---------------------------- */
    function saveOrder(userid, cohortid) {
        const order = [];
        $('#tp-edit-table tbody tr').each(function(i) {
            order.push({
                courseid: $(this).data('courseid'),
                orderindex: i + 1
            });
        });

        log('💾 Saving new order', order);

        $.post(`${M.cfg.wwwroot}/blocks/trainingplan/ajax.php`, {
            action: 'save_user_order',
            sesskey: M.cfg.sesskey,
            userid,
            cohortid,
            order: JSON.stringify(order)
        })
        .done(res => {
            log('✅ Order save response', res);
            $('#tp-save-order').addClass('d-none');
        })
        .fail(err => log('❌ Order save failed', err));
    }

    /** ----------------------------
     * Init page
     * ---------------------------- */
    edit.init = function(sesskey, userid) {
        const $cohort = $('#tp-cohort');
        log('🚀 init called', { sesskey, userid });

        if (!$cohort.length) {
            log('❌ Missing #tp-cohort element in DOM');
            return;
        }

        // Load first cohort automatically if present
        const initialCohort = $cohort.val();
        log('📋 Initial cohort', initialCohort);

        if (initialCohort) {
            fetchCohortCourses(userid, initialCohort);
        } else {
            log('⚠️ No cohorts found for this user');
        }

        // When admin changes cohort
        $cohort.on('change', function () {
            const cid = $(this).val();
            log('🔁 Cohort changed', cid);

            $('#tp-export-single').attr(
                'href',
                `/blocks/trainingplan/exportpdf_single.php?userid=${userid}&cohortid=${cid}`
            );

            fetchCohortCourses(userid, cid);
        });


        // Save button per row
        $('#tp-edit-table').on('click', '.tp-save', function() {
            const $row = $(this).closest('tr');
            const cohortid = $cohort.val();
            log('💾 Save clicked', { userid, cohortid });
            saveRow(userid, cohortid, $row, function() {
                // BUG FIX: Refresh the whole table after any save so that
                // cascaded date changes made by the server are immediately
                // visible — preventing staff from saving stale data for
                // other rows and silently overwriting the cascade.
                fetchCohortCourses(userid, cohortid);
            });
        });

        $('#tp-edit-table').on('change', '.tp-outcome', function() {
            const $row = $(this).closest('tr');
            const outcome = $(this).val();
            // BUG FIX: Conclusive outcomes must be protected from cron
            // reversion. Auto-tick "Manual" whenever staff pick C, CT, RPL,
            // or NA so the intent is explicit — they can untick it if they
            // want cron-managed transitions instead.
            const conclusive = ['C', 'CT', 'RPL', 'NA'];
            if (conclusive.indexOf(outcome) !== -1) {
                $row.find('.tp-override').prop('checked', true);
            }
            syncDateInputs($row);
        });

        // Up/down buttons for reordering
        $('#tp-edit-table').on('click', '.tp-up, .tp-down', function() {
            const $row = $(this).closest('tr');
            if ($(this).hasClass('tp-up')) {
                $row.prev().before($row);
            } else {
                $row.next().after($row);
            }
            markOrderChanged();
        });

        // Save order button
        $('#tp-save-order').on('click', function() {
            const cohortid = $cohort.val();
            log('💾 Save Order clicked', { userid, cohortid });
            saveOrder(userid, cohortid);
        });
    };

    return edit;
});
