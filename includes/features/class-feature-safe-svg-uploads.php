<?php
/**
 * Feature: Safe Admin & Shop Manager SVG Uploads.
 *
 * Allows administrators (`manage_options`) and WooCommerce shop managers (`manage_woocommerce`)
 * to upload `.svg` vector files in the WordPress Media Library while inspecting uploaded
 * SVG payloads via `wp_handle_upload_prefilter` to block XSS vectors (`<script`, `onload=`,
 * `onerror=`, `javascript:`, `<iframe`, `<foreignObject`). Also fixes SVG thumbnail previews
 * in the Media Library.
 *
 * @package WooSoyooTools
 */

namespace SOYOO\Tools\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feature_Safe_Svg_Uploads
 */
class Feature_Safe_Svg_Uploads {

	/**
	 * Register feature hooks.
	 */
	public static function register() {
		add_filter( 'upload_mimes', array( __CLASS__, 'allow_svg_mime_for_privileged_users' ), 10, 1 );
		add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'fix_svg_filetype_detection' ), 10, 5 );
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'validate_and_scan_svg_upload' ), 10, 1 );
		add_action( 'admin_head', array( __CLASS__, 'output_media_library_svg_styles' ) );
	}

	/**
	 * Check whether the current user is allowed to upload SVG files.
	 *
	 * @return bool
	 */
	public static function current_user_can_upload_svg() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Register `image/svg+xml` MIME type only for Administrators and Shop Managers.
	 *
	 * @param array $mimes Allowed MIME types.
	 * @return array
	 */
	public static function allow_svg_mime_for_privileged_users( $mimes ) {
		if ( ! is_array( $mimes ) ) {
			$mimes = array();
		}

		if ( self::current_user_can_upload_svg() ) {
			$mimes['svg'] = 'image/svg+xml';
		}

		return $mimes;
	}

	/**
	 * Fix WordPress 6.x `wp_check_filetype_and_ext` detection for `.svg` files
	 * (which may report `image/svg` or empty type when XML declaration is omitted).
	 *
	 * @param array         $data      Values for `ext`, `type`, and `proper_filename`.
	 * @param string        $file      Full path to the file.
	 * @param string        $filename  The name of the file.
	 * @param string[]|null $mimes     Array of mime types keyed by their file extension regex.
	 * @param string|false  $real_mime The actual mime type or false if the type cannot be determined.
	 * @return array
	 */
	public static function fix_svg_filetype_and_ext( $data, $file, $filename, $mimes = null, $real_mime = false ) {
		return self::fix_svg_filetype_detection( $data, $file, $filename, $mimes, $real_mime );
	}

	/**
	 * Fix WordPress 6.x `wp_check_filetype_and_ext` detection for `.svg` files.
	 *
	 * @param array         $data      Values for `ext`, `type`, and `proper_filename`.
	 * @param string        $file      Full path to the file.
	 * @param string        $filename  The name of the file.
	 * @param string[]|null $mimes     Array of mime types keyed by their file extension regex.
	 * @param string|false  $real_mime The actual mime type or false if the type cannot be determined.
	 * @return array
	 */
	public static function fix_svg_filetype_detection( $data, $file, $filename, $mimes = null, $real_mime = false ) {
		if ( ! self::current_user_can_upload_svg() ) {
			return $data;
		}

		$ext = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		if ( 'svg' === $ext ) {
			$data['ext']  = 'svg';
			$data['type'] = 'image/svg+xml';
		}

		return $data;
	}

	/**
	 * Inspect the raw content of uploaded `.svg` files before they are moved to `wp-content/uploads`.
	 * Rejects files that lack an `<svg` root tag or contain XSS vectors.
	 *
	 * @param array $file Uploaded file array (`name`, `type`, `tmp_name`, `error`, `size`).
	 * @return array
	 */
	public static function validate_and_scan_svg_upload( $file ) {
		if ( ! is_array( $file ) || empty( $file['name'] ) ) {
			return $file;
		}

		$ext  = strtolower( (string) pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		$type = isset( $file['type'] ) ? strtolower( (string) $file['type'] ) : '';

		if ( 'svg' !== $ext && 'image/svg+xml' !== $type && 'image/svg' !== $type ) {
			return $file;
		}

		if ( ! self::current_user_can_upload_svg() ) {
			$file['error'] = __( 'Seuls les administrateurs et gestionnaires de boutique sont autorisés à téléverser des fichiers SVG.', 'woo-soyoo-tools' );
			return $file;
		}

		if ( empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			$file['error'] = __( 'Impossible de vérifier le contenu du fichier SVG téléversé.', 'woo-soyoo-tools' );
			return $file;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $file['tmp_name'] );
		if ( false === $content || '' === trim( $content ) ) {
			$file['error'] = __( 'Le fichier SVG téléversé est vide ou illisible.', 'woo-soyoo-tools' );
			return $file;
		}

		// 1. Must contain an opening `<svg` tag.
		if ( false === stripos( $content, '<svg' ) ) {
			$file['error'] = __( 'Fichier SVG refusé : aucune balise <svg> valide détectée.', 'woo-soyoo-tools' );
			return $file;
		}

		// 2. Check for forbidden XSS / active content tokens.
		$forbidden_tokens = array(
			'<script',
			'onload=',
			'onerror=',
			'javascript:',
			'<iframe',
			'<foreignObject',
		);

		foreach ( $forbidden_tokens as $token ) {
			if ( false !== stripos( $content, $token ) ) {
				$file['error'] = sprintf(
					/* translators: %s: detected unsafe token */
					__( 'Fichier SVG bloqué par sécurité SOYOO : contenu potentiellement dangereux détecté (%s).', 'woo-soyoo-tools' ),
					$token
				);
				return $file;
			}
		}

		// 3. Also catch inline JS event handlers with whitespace variations (e.g., `onload =`, `onclick=`).
		if ( preg_match( '/\bon[a-z]+\s*=/i', $content ) ) {
			$file['error'] = __( 'Fichier SVG bloqué par sécurité SOYOO : attribut d\'événement JavaScript détecté.', 'woo-soyoo-tools' );
			return $file;
		}

		return $file;
	}

	/**
	 * Output lightweight CSS in `admin_head` so SVG thumbnails render properly in the WordPress Media Library.
	 */
	public static function output_media_library_svg_styles() {
		?>
		<style id="woo-soyoo-tools-svg-admin-css">
			.attachment-266x266,
			.thumbnail img[src$=".svg"],
			.media-icon img[src$=".svg"],
			#postimagediv .inside img[src$=".svg"] {
				width: 100% !important;
				height: auto !important;
			}
			.media-frame .attachment .thumbnail img[src$=".svg"] {
				object-fit: contain;
				padding: 8px;
			}
		</style>
		<?php
	}
}
