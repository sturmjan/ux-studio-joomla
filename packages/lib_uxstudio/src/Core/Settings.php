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

    /** Raw (untranslated) schema keyed by field key; unknown module => empty. */
    public function rawSchema(string $moduleId): array
    {
        $module = $this->registry->module($moduleId);
        if (!$module) {
            return [];
        }
        $fields = [];
        foreach ($module->settingsSchema() as $field) {
            if (isset($field['key']) && preg_match('/^[A-Za-z0-9_]+$/', (string) $field['key'])) {
                $field += ['type' => 'text'];
                $fields[$field['key']] = $field;
            }
        }

        return $fields;
    }

    /** Schema with language keys resolved, ready for the SPA. @return array<int,array<string,mixed>> */
    public function schema(string $moduleId): array
    {
        $out = [];
        foreach ($this->rawSchema($moduleId) as $field) {
            foreach (['label', 'help', 'section', 'group', 'group_help', 'placeholder'] as $k) {
                if (isset($field[$k]) && \is_string($field[$k])) {
                    $field[$k] = self::translate($field[$k]);
                }
            }
            if (isset($field['options']) && \is_array($field['options'])) {
                foreach ($field['options'] as $value => $label) {
                    $field['options'][$value] = self::translate((string) $label);
                }
            }
            unset($field['sanitize']);
            $out[] = $field;
        }

        return $out;
    }

    private static function translate(string $text): string
    {
        return preg_match('/^[A-Z][A-Z0-9_]{3,}$/', $text) ? Text::_($text) : $text;
    }

    private static function defaultFor(array $field): mixed
    {
        if (\array_key_exists('default', $field)) {
            return $field['default'];
        }

        return match ($field['type']) {
            'toggle' => false,
            'number' => 0,
            'multiselect' => [],
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

                return $this->limit($field, InputFilter::getInstance([], [], 1, 1)->clean($value, 'html'));

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
            case 'text':
            default:
                $value = (string) (\is_scalar($value) ? $value : '');

                return $this->limit($field, trim(strip_tags(str_replace("\0", '', $value))));
        }
    }

    private function limit(array $field, string $value): string
    {
        $max = (int) ($field['maxlength'] ?? 0);

        return $max > 0 ? mb_substr($value, 0, $max) : $value;
    }
}
