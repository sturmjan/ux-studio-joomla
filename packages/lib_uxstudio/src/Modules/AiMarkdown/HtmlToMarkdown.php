<?php

namespace Ux1\UxStudio\Modules\AiMarkdown;

\defined('_JEXEC') or die;

/**
 * Local (no external service) HTML to markdown conversion built on DOMDocument: headings, paragraphs, links, images,
 * nested lists, blockquotes, code blocks, tables (GFM), emphasis. Scripts, styles, forms and other non-content
 * elements are dropped; relative URLs are made absolute so the markdown is usable away from the site.
 */
final class HtmlToMarkdown
{
    /** Nesting limit, protects against pathological markup. */
    private const MAX_DEPTH = 40;

    /** Input cap in bytes. */
    private const MAX_INPUT = 1500000;

    private const SKIP = ['script', 'style', 'template', 'noscript', 'form', 'button', 'input', 'select', 'textarea', 'option', 'svg', 'canvas', 'object', 'embed', 'head', 'title', 'meta', 'link', 'map'];

    private const BLOCK = ['p', 'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'nav', 'figure', 'figcaption', 'fieldset', 'details', 'summary', 'address', 'center', 'dl', 'dt', 'dd',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'hr', 'table', 'iframe', 'video', 'audio'];

    /** @param string $root absolute site root with trailing slash, used to resolve relative URLs */
    public function __construct(private string $root)
    {
    }

    public function convert(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        if (\strlen($html) > self::MAX_INPUT) {
            $html = substr($html, 0, self::MAX_INPUT);
        }

        $doc  = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><div id="uxs-md-root">' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('uxs-md-root');
        if (!$root) {
            return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        }

        $out = implode("\n\n", $this->blocks($root, 0));
        $out = preg_replace("/[ \t]+\n/", "\n", $out) ?? $out;
        $out = preg_replace("/\n{3,}/", "\n\n", $out) ?? $out;

        return trim($out);
    }

    /**
     * Block-level pieces of an element's content: inline runs between block elements become paragraphs.
     *
     * @return string[]
     */
    private function blocks(\DOMNode $node, int $depth): array
    {
        $parts  = [];
        $inline = '';
        $flush  = function () use (&$parts, &$inline): void {
            $text = trim(preg_replace('/[ \t]*\n[ \t]*/', "\n", $inline) ?? $inline);
            if ($text !== '') {
                $parts[] = $text;
            }
            $inline = '';
        };

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $inline .= $this->text($child->data);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (\in_array($tag, self::SKIP, true)) {
                continue;
            }
            if (!\in_array($tag, self::BLOCK, true)) {
                $inline .= $this->inline($child, $depth + 1);
                continue;
            }
            $flush();
            if ($depth >= self::MAX_DEPTH) {
                $plain = trim($this->text($child->textContent));
                if ($plain !== '') {
                    $parts[] = $plain;
                }
                continue;
            }
            foreach ($this->block($child, $tag, $depth + 1) as $piece) {
                if (trim($piece) !== '') {
                    $parts[] = $piece;
                }
            }
        }
        $flush();

        return $parts;
    }

