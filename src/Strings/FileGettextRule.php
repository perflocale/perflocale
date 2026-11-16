<?php
/**
 * Files mode's gettext attach rule, required by TranslationFileLoader on first use.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Strings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TranslationFileLoader asks this only on an AJAX request or with an attach filter.
 */
final class FileGettextRule {

	/**
	 * StringTranslation::should_attach_gettext( $gettext_rows, true ) (StringFrontEndDomainsTest holds the two equal).
	 *
	 * @param bool $gettext_rows Whether a loaded file is of a gettext domain.
	 * @return bool
	 */
	public static function should_attach( bool $gettext_rows ): bool {
		if ( is_admin() && wp_doing_ajax() && StringTranslation::referer_is_admin() ) {
			return false;
		}

		/** This filter is documented in src/Strings/StringTranslation.php */
		$mode = apply_filters( 'perflocale/strings/attach_gettext', 'auto' );

		return true === $mode || ( false !== $mode && $gettext_rows );
	}
}
