<?php

namespace Mainstay\Content;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use JsonSerializable;

/*
 | A rich text field's value: a ProseMirror document, which is a node tree and
 | therefore data, and the HTML it renders to. Htmlable, so a template prints
 | it with `{{ $entry->body }}` and Blade does not escape the markup it made.
 | JsonSerializable, so `@json`, a JSON response or a page's props carry the
 | tree rather than the `{}` its private property would encode as.
 |
 | The node schema is closed, and it is the one packages/editor/src/schema.ts
 | declares -- the same names, the same nesting, the same attributes -- so a
 | document a seeder writes is one the editor can open. A test holds the two
 | to one fixture.
 |
 | A save refuses a document outside the schema. The renderer still meets
 | one written around the layer, and draws what it cannot trust as nothing:
 | an unknown node, or one whose shape is wrong, renders as nothing, and an
 | unknown mark leaves its text unmarked.
 */
final class Document implements Arrayable, Htmlable, JsonSerializable
{
    /*
     | Each node's group, what it holds -- a group or a node -- and the
     | attributes it takes. `least` is the fewest children it may have, and
     | `first` the node a list item has to start with: ProseMirror's
     | `block+`, `list_item+` and `paragraph block*`.
     */
    private const NODES = [
        'doc' => ['content' => 'block', 'least' => 1],
        'paragraph' => ['group' => 'block', 'content' => 'inline'],
        'heading' => ['group' => 'block', 'content' => 'inline', 'attrs' => ['level']],
        'blockquote' => ['group' => 'block', 'content' => 'block', 'least' => 1],
        'code_block' => ['group' => 'block', 'content' => 'text'],
        'bullet_list' => ['group' => 'block', 'content' => 'list_item', 'least' => 1],
        'ordered_list' => ['group' => 'block', 'content' => 'list_item', 'least' => 1, 'attrs' => ['order']],
        'list_item' => ['content' => 'block', 'least' => 1, 'first' => 'paragraph'],
        'horizontal_rule' => ['group' => 'block'],
        'hard_break' => ['group' => 'inline'],
        'text' => ['group' => 'inline'],
    ];

    /* In the order ProseMirror sorts a node's marks into when it loads them,
       and so the order they nest in from the outside: a link first, so a link
       over a bold word and a plain one is one link. Only a link takes an
       attribute. */
    private const MARKS = ['link' => ['href'], 'em' => [], 'strong' => [], 'code' => []];

    /* h1 is the page's title, which is not the body's to write. */
    private const LEVELS = [2, 3, 4];

    public function __construct(private array $tree) {}

    public function toArray(): array
    {
        return $this->tree;
    }

    public function jsonSerialize(): array
    {
        return $this->tree;
    }

    public function toHtml(): string
    {
        return ($this->tree['type'] ?? null) === 'doc' ? $this->node($this->tree) : '';
    }

    /*
     | What is wrong with a tree as a document, worded to follow "The body
     | field", or null for a document the schema holds. The place is the path
     | from the root, `content.2.content.0`, since the one writing it until
     | the editor exists is a person with a nested array open.
     */
    public static function problem(array $tree): ?string
    {
        if (($tree['type'] ?? null) !== 'doc') {
            return 'is not a document: its root is not a doc node';
        }

        return self::check($tree, 'the root', null);
    }

    /*
     | Whether a link may point there: a web, mail or phone address, or a path
     | on this site. Asked of the text before a colon rather than of
     | parse_url(), which finds no scheme in "java\tscript:" -- a string a
     | browser reads as javascript: once it has dropped the tab.
     */
    public static function linkable(mixed $href): bool
    {
        return is_string($href)
            && $href !== ''
            && ! preg_match('/[\x00-\x1F\x7F]/', $href)
            && (preg_match('/\A(?:https?|mailto|tel):/i', $href) === 1 || preg_match('#\A[^/?\#]*:#', $href) !== 1);
    }

    private static function check(mixed $node, string $at, ?string $parent): ?string
    {
        if (! is_array($node) || ! is_string($type = $node['type'] ?? null)) {
            return "has a node at {$at} with no type";
        }

        if (($spec = self::NODES[$type] ?? null) === null) {
            return "has a node at {$at} of a type it does not know: {$type}";
        }

        $attrs = $node['attrs'] ?? [];

        if (! is_array($attrs)) {
            return "gives the {$type} at {$at} attrs that are not a map";
        }

        foreach ($attrs as $name => $value) {
            $problem = match (true) {
                ! in_array($name, $spec['attrs'] ?? [], true) => "gives the {$type} at {$at} an attribute it does not take: {$name}",
                $name === 'level' && ! in_array($value, self::LEVELS, true) => "gives the heading at {$at} a level other than 2, 3 or 4",
                $name === 'order' && ! is_int($value) => "gives the ordered_list at {$at} an order that is not a whole number",
                default => null,
            };

            if ($problem !== null) {
                return $problem;
            }
        }

        if ($type === 'text' && (! is_string($node['text'] ?? null) || $node['text'] === '')) {
            return "has a text node at {$at} with no text";
        }

        if (($problem = self::marks($node['marks'] ?? [], $type, $at, $parent)) !== null) {
            return $problem;
        }

        $content = $node['content'] ?? [];

        if (! is_array($content) || ! array_is_list($content)) {
            return "gives the {$type} at {$at} content that is not a list";
        }

        if (! isset($spec['content']) && $content !== []) {
            return "gives the {$type} at {$at} content it cannot hold";
        }

        if (count($content) < ($spec['least'] ?? 0)) {
            return "leaves the {$type} at {$at} empty";
        }

        if (isset($spec['first']) && (($content[0]['type'] ?? null) !== $spec['first'])) {
            return "does not start the {$type} at {$at} with a {$spec['first']}";
        }

        foreach ($content as $index => $child) {
            $place = $at === 'the root' ? "content.{$index}" : "{$at}.content.{$index}";
            $name = is_array($child) && is_string($child['type'] ?? null) ? $child['type'] : null;

            if ($name !== null && isset(self::NODES[$name]) && $name !== $spec['content'] && (self::NODES[$name]['group'] ?? null) !== $spec['content']) {
                return "puts the {$name} at {$place} in a {$type}, which cannot hold one";
            }

            if (($problem = self::check($child, $place, $type)) !== null) {
                return $problem;
            }
        }

        return null;
    }