    /** @return string[] */
    private function block(\DOMElement $el, string $tag, int $depth): array
    {
        switch ($tag) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $text = trim(preg_replace('/\s+/', ' ', $this->inlineChildren($el, $depth)) ?? '');

                return $text === '' ? [] : [str_repeat('#', (int) $tag[1]) . ' ' . $text];
            case 'ul':
            case 'ol':
                $list = $this->listItems($el, $tag === 'ol', $depth);

                return $list === '' ? [] : [$list];
            case 'blockquote':
                $inner = implode("\n\n", $this->blocks($el, $depth));
                if (trim($inner) === '') {
                    return [];
                }
                $lines = array_map(static fn (string $l): string => rtrim('> ' . $l), explode("\n", $inner));

                return [implode("\n", $lines)];
            case 'pre':
                return [$this->codeBlock($el)];
            case 'hr':
                return ['---'];
            case 'table':
                $table = $this->table($el, $depth);

                return $table === '' ? [] : [$table];
            case 'iframe':
                $src = $this->url((string) $el->getAttribute('src'));
                $title = trim((string) $el->getAttribute('title'));

                return $src === '' ? [] : ['[' . ($title !== '' ? $title : 'Embedded content') . '](' . $src . ')'];
            case 'video':
            case 'audio':
                $src = (string) $el->getAttribute('src');
                if ($src === '') {
                    foreach ($el->getElementsByTagName('source') as $source) {
                        $src = (string) $source->getAttribute('src');
                        if ($src !== '') {
                            break;
                        }
                    }
                }
                $src = $this->url($src);

                return $src === '' ? [] : ['[' . ($tag === 'video' ? 'Video' : 'Audio') . '](' . $src . ')'];
            case 'li':
                // A stray <li> outside a list.
                return $this->blocks($el, $depth);
            case 'dt':
                $text = trim($this->inlineChildren($el, $depth));

                return $text === '' ? [] : ['**' . $text . '**'];
            default:
                return $this->blocks($el, $depth);
        }
    }

    private function listItems(\DOMElement $list, bool $ordered, int $depth): string
    {
        $lines = [];
        $n     = 1;
        $start = $ordered ? (int) $list->getAttribute('start') : 0;
        if ($start > 0) {
            $n = $start;
        }
        foreach ($list->childNodes as $li) {
            if (!$li instanceof \DOMElement || strtolower($li->tagName) !== 'li') {
                continue;
            }
            $marker = $ordered ? $n . '. ' : '- ';
            $n++;
            $content = implode("\n", $this->blocks($li, $depth + 1));
            $content = trim($content);
            if ($content === '') {
                continue;
            }
            $pad   = str_repeat(' ', \strlen($marker));
            $rows  = explode("\n", $content);
            $first = array_shift($rows);
            $out   = $marker . $first;
            foreach ($rows as $row) {
                $out .= "\n" . ($row === '' ? '' : $pad . $row);
            }
            $lines[] = $out;
        }

        return implode("\n", $lines);
    }

    private function codeBlock(\DOMElement $pre): string
    {
        $lang = '';
        foreach ($pre->getElementsByTagName('code') as $code) {
            if (preg_match('/(?:language|lang)-([A-Za-z0-9_+-]+)/', (string) $code->getAttribute('class'), $m)) {
                $lang = $m[1];
            }
            break;
        }
        $code = str_replace(["\r\n", "\r"], "\n", $pre->textContent);
        $code = trim($code, "\n");
        $fence = str_contains($code, '```') ? '~~~~' : '```';

        return $fence . $lang . "\n" . $code . "\n" . $fence;
    }

    private function table(\DOMElement $table, int $depth): string
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            // Skip rows of nested tables.
            $parent = $tr->parentNode;
            while ($parent && !($parent instanceof \DOMElement && strtolower($parent->tagName) === 'table')) {
                $parent = $parent->parentNode;
            }
            if ($parent !== $table) {
                continue;
            }
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && \in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $text    = trim(preg_replace('/\s+/', ' ', $this->inlineChildren($cell, $depth + 1)) ?? '');
                    $cells[] = str_replace('|', '\\|', $text);
                }
            }
            if ($cells) {
                $rows[] = $cells;
            }
        }
        if (!$rows) {
            return '';
        }
        $cols = max(array_map('count', $rows));
        $line = static function (array $cells) use ($cols): string {
            $cells = array_pad($cells, $cols, '');

            return '| ' . implode(' | ', $cells) . ' |';
        };
        $out   = [$line(array_shift($rows))];
        $out[] = '|' . str_repeat(' --- |', $cols);
        foreach ($rows as $row) {
            $out[] = $line($row);
        }

        return implode("\n", $out);
    }

    private function inlineChildren(\DOMNode $node, int $depth): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $out .= $this->text($child->data);
            } elseif ($child instanceof \DOMElement && !\in_array(strtolower($child->tagName), self::SKIP, true)) {
                $out .= $depth >= self::MAX_DEPTH ? $this->text($child->textContent) : $this->inline($child, $depth + 1);
            }
        }

        return $out;
    }

    private function inline(\DOMElement $el, int $depth): string
    {
        $tag = strtolower($el->tagName);
        switch ($tag) {
            case 'br':
                return "  \n";
            case 'img':
                $src = $this->url((string) $el->getAttribute('src'));
                if ($src === '') {
                    return '';
                }
                $alt = trim(preg_replace('/\s+/', ' ', (string) $el->getAttribute('alt')) ?? '');

                return '![' . str_replace(['[', ']', chr(92)], '', $alt) . '](' . $src . ')';
            case 'a':
                $inner = $this->inlineChildren($el, $depth);
                $href  = trim((string) $el->getAttribute('href'));
                $text  = trim(preg_replace('/\s+/', ' ', $inner) ?? '');
                if ($text === '') {
                    return '';
                }
                $url = $this->url($href);
                if ($url === '' || str_starts_with($href, '#')) {
                    return $inner;
                }

                return '[' . $text . '](' . $url . ')';
            case 'strong':
            case 'b':
                return $this->wrap($this->inlineChildren($el, $depth), '**');
            case 'em':
            case 'i':
                return $this->wrap($this->inlineChildren($el, $depth), '*');
            case 'del':
            case 's':
            case 'strike':
                return $this->wrap($this->inlineChildren($el, $depth), '~~');
            case 'code':
            case 'kbd':
            case 'samp':
                $code = trim($el->textContent);

                return $code === '' ? '' : (str_contains($code, '`') ? '`` ' . $code . ' ``' : '`' . $code . '`');
            case 'iframe':
                $src = $this->url((string) $el->getAttribute('src'));

                return $src === '' ? '' : '[Embedded content](' . $src . ')';
            default:
                if (\in_array($tag, self::BLOCK, true)) {
                    // A block element met inside an inline run (malformed markup): keep its text on its own line.
                    return "\n" . trim(implode("\n\n", $this->block($el, $tag, $depth))) . "\n";
                }

                return $this->inlineChildren($el, $depth);
        }
    }

    /** Wraps non-empty text in an emphasis marker, keeping surrounding whitespace outside of it. */
    private function wrap(string $text, string $mark): string
    {
        $core = trim($text);
        if ($core === '') {
            return $text;
        }
        $lead  = $text !== '' && ctype_space($text[0]) ? ' ' : '';
        $trail = ctype_space(substr($text, -1)) ? ' ' : '';

        return $lead . $mark . $core . $mark . $trail;
    }

    /** Collapses whitespace of a text node (non-breaking spaces included) and escapes markdown link/code characters. */
    private function text(string $text): string
    {
        $text = str_replace("\xC2\xA0", ' ', $text);

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        // Literal brackets, backslashes and backticks must not turn into links or code (e.g. "[x](javascript:...)").
        return str_replace(['\\', '[', ']', '`'], ['\\\\', '\[', '\]', '\`'], $text);
    }

    /** Absolute, markdown-safe http(s)/mailto/tel URL, or '' when the value is unusable or unsafe. */
    private function url(string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }
        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $href, $m)) {
            if (!\in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true)) {
                return '';
            }
            $abs = $href;
        } elseif (str_starts_with($href, '//')) {
            $abs = (str_starts_with($this->root, 'https') ? 'https:' : 'http:') . $href;
        } elseif (str_starts_with($href, '#')) {
            return $href;
        } elseif (str_starts_with($href, '/')) {
            $parts = parse_url($this->root);
            $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $abs   = $origin . $href;
        } else {
            $abs = $this->root . ltrim($href, './');
        }

        return str_replace([' ', '(', ')', '<', '>'], ['%20', '%28', '%29', '%3C', '%3E'], $abs);
    }
}
