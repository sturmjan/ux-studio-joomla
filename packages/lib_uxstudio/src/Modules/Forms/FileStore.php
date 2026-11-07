<?php

namespace Ux1\UxStudio\Modules\Forms;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Storage of uploaded files. Files live in <log_path>/uxs-forms/ (administrator/logs by default), a folder that is
 * sealed from the web (deny-all .htaccess, web.config, index.html) and holds files under random names without any
 * extension, so nothing uploaded can ever be executed or guessed. The original name is kept in the database and used
 * for the download through the (authenticated) administrator API.
 */
final class FileStore
{
    /** Extension => mime types finfo may report for it ("*" wildcards allowed). */
    private const MIME = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv'],
        'doc'  => ['application/msword', 'application/CDFV2*', 'application/x-ole-storage', 'application/octet-stream'],
        'xls'  => ['application/vnd.ms-excel', 'application/CDFV2*', 'application/x-ole-storage', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.*', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.*', 'application/zip', 'application/octet-stream'],
        'odt'  => ['application/vnd.oasis.opendocument.*', 'application/zip', 'application/octet-stream'],
        'ods'  => ['application/vnd.oasis.opendocument.*', 'application/zip', 'application/octet-stream'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    public static function baseDir(): string
    {
        $log = (string) Factory::getApplication()->get('log_path', JPATH_ADMINISTRATOR . '/logs');

        return rtrim(str_replace('\\', '/', $log !== '' ? $log : JPATH_ADMINISTRATOR . '/logs'), '/') . '/uxs-forms';
    }

    /** Creates the folder and its deny rules (idempotent). */
    public static function ensureBase(): string
    {
        $base = self::baseDir();
        if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
            throw new \RuntimeException('Cannot create the upload folder.');
        }
        $files = [
            '.htaccess'  => "# UX Studio Forms: uploaded files are never served directly.\nOptions -Indexes\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
            'index.html' => '<!DOCTYPE html><title></title>',
        ];
        foreach ($files as $name => $content) {
            if (!is_file($base . '/' . $name)) {
                file_put_contents($base . '/' . $name, $content);
            }
        }

        return $base;
    }

    /**
     * Validates one uploaded file against the field definition and returns its prepared data (not yet written).
     *
     * @param  array<string,mixed> $field
     * @param  mixed               $item  {name, data (base64)}
     * @return array{name:string,ext:string,mime:string,bytes:string}
     * @throws \InvalidArgumentException with a translated message
     */
    public static function inspect(array $field, mixed $item, int $maxMb): array
    {
        if (!\is_array($item) || !\is_string($item['data'] ?? null) || !\is_string($item['name'] ?? null)) {
            throw new \InvalidArgumentException(Text::_('UXS_FORMS_ERR_FILE_INVALID'));
        }
        $limit = max(1, min($maxMb, (int) ($field['max_size_mb'] ?? 2))) * 1048576;
        $data  = (string) $item['data'];
        $comma = strpos($data, ',');
        if (str_starts_with($data, 'data:') && $comma !== false) {
            $data = substr($data, $comma + 1);
        }
        // Reject by the encoded length before decoding anything.
        if (\strlen($data) > (int) ceil($limit * 4 / 3) + 8) {
            throw new \InvalidArgumentException(Text::sprintf('UXS_FORMS_ERR_FILE_SIZE', (int) ($limit / 1048576)));
        }
        $bytes = base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            throw new \InvalidArgumentException(Text::_('UXS_FORMS_ERR_FILE_INVALID'));
        }
        if (\strlen($bytes) > $limit) {
            throw new \InvalidArgumentException(Text::sprintf('UXS_FORMS_ERR_FILE_SIZE', (int) ($limit / 1048576)));
        }

