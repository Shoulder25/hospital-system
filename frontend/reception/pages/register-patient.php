<section class="admin-panel">
    <div class="admin-panel-heading"><div><h2>New patient record</h2><p>Register the patient first. Arrival and visit creation are separate steps.</p></div></div>
    <form class="reception-form" data-register-form>
        <div class="reception-field"><label for="new-first-name">First name <span>*</span></label><input id="new-first-name" name="first_name" maxlength="100" autocomplete="given-name" required></div>
        <div class="reception-field"><label for="new-last-name">Surname <span>*</span></label><input id="new-last-name" name="last_name" maxlength="100" autocomplete="family-name" required></div>
        <div class="reception-field"><label for="new-national-id">National ID</label><input id="new-national-id" name="national_id" maxlength="50"></div>
        <div class="reception-field"><label for="new-dob">Date of birth <span>*</span></label><input id="new-dob" name="date_of_birth" type="date" required></div>
        <div class="reception-field"><label for="new-gender">Gender <span>*</span></label><select id="new-gender" name="gender" required><option value="">Select</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option><option value="unknown">Unknown</option></select></div>
        <div class="reception-field"><label for="new-phone">Phone number</label><input id="new-phone" name="phone" type="tel" maxlength="40"></div>
        <div class="reception-field reception-field-wide"><label for="new-address">Address</label><input id="new-address" name="address" maxlength="255"></div>
        <div class="reception-field reception-field-wide"><button class="button button-primary" type="submit">Register patient</button></div>
    </form>
</section>
