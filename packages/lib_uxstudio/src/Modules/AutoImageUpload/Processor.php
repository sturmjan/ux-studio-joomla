<?php

namespace Ux1\UxStudio\Modules\AutoImageUpload;

use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Uri\Uri;
use Ux1\UxStudio\Core\Http;
use Ux1\UxStudio\Core\Kernel;

\defined('_JEXEC') or die;

/**
 * Finds <img> tags that point at other websites, downloads the images into images/<folder>/ and rewrites the tags.
 *
 * Downloads go through Core\Http (SSRF guard: public addresses only, the checked address is pinned, redirects are
 * validated, size cap). A file is stored only when its CONTENT is a JPEG, PNG, GIF, WebP or AVIF image; the extension
 * comes from the detected type, never from the address. SVG and everything else is refused.
 */
final class Processor
{
    private const TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
        19             => 'avif', // IMAGETYPE_AVIF (PHP 8.1+)
    ];

    /** @var string[] */
    private array $siteHosts = [];

    /** @var string[] */
    private array $excluded;

    private int $downloads = 0;

    /** @var string[] */
    private array $errors = [];

    /** @param array<string,mixed> $settings */
    public function __construct(private array $settings, private Store $store, private Http $http)
    {
        foreach ([Uri::root(), Uri::getInstance()->toString(['scheme', 'host', 'port'])] as $u) {
            $h = self::bareHost((string) $u);
            if ($h !== '') {
                $this->siteHosts[] = $h;
            }
        }
        $this->excluded = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($settings['excluded_domains'] ?? '')) ?: [])));
    }

    public function downloads(): int
    {
        return $this->downloads;
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Rewrites one HTML fragment.
     *
     * @param array{context:string,id:int,title:string,alias:string} $info
     * @param int                                                    $budget downloads still allowed (decreased)
     * @param bool                                                   $dryRun only counts the external images
     * @param float                                                  $deadline microtime after which nothing new is downloaded
     *
     * @return array{html:string,found:int,replaced:int,failed:int,left:int}
     */
    public function process(string $html, array $info, int &$budget, bool $dryRun = false, float $deadline = 0.0): array
    {
        $out = ['html' => $html, 'found' => 0, 'replaced' => 0, 'failed' => 0, 'left' => 0];
        if ($html === '' || stripos($html, '<img') === false) {
            return $out;
        }

        $out['html'] = (string) preg_replace_callback('#<img\b[^>]*>#i', function (array $m) use (&$out, &$budget, $info, $dryRun, $deadline): string {
            $tag = $m[0];
            if (!preg_match('#\ssrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $tag, $sm)) {
                return $tag;
            }
            $src = html_entity_decode(trim($sm[1] !== '' ? $sm[1] : ($sm[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!$this->isCandidate($src)) {
                return $tag;
            }
            $out['found']++;
            if ($dryRun) {
                return $tag;
            }

            $alt = preg_match('#\salt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $tag, $am) ? html_entity_decode($am[1] !== '' ? $am[1] : ($am[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            $res = $this->resolve($src, $info, $alt, $budget, $deadline);
            if ($res['state'] !== 'ok') {
                $out[$res['state']]++;

                return $tag;
            }
            $local = $res['path'];
            $out['replaced']++;

            return $this->rewrite($tag, $local, $info);
        }, $html);

        return $out;
    }

    /**
     * Local path for an external address: from the map when it was downloaded before, otherwise downloaded now.
     *
     * @param array{context:string,id:int,title:string,alias:string} $info
     *
     * @return array{state:string,path:string} state ok | failed | left (budget or time used up)
     */
    public function resolve(string $src, array $info, string $alt, int &$budget, float $deadline = 0.0): array
    {
        $known = $this->store->get($src);
        if ($known && $known['status'] === 'ok' && is_file(JPATH_ROOT . '/' . $known['path'])) {
            return ['state' => 'ok', 'path' => (string) $known['path']];
        }
        if ($known && $known['status'] === 'failed' && (time() - (int) strtotime($known['updated'] . ' UTC')) < Store::RETRY_AFTER) {
            return ['state' => 'failed', 'path' => '']; // failed recently: do not make every save wait for it again
        }
        if ($budget <= 0 || ($deadline > 0 && microtime(true) > $deadline)) {
            return ['state' => 'left', 'path' => ''];
        }
        $budget--;
        try {
            $path = $this->fetch($src, $info, $alt, $deadline);
            $this->store->saveOk($src, $path, $info['context'], $info['id']);
            $this->downloads++;

            return ['state' => 'ok', 'path' => $path];
        } catch (\Throwable $e) {
            $this->store->saveFailed($src, $e->getMessage(), $info['context'], $info['id']);
            $this->errors[] = mb_substr($src, 0, 120) . ': ' . $e->getMessage();

            return ['state' => 'failed', 'path' => ''];
        }
    }

    /**
     * Value for the article image fields: the local path plus the joomlaImage suffix with the size, as the Media field
     * stores it (width and height prevent layout shift).
     */
    public static function withSize(string $rel): string
    {
        $info = str_starts_with($rel, 'images/') ? @getimagesize(JPATH_ROOT . '/' . $rel) : false;
        if (!$info) {
            return $rel;
        }

        return $rel . '#joomlaImage://local-images/' . substr($rel, 7) . '?width=' . (int) $info[0] . '&height=' . (int) $info[1];
    }

    /** True for an address that points at another website (or is an embedded data: image). */
    public function isCandidate(string $src): bool
    {
        if ($src === '') {
            return false;
        }
        if (stripos($src, 'data:image/') === 0) {
            return !empty($this->settings['process_data_uri']);
        }
        if (!preg_match('#^(https?:)?//#i', $src)) {
            return false; // relative, root-relative, mailto, javascript, blob ...
        }
        $host = self::bareHost($src);
        if ($host === '' || \in_array($host, $this->siteHosts, true)) {
            return false;
        }
        foreach ($this->excluded as $entry) {
            $entry = strtolower($entry);
            if (str_starts_with($entry, '*.')) {
                // "*.example.com" covers the sub-domains and the domain itself
                if ($host === substr($entry, 2) || str_ends_with($host, substr($entry, 1))) {
                    return false;
                }
            } elseif (self::bareHost($entry) === $host) {
                return false;
            }
        }

        return true;
    }

    /** Lower-case host without www. and without port, '' when there is none. */
    public static function bareHost(string $url): string
    {
        $url   = trim($url);
        $parts = parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) || str_starts_with($url, '//') ? $url : '//' . $url);
        $host  = strtolower((string) ($parts['host'] ?? ''));

        return (string) preg_replace('/^www\./', '', $host);
    }

    /**
     * Downloads (or decodes) one image and stores it. Returns the path relative to the site root.
     *
     * @param array{context:string,id:int,title:string,alias:string} $info
     */
    private function fetch(string $src, array $info, string $alt, float $deadline = 0.0): string
    {
        $max = max(1, (int) ($this->settings['max_file_size_mb'] ?? 5)) * 1048576;
        if (stripos($src, 'data:') === 0) {
            if (!preg_match('#^data:image/[a-z0-9.+-]+;base64,(.+)$#is', $src, $m)) {
                throw new \RuntimeException('invalid data URI');
            }
            $data = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
            if ($data === false || $data === '') {
                throw new \RuntimeException('invalid base64');
            }
            $name = '';
        } else {
            if (str_starts_with($src, '//')) {
                $src = 'https:' . $src;
            }
            // One download never outlives the time the save has left (and never takes more than 15 s).
            $timeout = $deadline > 0 ? (int) max(3, min(15, ceil($deadline - microtime(true)))) : 15;
            $r = $this->http->get($src, ['timeout' => $timeout, 'max_bytes' => $max, 'headers' => ['Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/gif,*/*;q=0.5']]);
            if ($r['status'] !== 200) {
                throw new \RuntimeException('HTTP ' . $r['status']);
            }
            $data = $r['body'];
            $name = pathinfo((string) parse_url($src, PHP_URL_PATH), PATHINFO_FILENAME);
        }
        if (\strlen($data) > $max) {
            throw new \RuntimeException('larger than ' . ($max / 1048576) . ' MB');
        }
        $type = @getimagesizefromstring($data);
        if (!$type || !isset(self::TYPES[$type[2]])) {
            throw new \RuntimeException('not a JPEG, PNG, GIF, WebP or AVIF image');
        }
        $ext = self::TYPES[$type[2]];

        $now = time();
        $ctx = [
            '%filename%'  => $name,
            '%image_alt%' => $alt,
            '%title%'     => $info['title'],
            '%alias%'     => $info['alias'],
            '%id%'        => (string) $info['id'],
            '%year%'      => gmdate('Y', $now),
            '%month%'     => gmdate('m', $now),
            '%day%'       => gmdate('d', $now),
            '%date%'      => gmdate('Y-m-d', $now),
            '%timestamp%' => (string) $now,
            '%random%'    => bin2hex(random_bytes(4)),
            '%host%'      => self::bareHost($src),
        ];

        $base = self::slug(strtr((string) ($this->settings['file_pattern'] ?? '%filename%'), $ctx));
        if ($base === '') {
            $base = self::slug($name);
        }
        if ($base === '') {
            $base = 'image-' . bin2hex(random_bytes(4));
        }

        return $this->store($data, $ext, $base, $this->folder($ctx));
    }

    /** Folder below images/ from the setting (placeholders %year% %month% %day% %host%), every segment sanitized. */
    private function folder(array $ctx): string
    {
        $segments = [];
        foreach (explode('/', strtr((string) ($this->settings['folder'] ?? 'external/%year%/%month%'), $ctx)) as $seg) {
            $seg = self::slug($seg);
            if ($seg !== '') {
                $segments[] = $seg;
            }
        }

        return 'images' . ($segments ? '/' . implode('/', \array_slice($segments, 0, 4)) : '');
    }

    private function store(string $data, string $ext, string $base, string $dirRel): string
    {
        $dir = JPATH_ROOT . '/' . $dirRel;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create ' . $dirRel);
        }
        $hash = sha1($data);
        $name = $base . '.' . $ext;
        for ($i = 1; is_file($dir . '/' . $name); $i++) {
            if (sha1_file($dir . '/' . $name) === $hash) {
                return $dirRel . '/' . $name; // the very same picture is already there
            }
            if ($i > 500) {
                throw new \RuntimeException('no free file name');
            }
            $name = $base . '-' . $i . '.' . $ext;
        }
        if (@file_put_contents($dir . '/' . $name, $data) === false) {
            throw new \RuntimeException('cannot write ' . $dirRel . '/' . $name);
        }
        $rel = $dirRel . '/' . $name;
        $this->optimize($rel);

        return $rel;
    }

    /** Hands the new file to the Image optimizer when that module is on (it compresses once and keeps the original). */
    private function optimize(string $rel): void
    {
        try {
            $kernel   = Kernel::instance();
            $registry = $kernel->registry();
            if (!$registry->isEnabled('image-optimizer')) {
                return;
            }
            $module = $registry->module('image-optimizer');
            if ($module instanceof \Ux1\UxStudio\Modules\ImageOptimizer\Module && \in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), \Ux1\UxStudio\Modules\ImageOptimizer\Optimizer::EXTENSIONS, true)) {
                $module->optimizer()->optimizeFile($rel, 'auto-image-upload', false, $kernel->db());
            }
        } catch (\Throwable) {
            // optimization is a bonus, the image is already stored
        }
    }

    /** Rewrites src (and drops srcset / sizes that still point at the other site); fills an empty alt from the pattern. */
    private function rewrite(string $tag, string $local, array $info): string
    {
        $base = trim((string) ($this->settings['base_url'] ?? ''));
        if ($base !== '' && preg_match('#^https?://[^/\s]+$#i', rtrim($base, '/'))) {
            $url = rtrim($base, '/') . '/' . $local;
        } else {
            $url = $local; // relative to the site root, as the editor writes it: survives moving the site to another folder
        }
        $attr = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $tag = (string) preg_replace('#\s(?:srcset|sizes|data-srcset|data-lazy-srcset|data-src)\s*=\s*(?:"[^"]*"|\'[^\']*\')#i', '', $tag);
        $tag = (string) preg_replace_callback('#(\ssrc\s*=\s*)(?:"[^"]*"|\'[^\']*\')#i', static fn (array $m): string => $m[1] . '"' . $attr . '"', $tag, 1);

        $pattern = trim((string) ($this->settings['alt_pattern'] ?? '%image_alt%'));
        if ($pattern !== '' && $pattern !== '%image_alt%') {
            $hasAlt = preg_match('#\salt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $tag, $am);
            $alt    = $hasAlt ? html_entity_decode($am[1] !== '' ? $am[1] : ($am[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            $new    = trim(strtr($pattern, ['%image_alt%' => $alt, '%title%' => $info['title'], '%alias%' => $info['alias'], '%id%' => (string) $info['id']]));
            if ($new !== '' && $new !== $alt) {
                $altAttr = ' alt="' . htmlspecialchars($new, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
                $tag     = $hasAlt
                    ? (string) preg_replace_callback('#\salt\s*=\s*(?:"[^"]*"|\'[^\']*\')#i', static fn (): string => $altAttr, $tag, 1)
                    : (string) preg_replace_callback('#\s*/?>$#', static fn (): string => $altAttr . ' />', $tag, 1);
            }
        }

        return $tag;
    }

    /** File-name safe, lower-case ASCII slug (diacritics transliterated by Joomla). */
    public static function slug(string $text): string
    {
        $slug = OutputFilter::stringURLSafe(trim($text));

        return mb_substr(trim($slug, '-'), 0, 80);
    }
}
