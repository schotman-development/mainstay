<?php

namespace Mainstay\Tests;

use Mainstay\Content\Document;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/*
 | The phase 5 check for rich text, with no database: a document rendered to
 | the HTML expected of it. The fixture is the one packages/editor loads into
 | its own schema, so it holds only what both sides accept; what only the
 | renderer meets -- a node or mark it does not know, a link it will not draw
 | -- is written here.
 */
class RichTextTest extends TestCase
{
    #[Test]
    public function it_renders_every_node_and_mark(): void
    {
        $document = new Document(json_decode(file_get_contents(__DIR__.'/Fixtures/document.json'), true));

        $this->assertNull(Document::problem($document->toArray()));
        $this->assertSame(json_encode($document->toArray()), json_encode($document), 'Encoded as JSON, it is the tree.');
        $this->assertSame(
            '<h2>Declared in code</h2>'
            .'<p>A type is a <strong>class</strong>, its fields are <em>properties</em> and <code>#[Text]</code> declares one.<br>'
            .'Read <a href="/docs/fields?tab=all&amp;sort=name#text"><strong>the field</strong> reference</a>.</p>'
            .'<h3>Escaped &lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;kept&#039;</h3>'
            .'<blockquote><p>Adding a field is a commit, not a click.</p></blockquote>'
            ."<pre><code>#[Text(required: true)]\npublic string \$title;</code></pre>"
            .'<ul><li><p>Text</p><ul><li><p>Textarea</p></li></ul></li><li><p>Rich text</p></li></ul>'
            .'<ol start="3"><li><p>Sync</p></li></ol>'
            .'<hr>'
            .'<p><a href="mailto:hello@mainstay.test">Mail us</a></p>'
            .'<p></p>',
            $document->toHtml(),
        );
    }

