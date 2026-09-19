<?php

declare(strict_types=1);

/**
 * PHP resolves an UNQUALIFIED function call first against the CALLER's own
 * namespace, falling back to the global one only if no such function exists
 * there. AdSpendCsvUploader calls is_uploaded_file($tmpName) unqualified
 * from inside this exact namespace, so defining a same-named function here
 * intercepts only that one call site — nothing else in the codebase is
 * affected. Standard PHPUnit technique for a builtin (is_uploaded_file(),
 * time(), rand()...) that a test fixture cannot genuinely satisfy: a unit
 * test has no real HTTP upload to point it at, and PHPUnit's own manual says
 * this specific check cannot be faked any other way.
 *
 * Kept in its own file, included with require_once, rather than declared
 * inline in the test class's file via bracketed `namespace X { ... }`
 * blocks: both are valid PHP (this module's phpcs run confirmed the
 * bracketed form parses and executes correctly), but phpcs's tokenizer does
 * not handle the bracketed multi-namespace syntax cleanly, and there is no
 * real cost to sidestepping that entirely.
 */
namespace Aavirbhava\AdsAnalytics\Model\AdSpendProvider;

function is_uploaded_file($filename)
{
    return true;
}
