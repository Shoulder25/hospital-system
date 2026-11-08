<div class="admin-welcome nursing-welcome">
    <div><p class="eyebrow">NURSING RECORDS</p><h1 id="nursing-heading">Completed assessments</h1><p class="dashboard-subtitle">Assessments with a recorded clinical disposition, including historical visits.</p></div>
    <label class="search-field nursing-search"><span aria-hidden="true">⌕</span><span class="visually-hidden">Search completed assessments</span><input type="search" data-completed-search maxlength="100" placeholder="Search patient, number or visit"></label>
</div>
<section class="admin-panel nursing-queue-panel" aria-labelledby="completed-heading">
    <div class="admin-panel-heading"><div><h2 id="completed-heading">Assessment history</h2><p>Findings, observations, treatment outcome and current visit status.</p></div><button class="button button-outline nursing-refresh-button" type="button" data-refresh-completed>Refresh</button></div>
    <div class="table-wrap"><table class="admin-table nursing-table nursing-history-table">
        <thead><tr><th>Visit / patient</th><th>Arrival / source</th><th>Assessed at</th><th>Nurse</th><th>Findings / observations</th><th>Disposition / treatment</th><th>Visit status</th></tr></thead>
        <tbody data-completed-rows><tr><td class="table-message" colspan="7">Loading completed assessments…</td></tr></tbody>
    </table></div>
</section>