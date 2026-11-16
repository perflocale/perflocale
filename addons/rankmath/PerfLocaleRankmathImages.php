<?php
/**
 * Rank Math social images on a new translation; loaded on first use.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points a new translation's Facebook and X image at the attachment's copy in the target language.
 *
 * Rule: when the copied image id has a linked attachment in that language, the id and URL become the copy's; otherwise
 * both keep the source values. Rank Math renders og:image and twitter:image from the id.
 */
final class PerfLocaleRankmathImages {
	/**
	 * Image name => [ image id key, image URL key ].
	 */
	private const KEYS = [
		'facebook' => [ 'rank_math_facebook_image_id', 'rank_math_facebook_image' ],
		'twitter'  => [ 'rank_math_twitter_image_id', 'rank_math_twitter_image' ],
	];

	/**
	 * Map the image keys create_translation() copied to the target language's attachments.
	 *
	 * @param int                                              $post_id     New translation post ID.
	 * @param string                                           $target_slug Target language slug.
	 * @param int                                              $source_id   Source post ID.
	 * @param \PerfLocale\Translation\PostTranslationManager|null $manager     Built on first need when null.
	 * @return void
	 */
	public static function map( int $post_id, string $target_slug, int $source_id, ?\PerfLocale\Translation\PostTranslationManager $manager = null ): void {
		$image_ids = [];

		foreach ( self::KEYS as $name => [ $id_key ] ) {
			$image_id = get_post_meta( $post_id, $id_key, true );

			if ( is_numeric( $image_id ) && (int) $image_id > 0 ) {
				$image_ids[ $name ] = (int) $image_id;
			}
		}

		if ( [] === $image_ids ) {
			return;
		}

		$context = [
			'source_id'      => $source_id,
			'translation_id' => $post_id,
			'target_slug'    => $target_slug,
			'images'         => array_keys( $image_ids ),
		];

		/**
		 * Whether a new translation's Rank Math Facebook and X images point at their copies in its language.
		 *
		 * False keeps the source's image ids and URLs. Runs once per new post translation that has one of these images.
		 *
		 * @hook  perflocale/rankmath/translate_social_images
		 * @since 1.0.8
		 *
		 * @param bool                                                                                $translate Default true.
		 * @param array{source_id: int, translation_id: int, target_slug: string, images: list<string>} $context   Images: 'facebook', 'twitter'.
		 * @return bool
		 */
		if ( ! self::bool_or_default( apply_filters( 'perflocale/rankmath/translate_social_images', true, $context ) ) ) {
			return;
		}

		if ( null === $manager ) {
			$plugin  = \PerfLocale\Plugin::get_instance();
			$manager = new \PerfLocale\Translation\PostTranslationManager( $plugin->get( 'cache' ), $plugin->get( 'settings' ) );
		}

		foreach ( $image_ids as $name => $image_id ) {
			$copy_id  = $manager->attachment_in_language( $image_id, $target_slug );
			$copy_url = $copy_id !== $image_id ? wp_get_attachment_url( $copy_id ) : false;

			if ( is_string( $copy_url ) && '' !== $copy_url ) {
				update_post_meta( $post_id, self::KEYS[ $name ][0], (string) $copy_id );
				update_post_meta( $post_id, self::KEYS[ $name ][1], wp_slash( $copy_url ) );
			}
		}
	}

	/**
	 * The filter's result; any non-bool is reported and counts as the default, true.
	 *
	 * @param mixed $returned What the callbacks returned.
	 * @return bool
	 */
	private static function bool_or_default( $returned ): bool {
		return is_bool( $returned ) ? $returned : \PerfLocale\Util\FilterReturn::rejected( 'perflocale/rankmath/translate_social_images', $returned, true );
	}
}
