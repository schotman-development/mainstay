<?php

namespace Mainstay\Tests\Fixtures;

/* A backed enum with no cases: an empty options list written somewhere the
   Select constructor cannot see it by comparing against `[]`. */
enum Nothing: string {}
