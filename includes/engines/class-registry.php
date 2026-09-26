<?php
/**
 * The list of translation engines available on this site.
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Engines;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Engines register themselves on `tranzly_register_engines`, which fires once, the first time the
 * list is needed — so an add-on plugin can register from any point before that, whatever its
 * load order.
 */
final class Registry {

	/**
	 * The one instance.
	 *
	 * @var Registry|null
	 */
	private static ?Registry $instance = null;

	/**
	 * Registered engines by id.
	 *
	 * @var array<string, Engine>
	 */
	private array $engines = array();

	/**
	 * The registry, filled on first use.
	 *
	 * @return Registry
	 */
	public static function instance(): Registry {
		if ( null === self::$instance ) {
			self::$instance = new self();

			/**
			 * Register translation engines. Call `$registry->add( new Your_Engine() )`.
			 *
			 * @param Registry $registry The engine registry.
			 */
			do_action( 'tranzly_register_engines', self::$instance );
		}

		return self::$instance;
	}

	/**
	 * Forget the instance (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Add an engine. A second engine with the same id replaces the first, so an add-on can
	 * override a built-in engine on purpose.
	 *
	 * @param Engine $engine The engine.
	 * @return true|\WP_Error
	 */
	public function add( Engine $engine ) {
		$id = $engine->id();
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $id ) ) {
			return new \WP_Error( 'tranzly_bad_engine_id', __( 'An engine id must be lowercase letters, digits and hyphens.', 'tranzly' ) );
		}
		$this->engines[ $id ] = $engine;

		return true;
	}

	/**
	 * One engine, or null.
	 *
	 * @param string $id Engine id.
	 * @return Engine|null
	 */
	public function get( string $id ): ?Engine {
		return $this->engines[ $id ] ?? null;
	}

	/**
	 * Every engine.
	 *
	 * @return array<string, Engine>
	 */
	public function all(): array {
		return $this->engines;
	}

	/**
	 * The engine to use when none is named: the `tranzly_default_engine` filter's choice, else the
	 * first configured one.
	 *
	 * @return Engine|null
	 */
	public function default_engine(): ?Engine {
		$configured = array_filter( $this->engines, static fn( Engine $e ) => $e->is_configured() );

		/**
		 * Filters the id of the engine used when none is named.
		 *
		 * @param string $id The first configured engine's id, or an empty string.
		 */
		$id = (string) apply_filters( 'tranzly_default_engine', (string) array_key_first( $configured ) );

		return $configured[ $id ] ?? null;
	}
}