    /* Only inline content is marked, and a code block's text is not. */
    private static function marks(mixed $marks, string $type, string $at, ?string $parent): ?string
    {
        if (! is_array($marks) || ! array_is_list($marks)) {
            return "gives the {$type} at {$at} marks that are not a list";
        }

        if ($marks === []) {
            return null;
        }

        if ($parent === null || (self::NODES[$parent]['content'] ?? null) !== 'inline') {
            return "marks the {$type} at {$at}, where a ".($parent ?? 'document').' allows no marks';
        }

        $seen = [];

        foreach ($marks as $mark) {
            $name = is_array($mark) ? $mark['type'] ?? null : null;
            $attrs = is_array($mark) ? $mark['attrs'] ?? [] : null;

            if (! is_string($name) || ! isset(self::MARKS[$name])) {
                return "marks the {$type} at {$at} with a mark it does not know: ".(is_string($name) ? $name : 'one with no type');
            }

            if (! is_array($attrs) || array_diff(array_keys($attrs), self::MARKS[$name]) !== []) {
                return "gives the {$name} mark on the {$type} at {$at} attributes it does not take";
            }

            if ($name === 'link' && ! self::linkable($attrs['href'] ?? null)) {
                return "links the {$type} at {$at} to something other than a web, mail or phone address or a path";
            }

            /* ProseMirror sorts marks as it loads them, and refuses one
               written twice. */
            if (isset($seen[$name])) {
                return "marks the {$type} at {$at} {$name} twice";
            }

            $seen[$name] = true;
        }

        return null;
    }

    private function node(mixed $node): string
    {
        if (! is_array($node) || ! is_array($attrs = $node['attrs'] ?? []) || ! is_array($content = $node['content'] ?? []) || ! array_is_list($content)) {
            return '';
        }

        $inner = fn () => $this->children($content);

        return match ($node['type'] ?? null) {
            'doc' => $inner(),
            'paragraph' => '<p>'.$inner().'</p>',
            'heading' => in_array($level = $attrs['level'] ?? 2, self::LEVELS, true) ? "<h{$level}>".$inner()."</h{$level}>" : '',
            'blockquote' => '<blockquote>'.$inner().'</blockquote>',
            /* Its text alone: a code block's text is not marked. */
            'code_block' => '<pre><code>'.implode(array_map(fn (mixed $text) => $this->text($text), $content)).'</code></pre>',
            'bullet_list' => '<ul>'.$inner().'</ul>',
            'ordered_list' => is_int($order = $attrs['order'] ?? 1) ? ($order === 1 ? '<ol>' : "<ol start=\"{$order}\">").$inner().'</ol>' : '',
            'list_item' => '<li>'.$inner().'</li>',
            'horizontal_rule' => '<hr>',
            'hard_break' => '<br>',
            'text' => $this->text($node),
            default => '',
        };
    }

    private function text(mixed $node): string
    {
        return is_array($node) && ($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null) ? e($node['text']) : '';
    }

    /*
     | Siblings, with the marks they share opened once, the way ProseMirror
     | draws them: a link over a bold word and a plain one is one link, and
     | not two a screen reader announces separately.
     */
    private function children(array $content): string
    {
        $html = '';
        $open = [];

        foreach ($content as $node) {
            $marks = is_array($node) ? $this->tags($node['marks'] ?? []) : [];
            $kept = 0;

            while ($kept < count($open) && $kept < count($marks) && $open[$kept][0] === $marks[$kept][0]) {
                $kept++;
            }

            while (count($open) > $kept) {
                $html .= array_pop($open)[2];
            }

            foreach (array_slice($marks, $kept) as $mark) {
                $html .= $mark[1];
                $open[] = $mark;
            }

            $html .= $this->node($node);
        }

        while ($open !== []) {
            $html .= array_pop($open)[2];
        }

        return $html;
    }

    /*
     | Each mark the renderer knows, as what tells it apart from the next
     | node's, its opening tag and its closing one, outermost first. A link
     | whose href could not have been saved is left out with the marks nobody
     | knows.
     |
     | @return list<array{0: string, 1: string, 2: string}>
     */
    private function tags(mixed $marks): array
    {
        $tags = [];
        $marks = is_array($marks) ? $marks : [];
        $rank = array_flip(array_keys(self::MARKS));

        usort($marks, fn (mixed $a, mixed $b) => ($rank[is_array($a) ? $a['type'] ?? '' : ''] ?? 0) <=> ($rank[is_array($b) ? $b['type'] ?? '' : ''] ?? 0));

        foreach ($marks as $mark) {
            $href = is_array($mark) && is_array($mark['attrs'] ?? null) ? $mark['attrs']['href'] ?? null : null;

            $tag = match (is_array($mark) ? $mark['type'] ?? null : null) {
                'strong' => ['strong', '<strong>', '</strong>'],
                'em' => ['em', '<em>', '</em>'],
                'code' => ['code', '<code>', '</code>'],
                'link' => self::linkable($href) ? ["link {$href}", '<a href="'.e($href).'">', '</a>'] : null,
                default => null,
            };

            if ($tag !== null) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
