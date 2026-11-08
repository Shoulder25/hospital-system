<div class="admin-welcome emergency-welcome">
    <div><p class="eyebrow">EMERGENCY NURSING</p><h1 id="emergency-nurse-heading">Patient assessment</h1><p class="dashboard-subtitle">Save progress at any time. A doctor handover can be requested before the assessment is complete.</p></div>
    <a class="button button-outline" href="queue.php">Back to incoming patients</a>
</div>
<section class="admin-panel emergency-nurse-panel emergency-nurse-assessment" data-nurse-assessment hidden>
    <div class="admin-panel-heading"><div><h2 data-patient-name>Emergency patient</h2><p data-patient-summary></p></div><span class="emergency-badge" data-no-fee-badge>NO TICKET FEE</span></div>
    <dl class="emergency-facts">
        <div><dt>Patient number</dt><dd data-patient-number></dd></div>
        <div><dt>Date of birth / gender</dt><dd data-patient-demographics></dd></div>
        <div><dt>Phone</dt><dd data-patient-phone></dd></div>
        <div><dt>Address</dt><dd data-patient-address></dd></div>
        <div><dt>Visit ID</dt><dd data-visit-id></dd></div>
        <div><dt>Arrival</dt><dd data-arrival></dd></div>
        <div><dt>Presenting complaint</dt><dd data-complaint></dd></div>
        <div><dt>Reception notes</dt><dd data-reception-notes></dd></div>
    </dl>
    <section class="emergency-nurse-subsection" aria-labelledby="previous-history-heading">
        <h3 id="previous-history-heading">Available previous medical history</h3>
        <p data-history-note>No previous medical records are available.</p>
        <div class="table-wrap"><table class="admin-table emergency-history-table"><thead><tr><th>Visit / date</th><th>Record</th><th>Available notes</th></tr></thead><tbody data-patient-history><tr><td colspan="3" class="table-message">Loading history…</td></tr></tbody></table></div>
    </section>
    <section class="emergency-nurse-subsection">
        <h3>Nurse acknowledgement</h3>
        <p data-acknowledgement-state>Review the patient, then acknowledge to begin the nursing record.</p>
        <button class="button button-primary" type="button" data-acknowledge>Open and acknowledge patient</button>
    </section>
    <section class="emergency-nurse-subsection">
        <h3>Emergency staff attendance</h3>
        <div class="table-wrap">
            <table class="admin-table emergency-history-table">
                <thead><tr><th>Role</th><th>Staff member / status</th><th>Assignment / handover time</th></tr></thead>
                <tbody data-staff-attendance><tr><td colspan="3" class="table-message">No emergency staff assignment recorded.</td></tr></tbody>
            </table>
        </div>
    </section>
    <form class="emergency-form emergency-nurse-form" data-nurse-assessment-form>
        <fieldset data-assessment-fields disabled>
            <legend>Assessment (drafts can be resumed)</legend>
            <label>Symptoms<textarea name="symptoms" rows="3" maxlength="10000"></textarea></label>
            <div class="emergency-form-grid">
                <label>Blood pressure (as recorded)<input name="blood_pressure" maxlength="40"></label>
                <label>Pulse<input name="pulse" inputmode="decimal" maxlength="40"></label>
                <label>Respiratory rate<input name="respiratory_rate" inputmode="decimal" maxlength="40"></label>
                <label>Temperature<input name="temperature" inputmode="decimal" maxlength="40"></label>
                <label>Oxygen saturation<input name="oxygen_saturation" inputmode="decimal" maxlength="40"></label>
                <label>Consciousness level<input name="consciousness_level" maxlength="100"></label>
                <label>Pain score (record scale in notes if relevant)<input name="pain_score" maxlength="40"></label>
            </div>
            <label>Allergies<textarea name="allergies" rows="2" maxlength="5000"></textarea></label>
            <label>Visible injuries<textarea name="visible_injuries" rows="2" maxlength="5000"></textarea></label>
            <label>Triage priority
                <input name="triage_priority" maxlength="40" aria-describedby="triage-note">
                <small id="triage-note">Approved priority options are not configured in the current system; enter only the hospital-approved value. No values are preselected or invented.</small>
            </label>
            <label>Nursing notes<textarea name="nursing_notes" rows="3" maxlength="10000"></textarea></label>
            <label>Immediate care provided<textarea name="immediate_care" rows="3" maxlength="10000"></textarea></label>
            <div class="emergency-nurse-actions">
                <button class="button button-outline" type="submit" data-save-assessment="draft">Save draft</button>
                <button class="button button-primary" type="submit" data-save-assessment="complete">Mark assessment complete</button>
            </div>
        </fieldset>
    </form>
    <section class="emergency-nurse-subsection" data-observation-section>
        <h3>Continue recording observations</h3>
        <p>Each entry is retained as a separate observation; no clinical thresholds are applied.</p>
        <form class="emergency-form emergency-nurse-form" data-observation-form>
            <fieldset data-observation-fields disabled>
                <legend>New observation</legend>
                <div class="emergency-form-grid">
                    <label>Blood pressure<input name="blood_pressure" maxlength="40"></label>
                    <label>Pulse<input name="pulse" inputmode="decimal" maxlength="40"></label>
                    <label>Respiratory rate<input name="respiratory_rate" inputmode="decimal" maxlength="40"></label>
                    <label>Temperature<input name="temperature" inputmode="decimal" maxlength="40"></label>
                    <label>Oxygen saturation<input name="oxygen_saturation" inputmode="decimal" maxlength="40"></label>
                    <label>Consciousness level<input name="consciousness_level" maxlength="100"></label>
                    <label>Pain score<input name="pain_score" maxlength="40"></label>
                </div>
                <label>Nursing notes<textarea name="nursing_notes" rows="2" maxlength="10000"></textarea></label>
                <label>Immediate care<textarea name="immediate_care" rows="2" maxlength="10000"></textarea></label>
                <button class="button button-outline" type="submit">Record observation</button>
            </fieldset>
        </form>
        <div class="table-wrap"><table class="admin-table emergency-history-table"><thead><tr><th>Recorded</th><th>Vitals / observations</th><th>Notes / immediate care</th><th>Nurse</th></tr></thead><tbody data-observation-history><tr><td colspan="4" class="table-message">No observations recorded.</td></tr></tbody></table></div>
    </section>
    <section class="emergency-nurse-subsection">
        <h3>Emergency Doctor handover</h3>
        <p data-doctor-status>Doctor handover has not been requested.</p>
        <p class="emergency-nurse-muted">The system records assigned and accepting doctors; it does not track doctor availability.</p>
        <button class="button button-primary" type="button" data-request-handover hidden>Escalate to Emergency Doctor</button>
    </section>
    <section class="emergency-nurse-subsection" aria-labelledby="doctor-progress-heading">
        <h3 id="doctor-progress-heading">Emergency Doctor progress and instructions</h3>
        <p data-doctor-progress-status>No Emergency Doctor assessment or instruction has been recorded.</p>
        <div class="table-wrap">
            <table class="admin-table emergency-history-table">
                <thead><tr><th>Date</th><th>Doctor</th><th>Assessment / decision</th><th>Plan / instructions</th></tr></thead>
                <tbody data-doctor-progress><tr><td colspan="4" class="table-message">No doctor progress recorded.</td></tr></tbody>
            </table>
        </div>
    </section>
</section>