        return self::validate($field, (string) $item['name'], $bytes);
    }

    /**
     * Same checks for a multipart upload (ApiRequest::files() entry); the content is read from the temporary file.
     *
     * @param  array<string,mixed> $field
     * @param  mixed               $file  {name, size, tmp_name, error}
     * @return array{name:string,ext:string,mime:string,bytes:string}
     * @throws \InvalidArgumentException with a translated message
     */
    public static function inspectUpload(array $field, mixed $file, int $maxMb): array
    {
        if (!\is_array($file) || !\is_string($file['name'] ?? null) || !\is_string($file['tmp_name'] ?? null) || !is_file($file['tmp_name'])) {
            throw new \InvalidArgumentException(Text::_('UXS_FORMS_ERR_FILE_INVALID'));
        }
        $limit = max(1, min($maxMb, (int) ($field['max_size_mb'] ?? 2))) * 1048576;
        if ((int) filesize($file['tmp_name']) > $limit) {
            throw new \InvalidArgumentException(Text::sprintf('UXS_FORMS_ERR_FILE_SIZE', (int) ($limit / 1048576)));
        }
        $bytes = (string) file_get_contents($file['tmp_name']);
        if ($bytes === '') {
            throw new \InvalidArgumentException(Text::_('UXS_FORMS_ERR_FILE_INVALID'));
        }

        return self::validate($field, $file['name'], $bytes);
    }

    /**
     * @param  array<string,mixed> $field
     * @return array{name:string,ext:string,mime:string,bytes:string}
     */
    private static function validate(array $field, string $rawName, string $bytes): array
    {
        // Original name: base name only, no control characters, bounded length.
        $name = (string) preg_replace('/[\x00-\x1F\x7F\\\\\/<>:"|?*]+/u', '_', basename(str_replace('\\', '/', $rawName)));
        $name = trim(mb_substr($name, 0, 120), ". \t");
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = array_intersect((array) ($field['accept'] ?? Fields::FILE_DEFAULT), Fields::FILE_ALLOWED);
        if ($name === '' || !\in_array($ext, $allowed, true) || !isset(self::MIME[$ext])) {
            throw new \InvalidArgumentException(Text::sprintf('UXS_FORMS_ERR_FILE_TYPE', implode(', ', $allowed)));
        }
        // A double extension like "a.php.jpg" is harmless here (stored without extension) but is still refused.
        if (preg_match('/\.(php\d?|phtml|phar|pl|py|cgi|asp|aspx|jsp|exe|sh|bat|cmd|html?|js|svg)\./i', $name . '.')) {
            throw new \InvalidArgumentException(Text::sprintf('UXS_FORMS_ERR_FILE_TYPE', implode(', ', $allowed)));
        }

        $mime = 'application/octet-stream';
        if (\function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE)) !== false) {
            $mime = (string) finfo_buffer($fi, $bytes);
            finfo_close($fi);
        }
        $ok = false;
        foreach (self::MIME[$ext] as $pattern) {
            if (fnmatch($pattern, $mime)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new \InvalidArgumentException(Text::sprintf('UXS_FORMS_ERR_FILE_TYPE', implode(', ', $allowed)));
        }

        return ['name' => $name, 'ext' => $ext, 'mime' => $mime, 'bytes' => $bytes];
    }

    /**
     * Writes a validated file and returns the stored entry (relative path inside the base folder).
     *
     * @param  array{name:string,ext:string,mime:string,bytes:string} $file
     * @return array{name:string,size:int,mime:string,path:string}
     */
    public static function write(int $formId, array $file): array
    {
        $base = self::ensureBase();
        $sub  = $formId . '/' . gmdate('Ym');
        if (!is_dir($base . '/' . $sub) && !@mkdir($base . '/' . $sub, 0755, true) && !is_dir($base . '/' . $sub)) {
            throw new \RuntimeException('Cannot create the upload folder.');
        }
        $rel = $sub . '/' . bin2hex(random_bytes(16)) . '.dat';
        if (file_put_contents($base . '/' . $rel, $file['bytes']) === false) {
            throw new \RuntimeException('Cannot store the uploaded file.');
        }

        return ['name' => $file['name'], 'size' => \strlen($file['bytes']), 'mime' => $file['mime'], 'path' => $rel];
    }

    /** Absolute path of a stored file, or null when the relative path is not a plain file inside the base folder. */
    public static function resolve(string $rel): ?string
    {
        if (!preg_match('#^\d+/\d{6}/[a-f0-9]{32}\.dat$#', $rel)) {
            return null;
        }
        $path = self::baseDir() . '/' . $rel;

        return is_file($path) ? $path : null;
    }

    /** Removes the files of one submission's stored `files` structure. */
    public static function deleteAll(array $files): void
    {
        foreach ($files as $list) {
            foreach ((array) $list as $entry) {
                $path = \is_array($entry) ? self::resolve((string) ($entry['path'] ?? '')) : null;
                if ($path !== null) {
                    @unlink($path);
                }
            }
        }
    }
}
