<?php

namespace Mainstay\Fields;

use Attribute;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Date extends Field
{
    /*
     | `time` is here rather than left to a later phase because publish state is
     | a timestamp, not a day, and it is the same field with one flag rather
     | than a second field type.
     */
    public function __construct(
        ?bool $required = null,
        bool $localized = false,
        ?string $label = null,
        public readonly bool $time = false,
    ) {
        parent::__construct($required, $localized, $label);
    }

    /*
     | The same guard Select and Number carry: from() hands back a
     | CarbonImmutable, so `#[Date] public string $when` is a declaration that
     | reads as if it works and raises a TypeError at hydration, with nothing
     | in it naming the attribute.
     */
    public function bind(ReflectionProperty $property): static
    {
        $declared = $property->getType();

        /*
         | Every name in the declaration, however it is composed, and all of
         | them have to hold a date.
         |
         | For an intersection that is plain assignability: a value has to
         | satisfy each arm to be stored in one. For a union it is stricter
         | than PHP, which stores a value that matches any single arm -- so
         | `#[Date] public CarbonImmutable|string` takes a date and is refused
         | here anyway.
         |
         | The looser rule is not just looser, it cannot be written correctly:
         | "any arm holds a date" has to decide whether a `string` arm holds
         | one, and under coercive assignment it does, because Carbon has a
         | __toString. So it would accept `string|int` -- the case this guard
         | was added for, and the one that stores the date as a string and
         | reports nothing. All-arms never asks the question.
         |
         | The cost is refusing a `CarbonImmutable|string` that would have
         | worked. That is a loud refusal naming the property, against a silent
         | acceptance surfacing two frames away.
         |
         | Its own loop rather than stores(): what a date can hydrate into is an
         | assignability question rather than a list of names, and the message
         | has a tail only a composite type gets.
         */
        $names = $this->names($declared);

        foreach ($names as $name) {
            if ($this->holdsADate($name, count($names) > 1)) {
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                '%s::$%s is typed %s, and a date hydrates into a %s.%s',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                (string) $declared,
                CarbonImmutable::class,
                /* Otherwise a composite type that can hold one reads as though
                   it cannot, and the rule is only in the source. */
                count($names) > 1 ? ' Every part of the type has to hold one.' : '',
            ));
        }

        return parent::bind($property);
    }

    public function column(): ?array
    {
        return [$this->time ? 'dateTime' : 'date'];
    }

    public function rules(): array
    {
        return [...parent::rules(), 'date'];
    }

    /*
     | A string with no zone in it is read as UTC, because the column it came
     | out of is UTC -- see to(). Read in the process default instead and a
     | value round trips through this field shifted by the offset between that
     | and UTC, which is nothing at all until a host sets `app.timezone` and
     | then is silent and everywhere. A string that does carry an offset keeps
     | it, and so does a DateTimeInterface: both stated a zone, and to()
     | converts rather than discards.
     |
     | Which puts one rule on the caller: hand a column value over as the
     | string it is. `new DateTime('2026-09-10 06:30:00')` inherits the process
     | default -- the ambient zone this branch refuses to read a string in --
     | and is then converted out of it, so wrapping a naive string on the way
     | in shifts the instant. It cannot be guarded here: an object built that
     | way and a CarbonImmutable::now() are the same object, a timezone_type 3
     | named for the ambient zone, and now() is the instant it says it is.
     | Refusing them together would refuse the common one to catch the mistake.
     |
     | ponytail: an editor typing a naive time into the admin therefore types
     | UTC. The form sends an offset when the editor's zone matters, which is
     | phase 5's to send.
     */
    protected function from(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value, 'UTC');
    }

    /*
     | Through from() rather than beside it, so a date written out is parsed
     | the same way one read in was.
     |
     | A timestamp is converted to UTC before it is formatted, because the
     | column has no zone to keep one in: `2026-09-10T08:30:00+02:00` written
     | as `08:30:00` is a different instant from the one that was sent, and two
     | clients in different zones posting the same moment would store two
     | different rows with nothing left to tell them apart. A date has no
     | instant to convert -- shifting it by an offset moves the day -- so only
     | the `time` branch normalises.
     */
    protected function to(mixed $value): mixed
    {
        $date = $this->from($value);

        return $this->time
            ? $date->utc()->format('Y-m-d H:i:s')
            : $date->format('Y-m-d');
    }

    /*
     | `date-time` is RFC 3339 and wants the `T` and the offset that to() does
     | not write. They describe different things and neither is wrong: to() is
     | the value the column takes, and this is the shape a consumer reads over
     | the API. The payload that has to satisfy this is phase 11's, and it is
     | the reason from() and to() agree on UTC now rather than then.
     */
    protected function json(): array
    {
        return ['type' => 'string', 'format' => $this->time ? 'date-time' : 'date'];
    }

    /* Backwards on purpose: the question is not whether the declared type is a
       date, it is whether the CarbonImmutable from() returns can be assigned
       to it. Which is why `DateTime` and `Carbon` are refused -- neither is
       something a CarbonImmutable is -- and why a subclass of CarbonImmutable
       is too. `mixed` and `object` hold one and name no class to ask about.

       `null` only beside other arms: `?CarbonImmutable` reflects as a plain
       CarbonImmutable that allows null, so the name turns up in a union of
       three or in `(A&B)|null` -- or as `public null $when`, which holds no
       date and every other check would have refused. */
    private function holdsADate(string $type, bool $composite): bool
    {
        return in_array($type, ['mixed', 'object'], true)
            || ($type === 'null' && $composite)
            || is_a(CarbonImmutable::class, $type, true);
    }
}
