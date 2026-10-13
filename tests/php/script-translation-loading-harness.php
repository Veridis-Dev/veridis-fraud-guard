<?php
declare(strict_types=1);

$wordpress_root   = rtrim( (string) getenv( 'VFG_WP_ROOT' ), '/\\' );
$translations_dir = rtrim( (string) getenv( 'VFG_TRANSLATIONS_DIR' ), '/\\' );
$locale           = (string) ( getenv( 'VFG_TEST_LOCALE' ) ?: 'fr_FR' );
$expected          = (string) getenv( 'VFG_EXPECTED_TRANSLATION' );

if ( '' === $wordpress_root || '' === $translations_dir || '' === $expected ) {
	fwrite( STDERR, "Set VFG_WP_ROOT, VFG_TRANSLATIONS_DIR, and VFG_EXPECTED_TRANSLATION.\n" );
	exit( 2 );
}

define( 'WP_INSTALLING', true );
define( 'DISABLE_WP_CRON', true );
define( 'WP_USE_THEMES', false );
require $wordpress_root . '/wp-load.php';

switch_to_locale( $locale );

$manifest = json_decode( (string) file_get_contents( __DIR__ . '/../../build/manifest.json' ), true );
$entry    = is_array( $manifest ) ? ( $manifest['src/AdminApp/main.tsx'] ?? null ) : null;
if ( ! is_array( $entry ) || empty( $entry['file'] ) ) {
	throw new RuntimeException( 'The built admin entry is missing from build/manifest.json.' );
}

$handle = 'veridis-fraud-guard-app';
$src    = plugins_url( 'veridis-fraud-guard/build/' . ltrim( (string) $entry['file'], '/' ) );
wp_register_script( $handle, $src, array( 'wp-element', 'wp-i18n' ), null, true );

if ( ! wp_set_script_translations( $handle, 'veridis-fraud-guard', $translations_dir ) ) {
	throw new RuntimeException( 'WordPress rejected the script translation association.' );
}

$translations = wp_scripts()->print_translations( $handle, false );
if ( ! is_string( $translations ) || ! str_contains( $translations, $expected ) ) {
	throw new RuntimeException( 'WordPress did not load the disposable JavaScript catalog.' );
}

echo "script translation loading harness passed\n";
