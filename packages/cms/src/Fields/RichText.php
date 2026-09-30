<?php

namespace Mainstay\Fields;

use Attribute;
use Closure;
use Mainstay\Content\Document;
use ReflectionProperty;

/*
 | Prose, stored as a ProseMirror document in a json column of its own and read
 | back as a Document. Written as the nested array ProseMirror's toJSON() gives,
 | or as a Document a read handed out.
 |
 | No empty value, as a date has none: the empty document is a paragraph
 | holding nothing, and a field storing one when it was given nothing would
 | print an empty paragraph on every page that left it blank. So an optional
 | body is declared nullable.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class RichText extends Field
{
    public function bind(ReflectionProperty $property): static
    {
        $this->stores($property, [Document::class], 'a rich text field stores a Document');

        return parent::bind($property);
    }

    public function column(): array
    {
        return ['json'];
    }

    public function rules(): array
    {
        return [...parent::rules(), 'array', function (string $attribute, mixed $value, Closure $fail) {
            if (is_array($value) && ($problem = Document::problem($value)) !== null) {
                $fail("The :attribute field {$problem}.");
            }
        }];
    }

    /* Anything but an array is a row this cannot read, and it reads as a
       document that renders nothing. */
    protected function from(mixed $value): mixed
    {
        return $value instanceof Document ? $value : new Document(is_array($value) ? $value : []);
    }

    protected function to(mixed $value): mixed
    {
        return $value instanceof Document ? $value->toArray() : $value;
    }

    /* The root only. The recursive node schema is served with the rest in
       phase 11, from the discovery endpoint, rather than inlined per field. */
    protected function json(): array
    {
        return [
            'type' => 'object',
            'properties' => ['type' => ['const' => 'doc'], 'content' => ['type' => 'array']],
            'required' => ['type', 'content'],
        ];
    }
}
