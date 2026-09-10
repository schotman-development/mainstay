<?php

namespace Mainstay\Content;

/*
 | Many instances, each with a route. Everything that separates an entry from a
 | global -- the slug, the URI row, the route pattern -- arrives in the phase
 | that needs it. The shape is what phase 1 has to settle.
 */
abstract class Entry extends ContentType {}
