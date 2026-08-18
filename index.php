<?php
/**
 * @package health
 * @subpackage functions
 */

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem;

$gBitSystem->verifyPackage( 'health' );

// placeholder — no content classes or list pages built yet, see this package's own
// MANUAL.md for the architecture sketch. Package exists so it can be installed and
// its schema iterated on.
echo 'Health package installed — no pages built yet.';
