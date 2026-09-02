<?php
/**
 * FAFH -- the shared icon library.
 *
 * Renders Font Awesome Free icons as inline SVG on the front end, so a page
 * costs a few hundred bytes of markup instead of ~234 KB of stylesheet and
 * webfont, and never collides with the Font Awesome 5 that HivePress core
 * enqueues (there is no `.fas` / `.fa-solid` cascade to lose when the glyph
 * is not a font). The webfont is still bundled, for the admin icon pickers
 * only, where 1,918 inline SVGs in one control would be the worse trade.
 *
 * Icons are addressed by NAME, not by style, because in Font Awesome 7 Free
 * only `font-awesome` and `web-awesome` exist in more than one family. Every
 * saved setting that already holds a bare icon slug therefore keeps working,
 * and brand icons resolve on their own without anything being migrated.
 *
 * @package FAFH
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'FAFH', false ) ) {
	return;
}

/**
 * Inline-SVG icon rendering, backed by the bundled Font Awesome Free data.
 */
final class FAFH {

	/**
	 * Styles, in the order a bare name is resolved against them.
	 *
	 * @var array
	 */
	const STYLES = [ 'solid', 'brands', 'regular' ];

	/**
	 * Handle for the (tiny) stylesheet that sizes inline SVGs.
	 *
	 * @var string
	 */
	const STYLE_HANDLE = 'fafh-icons';

	/**
	 * Handle for the wp-admin shim that draws icons without a webfont.
	 *
	 * @var string
	 */
	const ADMIN_HANDLE = 'fafh-admin';

	/**
	 * Most icons one admin-ajax request will answer for.
	 *
	 * A dropdown shows a few dozen results at a time and the script coalesces a
	 * render into one request, so this is a ceiling rather than a target. It
	 * exists so a crafted request cannot ask for the whole library at once.
	 *
	 * @var int
	 */
	const AJAX_LIMIT = 200;

	/**
	 * Most icons one picker search will offer.
	 *
	 * Core caps its own sourced fields at 20 (class-user.php:268). Thirty gives
	 * a little more to scroll without turning the dropdown into the list this
	 * change exists to remove.
	 *
	 * @var int
	 */
	const SEARCH_LIMIT = 30;

	/**
	 * Single-letter style codes used inside data/index.json.
	 *
	 * @var array
	 */
	private static $codes = [
		's' => 'solid',
		'r' => 'regular',
		'b' => 'brands',
	];

	/**
	 * Directory of the copy that won arbitration.
	 *
	 * @var string
	 */
	private static $dir = '';

	/**
	 * URL of that directory, resolved lazily.
	 *
	 * @var string|null
	 */
	private static $url = null;

	/**
	 * Library version of the running copy.
	 *
	 * @var string
	 */
	private static $version = '';

	/**
	 * Decoded data files, keyed by basename.
	 *
	 * @var array
	 */
	private static $data = [];

	/**
	 * Records where the running copy lives. Called once, by FAFH_Loader.
	 *
	 * @param string $dir     Absolute path to the winning fafh/ directory.
	 * @param string $version That copy's library version.
	 */
	public static function boot( $dir, $version ) {
		if ( self::$dir ) {
			return;
		}

		self::$dir     = (string) $dir;
		self::$version = (string) $version;
	}

	/**
	 * Library version of the running copy.
	 *
	 * @return string
	 */
	public static function version() {
		return self::$version;
	}

	/**
	 * Font Awesome version the bundled data was generated from.
	 *
	 * @return string
	 */
	public static function fa_version() {
		$manifest = self::data( 'manifest.json' );

		return isset( $manifest['fa_version'] ) ? $manifest['fa_version'] : '';
	}

	// ---------------------------------------------------------------------
	// Lookup.
	// ---------------------------------------------------------------------

	/**
	 * Resolves an icon name to its canonical Font Awesome 7 name.
	 *
	 * Font Awesome renamed a great many icons between 5 and 6, keeping the old
	 * names as aliases -- 351 of the 1,000 names in HivePress core's own
	 * configs/icons.php only resolve this way, so never skip this step.
	 *
	 * @param string $name Icon name, canonical or aliased.
	 * @return string Canonical name, or the input unchanged if unknown.
	 */
	public static function resolve( $name ) {
		$name = self::clean( $name );

		if ( '' === $name || isset( self::index()[ $name ] ) ) {
			return $name;
		}

		$aliases = self::data( 'aliases.json' );

		return isset( $aliases[ $name ] ) ? $aliases[ $name ] : $name;
	}

