<?php

namespace Mainstay\Tests;

use Carbon\CarbonImmutable;
use DateTime;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use InvalidArgumentException;
use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Fields\Boolean;
use Mainstay\Fields\Date;
use Mainstay\Fields\Field;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Number;
use Mainstay\Fields\Select;
use Mainstay\Fields\Text;
use Mainstay\Fields\Textarea;
use Mainstay\Mainstay;
use Mainstay\Tests\Fixtures\Accented\Article as AccentedArticle;
use Mainstay\Tests\Fixtures\Article;
use Mainstay\Tests\Fixtures\Blanks;
use Mainstay\Tests\Fixtures\ColorPicker;
use Mainstay\Tests\Fixtures\NewsItem;
use Mainstay\Tests\Fixtures\Revised\Article as RevisedArticle;
use Mainstay\Tests\Fixtures\SiteSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

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
    public function a_child_reopens_a_property_its_base_declared_protected(): void
    {
        /* PHP allows the widening, and every guard here read the base's own
           declaration first -- so the declaration that fixes the base's was
           refused for the mistake it fixes. `heading` leaves the attribute to
           the base and widens nothing else, which is the same property seen
           twice rather than a second field. */
        $fields = $this->mainstay->fields(Fixtures\Note::class);

        $this->assertSame(['body', 'heading'], array_keys($fields));
        $this->assertInstanceOf(Textarea::class, $fields['body']);
        $this->assertInstanceOf(Text::class, $fields['heading']);
    }

    #[Test]
    public function an_internal_field_stays_internal_when_a_child_widens_it(): void
    {
        $fields = $this->mainstay->fields(Fixtures\Memo::class);

        $this->assertTrue($fields['note']->internal, 'The flag follows the field attribute to the base, as the rest of the field does.');
        $this->assertTrue($fields['source']->internal);
        $this->assertFalse($fields['title']->internal);
    }

    #[Test]
    public function a_child_widening_a_base_field_can_mark_it_internal(): void
    {
        $fields = $this->mainstay->fields(Fixtures\Restated::class);

        $this->assertTrue($fields['body']->internal);
        $this->assertFalse($fields['heading']->internal);
    }

    #[Test]
    public function it_refuses_internal_on_a_property_that_is_not_a_field(): void
    {
        $this->expectExceptionMessage('carries no field attribute, so there is no field for it to hide');

        $this->mainstay->fields(Fixtures\Broken\Unfielded::class);
    }

    #[Test]
    public function it_reads_a_route_as_declared(): void
    {
        $this->assertSame(['en' => '/blog/{slug}', 'nl' => '/nieuws/{slug}'], $this->mainstay->route(Fixtures\Post::class));
        $this->assertSame('/', $this->mainstay->route((new #[Route('/')] class extends Entry {})::class), 'The root is a path, which is how a home page is made.');
        $this->assertNull($this->mainstay->route(Article::class));
    }

    #[Test]
    public function it_refuses_a_route_no_path_can_be_built_from(): void
    {
        $refusals = [
            'is not a path Mainstay can store' => [
                new #[Route('/Blog/{slug}')] class extends Entry
                {
                    #[Text]
                    public string $slug;
                },
                new #[Route('/blog/')] class extends Entry {},
                new #[Route('blog/{slug}')] class extends Entry
                {
                    #[Text]
                    public string $slug;
                },
                new #[Route('/blog/{slug}-x')] class extends Entry
                {
                    #[Text]
                    public string $slug;
                },
            ],
            'names {nothing}, which is not a field of the type' => [new #[Route('/blog/{nothing}')] class extends Entry {}],
            'names {slug}, which is internal, and the path is published' => [
                new #[Route('/blog/{slug}')] class extends Entry
                {
                    #[Text]
                    #[Internal]
                    public string $slug;
                },
            ],
            'names {slug}, which is typed int, and a path is built from strings' => [
                new #[Route('/blog/{slug}')] class extends Entry
                {
                    #[Number]
                    public int $slug;
                },
            ],
            'names {slug}, which is optional, and a path cannot be built from nothing' => [
                new #[Route('/blog/{slug}')] class extends Entry
                {
                    #[Text]
                    public ?string $slug;
                },
            ],
            '#[Route] is a list. Give one pattern, or a pattern per locale keyed by the locale.' => [new #[Route(['/blog'])] class extends Entry {}],
        ];

        foreach ($refusals as $message => $types) {
            foreach ($types as $type) {
                try {
                    $this->mainstay->route($type::class);
                    $this->fail("A route was read that should have been refused with: {$message}");
                } catch (InvalidArgumentException $exception) {
                    $this->assertStringContainsString($message, $exception->getMessage());
                }
            }
        }
    }

    #[Test]
    public function it_refuses_a_field_on_a_private_property_a_child_shadows(): void
    {
        /* The child's property of the same name is a second slot, not the
           base's widened. Binding the field to it by name would write the
           column from a property the attribute was never on. */
        $this->expectExceptionMessage('is not public');

        $this->mainstay->fields(Fixtures\Broken\Filed::class);
    }

    #[Test]
    public function it_refuses_two_field_attributes_on_one_property(): void
    {
        $this->expectExceptionMessage('more than one field attribute');

        $this->mainstay->fields(Fixtures\Broken\Doubled::class);
    }

    #[Test]
    public function it_refuses_a_field_attribute_on_a_static_property(): void
    {
        /* A field is a value an entry holds. Skipping this one in silence was
           the same missing box the two guards above are here to prevent. */
        $this->expectExceptionMessage('is static');

        $this->mainstay->fields(Fixtures\Broken\Shared::class);
    }

    #[Test]
    public function it_names_a_class_it_cannot_reflect(): void
    {
        /* fields() and schema() take a class name a host typed, so a typo
           answers the way types() answers one rather than as a
           ReflectionException nothing downstream is catching for. */
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('App\Typo');

        $this->mainstay->fields('App\Typo');
    }

    #[Test]
    public function it_reads_presence_from_the_argument_then_the_property_type(): void
    {
        $fields = $this->mainstay->fields(Blanks::class);

        /* Silent attribute, non-nullable property: the type answers. */
        $this->assertTrue($fields['keptText']->isRequired());
        $this->assertSame(['required', 'string', 'max:255'], $fields['keptText']->rules());

        /* Silent attribute, nullable property: so is the field. */
        $this->assertFalse($fields['absentText']->isRequired());
        $this->assertSame(['nullable', 'string', 'max:255'], $fields['absentText']->rules());

        /* The argument still wins where it is stated. */
        $this->assertTrue((new Text(required: true))->bind(
            new ReflectionProperty(Blanks::class, 'absentText')
        )->isRequired());
    }

    #[Test]
    public function it_refuses_an_optional_field_its_type_cannot_hold_nothing_in(): void
    {
        $this->expectExceptionMessage('cannot hold null');

        $this->mainstay->fields(Fixtures\Broken\Contradicted::class);
    }

    #[Test]
    public function a_backed_enum_is_a_selects_options(): void
    {
        $select = (new Select(options: Fixtures\Priority::class))->bind(
            new ReflectionProperty(Blanks::class, 'absentChoice'),
        );

        /* The array form cannot say this: [0 => 'Low', 1 => 'Highly Urgent']
           is the same array as ['Low', 'Highly Urgent'], so the keys are gone
           before the attribute sees them. */
        $this->assertSame([0 => 'Low', 1 => 'Highly Urgent'], $select->options());
        $this->assertSame(['0', '1'], $select->values());
        $this->assertSame(['nullable', 'in:"0","1"'], $select->rules());
    }

    #[Test]
    public function a_required_boolean_asks_to_be_true_rather_than_present(): void
    {
        $bound = fn (Boolean $field) => $field->bind(new ReflectionProperty(Blanks::class, 'keptFlag'));

        /* `required` fails on the absent key an unchecked box posts, so the
           plain declaration has to stay satisfiable with it left alone. */
        $this->assertSame(['nullable', 'boolean'], $bound(new Boolean)->rules());
        $this->assertSame(['accepted'], $bound(new Boolean(required: true))->rules());
    }

    #[Test]
    public function a_type_with_no_fields_still_has_an_object_for_properties(): void
    {
        $schema = $this->mainstay->schema(Fixtures\Bare::class);

        /* [] encodes as a JSON array, which is not a schema. */
        $this->assertEquals(new stdClass, $schema['properties']);
        $this->assertSame('{"properties":{}}', json_encode(['properties' => $schema['properties']]));
    }

    #[Test]
    public function it_reflects_a_class_once_however_its_name_is_spelled(): void
    {
        $this->assertSame(
            $this->mainstay->fields(Article::class),
            $this->mainstay->fields('\\'.Article::class),
            'One class is one field list, or the schema and the form can disagree about it.',
        );

        $this->assertSame('Article', $this->mainstay->schema('\\'.Article::class)['title']);
    }

    #[Test]
    public function it_refuses_a_select_over_a_property_that_does_not_hold_strings(): void
    {
        $this->expectExceptionMessage('a select stores strings');

        (new Select(options: Fixtures\Priority::class))->bind(
            new ReflectionProperty(Blanks::class, 'absentNumber'),
        );
    }

    #[Test]
    public function a_union_is_not_a_string_either(): void
    {
        /* $phpType is 'mixed' for every union as well as for no type at all,
           so a guard reading it lets `int|float` through and hands it '7'. */
        $this->expectExceptionMessage('a select stores strings');

        (new Select(options: ['a', 'b']))->bind(
            new ReflectionProperty(Fixtures\Broken\Widened::class, 'choice'),
        );
    }

    #[Test]
    public function it_refuses_a_number_over_a_property_that_holds_neither(): void
    {
        /* The column plan and the property have to agree, or the declaration
           reads as if it works and raises a TypeError at hydration. */
        $this->expectExceptionMessage('a number stores an int or a float');

        $this->mainstay->fields(Fixtures\Broken\Mistyped::class);
    }

    #[Test]
    public function a_number_has_to_hold_one_in_every_arm(): void
    {
        /* Reading the declared type as a single name let every union past a
           guard written to admit `int|float`, and cast() then coerced 7.5 into
           whichever arm PHP reached for -- true for a bool, '7.5' for a
           string. */
        $this->expectExceptionMessage('a number stores an int or a float');

        (new Number)->bind(new ReflectionProperty(Fixtures\Broken\Widened::class, 'sku'));
    }

    #[Test]
    public function null_is_an_arm_of_a_union_and_not_a_type_a_field_stores(): void
    {
        /* `?int` and `int|null` both reflect as a plain `int` that allows
           null, so the name only ever arrives beside other arms. Allowed on
           its own, `public null $stock` binds and then raises the TypeError
           the guard exists to replace. */
        $this->expectExceptionMessage('a number stores an int or a float');

        (new Number)->bind(new ReflectionProperty(Fixtures\Broken\Miscast::class, 'nothing'));
    }

    #[Test]
    public function it_refuses_a_boolean_over_a_property_that_does_not_hold_one(): void
    {
        /* The only one of these that reports nothing on its own: from() hands
           back a bool and coercive assignment stores the string "1", so the
           column plan, the schema and the property all disagree in silence. */
        $this->expectExceptionMessage('a boolean stores true or false');

        (new Boolean)->bind(new ReflectionProperty(Fixtures\Broken\Miscast::class, 'flag'));
    }

    #[Test]
    public function it_refuses_a_text_field_over_a_property_that_does_not_hold_strings(): void
    {
        /* Both of them, because a textarea is not a text field's subclass and
           would otherwise carry the guard only one of them has. */
        $this->expectExceptionMessage('a text field stores strings');

        (new Text)->bind(new ReflectionProperty(Fixtures\Broken\Miscast::class, 'count'));
    }

    #[Test]
    public function a_textarea_does_not_hold_an_array_either(): void
    {
        $this->expectExceptionMessage('a textarea field stores strings');

        (new Textarea)->bind(new ReflectionProperty(Fixtures\Broken\Miscast::class, 'tags'));
    }

    #[Test]
    public function it_refuses_a_date_over_a_property_that_does_not_hold_one(): void
    {
        /* The guard Select and Number carry. from() hands back a
           CarbonImmutable whatever the property is typed for. */
        $this->expectExceptionMessage('a date hydrates into');

        $this->mainstay->fields(Fixtures\Broken\Mistimed::class);
    }

    #[Test]
    public function a_union_has_to_hold_a_date_in_every_arm(): void
    {
        /* Worse than the select's union, which at least raises a TypeError:
           Carbon has a __toString, so `string|int` takes the date as a string
           and stores it as one with nothing reporting anything. */
        $this->expectExceptionMessage('Every part of the type has to hold one');

        (new Date)->bind(new ReflectionProperty(Fixtures\Broken\Widened::class, 'whenever'));
    }

    #[Test]
    public function an_intersection_has_to_hold_a_date_in_every_arm_too(): void
    {
        /* Assignability rather than the union's strictness: a value has to
           satisfy every arm of an intersection to be stored in one. */
        $this->expectExceptionMessage('a date hydrates into');

        (new Date)->bind(new ReflectionProperty(Fixtures\Broken\Widened::class, 'neither'));
    }

    #[Test]
    public function a_union_of_intersections_is_a_declaration_and_not_a_crash(): void
    {
        /* The one shape the guard used to reach the end of without a name --
           and it did not pass through, it raised the engine TypeError the
           guard exists to replace. */
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a date hydrates into');

        (new Date)->bind(new ReflectionProperty(Fixtures\Broken\Widened::class, 'nested'));
    }

    #[Test]
    public function a_date_takes_any_property_a_carbon_can_be_assigned_to(): void
    {
        /* The question is not whether the type is a date, it is whether the
           CarbonImmutable from() returns fits in it. */
        foreach (['neverToday', 'absentDate'] as $property) {
            $this->assertInstanceOf(
                Date::class,
                (new Date)->bind(new ReflectionProperty(Blanks::class, $property)),
            );
        }

        /* Which is why these are refused: a CarbonImmutable is not a DateTime
           and is not a Carbon, so neither property could hold what cast()
           hands it. */
        foreach (['aDateTime', 'aMutableCarbon'] as $property) {
            try {
                (new Date)->bind(new ReflectionProperty(Fixtures\Broken\Widened::class, $property));
                $this->fail("{$property} cannot hold a CarbonImmutable and should have been refused.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('a date hydrates into', $e->getMessage());
            }
        }
    }

    #[Test]
    public function the_first_argument_of_every_field_type_is_presence(): void
    {
        /* Written the other way round, `#[Text(true)]` is a varchar(1) with a
           one-character limit and `#[Boolean(true)]` is a required flag, and
           the declaration does not show which of the two it is. */
        $declarations = [
            [new Text(true), 'absentText'],
            [new Textarea(true), 'absentProse'],
            [new Boolean(true), 'absentFlag'],
            [new Number(true), 'absentNumber'],
            [new Date(true), 'absentDate'],
            [new Select(true, options: ['a', 'b']), 'absentChoice'],
        ];

        foreach ($declarations as [$field, $property]) {
            $this->assertTrue(
                $field->bind(new ReflectionProperty(Blanks::class, $property))->isRequired(),
                $field::class.' reads its first argument as required.',
            );
        }

        $this->assertSame(255, (new Text(true))->max, 'The type\'s own arguments come after, so none of them is what was set.');
        $this->assertFalse((new Date(true))->time);
    }

    #[Test]
    public function an_optional_boolean_is_not_a_contradiction(): void
    {
        /* The guard reads nullability, but Boolean redefines what `required`
           asks, so `required: false` on `public bool` is the ordinary way to
           write a checkbox that defaults off rather than a declaration at odds
           with its own type. */
        $field = (new Boolean(required: false))->bind(
            new ReflectionProperty(Blanks::class, 'keptFlag'),
        );

        $this->assertFalse($field->isRequired());
        $this->assertSame(['nullable', 'boolean'], $field->rules());
    }

    #[Test]
    public function it_refuses_a_select_with_no_options(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Select;
    }

    #[Test]
    public function an_enum_with_no_cases_is_a_select_with_no_options(): void
    {
        /* It passed both constructor checks and produced the rule `in:`, which
           Laravel reads as the single option null and compares strictly
           against -- a field no value can ever satisfy. */
        $this->expectExceptionMessage('A select field needs options');

        new Select(options: Fixtures\Nothing::class);
    }

    #[Test]
    public function an_option_containing_a_comma_stays_one_option(): void
    {
        /* Bound, because presence now reads the property's nullability -- an
           unbound field has no more answer for that than for its own name. */
        $select = (new Select(options: ['a,b', 'c']))->bind(
            new ReflectionProperty(Blanks::class, 'absentChoice'),
        );

        $this->assertSame(['nullable', 'in:"a,b","c"'], $select->rules());
    }

    #[Test]
    public function it_refuses_an_option_that_reads_back_as_something_else(): void
    {
        /* `a\` quotes as `"a\"`, where the backslash escapes the quote meant
           to close it: the option runs on into the ones after it and the rule
           accepts none of them. */
        $this->expectExceptionMessage('backslash against a quote');

        new Select(options: ['a\\', 'b']);
    }

    #[Test]
    public function an_option_carrying_a_backslash_is_kept(): void
    {
        /* Only a backslash against a quote is unwritable. A class name and a
           Windows path survive the split intact, and refusing them was
           refusing options for a bug they do not have. */
        $select = (new Select(options: ['App\\Models\\Post', 'C:\\Users']))->bind(
            new ReflectionProperty(Blanks::class, 'absentChoice'),
        );

        $this->assertSame(['nullable', 'in:"App\\Models\\Post","C:\\Users"'], $select->rules());
    }

    #[Test]
    public function an_option_the_guard_keeps_is_one_the_validator_accepts(): void
    {
        /* The rule string is half the claim: it is pinned above against a
           written-out expectation, and what the guard actually reasons about
           is Laravel reading that string back. A factory rather than an
           application, since phase 1 still boots nothing. */
        $options = ['App\\Models\\Post', 'a,b', 'a"b'];

        $select = (new Select(options: $options))->bind(
            new ReflectionProperty(Blanks::class, 'absentChoice'),
        );

        $validator = new Factory(new Translator(new ArrayLoader, 'en'));

        foreach ($options as $option) {
            $this->assertTrue(
                $validator->make(['choice' => $option], ['choice' => $select->rules()])->passes(),
                "The rule refuses {$option}, which is one of the options it was built from.",
            );
        }
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
         | refuse it -- except a date and a select, neither of which has a value
         | of its own that means nothing.
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
            'absentChoice' => null,
            'absentDate' => null,
        ];

        /* Keyed off $expected rather than intersected with it, so reordering
           Blanks fails on the behaviour under test and not on the order. */
        $answers = fn (string $method) => array_map(
            fn (string $name) => $fields[$name]->{$method}(''),
            array_combine(array_keys($expected), array_keys($expected)),
        );

        $this->assertSame($expected, $answers('cast'));
        $this->assertSame($expected, $answers('serialize'));
    }

    #[Test]
    public function it_refuses_an_empty_box_the_field_has_no_value_for(): void
    {
        $fields = $this->mainstay->fields(Blanks::class);

        /* Not null, which the property would refuse at hydration, and not a
           value invented for the occasion: a select's options are a closed list
           and none of them means "none of them". */
        $this->expectExceptionMessage('has no empty value');

        $fields['keptChoice']->cast('');
    }

    #[Test]
    public function a_non_nullable_date_has_no_empty_value_either(): void
    {
        $this->expectExceptionMessage('has no empty value');

        $this->mainstay->fields(Blanks::class)['neverToday']->cast('  ');
    }

    #[Test]
    public function whitespace_is_nothing_for_every_type_not_only_a_date(): void
    {
        $fields = $this->mainstay->fields(Blanks::class);

        $this->assertNull($fields['absentDate']->cast("\n"));

        /* 0 for an optional number, and a space written into a select's
           column as though it were a choice, were the same silent stand-in for
           nothing that only Date used to guard against. */
        $this->assertNull($fields['absentNumber']->cast('  '));
        $this->assertNull($fields['absentChoice']->serialize(' '));
        $this->assertNull($fields['absentText']->cast("\t"));
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
            'featured' => ['nullable', 'boolean'],
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

        /* A null column reaching a property that cannot hold null is the same
           empty box, and gets the same answer -- not null, which is what used
           to come back for every field whatever its type. */
        $this->assertNull($fields['summary']->cast(null));
        $this->assertNull($fields['publishedAt']->cast(null));
        $this->assertSame('', $fields['title']->cast(null));
        $this->assertSame(0, $fields['readingMinutes']->cast(null));
        $this->assertFalse($fields['featured']->cast(null));
    }

    #[Test]
    public function a_timestamp_is_written_out_in_utc(): void
    {
        $fields = $this->mainstay->fields(Article::class);

        /* The column has no zone to keep an offset in, so discarding it stores
           a different instant from the one that was sent -- and two clients in
           different zones posting the same moment store two different rows. */
        $this->assertSame('2026-09-10 06:30:00', $fields['publishedAt']->serialize('2026-09-10T08:30:00+02:00'));

        /* A date has no instant to convert. Shifting it by an offset moves the
           day, which is the reason only the `time` branch normalises. */
        $this->assertSame('2026-09-10', (new Date)->serialize('2026-09-10T00:30:00+02:00'));
    }

    #[Test]
    public function a_timestamp_round_trips_whatever_zone_the_process_is_in(): void
    {
        $field = $this->mainstay->fields(Article::class)['publishedAt'];
        $zone = date_default_timezone_get();

        /*
         | Reading a naive string in the process default and writing it out in
         | UTC is a value that drifts by the offset between them every time it
         | passes through the field. It is nothing at all under Laravel's
         | default `app.timezone`, which is why only a host that changed it
         | would ever see it -- everywhere, and silently.
         */
        try {
            foreach (['UTC', 'Europe/Amsterdam', 'America/New_York'] as $timezone) {
                date_default_timezone_set($timezone);

                $this->assertSame('2026-09-10 06:30:00', $field->serialize('2026-09-10 06:30:00'), "A column value is UTC in {$timezone} too.");
                $this->assertSame('2026-09-10 06:30:00', $field->serialize($field->cast('2026-09-10 06:30:00')));
                $this->assertSame('2026-09-10 06:30:00', $field->serialize('2026-09-10T08:30:00+02:00'), "A stated offset is converted in {$timezone} too.");
            }
        } finally {
            date_default_timezone_set($zone);
        }
    }

    #[Test]
    public function an_object_is_converted_out_of_the_zone_it_carries(): void
    {
        $field = $this->mainstay->fields(Article::class)['publishedAt'];
        $zone = date_default_timezone_get();

        try {
            date_default_timezone_set('Europe/Amsterdam');

            /* A string with no zone is the column's own value, and the column
               is UTC. */
            $this->assertSame('2026-09-10 06:30:00', $field->serialize('2026-09-10 06:30:00'));

            /* An object stated a zone, so it is converted rather than read.
               Which is the only reading that stores a local instant as the
               instant it is. */
            $this->assertSame(
                '2026-09-10 04:30:00',
                $field->serialize(CarbonImmutable::parse('2026-09-10 06:30:00', 'Europe/Amsterdam')),
            );

            /*
             | And the cost of that, pinned rather than guarded: a naive string
             | wrapped in a DateTime inherits the process default and is shifted
             | by it -- the same 04:30, from a value that meant 06:30 UTC.
             |
             | Nothing distinguishes the two objects. Both are a timezone_type 3
             | named for the ambient zone, so a guard that refused this one would
             | refuse CarbonImmutable::now() with it. The rule is the caller's:
             | hand a column value over as the string it is.
             */
            $this->assertSame('2026-09-10 04:30:00', $field->serialize(new DateTime('2026-09-10 06:30:00')));
        } finally {
            date_default_timezone_set($zone);
        }
    }

    #[Test]
    public function a_boolean_reads_every_false_a_driver_spells(): void
    {
        $fields = $this->mainstay->fields(Blanks::class);

        /* A Postgres connection handing back strings reports 'f', and a plain
           cast reads it as true -- every stored false read back inverted. */
        foreach (['f', 'false', 'FALSE', '0', 'off', 'no'] as $false) {
            $this->assertFalse($fields['keptFlag']->cast($false), "{$false} is a stored false.");
            $this->assertFalse($fields['keptFlag']->serialize($false), "{$false} is written back as false.");
        }

        $this->assertTrue($fields['keptFlag']->cast('t'));
        $this->assertTrue($fields['keptFlag']->cast('1'));

        /* The byte a MySQL bit column hands back. The base blank() trims it as
           whitespace, which made this an absent value on a nullable field
           rather than the false it was stored as. */
        $this->assertFalse($fields['absentFlag']->cast("\0"));
        $this->assertNull($fields['absentFlag']->cast(''), 'An empty box is still nothing.');
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
    public function one_class_registers_once_however_its_name_is_spelled(): void
    {
        $this->mainstay->types([Article::class, '\\'.Article::class]);

        $this->assertSame(
            ['article' => Article::class],
            $this->mainstay->registered(),
            'A spelling is not a second type, and the handle it claims is not a collision.',
        );
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
    public function it_names_an_abstract_type_as_abstract(): void
    {
        /* A base class does extend Entry, so the other branch's message tells
           its author to do the thing they already did. */
        $this->expectExceptionMessage('is abstract');

        $this->mainstay->types([Fixtures\BaseArticle::class]);
    }

    #[Test]
    public function it_refuses_two_types_claiming_one_handle(): void
    {
        $this->mainstay->types([Article::class]);

        $this->expectExceptionMessage('Two content types are called "article"');

        $this->mainstay->types([Fixtures\Other\Article::class]);
    }

    #[Test]
    public function each_field_type_says_what_fills_a_required_column_added_to_stored_rows(): void
    {
        $fields = $this->mainstay->fields(RevisedArticle::class);

        $this->assertSame('', $fields['headline']->backfill());
        $this->assertSame(0.0, $fields['readingMinutes']->backfill());
        $this->assertFalse($fields['featured']->backfill());
        $this->assertSame('plain', $fields['tone']->backfill(), 'A select takes its first option.');
        $this->assertSame(CarbonImmutable::now('UTC')->format('Y-m-d'), $fields['reviewedOn']->backfill());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fields['publishedAt']->backfill(), 'A timestamp is written the way to() writes one.');

        $this->expectException(InvalidArgumentException::class);

        $this->mainstay->fields(AccentedArticle::class)['accent']->backfill();
    }

    /*
     | The assertion above only says UTC where the host is already in it. This
     | one pins an instant that falls on a different day either side of the
     | line, so a backfill reading the ambient zone is a different date from
     | the one ContentSchema::fill() writes for the columns it fills itself.
     */
    #[Test]
    public function a_date_is_filled_with_the_utc_day_whatever_zone_the_host_keeps(): void
    {
        $zone = date_default_timezone_get();

        try {
            date_default_timezone_set('Etc/GMT+12');
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 06:00:00', 'UTC'));

            $fields = $this->mainstay->fields(RevisedArticle::class);

            $this->assertSame('2026-09-18', $fields['reviewedOn']->backfill());
            $this->assertSame('2026-09-18 06:00:00', $fields['publishedAt']->backfill());
        } finally {
            CarbonImmutable::setTestNow();
            date_default_timezone_set($zone);
        }
    }
}
