<?php

namespace Ux1\UxStudio\Modules\CaConnector;

use Joomla\CMS\Factory;
use Joomla\CMS\Updater\Updater;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/** Data of the "updates" and "php-errors" routes (shapes in docs/CA-INTEGRACE.md). */
final class Health
{
    private const PHP_TYPES = 'Fatal\s+error|Parse\s+error|Recoverable\s+fatal\s+error|Warning|Notice|Deprecated|Strict\s+Standards';

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * Core and extension updates from #__updates (filled by Joomla's update checker, see refresh()).
     *
     * @return array{core:array{current:string,latest:string},extensions:array<int,array<string,string>>,checked_at:?string}
     */
    public function updates(): array
    {
        $db      = $this->db;
        $current = (new Version())->getShortVersion();
        $rows    = $db->setQuery(
            $db->createQuery()->select($db->quoteName(['extension_id', 'name', 'element', 'type', 'folder', 'version']))->from($db->quoteName('#__updates'))
        )->loadAssocList() ?: [];

        $latest = $current;
        $byExt  = [];
        foreach ($rows as $row) {
            $id = (int) $row['extension_id'];
            if ($row['element'] === 'joomla' && $row['type'] === 'file') {
                if (version_compare((string) $row['version'], $latest, '>')) {
                    $latest = (string) $row['version'];
                }
                continue;
            }
            if ($id > 0) { // extension_id 0 = an offered language pack / extension that is not installed here
                if (!isset($byExt[$id]) || version_compare((string) $row['version'], (string) $byExt[$id]['version'], '>')) {
                    $byExt[$id] = $row;
                }
            }
        }

        $extensions = [];
        if ($byExt) {
            $ids  = array_keys($byExt);
            $inst = $db->setQuery(
                $db->createQuery()->select($db->quoteName(['extension_id', 'name', 'type', 'element', 'folder', 'manifest_cache']))->from($db->quoteName('#__extensions'))
                    ->whereIn($db->quoteName('extension_id'), $ids, ParameterType::INTEGER)
            )->loadAssocList('extension_id') ?: [];
            foreach ($byExt as $id => $row) {
                $ext = $inst[$id] ?? null;
                if ($ext === null) {
                    continue;
                }
                $manifest = json_decode((string) $ext['manifest_cache'], true);
                $cur      = \is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';
                if ($cur !== '' && !version_compare((string) $row['version'], $cur, '>')) {
                    continue; // stale row
                }
                $type = (string) $ext['type'];
                $el   = (string) $ext['element'];
                $slug = match ($type) {
                    'plugin'   => 'plg_' . $ext['folder'] . '_' . $el,
                    'template' => 'tpl_' . $el,
                    'library'  => 'lib_' . $el,
                    'language' => 'language_' . $el,
                    default    => $el,
                };
                $extensions[] = [
                    'type'    => $type,
                    'element' => $slug,
                    'name'    => (string) ($row['name'] !== '' ? $row['name'] : $ext['name']),
                    'current' => $cur,
                    'latest'  => (string) $row['version'],
                ];
            }
        }

        $checked = $db->setQuery(
            $db->createQuery()->select('MAX(' . $db->quoteName('last_check_timestamp') . ')')->from($db->quoteName('#__update_sites'))->where($db->quoteName('enabled') . ' = 1')
        )->loadResult();

        return [
            'core'       => ['current' => $current, 'latest' => $latest],
            'extensions' => $extensions,
            'checked_at' => $checked ? gmdate('c', (int) $checked) : null,
        ];
    }

    /** Runs Joomla's own update check (skips sites checked within $cacheSeconds). Scheduler job; slow, it calls update servers. */
    public function refresh(int $cacheSeconds = 21600): int
    {
        Updater::getInstance()->findUpdates(0, $cacheSeconds);

        return (int) $this->db->setQuery($this->db->createQuery()->select('COUNT(*)')->from($this->db->quoteName('#__updates')))->loadResult();
    }

