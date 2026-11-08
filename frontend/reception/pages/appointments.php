<div class="reception-workspace">
    <section class="admin-panel">
        <div class="admin-panel-heading"><div><h2>Schedule an appointment</h2><p>Search and select an existing patient. A visit is created separately at check-in.</p></div></div>
        <form class="reception-search-form" data-appointment-search><label class="search-field reception-search-field"><span aria-hidden="true">⌕</span><span class="visually-hidden">Find patient</span><input name="search" type="search" maxlength="100" placeholder="Patient number, name or phone" required></label><button class="button button-outline" type="submit">Find</button></form>
        <div class="table-wrap"><table class="admin-table"><thead><tr><th>Patient</th><th>Patient number</th><th></th></tr></thead><tbody data-appointment-patient-results><tr><td class="table-message" colspan="3">Find a patient to schedule an appointment.</td></tr></tbody></table></div>
        <div class="selected-patient" data-appointment-selected><span class="selected-patient-icon" aria-hidden="true">♙</span><span data-appointment-selected-label>No patient selected</span></div>
        <form class="reception-form" data-appointment-form>
            <input type="hidden" name="patient_id">
            <div class="reception-field"><label for="appointment-date">Date and time <span>*</span></label><input id="appointment-date" name="scheduled_at" type="datetime-local" required></div>
            <div class="reception-field reception-field-wide"><label for="appointment-reason">Reason for appointment <span>*</span></label><textarea id="appointment-reason" name="reason" maxlength="500" required></textarea></div>
            <div class="reception-field reception-field-wide"><button class="button button-primary" type="submit" disabled data-appointment-submit>Schedule appointment</button></div>
        </form>
    </section>
</div>
<section class="admin-panel reception-list-panel">
    <div class="admin-panel-heading"><div><h2>Appointments</h2><p>Schedule, reschedule, cancel or check in appointments.</p></div><button class="button button-outline" type="button" data-refresh-appointments>Refresh</button></div>
    <div class="table-wrap"><table class="admin-table reception-table"><thead><tr><th>Patient</th><th>Patient number</th><th>Date and time</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead><tbody data-appointment-rows><tr><td class="table-message" colspan="6">Loading appointments…</td></tr></tbody></table></div>
</section>
