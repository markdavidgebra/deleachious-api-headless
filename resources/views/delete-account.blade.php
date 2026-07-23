<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Delete Account — Daleachious Cafe</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap" rel="stylesheet">
    <style>
        :root {
            --espresso: #402218;
            --espresso-light: #5c3428;
            --cream: #faf6f0;
            --cream-dark: #f0e8dc;
            --gold: #c4a35a;
            --gold-soft: rgba(196, 163, 90, 0.18);
            --danger-soft: #fdf2f2;
            --danger-border: #f0d4d4;
            --danger-text: #7f1d1d;
            --text: #2c2420;
            --text-muted: #6b5e56;
            --white: #ffffff;
            --shadow: 0 24px 64px rgba(64, 34, 24, 0.08);
            --radius: 20px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "DM Sans", sans-serif;
            font-size: 16px;
            line-height: 1.75;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(196, 163, 90, 0.12), transparent 28rem),
                radial-gradient(circle at bottom right, rgba(64, 34, 24, 0.06), transparent 32rem),
                var(--cream);
            min-height: 100vh;
        }

        .page {
            max-width: 760px;
            margin: 0 auto;
            padding: 2.5rem 1.25rem 4rem;
        }

        .hero {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, var(--espresso) 0%, var(--espresso-light) 100%);
            color: var(--white);
            border-radius: var(--radius);
            padding: 3rem 2.5rem 2.75rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 85% 15%, rgba(196, 163, 90, 0.22), transparent 40%),
                radial-gradient(circle at 10% 90%, rgba(255, 255, 255, 0.06), transparent 35%);
            pointer-events: none;
        }

        .hero-inner { position: relative; z-index: 1; }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 1.25rem;
        }

        .brand::before,
        .brand::after {
            content: "";
            width: 1.5rem;
            height: 1px;
            background: currentColor;
            opacity: 0.7;
        }

        .hero h1 {
            font-family: "Cormorant Garamond", serif;
            font-size: clamp(2.2rem, 6vw, 3.2rem);
            font-weight: 600;
            line-height: 1.1;
            letter-spacing: -0.02em;
            margin-bottom: 0.85rem;
        }

        .hero-meta {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.78);
            max-width: 36rem;
        }

        .content {
            background: var(--white);
            border: 1px solid rgba(64, 34, 24, 0.06);
            border-radius: var(--radius);
            padding: 2.5rem 2.25rem;
            box-shadow: var(--shadow);
        }

        .intro {
            font-size: 1.02rem;
            color: var(--text-muted);
            padding-bottom: 2rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--cream-dark);
        }

        .section {
            padding-bottom: 2rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--cream-dark);
        }

        .section:last-of-type {
            padding-bottom: 0;
            margin-bottom: 0;
            border-bottom: none;
        }

        .section-label {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--gold);
            background: var(--gold-soft);
            padding: 0.35rem 0.7rem;
            border-radius: 999px;
            margin-bottom: 0.85rem;
        }

        .section h2 {
            font-family: "Cormorant Garamond", serif;
            font-size: 1.65rem;
            font-weight: 600;
            color: var(--espresso);
            line-height: 1.25;
            margin-bottom: 0.85rem;
        }

        .section p {
            margin-bottom: 0.9rem;
            color: var(--text-muted);
        }

        .section p:last-child { margin-bottom: 0; }

        .steps {
            list-style: none;
            margin-top: 0.75rem;
            counter-reset: step;
        }

        .steps li {
            position: relative;
            padding: 1rem 1rem 1rem 3.25rem;
            margin-bottom: 0.75rem;
            background: var(--cream);
            border: 1px solid var(--cream-dark);
            border-radius: 14px;
            color: var(--text-muted);
            counter-increment: step;
        }

        .steps li::before {
            content: counter(step);
            position: absolute;
            left: 1rem;
            top: 1rem;
            width: 1.65rem;
            height: 1.65rem;
            border-radius: 50%;
            background: var(--espresso);
            color: var(--white);
            font-size: 0.78rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .list {
            list-style: none;
            margin: 0.75rem 0 0;
        }

        .list li {
            position: relative;
            padding-left: 1.35rem;
            margin-bottom: 0.55rem;
            color: var(--text-muted);
        }

        .list li::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0.72em;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--gold);
        }

        .list-danger li::before {
            background: #b45309;
        }

        .warning-box {
            margin-top: 0.75rem;
            padding: 1.1rem 1.2rem;
            background: var(--danger-soft);
            border: 1px solid var(--danger-border);
            border-radius: 14px;
            color: var(--danger-text);
            font-weight: 500;
        }

        .info-box {
            margin-top: 0.75rem;
            padding: 1.1rem 1.2rem;
            background: var(--cream);
            border: 1px solid var(--cream-dark);
            border-radius: 14px;
            color: var(--text);
        }

        .contact-card {
            margin-top: 1rem;
            padding: 1.5rem 1.6rem;
            background: linear-gradient(180deg, var(--cream) 0%, #fff 100%);
            border: 1px solid var(--cream-dark);
            border-radius: 16px;
        }

        .contact-card .name {
            font-family: "Cormorant Garamond", serif;
            font-size: 1.35rem;
            font-weight: 600;
            color: var(--espresso);
            margin-bottom: 0.85rem;
        }

        .contact-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.55rem;
            color: var(--text-muted);
            font-size: 0.96rem;
        }

        .contact-row:last-child { margin-bottom: 0; }

        .contact-row span:first-child {
            min-width: 4.5rem;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--gold);
        }

        a {
            color: var(--espresso);
            text-decoration: none;
            border-bottom: 1px solid rgba(64, 34, 24, 0.22);
            transition: color 0.2s ease, border-color 0.2s ease;
        }

        a:hover {
            color: var(--gold);
            border-color: var(--gold);
        }

        .footer {
            margin-top: 2rem;
            text-align: center;
            font-size: 0.86rem;
            color: var(--text-muted);
        }

        .footer a {
            border-bottom: none;
            font-weight: 500;
        }

        @media (max-width: 640px) {
            .page { padding: 1.25rem 1rem 3rem; }
            .hero { padding: 2.25rem 1.5rem 2rem; border-radius: 16px; }
            .content { padding: 1.75rem 1.35rem; border-radius: 16px; }
            .brand::before { display: none; }
            .contact-row { flex-direction: column; align-items: flex-start; gap: 0.25rem; }
            .contact-row span:first-child { min-width: auto; }
        }
    </style>
