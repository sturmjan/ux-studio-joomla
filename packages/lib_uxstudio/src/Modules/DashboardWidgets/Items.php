<?php

namespace Ux1\UxStudio\Modules\DashboardWidgets;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Custom cards (notes and links) and the per-module card rules (hidden / visible for user groups).
 * Group lists are stored as a JSON array of user group ids; an empty list means "everyone who sees the home screen".
 */
final class Items
{
    public const ITEMS = '#__uxstudio_dashboard_items';
    public const RULES = '#__uxstudio_dashboard_rules';

    public function __construct(private DatabaseInterface $db)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function all(bool $onlyPublished = false): array
    {
        $db = $this->db;
        $q  = $db->createQuery()->select('*')->from($db->quoteName(self::ITEMS))->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('id') . ' ASC');
        if ($onlyPublished) {
            $q->where($db->quoteName('published') . ' = 1');
        }

        return array_map([$this, 'normalize'], $db->setQuery($q, 0, 200)->loadAssocList() ?: []);
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName(self::ITEMS))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadAssoc();

        return \is_array($row) ? $this->normalize($row) : null;
    }

    /** @param array<string,mixed> $d validated fields: kind,title,body,url,groups(int[]),published,ordering */
    public function save(?int $id, array $d, int $userId): int
    {
        $db        = $this->db;
        $kind      = (string) $d['kind'];
        $title     = (string) $d['title'];
        $body      = (string) $d['body'];
        $url       = (string) $d['url'];
        $groups    = (string) json_encode(array_values(array_map('intval', (array) $d['groups'])));
        $published = (int) (bool) $d['published'];
        $ordering  = (int) $d['ordering'];

        if ($id === null) {
            $now = Factory::getDate()->toSql();
            $db->setQuery(
                $db->createQuery()->insert($db->quoteName(self::ITEMS))
                    ->columns($db->quoteName(['kind', 'title', 'body', 'url', 'group_ids', 'published', 'ordering', 'created', 'created_by']))
                    ->values(':k, :t, :b, :u, :g, :p, :o, :c, :cb')
                    ->bind(':k', $kind)->bind(':t', $title)->bind(':b', $body)->bind(':u', $url)->bind(':g', $groups)
                    ->bind(':p', $published, ParameterType::INTEGER)->bind(':o', $ordering, ParameterType::INTEGER)->bind(':c', $now)->bind(':cb', $userId, ParameterType::INTEGER)
            )->execute();

            return (int) $db->insertid();
        }
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::ITEMS))
                ->set($db->quoteName('kind') . ' = :k')->set($db->quoteName('title') . ' = :t')->set($db->quoteName('body') . ' = :b')
                ->set($db->quoteName('url') . ' = :u')->set($db->quoteName('group_ids') . ' = :g')->set($db->quoteName('published') . ' = :p')->set($db->quoteName('ordering') . ' = :o')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':k', $kind)->bind(':t', $title)->bind(':b', $body)->bind(':u', $url)->bind(':g', $groups)
                ->bind(':p', $published, ParameterType::INTEGER)->bind(':o', $ordering, ParameterType::INTEGER)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();

        return $id;
    }

    public function delete(int $id): bool
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::ITEMS))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();

        return $db->getAffectedRows() > 0;
    }

    /** @return array<string,array{hidden:bool,groups:int[]}> keyed by module id */
    public function rules(): array
    {
        $db   = $this->db;
        $rows = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName(self::RULES)))->loadAssocList() ?: [];
        $out  = [];
        foreach ($rows as $r) {
            $out[(string) $r['module']] = ['hidden' => (bool) $r['hidden'], 'groups' => $this->decodeGroups((string) $r['group_ids'])];
        }

        return $out;
    }

    /** @param int[] $groups */
    public function saveRule(string $module, bool $hidden, array $groups): void
    {
        $db = $this->db;
        if (!$hidden && !$groups) {
            // The default rule: nothing to store.
            $db->setQuery($db->createQuery()->delete($db->quoteName(self::RULES))->where($db->quoteName('module') . ' = :m')->bind(':m', $module))->execute();

            return;
        }
        $h = (int) $hidden;
        $g = (string) json_encode(array_values(array_map('intval', $groups)));
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName(self::RULES) . ' (' . $db->quoteName('module') . ', ' . $db->quoteName('hidden') . ', ' . $db->quoteName('group_ids') . ') VALUES ('
            . $db->quote($module) . ', ' . $h . ', ' . $db->quote($g) . ') ON DUPLICATE KEY UPDATE '
            . $db->quoteName('hidden') . ' = VALUES(' . $db->quoteName('hidden') . '), ' . $db->quoteName('group_ids') . ' = VALUES(' . $db->quoteName('group_ids') . ')'
        )->execute();
    }

    /** @return array<int,array{id:int,title:string,level:int}> all user groups, tree order */
    public function userGroups(): array
    {
        $db   = $this->db;
        $rows = $db->setQuery(
            $db->createQuery()->select(['a.' . $db->quoteName('id'), 'a.' . $db->quoteName('title'), 'COUNT(DISTINCT b.' . $db->quoteName('id') . ') AS ' . $db->quoteName('level')])
                ->from($db->quoteName('#__usergroups', 'a'))
                ->join('LEFT', $db->quoteName('#__usergroups', 'b') . ' ON a.lft > b.lft AND a.rgt < b.rgt')
                ->group(['a.' . $db->quoteName('id'), 'a.' . $db->quoteName('title'), 'a.' . $db->quoteName('lft')])
                ->order('a.' . $db->quoteName('lft') . ' ASC')
        )->loadAssocList() ?: [];

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'level' => (int) $r['level']], $rows);
    }

    /** @param int[] $ids @return int[] only ids that exist */
    public function existingGroups(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if (!$ids) {
            return [];
        }
        $db = $this->db;

        return array_map('intval', $db->setQuery(
            $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__usergroups'))->whereIn($db->quoteName('id'), $ids)
        )->loadColumn() ?: []);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalize(array $row): array
    {
        return [
            'id'        => (int) $row['id'],
            'kind'      => (string) $row['kind'],
            'title'     => (string) $row['title'],
            'body'      => (string) $row['body'],
            'url'       => (string) $row['url'],
            'groups'    => $this->decodeGroups((string) $row['group_ids']),
            'published' => (bool) $row['published'],
            'ordering'  => (int) $row['ordering'],
            'created'   => (string) $row['created'],
        ];
    }

    /** @return int[] */
    private function decodeGroups(string $json): array
    {
        $v = json_decode($json, true);

        return \is_array($v) ? array_values(array_map('intval', $v)) : [];
    }
}