	/**
	 * Every icon, as canonical name => [ style codes, label ].
	 *
	 * @return array
	 */
	public static function index() {
		return self::data( 'index.json' );
	}

	/**
	 * Styles a given icon exists in.
	 *
	 * @param string $name Icon name, canonical or aliased.
	 * @return array Style names, in STYLES order. Empty if the icon is unknown.
	 */
	public static function styles( $name ) {
		$entry = self::entry( $name );

		if ( ! $entry ) {
			return [];
		}

		$styles = [];

		foreach ( self::STYLES as $style ) {
			if ( false !== strpos( $entry[0], array_search( $style, self::$codes, true ) ) ) {
				$styles[] = $style;
			}
		}

		return $styles;
	}

	/**
	 * Whether an icon exists, optionally in a specific style.
	 *
	 * @param string      $name  Icon name, canonical or aliased.
	 * @param string|null $style Style to require, or null for any.
	 * @return bool
	 */
	public static function has( $name, $style = null ) {
		$styles = self::styles( $name );

		if ( null === $style ) {
			return (bool) $styles;
		}

		return in_array( $style, $styles, true );
	}

	/**
	 * Human label for an icon, for use in a picker.
	 *
	 * @param string $name Icon name, canonical or aliased.
	 * @return string Label, or '' if the icon is unknown.
	 */
	public static function label( $name ) {
		$entry = self::entry( $name );

		return $entry ? $entry[1] : '';
	}

	/**
	 * Icon choices for a settings field, as canonical name => label.
	 *
	 * This is the one canonical list. Plugins should use it rather than
	 * maintaining their own, so that an icon added here appears everywhere.
	 *
	 * @param array|null $styles Styles to include, or null for all of them.
	 * @return array Sorted by label.
	 */
	public static function choices( $styles = null ) {
		$styles = null === $styles ? self::STYLES : (array) $styles;
		$wanted = '';

		foreach ( $styles as $style ) {
			$code = array_search( $style, self::$codes, true );

			if ( $code ) {
				$wanted .= $code;
			}
		}

		$choices = [];

		foreach ( self::index() as $name => $entry ) {
			if ( '' !== $wanted && ! self::intersects( $entry[0], $wanted ) ) {
				continue;
			}

			$choices[ $name ] = $entry[1];
		}

		natcasesort( $choices );

		return $choices;
	}

	// ---------------------------------------------------------------------
	// Rendering.
	// ---------------------------------------------------------------------

	/**
	 * Splits a Font Awesome class string into an icon name and a style.
	 *
	 * Accepts anything a stored setting realistically holds: a bare slug
	 * ("star"), a name class ("fa-star"), or a full class string in either
	 * version-5 or version-6/7 spelling ("fab fa-instagram",
	 * "fa-brands fa-instagram").
	 *
	 * @param string $classes Class string or bare icon name.
	 * @return array [ name, style|null ] -- style is null when unspecified.
	 */
	public static function parse( $classes ) {
		$map = [
			'fas'        => 'solid',
			'fa-solid'   => 'solid',
			'far'        => 'regular',
			'fa-regular' => 'regular',
			'fab'        => 'brands',
			'fa-brands'  => 'brands',
		];

		$name  = '';
		$style = null;

		foreach ( preg_split( '/\s+/', trim( (string) $classes ) ) as $token ) {
			$token = self::clean( $token );

			if ( '' === $token ) {
				continue;
			}

			if ( isset( $map[ $token ] ) ) {
				$style = $map[ $token ];
				continue;
			}

			// Plain "fa" is the version-5 generic prefix and carries no style.
			if ( 'fa' === $token ) {
				continue;
			}

			if ( '' === $name ) {
				$name = 0 === strpos( $token, 'fa-' ) ? substr( $token, 3 ) : $token;
			}
		}

		return [ $name, $style ];
	}

