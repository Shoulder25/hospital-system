<div class="admin-welcome emergency-welcome">
    <div><p class="eyebrow">EMERGENCY DOCTOR</p><h1 id="emergency-doctor-heading">Specialist and outside referrals</h1><p class="dashboard-subtitle">Create referral records using existing resident/locum and outside-facility workflows.</p></div>
    <a class="button button-outline" href="doctor-assessment.php">Clinical assessment</a>
</div>
<?php require __DIR__ . '/_doctor-case-context.php'; ?>
<section class="admin-panel emergency-doctor-panel emergency-doctor-workspace">
    <form class="emergency-form" data-doctor-form="referral">
        <h2>Record referral</h2>
        <input type="hidden" name="consultation_id">
        <label>Referral destination
            <select name="referral_type" data-referral-type><option value="specialist">Resident / locum specialist</option><option value="outside">Outside facility</option></select>
        </label>
        <label data-specialist-field>Resident or locum specialist<select name="specialist_user_id" data-specialist-options><option value="">Loading specialists…</option></select></label>
        <label data-reason-field>Referral reason<textarea name="reason" rows="2" maxlength="5000"></textarea></label>
        <label data-provider-field hidden>Outside facility name (optional)<input name="provider_name" maxlength="150"></label>
        <label>Clinical summary<textarea name="clinical_summary" rows="4" maxlength="10000"></textarea></label>
        <label class="emergency-doctor-checkbox" data-outside-tests-field hidden><input type="checkbox" name="outside_tests_payable" checked><span>Outside tests are payable by the patient (record only; no price is invented)</span></label>
        <button class="button button-primary" type="submit">Save referral</button>
    </form>
    <section class="emergency-doctor-subsection">
        <h2>Recorded referrals</h2>
        <div class="table-wrap"><table class="admin-table emergency-history-table"><thead><tr><th>Destination</th><th>Type</th><th>Status</th><th>Reason / summary</th><th>Date</th></tr></thead><tbody data-referral-list><tr><td colspan="5" class="table-message">No referrals recorded.</td></tr></tbody></table></div>
    </section>
</section>
