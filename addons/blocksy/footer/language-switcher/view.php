<?php
/**
 * Blocksy footer builder - Language Switcher element view.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$perflocale_class = 'ct-footer-language-switcher';

if ( function_exists( 'blocksy_default_akg' ) ) {
	$perflocale_visibility = blocksy_default_akg(
		'visibility',
		$atts,
		[
			'tablet' => true,
			'mobile' => true,
		]
	);

	if ( function_exists( 'blocksy_visibility_classes' ) ) {
		$perflocale_class .= ' ' . blocksy_visibility_classes( $perflocale_visibility );
	}
}

$perflocale_style             = function_exists( 'blocksy_default_akg' ) ? blocksy_default_akg( 'perflocale_switcher_style', $atts, 'flags_names' ) : 'flags_names';
$perflocale_display           = function_exists( 'blocksy_default_akg' ) ? blocksy_default_akg( 'perflocale_switcher_display', $atts, 'inline' ) : 'inline';
$perflocale_layout            = function_exists( 'blocksy_default_akg' ) ? blocksy_default_akg( 'perflocale_switcher_layout', $atts, 'horizontal' ) : 'horizontal';
$perflocale_name_format       = function_exists( 'blocksy_default_akg' ) ? blocksy_default_akg( 'perflocale_switcher_name_format', $atts, 'native' ) : 'native';
$perflocale_show_flags        = function_exists( 'blocksy_default_akg' ) ? blocksy_default_akg( 'perflocale_switcher_show_flags', $atts, 'yes' ) === 'yes' : true;
$perflocale_show_names        = function_exists( 'blocksy_default_akg' ) ? blocksy_default_akg( 'perflocale_switcher_show_names', $atts, 'yes' ) === 'yes' : true;
$perflocale_hide_current      = function_exists( 'blocksy_default_akg' ) ? blocksy_default_akg( 'perflocale_switcher_hide_current', $atts, 'no' ) === 'yes' : false;
$perflocale_show_untranslated = function_exists( 'blocksy_default_akg' ) ? blocksy_default_akg( 'perflocale_switcher_show_untranslated', $atts, 'no' ) === 'yes' : false;
$perflocale_arrow_style       = function_exists( 'blocksy_default_akg' ) ? blocksy_default_akg( 'perflocale_switcher_arrow_style', $atts, '' ) : '';
$perflocale_trigger_format    = function_exists( 'blocksy_default_akg' ) ? blocksy_default_akg( 'perflocale_switcher_trigger_format', $atts, '' ) : '';

$perflocale_block = new PerfLocale\Frontend\LanguageSwitcherBlock();

$perflocale_render_args = [
	'display'          => $perflocale_display,
	'style'            => $perflocale_style,
	'layout'           => $perflocale_layout,
	'nameFormat'       => $perflocale_name_format,
	'showFlags'        => $perflocale_show_flags,
	'showNames'        => $perflocale_show_names,
	'hideCurrent'      => $perflocale_hide_current,
	'showUntranslated' => $perflocale_show_untranslated,
	'fontSize'         => 14,
	'flagSize'         => 18,
	'gap'              => 8,
];

if ( $perflocale_arrow_style !== '' ) {
	$perflocale_render_args['arrowStyle'] = $perflocale_arrow_style;
}

if ( $perflocale_trigger_format !== '' ) {
	$perflocale_render_args['triggerFormat'] = $perflocale_trigger_format;
}

$perflocale_html = $perflocale_block->render( $perflocale_render_args );
?>
<div class="<?php echo esc_attr( $perflocale_class ); ?>">
	<?php echo PerfLocale\Frontend\LanguageSwitcherBlock::kses_switcher( $perflocale_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG-aware kses allowlist. ?>
</div>
