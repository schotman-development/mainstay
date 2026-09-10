<?php

namespace Mainstay\Tests;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Mainstay\Fields\Boolean;
use Mainstay\Fields\Date;
use Mainstay\Fields\Field;
use Mainstay\Fields\Number;
use Mainstay\Fields\Select;
use Mainstay\Fields\Text;
use Mainstay\Fields\Textarea;
use Mainstay\Mainstay;
use Mainstay\Tests\Fixtures\Article;
use Mainstay\Tests\Fixtures\Blanks;
use Mainstay\Tests\Fixtures\ColorPicker;
use Mainstay\Tests\Fixtures\NewsItem;
use Mainstay\Tests\Fixtures\SiteSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/*
 | No database and no application: reflection is the whole of phase 1, so the
 | check is a declared type turned into the list every later phase reads.
 */
class DeclarationTest extends TestCase
{
    private Mainstay $mainstay;

    protected function setUp(): void
    {
        $this->mainstay = new Mainstay;
    }

    #[Test]
    public function it_reflects_a_content_type_into_a_field_list(): void
    {
        $fields = $this->mainstay->fields(Article::class);

        $this->assertSame(
            ['title', 'summary', 'readingMinutes', 'featured', 'publishedAt', 'status'],
            array_keys($fields),
            'The list is the declaration, in order, and a public property with no attribute is not a field.',
        );

        $this->assertInstanceOf(Text::class, $fields['title']);
        $this->assertInstanceOf(Textarea::class, $fields['summary']);
        $this->assertInstanceOf(Number::class, $fields['readingMinutes']);
        $this->assertInstanceOf(Boolean::class, $fields['featured']);
        $this->assertInstanceOf(Date::class, $fields['publishedAt']);
        $this->assertInstanceOf(Select::class, $fields['status']);
    }

    #[Test]
    public function it_binds_each_field_to_the_property_it_sits_on(): void
    {
        $fields = $this->mainstay->fields(Article::class);

        $this->assertSame('summary', $fields['summary']->name);
        $this->assertSame('string', $fields['summary']->phpType);
        $this->assertTrue($fields['summary']->nullable);
        $this->assertTrue($fields['summary']->localized);

        $this->assertSame('int', $fields['readingMinutes']->phpType);
        $this->assertFalse($fields['readingMinutes']->nullable);
        $this->assertFalse($fields['readingMinutes']->localized);

        $this->assertSame(CarbonImmutable::class, $fields['publishedAt']->phpType);
    }

    #[Test]
    public function it_plans_a_column_for_every_scalar_field(): void
    {
        $columns = array_map(fn (Field $field) => $field->column(), $this->mainstay->fields(Article::class));

        $this->assertSame([
            'title' => ['string', 120],
            'summary' => ['text'],
            'readingMinutes' => ['integer'],
            'featured' => ['boolean'],
            'publishedAt' => ['dateTime'],
            'status' => ['string', 255],
        ], $columns);
    }

