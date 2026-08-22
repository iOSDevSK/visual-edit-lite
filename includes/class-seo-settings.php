<?php
/**
 * "Search &amp; Sharing" settings: the handful of facts that are true of the whole
 * site rather than of one page.
 *
 * These options already existed — the importer seeds them from the schema.org
 * block the original design carried, and the exporter carries them to the next
 * site. What was missing was any way for the owner to see or change them, which
 * made for an absurd situation: the readiness report asked for a logo the owner
 * had no screen to provide.
 *
 * Small on purpose. Per-page wording belongs in the Visual Editor's Search
 * appearance panel, next to the page it describes; this screen is only for the
 * things that would otherwise have to be repeated on every page.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_SEO_Settings {

	const PAGE  = 'visual-edit-seo';
	const GROUP = 'clara_ve_seo_settings';

	/**
	 * schema.org types offered for the business.
	 *
	 * A short curated list, not the whole vocabulary. Choosing between hundreds
	 * of subtypes is the kind of decision that produces a worse answer the more
	 * options it is given, and "Organization" is never wrong.
	 */
	const TYPES = array(
		'Organization'        => 'Organization — any company or group',
		'LocalBusiness'       => 'Local business — has premises customers visit',
		'ProfessionalService' => 'Professional service — consultancy, studio, agency',
		'Person'              => 'Person — a personal brand or sole practitioner',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function register_page() {
		add_submenu_page(
			'visual-edit',
			__( 'SEO &amp; Sharing', 'visual-edit-lite' ),
			__( 'SEO &amp; Sharing', 'visual-edit-lite' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The media library, for picking the logo and the default share image.
	 *
	 * @param string $hook
	 */
	public static function enqueue( $hook ) {
		if ( false !== strpos( (string) $hook, self::PAGE ) ) {
			wp_enqueue_media();
		}
	}

	public static function register_settings() {
		$text = array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		);
		$url  = array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		);

		register_setting( self::GROUP, Clara_VE_SEO::OPT_ENTITY_NAME, $text );
		register_setting( self::GROUP, Clara_VE_SEO::OPT_TITLE_SEPARATOR, $text );
		register_setting( self::GROUP, Clara_VE_SEO::OPT_ENTITY_LOGO, $url );
		register_setting( self::GROUP, Clara_VE_SEO::OPT_DEFAULT_OG, $url );
		register_setting(
			self::GROUP,
			Clara_VE_SEO::OPT_ENTITY_TYPE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_type' ),
				'default'           => 'Organization',
			)
		);
		register_setting(
			self::GROUP,
			Clara_VE_SEO::OPT_SAME_AS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_same_as' ),
				'default'           => array(),
			)
		);
		register_setting(
			self::GROUP,
			Clara_VE_GEO::OPT_AI_CRAWLERS,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_switch' ),
				'default'           => 'on',
			)
		);
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	public static function sanitize_type( $value ) {
		$value = sanitize_text_field( (string) $value );
		return isset( self::TYPES[ $value ] ) ? $value : 'Organization';
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	public static function sanitize_switch( $value ) {
		// An unchecked checkbox is ABSENT from the POST, and options.php then
		// hands this callback null. The obvious `'off' === $value ? 'off' : 'on'`
		// therefore turns "owner unchecked the box" into 'on' — a switch that can
		// be flipped one way only. Absence must read as off.
		return 'on' === $value ? 'on' : 'off';
	}

	/**
	 * One profile URL per line in, a clean array out.
	 *
	 * A textarea rather than repeatable fields: this is a list somebody pastes
	 * once and edits rarely, and a row of add/remove buttons is more machinery
	 * than the job needs.
	 *
	 * @param mixed $value
	 * @return string[]
	 */
	public static function sanitize_same_as( $value ) {
		if ( is_array( $value ) ) {
			$lines = $value;
		} else {
			$lines = preg_split( '/[\r\n]+/', (string) $value );
		}
		$out = array();
		foreach ( (array) $lines as $line ) {
			$url = esc_url_raw( trim( (string) $line ) );
			// Only absolute http(s) URLs: sameAs asserts "this profile IS this
			// business", and a relative path cannot make that claim.
			if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
				$out[] = $url;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'visual-edit-lite' ) );
		}

		$type    = (string) get_option( Clara_VE_SEO::OPT_ENTITY_TYPE, 'Organization' );
		$name    = (string) get_option( Clara_VE_SEO::OPT_ENTITY_NAME, '' );
		$logo    = (string) get_option( Clara_VE_SEO::OPT_ENTITY_LOGO, '' );
		$default = (string) get_option( Clara_VE_SEO::OPT_DEFAULT_OG, '' );
		$sep     = (string) get_option( Clara_VE_SEO::OPT_TITLE_SEPARATOR, '–' );
		$same_as = (array) get_option( Clara_VE_SEO::OPT_SAME_AS, array() );
		$crawl   = 'off' === get_option( Clara_VE_GEO::OPT_AI_CRAWLERS, 'on' ) ? 'off' : 'on';
		$host    = Clara_VE_SEO::host_label();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SEO &amp; Sharing', 'visual-edit-lite' ); ?></h1>
			<p class="description" style="max-width:44em;">
				<?php esc_html_e( 'What is true of the whole site. Each page\'s own title and description live in Visual Edit Lite, under Search appearance.', 'visual-edit-lite' ); ?>
			</p>

			<?php if ( $host ) : ?>
				<div class="notice notice-info inline" style="max-width:44em;">
					<p>
						<?php
						printf(
							/* translators: %s: SEO plugin name */
							esc_html__( '%s is installed and writes the page titles and descriptions. The settings here still apply: they describe the business itself, which it does not carry.', 'visual-edit-lite' ),
							esc_html( $host )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cve-seo-name"><?php esc_html_e( 'Business or personal name', 'visual-edit-lite' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="cve-seo-name"
								name="<?php echo esc_attr( Clara_VE_SEO::OPT_ENTITY_NAME ); ?>"
								value="<?php echo esc_attr( $name ); ?>"
								placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Left empty, the site title is used.', 'visual-edit-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cve-seo-type"><?php esc_html_e( 'What it is', 'visual-edit-lite' ); ?></label></th>
						<td>
							<select id="cve-seo-type" name="<?php echo esc_attr( Clara_VE_SEO::OPT_ENTITY_TYPE ); ?>">
								<?php foreach ( self::TYPES as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Taken from the original design where it said so. Changing it changes how search engines and AI assistants classify the business.', 'visual-edit-lite' ); ?></p>
						</td>
					</tr>
					<?php
					self::image_row(
						'cve-seo-logo',
						Clara_VE_SEO::OPT_ENTITY_LOGO,
						$logo,
						__( 'Logo', 'visual-edit-lite' ),
						__( 'Used in structured data, where a square image works best.', 'visual-edit-lite' )
					);
					self::image_row(
						'cve-seo-og',
						Clara_VE_SEO::OPT_DEFAULT_OG,
						$default,
						__( 'Default sharing image', 'visual-edit-lite' ),
						__( 'Shown when a page shared on social media has no image of its own. Without one, those posts render as an empty grey box.', 'visual-edit-lite' )
					);
					?>
					<tr>
						<th scope="row"><label for="cve-seo-sameas"><?php esc_html_e( 'Profiles elsewhere', 'visual-edit-lite' ); ?></label></th>
						<td>
							<textarea id="cve-seo-sameas" class="large-text code" rows="4"
								name="<?php echo esc_attr( Clara_VE_SEO::OPT_SAME_AS ); ?>"
								placeholder="https://instagram.com/yourname"><?php echo esc_textarea( implode( "\n", $same_as ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One address per line. This is how an AI assistant confirms the site belongs to a real, identifiable business rather than guessing.', 'visual-edit-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cve-seo-sep"><?php esc_html_e( 'Title separator', 'visual-edit-lite' ); ?></label></th>
						<td>
							<input type="text" class="small-text" id="cve-seo-sep"
								name="<?php echo esc_attr( Clara_VE_SEO::OPT_TITLE_SEPARATOR ); ?>"
								value="<?php echo esc_attr( $sep ); ?>" />
							<p class="description"><?php esc_html_e( 'Between a page name and the site name, on pages that have no title of their own.', 'visual-edit-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI assistants', 'visual-edit-lite' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Clara_VE_GEO::OPT_AI_CRAWLERS ); ?>" value="on" <?php checked( $crawl, 'on' ); ?> />
								<?php esc_html_e( 'Let AI assistants read this site, and tell them where the plain-text summary is', 'visual-edit-lite' ); ?>
							</label>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to llms.txt */
									esc_html__( 'Names the known assistant crawlers in robots.txt and points them at %s.', 'visual-edit-lite' ),
									'<a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener noreferrer">llms.txt</a>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * An image field with a media-library picker and a thumbnail.
	 *
	 * @param string $id
	 * @param string $option
	 * @param string $value
	 * @param string $label
	 * @param string $help
	 */
	private static function image_row( $id, $option, $value, $label, $help ) {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="url" class="regular-text" id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<button type="button" class="button cve-seo-pick" data-target="<?php echo esc_attr( $id ); ?>">
					<?php esc_html_e( 'Choose…', 'visual-edit-lite' ); ?>
				</button>
				<p class="description"><?php echo esc_html( $help ); ?></p>
				<img class="cve-seo-thumb" data-for="<?php echo esc_attr( $id ); ?>"
					src="<?php echo esc_url( $value ); ?>"
					style="max-width:180px;height:auto;margin-top:8px;<?php echo $value ? '' : 'display:none;'; ?>" alt="" />
			</td>
		</tr>
		<?php
		// Inline rather than a file: two dozen lines used on one screen, and a
		// separate asset would be one more thing to enqueue, version and ship.
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.cve-seo-pick' ) : null;
			if ( ! btn || ! window.wp || ! window.wp.media ) { return; }
			e.preventDefault();
			var input = document.getElementById( btn.dataset.target );
			var thumb = document.querySelector( '.cve-seo-thumb[data-for="' + btn.dataset.target + '"]' );
			var frame = wp.media( { library: { type: 'image' }, multiple: false, button: { text: 'Use this image' } } );
			frame.on( 'select', function () {
				var picked = frame.state().get( 'selection' ).first();
				if ( ! picked ) { return; }
				input.value = picked.get( 'url' );
				if ( thumb ) { thumb.src = input.value; thumb.style.display = ''; }
			} );
			frame.open();
		} );
		</script>
		<?php
	}
}

Clara_VE_SEO_Settings::init();
