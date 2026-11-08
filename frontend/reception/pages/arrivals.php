<section class="admin-panel">
    <div class="admin-panel-heading"><div><h2>Find and check in a patient</h2><p>Search an existing patient first. Register new patients on the separate registration page.</p></div><a class="button button-outline" href="register-patient.php">Register new patient</a></div>
    <form class="reception-search-form" data-arrival-search><label class="search-field reception-search-field"><span aria-hidden="true">⌕</span><span class="visually-hidden">Find patient</span><input name="search" type="search" maxlength="100" placeholder="Patient number, ID, name or phone" required></label><button class="button button-outline" type="submit">Find</button></form>
    <div class="table-wrap"><table class="admin-table"><thead><tr><th>Patient</th><th>Patient number</th><th>Date of birth</th><th></th></tr></thead><tbody data-arrival-patient-results><tr><td class="table-message" colspan="4">Search and select a patient to begin check-in.</td></tr></tbody></table></div>
    <div class="selected-patient" data-arrival-selected><span class="selected-patient-icon" aria-hidden="true">♙</span><span data-arrival-selected-label>No patient selected</span></div>
</section>
<section class="admin-panel reception-list-panel">
    <div class="admin-panel-heading"><div><h2>Arrival details</h2><p>Emergency patients route immediately; standard, school, and referral visits require ticket payment before nursing.</p></div></div>
    <form class="reception-form" data-arrival-form>
        <input type="hidden" name="patient_id">
        <fieldset class="visit-type-fieldset"><legend>Arrival type</legend><label><input type="radio" name="arrival_type" value="standard" checked> Standard arrival</label><label><input type="radio" name="arrival_type" value="emergency"> Emergency</label><label><input type="radio" name="arrival_type" value="medication_collection_only"> Medication collection only</label></fieldset>
        <div class="reception-field"><label for="arrival-source">Visit source</label><select id="arrival-source" name="visit_source"><option value="walk_in">Walk-in</option><option value="school_appointment">School appointment</option><option value="referral">Referral</option></select></div>
        <div class="reception-field" data-arrival-organization-field hidden><label for="arrival-organization">School or referring facility <span>*</span></label><select id="arrival-organization" name="source_organization_id"><option value="">Choose an organization</option></select></div>
        <div class="reception-field reception-field-wide"><label for="arrival-reason">Reason for visit <span>*</span></label><textarea id="arrival-reason" name="reason_for_visit" maxlength="500" required></textarea></div>
        <div class="reception-field reception-field-wide"><label for="arrival-notes">Reception notes</label><textarea id="arrival-notes" name="reception_notes" maxlength="5000"></textarea></div>
        <div class="reception-field reception-field-wide"><button class="button button-primary" type="submit" disabled data-arrival-submit>Check in and create visit</button></div>
    </form>
</section>
<section class="admin-panel reception-list-panel">
    <div class="admin-panel-heading"><div><h2>Today's arrivals</h2><p>Live visit status shared with billing and nursing.</p></div><button class="button button-outline" type="button" data-refresh-arrivals>Refresh</button></div>
    <div class="table-wrap"><table class="admin-table reception-table"><thead><tr><th>Visit</th><th>Patient</th><th>Arrival</th><th>Reason</th><th>Route</th><th>Status</th></tr></thead><tbody data-arrival-rows><tr><td class="table-message" colspan="6">Loading arrivals…</td></tr></tbody></table></div>
</section>
