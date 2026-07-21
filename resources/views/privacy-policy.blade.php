<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy — Daleachious Cafe</title>
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
            --text: #2c2420;
            --text-muted: #6b5e56;
            --white: #ffffff;
            --shadow: 0 24px 64px rgba(64, 34, 24, 0.08);
            --radius: 20px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html { scroll-behavior: smooth; }

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
            font-size: clamp(2.4rem, 6vw, 3.4rem);
            font-weight: 600;
            line-height: 1.1;
            letter-spacing: -0.02em;
            margin-bottom: 0.85rem;
        }

        .hero-meta {
            font-size: 0.92rem;
            color: rgba(255, 255, 255, 0.72);
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

        .highlight {
            margin-top: 0.75rem;
            padding: 1rem 1.15rem;
            background: var(--cream);
            border-radius: 14px;
            border: 1px solid var(--cream-dark);
            color: var(--text);
            font-weight: 500;
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

            .hero {
                padding: 2.25rem 1.5rem 2rem;
                border-radius: 16px;
            }

            .content {
                padding: 1.75rem 1.35rem;
                border-radius: 16px;
            }

            .brand::before { display: none; }

            .contact-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }

            .contact-row span:first-child { min-width: auto; }
        }
    </style>
</head>
<body>
    <div class="page">
        <header class="hero">
            <div class="hero-inner">
                <p class="brand">Daleachious Cafe</p>
                <h1>Privacy Policy</h1>
                <p class="hero-meta">Effective date: July 21, 2026</p>
            </div>
        </header>

        <main class="content">
            <p class="intro">
                Daleachious Cafe ("we," "our," or "us") values your privacy and is committed to protecting your personal information. This Privacy Policy explains what information we collect, how we use it, and the choices you have regarding your information when using the Daleachious Cafe mobile application.
            </p>

            <section class="section">
                <span class="section-label">Section 01</span>
                <h2>Information We Collect</h2>
                <p>When you use our application, we may collect:</p>
                <ul class="list">
                    <li>Name</li>
                    <li>Email address</li>
                    <li>Mobile phone number</li>
                    <li>Profile photo (if you choose to upload one)</li>
                    <li>Loyalty account information</li>
                    <li>Order history</li>
                    <li>Device information necessary for app functionality</li>
                    <li>Push notification token (if notifications are enabled)</li>
                </ul>
            </section>

            <section class="section">
                <span class="section-label">Section 02</span>
                <h2>How We Use Your Information</h2>
                <p>We use the information we collect to:</p>
                <ul class="list">
                    <li>Create and manage your account</li>
                    <li>Process food and beverage orders</li>
                    <li>Manage loyalty rewards and membership benefits</li>
                    <li>Improve our products and services</li>
                    <li>Provide customer support</li>
                    <li>Send important account or order notifications</li>
                    <li>Maintain the security of our services</li>
                </ul>
            </section>

            <section class="section">
                <span class="section-label">Section 03</span>
                <h2>Camera and Photo Library</h2>
                <p>
                    If you choose to upload a profile picture, the app may request permission to access your camera and photo library.
                </p>
                <p class="highlight">
                    These permissions are used solely for updating your profile picture and are never used without your consent.
                </p>
            </section>

            <section class="section">
                <span class="section-label">Section 04</span>
                <h2>Data Sharing</h2>
                <p class="highlight">We do not sell your personal information.</p>
                <p>
                    We may share your information only when necessary with trusted service providers that help us operate the application, such as payment providers or notification services, and only for the purposes described in this Privacy Policy.
                </p>
            </section>

            <section class="section">
                <span class="section-label">Section 05</span>
                <h2>Data Security</h2>
                <p>
                    We implement reasonable administrative, technical, and physical safeguards designed to protect your personal information from unauthorized access, disclosure, alteration, or destruction.
                </p>
            </section>

            <section class="section">
                <span class="section-label">Section 06</span>
                <h2>Data Retention</h2>
                <p>
                    We retain your personal information only as long as necessary to provide our services, comply with legal obligations, resolve disputes, and enforce our agreements.
                </p>
            </section>

            <section class="section">
                <span class="section-label">Section 07</span>
                <h2>Your Rights</h2>
                <p>Depending on your location, you may have the right to:</p>
                <ul class="list">
                    <li>Access your personal information</li>
                    <li>Request correction of inaccurate information</li>
                    <li>Request deletion of your account and personal data</li>
                    <li>Withdraw consent where applicable</li>
                </ul>
                <p>To exercise these rights, please contact us using the information below.</p>
            </section>

            <section class="section">
                <span class="section-label">Section 08</span>
                <h2>Children's Privacy</h2>
                <p>
                    The Daleachious Cafe application is not specifically directed to children under the age of 13. We do not knowingly collect personal information from children without appropriate consent.
                </p>
            </section>

            <section class="section">
                <span class="section-label">Section 09</span>
                <h2>Changes to This Privacy Policy</h2>
                <p>
                    We may update this Privacy Policy from time to time. Changes will be posted on this page with an updated effective date.
                </p>
            </section>

            <section class="section">
                <span class="section-label">Section 10</span>
                <h2>Contact Us</h2>
                <p>If you have questions regarding this Privacy Policy, please contact us.</p>
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
                </div>
            </section>
        </main>

        <footer class="footer">
            <p>&copy; {{ date('Y') }} Daleachious Cafe · <a href="https://daleachious.com" target="_blank" rel="noopener noreferrer">daleachious.com</a></p>
        </footer>
    </div>
</body>
</html>