	/**
	 * Inline SVG markup for one icon.
	 *
	 * @param string      $name  Icon name or a full Font Awesome class string.
	 * @param string|null $style Style to force, or null to resolve by name.
	 * @param array       $args  Optional. 'class' for extra CSS classes and
	 *                           'label' for an accessible name -- with a label
	 *                           the SVG is exposed as an image, without one it
	 *                           is hidden from assistive technology.
	 * @return string SVG markup, or '' if the icon is unknown.
	 */
	public static function svg( $name, $style = null, $args = [] ) {
		list( $parsed, $parsed_style ) = self::parse( $name );

		$name  = self::resolve( $parsed );
		$style = $style ? $style : $parsed_style;
		$glyph = self::glyph( $name, $style );

		if ( ! $glyph ) {
			return '';
		}

		list( $view_box, $path ) = $glyph;

		$args = array_merge(
			[
				'class' => '',
				'label' => '',
			],
			(array) $args
		);

		$class = trim( 'fafh-icon__svg ' . $args['class'] );

		$attributes = [
			'xmlns'   => 'http://www.w3.org/2000/svg',
			'viewBox' => $view_box,
			'class'   => $class,
		];

		if ( '' === $args['label'] ) {
			$attributes['aria-hidden'] = 'true';
			$attributes['focusable']   = 'false';
			$title                     = '';
		} else {
			$attributes['role']       = 'img';
			$attributes['aria-label'] = $args['label'];
			$title                    = '<title>' . esc_html( $args['label'] ) . '</title>';
		}

		/*
		 * Enqueued HERE rather than only in icon(), because an <svg> with no
		 * width or height is not merely unstyled -- browsers fall back to the
		 * replaced-element default of 300x150 px, so a missing stylesheet gives
		 * a caller an enormous icon rather than a plain one.
		 *
		 * Trust Signals calls svg() directly and shipped exactly that on
		 * 2026-09-01: the markup was right and the page had no sizing rule at
		 * all. Anything that emits a glyph now pulls its stylesheet with it.
		 */
		self::enqueue_style();

		$markup = '<svg';

		foreach ( $attributes as $attribute => $value ) {
			$markup .= ' ' . $attribute . '="' . esc_attr( $value ) . '"';
		}

		/*
		 * vector-effect="non-scaling-stroke" is emitted always, even though most
		 * icons carry no stroke, because without it a stroke is unusable.
		 *
		 * Every Font Awesome viewBox is "0 0 W 512" (checked: 2,089 of 2,089
		 * glyphs), so one user unit is 1/512 em. A caller setting a hairline
		 * weight the way the webfont did it -- stroke-width:0.3px, mirroring
		 * -webkit-text-stroke:0.3px -- would get 0.3 USER UNITS, about 0.06% of
		 * the icon's height, which is invisible. Holiday Mode's icon-weight
		 * setting did exactly that and silently stopped working. This attribute
		 * makes stroke-width mean CSS pixels regardless of the viewBox scale, so
		 * the same value means the same thickness in both renderers.
		 *
		 * As an ATTRIBUTE rather than the CSS property of the same name: the
		 * attribute has been supported for as long as SVG has, while the CSS
		 * property only reached Safari in 16.4 (2023). It costs 38 bytes and is
		 * inert on an icon with no stroke.
		 */
		return $markup . '>' . $title . '<path vector-effect="non-scaling-stroke" d="' . esc_attr( $path ) . '"/></svg>';
	}

	/**
	 * Inline SVG wrapped in the element the front end should output.
	 *
	 * The wrapper deliberately does NOT carry Font Awesome's own classes: with
	 * core's Font Awesome 5 stylesheet also on the page, `fas`/`fa-solid` would
	 * inject a ::before pseudo-glyph and the icon would render twice.
	 *
	 * @param string      $name  Icon name or a full Font Awesome class string.
	 * @param string|null $style Style to force, or null to resolve by name.
	 * @param array       $args  See svg().
	 * @return string Markup, or '' if the icon is unknown.
	 */
	public static function icon( $name, $style = null, $args = [] ) {
		$svg = self::svg( $name, $style, $args );

		if ( ! $svg ) {
			return '';
		}

		self::enqueue_style();

		return '<i class="fafh-icon">' . $svg . '</i>';
	}

