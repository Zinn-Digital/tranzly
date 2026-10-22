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
				'title'      => '',
				'style'      => 'list',
				'display'    => 'name',
				'flags'      => false,
				'flag_shape' => '',
				'flag_size'  => '',
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
			$this->select( $field, $pair[0], $pair[1], (string) $instance[ $field ] );
		}
		echo '<p><input type="checkbox" id="' . esc_attr( $this->get_field_id( 'flags' ) ) . '" name="' . esc_attr( $this->get_field_name( 'flags' ) ) . '" value="1"' . checked( ! empty( $instance['flags'] ), true, false ) . '> <label for="' . esc_attr( $this->get_field_id( 'flags' ) ) . '">' . esc_html__( 'Show flags (a flag is a country, not a language)', 'tranzly' ) . '</label></p>';
		$this->select( 'flag_shape', __( 'Flag shape', 'tranzly' ), Switcher::flag_shape_labels( true ), (string) $instance['flag_shape'] );
		$this->select( 'flag_size', __( 'Flag size', 'tranzly' ), Switcher::flag_size_labels( true ), (string) $instance['flag_size'] );

		return '';
	}

	/**
	 * One labelled select of the form.
	 *
	 * @param string                $field   Field name.
	 * @param string                $label   Its label.
	 * @param array<string, string> $choices value => label.
	 * @param string                $current The saved value.
	 * @return void
	 */
	private function select( string $field, string $label, array $choices, string $current ): void {
		echo '<p><label for="' . esc_attr( $this->get_field_id( $field ) ) . '">' . esc_html( $label ) . '</label> <select class="widefat" id="' . esc_attr( $this->get_field_id( $field ) ) . '" name="' . esc_attr( $this->get_field_name( $field ) ) . '">';
		foreach ( $choices as $value => $name ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $current, (string) $value, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';
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
				'style'     => $new_instance['style'] ?? 'list',
				'display'   => $new_instance['display'] ?? 'name',
				'flags'     => ! empty( $new_instance['flags'] ),
				'flagShape' => $new_instance['flag_shape'] ?? '',
				'flagSize'  => $new_instance['flag_size'] ?? '',
			)
		);

		return array(
			'title'      => sanitize_text_field( (string) ( $new_instance['title'] ?? '' ) ),
			'style'      => $clean['style'],
			'display'    => $clean['display'],
			'flags'      => $clean['flags'],
			'flag_shape' => $clean['flagShape'],
			'flag_size'  => $clean['flagSize'],
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
			'style'     => $instance['style'] ?? 'list',
			'display'   => $instance['display'] ?? 'name',
			'flags'     => ! empty( $instance['flags'] ),
			'flagShape' => $instance['flag_shape'] ?? '',
			'flagSize'  => $instance['flag_size'] ?? '',
		);
	}
}
