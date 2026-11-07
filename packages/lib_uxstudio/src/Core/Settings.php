<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Per-module settings stored as one JSON document in #__uxstudio_settings.
 * Everything is validated and sanitised on the server according to the module's settingsSchema().
 * Fields of type "password" are stored encrypted and never sent to the browser (only "__set__").
 */
final class Settings
{
    /** Marker returned to / accepted from the SPA instead of a stored secret. */
    public const SECRET_MARKER = '__set__';

    /** @var array<string,array<string,mixed>> */
    private array $cache = [];

    public function __construct(private DatabaseInterface $db, private ModuleRegistry $registry)
    {
    }

    /** Raw stored document (password values still encrypted). */
    private function stored(string $moduleId): array
    {
        if (isset($this->cache[$moduleId])) {
            return $this->cache[$moduleId];
        }
        $data = [];
        try {
            $json = $this->db->setQuery(
                $this->db->createQuery()->select($this->db->quoteName('data'))->from($this->db->quoteName('#__uxstudio_settings'))
                    ->where($this->db->quoteName('module') . ' = :m')->bind(':m', $moduleId)
            )->loadResult();
            $decoded = json_decode((string) $json, true);
            $data    = \is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            // Table missing before the first migration.
        }

        return $this->cache[$moduleId] = $data;
    }

    /** True when a value for the key was saved (as opposed to the schema default). */
    public function hasStored(string $moduleId, string $key): bool
    {
        return \array_key_exists($key, $this->stored($moduleId));
    }

    /** Stored value as is (password fields stay encrypted); null when nothing was saved. */
    public function storedValue(string $moduleId, string $key): mixed
    {
        return $this->stored($moduleId)[$key] ?? null;
    }

    /** Raw (untranslated) schema keyed by field key; unknown module => empty. */
    public function rawSchema(string $moduleId): array
    {
        if ($moduleId === CoreSettings::ID) {
            $definition = CoreSettings::schema();
        } else {
            $module = $this->registry->module($moduleId);
            if (!$module) {
                return [];
            }
            $definition = $module->settingsSchema();
        }
        $fields = [];
        foreach ($definition as $field) {
            if (isset($field['key']) && preg_match('/^[A-Za-z0-9_]+$/', (string) $field['key'])) {
                $fields[$field['key']] = self::normalizeField($field);
            }
        }

        return $fields;
    }

    /** Defaults for the type; a repeater gets its sub fields normalised (sub fields cannot be repeaters or passwords). */
    private static function normalizeField(array $field): array
    {
        $field += ['type' => 'text'];
        if ($field['type'] === 'repeater') {
            $sub = [];
            foreach ((array) ($field['fields'] ?? []) as $child) {
                if (\is_array($child) && isset($child['key']) && preg_match('/^[A-Za-z0-9_]+$/', (string) $child['key'])
                    && !\in_array($child['type'] ?? 'text', ['repeater', 'password'], true)) {
                    $child += ['type' => 'text'];
                    $sub[$child['key']] = $child;
                }
            }
            $field['fields'] = $sub;
        }

        return $field;
    }

    /** Schema with language keys resolved, ready for the SPA. @return array<int,array<string,mixed>> */
    public function schema(string $moduleId): array
    {
        $out = [];
        foreach ($this->rawSchema($moduleId) as $field) {
            $out[] = self::translateField($field);
        }

        return $out;
    }

    /** @param array<string,mixed> $field @return array<string,mixed> labels, option labels and text defaults resolved (sub fields too) */
    private static function translateField(array $field): array
    {
        foreach (['label', 'help', 'section', 'group', 'group_help', 'placeholder', 'add_label', 'item_label'] as $k) {
            if (isset($field[$k]) && \is_string($field[$k])) {
                $field[$k] = self::translate($field[$k]);
            }
        }
        if (isset($field['options']) && \is_array($field['options'])) {
            foreach ($field['options'] as $value => $label) {
                $field['options'][$value] = self::translate((string) $label);
            }
        }
        if (\array_key_exists('default', $field)) {
            $field['default'] = self::defaultFor($field);
        }
        if (($field['type'] ?? '') === 'repeater') {
            $field['fields'] = array_values(array_map([self::class, 'translateField'], (array) ($field['fields'] ?? [])));
        }
        unset($field['sanitize']);

        return $field;
    }

    private static function translate(string $text): string
    {
        return preg_match('/^[A-Z][A-Z0-9_]{3,}$/', $text) ? Text::_($text) : $text;
    }

