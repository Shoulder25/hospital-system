<div class="admin-welcome emergency-welcome">
    <div><p class="eyebrow">EMERGENCY DOCTOR</p><h1 id="emergency-doctor-heading">Patient outcome and disposition</h1><p class="dashboard-subtitle">Record an outcome for the shared emergency encounter. Discharge uses existing pharmacy/payment routing.</p></div>
    <a class="button button-outline" href="doctor-history.php">Emergency History</a>
</div>
<?php require __DIR__ . '/_doctor-case-context.php'; ?>
<section class="admin-panel emergency-doctor-panel emergency-doctor-workspace">
    <form class="emergency-form" data-doctor-form="outcome">
        <h2>Record outcome</h2>
        <label>Outcome
            <select name="outcome" required>
                <option value="observation">Continue observation</option>
                <option value="admission">Admission (record only)</option>
                <option value="transfer">Transfer (record only)</option>
                <option value="discharge">Discharge</option>
            </select>
        </label>
        <label>Outcome notes<textarea name="outcome_notes" rows="4" maxlength="10000"></textarea></label>
        <p>Admission and transfer are recorded on the encounter; this system has no linked inpatient or transport workflow. Observation keeps the encounter active. Discharge closes the encounter and defers charges to Pharmacy/payment.</p>
        <button class="button button-primary" type="submit">Save patient outcome</button>
    </form>
</section>
