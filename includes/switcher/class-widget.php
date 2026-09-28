<?php
/**
 * The classic "Language switcher" widget (tz-l4).
 *
 * @package ZinnDigital\Tranzly
 */

declare( strict_types = 1 );

namespace ZinnDigital\Tranzly\Switcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * For themes with sidebars. Its markup comes from Switcher::render() like every other switcher.
 */
final class Widget extends \WP_Widget {

	/**
	 * Register the widget type.
	 */
	public function __construct() {
		parent::__construct(
			'lang_switcher', // Neutral: WordPress prints it in the widget's id and class (F5).
			__( 'Language switcher', 'tranzly' ),
			array(
				'description'                 => __( 'Links to this page in each of your languages.', 'tranzly' ),
				'customize_selective_refresh' => true,
				'show_instance_in_rest'       => true,
			)
		);
	}

	/**
	 * Front end.
	 *
	 * @param array<string, mixed> $args     Sidebar arguments.
	 * @param array<string, mixed> $instance Saved options.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$html = Switcher::render( self::switcher_args( (array) $instance ) );
		if ( '' === $html ) {
			return;
		}
		$title = trim( (string) ( $instance['title'] ?? '' ) );
		echo wp_kses_post( (string) ( $args['before_widget'] ?? '' ) );
		if ( '' !== $title ) {
			/** This filter is documented in wp-includes/widgets/class-wp-widget-pages.php */
			$title = apply_filters( 'widget_title', $title, $instance, $this->id_base ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter.
			echo wp_kses_post( (string) ( $args['before_title'] ?? '' ) ) . esc_html( (string) $title ) . wp_kses_post( (string) ( $args['after_title'] ?? '' ) );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes every value.
		echo wp_kses_post( (string) ( $args['after_widget'] ?? '' ) );
	}

	/**
	 * The options form.
	 *
	 * @param array<string, mixed> $instance Saved options.
	 * @return string
	 */
	public function form( $instance ) {
		$instance = wp_parse_args(
			(array) $instance,
			array(
				'title'   => '',
				'style'   => 'list',
				'display' => 'name',
				'flags'   => false,
			)
		);
		$styles   = self::style_labels();
		$displays = array(
			'name'      => __( 'Language name', 'tranzly' ),
			'code'      => __( 'Language code', 'tranzly' ),
			'name_code' => __( 'Name and code', 'tranzly' ),
		);
		echo '<p><label for="' . esc_attr( $this->get_field_id( 'title' ) ) . '">' . esc_html__( 'Title', 'tranzly' ) . '</label> '
			. '<input class="widefat" id="' . esc_attr( $this->get_field_id( 'title' ) ) . '" name="' . esc_attr( $this->get_field_name( 'title' ) ) . '" type="text" value="' . esc_attr( (string) $instance['title'] ) . '"></p>';
		foreach ( array(
			'style'   => array( __( 'Design', 'tranzly' ), $styles ),
			'display' => array( __( 'Show each language as', 'tranzly' ), $displays ),
		) as $field => $pair ) {
			echo '<p><label for="' . esc_attr( $this->get_field_id( $field ) ) . '">' . esc_html( $pair[0] ) . '</label> <select class="widefat" id="' . esc_attr( $this->get_field_id( $field ) ) . '" name="' . esc_attr( $this->get_field_name( $field ) ) . '">';
			foreach ( $pair[1] as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) $instance[ $field ], $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></p>';
		}
		echo '<p><input type="checkbox" id="' . esc_attr( $this->get_field_id( 'flags' ) ) . '" name="' . esc_attr( $this->get_field_name( 'flags' ) ) . '" value="1"' . checked( ! empty( $instance['flags'] ), true, false ) . '> <label for="' . esc_attr( $this->get_field_id( 'flags' ) ) . '">' . esc_html__( 'Show flags (a flag is a country, not a language)', 'tranzly' ) . '</label></p>';

		return '';
	}

	/**
	 * Save.
	 *
	 * @param array<string, mixed> $new_instance New options.
	 * @param array<string, mixed> $old_instance Old options.
	 * @return array<string, mixed>
	 */
	public function update( $new_instance, $old_instance ) {
		$clean = Switcher::normalise(
			array(
				'style'   => $new_instance['style'] ?? 'list',
				'display' => $new_instance['display'] ?? 'name',
				'flags'   => ! empty( $new_instance['flags'] ),
			)
		);

		return array(
			'title'   => sanitize_text_field( (string) ( $new_instance['title'] ?? '' ) ),
			'style'   => $clean['style'],
			'display' => $clean['display'],
			'flags'   => $clean['flags'],
		);
	}

	/**
	 * The designs, named for people.
	 *
	 * @return array<string, string>
	 */
	public static function style_labels(): array {
		return array(
			'list'     => __( 'List', 'tranzly' ),
			'pills'    => __( 'Pills', 'tranzly' ),
			'buttons'  => __( 'Buttons', 'tranzly' ),
			'dropdown' => __( 'Dropdown', 'tranzly' ),
			'codes'    => __( 'Language codes', 'tranzly' ),
		);
	}

	/**
	 * Saved widget options as renderer arguments.
	 *
	 * @param array<string, mixed> $instance Saved options.
	 * @return array<string, mixed>
	 */
	private static function switcher_args( array $instance ): array {
		return array(
			'style'   => $instance['style'] ?? 'list',
			'display' => $instance['display'] ?? 'name',
			'flags'   => ! empty( $instance['flags'] ),
		);
	}
}
