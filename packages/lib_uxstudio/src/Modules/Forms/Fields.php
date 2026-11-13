<?php

namespace Ux1\UxStudio\Modules\Forms;

use Joomla\CMS\Filter\InputFilter;

\defined('_JEXEC') or die;

/**
 * Field catalogue of the form builder: sanitizing of field definitions saved from the editor and
 * helpers shared by the renderer and the submit pipeline (conditions, options).
 */
final class Fields
{
    public const TYPES = ['text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox_group', 'checkbox', 'acceptance', 'file', 'hidden', 'html'];

    /** Layout-only type: no value is submitted. */
    public const LAYOUT = ['html'];

    public const CHOICE = ['select', 'radio', 'checkbox_group'];

    public const WIDTHS = [25, 33, 50, 66, 75, 100];

    public const OPERATORS = ['equals', 'not_equals', 'contains', 'empty', 'not_empty'];

    /** Extensions a file field may ever accept (executables, scripts, HTML and SVG are never allowed). */
    public const FILE_ALLOWED = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'zip'];

    public const FILE_DEFAULT = ['jpg', 'jpeg', 'png', 'pdf'];

    public const MAX_FIELDS = 60;

    /**
     * Sanitizes the list of fields posted by the editor. Keys are made unique, unknown types dropped.
     *
     * @param  mixed $raw
     * @return array<int,array<string,mixed>>
     */
    public static function sanitizeList(mixed $raw): array
    {
        $out  = [];
        $seen = [];
        if (!\is_array($raw)) {
            return $out;
        }
        foreach (array_slice(array_values($raw), 0, self::MAX_FIELDS) as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $field = self::sanitize($item);
            if ($field === null) {
                continue;
            }
            $base = $field['key'];
            for ($i = 2; isset($seen[$field['key']]); $i++) {
                $field['key'] = substr($base, 0, 36) . '_' . $i;
            }
            $seen[$field['key']] = true;
            $out[]               = $field;
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function sanitize(array $in): ?array
    {
        $type = (string) ($in['type'] ?? '');
        if (!\in_array($type, self::TYPES, true)) {
            return null;
        }
        $key = strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', (string) ($in['key'] ?? '')));
        $key = trim(substr($key, 0, 40), '_');
        if ($key === '' || !preg_match('/^[a-z]/', $key)) {
            $key = $type . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
        }

        $width = (int) ($in['width'] ?? 100);
        $field = [
            'key'           => $key,
            'type'          => $type,
            'label'         => self::text($in['label'] ?? '', 200),
            'placeholder'   => self::text($in['placeholder'] ?? '', 200),
            'help'          => self::text($in['help'] ?? '', 300),
            'required'      => !empty($in['required']) && !\in_array($type, self::LAYOUT, true) && $type !== 'hidden',
            'default_value' => self::text($in['default_value'] ?? '', 500),
            'width'         => \in_array($width, self::WIDTHS, true) ? $width : 100,
            'css_class'     => trim((string) preg_replace('/[^A-Za-z0-9_ -]/', '', (string) ($in['css_class'] ?? ''))),
            'conditions'    => self::conditions($in['conditions'] ?? []),
            'logic'         => (($in['logic'] ?? 'all') === 'any') ? 'any' : 'all',
        ];

        if (\in_array($type, self::CHOICE, true)) {
            $field['options'] = self::options($in['options'] ?? []);
        }
        if ($type === 'html') {
            // Whitelist of harmless text markup; scripts, event handlers and javascript: URLs never survive the filter.
            $filter        = InputFilter::getInstance(
                ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'span', 'div', 'small', 'hr'],
                ['href', 'target', 'rel', 'class'],
                InputFilter::ONLY_ALLOW_DEFINED_TAGS,
                InputFilter::ONLY_ALLOW_DEFINED_ATTRIBUTES
            );
            // Drop script/style elements together with their content, otherwise the code would show up as visible text.
            $raw = (string) ($in['html'] ?? '');
            // Repeat until stable (a nested "<scr<script></script>ipt>" cannot rebuild a tag), then cut an unclosed one to the end.
            do {
                $before = $raw;
                $raw    = (string) preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1\s*>#is', '', $raw);
            } while ($raw !== $before);
            $raw = (string) preg_replace('#<(script|style|iframe|object|embed)\b.*$#is', '', $raw);
            $field['html'] = $filter->clean($raw, 'html');
        }
        if ($type === 'acceptance') {
            $url                = (string) ($in['terms_url'] ?? '');
            $field['terms_url'] = preg_match('#^(https?://|/)[^\s"<>]*$#i', $url) ? $url : '';
        }
        if (\in_array($type, ['text', 'textarea', 'email', 'tel', 'url'], true)) {
            $field['minlength'] = self::nullableInt($in['minlength'] ?? null, 0, 10000);
            $field['maxlength'] = self::nullableInt($in['maxlength'] ?? null, 1, 20000);
        }
        if ($type === 'number') {
            $field['min'] = self::nullableFloat($in['min'] ?? null);
            $field['max'] = self::nullableFloat($in['max'] ?? null);
        }
        if ($type === 'textarea') {
            $field['rows'] = max(2, min(20, (int) ($in['rows'] ?? 5)));
        }
        if ($type === 'file') {
            $accept = [];
            foreach ((array) ($in['accept'] ?? []) as $ext) {
                $ext = strtolower(ltrim((string) $ext, '.'));
                if (\in_array($ext, self::FILE_ALLOWED, true)) {
                    $accept[$ext] = $ext;
                }
            }
            $field['accept']      = array_values($accept) ?: self::FILE_DEFAULT;
            $field['max_size_mb'] = max(1, min(5, (int) ($in['max_size_mb'] ?? 2)));
            $field['multiple']    = !empty($in['multiple']);
        }

        return $field;
    }

    /** @return array<int,array{label:string,value:string}> */
    public static function options(mixed $raw): array
    {
        $out = [];
        foreach (\is_array($raw) ? array_slice($raw, 0, 100) : [] as $o) {
            if (!\is_array($o)) {
                continue;
            }
            $label = self::text($o['label'] ?? '', 200);
            $value = self::text($o['value'] ?? $label, 200);
            if ($label === '' && $value === '') {
                continue;
            }
            $out[] = ['label' => $label !== '' ? $label : $value, 'value' => $value !== '' ? $value : $label];
        }

        return $out;
    }

    /** @return array<int,array{field:string,operator:string,value:string}> */
    public static function conditions(mixed $raw): array
    {
        $out = [];
        foreach (\is_array($raw) ? array_slice($raw, 0, 10) : [] as $c) {
            if (!\is_array($c)) {
                continue;
            }
            $field = strtolower((string) preg_replace('/[^a-z0-9_]/i', '', (string) ($c['field'] ?? '')));
            $op    = (string) ($c['operator'] ?? 'equals');
            if ($field === '' || !\in_array($op, self::OPERATORS, true)) {
                continue;
            }
            $out[] = ['field' => $field, 'operator' => $op, 'value' => self::text($c['value'] ?? '', 200)];
        }

        return $out;
    }

    /**
     * Whether a field is visible for the submitted values (no conditions = always visible).
     *
     * @param array<string,mixed> $field
     * @param array<string,mixed> $values submitted values by field key (string or list of strings)
     */
    public static function isVisible(array $field, array $values): bool
    {
        $conditions = $field['conditions'] ?? [];
        if (!$conditions) {
            return true;
        }
        $results = [];
        foreach ($conditions as $c) {
            $current   = $values[$c['field']] ?? '';
            $list      = \is_array($current) ? array_map('strval', $current) : [(string) $current];
            $joined    = implode(',', $list);
            $results[] = match ($c['operator']) {
                'equals'     => \in_array((string) $c['value'], $list, true),
                'not_equals' => !\in_array((string) $c['value'], $list, true),
                'contains'   => $c['value'] !== '' && str_contains(mb_strtolower($joined), mb_strtolower((string) $c['value'])),
                'empty'      => trim($joined) === '',
                'not_empty'  => trim($joined) !== '',
                default      => true,
            };
        }

        return ($field['logic'] ?? 'all') === 'any' ? \in_array(true, $results, true) : !\in_array(false, $results, true);
    }

    public static function text(mixed $value, int $max): string
    {
        $value = \is_scalar($value) ? (string) $value : '';
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return trim(mb_substr($value, 0, $max));
    }

    private static function nullableInt(mixed $v, int $min, int $max): ?int
    {
        return ($v === null || $v === '' || !is_numeric($v)) ? null : max($min, min($max, (int) $v));
    }

    private static function nullableFloat(mixed $v): ?float
    {
        return ($v === null || $v === '' || !is_numeric($v)) ? null : (float) $v;
    }
}