</head>
<body>
    <div class="page">
        <header class="hero">
            <div class="hero-inner">
                <p class="brand">Daleachious Cafe</p>
                <h1>Delete Your Account</h1>
                <p class="hero-meta">
                    Request permanent deletion of your Daleachious Cafe mobile app account and associated personal data.
                </p>
            </div>
        </header>

        <main class="content">
            <p class="intro">
                You can delete your account at any time. Once deleted, your account cannot be recovered and personal data linked to your profile will be permanently removed or anonymized, except where retention is required by law.
            </p>

            <section class="section">
                <span class="section-label">Option 1</span>
                <h2>Delete From the Mobile App</h2>
                <p>The fastest way to delete your account is directly inside the Daleachious Cafe mobile application:</p>
                <ol class="steps">
                    <li>Open the Daleachious Cafe app and sign in to your account.</li>
                    <li>Go to <strong>Profile</strong> or <strong>Account Settings</strong>.</li>
                    <li>Tap <strong>Delete Account</strong>.</li>
                    <li>Enter your password to confirm and complete the deletion.</li>
                </ol>
                <p class="info-box">
                    After confirmation, you will be signed out immediately and your personal data will be processed for deletion.
                </p>
            </section>

            <section class="section">
                <span class="section-label">Option 2</span>
                <h2>Request Deletion by Email</h2>
                <p>
                    If you are unable to access the app, you may request account deletion by contacting us. Please include the email address associated with your account so we can verify your identity.
                </p>
                <div class="contact-card">
                    <p class="name">Daleachious Cafe Support</p>
                    <div class="contact-row">
                        <span>Email</span>
                        <a href="mailto:ddonecorp@gmail.com?subject=Account%20Deletion%20Request">ddonecorp@gmail.com</a>
                    </div>
                </div>
            </section>

            <section class="section">
                <span class="section-label">What Is Deleted</span>
                <h2>Data Removed Permanently</h2>
                <p>When your account is deleted, the following will be permanently removed or anonymized:</p>
                <ul class="list list-danger">
                    <li>Profile information (name, email, phone number, profile photo)</li>
                    <li>Personal information stored in your account</li>
                    <li>Loyalty points and rewards balance</li>
                    <li>Wallet balance and active wallet access</li>
                    <li>Saved addresses</li>
                    <li>Push notification tokens and in-app notification history</li>
                    <li>Login sessions and API access tokens</li>
                </ul>
                <p class="warning-box">
                    Account deletion is permanent. Any unused wallet balance or loyalty points will be forfeited and cannot be restored.
                </p>
            </section>

            <section class="section">
                <span class="section-label">What Is Retained</span>
                <h2>Records Kept for Legal &amp; Accounting Purposes</h2>
                <p>
                    To comply with tax, financial, and regulatory requirements, we may retain certain non-personal records after account deletion, including:
                </p>
                <ul class="list">
                    <li>Order history</li>
                    <li>Payment and wallet transaction records</li>
                    <li>Refund and financial audit logs</li>
                </ul>
                <p>
                    These records are retained for up to <strong>7 years</strong>, are disassociated from your personal identity, and are used only for legal, tax, and accounting purposes.
                </p>
            </section>

            <section class="section">
                <span class="section-label">Need Help?</span>
                <h2>Contact Us</h2>
                <p>If you have questions about account deletion or your personal data, please contact us.</p>
                <div class="contact-card">
                    <p class="name">Daleachious Cafe</p>
                    <div class="contact-row">
                        <span>Email</span>
                        <a href="mailto:ddonecorp@gmail.com">ddonecorp@gmail.com</a>
                    </div>
                    <div class="contact-row">
                        <span>Website</span>
                        <a href="https://daleachious.com" target="_blank" rel="noopener noreferrer">daleachious.com</a>
                    </div>
                    <div class="contact-row">
                        <span>Policy</span>
                        <a href="{{ url('/privacy-policy') }}">Privacy Policy</a>
                    </div>
                </div>
            </section>
        </main>

        <footer class="footer">
            <p>&copy; {{ date('Y') }} Daleachious Cafe · <a href="{{ url('/privacy-policy') }}">Privacy Policy</a></p>
        </footer>
    </div>
</body>
</html>
