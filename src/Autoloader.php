<?php
/**
 * PSR-4 Autoloader for SmartWebPFlow.
 *
 * @package SmartWebPFlow
 */

namespace SmartWebPFlow;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Autoloader
 * Dynamically loads classes located within the src/ directory matching the SmartWebPFlow namespace.
 */
class Autoloader {

	/**
	 * Namespace prefix.
	 *
	 * @var string
	 */
	const PREFIX = 'SmartWebPFlow\\';

	/**
	 * Base directory for the namespace prefix.
	 *
	 * @var string
	 */
	private static $base_dir = '';

	/**
	 * Registers the autoloader.
	 *
	 * @return void
	 */
	public static function register(): void {
		self::$base_dir = __DIR__ . '/';
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Loads the class file for a given class name.
	 *
	 * @param string $class_name The fully-qualified class name.
	 * @return bool True if loaded, false otherwise.
	 */
	public static function autoload( string $class_name ): bool {
		// Does the class use our namespace prefix?
		$len = strlen( self::PREFIX );
		if ( strncmp( self::PREFIX, $class_name, $len ) !== 0 ) {
			return false;
		}

		// Get the relative class name.
		$relative_class = substr( $class_name, $len );

		// Replace namespace separators with directory separators and append .php.
		$file = self::$base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		// If the file exists, require it.
		if ( file_exists( $file ) ) {
			require_once $file;
			return true;
		}

		return false;
	}
}
