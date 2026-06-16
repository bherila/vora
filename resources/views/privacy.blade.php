@extends('layouts.app')

@section('content')
  <article class="mx-auto max-w-4xl space-y-8 text-gray-800 dark:text-[#EDEDEC]">
    <header class="space-y-3">
      <p class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-[#A1A09A]">Legal boilerplate</p>
      <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">Privacy Policy</h1>
      <p class="text-sm text-gray-600 dark:text-[#A1A09A]">Last updated: {{ date('F j, Y') }}</p>
      <p class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
        This draft is provided as a practical starting point for {{ config('app.name') }} and is not legal advice. Counsel should review and approve it before production use.
      </p>
    </header>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">1. Who we are</h2>
      <p>
        {{ config('app.name') }} provides tools for account holders to create, upload, organize, share, and explore user-generated media, characters, stories, interests, and related profile information. This Privacy Policy explains how we collect, use, disclose, and protect personal information when you use our website, applications, and related services.
      </p>
      <p>
        For privacy questions or requests, contact us at <a class="font-medium underline" href="mailto:{{ config('app.privacy_contact_email') }}">{{ config('app.privacy_contact_email') }}</a>.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">2. Personal information we collect</h2>
      <ul class="list-disc space-y-2 pl-6">
        <li><strong>Account information:</strong> name, username or display name, email address, password credentials, authentication factors, profile details, account settings, approval status, and communications with us.</li>
        <li><strong>User content:</strong> media, stories, character details, interests, ratings, follow requests, comments or messages where available, metadata, and other content you submit or generate through the service.</li>
        <li><strong>Usage and device information:</strong> log data, IP address, browser and device identifiers, pages viewed, actions taken, approximate location derived from IP address, session information, and diagnostic data.</li>
        <li><strong>Cookies and similar technologies:</strong> cookies, local storage, and comparable technologies used for login sessions, security, preferences, analytics, and service functionality.</li>
        <li><strong>Administrative and moderation information:</strong> reports, moderation decisions, audit logs, abuse-prevention signals, and records needed to enforce our Terms of Service.</li>
      </ul>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">3. How we use personal information</h2>
      <p>We use personal information to:</p>
      <ul class="list-disc space-y-2 pl-6">
        <li>Provide, maintain, personalize, and improve the service.</li>
        <li>Create and manage accounts, authenticate users, process requests, and provide support.</li>
        <li>Host, display, share, moderate, and secure user content according to your settings and our policies.</li>
        <li>Send service messages, security notices, account updates, and other communications permitted by law.</li>
        <li>Detect, investigate, and prevent spam, fraud, abuse, security incidents, and policy violations.</li>
        <li>Comply with legal obligations, enforce agreements, and protect rights, safety, and property.</li>
      </ul>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">4. Legal bases for processing in the EEA/UK</h2>
      <p>
        Where GDPR or similar laws apply, we process personal information as necessary to perform our contract with you, based on our legitimate interests in operating and securing the service, with your consent where required, and to comply with legal obligations. You may withdraw consent where processing depends on consent, but withdrawal will not affect earlier processing.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">5. How we disclose personal information</h2>
      <p>We may disclose personal information:</p>
      <ul class="list-disc space-y-2 pl-6">
        <li>To service providers that host infrastructure, store files, deliver email, analyze service performance, process security signals, or provide support under appropriate contractual protections.</li>
        <li>To other users or the public when you choose to publish, share, collaborate on, or make content discoverable through the service.</li>
        <li>To administrators and moderators for safety, support, compliance, and enforcement purposes.</li>
        <li>To comply with law, legal process, or governmental requests, or to protect rights, safety, and security.</li>
        <li>In connection with a merger, financing, acquisition, bankruptcy, reorganization, or sale of assets, subject to appropriate safeguards.</li>
      </ul>
      <p>We do not sell personal information for money. If our use of advertising, analytics, or sharing technologies is considered a “sale,” “sharing,” or targeted advertising under applicable privacy laws, you may contact us to exercise opt-out rights.</p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">6. Retention</h2>
      <p>
        We retain personal information for as long as reasonably necessary to provide the service, maintain accounts, resolve disputes, enforce agreements, comply with legal obligations, and preserve security and audit records. Retention periods vary depending on the type of information, user settings, deletion requests, backup schedules, and legal requirements.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">7. Your privacy rights</h2>
      <p>
        Depending on where you live, you may have rights to request access, correction, deletion, portability, restriction, objection, withdrawal of consent, or an appeal of our decision. California residents may also request notice of categories collected, sources, purposes, disclosures, and opt out of sale or sharing where applicable. We will not discriminate against you for exercising legally protected privacy rights.
      </p>
      <p>
        To make a request, email <a class="font-medium underline" href="mailto:{{ config('app.privacy_contact_email') }}">{{ config('app.privacy_contact_email') }}</a>. We may need to verify your identity and authority before fulfilling a request. Authorized agents may submit requests where permitted by law.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">8. International transfers</h2>
      <p>
        We may process and store personal information in the United States and other countries. Where required, we use appropriate safeguards for international transfers, such as contractual commitments or other lawful transfer mechanisms.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">9. Security</h2>
      <p>
        We use reasonable administrative, technical, and organizational safeguards designed to protect personal information. No system is completely secure, and you are responsible for maintaining the confidentiality of your login credentials and using strong account security settings.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">10. Children</h2>
      <p>
        The service is not intended for children under 13, and we do not knowingly collect personal information from children under 13. If you believe a child provided personal information, contact us so we can take appropriate action.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">11. Changes to this policy</h2>
      <p>
        We may update this Privacy Policy from time to time. If changes are material, we will provide notice as required by law. The “Last updated” date shows when this draft was most recently updated.
      </p>
    </section>
  </article>
@endsection
