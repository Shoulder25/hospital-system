<div class="admin-welcome billing-welcome">
    <div><p class="eyebrow">ARRIVAL PAYMENT</p><h1 id="billing-heading">Initial ticket payments</h1><p class="dashboard-subtitle">Collect the configured arrival fee before eligible visits enter nursing.</p></div>
    <div class="billing-demo-badge">CARD / MEDICAL AID SIMULATED</div>
</div>
<section class="admin-panel billing-panel" id="ticket-payments" aria-labelledby="queue-heading">
    <div class="admin-panel-heading billing-panel-heading">
        <div><h2 id="queue-heading">Eligible visits</h2><p>Emergency and medication-collection-only visits do not pay an initial ticket.</p></div>
        <button class="button button-outline button-small" type="button" data-refresh-ticket-queue>Refresh</button>
    </div>
    <div class="billing-table-wrap">
        <table class="admin-table billing-table">
            <thead><tr><th>Patient</th><th>Visit</th><th>Source</th><th>Organization</th><th>Arrived</th><th>Ticket fee</th><th>Action</th></tr></thead>
            <tbody data-billing-rows><tr><td class="table-message" colspan="7">Loading ticket-payment queue…</td></tr></tbody>
        </table>
    </div>
</section>
<section class="admin-panel billing-visit-panel" data-ticket-visit hidden aria-labelledby="ticket-heading">
    <div class="admin-panel-heading">
        <div><p class="eyebrow">ARRIVAL PAYMENT</p><h2 id="ticket-heading">Initial ticket fee</h2><p data-ticket-summary></p></div>
        <button class="button button-outline button-small" type="button" data-close-ticket>Close</button>
    </div>
    <dl class="billing-visit-details">
        <div><dt>Patient number</dt><dd data-ticket-patient-number></dd></div>
        <div><dt>Visit source</dt><dd data-ticket-source></dd></div>
        <div><dt>Arrived</dt><dd data-ticket-arrived></dd></div>
        <div><dt>Current status</dt><dd data-ticket-status></dd></div>
        <div><dt>Configured ticket fee</dt><dd data-ticket-fee></dd></div>
    </dl>
    <form class="billing-payment-form billing-visit-payment" data-ticket-payment-form>
        <label for="ticket-payment-method">Payment method</label>
        <select id="ticket-payment-method" name="payment_method" required><option value="cash">Cash</option><option value="card">Card (simulated)</option><option value="medical_aid">Medical aid (simulated)</option></select>
        <button class="button button-primary" type="submit" data-ticket-payment-submit>Take ticket payment</button>
    </form>
    <p class="billing-note">This initial ticket flow uses the active fee catalog and, after success, sends the eligible visit to Nursing.</p>
</section>