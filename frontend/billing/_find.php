<section class="admin-panel billing-panel" id="patient-search" aria-labelledby="patient-search-heading">
    <div class="admin-panel-heading billing-panel-heading">
        <div><p class="eyebrow">PATIENT BILLING</p><h1 id="billing-heading">Find patient and visit</h1><p>Search by patient number, name, or visit ID. Each visit has a separate bill.</p></div>
        <form class="billing-search" data-patient-search>
            <label for="patient-billing-search">Patient number, patient name, or visit ID</label>
            <div><input id="patient-billing-search" name="search" type="search" maxlength="100" autocomplete="off" placeholder="Search patients and visits"><button class="button button-outline button-small" type="submit">Search</button></div>
        </form>
    </div>
    <div class="billing-table-wrap">
        <table class="admin-table billing-table">
            <thead><tr><th>Patient</th><th>Visit</th><th>Visit date</th><th>Visit status</th><th>Bill status</th><th>Total</th><th>Paid</th><th>Balance</th><th>Action</th></tr></thead>
            <tbody data-patient-visits><tr><td class="table-message" colspan="9">Search to find a patient and their visit history.</td></tr></tbody>
        </table>
    </div>
</section>
<section class="admin-panel billing-visit-panel" data-bill-panel hidden aria-labelledby="bill-heading">
    <div class="admin-panel-heading">
        <div><p class="eyebrow">VISIT BILL</p><h2 id="bill-heading">Selected services &amp; visit charges</h2><p data-bill-summary></p></div>
        <button class="button button-outline button-small" type="button" data-close-bill>Close</button>
    </div>
    <dl class="billing-visit-details">
        <div><dt>Patient number</dt><dd data-bill-patient-number></dd></div>
        <div><dt>Patient name</dt><dd data-bill-patient-name></dd></div>
        <div><dt>Phone</dt><dd data-bill-patient-phone></dd></div>
        <div><dt>Address</dt><dd data-bill-patient-address></dd></div>
        <div><dt>Visit date</dt><dd data-bill-visit-date></dd></div>
        <div><dt>Visit source</dt><dd data-bill-visit-source></dd></div>
        <div><dt>Current visit status</dt><dd data-bill-visit-status></dd></div>
        <div><dt>Bill reference</dt><dd data-bill-reference></dd></div>
        <div><dt>Bill status</dt><dd data-bill-status></dd></div>
    </dl>
    <section class="billing-service-adder" aria-labelledby="add-service-heading" data-service-adder hidden>
        <div><h3 id="add-service-heading">Add configured service</h3><p>Prices come from the active fee catalog. Department-managed charges must be recorded through their workflow.</p></div>
        <form data-add-service-form>
            <div><label for="billing-service">Service</label><select id="billing-service" name="fee_id" required><option value="">Choose a configured service</option></select></div>
            <div><label for="billing-service-quantity">Quantity</label><input id="billing-service-quantity" name="quantity" type="number" min="1" max="100" step="1" value="1" required></div>
            <span class="billing-service-price" data-selected-service-price aria-live="polite">Select a service to see its configured price.</span>
            <button class="button button-primary" type="submit" data-add-service>Add</button>
        </form>
    </section>
    <div class="billing-table-wrap">
        <table class="admin-table billing-items">
            <thead><tr><th>Charge type</th><th>Service</th><th>Quantity</th><th>Unit price</th><th>Subtotal</th><th>Action</th></tr></thead>
            <tbody data-bill-items><tr><td class="table-message" colspan="6">Loading bill…</td></tr></tbody>
        </table>
    </div>
    <dl class="billing-totals">
        <div><dt>Bill total</dt><dd data-bill-total></dd></div>
        <div><dt>Previously paid</dt><dd data-bill-paid></dd></div>
        <div class="billing-balance-total"><dt>Outstanding balance</dt><dd data-bill-balance></dd></div>
    </dl>
    <section class="billing-history" aria-labelledby="payment-history-heading">
        <h3 id="payment-history-heading">Payment history</h3>
        <div class="billing-table-wrap">
            <table class="admin-table billing-history-table">
                <thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th>Status</th><th>Amount</th><th>Action</th></tr></thead>
                <tbody data-bill-payments><tr><td class="table-message" colspan="6">No bill selected.</td></tr></tbody>
            </table>
        </div>
    </section>
    <form class="billing-payment-form billing-cashier-payment" data-cashier-payment hidden>
        <label for="cashier-payment-amount">Payment amount (maximum is the current balance)</label>
        <div class="billing-payment-controls">
            <input id="cashier-payment-amount" name="amount" type="number" min="0.01" step="0.01" required>
            <select name="payment_method" aria-label="Payment method" required><option value="cash">Cash</option><option value="card">Card (simulated)</option><option value="medical_aid">Medical aid (simulated)</option></select>
            <button class="button button-primary" type="submit" data-record-payment>Record payment</button>
        </div>
        <div class="billing-medical-aid-fields" data-medical-aid-fields hidden>
            <label>Saved membership<select name="membership_id" data-saved-membership><option value="">Enter a membership</option></select></label>
            <label>Scheme<select name="medical_aid_scheme_id" data-medical-aid-scheme><option value="">Select a scheme</option></select></label>
            <label>Member number<input name="member_number" type="text" maxlength="100"></label>
            <label>Member name (optional)<input name="member_name" type="text" maxlength="150"></label>
            <label for="cashier-medical-aid-outcome">Simulated medical-aid response<select id="cashier-medical-aid-outcome" name="medical_aid_outcome"><option value="approved">Simulate approval</option><option value="rejected">Simulate rejection</option></select></label>
        </div>
    </form>
    <p class="billing-note">Payments are recorded against this bill only. A payment does not complete or reroute the clinical visit.</p>
    <p class="billing-no-bill" data-no-bill hidden>This visit has no bill yet. Charges are added by the relevant workflow.</p>
</section>