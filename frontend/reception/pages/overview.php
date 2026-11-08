<section class="admin-stat-grid reception-stat-grid reception-overview-stats" aria-label="Reception statistics">
    <article class="stat-card"><div class="stat-icon stat-icon-teal" aria-hidden="true">♙</div><p>Total registered patients</p><strong data-stat="total_patients">—</strong><span>Patient records in the database</span></article>
    <article class="stat-card"><div class="stat-icon stat-icon-blue" aria-hidden="true">◷</div><p>Today's appointments</p><strong data-stat="todays_appointments">—</strong><span>Scheduled for today</span></article>
    <article class="stat-card"><div class="stat-icon stat-icon-teal" aria-hidden="true">＋</div><p>Today's arrivals</p><strong data-stat="todays_arrivals">—</strong><span>Visits checked in today</span></article>
    <article class="stat-card"><div class="stat-icon stat-icon-amber" aria-hidden="true">!</div><p>Waiting for processing</p><strong data-stat="waiting_for_processing">—</strong><span>Including ticket payment</span></article>
    <article class="stat-card"><div class="stat-icon stat-icon-violet" aria-hidden="true">$</div><p>Awaiting ticket payment</p><strong data-stat="awaiting_ticket_payment">—</strong><span>Must pay before nursing</span></article>
    <article class="stat-card"><div class="stat-icon stat-icon-blue" aria-hidden="true">➜</div><p>Transferred to nursing</p><strong data-stat="transferred_to_nursing">—</strong><span>Ticket paid and awaiting assessment</span></article>
</section>

<section class="reception-quick-actions" aria-label="Reception quick actions">
    <a class="reception-quick-card" href="register-patient.php"><span>＋</span><strong>Register patient</strong><small>Create a patient record</small></a>
    <a class="reception-quick-card" href="find-patient.php"><span>⌕</span><strong>Find patient</strong><small>Search existing records</small></a>
    <a class="reception-quick-card" href="appointments.php"><span>◷</span><strong>Schedule appointment</strong><small>Manage upcoming visits</small></a>
    <a class="reception-quick-card" href="arrivals.php"><span>↗</span><strong>Check in patient</strong><small>Record an arrival</small></a>
    <a class="reception-quick-card" href="queue.php"><span>☷</span><strong>View patient queue</strong><small>Track workflow statuses</small></a>
</section>

<div class="reception-dashboard-grid">
    <section class="admin-panel">
        <div class="admin-panel-heading"><div><h2>Recent registrations</h2><p>Latest patient records created by reception.</p></div><a class="text-link" href="find-patient.php">Find patient</a></div>
        <div class="table-wrap"><table class="admin-table"><thead><tr><th>Patient</th><th>Patient number</th><th>Registered</th></tr></thead><tbody data-overview-recent><tr><td class="table-message" colspan="3">Loading patient registrations…</td></tr></tbody></table></div>
    </section>
    <section class="admin-panel">
        <div class="admin-panel-heading"><div><h2>Upcoming appointments</h2><p>Next scheduled patient appointments.</p></div><a class="text-link" href="appointments.php">Manage appointments</a></div>
        <div class="table-wrap"><table class="admin-table"><thead><tr><th>Patient</th><th>Appointment</th><th>Status</th></tr></thead><tbody data-overview-appointments><tr><td class="table-message" colspan="3">Loading appointments…</td></tr></tbody></table></div>
    </section>
    <section class="admin-panel">
        <div class="admin-panel-heading"><div><h2>Today's arrivals</h2><p>Patients checked in today.</p></div><a class="text-link" href="arrivals.php">Arrivals</a></div>
        <div class="table-wrap"><table class="admin-table"><thead><tr><th>Patient</th><th>Visit</th><th>Status</th></tr></thead><tbody data-overview-arrivals><tr><td class="table-message" colspan="3">Loading arrivals…</td></tr></tbody></table></div>
    </section>
    <section class="admin-panel">
        <div class="admin-panel-heading"><div><h2>Recently processed</h2><p>Visits already routed to a care department.</p></div><a class="text-link" href="queue.php">Full queue</a></div>
        <div class="table-wrap"><table class="admin-table"><thead><tr><th>Patient</th><th>Visit</th><th>Status</th></tr></thead><tbody data-overview-processed><tr><td class="table-message" colspan="3">Loading visits…</td></tr></tbody></table></div>
    </section>
    <section class="admin-panel reception-attention-panel">
        <div class="admin-panel-heading"><div><h2>Requiring attention</h2><p>Emergency and ticket-payment visits still at reception.</p></div><a class="text-link" href="queue.php">Review queue</a></div>
        <div class="table-wrap"><table class="admin-table"><thead><tr><th>Patient</th><th>Priority</th><th>Status</th></tr></thead><tbody data-overview-attention><tr><td class="table-message" colspan="3">Loading attention list…</td></tr></tbody></table></div>
    </section>
</div>
