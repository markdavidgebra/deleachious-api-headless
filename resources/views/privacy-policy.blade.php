@extends('layouts.legal', ['title' => 'Privacy Policy'])

@section('content')
    <p class="intro">
        Daleachious Cafe ("we," "our," or "us") operates the Daleachious mobile app as a cafe loyalty membership. This Privacy Policy explains what personal information we collect, how we use it, and the choices you have.
    </p>

    <section class="section">
        <span class="section-label">Section 01</span>
        <h2>Who we are</h2>
        <p>
            Daleachious is a cafe loyalty app. Members create an account, earn points at the counter, and redeem them in our cafes.
        </p>
    </section>

    <section class="section">
        <span class="section-label">Section 02</span>
        <h2>Information we collect</h2>
        <p>We collect the information needed to run your membership:</p>
        <ul class="list">
            <li>Account details: name, email address, and optional phone number</li>
            <li>Profile photo, if you choose to add one</li>
            <li>Loyalty records: points, visits, and redemptions</li>
            <li>Sign-in and security data, including one-time passwords we email you</li>
            <li>Device information needed to keep you signed in and send notifications you allow</li>
            <li>Messages you send us through Support</li>
        </ul>
    </section>

    <section class="section">
        <span class="section-label">Section 03</span>
        <h2>How we use information</h2>
        <p>We use your information to:</p>
        <ul class="list">
            <li>Create and manage your account</li>
            <li>Confirm your email and reset your password</li>
            <li>Run the loyalty program at the cafe counter</li>
            <li>Show your points and member QR</li>
            <li>Send service messages and, if you allow them, app notifications</li>
            <li>Provide customer support and keep the app secure</li>
        </ul>
    </section>

    <section class="section">
        <span class="section-label">Section 04</span>
        <h2>Emails and one-time passwords</h2>
        <p>
            When you create an account or reset a password, we email a one-time password to the address you provide. We use that code only to confirm it is your email. The code expires after a short time.
        </p>
    </section>

    <section class="section">
        <span class="section-label">Section 05</span>
        <h2>Camera and photos</h2>
        <p>
            If you add a profile picture, the app may ask for camera or photo-library permission. Those permissions are used only for your profile photo, and only after you agree.
        </p>
    </section>

    <section class="section">
        <span class="section-label">Section 06</span>
        <h2>Sharing</h2>
        <p class="highlight">We do not sell your personal information.</p>
        <p>
            We share data only with service providers that host the app and send email, and when the law requires it. Cafe staff see what they need to earn or redeem points at the counter.
        </p>
    </section>

    <section class="section">
        <span class="section-label">Section 07</span>
        <h2>Data security</h2>
        <p>
            We use reasonable administrative and technical safeguards to protect your information. No method of transmission or storage is completely secure, so we cannot guarantee absolute security.
        </p>
    </section>

    <section class="section">
        <span class="section-label">Section 08</span>
        <h2>Retention and deletion</h2>
        <p>
            You can delete your account in the app under Profile → Delete Account. We then remove or anonymize personal data. Some loyalty or legal records may be kept only as long as the law requires.
        </p>
    </section>

    <section class="section">
        <span class="section-label">Section 09</span>
        <h2>Your choices</h2>
        <p>
            You may update your name, email, phone, and photo, sign out, turn off notifications in your device settings, or delete your account at any time.
        </p>
    </section>

    <section class="section">
        <span class="section-label">Section 10</span>
        <h2>Children</h2>
        <p>
            The app is not directed to children under 13. We do not knowingly collect personal information from children under 13. If you believe we have, contact us and we will delete it.
        </p>
    </section>

    <section class="section">
        <span class="section-label">Section 11</span>
        <h2>Changes</h2>
        <p>
            We may update this Privacy Policy from time to time. The updated date at the top of this page will change, and the new policy applies when it is posted.
        </p>
    </section>

    <section class="section">
        <span class="section-label">Section 12</span>
        <h2>Contact</h2>
        <p>For privacy questions or requests, contact us.</p>
        <div class="contact-card">
            <p class="name">Daleachious Cafe</p>
            <div class="contact-row">
                <span>Email</span>
                <a href="mailto:ddonecorp@gmail.com">ddonecorp@gmail.com</a>
            </div>
            <div class="contact-row">
                <span>Website</span>
                <a href="https://daleachious.cloud" target="_blank" rel="noopener noreferrer">daleachious.cloud</a>
            </div>
        </div>
    </section>
@endsection
