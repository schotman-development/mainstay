<?php

namespace Mainstay\Ui;

class Control
{
    /*
     | The ground a control sits on. A control has to contrast with whatever is
     | behind it, and the admin has two backgrounds -- the page and the panels
     | on it. A control on the page takes the panel colour and one on a panel
     | takes the page colour, which is why this cannot be baked in.
     */
    private const GROUNDS = [
        'canvas' => 'bg-canvas',
        'surface' => 'bg-surface',
    ];

    /*
     | Two densities, matching the menu item's `compact`: the forms are 14px
     | type with room around it, the bar controls are the same type in a shorter
     | box so they line up with the other things on a bar.
     |
     | The placeholder weight rides along with the size rather than sitting in
     | the base, and it is not an accident: a form placeholder is a sample value
     | and should stay behind the real one, while a bar placeholder is an
     | instruction ("Search") and has to be readable at rest.
     |
     | `none` hands the padding and the type size back to the caller, for a
     | control the two densities do not fit -- the command centre's combobox,
     | which has to leave room for an icon and a shortcut hint -- so that it can
     | still take its border, its ground and its focus ring from here.
     */
    private const SIZES = [
        'none' => '',
        'sm' => 'px-2.5 py-1 text-sm placeholder:text-muted',
        'md' => 'px-2.5 py-1.5 text-sm placeholder:text-muted/70',
    ];

    /*
     | One class list for every text control in the admin, so a border, a ground
     | or a focus ring cannot drift between an input, a textarea and a select.
     |
     | Public because a control this does not cover yet should still take its
     | border and its focus ring from here rather than restating them. The three
     | components that wrap it hold the list once between them; a Blade
     | component in the middle cannot, because a prop forwarded on through an
     | attribute bag keeps the spelling it was written with and `full-width`
     | quietly stops matching `fullWidth`.
     |
     | `bare` means no border, no ground and no focus ring, for a control that
     | sits inside an input shell. The shell draws all three for the group, and
     | a control that draws its own inside it gives you a box in a box and two
     | focus rings. It implies size none: a control inside a shell is spaced
     | against the shell's own padding, and the two sets fighting is how the tag
     | box ended up three times taller than its chips.
     |
     | fullWidth is off for a control that sets its own width. `w-full` cannot
     | simply be overridden from the outside -- at equal specificity the cascade
     | goes by stylesheet order, and `.w-full` is emitted after `.w-48` -- so
     | the base has to be asked not to claim the width rather than argued out
     | of it.
     */
    public static function classes(
        string $size = 'md',
        string $ground = 'canvas',
        bool $bare = false,
        bool $fullWidth = true,
    ): string {
        return implode(' ', array_filter([
            $fullWidth ? 'w-full' : '',
            $bare
                ? 'bg-transparent focus:outline-none'
                : 'rounded-control border border-border '.self::GROUNDS[$ground].' focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
            $bare ? '' : self::SIZES[$size],
        ]));
    }
}
