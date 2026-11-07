<?php

namespace Ux1\UxStudio\Modules\ExitPopup;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/** E-mail addresses captured by the exit popup (#__uxstudio_exit_popup_emails). Raw IP addresses are never stored. */
final class Subscribers
{
    public const PER_PAGE = 50;

    public function __construct(private DatabaseInterface $db)
    {
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int} */
    public function page(int $page, string $search): array
    {
        $db    = $this->db;
        $page  = max(1, $page);
        $like  = '%' . addcslashes($search, '%_\\') . '%';
        $count = $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_exit_popup_emails'));
        $query = $db->createQuery()->select($db->quoteName(['id', 'created', 'email', 'page_url', 'consent']))->from($db->quoteName('#__uxstudio_exit_popup_emails'))->order($db->quoteName('id') . ' DESC');
        if ($search !== '') {
            foreach ([$count, $query] as $q) {
                $q->where($db->quoteName('email') . ' LIKE :q')->bind(':q', $like);
            }
        }
        $total = (int) $db->setQuery($count)->loadResult();
        $rows  = $db->setQuery($query, ($page - 1) * self::PER_PAGE, self::PER_PAGE)->loadAssocList() ?: [];

        return ['rows' => $this->cast($rows), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / self::PER_PAGE))];
    }

    /** @return array<int,array<string,mixed>> */
    public function everything(int $limit = 50000): array
    {
        $db = $this->db;

        return $this->cast($db->setQuery(
            $db->createQuery()->select($db->quoteName(['id', 'created', 'email', 'page_url', 'consent']))->from($db->quoteName('#__uxstudio_exit_popup_emails'))->order($db->quoteName('id') . ' DESC'),
            0,
            $limit
        )->loadAssocList() ?: []);
    }

    public function count(): int
    {
        $db = $this->db;

        return (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_exit_popup_emails')))->loadResult();
    }

    /** @return bool true when the address is new */
    public function add(string $email, string $pageUrl, string $ipHash, bool $consent): bool
    {
        $db = $this->db;
        $id = $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__uxstudio_exit_popup_emails'))->where($db->quoteName('email') . ' = :e')->bind(':e', $email))->loadResult();
        if ($id) {
            return false;
        }
        $now = Factory::getDate()->toSql();
        $c   = $consent ? 1 : 0;
        try {
            $db->setQuery(
                $db->createQuery()->insert($db->quoteName('#__uxstudio_exit_popup_emails'))
                    ->columns($db->quoteName(['created', 'email', 'page_url', 'ip_hash', 'consent']))->values(':c, :e, :p, :h, :k')
                    ->bind(':c', $now)->bind(':e', $email)->bind(':p', $pageUrl)->bind(':h', $ipHash)->bind(':k', $c, ParameterType::INTEGER)
            )->execute();
        } catch (\Throwable $e) {
            // Two requests with the same address raced: the unique key makes the loser a duplicate.
            return false;
        }

        return true;
    }

    public function delete(int $id): bool
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_exit_popup_emails'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();

        return $db->getAffectedRows() > 0;
    }

    public function purge(): int
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_exit_popup_emails')))->execute();

        return $db->getAffectedRows();
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function cast(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['id']      = (int) $row['id'];
            $row['consent'] = (bool) $row['consent'];
        }

        return $rows;
    }
}
