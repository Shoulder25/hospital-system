<div class="admin-welcome emergency-welcome">
    <div><p class="eyebrow">EMERGENCY DOCTOR</p><h1 id="emergency-doctor-heading">Treatment and management</h1><p class="dashboard-subtitle">Record treatment, stabilization and instructions that the Emergency Nurse can see.</p></div>
    <a class="button button-outline" href="doctor-assessment.php">Clinical assessment</a>
</div>
<?php require __DIR__ . '/_doctor-case-context.php'; ?>
<section class="admin-panel emergency-doctor-panel emergency-doctor-workspace">
    <form class="emergency-form" data-doctor-form="treatment">
        <h2>Progress update</h2>
        <label>Treatment / stabilization provided<textarea name="treatment" rows="5" maxlength="10000"></textarea></label>
        <label>Instructions for Emergency Nurse<textarea name="instructions" rows="5" maxlength="10000"></textarea></label>
        <p>These notes are shared with the assigned Emergency Nurse. No medication, dose, or protocol is prefilled.</p>
        <button class="button button-primary" type="submit">Save progress update</button>
    </form>
</section>
