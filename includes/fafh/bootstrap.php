<?php
/**
 * FAFH -- Font Awesome For HivePress.
 *
 * Drop-in icon library shared by several HivePress extensions. It is BUNDLED
 * inside each plugin, not installed separately: there is no dependency for a
 * site owner to satisfy, and a plugin works on its own.
 *
 * Each plugin includes this file once, from its main plugin file:
 *
 *     require_once __DIR__ . '/includes/fafh/bootstrap.php';
 *
 * When several plugins are active they all register here and the highest
 * version wins, so exactly one copy runs however many are installed. Keep the
 * whole `fafh/` folder byte-identical across plugins and bump VERSION below
 * whenever the library changes; syncing is what tools/sync-fafh.ps1 is for.
 *
 * @package FAFH
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'FAFH_Loader', false ) ) {
	require_once __DIR__ . '/class-fafh-loader.php';
}

/*
 * The '1.0.0' is the version of THIS copy of the library, and it is what the
 * arbitration compares -- not the Font Awesome version, which lives in
 * data/manifest.json and is exposed as FAFH::fa_version().
 *
 * Passed as a literal rather than through a variable on purpose: a variable at
 * file scope here is a global, and Plugin Check reports one that does not carry
 * the host plugin's prefix (PrefixAllGlobals.NonPrefixedVariableFound). The
 * library is shared by several plugins and so cannot carry any one of their
 * prefixes, and tools\sync-fafh.ps1 reads the version straight out of this call.
 */
FAFH_Loader::register( '1.2.1', __DIR__ );