    /**
     * A text / textarea / richtext default that is a language key (UXS_...) is translated, so a module can ship a default
     * subject or message in the language of the site. Everything else is returned as declared.
     */
    private static function translateDefault(string $text): string
    {
        if (!preg_match('/^UXS_[A-Z0-9_]+$/', $text)) {
            return $text;
        }
        try {
            $lang = Factory::getApplication()->getLanguage();

            return $lang->hasKey($text) ? Text::_($text) : $text;
        } catch (\Throwable $e) {
            return $text;
        }
    }

    private static function defaultFor(array $field): mixed
    {
        if (\array_key_exists('default', $field)) {
            $default = $field['default'];

            return \is_string($default) && \in_array($field['type'] ?? 'text', ['text', 'textarea', 'richtext'], true) ? self::translateDefault($default) : $default;
        }

        return match ($field['type']) {
            'toggle' => false,
            'number' => 0,
            'multiselect', 'repeater' => [],
            default => '',
        };
    }

    /** All values for server-side use: defaults merged in, secrets decrypted. */
    public function all(string $moduleId): array
    {
        $stored = $this->stored($moduleId);
        $values = [];
        foreach ($this->rawSchema($moduleId) as $key => $field) {
            $value = \array_key_exists($key, $stored) ? $stored[$key] : self::defaultFor($field);
            if ($field['type'] === 'password' && \is_string($value)) {
                $value = Crypto::decrypt($value);
            }
            $values[$key] = $value;
        }

        return $values;
    }

