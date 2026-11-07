<?php

namespace Ux1\UxStudio\Modules\ImageOptimizer;

use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Walks the images folder in batches. The state lives in the table: a file is pending when it has no row or its size
 * differs from the recorded result, so a run can be stopped and resumed at any time (also by the scheduler).
 */
final class Bulk
{
    /** Files looked at in one scan before the result is reported as truncated. */
    public const SCAN_LIMIT = 60000;

    /** @param array<string,mixed> $settings */
    public function __construct(private DatabaseInterface $db, private Store $store, private array $settings)
    {
    }

    /**
     * Counts the images and how many are still to do.
     *
     * @return array{total:int,pending:int,pending_bytes:int,truncated:bool}
     */
    public function scan(): array
    {
        // Housekeeping while the admin looks at the numbers: rows of files that vanished (moved, renamed, deleted over FTP).
        try {
            (new Optimizer($this->settings, $this->store))->cleanupOrphans($this->db);
        } catch (\Throwable) {
        }
        $total = 0;
        $pending = 0;
        $bytes = 0;
        $truncated = false;
        $chunk = [];
        $flush = function () use (&$chunk, &$pending, &$bytes): void {
            foreach ($this->pendingOf($chunk) as $size) {
                $pending++;
                $bytes += $size;
            }
            $chunk = [];
        };
        foreach ($this->files() as $rel => $size) {
            if ($total >= self::SCAN_LIMIT) {
                $truncated = true;
                break;
            }
            $total++;
            // A sibling older than its image is a leftover of a file that was replaced outside the Media Manager.
            Optimizer::dropStaleSiblings(JPATH_ROOT . '/' . $rel);
            $chunk[$rel] = $size;
            if (\count($chunk) >= 400) {
                $flush();
            }
        }
        $flush();

        return ['total' => $total, 'pending' => $pending, 'pending_bytes' => $bytes, 'truncated' => $truncated];
    }

    /**
     * Next pending files.
     *
     * @return string[] site-relative paths
     */
    public function next(int $limit): array
    {
        $out   = [];
        $chunk = [];
        $seen  = 0;
        foreach ($this->files() as $rel => $size) {
            if (++$seen > self::SCAN_LIMIT) {
                break;
            }
            $chunk[$rel] = $size;
            if (\count($chunk) >= 200) {
                $out = array_merge($out, array_keys($this->pendingOf($chunk)));
                $chunk = [];
                if (\count($out) >= $limit) {
                    return \array_slice($out, 0, $limit);
                }
            }
        }
        $out = array_merge($out, array_keys($this->pendingOf($chunk)));

        return \array_slice($out, 0, $limit);
    }

    /**
     * @param array<string,int> $chunk path => size
     *
     * @return array<string,int> the pending ones
     */
    private function pendingOf(array $chunk): array
    {
        if (!$chunk) {
            return [];
        }
        $known = $this->store->sizes(array_keys($chunk));
        $out   = [];
        foreach ($chunk as $rel => $size) {
            $hash = Store::hash($rel);
            if (!isset($known[$hash]) || $known[$hash] !== $size) {
                $out[$rel] = $size;
            }
        }

        return $out;
    }

    /**
     * Eligible image files: allowed extension, not tiny, not in an excluded or hidden folder.
     *
     * @return \Generator<string,int> site-relative path => size
     */
    public function files(): \Generator
    {
        $min     = max(0, (int) ($this->settings['min_size_kb'] ?? 0)) * 1024;
        $exclude = [];
        foreach (preg_split('/\R/', (string) ($this->settings['exclude_paths'] ?? '')) ?: [] as $line) {
            $line = trim(str_replace('\\', '/', $line), " \t/");
            if ($line !== '') {
                $exclude[] = str_starts_with($line, 'images/') ? $line : 'images/' . $line;
            }
        }

        // Only the images folder is walked in bulk; other local directories are covered on upload.
        $root = realpath(JPATH_ROOT . '/images');
        if ($root === false) {
            return;
        }
        $prefix = rtrim(str_replace('\\', '/', (string) realpath(JPATH_ROOT)), '/') . '/';
        $it     = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $f) use ($prefix, $exclude): bool {
                    if (str_starts_with($f->getFilename(), '.')) {
                        return false;
                    }
                    if ($f->isLink()) {
                        return false;
                    }
                    if ($f->isDir()) {
                        $rel = substr(str_replace('\\', '/', $f->getPathname()), \strlen($prefix));
                        foreach ($exclude as $e) {
                            if ($rel === $e || str_starts_with($rel . '/', $e . '/')) {
                                return false;
                            }
                        }
                    }

                    return true;
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !\in_array(strtolower($file->getExtension()), Optimizer::EXTENSIONS, true)) {
                continue;
            }
            $size = (int) $file->getSize();
            if ($size < $min || $size === 0) {
                continue;
            }
            $rel = substr(str_replace('\\', '/', $file->getPathname()), \strlen($prefix));
            if (\strlen($rel) > 700) {
                continue; // does not fit the path column
            }
            $skip = false;
            foreach ($exclude as $e) {
                if (str_starts_with($rel, $e . '/') || $rel === $e) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                yield $rel => $size;
            }
        }
    }
}