	/**
	 * Compact glyph data for one icon: "viewBox|path".
	 *
	 * The form JavaScript should be handed, rather than finished <svg> markup.
	 * It carries no angle brackets, so it cannot be mistaken for HTML however it
	 * is handled at the other end, and it is ~120 bytes smaller per icon. Build
	 * the element with createElementNS() and setAttribute(); see
	 * assets/js/social-proof.js for the reference consumer.
	 *
	 * @param string      $name  Icon name or a full Font Awesome class string.
	 * @param string|null $style Style to force, or null to resolve by name.
	 * @return string|null "viewBox|path", or null if the icon is unknown.
	 */
	public static function pair( $name, $style = null ) {
		list( $parsed, $parsed_style ) = self::parse( $name );

		$glyph = self::glyph( self::resolve( $parsed ), $style ? $style : $parsed_style );

		return $glyph ? $glyph[0] . '|' . $glyph[1] : null;
	}

	/**
	 * Compact glyph data for several icons, for localising to a script.
	 *
	 * Pass either a plain list of names, or name => style pairs where the style
	 * matters. Passing the style is worth the trouble: 19 of the icons in Social
	 * Proof's own list alone exist in both solid and regular, and while a bare
	 * name resolves to solid first, relying on that is relying on luck.
	 *
	 * Unknown icons are skipped rather than emitted empty, so the consumer can
	 * treat a missing key as "no icon" without a second check.
	 *
	 * Keyed by the name the CALLER PASSED, not the canonical one. That is the
	 * whole point: a consumer looks up the name it is holding, and the names
	 * these plugins hold are whatever was saved in a setting or stored on a
	 * notification -- frequently a Font Awesome 5 name.
	 *
	 * It was keyed canonically until 2026-09-01, and the bug that found was
	 * this: Notifications localised `times` and `cog`, which resolve to `xmark`
	 * and `gear`, so its script asked for `times`, missed, and fell back to a
	 * font class that no longer loads. Every close button in every toast drew
	 * nothing. Social Proof had the same fault waiting on any event using one of
	 * the four aliases in its list. If a caller genuinely wants the canonical
	 * key, it can call resolve() itself.
	 *
	 * @param array $wanted Icon names, or name => style.
	 * @return array Name as passed => "viewBox|path".
	 */
	public static function map( $wanted ) {
		$map = [];

		foreach ( (array) $wanted as $key => $value ) {
			$name  = is_int( $key ) ? $value : $key;
			$style = is_int( $key ) ? null : $value;
			$pair  = self::pair( $name, $style );

			if ( null !== $pair ) {
				$map[ self::clean( $name ) ] = $pair;
			}
		}

		return $map;
	}

	/**
	 * Tags and attributes to pass to wp_kses() when echoing FAFH markup.
	 *
	 * @return array
	 */
	public static function kses() {
		return [
			'i'     => [ 'class' => true ],
			'svg'   => [
				'xmlns'       => true,
				'viewbox'     => true,
				'class'       => true,
				'role'        => true,
				'aria-label'  => true,
				'aria-hidden' => true,
				'focusable'   => true,
			],
			'title' => [],
			'path'  => [
				'd'             => true,
				'vector-effect' => true,
			],
		];
	}

	// ---------------------------------------------------------------------
	// Assets.
	// ---------------------------------------------------------------------

	/**
	 * URL of the running copy's fafh/ directory, with a trailing slash.
	 *
	 * @return string
	 */
	public static function url() {
		if ( null === self::$url ) {
			self::$url = self::$dir ? plugin_dir_url( self::$dir . '/bootstrap.php' ) : '';
		}

		return self::$url;
	}

