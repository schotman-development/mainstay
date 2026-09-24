<?php

namespace Mainstay\Fields;

use Attribute;

/*
 | A field a reader without the capability never sees: an editor's note, a
 | cost price. The decision log calls it `#[Private]`, which cannot be written
 | -- `private` is a reserved word, the same reason `Global` is GlobalSet.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Internal {}
