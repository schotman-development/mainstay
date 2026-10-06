<?php

namespace Mainstay\Content;

/*
 | Terms that group entries: `class Tag extends Taxonomy`, each row a term.
 | A term is an entry -- its tables, its route, its view, url(), the reads and
 | the writes -- and what makes it a term is that a Terms field points at it
 | and a read filters by it. Flat.
 */
abstract class Taxonomy extends Entry {}
