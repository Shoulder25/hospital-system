<section class="admin-panel">
    <div class="admin-panel-heading"><div><h2>Search patient records</h2><p>Use patient number, national ID, first or last name, or phone number.</p></div></div>
    <form class="reception-search-form" data-patient-search-form>
        <label class="search-field reception-search-field"><span aria-hidden="true">⌕</span><span class="visually-hidden">Patient search</span><input name="search" type="search" maxlength="100" placeholder="Patient number, ID, name or phone" required></label>
        <button class="button button-primary" type="submit">Search</button>
    </form>
    <div class="table-wrap"><table class="admin-table reception-table"><thead><tr><th>Patient</th><th>Patient number</th><th>Date of birth</th><th>Phone</th><th></th></tr></thead><tbody data-patient-results><tr><td class="table-message" colspan="5">Search for a patient to see matching records.</td></tr></tbody></table></div>
</section>
