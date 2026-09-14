<?php
// privacy.php — public privacy policy for the Vikundi Android app (Play Store listing).
//
// Deliberately standalone: no roots.php/session/auth of any kind. This is
// reached via roots.php's own route table ('privacy' => ROOT_DIR . '/privacy.php'),
// so by the time PHP runs this file the session is already whatever the visitor's
// cookie made it — but nothing here reads $_SESSION or redirects on it. A
// logged-out visitor, Google Play's own crawler, and a logged-in leader must
// all see the exact same page, unconditionally.
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Vikundi — Privacy Policy</title>
<style>
  :root { --ink:#1a1c1e; --muted:#5b5f66; --blue:#1B5FC7; --line:#e3e6ea; --bg:#ffffff; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--ink);
    font:16px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
  .wrap { max-width: 820px; margin: 0 auto; padding: 40px 22px 80px; }
  h1 { font-size: 1.9rem; margin: 0 0 4px; }
  h2 { font-size: 1.2rem; margin: 34px 0 8px; }
  h3 { font-size: 1.02rem; margin: 20px 0 4px; }
  p, li { color: var(--ink); }
  .muted { color: var(--muted); }
  .meta { color: var(--muted); font-size: 0.92rem; margin: 0 0 24px; }
  a { color: var(--blue); }
  ul { padding-left: 22px; }
  li { margin: 4px 0; }
  hr { border:0; border-top:1px solid var(--line); margin: 28px 0; }
  .note { background:#f6f8fb; border:1px solid var(--line); border-radius:10px; padding:12px 16px; }
  code { background:#f2f4f7; padding:1px 5px; border-radius:4px; font-size: 0.9em; }
  footer { color: var(--muted); font-size: 0.88rem; margin-top: 40px; }
</style>
</head>
<body>
<div class="wrap">

  <h1>Vikundi — Privacy Policy</h1>
  <p class="meta">Last updated: 12 September 2026</p>

  <p>
    Vikundi (the "App") is a mobile application for VICOBA savings groups. It is
    operated by <strong>BJP Technologies (T) Ltd</strong> ("BJP", "we", "us", "our"),
    a company registered in Tanzania. This policy explains what personal information the
    App handles, how it is used and protected, and the choices and rights you have.
  </p>
  <p>
    By using Vikundi you agree to this policy. If you do not agree, please do not use the App.
  </p>

  <h2>1. Who controls your data</h2>
  <p>
    Vikundi is used by many independent savings groups. Each <strong>group is the controller
    of its own members' data</strong> and decides who in the group may see it (for example,
    leaders can see members' contribution positions — this reflects the group's own
    transparency rules). <strong>BJP provides and operates the software</strong> on the
    group's behalf (a data processor/operator) and also acts as a controller for the limited
    technical data needed to run and secure the service.
  </p>

  <h2>2. Information we collect</h2>

  <h3>Information you or your group provide</h3>
  <ul>
    <li><strong>Identity &amp; account:</strong> name, username, email address, phone number,
      national ID number, and a profile photo where provided.</li>
    <li><strong>Membership &amp; financial records:</strong> contributions and savings, entrance
      fees, fines, member payouts, condolence/welfare assistance, meeting attendance, and the
      statements and reports built from them.</li>
    <li><strong>Governance:</strong> documents you write or sign, and participation in votes and
      elections. Voting is by <strong>secret ballot</strong> — the App records <em>that</em> you
      voted, never <em>how</em> you voted.</li>
    <li><strong>Attachments:</strong> images you upload, such as payment slips and document files.</li>
  </ul>

  <h3>Information collected automatically</h3>
  <ul>
    <li><strong>Authentication data:</strong> secure sign-in tokens, stored in your device's
      protected storage.</li>
    <li><strong>Technical &amp; diagnostic data:</strong> app version, device type/operating
      system, and error or crash information used to keep the App working and to fix problems.</li>
  </ul>

  <h2>3. How we use your information</h2>
  <ul>
    <li>To provide the App and run your group's savings, contributions, fines, meetings,
      reports, documents and elections.</li>
    <li>To authenticate you and keep accounts and data secure.</li>
    <li>To provide support and respond to your requests.</li>
    <li>To diagnose problems, fix bugs and improve reliability and performance.</li>
    <li>To meet legal, regulatory and record-keeping obligations.</li>
  </ul>
  <p>We do <strong>not</strong> sell your personal information, and the App shows <strong>no
    advertising</strong>.</p>

  <h2>4. Legal basis</h2>
  <p>
    We process personal data in line with Tanzania's <strong>Personal Data Protection Act,
    2022</strong> and its regulations. Depending on the case, our basis is the performance of
    the service you and your group have signed up for, your consent, our legitimate interest in
    running and securing the App, and compliance with legal obligations.
  </p>

  <h2>5. When information is shared</h2>
  <ul>
    <li><strong>Within your group:</strong> your group's leaders and, where the group allows it,
      other members can see membership and contribution information, per the group's own rules.</li>
    <li><strong>Service providers (processors):</strong> trusted providers who host the service
      and, where a group enables them, deliver SMS or email on the group's behalf, and provide
      crash/diagnostic reporting to help us fix problems. These providers may only use the data
      to perform their service for us.</li>
    <li><strong>Legal &amp; safety:</strong> where required by law, regulation, legal process, or
      to protect rights, safety and the integrity of the service.</li>
  </ul>

  <h2>6. How we protect your data</h2>
  <ul>
    <li>All communication between the App and our servers is encrypted in transit (HTTPS only).</li>
    <li>Sign-in tokens are held in your device's secure storage.</li>
    <li>Access is controlled by roles and permissions, so people only see what their role allows.</li>
    <li>Data is stored on secured servers with access limited to authorised personnel.</li>
  </ul>
  <p class="muted">No system can be guaranteed perfectly secure, but we take reasonable measures
    to protect your information.</p>

  <h2>7. How long we keep it</h2>
  <p>
    We keep personal and financial records for as long as your account is active and for as long
    as your group needs them for its own records and to meet legal and accounting obligations.
    When data is no longer needed, it is deleted or anonymised.
  </p>

  <h2>8. Your rights and choices</h2>
  <p>Subject to Tanzanian law, you may ask to:</p>
  <ul>
    <li>access the personal data we hold about you;</li>
    <li>correct information that is wrong or out of date;</li>
    <li>request deletion of your account and data (see below);</li>
    <li>object to or restrict certain processing, and withdraw consent where processing relies on it.</li>
  </ul>

  <h3>Deleting your account and data</h3>
  <p class="note">
    To delete your account and personal data, email
    <a href="mailto:privacy@bjptechnologies.co.tz">privacy@bjptechnologies.co.tz</a>, or ask
    your group's administrator. We action verified requests within <strong>30 days</strong>,
    except for records your group is legally required to keep (for example, financial and
    membership records the group must retain for its own accounting and audit).
  </p>

  <h2>9. Children</h2>
  <p>
    Vikundi is intended for adult members of savings groups and is not directed at children under
    18. We do not knowingly collect data from children.
  </p>

  <h2>10. Changes to this policy</h2>
  <p>
    We may update this policy from time to time. We will change the "Last updated" date above and,
    where appropriate, notify you in the App. Continued use after an update means you accept the
    revised policy.
  </p>

  <h2>11. Contact us</h2>
  <p>
    BJP Technologies (T) Ltd<br />
    Email: <a href="mailto:privacy@bjptechnologies.co.tz">privacy@bjptechnologies.co.tz</a><br />
    Phone: <a href="tel:+255764764011">0764 764 011</a><br />
    Address: <span class="muted">Msakuzi, Ubungo, Dar es Salaam, 16113, Tanzania</span><br />
    Website: <a href="https://bjptechnologies.co.tz">bjptechnologies.co.tz</a>
  </p>

  <hr />
  <footer>
    © 2026 BJP Technologies (T) Ltd. This document is the privacy policy for the Vikundi
    mobile application.
  </footer>

</div>
</body>
</html>
