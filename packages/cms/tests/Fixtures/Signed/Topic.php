<?php

namespace Mainstay\Tests\Fixtures\Signed;

use Mainstay\Content\Route;
use Mainstay\Content\Taxonomy;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Text;

#[Route('/topics/{slug}')]
class Topic extends Taxonomy
{
    #[Text(required: true)]
    public string $title;

    #[Text(required: true)]
    public string $slug;

    #[Internal]
    #[Text]
    public ?string $memo;
}
