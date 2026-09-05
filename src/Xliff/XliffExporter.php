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
 * Exports WordPress post content as XLIFF 2.0 format.
 *
 * Creates translation units for title, content, and excerpt of each post.
 */
final class XliffExporter {

	/**
	 * Export posts as XLIFF 2.0 XML string.
	 *
	 * @param array<int> $post_ids Post IDs to export.
	 * @param string     $source_lang Source language code.
	 * @param string     $target_lang Target language code.
	 * @return string XLIFF XML content.
	 */
	public function export( array $post_ids, string $source_lang, string $target_lang ): string {
		// Stream-build via XMLWriter into memory so very large exports don't
		// hold the entire DOM tree + its duplicate serialization simultaneously.
		$writer = new \XMLWriter();
		$writer->openMemory();
		$writer->setIndent( true );
		$writer->setIndentString( ' ' );

		$this->write_xliff( $writer, $post_ids, $source_lang, $target_lang );

		return $writer->outputMemory();
	}

	/**
	 * Shared XLIFF writer - serialises the xliff/file/unit tree via XMLWriter.
	 *
	 * Releases each post from the object cache after writing so the peak
	 * memory footprint is bounded by one post, not the full export set.
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
		// XLIFF 2.0 §3.1 mandates BCP 47 language tags for srcLang / trgLang.
		// Caller passes the raw `lang->slug` (lowercase, e.g. `en-gb`); CAT
		// tools and validators expect the canonical mixed-case form.
		$writer->writeAttribute( 'srcLang', \PerfLocale\Helper::format_locale_as_bcp47( $source_lang ) );
		$writer->writeAttribute( 'trgLang', \PerfLocale\Helper::format_locale_as_bcp47( $target_lang ) );

		// Prime the post cache in ONE query instead of one-per-post inside the
		// loop - big exports of 50+ posts otherwise issue 50+ SELECTs against
		// wp_posts. `update_meta_cache`=false because we only read title/
		// content/excerpt from the post row itself.
		// Chunked (100/query), NOT one prime over the whole set: front-loading
		// every full post row into the object cache before the loop starts
		// defeats the per-post clean_post_cache() below, so peak memory grew
		// with the export size instead of staying bounded by one chunk.
		foreach ( array_chunk( array_map( 'intval', $post_ids ), 100 ) as $chunk ) {
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

				// Drop the post from the cache so memory stays flat across the loop.
				clean_post_cache( $post_id );
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
	 * Write text into a CDATA section, safely handling the ']]>' terminator.
	 *
	 * A CDATA section cannot contain the literal sequence ']]>'; content that
	 * does (code samples, shortcodes, escaped markup) would otherwise close the
	 * section early and produce malformed XLIFF no importer can parse.
	 * Splitting each occurrence across two sections (']]]]><![CDATA[>') keeps
	 * the document well-formed while preserving the exact bytes.
	 *
	 * @param \XMLWriter $writer Writer.
	 * @param string     $text   Raw text to wrap in CDATA.
	 * @return void
	 */
	private function write_cdata( \XMLWriter $writer, string $text ): void {
		// XML 1.0 forbids C0 control characters (below 0x20 except tab/LF/CR)
		// even inside CDATA. Post content can carry a stray \x0B etc.; passing
		// it through writeRaw produced a document neither the importer nor any
		// CAT tool could parse. Strip every char outside the legal XML range
		// (wp_check_invalid_utf8 first so preg_replace can't null on bad UTF-8).
		//
		// The allowed set is XML 1.0's Char production verbatim:
		// #x9 | #xA | #xD | [#x20-#xD7FF] | [#xE000-#xFFFD] | [#x10000-#x10FFFF].
		// The last alternative was missing, so every supplementary-plane
		// character — emoji, rare CJK, Deseret, Gothic, mathematical
		// alphanumerics — was silently deleted from the export. The result
		// stayed well-formed, which is exactly why a well-formedness check
		// never caught it. U+FFFE / U+FFFF remain excluded, as XML requires.
		//
		// Deliberately NOT passing $strip = true here: on the declared 6.4
		// floor that branch returns iconv()'s false on PHP 8, and this file
		// declares strict_types=1, so the preg_replace() below would throw a
		// TypeError out of the REST handler on any input with one bad byte.
		$text = wp_check_invalid_utf8( $text );
		$text = (string) preg_replace( '/[^\x{09}\x{0A}\x{0D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );

		$writer->writeRaw( '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $text ) . ']]>' );
	}
}
