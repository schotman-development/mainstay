<?php

namespace Mainstay\Fields;

use Attribute;
use InvalidArgumentException;
use Mainstay\Content\Media;
use Mainstay\Media\Size;
use ReflectionProperty;

/*
 | An image from the media library, stored as its plain id -- in a column of
 | its own, or inside a block's JSON the same way -- with no foreign key, and
 | read back as a Media with the sizes declared here:
 |
 |     #[Image(sizes: ['cover' => [1600, 900], 'card' => [640]])]
 |     public ?Media $cover;
 |
 | Resolved as a relation is, defensively: an image trashed or gone reads as
 | a Media marked missing that keeps its id, and writing it back writes the
 | id. Null is no image chosen, so the property is nullable, and
 | `required: true` is what makes a write need one.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Image extends Field
{
    /** @var array<string, Size> */
    public readonly array $sizes;

    public function __construct(
        ?bool $required = null,
        bool $localized = false,
        ?string $label = null,
        array $sizes = [],
    ) {
        $declared = [];

        foreach ($sizes as $name => $size) {
            $declared[$name] = is_string($name) ? Size::declared($name, $size) : null;
        }

        /* Nothing could print an image with no size to print it at. */
        if ($declared === [] || in_array(null, $declared, true)) {
            throw new InvalidArgumentException("An image field names the sizes it is printed at: #[Image(sizes: ['card' => [640, 360]])].");
        }

        $this->sizes = $declared;

        parent::__construct($required, $localized, $label);
    }

    public function bind(ReflectionProperty $property): static
    {
        $this->stores($property, [Media::class], 'an image field stores a Media');

        if (! ($property->getType()?->allowsNull() ?? true)) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s is not nullable, and an image field holds null for no image chosen. Declare it ?%s; required: true still asks every write for one.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                class_basename(Media::class),
            ));
        }

        return parent::bind($property);
    }

    public function column(): array
    {
        return ['unsignedBigInteger'];
    }

    /* Whether the image is in the library is asked of what a save is given,
       once these pass -- see ContentStore::validate() -- and never of what a
       row already holds, which is the field's to keep while it is away. */
    public function rules(): array
    {
        return [...parent::rules(), 'bail', 'integer', 'min:1', self::ID];
    }

    /* A Media a read handed out, given back as the id it stands for. */
    public function complete(mixed $value, bool $stored = false): mixed
    {
        return $value instanceof Media ? $value->id : $value;
    }

    public function references(mixed $value, string $at): array
    {
        $id = filter_var($value instanceof Media ? $value->id : $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? [] : [$at => [Media::class, $id]];
    }

    /* The image the read loaded, or one marked missing that keeps the id.
       Anything but an id was written around the layer, and is named as a
       column that does not parse is. */
    public function cast(mixed $value, array $loaded = []): mixed
    {
        if ($this->blank($value)) {
            return null;
        }

        if (($reference = $this->references($value, $this->name)) === []) {
            throw new InvalidArgumentException("{$this->name} holds something that is not an image's id.");
        }

        $id = reset($reference)[1];

        return ($loaded[Media::class][$id] ?? new Media($id))->sized($this->sizes);
    }

    protected function to(mixed $value): mixed
    {
        return $value instanceof Media ? $value->id : (int) $value;
    }

    /* The id, as it is stored. What a reader of the API is handed in its
       place is phase 11's to decide. */
    protected function json(): array
    {
        return ['type' => 'integer'];
    }
}
