<?php

namespace App\Support;

class DefaultStaticPages
{
    /**
     * Revision date for the built-in legal boilerplate. Bump this only when the
     * default policy text below actually changes, so "Last updated" reflects the
     * document revision rather than the current request date.
     */
    public const REVISION_DATE = 'June 16, 2026';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $app = config('app.name');

        // Note: app_name and privacy_contact_email are intentionally NOT stored as
        // page variables. StaticPageRenderer::variables() supplies them live from
        // config, and stored variables override that — so freezing them here would
        // pin a stale value into seeded rows and defeat the PRIVACY_CONTACT_EMAIL
        // knob. Only page-specific values (the fixed revision date) are stored.
        return [
            'home' => [
                'slug' => 'home',
                'title' => $app,
                'body_markdown' => "# Welcome to {{app_name}}\n\nCreate, organize, and share media, characters, stories, and interests from one private-by-default workspace.\n\nUse the admin static page editor to replace this boilerplate home page with launch-ready copy.",
                'variables' => [],
                'is_published' => true,
                'show_in_footer' => false,
                'footer_label' => null,
                'sort_order' => 0,
            ],
            'privacy' => [
                'slug' => 'privacy',
                'title' => 'Privacy Policy',
                'body_markdown' => self::privacyMarkdown(),
                'variables' => ['last_updated' => self::REVISION_DATE],
                'is_published' => true,
                'show_in_footer' => true,
                'footer_label' => 'Privacy',
                'sort_order' => 10,
            ],
            'terms' => [
                'slug' => 'terms',
                'title' => 'Terms of Service',
                'body_markdown' => self::termsMarkdown(),
                'variables' => ['last_updated' => self::REVISION_DATE],
                'is_published' => true,
                'show_in_footer' => true,
                'footer_label' => 'Terms',
                'sort_order' => 20,
            ],
        ];
    }

    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    public static function privacyMarkdown(): string
    {
        return <<<'MARKDOWN'
> This draft is boilerplate for {{app_name}} and is not legal advice. Counsel should review and approve it before production use.

Last updated: {{last_updated}}

## 1. Who we are

{{app_name}} provides tools for account holders to create, upload, organize, share, and explore user-generated media, characters, stories, interests, and related profile information. For privacy questions or requests, contact [{{privacy_contact_email}}](mailto:{{privacy_contact_email}}).

## 2. Personal information we collect

- **Account information:** name, display name, email address, authentication factors, profile details, account settings, approval status, and communications with us.
- **User content:** media, stories, character details, interests, ratings, follow requests, metadata, and other content you submit or generate.
- **Usage and device information:** log data, IP address, browser and device identifiers, pages viewed, actions taken, approximate location from IP address, session information, and diagnostic data.
- **Cookies and similar technologies:** cookies, local storage, and comparable technologies used for login sessions, security, preferences, analytics, and functionality.
- **Administrative and moderation information:** reports, moderation decisions, audit logs, abuse-prevention signals, and records needed to enforce our Terms of Service.

## 3. How we use personal information

We use personal information to provide, maintain, personalize, and improve the service; manage accounts; host and moderate user content; send service and security messages; prevent fraud, abuse, and security incidents; comply with law; enforce agreements; and protect rights, safety, and property.

## 4. Legal bases for processing in the EEA/UK

Where GDPR or similar laws apply, we process personal information as necessary to perform our contract with you, based on legitimate interests in operating and securing the service, with consent where required, and to comply with legal obligations.

## 5. How we disclose personal information

We may disclose personal information to service providers; to other users or the public based on your sharing settings; to administrators and moderators; to comply with law or legal process; and in connection with a merger, financing, acquisition, bankruptcy, reorganization, or sale of assets. We do not sell personal information for money. California residents may contact us to exercise CCPA/CPRA rights and opt-out rights where applicable.

## 6. Retention, security, and transfers

We retain personal information for as long as reasonably necessary to provide the service, resolve disputes, enforce agreements, comply with law, and preserve security and audit records. We use reasonable safeguards, but no system is completely secure. We may process and store personal information in the United States and other countries using appropriate safeguards where required.

## 7. Your privacy rights

Depending on where you live, you may have rights to request access, correction, deletion, portability, restriction, objection, withdrawal of consent, or an appeal. To make a request, email [{{privacy_contact_email}}](mailto:{{privacy_contact_email}}). We may verify your identity and authority before fulfilling a request.

## 8. Children

The service is intended only for users who meet the age eligibility requirements in our Terms of Service. We do not knowingly collect personal information from people who are not eligible to use the service.

## 9. Changes

We may update this Privacy Policy from time to time. Material changes will be posted on this page or communicated as required by law.
MARKDOWN;
    }

    public static function termsMarkdown(): string
    {
        return <<<'MARKDOWN'
> These terms are boilerplate for {{app_name}} and are not legal advice. Counsel should refine and approve them before production use.

Last updated: {{last_updated}}

## 1. Acceptance of these terms

By accessing or using {{app_name}}, you agree to these Terms of Service and our Privacy Policy. If you do not agree, do not use the service.

## 2. Eligibility and accounts

You must be at least 18 years old or the age of majority in your local jurisdiction, whichever is higher, and legally able to enter into these terms. You are responsible for accurate account information, safeguarding credentials, enabling appropriate security settings, and all activity under your account. We may approve, deny, suspend, deactivate, or terminate accounts as permitted by these terms.

## 3. User content

You retain ownership of content you submit, upload, create, or share through the service. You grant us a worldwide, non-exclusive, royalty-free license to host, store, reproduce, process, display, transmit, and otherwise use your content as needed to operate, secure, improve, and provide the service and as allowed by your sharing settings. You represent that you have all rights necessary to submit your content and that your content does not violate law, these terms, or the rights of others.

## 4. Acceptable use

You may not use the service to violate law or third-party rights; upload unlawful, exploitative, abusive, harassing, hateful, threatening, defamatory, invasive, or harmful content; impersonate others; distribute malware, spam, phishing, or harmful code; bypass security or moderation systems; scrape data without permission; or use the service to develop competing systems unless expressly permitted.

## 5. Moderation and enforcement

We may review, remove, restrict, or disable access to content or accounts when necessary to operate the service, protect users, enforce these terms, comply with law, or reduce risk. We are not obligated to monitor all content and are not responsible for user content posted by others.

## 6. Service changes and availability

We may modify, suspend, or discontinue any part of the service at any time. We do not guarantee that the service will be uninterrupted, secure, error-free, or that content will always be available.

## 7. Third-party services

The service may rely on or link to third-party services, infrastructure, integrations, or content. We are not responsible for third-party services, and your use of them may be governed by separate terms and policies.

## 8. Disclaimers, liability, and indemnity

To the fullest extent permitted by law, the service is provided “as is” and “as available” without warranties of any kind. To the fullest extent permitted by law, we will not be liable for indirect, incidental, special, consequential, exemplary, or punitive damages. You agree to indemnify us from claims arising from your content, your use of the service, or your violation of these terms.

## 9. Termination

You may stop using the service at any time. We may suspend or terminate access if we believe you violated these terms, create risk, or as otherwise permitted by law. Sections intended to survive termination will continue to apply.

## 10. Changes and contact

We may update these terms from time to time. Material changes will be posted on this page or communicated as required by law. Questions may be sent to [{{privacy_contact_email}}](mailto:{{privacy_contact_email}}).
MARKDOWN;
    }
}