    #[Test]
    public function what_it_cannot_trust_renders_as_nothing(): void
    {
        $paragraph = fn (array ...$content) => ['type' => 'paragraph', 'content' => $content];
        $text = fn (string $text, array ...$marks) => ['type' => 'text', 'text' => $text, ...($marks === [] ? [] : ['marks' => $marks])];

        $document = new Document(['type' => 'doc', 'content' => [
            ['type' => 'image', 'attrs' => ['src' => 'x.png']],
            ['type' => 'figure', 'content' => [$paragraph($text('A caption'))]],
            $paragraph($text('underlined', ['type' => 'underline']), $text(' and '), $text('scripted', ['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']])),
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [$text('A second title')]],
            ['type' => 'heading', 'attrs' => ['level' => '2 onclick=alert(1)'], 'content' => [$text('Injected')]],
            ['type' => 'ordered_list', 'attrs' => ['order' => '1" onclick="x'], 'content' => []],
            ['type' => 'paragraph', 'content' => 'not a list'],
            ['type' => 'code_block', 'content' => [$text('bold code', ['type' => 'strong'])]],
            'not a node',
        ]]);

        $this->assertSame('<p>underlined and scripted</p><pre><code>bold code</code></pre>', $document->toHtml());

        /* Marks in any order, nested as ProseMirror sorts them. */
        $this->assertNull(Document::problem(['type' => 'doc', 'content' => [$paragraph($text('bold', ['type' => 'strong'], ['type' => 'link', 'attrs' => ['href' => '/x']]))]]));
        $this->assertSame(
            '<p><a href="/x"><strong>bold</strong> plain</a></p>',
            (new Document(['type' => 'doc', 'content' => [$paragraph($text('bold', ['type' => 'strong'], ['type' => 'link', 'attrs' => ['href' => '/x']]), $text(' plain', ['type' => 'link', 'attrs' => ['href' => '/x']]))]]))->toHtml(),
        );
        $this->assertSame('', (new Document(['type' => 'paragraph']))->toHtml(), 'A tree whose root is not a doc is not a document.');
    }

    #[Test]
    #[DataProvider('links')]
    public function a_link_leads_to_an_address_or_a_path(string $href, bool $linkable): void
    {
        $this->assertSame($linkable, Document::linkable($href));
    }

    public static function links(): array
    {
        return [
            ['https://mainstay.test/docs', true],
            ['HTTP://mainstay.test', true],
            ['mailto:hello@mainstay.test?subject=Hi there', true],
            ['tel:+31 20 123 4567', true],
            ['/docs/fields', true],
            ['fields#text', true],
            ['#top', true],
            ['?page=2', true],
            ['/search?q=a:b', true],
            ['?q=a:b', true],
            ['#step:2', true],
            ['//cdn.mainstay.test/logo.svg', true],
            ['javascript:alert(1)', false],
            ['JavaScript:alert(1)', false],
            ["java\tscript:alert(1)", false],
            [' javascript:alert(1)', false],
            ["\x01javascript:alert(1)", false],
            ['data:text/html,<script>alert(1)</script>', false],
            ['vbscript:msgbox', false],
            ["/docs\n", false],
            ['', false],
        ];
    }

    #[Test]
    #[DataProvider('problems')]
    public function a_document_outside_the_schema_is_named_where_it_goes_wrong(array $content, string $problem): void
    {
        $this->assertSame($problem, Document::problem(['type' => 'doc', 'content' => $content]));
    }

    public static function problems(): array
    {
        $text = ['type' => 'text', 'text' => 'x'];
        $paragraph = ['type' => 'paragraph', 'content' => [$text]];

        return [
            'an unknown node' => [[['type' => 'image']], 'has a node at content.0 of a type it does not know: image'],
            'a node with no type' => [[$paragraph, ['content' => []]], 'has a node at content.1 with no type'],
            'text straight in the doc' => [[$text], 'puts the text at content.0 in a doc, which cannot hold one'],
            'a list holding a paragraph' => [[['type' => 'bullet_list', 'content' => [$paragraph]]], 'puts the paragraph at content.0.content.0 in a bullet_list, which cannot hold one'],
            'an empty list' => [[['type' => 'bullet_list', 'content' => []]], 'leaves the bullet_list at content.0 empty'],
            'a list item starting with a list' => [[['type' => 'bullet_list', 'content' => [['type' => 'list_item', 'content' => [['type' => 'bullet_list', 'content' => [['type' => 'list_item', 'content' => [$paragraph]]]]]]]]], 'does not start the list_item at content.0.content.0 with a paragraph'],
            'a heading of the first level' => [[['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [$text]]], 'gives the heading at content.0 a level other than 2, 3 or 4'],
            'an attribute a node does not take' => [[['type' => 'paragraph', 'attrs' => ['align' => 'left']]], 'gives the paragraph at content.0 an attribute it does not take: align'],
            'a list numbered in words' => [[['type' => 'ordered_list', 'attrs' => ['order' => 'three'], 'content' => [['type' => 'list_item', 'content' => [$paragraph]]]]], 'gives the ordered_list at content.0 an order that is not a whole number'],
            'an empty text node' => [[['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]]], 'has a text node at content.0.content.0 with no text'],
            'content on a leaf' => [[['type' => 'horizontal_rule', 'content' => [$text]]], 'gives the horizontal_rule at content.0 content it cannot hold'],
            'content that is not a list' => [[['type' => 'paragraph', 'content' => ['type' => 'text']]], 'gives the paragraph at content.0 content that is not a list'],
            'an unknown mark' => [[['type' => 'paragraph', 'content' => [[...$text, 'marks' => [['type' => 'underline']]]]]], 'marks the text at content.0.content.0 with a mark it does not know: underline'],
            'a mark in a code block' => [[['type' => 'code_block', 'content' => [[...$text, 'marks' => [['type' => 'strong']]]]]], 'marks the text at content.0.content.0, where a code_block allows no marks'],
            'a mark on a block' => [[[...$paragraph, 'marks' => [['type' => 'strong']]]], 'marks the paragraph at content.0, where a doc allows no marks'],
            'a mark twice' => [[['type' => 'paragraph', 'content' => [[...$text, 'marks' => [['type' => 'em'], ['type' => 'strong'], ['type' => 'em']]]]]], 'marks the text at content.0.content.0 em twice'],
            'a script link' => [[['type' => 'paragraph', 'content' => [[...$text, 'marks' => [['type' => 'link', 'attrs' => ['href' => "java\tscript:alert(1)"]]]]]]], 'links the text at content.0.content.0 to something other than a web, mail or phone address or a path'],
            'a link with no href' => [[['type' => 'paragraph', 'content' => [[...$text, 'marks' => [['type' => 'link']]]]]], 'links the text at content.0.content.0 to something other than a web, mail or phone address or a path'],
            'an attribute a mark does not take' => [[['type' => 'paragraph', 'content' => [[...$text, 'marks' => [['type' => 'strong', 'attrs' => ['weight' => 700]]]]]]], 'gives the strong mark on the text at content.0.content.0 attributes it does not take'],
            'an empty document' => [[], 'leaves the doc at the root empty'],
        ];
    }
}