	/**
	 * Enqueues the stylesheet that sizes and colours inline SVGs.
	 */
	public static function enqueue_style() {
		// Callable from anywhere, including a context with no style queue at all
		// (a REST response, a cron run, a test harness). Bail rather than fatal.
		if ( ! function_exists( 'wp_style_is' ) || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		if ( wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			return;
		}

		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style(
				self::STYLE_HANDLE,
				self::url() . 'assets/fafh-icons.css',
				[],
				self::version()
			);
		}

		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * Enqueues the wp-admin shim that draws icons without a webfont.
	 *
	 * Replaced enqueue_font() in 1.2.0. Until then the library shipped the whole
	 * Font Awesome webfont -- 315 KB in every plugin carrying it -- for one
	 * reason: an icon PICKER has to preview a glyph, and core's select2 template
	 * hardcodes `<i class="fas fa-fw fa-{id}">` (hivepress/assets/js/
	 * common.js:233). The shim converts those elements to inline SVG instead,
	 * fetching glyphs in batches as a dropdown renders them.
	 *
	 * It converts markup this library did not write, on purpose. That means no
	 * picker had to change, core's own included, and the plugins still on the
	 * old webfont path keep working.
	 *
	 * Safe to call more than once, and safe to call on any admin screen.
	 */
	public static function enqueue_admin() {
		/*
		 * wp-admin ONLY, enforced here rather than trusted to callers.
		 *
		 * On 2026-09-01 a front-end path in Notifications still called the
		 * helper that delegates here, and the shim loaded on a public listing
		 * page. It then did exactly what it is built to do -- convert every
		 * `<i class="fas fa-...">` it could see -- which on the front end meant
		 * 26 of HivePress core's and the theme's own icons, each waiting on an
		 * admin-ajax round trip that a logged-out visitor cannot even make.
		 *
		 * The front end does not need this: it renders inline SVG server-side
		 * from FAFH::svg(), or from a localised map. If an icon is missing out
		 * there, the fix is at the render site, never here.
		 */
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return;
		}

		if ( ! wp_script_is( self::ADMIN_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::ADMIN_HANDLE,
				self::url() . 'assets/fafh-admin.js',
				[],
				self::version(),
				true
			);

			wp_localize_script(
				self::ADMIN_HANDLE,
				'fafhAdmin',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'fafh_icons' ),
				]
			);
		}

		wp_enqueue_script( self::ADMIN_HANDLE );

		if ( ! wp_style_is( self::ADMIN_HANDLE, 'registered' ) ) {
			wp_register_style(
				self::ADMIN_HANDLE,
				self::url() . 'assets/fafh-admin.css',
				[],
				self::version()
			);
		}

		wp_enqueue_style( self::ADMIN_HANDLE );
	}

	/**
	 * Registers the admin-ajax endpoint the shim fetches glyphs from.
	 *
	 * Called once, by FAFH_Loader, on the copy that won arbitration.
	 */
	public static function register_ajax() {
		add_action( 'wp_ajax_fafh_icons', [ __CLASS__, 'ajax_icons' ] );
	}

	// ---------------------------------------------------------------------
	// The icon picker.
	// ---------------------------------------------------------------------

	/**
	 * Registers the REST route an icon picker searches against.
	 *
	 * Called once, by FAFH_Loader, on the copy that won arbitration.
	 */
	public static function register_rest() {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'fafh/v1',
					'/icons',
					[
						'methods'             => 'GET',
						'callback'            => [ __CLASS__, 'rest_icons' ],
						'permission_callback' => static function () {
							// Editing a setting is what this is for, so the bar
							// is the same one the settings screen sets. The data
							// is bundled Font Awesome Free either way.
							return current_user_can( 'edit_posts' );
						},
						'args'                => [
							'search' => [
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							],
						],
					]
				);
			}
		);
	}

	/**
	 * Answers a picker's search with matching icons.
	 *
	 * Shape is core's: `{ data: [ { id, text } ] }`, which is what the select2
	 * setup in hivepress/assets/js/common.js:264 reads. Exact and prefix matches
	 * come first, so typing "star" offers `star` before `star-and-crescent`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function rest_icons( $request ) {
		$term  = self::clean( $request->get_param( 'search' ) );
		$index = self::index();

		$exact  = [];
		$prefix = [];
		$rest   = [];

		foreach ( $index as $name => $entry ) {
			$label = isset( $entry[1] ) ? $entry[1] : $name;

			if ( '' === $term ) {
				$prefix[ $name ] = $label;
			} elseif ( $name === $term ) {
				$exact[ $name ] = $label;
			} elseif ( 0 === strpos( $name, $term ) ) {
				$prefix[ $name ] = $label;
			} elseif ( false !== strpos( $name, $term ) || false !== stripos( $label, $term ) ) {
				$rest[ $name ] = $label;
			}

			if ( count( $exact ) + count( $prefix ) + count( $rest ) >= self::SEARCH_LIMIT * 4 ) {
				// Enough to fill the list several times over; no point walking
				// the remaining 1,900 entries to sort them and throw them away.
				break;
			}
		}

		$matches = array_slice( $exact + $prefix + $rest, 0, self::SEARCH_LIMIT, true );
		$results = [];

		foreach ( $matches as $name => $label ) {
			$results[] = [
				'id'   => $name,

				// The label alone. Core's select2 template prepends
				// `<i class="fas fa-fw fa-{id}">` itself, which the admin shim
				// then turns into an SVG -- so the preview costs nothing here.
				'text' => $label,
			];
		}

		return [ 'data' => $results ];
	}

	/**
	 * URL an icon field should point its `source` argument at.
	 *
	 * A field with a source loads its options over AJAX instead of printing all
	 * 1,918 as markup. That matters more than it sounds: with nine pickers on
	 * one screen the inline form was 2 MB of HTML, which is worse than the
	 * webfont this library removed.
	 *
	 * @return string
	 */
	public static function picker_source() {
		return rest_url( 'fafh/v1/icons' );
	}

	/**
	 * Field arguments for an icon picker.
	 *
	 * Use in place of `'options' => FAFH::choices()`. The empty options array is
	 * deliberate -- filter_field_options() puts the saved icon back so the field
	 * shows what is currently chosen rather than an empty box.
	 *
	 * @param array $args Extra arguments to merge in.
	 * @return array
	 */
	public static function picker_field( $args = [] ) {
		return array_merge(
			[
				'type'       => 'select',
				'options'    => [],
				'source'     => self::picker_source(),
				'attributes' => [ 'data-template' => 'icon' ],
			],
			(array) $args
		);
	}

	/**
	 * Registers the filter that puts a saved icon back into a sourced field.
	 *
	 * Called once, by FAFH_Loader, on the copy that won arbitration.
	 */
	public static function register_picker() {
		add_filter( 'hivepress/v1/fields/field/options', [ __CLASS__, 'filter_field_options' ], 20, 2 );
	}

	/**
	 * Adds the currently saved icon to a sourced icon field's options.
	 *
	 * A field with a `source` prints no options, so without this the saved value
	 * has nothing to match and select2 renders an empty control -- the setting
	 * looks unset even though it is not, and saving the form would clear it.
	 *
	 * It REPLACES the options rather than adding to them, which matters. An icon
	 * field has to declare `'options' => 'icons'` as a string, because core reads
	 * that argument as a preset NAME whenever a source is set
	 * (components/class-form.php:59-72 and :106) and hands an array straight to
	 * get_config(), which fatals with "Illegal offset type". Core therefore
	 * resolves the preset and hands us its whole 1,000-name list; keeping it
	 * would print every one of them and undo the point of the source.
	 *
	 * @param array  $options Field options.
	 * @param object $field   Field object.
	 * @return array
	 */
	public static function filter_field_options( $options, $field ) {
		if ( ! is_object( $field ) || ! method_exists( $field, 'get_arg' ) ) {
			return $options;
		}

		if ( self::picker_source() !== $field->get_arg( 'source' ) ) {
			return $options;
		}

		/*
		 * The empty option comes first, ALWAYS, and this is not cosmetic.
		 *
		 * HivePress's Repeater field holds one field object per column and reuses it for every row
		 * (fields/class-repeater.php, render() and sanitize()). set_value() runs this filter only
		 * when the row's value is not null, so a row whose icon is empty skips it - and the shared
		 * object still carries the options the PREVIOUS row left in it. Before this fix that was a
		 * single option, the previous row's icon, and a select with one option and nothing marked
		 * selected submits that option. Every empty row below a chosen icon inherited it on the next
		 * save. Account Menu Enhancer 3.4.0 shipped exactly that on 2026-09-02: five custom items
		 * came back as "stripe-s", and the release was pulled.
		 *
		 * With the empty option first, an empty row renders it selected by default and submits
		 * nothing, whatever the previous row chose. Core's own Select puts this option in at boot
		 * (fields/class-select.php, boot()); replacing the list wholesale had thrown it away.
		 *
		 * The placeholder text is the field's own, falling back to the dash core uses.
		 */
		$placeholder = $field->get_arg( 'placeholder' );
		$choices     = [ '' => is_string( $placeholder ) && '' !== $placeholder ? $placeholder : '&mdash;' ];

		$value = $field->get_value();

		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return $choices;
		}

		$value = self::clean( $value );
		$label = self::label( $value );

		$choices[ $value ] = $label ? $label : $value;

		return $choices;
	}

	/**
	 * Answers a batch of icon names with their glyph data.
	 *
	 * Logged-in only (wp_ajax_ with no nopriv twin), nonce-checked, and capped
	 * at AJAX_LIMIT names. The data itself is not sensitive -- it is bundled
	 * Font Awesome Free, the same paths the front end already ships in its
	 * markup -- so the checks are about not offering an unauthenticated way to
	 * make the server do work, rather than about protecting the icons.
	 */
	public static function ajax_icons() {
		check_ajax_referer( 'fafh_icons', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		$names = isset( $_POST['names'] ) ? wp_unslash( $_POST['names'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in the loop below, which is where the shape is known.

		if ( ! is_array( $names ) ) {
			wp_send_json_error( [ 'message' => 'bad request' ], 400 );
		}

		$wanted = [];

		foreach ( array_slice( $names, 0, self::AJAX_LIMIT ) as $name ) {
			if ( ! is_scalar( $name ) ) {
				continue;
			}

			$name = sanitize_key( (string) $name );

			if ( '' !== $name ) {
				$wanted[] = $name;
			}
		}

		wp_send_json_success( [ 'icons' => self::map( $wanted ) ] );
	}

	// ---------------------------------------------------------------------
	// Data.
	// ---------------------------------------------------------------------

	/**
	 * "viewBox" and path data for one icon.
	 *
	 * @param string      $name  Canonical icon name.
	 * @param string|null $style Style to force, or null to resolve by name.
	 * @return array|null [ view_box, path ], or null if not found.
	 */
	private static function glyph( $name, $style = null ) {
		$styles = $style ? [ $style ] : self::styles( $name );

		foreach ( $styles as $candidate ) {
			$glyphs = self::data( $candidate . '/' . self::shard( $name ) . '.json' );

			if ( isset( $glyphs[ $name ] ) ) {
				$parts = explode( '|', $glyphs[ $name ], 2 );

				if ( 2 === count( $parts ) ) {
					return $parts;
				}
			}
		}

		return null;
	}

	/**
	 * Index entry for an icon, resolving aliases.
	 *
	 * @param string $name Icon name, canonical or aliased.
	 * @return array|null [ style codes, label ], or null if unknown.
	 */
	private static function entry( $name ) {
		$index = self::index();
		$name  = self::resolve( $name );

		return isset( $index[ $name ] ) ? $index[ $name ] : null;
	}

	/**
	 * Reads and decodes one data file, once per request.
	 *
	 * @param string $file Basename inside data/.
	 * @return array
	 */
	private static function data( $file ) {
		if ( isset( self::$data[ $file ] ) ) {
			return self::$data[ $file ];
		}

		self::$data[ $file ] = [];

		$path = self::$dir . '/data/' . $file;

		if ( ! self::$dir || ! is_readable( $path ) ) {
			return self::$data[ $file ];
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled data file, not a remote request.

		if ( false === $raw ) {
			return self::$data[ $file ];
		}

		$decoded = json_decode( $raw, true );

		if ( is_array( $decoded ) ) {
			self::$data[ $file ] = $decoded;
		}

		return self::$data[ $file ];
	}

	/**
	 * Data shard an icon's glyph lives in.
	 *
	 * Glyph maps are split on the name's first character so that drawing a
	 * handful of icons decodes a few KB instead of the whole style. Must match
	 * shard_by_initial() in tools/build-fafh.py.
	 *
	 * @param string $name Canonical icon name.
	 * @return string Shard name.
	 */
	private static function shard( $name ) {
		$initial = substr( (string) $name, 0, 1 );

		return ( $initial >= 'a' && $initial <= 'z' ) ? $initial : '_';
	}

	/**
	 * Whether two style-code strings share any character.
	 *
	 * @param string $codes  Codes on the icon.
	 * @param string $wanted Codes being asked for.
	 * @return bool
	 */
	private static function intersects( $codes, $wanted ) {
		$length = strlen( $wanted );

		for ( $i = 0; $i < $length; $i++ ) {
			if ( false !== strpos( $codes, $wanted[ $i ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalises a name or class token.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private static function clean( $token ) {
		return strtolower( trim( (string) $token ) );
	}
}
