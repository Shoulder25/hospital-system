<section class="admin-panel emergency-doctor-case" data-doctor-case hidden aria-labelledby="doctor-case-heading">
    <div class="admin-panel-heading">
        <div><p class="eyebrow">SHARED EMERGENCY ENCOUNTER</p><h2 id="doctor-case-heading" data-case-name>Emergency patient</h2><p data-case-summary></p></div>
        <span class="emergency-badge">TREAT BEFORE PAYMENT</span>
    </div>
    <dl class="emergency-facts">
        <div><dt>Patient number</dt><dd data-case-patient-number></dd></div>
        <div><dt>Visit ID</dt><dd data-case-visit-id></dd></div>
        <div><dt>Arrival</dt><dd data-case-arrival></dd></div>
        <div><dt>Current status</dt><dd data-case-status></dd></div>
        <div><dt>Emergency Doctor</dt><dd data-case-doctor></dd></div>
        <div><dt>Outcome</dt><dd data-case-outcome></dd></div>
        <div><dt>Presenting complaint</dt><dd data-case-complaint></dd></div>
        <div><dt>Reception notes</dt><dd data-case-reception-notes></dd></div>
    </dl>
    <section class="emergency-doctor-subsection" data-case-handover>
        <h3>Nurse assessment and handover</h3>
        <p data-handover-status></p>
        <div class="table-wrap">
            <table class="admin-table emergency-history-table">
                <thead><tr><th>Role</th><th>Staff member / status</th><th>Assignment / handover time</th></tr></thead>
                <tbody data-staff-attendance><tr><td colspan="3" class="table-message">No emergency staff assignment recorded.</td></tr></tbody>
            </table>
        </div>
        <dl class="emergency-facts emergency-doctor-facts" data-nurse-assessment></dl>
        <div class="table-wrap">
            <table class="admin-table emergency-history-table">
                <thead><tr><th>Recorded</th><th>Observations</th><th>Nursing notes / care</th><th>Nurse</th></tr></thead>
                <tbody data-nurse-observations><tr><td colspan="4" class="table-message">No observations recorded.</td></tr></tbody>
            </table>
        </div>
        <button class="button button-primary" type="button" data-accept-doctor-handover hidden>Accept handover</button>
    </section>
    <section class="emergency-doctor-subsection">
        <h3>Emergency Doctor progress</h3>
        <div class="table-wrap">
            <table class="admin-table emergency-history-table">
                <thead><tr><th>Date</th><th>Doctor</th><th>Assessment / diagnosis</th><th>Plan</th></tr></thead>
                <tbody data-doctor-clinical-records><tr><td colspan="4" class="table-message">No doctor assessment recorded.</td></tr></tbody>
            </table>
        </div>
        <div class="table-wrap">
            <table class="admin-table emergency-history-table">
                <thead><tr><th>Date</th><th>Type</th><th>Instruction or treatment</th><th>Doctor</th></tr></thead>
                <tbody data-doctor-notes><tr><td colspan="4" class="table-message">No treatment instructions recorded.</td></tr></tbody>
            </table>
        </div>
    </section>
    <nav class="emergency-doctor-case-nav" aria-label="Emergency case activities">
        <a class="button button-outline button-small" data-case-link="doctor-assessment.php" href="doctor-assessment.php">Assessment</a>
        <a class="button button-outline button-small" data-case-link="doctor-treatment.php" href="doctor-treatment.php">Treatment</a>
        <a class="button button-outline button-small" data-case-link="doctor-orders.php" href="doctor-orders.php">Orders</a>
        <a class="button button-outline button-small" data-case-link="doctor-referrals.php" href="doctor-referrals.php">Referrals</a>
        <a class="button button-outline button-small" data-case-link="doctor-outcomes.php" href="doctor-outcomes.php">Outcome</a>
    </nav>
</section>
