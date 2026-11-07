<?php

namespace Ux1\UxStudio\Core\Onboarding;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * State of the setup wizard. Kept in the core settings table (#__uxstudio_settings) under a reserved key that can
 * never be a module id (module ids start with a letter), next to the upgrade fingerprint.
 */
final class OnboardingStore
{
    public const KEY = '_onboarding';

    public const STATUSES = ['new', 'started', 'dismissed', 'completed'];

    public function __construct(private DatabaseInterface $db)
    {
    }

    /** @return array{status:string,site_type:string,modules:string[],baseline:?string[],profile:array<string,string>,started_at:?string,completed_at:?string,dismissed_at:?string} */
    public function load(): array
    {
        $stored = [];
        $key    = self::KEY;
        try {
            $json = $this->db->setQuery(
                $this->db->createQuery()->select($this->db->quoteName('data'))->from($this->db->quoteName('#__uxstudio_settings'))
                    ->where($this->db->quoteName('module') . ' = :m')->bind(':m', $key)
            )->loadResult();
            $decoded = json_decode((string) $json, true);
            $stored  = \is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            // The table does not exist before the first migration: behave like a fresh site.
        }

        $profile = \is_array($stored['profile'] ?? null) ? $stored['profile'] : [];
        $status  = \in_array($stored['status'] ?? '', self::STATUSES, true) ? (string) $stored['status'] : 'new';

        return [
            'status'       => $status,
            'site_type'    => (string) ($stored['site_type'] ?? ''),
            'modules'      => array_values(array_filter((array) ($stored['modules'] ?? []), 'is_string')),
            // Modules that were already on when the wizard was opened (null = unknown).
            'baseline'     => \is_array($stored['baseline'] ?? null) ? array_values(array_filter($stored['baseline'], 'is_string')) : null,
            'profile'      => [
                'sender_email'  => (string) ($profile['sender_email'] ?? ''),
                'sender_name'   => (string) ($profile['sender_name'] ?? ''),
                'contact_email' => (string) ($profile['contact_email'] ?? ''),
            ],
            'started_at'   => isset($stored['started_at']) ? (string) $stored['started_at'] : null,
            'completed_at' => isset($stored['completed_at']) ? (string) $stored['completed_at'] : null,
            'dismissed_at' => isset($stored['dismissed_at']) ? (string) $stored['dismissed_at'] : null,
        ];
    }

    /** Merge $patch into the stored state and persist it. */
    public function save(array $patch): array
    {
        $state = array_replace($this->load(), $patch);
        $json  = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now   = Factory::getDate()->toSql();
        $db    = $this->db;
        $key   = self::KEY;

        $exists = (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_settings'))->where($db->quoteName('module') . ' = :m')->bind(':m', $key)
        )->loadResult();

        if ($exists) {
            $q = $db->createQuery()->update($db->quoteName('#__uxstudio_settings'))
                ->set($db->quoteName('data') . ' = :d')->set($db->quoteName('updated_at') . ' = :ts')
                ->where($db->quoteName('module') . ' = :m')
                ->bind(':d', $json)->bind(':ts', $now)->bind(':m', $key);
        } else {
            $q = $db->createQuery()->insert($db->quoteName('#__uxstudio_settings'))
                ->columns($db->quoteName(['module', 'data', 'updated_at']))->values(':m, :d, :ts')
                ->bind(':m', $key)->bind(':d', $json)->bind(':ts', $now);
        }
        $db->setQuery($q)->execute();

        return $this->load();
    }
}