    /**
     * Tail of the PHP error log and of the Joomla error log, parsed. Shape of the WordPress route:
     * {total_count, type_counts, errors[{time,type,message,file,line}], log_size, log_path, available}.
     *
     * @return array<string,mixed>
     */
    public function phpErrors(int $limit): array
    {
        $limit = min(200, max(10, $limit));
        $paths = [];
        $ini   = (string) ini_get('error_log');
        if ($ini !== '' && $ini !== 'syslog') {
            $paths[] = $ini;
        }
        $logDir = (string) Factory::getApplication()->get('log_path', JPATH_ADMINISTRATOR . '/logs');
        $paths[] = rtrim($logDir, '/\\') . '/error.php';

        $entries = [];
        $size    = 0;
        $names   = [];
        $usable  = false;
        foreach ($paths as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $usable  = true;
            $size   += (int) filesize($path);
            $names[] = basename($path);
            foreach ($this->tail($path) as $line) {
                $entry = $this->parseLine($line);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        $counts = ['fatal' => 0, 'warning' => 0, 'notice' => 0, 'deprecated' => 0, 'other' => 0];
        foreach ($entries as $e) {
            $counts[$e['kind']]++;
        }
        usort($entries, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);
        $errors = [];
        foreach (\array_slice($entries, 0, $limit) as $e) {
            $errors[] = ['time' => $e['time'], 'type' => $e['type'], 'message' => $e['message'], 'file' => $e['file'], 'line' => $e['line']];
        }

        return [
            'errors'      => $errors,
            'total_count' => \count($entries),
            'type_counts' => $counts,
            'log_size'    => self::bytes($size),
            'log_path'    => $names ? implode(', ', $names) : 'not configured',
            'available'   => $usable,
        ];
    }

    /** @return string[] non-empty lines of the last 512 KB */
    private function tail(string $path): array
    {
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return [];
        }
        $size = (int) filesize($path);
        $max  = 512 * 1024;
        if ($size > $max) {
            fseek($fp, -$max, SEEK_END);
            fgets($fp); // partial first line
        }
        $lines = [];
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') { // "#" lines are the header of Joomla's PHP-protected log files
                $lines[] = $line;
            }
        }
        fclose($fp);

        return $lines;
    }

    /** @return array{time:string,type:string,kind:string,message:string,file:?string,line:?int,ts:int}|null */
    private function parseLine(string $line): ?array
    {
        $file = null;
        $ln   = null;
        if (preg_match('/(?:PHP\s+)?(' . self::PHP_TYPES . '):\s*(.+?)(?:\s+in\s+(\S.*?)\s+on\s+line\s+(\d+))?(?:,\s*referer:.*)?$/i', $line, $m)) {
            $type = trim((string) preg_replace('/\s+/', ' ', $m[1]));
            $file = isset($m[3]) && $m[3] !== '' ? basename($m[3]) : null;
            $ln   = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null;
            $time = preg_match('/^\[([^\]]+)\]/', $line, $tm) ? $tm[1] : '';

            return ['time' => $time, 'type' => $type, 'kind' => self::kind($type), 'message' => mb_substr(trim($m[2]), 0, 500), 'file' => $file, 'line' => $ln, 'ts' => (int) strtotime((string) preg_replace('/(\d{2}:\d{2}:\d{2})\.\d+/', '$1', $time))];
        }
        // Joomla log line: 2026-11-07T10:00:00+00:00<TAB>ERROR 127.0.0.1<TAB>category<TAB>message
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T[\d:+.-]+)\s+(ERROR|CRITICAL|ALERT|EMERGENCY)\s+\S*\s+(\S+)\s+(.*)$/', $line, $m)) {
            return ['time' => $m[1], 'type' => ucfirst(strtolower($m[2])), 'kind' => 'fatal', 'message' => mb_substr(trim($m[4]), 0, 500), 'file' => null, 'line' => null, 'ts' => (int) strtotime($m[1])];
        }

        return null;
    }

    private static function kind(string $type): string
    {
        $t = strtolower($type);

        return match (true) {
            str_contains($t, 'fatal'), str_contains($t, 'parse') => 'fatal',
            $t === 'warning'                                      => 'warning',
            $t === 'notice', str_contains($t, 'strict')           => 'notice',
            $t === 'deprecated'                                   => 'deprecated',
            default                                               => 'other',
        };
    }

    private static function bytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return $bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B';
    }
}
