<?php

namespace App\Enums;

/**
 * Why a viewer reported a piece of content. Ordered roughly by severity; the
 * label is shown in the report dialog and the admin review queue.
 */
enum ReportReason: string
{
    case ChildSafety = 'child_safety';
    case NonconsensualIntimate = 'nonconsensual_intimate';
    case Violence = 'violence';
    case Harassment = 'harassment';
    case Hate = 'hate';
    case SelfHarm = 'self_harm';
    case Spam = 'spam';
    case Impersonation = 'impersonation';
    case IntellectualProperty = 'intellectual_property';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ChildSafety => 'Child sexual abuse or endangerment',
            self::NonconsensualIntimate => 'Non-consensual intimate imagery',
            self::Violence => 'Violence or threats',
            self::Harassment => 'Harassment or bullying',
            self::Hate => 'Hate speech',
            self::SelfHarm => 'Self-harm or suicide',
            self::Spam => 'Spam or scam',
            self::Impersonation => 'Impersonation',
            self::IntellectualProperty => 'Intellectual-property violation',
            self::Other => 'Something else',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $c): array => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
