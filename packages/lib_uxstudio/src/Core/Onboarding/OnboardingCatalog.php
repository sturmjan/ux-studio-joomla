<?php

namespace Ux1\UxStudio\Core\Onboarding;

\defined('_JEXEC') or die;

/**
 * What the wizard knows about site types. It deliberately holds NO list of modules to offer: it only maps module ids to
 * the site types they suit. Ids of modules that do not exist on this installation are simply never shown, and modules
 * missing from the map are offered as "other modules" without a recommendation.
 */
final class OnboardingCatalog
{
    /** Id of the Central App connector module (own wizard step, not a regular choice). */
    public const CA_MODULE = 'ca-connector';

    /** @return array<int,array{id:string,icon:string}> label/description come from language keys UXS_CORE_WIZ_TYPE_<ID>[_DESC] */
    public static function siteTypes(): array
    {
        return [
            ['id' => 'company', 'icon' => 'building'],
            ['id' => 'eshop', 'icon' => 'shopping-cart'],
            ['id' => 'accommodation', 'icon' => 'bed'],
            ['id' => 'blog', 'icon' => 'newspaper'],
            ['id' => 'other', 'icon' => 'sparkles'],
        ];
    }

    /** @return string[] */
    public static function typeIds(): array
    {
        return array_column(self::siteTypes(), 'id');
    }

    /**
     * Module id => site types it is recommended for.
     *
     * @return array<string,string[]>
     */
    public static function recommendations(): array
    {
        $all = self::typeIds();
        $biz = ['company', 'eshop', 'accommodation'];

        return [
            // Every site: protection, working mail and visibility of problems.
            'security-optimization' => $all,
            'vulnerability-scanner' => $all,
            'smtp-email'            => $all,
            'email-health'          => $all,
            'redirects'             => $all,
            'analytics'             => $all,
            // Sites that talk to customers.
            'email-log'             => $biz,
            'bot-throttle'          => ['eshop', 'blog', 'company'],
            'forms'                 => ['company', 'accommodation', 'eshop'],
            'opening-hours'         => ['company', 'accommodation'],
            'review-aggregator'     => $biz,
            'google-review-request' => $biz,
            // Marketing.
            'top-bar'               => ['eshop', 'accommodation'],
            'popup-manager'         => ['eshop', 'blog'],
            'exit-popup'            => ['eshop', 'blog'],
        ];
    }

    /** @return string[] site types a module is recommended for ([] = no recommendation) */
    public static function typesFor(string $moduleId): array
    {
        return self::recommendations()[$moduleId] ?? [];
    }

    /**
     * Field of the basic-settings step => module settings that may be filled from it. A target is used only when the
     * module and the key exist (the schema decides) and the field is empty or still holds the value the wizard wrote before.
     *
     * @return array<string,array<int,array{0:string,1:string}>>
     */
    public static function profileTargets(): array
    {
        return [
            'sender_email'  => [['smtp-email', 'from_email']],
            'sender_name'   => [['smtp-email', 'from_name'], ['google-review-request', 'sender_name']],
            'contact_email' => [['bot-throttle', 'notify_email'], ['vulnerability-scanner', 'notify_email'], ['security-optimization', 'lockout_notify_email']],
        ];
    }
}