    #[Test]
    public function it_derives_the_json_schema_from_the_declaration(): void
    {
        $this->assertSame([
            'type' => 'object',
            'title' => 'Article',
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 120],
                'summary' => ['type' => ['string', 'null']],
                'readingMinutes' => ['type' => 'integer', 'minimum' => 1],
                'featured' => ['type' => 'boolean'],
                'publishedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'review', 'live']],
            ],
            'required' => ['title', 'summary', 'readingMinutes', 'featured', 'publishedAt', 'status'],
            'additionalProperties' => false,
        ], $this->mainstay->schema(Article::class));
    }

    #[Test]
    public function a_nullable_select_admits_null_in_its_enum_as_well_as_its_type(): void
    {
        $this->assertSame(
            ['type' => ['string', 'null'], 'enum' => ['wire', 'staff', null]],
            $this->mainstay->fields(NewsItem::class)['source']->schema(),
        );
    }

    #[Test]
    public function it_lists_inherited_and_trait_fields_before_the_ones_a_type_adds(): void
    {
        $this->assertSame(
            ['title', 'seoDescription', 'source'],
            array_keys($this->mainstay->fields(NewsItem::class)),
            'A base class and a trait declare first, so their fields read first, whatever order reflection answers in.',
        );
    }

    #[Test]
    public function it_refuses_a_field_attribute_it_would_otherwise_drop_in_silence(): void
    {
        $this->expectExceptionMessage('is not public');

        $this->mainstay->fields(Fixtures\Broken\Hidden::class);
    }

    #[Test]
    public function it_refuses_two_field_attributes_on_one_property(): void
    {
        $this->expectExceptionMessage('more than one field attribute');

        $this->mainstay->fields(Fixtures\Broken\Doubled::class);
    }

    #[Test]
    public function it_refuses_a_select_with_no_options(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Select;
    }

    #[Test]
    public function an_option_containing_a_comma_stays_one_option(): void
    {
        $this->assertSame(
            ['nullable', 'in:"a,b","c"'],
            (new Select(['a,b', 'c']))->rules(),
        );
    }

    #[Test]
    public function an_empty_box_is_absent_where_the_field_can_hold_nothing(): void
    {
        $fields = $this->mainstay->fields(Blanks::class);

        /*
         | Not just *today* for a date: 0 for an optional number and false for
         | an optional boolean are the same silent value standing in for
         | nothing. Where the property is not nullable the empty value is what
         | it holds instead, since null is not a string and hydration would
         | refuse it -- except a date and a select, neither of which has an
         | empty value to hold. '' is not one of a select's options.
         */
        $expected = [
            'keptText' => '',
            'absentText' => null,
            'keptProse' => '',
            'absentProse' => null,
            'keptNumber' => 0,
            'absentNumber' => null,
            'keptFlag' => false,
            'absentFlag' => null,
            'keptChoice' => null,
            'absentChoice' => null,
            'neverToday' => null,
            'absentDate' => null,
        ];

        $this->assertSame($expected, array_map(fn (Field $field) => $field->cast(''), $fields));
        $this->assertSame($expected, array_map(fn (Field $field) => $field->serialize(''), $fields));
    }

    #[Test]
    public function a_date_of_whitespace_is_absent_rather_than_today(): void
    {
        $fields = $this->mainstay->fields(Blanks::class);

        $this->assertNull($fields['neverToday']->cast('  '));
        $this->assertNull($fields['neverToday']->serialize('  '));
        $this->assertNull($fields['absentDate']->cast("\n"));
    }

    #[Test]
    public function it_writes_out_the_type_the_column_holds(): void
    {
        $fields = $this->mainstay->fields(Article::class);

        /* Serialize is not identity: a driver is no readier to take '7' for an
           integer column than it was to hand one back. */
        $this->assertSame(7, $fields['readingMinutes']->serialize('7'));
        $this->assertSame('123', $fields['status']->serialize(123));
        $this->assertTrue($fields['featured']->serialize('1'));
    }

    #[Test]
    public function it_derives_validation_rules_from_the_declaration(): void
    {
        $rules = array_map(fn (Field $field) => $field->rules(), $this->mainstay->fields(Article::class));

        $this->assertSame([
            'title' => ['required', 'string', 'max:120'],
            'summary' => ['nullable', 'string'],
            'readingMinutes' => ['required', 'integer', 'min:1'],
            'featured' => ['required', 'boolean'],
            'publishedAt' => ['nullable', 'date'],
            'status' => ['required', 'in:"draft","review","live"'],
        ], $rules);
    }

    #[Test]
    public function it_casts_in_both_directions(): void
    {
        $fields = $this->mainstay->fields(Article::class);

        $date = $fields['publishedAt']->cast('2026-09-10 08:30:00');
        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame('2026-09-10 08:30:00', $fields['publishedAt']->serialize($date));
        $this->assertSame('2026-09-10', (new Date)->serialize($date));

        $this->assertFalse($fields['featured']->cast('0'));
        $this->assertTrue($fields['featured']->serialize(true));
        $this->assertFalse($fields['featured']->serialize('0'));

        $this->assertSame(7, $fields['readingMinutes']->cast('7'));

        $this->assertSame('draft', $fields['status']->cast('draft'));

        foreach ($fields as $field) {
            $this->assertNull($field->cast(null));
            $this->assertNull($field->serialize(null));
        }
    }

    #[Test]
    public function a_field_names_its_component_and_its_label(): void
    {
        $fields = $this->mainstay->fields(Article::class);

        $this->assertSame('mainstay::fields.textarea', $fields['summary']->component());
        $this->assertSame('Reading Minutes', $fields['readingMinutes']->label());

        /* A host cannot add views to `mainstay::`, so a field type it writes
           itself would have no component it could ever supply. */
        $this->assertSame('acme::fields.color-picker', (new ColorPicker)->component());
    }

    #[Test]
    public function it_registers_types_by_handle(): void
    {
        $this->mainstay->types([Article::class, SiteSettings::class]);
        $this->mainstay->types([Article::class]);

        $this->assertSame([
            'article' => Article::class,
            'site_settings' => SiteSettings::class,
        ], $this->mainstay->registered());
    }

    #[Test]
    public function it_refuses_a_class_that_is_not_a_content_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->mainstay->types([Text::class]);
    }

    #[Test]
    public function it_refuses_two_types_claiming_one_handle(): void
    {
        $this->mainstay->types([Article::class]);

        $this->expectExceptionMessage('Two content types are called "article"');

        $this->mainstay->types([Fixtures\Other\Article::class]);
    }
}
