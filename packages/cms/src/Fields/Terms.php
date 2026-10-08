<?php

namespace Mainstay\Fields;

use Attribute;
use InvalidArgumentException;
use Mainstay\Content\Taxonomy;
use ReflectionClass;
use ReflectionProperty;

/*
 | An entry's terms from one taxonomy, in the order given:
 | `#[Terms(of: Tag::class)] public array $tags`. A list relation in every
 | way but where it is kept: in rows of the taxonomy's pivot, `tag_entries`,
 | rather than a column, so a read can ask which entries hold a term -- the
 | reverse query a term's page is for -- with `where: ['tags' => $tag->id]`.
 |
 | The pivot has no locale, so an entry has the same terms in every language,
 | and each term is translated itself. Entry-level only: the reverse query
 | could not see one inside a block, and a global is no entry to list.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Terms extends Relation
{
    /** @var class-string<Taxonomy> */
    public readonly string $of;

    /** @param class-string<Taxonomy> $of */
    public function __construct(
        ?bool $required = null,
        bool $localized = false,
        ?string $label = null,
        string $of = '',
    ) {
        if (! is_subclass_of($of, Taxonomy::class) || (new ReflectionClass($of))->isAbstract()) {
            throw new InvalidArgumentException(sprintf('A terms field names the taxonomy its terms are from: #[Terms(of: Tag::class)]. %s is not one.', $of === '' ? 'Nothing' : $of));
        }

        if ($localized) {
            throw new InvalidArgumentException("A terms field is not localized: an entry has the same terms in every language, and each term is translated itself. {$of}'s are kept in a pivot with no locale.");
        }

        $this->of = (new ReflectionClass($of))->getName();

        parent::__construct($required, $localized, $label, $this->of);
    }

    public function bind(ReflectionProperty $property): static
    {
        $this->stores($property, ['array'], 'a terms field holds a list of terms');

        return parent::bind($property);
    }

    /*
     | What a write takes from the tag input's chips: `id:5` for a term
     | picked, `new:` and its title for one typed, which the admin creates
     | before it saves -- so a tag called "2026" is not read as an id. The
     | empty value a list posts ahead of its chips is dropped, as a
     | relation's is.
     */
    public function fromForm(mixed $posted): mixed
    {
        $posted = parent::fromForm($posted);

        return is_array($posted) ? array_map(fn (mixed $chip) => match (true) {
            ! is_string($chip) => $chip,
            str_starts_with($chip, 'id:') => substr($chip, 3),
            str_starts_with($chip, 'new:') => ['new' => substr($chip, 4)],
            default => $chip,
        }, $posted) : $posted;
    }

    /* Kept in the pivot, which the layer reads and writes itself. */
    public function column(): ?array
    {
        return null;
    }
}