    public function get(string $moduleId, string $key, mixed $default = null): mixed
    {
        $all = $this->all($moduleId);

        return \array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** Values for the browser: password fields are replaced by "__set__" / "". */
    public function forClient(string $moduleId): array
    {
        $stored = $this->stored($moduleId);
        $values = [];
        foreach ($this->rawSchema($moduleId) as $key => $field) {
            if ($field['type'] === 'password') {
                $values[$key] = !empty($stored[$key]) ? self::SECRET_MARKER : '';
                continue;
            }
            $values[$key] = \array_key_exists($key, $stored) ? $stored[$key] : self::defaultFor($field);
        }

        return $values;
    }

    /**
     * Validate $input against the schema and persist. Keys missing from $input keep their stored value.
     *
     * @throws ApiException on invalid input
     */
    public function save(string $moduleId, array $input): array
    {
        $schema = $this->rawSchema($moduleId);
        $stored = $this->stored($moduleId);
        $next   = [];

        foreach ($schema as $key => $field) {
            if (!\array_key_exists($key, $input)) {
                if (\array_key_exists($key, $stored)) {
                    $next[$key] = $stored[$key];
                }
                continue;
            }
            $next[$key] = $this->sanitize($field, $input[$key], $stored[$key] ?? null);
        }

        $json = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now  = Factory::getDate()->toSql();
        $db   = $this->db;

        $exists = (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_settings'))->where($db->quoteName('module') . ' = :m')->bind(':m', $moduleId)
        )->loadResult();

        if ($exists) {
            $q = $db->createQuery()->update($db->quoteName('#__uxstudio_settings'))
                ->set($db->quoteName('data') . ' = :d')->set($db->quoteName('updated_at') . ' = :ts')
                ->where($db->quoteName('module') . ' = :m')
                ->bind(':d', $json)->bind(':ts', $now)->bind(':m', $moduleId);
        } else {
            $q = $db->createQuery()->insert($db->quoteName('#__uxstudio_settings'))
                ->columns($db->quoteName(['module', 'data', 'updated_at']))->values(':m, :d, :ts')
                ->bind(':m', $moduleId)->bind(':d', $json)->bind(':ts', $now);
        }
        $db->setQuery($q)->execute();
        unset($this->cache[$moduleId]);

        return $this->forClient($moduleId);
    }

    /** @param string|null $previous the currently stored (encrypted) value, for secrets */
    private function sanitize(array $field, mixed $value, mixed $previous): mixed
    {
        $type = (string) $field['type'];
        $key  = (string) $field['key'];

        switch ($type) {
            case 'toggle':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);

            case 'number':
                if (!is_numeric($value)) {
                    throw new ApiException($key . ': not a number', 422, 'invalid_value');
                }
                $n = $value + 0;
                if (!is_finite((float) $n)) {
                    throw new ApiException($key . ': not a finite number', 422, 'invalid_value');
                }
                if (isset($field['min']) && $n < $field['min']) {
                    $n = $field['min'];
                }
                if (isset($field['max']) && $n > $field['max']) {
                    $n = $field['max'];
                }

                return $n;

            case 'select':
                $options = (array) ($field['options'] ?? []);
                $value   = (string) (\is_scalar($value) ? $value : '');
                if ($value === '' || \array_key_exists($value, $options)) {
                    return $value;
                }
                throw new ApiException($key . ': invalid option', 422, 'invalid_value');

            case 'multiselect':
                $options = array_map('strval', array_keys((array) ($field['options'] ?? [])));
                $list    = array_values(array_unique(array_map('strval', array_filter((array) $value, 'is_scalar'))));

                return array_values(array_intersect($list, $options));

            case 'color':
                $value = trim((string) (\is_scalar($value) ? $value : ''));
                if ($value === '' || preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)) {
                    return $value;
                }
                throw new ApiException($key . ': invalid color', 422, 'invalid_value');

            case 'datetime':
                $value = trim((string) (\is_scalar($value) ? $value : ''));
                if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $value)) {
                    return $value;
                }
                throw new ApiException($key . ': invalid date', 422, 'invalid_value');

            case 'css':
                $value = (string) (\is_scalar($value) ? $value : '');
                $value = str_replace("\0", '', $value);
                // Prevent breaking out of a <style> element.
                $value = preg_replace('#<\s*/?\s*(style|script)#i', '', $value) ?? '';

                return $this->limit($field, $value);

            case 'richtext':
                $value = (string) (\is_scalar($value) ? $value : '');

                return $this->limit($field, self::safeLinks(InputFilter::getInstance([], [], 1, 1)->clean($value, 'html')));

            case 'repeater':
                return $this->sanitizeRepeater($field, $value);

            case 'textarea':
                $value = (string) (\is_scalar($value) ? $value : '');

                return $this->limit($field, trim(strip_tags(str_replace("\0", '', $value))));

            case 'password':
                // Only an explicit string can set / clear a secret; null or structures are rejected (never a silent wipe).
                if (!\is_string($value)) {
                    throw new ApiException($key . ': invalid value', 422, 'invalid_value');
                }
                if ($value === self::SECRET_MARKER) {
                    return \is_string($previous) ? $previous : '';
                }

                return $value === '' ? '' : Crypto::encrypt($value);

            case 'media':
                // A Joomla media path (images/a.png, optionally with the #joomlaImage:// suffix) or a http(s) URL; never a script / data URL.
                $value = trim(strip_tags(str_replace("\0", '', (string) (\is_scalar($value) ? $value : ''))));
                if ($value !== '' && (str_contains($value, '..') || preg_match('#^(?!https?://)[a-z][a-z0-9+.-]*:#i', $value))) {
                    throw new ApiException($key . ': invalid media path', 422, 'invalid_value');
                }

                return $this->limit($field, $value);

            case 'text':
            default:
                $value = (string) (\is_scalar($value) ? $value : '');

                return $this->limit($field, trim(strip_tags(str_replace("\0", '', $value))));
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function sanitizeRepeater(array $field, mixed $value): array
    {
        $key  = (string) $field['key'];
        $rows = \is_array($value) ? array_values($value) : [];
        $max  = max(1, min(200, (int) ($field['max'] ?? 50)));
        if (\count($rows) > $max) {
            throw new ApiException($key . ': too many items (maximum ' . $max . ')', 422, 'invalid_value');
        }
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $clean = [];
            foreach ((array) ($field['fields'] ?? []) as $subKey => $sub) {
                $clean[$subKey] = \array_key_exists($subKey, $row) ? $this->sanitize($sub, $row[$subKey], null) : self::defaultFor($sub);
            }
            $out[] = $clean;
        }

        return $out;
    }

    /** Rich text links: only http(s), mailto, tel, relative and #anchor targets; everything else becomes "#". */
    private static function safeLinks(string $html): string
    {
        return (string) preg_replace_callback(
            '/\b(href|src)\s*=\s*(["\'])(.*?)\2/is',
            static function (array $m): string {
                $url = html_entity_decode(trim($m[3]), ENT_QUOTES | ENT_HTML5);
                $url = preg_replace('/[\x00-\x20\x7f]+/', '', $url) ?? '';

                return preg_match('#^(https?:|mailto:|tel:|/|\#|\?)#i', $url) || !preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) ? $m[0] : $m[1] . '=' . $m[2] . '#' . $m[2];
            },
            $html
        );
    }

    private function limit(array $field, string $value): string
    {
        $max = (int) ($field['maxlength'] ?? 0);

        return $max > 0 ? mb_substr($value, 0, $max) : $value;
    }
}
