<?php
/**
 * XLIFF 2.0 exporter.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Xliff;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports post titles, content and excerpts as XLIFF 2.0.
 */
final class XliffExporter {

	/**
	 * Posts loaded per statement.
	 *
	 * @var int
	 */
	private const CHUNK = 100;

	/**
	 * Export posts as XLIFF 2.0 XML string.
	 *
	 * @param array<int> $post_ids Post IDs to export.
	 * @param string     $source_lang Source language code.
	 * @param string     $target_lang Target language code.
	 * @return string XLIFF XML content.
	 *
	 * @throws \RuntimeException If PHP was built without ext-xmlwriter.
	 */
	public function export( array $post_ids, string $source_lang, string $target_lang ): string {
		// A build without ext-xmlwriter would throw an uncaught Error. Not XliffFormatException:
		// that one maps to 4xx (bad client XLIFF), a missing extension is a server fault.
		if ( ! class_exists( '\XMLWriter' ) ) {
			throw new \RuntimeException(
				esc_html__( 'XLIFF export needs the PHP xmlwriter extension, which is not installed on this server. Ask your host to enable the php-xml package.', 'perflocale' )
			);
		}

		// XMLWriter, not DOM: a large export never holds a tree beside its serialization.
		$writer = new \XMLWriter();
		$writer->openMemory();
		$writer->setIndent( true );
		$writer->setIndentString( ' ' );

		$this->write_xliff( $writer, $post_ids, $source_lang, $target_lang );

		return $writer->outputMemory();
	}

	/**
	 * Writes the xliff/file/unit tree.
	 *
	 * Never clean_post_cache() here: it would bump the shared posts last_changed key for a read.
	 * An export longer than one chunk releases each written chunk from the runtime cache.
	 *
	 * @param \XMLWriter $writer Writer.
	 * @param array<int> $post_ids Post IDs.
	 * @param string     $source_lang Source language.
	 * @param string     $target_lang Target language.
	 * @return void
	 */
	private function write_xliff( \XMLWriter $writer, array $post_ids, string $source_lang, string $target_lang ): void {
		$writer->startDocument( '1.0', 'UTF-8' );
		$writer->startElement( 'xliff' );
		$writer->writeAttribute( 'xmlns', 'urn:oasis:names:tc:xliff:document:2.0' );
		$writer->writeAttribute( 'version', '2.0' );
		// XLIFF 2.0 requires BCP 47 tags; the caller passes the lowercase slug (`en-gb`).
		$writer->writeAttribute( 'srcLang', \PerfLocale\Helper::format_locale_as_bcp47( $source_lang ) );
		$writer->writeAttribute( 'trgLang', \PerfLocale\Helper::format_locale_as_bcp47( $target_lang ) );

		// Each post once: a repeated id would repeat its whole <file>.
		$post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
		$release  = count( $post_ids ) > self::CHUNK;

		foreach ( array_chunk( $post_ids, self::CHUNK ) as $chunk ) {
			// One query per chunk; no meta cache, only post columns are read.
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $chunk, false, false );
			}

			foreach ( $chunk as $post_id ) {
				$post = get_post( $post_id );

				if ( ! $post ) {
					continue;
				}

				$writer->startElement( 'file' );
				$writer->writeAttribute( 'id', 'post-' . $post_id );
				$writer->writeAttribute( 'original', 'post-' . $post_id );

				$this->write_unit( $writer, "post-{$post_id}-title", $post->post_title );

				if ( $post->post_content !== '' ) {
					$this->write_unit( $writer, "post-{$post_id}-content", $post->post_content );
				}

				if ( $post->post_excerpt !== '' ) {
					$this->write_unit( $writer, "post-{$post_id}-excerpt", $post->post_excerpt );
				}

				$writer->endElement(); // file
			}

			if ( $release ) {
				\PerfLocale\Cache\CacheManager::release_runtime_posts( $chunk );
			}
		}

		$writer->endElement(); // xliff
		$writer->endDocument();
	}

	/**
	 * Write a single unit element via XMLWriter.
	 *
	 * @param \XMLWriter $writer Writer.
	 * @param string     $id Unit ID.
	 * @param string     $source Source text.
	 * @param string     $target Target text (optional).
	 * @return void
	 */
	private function write_unit( \XMLWriter $writer, string $id, string $source, string $target = '' ): void {
		$writer->startElement( 'unit' );
		$writer->writeAttribute( 'id', $id );
		$writer->startElement( 'segment' );

		$writer->startElement( 'source' );
		$this->write_cdata( $writer, $source );
		$writer->endElement();

		$writer->startElement( 'target' );

		if ( $target !== '' ) {
			$this->write_cdata( $writer, $target );
		}

		$writer->endElement(); // target
		$writer->endElement(); // segment
		$writer->endElement(); // unit
	}

	/**
	 * Writes text as CDATA; each ']]>' is split across two sections so the bytes survive.
	 *
	 * @param \XMLWriter $writer Writer.
	 * @param string     $text   Raw text to wrap in CDATA.
	 * @return void
	 */
	private function write_cdata( \XMLWriter $writer, string $text ): void {
		// Drop invalid UTF-8 bytes (no /u: it cannot run on invalid input), then chars outside XML 1.0 Char.
		// Not wp_check_invalid_utf8(): it empties the whole field on one bad byte; its $strip mode can return false.
		// See docs-ai/11-code-rationale.md, "XliffExporter: CDATA byte cleaning".
		$text = (string) preg_replace(
			'/([\x00-\x7F]'
			. '|[\xC2-\xDF][\x80-\xBF]'
			. '|\xE0[\xA0-\xBF][\x80-\xBF]'
			. '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
			. '|\xED[\x80-\x9F][\x80-\xBF]'
			. '|\xF0[\x90-\xBF][\x80-\xBF]{2}'
			. '|[\xF1-\xF3][\x80-\xBF]{3}'
			. '|\xF4[\x80-\x8F][\x80-\xBF]{2})|./s',
			'$1',
			$text
		);
		$text = (string) preg_replace( '/[^\x{09}\x{0A}\x{0D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );

		$writer->writeRaw( '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $text ) . ']]>' );
	}
}
