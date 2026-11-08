<?php
$listPage = [
    'queue' => ['Awaiting Emergency Doctor', 'queue', 'Patients with a nurse handover waiting for a doctor to accept.'],
    'handovers' => ['Nurse handovers', 'handovers', 'Pending handovers and handovers already accepted by a doctor.'],
    'history' => ['Emergency history', 'history', 'Previous emergency encounters and their recorded outcomes.'],
][$doctorPage];
?>
<div class="admin-welcome emergency-welcome">
    <div><p class="eyebrow">EMERGENCY DOCTOR</p><h1 id="emergency-doctor-heading"><?= htmlspecialchars($listPage[0], ENT_QUOTES, 'UTF-8') ?></h1><p class="dashboard-subtitle"><?= htmlspecialchars($listPage[2], ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php if ($doctorPage !== 'history'): ?><span class="emergency-badge">NO PAYMENT HOLD</span><?php endif; ?>
</div>
<section class="admin-panel emergency-doctor-panel" data-doctor-list-page data-section="<?= htmlspecialchars($listPage[1], ENT_QUOTES, 'UTF-8') ?>">
    <div class="admin-panel-heading emergency-panel-heading">
        <div><h2><?= htmlspecialchars($listPage[0], ENT_QUOTES, 'UTF-8') ?></h2><p>Priority is shown from the nurse-recorded triage value and handover time; no clinical thresholds are inferred.</p></div>
        <label class="emergency-search">Search patient or visit
            <span><input type="search" maxlength="100" data-doctor-search placeholder="Patient number, name or visit ID"><button class="button button-outline button-small" type="button" data-doctor-search-button>Search</button></span>
        </label>
    </div>
    <div class="emergency-table-wrap">
        <table class="admin-table emergency-table">
            <thead><tr><th>Patient</th><th>Visit / arrival</th><th>Nurse triage</th><th>Handover / assigned doctor</th><th>Current status / outcome</th><th>Action</th></tr></thead>
            <tbody data-doctor-rows><tr><td class="table-message" colspan="6">Loading emergency cases…</td></tr></tbody>
        </table>
    </div>
</section>
<?php if ($doctorPage === 'history'): ?>
    <?php require __DIR__ . '/_doctor-case-context.php'; ?>
<?php endif; ?>
