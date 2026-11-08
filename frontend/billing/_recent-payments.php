<div class="admin-welcome billing-welcome">
    <div><p class="eyebrow">PAYMENT HISTORY</p><h1 id="billing-heading">Recent payments</h1><p class="dashboard-subtitle">Review recorded payments and open approved receipts.</p></div>
    <div class="billing-demo-badge">CARD / MEDICAL AID SIMULATED</div>
</div>
<section class="admin-panel billing-panel">
    <div class="admin-panel-heading billing-panel-heading">
        <div><h2>Recent payments</h2><p>Approved and other recorded payment attempts within this date range.</p></div>
        <form class="billing-date-range" data-date-range><label>From <input type="date" name="from" required></label><label>To <input type="date" name="to" required></label><button class="button button-outline button-small" type="submit">Apply dates</button></form>
    </div>
    <div class="billing-table-wrap">
        <table class="admin-table billing-table">
            <thead><tr><th>Date</th><th>Patient</th><th>Visit</th><th>Cashier</th><th>Method</th><th>Status</th><th>Amount</th><th>Receipt</th></tr></thead>
            <tbody data-recent-payments><tr><td class="table-message" colspan="8">Loading recent payments…</td></tr></tbody>
        </table>
    </div>
</section>
<section class="admin-panel billing-panel configured-fees-panel">
    <div class="admin-panel-heading"><div><h2>Active configured fees</h2><p>Read-only prices. Department-managed charges are added by their responsible workflows.</p></div></div>
    <div class="billing-missing-fees" data-missing-fees hidden role="status"></div>
    <div class="billing-fees" data-configured-fees></div>
</section>