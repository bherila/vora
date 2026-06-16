@extends('layouts.app')

@section('content')
  <article class="mx-auto max-w-4xl space-y-8 text-gray-800 dark:text-[#EDEDEC]">
    <header class="space-y-3">
      <p class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-[#A1A09A]">Legal boilerplate</p>
      <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">Terms of Service</h1>
      <p class="text-sm text-gray-600 dark:text-[#A1A09A]">Last updated: {{ date('F j, Y') }}</p>
      <p class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
        These terms are boilerplate for {{ config('app.name') }} and are not legal advice. Counsel should refine and approve them before production use.
      </p>
    </header>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">1. Acceptance of these terms</h2>
      <p>
        By accessing or using {{ config('app.name') }}, you agree to these Terms of Service and our Privacy Policy. If you do not agree, do not use the service. If you use the service on behalf of an organization, you represent that you have authority to bind that organization.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">2. Eligibility and accounts</h2>
      <p>
        You must be at least 13 years old and legally able to enter into these terms. You are responsible for the accuracy of your account information, maintaining the confidentiality of your credentials, enabling appropriate security settings, and all activity under your account. We may approve, deny, suspend, deactivate, or terminate accounts as permitted by these terms.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">3. User content</h2>
      <p>
        You retain ownership of content you submit, upload, create, or share through the service. You grant us a worldwide, non-exclusive, royalty-free license to host, store, reproduce, process, display, transmit, and otherwise use your content as needed to operate, secure, improve, and provide the service and as allowed by your sharing settings.
      </p>
      <p>
        You represent that you have all rights necessary to submit your content and that your content does not violate law, these terms, or the rights of others. You are solely responsible for backing up content you want to preserve.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">4. Acceptable use</h2>
      <p>You may not use the service to:</p>
      <ul class="list-disc space-y-2 pl-6">
        <li>Violate any applicable law, regulation, contractual obligation, or third-party right.</li>
        <li>Upload or share unlawful, exploitative, abusive, harassing, hateful, threatening, defamatory, invasive, or otherwise harmful content.</li>
        <li>Impersonate others, misrepresent affiliations, or engage in deceptive, fraudulent, or manipulative activity.</li>
        <li>Distribute malware, spam, credential phishing, or harmful code.</li>
        <li>Interfere with or attempt to bypass security, rate limits, access controls, moderation systems, or technical restrictions.</li>
        <li>Scrape, harvest, or extract data except as expressly permitted by us in writing.</li>
        <li>Use the service to develop or train competing systems or models unless we expressly permit it.</li>
      </ul>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">5. Moderation and enforcement</h2>
      <p>
        We may review, remove, restrict, or disable access to content or accounts when we believe it is necessary to operate the service, protect users, enforce these terms, comply with law, or reduce risk. We are not obligated to monitor all content and are not responsible for user content posted by others.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">6. Service changes and availability</h2>
      <p>
        We may modify, suspend, or discontinue any part of the service at any time. We do not guarantee that the service will be uninterrupted, secure, error-free, or that content will always be available.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">7. Third-party services</h2>
      <p>
        The service may rely on or link to third-party services, infrastructure, integrations, or content. We are not responsible for third-party services, and your use of them may be governed by separate terms and policies.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">8. Disclaimers</h2>
      <p>
        To the fullest extent permitted by law, the service is provided “as is” and “as available” without warranties of any kind, whether express, implied, or statutory, including warranties of merchantability, fitness for a particular purpose, title, non-infringement, availability, and security.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">9. Limitation of liability</h2>
      <p>
        To the fullest extent permitted by law, we will not be liable for indirect, incidental, special, consequential, exemplary, or punitive damages, lost profits, lost data, goodwill, or service interruption. Our total liability for any claim relating to the service will not exceed the greater of the amount you paid us for the service in the 12 months before the claim or $100.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">10. Indemnity</h2>
      <p>
        You will indemnify and hold us harmless from claims, damages, liabilities, losses, and expenses, including reasonable attorneys’ fees, arising from your content, your use of the service, your violation of these terms, or your violation of law or third-party rights.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">11. Termination</h2>
      <p>
        You may stop using the service at any time. We may suspend or terminate your access if we believe you violated these terms, pose risk to the service or others, or where required by law. Sections that by their nature should survive termination will survive.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">12. Governing law and disputes</h2>
      <p>
        These terms should be customized by counsel to identify the governing law, venue, arbitration requirements, class action waiver, consumer-law exceptions, and other dispute terms appropriate for the operator of {{ config('app.name') }}.
      </p>
    </section>

    <section class="space-y-4">
      <h2 class="text-2xl font-semibold">13. Contact</h2>
      <p>
        Questions about these terms may be sent to <a class="font-medium underline" href="mailto:{{ config('app.privacy_contact_email') }}">{{ config('app.privacy_contact_email') }}</a>.
      </p>
    </section>
  </article>
@endsection
