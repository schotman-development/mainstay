<?php

namespace Mainstay\Tests\Fixtures;

use Attribute;
use Mainstay\Fields\Field;

/* A field type as a host writes one: a subclass, no registration call, and a
   component in a view namespace the host can actually put a file in. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ColorPicker extends Field
{
    protected string $viewNamespace = 'acme';

    public function column(): ?array
    {
        return ['string', 7];
    }

    protected function json(): array
    {
        return ['type' => 'string'];
    }
}
