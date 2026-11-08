<section class="admin-panel">
    <div class="admin-panel-heading"><div><h2>Patient records and visit history</h2><p>Search existing records and open a profile without registering a duplicate.</p></div></div>
    <form class="reception-search-form" data-patient-search-form><label class="search-field reception-search-field"><span aria-hidden="true">⌕</span><span class="visually-hidden">Patient search</span><input name="search" type="search" maxlength="100" placeholder="Patient number, ID, name or phone" required></label><button class="button button-primary" type="submit">Search</button></form>
    <div class="table-wrap"><table class="admin-table reception-table"><thead><tr><th>Patient</th><th>Patient number</th><th>Date of birth</th><th>Phone</th><th></th></tr></thead><tbody data-patient-results><tr><td class="table-message" colspan="5">Search for a patient to see matching records.</td></tr></tbody></table></div>
</section>
<section class="admin-panel reception-list-panel" data-record-panel hidden>
    <div class="admin-panel-heading"><div><h2 data-record-title>Patient profile</h2><p>Patient demographics and shared visit history.</p></div><a class="button button-primary" data-create-visit href="arrivals.php">Create new visit</a></div>
    <dl class="patient-detail-grid" data-record-details></dl>
    <div class="table-wrap"><table class="admin-table"><thead><tr><th>Visit</th><th>Arrival</th><th>Reason</th><th>Emergency</th><th>Status</th></tr></thead><tbody data-record-visits></tbody></table></div>
</section>
