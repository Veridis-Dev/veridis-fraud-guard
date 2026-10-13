<?php
/**
 * Veridis Fraud Guard uninstall cleanup.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/PHP/Core/Uninstaller.php';

\Veridis\FraudGuard\Core\Uninstaller::uninstall();
