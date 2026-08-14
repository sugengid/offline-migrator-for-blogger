<?php
/**
 * Parses a Blogger feed.atom export into plain item arrays.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BM_Parser {

	const NS_ATOM    = 'http://www.w3.org/2005/Atom';
	const NS_BLOGGER = 'http://schemas.google.com/blogger/2018';

	/**
	 * Parse a feed.atom file into a list of items.
	 *
	 * @param string $file_path Absolute path to feed.atom.
	 * @return array|WP_Error List of item arrays, or WP_Error when the file cannot be parsed.
	 */
	public function parse( $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			/* translators: %s: absolute path of the feed file. */
			return new WP_Error( 'bm_feed_unreadable', sprintf( __( 'File feed tidak bisa dibaca: %s', 'bloggermigrator' ), $file_path ) );
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_file( $file_path, 'SimpleXMLElement', LIBXML_NONET );
		libxml_use_internal_errors( $previous );

		if ( false === $xml ) {
			return new WP_Error( 'bm_feed_invalid', __( 'File feed bukan XML yang valid.', 'bloggermigrator' ) );
		}

		$items = array();
		foreach ( $xml->entry as $entry ) {
			$item = $this->parse_entry( $entry );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Map one Atom entry to an item array. Returns null for entries that must be skipped.
	 *
	 * @param SimpleXMLElement $entry Atom entry element.
	 * @return array|null
	 */
	private function parse_entry( $entry ) {
		$b = $entry->children( self::NS_BLOGGER );

		$type   = strtoupper( trim( (string) $b->type ) );
		$status = strtoupper( trim( (string) $b->status ) );

		if ( '' === $type ) {
			$type = 'POST';
		}
		if ( '' === $status ) {
			$status = 'LIVE';
		}

		if ( 'TRASHED' === $status ) {
			return null;
		}

		$filename = trim( (string) $b->filename );

		return array(
			'type'        => strtolower( $type ),
			'status'      => $status,
			'id'          => trim( (string) $entry->id ),
			'title'       => (string) $entry->title,
			'content'     => (string) $entry->content,
			'published'   => trim( (string) $entry->published ),
			'created'     => trim( (string) $b->created ),
			'parent'      => trim( (string) $b->parent ),
			'in_reply_to' => trim( (string) $b->inReplyTo ),
			'filename'    => $filename,
			'slug'        => $this->slug_from_filename( $filename ),
			'author_name' => isset( $entry->author ) ? trim( (string) $entry->author->name ) : '',
			'author_url'  => isset( $entry->author ) ? trim( (string) $entry->author->uri ) : '',
			'categories'  => $this->parse_categories( $entry ),
		);
	}

	/**
	 * Collect label terms, dropping Blogger kind categories and empty terms.
	 *
	 * @param SimpleXMLElement $entry Atom entry element.
	 * @return string[]
	 */
	private function parse_categories( $entry ) {
		$terms = array();
		foreach ( $entry->category as $category ) {
			$term = trim( (string) $category['term'] );
			if ( '' === $term || false !== strpos( $term, 'schemas.google.com' ) ) {
				continue;
			}
			$terms[] = $term;
		}
		return array_values( array_unique( $terms ) );
	}

	/**
	 * Derive the post slug from a Blogger filename path like /2024/03/slug.html.
	 *
	 * @param string $filename Blogger filename path.
	 * @return string Slug, or empty string when there is no filename.
	 */
	private function slug_from_filename( $filename ) {
		if ( '' === $filename ) {
			return '';
		}
		return basename( $filename, '.html' );
	}
}
