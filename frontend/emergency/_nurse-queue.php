<?php
$section = [
    'queue' => ['Incoming emergency patients', 'incoming', 'New emergency visits received from Reception.'],
    'active' => ['Active emergency patients', 'active', 'Patients acknowledged by you and still receiving emergency care.'],
    'handover' => ['Doctor handover', 'handover', 'Pending and accepted Emergency Doctor handovers.'],
    'history' => ['Emergency nursing history', 'history', 'Completed encounters and completed nursing assessments.'],
][$nursePage];
?>
<div class="admin-welcome emergency-welcome">
    <div><p class="eyebrow">EMERGENCY NURSING</p><h1 id="emergency-nurse-heading"><?= htmlspecialchars($section[0], ENT_QUOTES, 'UTF-8') ?></h1><p class="dashboard-subtitle"><?= htmlspecialchars($section[2], ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php if ($nursePage === 'queue'): ?><span class="emergency-badge">NO TICKET FEE</span><?php endif; ?>
</div>
<section class="admin-panel emergency-nurse-panel" data-nurse-list-page data-section="<?= htmlspecialchars($section[1], ENT_QUOTES, 'UTF-8') ?>">
    <div class="admin-panel-heading emergency-panel-heading">
        <div><h2><?= htmlspecialchars($section[0], ENT_QUOTES, 'UTF-8') ?></h2><p data-nurse-list-description></p></div>
        <label class="emergency-search">Search patient or visit
            <span><input type="search" maxlength="100" data-nurse-search placeholder="Patient number, name or visit ID"><button class="button button-outline button-small" type="button" data-nurse-search-button>Search</button></span>
        </label>
    </div>
    <div class="emergency-table-wrap">
        <table class="admin-table emergency-table">
            <thead><tr><th>Patient</th><th>Visit</th><th>Arrival</th><th>Assessment / handover</th><th>Visit status</th><th>Action</th></tr></thead>
            <tbody data-nurse-rows><tr><td class="table-message" colspan="6">Loading emergency patients…</td></tr></tbody>
        </table>
    </div>
</section>
