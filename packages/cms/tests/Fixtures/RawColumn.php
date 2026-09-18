<?php

namespace Mainstay\Tests\Fixtures;

use Attribute;
use Mainstay\Fields\Field;

/* A host field type that asks for whatever column it is handed, and has no
   empty value and no backfill() of its own. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class RawColumn extends Field
{
    public function __construct(public readonly array $definition = ['string'])
    {
        parent::__construct();
    }

    public function column(): ?array
    {
        return $this->definition;
    }

    protected function json(): array
    {
        return [];
    }
}
