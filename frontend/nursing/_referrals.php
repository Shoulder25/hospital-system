<div class="admin-welcome nursing-welcome">
    <div><p class="eyebrow">NURSING HANDOFFS</p><h1 id="nursing-heading">Doctor referrals</h1><p class="dashboard-subtitle">Nursing assessments routed to the existing General Doctor queue.</p></div>
    <label class="search-field nursing-search"><span aria-hidden="true">⌕</span><span class="visually-hidden">Search doctor referrals</span><input type="search" data-referral-search maxlength="100" placeholder="Search patient, number or visit"></label>
</div>
<section class="admin-panel nursing-queue-panel" aria-labelledby="referrals-heading">
    <div class="admin-panel-heading"><div><h2 id="referrals-heading">Referred visits</h2><p>Referral reason is shown from the recorded complaint, symptoms and nursing observations.</p></div><button class="button button-outline nursing-refresh-button" type="button" data-refresh-referrals>Refresh</button></div>
    <div class="table-wrap"><table class="admin-table nursing-table nursing-history-table">
        <thead><tr><th>Visit / patient</th><th>Source</th><th>Referred at</th><th>Referring nurse</th><th>Reason / assessment findings</th><th>Current status</th></tr></thead>
        <tbody data-referral-rows><tr><td class="table-message" colspan="6">Loading doctor referrals…</td></tr></tbody>
    </table></div>
</section>