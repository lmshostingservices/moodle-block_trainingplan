define(['jquery'], function ($) {
    const admin = {};
    // helper for timestamped logs
    function log(...args) {
        const now = new Date().toISOString().split('T')[1];
        // debug logging removed for production
    }

    function stamp(msg) {
        $('#tp-status').text(msg).show();
        setTimeout(() => $('#tp-status').fadeOut(500), 2000);
        log(msg);
    }

    function escapeHtml(str) {
    return String(str)
        //.replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    function renderMarksheet(course) {
        if (course.marksheetstatus === 'approved' && course.marksheeturl) {
            return `<a href="${escapeHtml(course.marksheeturl)}" target="_blank" rel="noopener">Approved</a>`;
        }

        return '<span class="badge bg-warning text-dark">pending</span>';
    }

    function updateReminderButton() {
        const count = $('#tp-table tbody .tp-select-row:checked').length;
        $('#tp-send-reminder-btn').prop('disabled', count === 0);
        $('#tp-reminder-count').text(count);
    }

    function renderRows(rows) {
        const $tb = $('#tp-table tbody');
        $tb.empty();

        if (!rows || !rows.length) {
            $tb.append('<tr><td colspan="12" class="text-center text-muted">No training plans found.</td></tr>');
            log('renderRows: no rows');
            updateReminderButton();
            return;
        }

        log('renderRows: drawing', rows.length, 'grouped rows');

        rows.forEach(r => {
            if (!r.courses || !r.courses.length) {
                return;
            }

            // Default course = first one (we'll later change this to "active")
            const first = r.courses[0];

            // Build dropdown options for all courses
            const items = r.courses.map(c => `
            <li>
                <a class="dropdown-item tp-course-item"
                href="#"
                data-courseid="${c.courseid}"
                data-start="${c.startdate}"
                data-end="${c.enddate}"
                data-progress="${c.progress}"
                data-status="${c.status}"
                data-marksheetstatus="${c.marksheetstatus || 'pending'}"
                data-marksheeturl="${escapeHtml(c.marksheeturl || '')}"
                data-signdate="${c.signdate}">
                ${escapeHtml(c.coursename)}
                </a>
            </li>
            `).join('');

            const tr = `
            <tr data-userid="${r.userid}" data-cohortid="${r.cohortid}">
            <td><input type="checkbox" class="tp-select-row" value="${r.userid}"></td>
            <td>${escapeHtml(r.student)}</td>
            <td>${escapeHtml(r.email)}</td>
            <td>${escapeHtml(r.cohort)}</td>
            <td>
                <div class="dropdown tp-course-dd">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle tp-course-btn"
                        type="button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false">
                    ${escapeHtml(first.coursename)}
                </button>
                <ul class="dropdown-menu tp-course-menu">
                    ${items}
                </ul>
                </div>
            </td>
            <td class="tp-start">${first.startdate}</td>
            <td class="tp-end">${first.enddate}</td>
            <td class="tp-progress">${first.progress}%</td>
            <td class="tp-status">${first.status}</td>
            <td class="tp-marksheet">${renderMarksheet(first)}</td>
            <td class="tp-signed">
                ${first.signdate}<br>
                ${first.signature ? `<img src="${first.signature}" style="height:40px;">` : ''}
            </td>
            <td>
                <a href="${r.editurl}" class="btn btn-warning btn-sm tp-edit">Edit</a>
            </td>
            </tr>`;

            $tb.append(tr);
        });

        // Reset select-all and reminder button after re-render
        $('#tp-select-all').prop('checked', false);
        updateReminderButton();
    }


    function fetchRows(ajaxBase, sesskey, cohortid, page = 1, search = '') {
        log('fetchRows start', { ajaxBase, sesskey, cohortid, page, search });
        return $.ajax({
            url: ajaxBase,
            method: 'GET',
            data: { action: 'list_rows', sesskey, cohortid, page, search },
            dataType: 'json'
        }).done(data => {
            log('fetchRows success', data);
            if (data.status === 'ok') {
                renderRows(data.rows);
                updatePagination(data.page, data.pages, cohortid);
            } else {
                renderRows([]);
                stamp('Server returned error status');
            }
        }).fail((xhr, text, err) => {
            log('fetchRows fail', text, err, xhr.responseText);
            stamp('AJAX error');
        });
    }


    function updatePagination(current, total, cohortid) {
        $('#tp-page').text(current);
        $('#tp-pages').text(total);
        $('#tp-prev').prop('disabled', current <= 1);
        $('#tp-next').prop('disabled', current >= total);

        // Store current page for reuse
        $('#tp-pagination').data('page', current);
        $('#tp-pagination').data('cohortid', cohortid);
    }

    admin.init = function (ajaxBase, sesskey) {
        const $cohort = $('#tp-cohort');

        log('init: starting', { ajaxBase, sesskey });

        // Prevent any button submitting forms / reloading.
        $('button').attr('type', 'button');

        // Initial load
        fetchRows(ajaxBase, sesskey, 0);

        // Dropdown change
        $cohort.on('change', function (e) {
            e.preventDefault();
            const cid = $(this).val();
            log('Dropdown changed', cid);
            fetchRows(ajaxBase, sesskey, cid);
        });

        // Refresh
        $('#tp-refresh').on('click', function (e) {
            e.preventDefault();
            const cid = $cohort.val();
            log('Refresh clicked', cid);
            fetchRows(ajaxBase, sesskey, cid).then(() => stamp('Refreshed'));
        });

        // Export
        $('#tp-export').on('click', function (e) {
            e.preventDefault();
            const cid = $cohort.val();
            log('Export clicked', cid);
            window.location = `${ajaxBase}?action=export_csv&sesskey=${sesskey}&cohortid=${cid}`;
        });

        // Select all checkbox
        $(document).on('change', '#tp-select-all', function () {
            const checked = $(this).prop('checked');
            $('#tp-table tbody .tp-select-row').prop('checked', checked);
            updateReminderButton();
        });

        // Individual row checkbox
        $('#tp-table').on('change', '.tp-select-row', function () {
            const total = $('#tp-table tbody .tp-select-row').length;
            const checked = $('#tp-table tbody .tp-select-row:checked').length;
            $('#tp-select-all').prop('checked', total > 0 && total === checked);
            updateReminderButton();
        });

        // Open reminder modal
        $('#tp-send-reminder-btn').on('click', function (e) {
            e.preventDefault();
            const count = $('#tp-table tbody .tp-select-row:checked').length;
            if (count === 0) { return; }
            $('#tp-reminder-count').text(count);
            $('#tp-reminder-message').val('');
            $('#tp-reminder-modal').show();
        });

        // Close reminder modal
        $('#tp-reminder-cancel').on('click', function (e) {
            e.preventDefault();
            $('#tp-reminder-modal').hide();
        });

        // Close modal on backdrop click
        $('#tp-reminder-modal').on('click', function (e) {
            if (e.target === this) { $(this).hide(); }
        });

        // Send reminders
        $('#tp-reminder-send').on('click', function (e) {
            e.preventDefault();
            const message = $('#tp-reminder-message').val().trim();
            if (!message) {
                alert('Please enter a message.');
                return;
            }
            const userids = $('#tp-table tbody .tp-select-row:checked').map(function () {
                return $(this).val();
            }).get();

            if (!userids.length) { return; }

            $('#tp-reminder-send').prop('disabled', true).text('Sending...');

            $.ajax({
                url: ajaxBase,
                method: 'POST',
                data: {
                    action: 'send_reminders',
                    sesskey: sesskey,
                    userids: userids,
                    message: message
                },
                traditional: true,
                dataType: 'json'
            }).done(data => {
                $('#tp-reminder-modal').hide();
                if (data.status === 'ok') {
                    stamp('Reminders sent to ' + userids.length + ' learner(s).');
                    // Uncheck all rows
                    $('#tp-table tbody .tp-select-row').prop('checked', false);
                    $('#tp-select-all').prop('checked', false);
                    updateReminderButton();
                } else {
                    stamp('Send failed: ' + (data.error || 'unknown error'));
                }
            }).fail((xhr, text, err) => {
                $('#tp-reminder-modal').hide();
                stamp('AJAX error sending reminders');
                log('send_reminders fail', text, err, xhr.responseText);
            }).always(() => {
                $('#tp-reminder-send').prop('disabled', false).text('Send');
            });
        });

        // Save first-unit start date
        $('#tp-table').on('click', '.tp-save', function (e) {
            e.preventDefault();
            const $row = $(this).closest('tr');
            const userid = $row.data('userid');
            const cohortid = $row.data('cohortid');
            const input = $row.find('.tp-start').val();
            const timestamp = Math.floor(new Date(input).getTime() / 1000);
            log('Save clicked', { userid, cohortid, input, timestamp });

            if (!timestamp) {
                stamp('Invalid date');
                return;
            }

            $.ajax({
                url: ajaxBase,
                method: 'POST',
                data: {
                    action: 'edit_startdate',
                    sesskey,
                    userid,
                    cohortid,
                    startdate: timestamp
                },
                dataType: 'json'
            }).done(data => {
                log('edit_startdate response', data);
                if (data.status === 'ok') {
                    renderRows(data.rows || []);
                    stamp('Start date updated');
                } else {
                    stamp('Save failed');
                }
            }).fail((xhr, text, err) => {
                log('edit_startdate fail', text, err, xhr.responseText);
                stamp('AJAX error');
            });
        });

        // When admin changes the course dropdown
        $('#tp-table').on('click', '.tp-course-item', function (e) {
            e.preventDefault();

            const $item = $(this);
            const $row = $item.closest('tr');

            // Update button text
            $row.find('.tp-course-btn').text($item.text());

            // Update row values
            $row.find('.tp-start').text($item.data('start'));
            $row.find('.tp-end').text($item.data('end'));
            $row.find('.tp-progress').text($item.data('progress') + '%');
            $row.find('.tp-status').text($item.data('status'));
            $row.find('.tp-marksheet').html(renderMarksheet({
                marksheetstatus: $item.data('marksheetstatus'),
                marksheeturl: $item.data('marksheeturl')
            }));
            $row.find('.tp-signed').text($item.data('signdate') || '');
        });


        const $search = $('#tp-search');
        let searchTimer = null;

        $search.on('input', function () {
            const search = $(this).val();
            const cohortid = $('#tp-cohort').val();

            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                log('Search triggered', search);
                fetchRows(ajaxBase, sesskey, cohortid, 1, search);
            }, 400); // debounce: wait 400ms after typing stops
        });


        $('#tp-prev').on('click', function() {
            const $p = $('#tp-pagination');
            const page = Math.max(1, ($p.data('page') || 1) - 1);
            const cohortid = $p.data('cohortid') || $('#tp-cohort').val();
            const search = $('#tp-search').val();
            fetchRows(ajaxBase, sesskey, cohortid, page, search);
        });

        $('#tp-next').on('click', function() {
            const $p = $('#tp-pagination');
            const total = parseInt($('#tp-pages').text(), 10);
            const page = Math.min(total, ($p.data('page') || 1) + 1);
            const cohortid = $p.data('cohortid') || $('#tp-cohort').val();
            const search = $('#tp-search').val();
            fetchRows(ajaxBase, sesskey, cohortid, page, search);
        });

        $('#tp-table').on('click', '.tp-edit', function (e) {
            e.preventDefault();
            const userid = $(this).closest('tr').data('userid');
            window.location = M.cfg.wwwroot + '/blocks/trainingplan/edit.php?userid=' + userid;
        });




    };
    return admin;
});
