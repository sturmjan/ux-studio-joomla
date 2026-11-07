<?php

namespace Ux1\UxStudio\Modules\Forms;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Repository of stored form submissions (#__uxstudio_forms_submissions) plus CSV export.
 */
final class Submissions
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * @param array<string,mixed>                  $data  field key => value (string | list of strings)
     * @param array<string,array<int,array<string,mixed>>> $files field key => stored file entries
     */
    public function insert(int $formId, array $data, array $files, string $ip, string $userAgent, string $pageUrl): int
    {
        $db   = $this->db;
        $now  = Factory::getDate()->toSql();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $fj   = $files ? json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $ip   = self::anonymize($ip);
        $ua   = mb_substr($userAgent, 0, 250);
        $url  = mb_substr($pageUrl, 0, 500);
        $mail = 'none';
        $q    = $db->createQuery()->insert($db->quoteName('#__uxstudio_forms_submissions'))
            ->columns($db->quoteName(['form_id', 'created', 'is_read', 'ip', 'user_agent', 'page_url', 'data', 'files', 'mail_status']))
            ->values(':f, :c, 0, :ip, :ua, :url, :d, :fl, :ms')
            ->bind(':f', $formId, ParameterType::INTEGER)->bind(':c', $now)->bind(':ip', $ip)->bind(':ua', $ua)->bind(':url', $url)
            ->bind(':d', $json)->bind(':fl', $fj, $fj === null ? ParameterType::NULL : ParameterType::STRING)->bind(':ms', $mail);
        $db->setQuery($q)->execute();

        return (int) $db->insertid();
    }

    public function setMailStatus(int $id, string $status): void
    {
        $db = $this->db;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__uxstudio_forms_submissions'))->set($db->quoteName('mail_status') . ' = :s')
                ->where($db->quoteName('id') . ' = :id')->bind(':s', $status)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function search(int $formId, string $search, string $status, int $page, int $perPage = 20, bool $withMeta = false): array
    {
        $db = $this->db;
        $q  = $db->createQuery()->from($db->quoteName('#__uxstudio_forms_submissions', 's'));
        if ($formId > 0) {
            $q->where($db->quoteName('s.form_id') . ' = :fid')->bind(':fid', $formId, ParameterType::INTEGER);
        }
        if ($status === 'unread') {
            $q->where($db->quoteName('s.is_read') . ' = 0');
        }
        if ($search !== '') {
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $q->where('(' . $db->quoteName('s.data') . ' LIKE :q OR ' . $db->quoteName('s.ip') . ' LIKE :q2)')->bind(':q', $like)->bind(':q2', $like);
        }

        $count = clone $q;
        $total = (int) $db->setQuery($count->select('COUNT(*)'))->loadResult();
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = max(1, min($page, $pages));

        $q->select($db->quoteName(['s.id', 's.form_id', 's.created', 's.is_read', 's.data', 's.files', 's.mail_status']))
            ->select($withMeta ? $db->quoteName(['s.ip', 's.page_url']) : [])
            ->select($db->quoteName('f.title', 'form_title'))
            ->join('LEFT', $db->quoteName('#__uxstudio_forms_forms', 'f') . ' ON ' . $db->quoteName('f.id') . ' = ' . $db->quoteName('s.form_id'))
            ->order($db->quoteName('s.id') . ' DESC');
        $rows = $db->setQuery($q, ($page - 1) * $perPage, $perPage)->loadAssocList() ?: [];

        return ['items' => array_map([self::class, 'shape'], $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery(
            $db->createQuery()->select($db->quoteName(['s.id', 's.form_id', 's.created', 's.is_read', 's.ip', 's.user_agent', 's.page_url', 's.data', 's.files', 's.mail_status']))
                ->select($db->quoteName('f.title', 'form_title'))
                ->from($db->quoteName('#__uxstudio_forms_submissions', 's'))
                ->join('LEFT', $db->quoteName('#__uxstudio_forms_forms', 'f') . ' ON ' . $db->quoteName('f.id') . ' = ' . $db->quoteName('s.form_id'))
                ->where($db->quoteName('s.id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadAssoc();

        return $row ? self::shape($row) : null;
    }

    public function markRead(array $ids, bool $read): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return;
        }
        $db   = $this->db;
        $flag = $read ? 1 : 0;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__uxstudio_forms_submissions'))->set($db->quoteName('is_read') . ' = :r')
                ->whereIn($db->quoteName('id'), $ids)->bind(':r', $flag, ParameterType::INTEGER)
        )->execute();
    }

    /** Deletes the given submissions together with their files. Returns the number of removed rows. */
    public function delete(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $db = $this->db;
        foreach ($db->setQuery($db->createQuery()->select($db->quoteName('files'))->from($db->quoteName('#__uxstudio_forms_submissions'))->whereIn($db->quoteName('id'), $ids))->loadColumn() ?: [] as $files) {
            if ($files) {
                FileStore::deleteAll((array) json_decode((string) $files, true));
            }
        }
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_forms_submissions'))->whereIn($db->quoteName('id'), $ids))->execute();

        return $db->getAffectedRows();
    }

    public function deleteForForm(int $formId): void
    {
        $db  = $this->db;
        $ids = $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__uxstudio_forms_submissions'))->where($db->quoteName('form_id') . ' = :f')->bind(':f', $formId, ParameterType::INTEGER))->loadColumn() ?: [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $this->delete($chunk);
        }
    }

    /** Removes submissions (and their files) older than the given number of days; returns the count. */
    public function purgeOlderThan(int $days): int
    {
        $db     = $this->db;
        $cutoff = Factory::getDate('-' . $days . ' days')->toSql();
        $ids    = $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__uxstudio_forms_submissions'))->where($db->quoteName('created') . ' < :c')->bind(':c', $cutoff))->loadColumn() ?: [];
        $n      = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $n += $this->delete($chunk);
        }

        return $n;
    }

    public function unreadCount(): int
    {
        $db = $this->db;

        return (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_forms_submissions'))->where($db->quoteName('is_read') . ' = 0'))->loadResult();
    }

    /**
     * Builds the CSV (UTF-8 with BOM, ';' separated) of all submissions of a form.
     *
     * @param array<string,mixed> $form
     */
    public function csv(array $form, string $search = ''): string
    {
        $fields = array_values(array_filter((array) $form['fields'], static fn ($f) => !\in_array($f['type'], Fields::LAYOUT, true)));
        $out    = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        $header = ['ID', 'Date (UTC)'];
        foreach ($fields as $f) {
            $header[] = $f['label'] !== '' ? $f['label'] : $f['key'];
        }
        $header[] = 'IP';
        $header[] = 'URL';
        fputcsv($out, array_map([self::class, 'safeCell'], $header), ';', '"', '\\');

        $page = 1;
        do {
            $res = $this->search((int) $form['id'], $search, 'all', $page, 200, true);
            foreach ($res['items'] as $full) {
                $line = [$full['id'], $full['created']];
                foreach ($fields as $f) {
                    $value = $full['data'][$f['key']] ?? '';
                    if ($f['type'] === 'file') {
                        $value = array_map(static fn ($e) => (string) ($e['name'] ?? ''), (array) ($full['files'][$f['key']] ?? []));
                    }
                    $line[] = \is_array($value) ? implode(', ', $value) : (string) $value;
                }
                $line[] = $full['ip'];
                $line[] = $full['page_url'];
                fputcsv($out, array_map([self::class, 'safeCell'], $line), ';', '"', '\\');
            }
            $page++;
        } while ($page <= $res['pages']);

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** Neutralizes spreadsheet formula injection. */
    public static function safeCell(mixed $v): string
    {
        $v = (string) $v;

        return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
    }

    /** Last IPv4 octet / last 80 bits of IPv6 are dropped before storing. */
    public static function anonymize(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return '';
        }
        if (\strlen($bin) === 4) {
            $bin[3] = "\0";
        } else {
            $bin = substr($bin, 0, 6) . str_repeat("\0", 10);
        }

        return (string) inet_ntop($bin);
    }

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'form_id'     => (int) $row['form_id'],
            'form_title'  => (string) ($row['form_title'] ?? ''),
            'created'     => (string) $row['created'],
            'is_read'     => (bool) $row['is_read'],
            'ip'          => (string) ($row['ip'] ?? ''),
            'user_agent'  => (string) ($row['user_agent'] ?? ''),
            'page_url'    => (string) ($row['page_url'] ?? ''),
            'mail_status' => (string) ($row['mail_status'] ?? ''),
            'data'        => (array) json_decode((string) $row['data'], true),
            'files'       => $row['files'] ? (array) json_decode((string) $row['files'], true) : [],
        ];
    }
}
