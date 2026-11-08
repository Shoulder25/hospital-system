<div class="admin-welcome nursing-welcome">
    <div><p class="eyebrow">NURSING</p><h1 id="nursing-heading">Incoming patient queue</h1><p class="dashboard-subtitle">Patients appear after ticket payment. Emergency and medication-only visits are excluded.</p></div>
    <span class="nursing-date" data-current-date></span>
</div>
<section class="admin-panel nursing-queue-panel" id="nursing-queue" aria-labelledby="queue-heading">
    <div class="admin-panel-heading nursing-queue-heading">
        <div><h2 id="queue-heading">Patients awaiting nursing assessment</h2><p>Take responsibility for a visit to start its assessment.</p></div>
        <div class="nursing-queue-controls">
            <label class="search-field nursing-search"><span aria-hidden="true">⌕</span><span class="visually-hidden">Search nursing queue</span><input type="search" data-queue-search maxlength="100" placeholder="Search patient or number"></label>
            <label class="nursing-filter-label"><span class="visually-hidden">Filter queue by assignment</span><select data-queue-filter><option value="all">All waiting visits</option><option value="unassigned">Unassigned</option><option value="assigned">Assigned</option><option value="mine">Assigned to me</option></select></label>
            <button class="button button-outline nursing-refresh-button" type="button" data-refresh-queue>Refresh queue</button>
        </div>
    </div>
    <div class="table-wrap"><table class="admin-table nursing-table">
        <thead><tr><th scope="col">Visit ID</th><th scope="col">Patient number</th><th scope="col">Patient name</th><th scope="col">Age / gender</th><th scope="col">Source</th><th scope="col">Arrival time</th><th scope="col">Status</th><th scope="col">Ticket payment</th><th scope="col">Assigned nurse</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody data-queue-rows><tr><td class="table-message" colspan="10">Loading nursing queue…</td></tr></tbody>
    </table></div>
</section>
<section class="admin-panel nursing-queue-panel" aria-labelledby="draft-heading">
    <div class="admin-panel-heading"><div><h2 id="draft-heading">Draft / in-progress assessments</h2><p>Resume an assessment saved by its assigned nurse. Drafts do not change the visit department.</p></div><button class="button button-outline nursing-refresh-button" type="button" data-refresh-drafts>Refresh drafts</button></div>
    <div class="table-wrap"><table class="admin-table nursing-table">
        <thead><tr><th scope="col">Visit ID</th><th scope="col">Patient</th><th scope="col">Source</th><th scope="col">Arrival</th><th scope="col">Draft saved</th><th scope="col">Assigned nurse</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody data-draft-rows><tr><td class="table-message" colspan="7">Loading draft assessments…</td></tr></tbody>
    </table></div>
</section>