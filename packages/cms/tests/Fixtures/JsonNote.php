<?php

namespace Mainstay\Tests\Fixtures;

use Attribute;
use Mainstay\Fields\Field;

/* A host field type kept in the type's JSON column, which the contract
   allows by answering column() with null. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class JsonNote extends Field
{
    public function column(): ?array
    {
        return null;
    }

    protected function json(): array
    {
        return ['type' => 'object'];
    }
}
