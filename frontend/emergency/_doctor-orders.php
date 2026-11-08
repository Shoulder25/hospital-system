<div class="admin-welcome emergency-welcome">
    <div><p class="eyebrow">EMERGENCY DOCTOR</p><h1 id="emergency-doctor-heading">Investigations and prescriptions</h1><p class="dashboard-subtitle">Orders use the existing testing queues and medicine catalogue; charges stay in the existing payment workflow.</p></div>
    <a class="button button-outline" href="doctor-assessment.php">Clinical assessment</a>
</div>
<?php require __DIR__ . '/_doctor-case-context.php'; ?>
<section class="admin-panel emergency-doctor-panel emergency-doctor-workspace">
    <form class="emergency-form" data-doctor-form="orders">
        <h2>Supported in-house investigations</h2>
        <fieldset class="emergency-doctor-test-options">
            <legend>Choose investigations</legend>
            <label><input type="checkbox" name="test_types[]" value="blood"> Blood</label>
            <label><input type="checkbox" name="test_types[]" value="glucose"> Glucose</label>
            <label><input type="checkbox" name="test_types[]" value="urine"> Urine</label>
            <label><input type="checkbox" name="test_types[]" value="pregnancy"> Pregnancy</label>
            <label><input type="checkbox" name="test_types[]" value="pap_smear"> Pap smear</label>
            <label><input type="checkbox" name="test_types[]" value="x_ray"> X-ray</label>
            <label><input type="checkbox" name="test_types[]" value="ecg"> ECG</label>
        </fieldset>
        <button class="button button-primary" type="submit">Send investigation orders</button>
    </form>
    <form class="emergency-form emergency-doctor-divider" data-doctor-form="prescription">
        <div class="emergency-doctor-form-heading"><div><h2>Prescription</h2><p>Medication must still go through existing payment / approval and dispensing.</p></div><button class="button button-outline button-small" type="button" data-add-medicine>Add medicine</button></div>
        <div class="emergency-doctor-medicine-items" data-medicine-items>
            <div class="emergency-doctor-medicine-item" data-medicine-item>
                <label>Medicine<select name="medicine_id" data-medicine-select required><option value="">Loading medicines…</option></select></label>
                <label>Quantity<input name="quantity" type="number" min="0.01" max="10000" step="0.01" required></label>
                <label>Dosage / directions<input name="dosage" maxlength="100"></label>
                <label>Frequency<input name="frequency" maxlength="100"></label>
                <label>Duration<input name="duration" maxlength="100"></label>
                <label>Instructions<input name="instructions" maxlength="2000"></label>
                <button class="button button-outline button-small" type="button" data-remove-medicine>Remove</button>
            </div>
        </div>
        <button class="button button-primary" type="submit">Save prescription for Pharmacy</button>
    </form>
    <section class="emergency-doctor-subsection">
        <h2>Investigation orders and results</h2>
        <div class="table-wrap"><table class="admin-table emergency-history-table"><thead><tr><th>Order</th><th>Department</th><th>Status</th><th>Results</th></tr></thead><tbody data-test-orders><tr><td colspan="4" class="table-message">No investigation orders recorded.</td></tr></tbody></table></div>
    </section>
    <section class="emergency-doctor-subsection">
        <h2>Existing prescriptions</h2>
        <div class="table-wrap"><table class="admin-table emergency-history-table"><thead><tr><th>Prescription</th><th>Status</th><th>Created</th><th>Prescriber</th></tr></thead><tbody data-prescriptions><tr><td colspan="4" class="table-message">No prescriptions recorded.</td></tr></tbody></table></div>
    </section>
</section>
