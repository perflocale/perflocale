<?php
/**
 * Beaver Builder Language Switcher module - frontend output.
 *
 * @package PerfLocale
 * @var object $module Module instance.
 * @var object $settings Module settings.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\PerfLocale\Frontend\LanguageSwitcherBlock' ) ) {
	return;
}

$perflocale_block = new \PerfLocale\Frontend\LanguageSwitcherBlock();
$perflocale_style = $settings->style ?? 'flags_names';

$perflocale_render_args = [
	'display'          => $settings->display ?? 'inline',
	'style'            => $perflocale_style,
	'layout'           => $settings->layout ?? 'horizontal',
	'nameFormat'       => $settings->name_format ?? 'native',
	'showFlags'        => $perflocale_style !== 'names_only',
	'showNames'        => $perflocale_style !== 'flags_only',
	'hideCurrent'      => ( $settings->hide_current ?? 'no' ) === 'yes',
	'showUntranslated' => ( $settings->show_untranslated ?? 'no' ) === 'yes',
	'fontSize'         => (int) ( $settings->font_size ?? 14 ),
	'flagSize'         => (int) ( $settings->flag_size ?? 20 ),
	'gap'              => (int) ( $settings->gap ?? 8 ),
	'className'        => sanitize_html_class( $settings->custom_class ?? '' ),
];

$perflocale_trigger_format = sanitize_key( $settings->trigger_format ?? '' );
if ( $perflocale_trigger_format !== '' ) {
	$perflocale_render_args['triggerFormat'] = $perflocale_trigger_format;
}

$perflocale_arrow_style = sanitize_key( $settings->arrow_style ?? '' );
if ( $perflocale_arrow_style !== '' ) {
	$perflocale_render_args['arrowStyle'] = $perflocale_arrow_style;
}

$perflocale_html = $perflocale_block->render( $perflocale_render_args );

echo PerfLocale\Frontend\LanguageSwitcherBlock::kses_switcher( $perflocale_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG-aware kses allowlist.
