<div class="admin-welcome emergency-welcome">
    <div><p class="eyebrow">EMERGENCY DOCTOR</p><h1 id="emergency-doctor-heading">Clinical assessment and diagnosis</h1><p class="dashboard-subtitle">Review the nurse handover, record your independent findings, and save preliminary or final diagnoses.</p></div>
    <a class="button button-outline" href="doctor-queue.php">Awaiting Doctor</a>
</div>
<?php require __DIR__ . '/_doctor-case-context.php'; ?>
<section class="admin-panel emergency-doctor-panel emergency-doctor-workspace" data-doctor-workspace>
    <form class="emergency-form" data-doctor-form="assessment">
        <h2>Clinical assessment</h2>
        <label>Presenting complaint<textarea name="presenting_complaint" rows="3" maxlength="5000"></textarea></label>
        <div class="emergency-form-grid">
            <label>Clinical observations<textarea name="clinical_observations" rows="4" maxlength="10000"></textarea></label>
            <label>Examination findings<textarea name="examination_findings" rows="4" maxlength="10000"></textarea></label>
        </div>
        <label>Assessment notes<textarea name="notes" rows="3" maxlength="10000"></textarea></label>
        <button class="button button-primary" type="submit">Save assessment</button>
    </form>
    <form class="emergency-form emergency-doctor-divider" data-doctor-form="diagnosis">
        <h2>Diagnosis</h2>
        <input type="hidden" name="consultation_id">
        <label>Was a diagnosis made?
            <select name="diagnosis_made"><option value="true">Yes</option><option value="false">No</option></select>
        </label>
        <label>Diagnosis stage
            <select name="diagnosis_stage"><option value="preliminary">Preliminary</option><option value="final">Final</option></select>
        </label>
        <label>Diagnosis description<textarea name="diagnosis_description" rows="2" maxlength="5000"></textarea></label>
        <label>Clinical findings<textarea name="clinical_findings" rows="3" maxlength="10000"></textarea></label>
        <label>Treatment plan<textarea name="treatment_plan" rows="3" maxlength="10000"></textarea></label>
        <label>Additional notes<textarea name="notes" rows="2" maxlength="10000"></textarea></label>
        <button class="button button-primary" type="submit">Save diagnosis</button>
    </form>
</section>
