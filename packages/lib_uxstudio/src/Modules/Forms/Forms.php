<?php

namespace Ux1\UxStudio\Modules\Forms;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Repository of form definitions (#__uxstudio_forms_forms): title, status, fields and per-form settings.
 */
final class Forms
{
    public const STATUSES = ['published', 'draft'];

    public function __construct(private DatabaseInterface $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $db   = $this->db;
        $rows = $db->setQuery(
            $db->createQuery()->select($db->quoteName(['id', 'title', 'status', 'fields', 'created', 'modified']))
                ->from($db->quoteName('#__uxstudio_forms_forms'))->order($db->quoteName('id') . ' DESC')
        )->loadAssocList() ?: [];

        $counts = [];
        $q      = $db->createQuery()
            ->select([$db->quoteName('form_id'), 'COUNT(*) AS ' . $db->quoteName('total'), 'SUM(CASE WHEN ' . $db->quoteName('is_read') . ' = 0 THEN 1 ELSE 0 END) AS ' . $db->quoteName('unread')])
            ->from($db->quoteName('#__uxstudio_forms_submissions'))->group($db->quoteName('form_id'));
        foreach ($db->setQuery($q)->loadAssocList() ?: [] as $c) {
            $counts[(int) $c['form_id']] = $c;
        }

        $out = [];
        foreach ($rows as $row) {
            $id    = (int) $row['id'];
            $out[] = [
                'id'          => $id,
                'title'       => (string) $row['title'],
                'status'      => (string) $row['status'],
                'field_count' => \count(array_filter((array) json_decode((string) $row['fields'], true), static fn ($f) => \is_array($f) && !\in_array($f['type'] ?? '', Fields::LAYOUT, true))),
                'submissions' => (int) ($counts[$id]['total'] ?? 0),
                'unread'      => (int) ($counts[$id]['unread'] ?? 0),
                'created'     => (string) $row['created'],
                'modified'    => (string) $row['modified'],
                'shortcode'   => '{uxsform ' . $id . '}',
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName('#__uxstudio_forms_forms'))
                ->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadAssoc();
        if (!$row) {
            return null;
        }

        return [
            'id'        => (int) $row['id'],
            'title'     => (string) $row['title'],
            'status'    => (string) $row['status'],
            'fields'    => Fields::sanitizeList(json_decode((string) $row['fields'], true)),
            'settings'  => self::sanitizeSettings((array) json_decode((string) $row['settings'], true)),
            'created'   => (string) $row['created'],
            'modified'  => (string) $row['modified'],
            'shortcode' => '{uxsform ' . (int) $row['id'] . '}',
        ];
    }

    /**
     * Creates (id 0) or updates a form from editor input. Returns the form id.
     *
     * @param array<string,mixed> $input
     */
    public function save(int $id, array $input): int
    {
        $db       = $this->db;
        $now      = Factory::getDate()->toSql();
        $title    = Fields::text($input['title'] ?? '', 190);
        $status   = \in_array($input['status'] ?? '', self::STATUSES, true) ? (string) $input['status'] : 'published';
        $fields   = json_encode(Fields::sanitizeList($input['fields'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $settings = json_encode(self::sanitizeSettings((array) ($input['settings'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($id > 0 && $this->get($id) !== null) {
            $q = $db->createQuery()->update($db->quoteName('#__uxstudio_forms_forms'))
                ->set($db->quoteName('title') . ' = :t')->set($db->quoteName('status') . ' = :s')
                ->set($db->quoteName('fields') . ' = :f')->set($db->quoteName('settings') . ' = :se')->set($db->quoteName('modified') . ' = :m')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':t', $title)->bind(':s', $status)->bind(':f', $fields)->bind(':se', $settings)->bind(':m', $now)->bind(':id', $id, ParameterType::INTEGER);
            $db->setQuery($q)->execute();

            return $id;
        }

        $q = $db->createQuery()->insert($db->quoteName('#__uxstudio_forms_forms'))
            ->columns($db->quoteName(['title', 'status', 'fields', 'settings', 'created', 'modified']))
            ->values(':t, :s, :f, :se, :c, :m')
            ->bind(':t', $title)->bind(':s', $status)->bind(':f', $fields)->bind(':se', $settings)->bind(':c', $now)->bind(':m', $now);
        $db->setQuery($q)->execute();

        return (int) $db->insertid();
    }

    public function delete(int $id): void
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_forms_forms'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
    }

    /** Template of a new form: name, e-mail, message and a consent checkbox. */
    public static function starter(): array
    {
        return Fields::sanitizeList([
            ['key' => 'name', 'type' => 'text', 'label' => Text::_('UXS_FORMS_STARTER_NAME'), 'required' => true, 'width' => 50],
            ['key' => 'email', 'type' => 'email', 'label' => Text::_('UXS_FORMS_STARTER_EMAIL'), 'required' => true, 'width' => 50],
            ['key' => 'message', 'type' => 'textarea', 'label' => Text::_('UXS_FORMS_STARTER_MESSAGE'), 'required' => true],
            ['key' => 'consent', 'type' => 'acceptance', 'label' => Text::_('UXS_FORMS_STARTER_CONSENT'), 'required' => true],
        ]);
    }

    /** @return array<string,mixed> */
    public static function defaultSettings(): array
    {
        return [
            'send_notification' => true,
            'to'                => '',
            'subject'           => '',
            'reply_to_field'    => '',
            'attach_files'      => false,
            'confirm_enabled'   => false,
            'confirm_field'     => '',
            'confirm_subject'   => '',
            'confirm_message'   => '',
            'success_message'   => '',
            'redirect_url'      => '',
            'submit_label'      => '',
            'store'             => true,
            'min_time'          => 3,
            'turnstile'         => false,
        ];
    }

    /**
     * @param  array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function sanitizeSettings(array $in): array
    {
        $d = self::defaultSettings();

        $recipients = [];
        foreach (preg_split('/[\s,;]+/', (string) ($in['to'] ?? '')) ?: [] as $mail) {
            if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                $recipients[strtolower($mail)] = $mail;
            }
        }
        $redirect = trim((string) ($in['redirect_url'] ?? ''));
        // Only same-site paths or absolute http(s) URLs; nothing like javascript: or data:.
        if ($redirect !== '' && !preg_match('#^(https?://[^\s"<>]+|/[^\s"<>]*)$#i', $redirect)) {
            $redirect = '';
        }
        if (str_starts_with($redirect, '//')) {
            $redirect = '';
        }

        return [
            'send_notification' => (bool) ($in['send_notification'] ?? $d['send_notification']),
            'to'                => implode(', ', \array_slice(array_values($recipients), 0, 10)),
            'subject'           => Fields::text($in['subject'] ?? '', 250),
            'reply_to_field'    => self::key($in['reply_to_field'] ?? ''),
            'attach_files'      => !empty($in['attach_files']),
            'confirm_enabled'   => !empty($in['confirm_enabled']),
            'confirm_field'     => self::key($in['confirm_field'] ?? ''),
            'confirm_subject'   => Fields::text($in['confirm_subject'] ?? '', 250),
            'confirm_message'   => mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) ($in['confirm_message'] ?? ''))), 0, 5000),
            'success_message'   => Fields::text($in['success_message'] ?? '', 500),
            'redirect_url'      => $redirect,
            'submit_label'      => Fields::text($in['submit_label'] ?? '', 80),
            'store'             => (bool) ($in['store'] ?? $d['store']),
            'min_time'          => max(0, min(60, (int) ($in['min_time'] ?? $d['min_time']))),
            'turnstile'         => !empty($in['turnstile']),
        ];
    }

    private static function key(mixed $v): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9_]/i', '', (string) $v));
    }
}
