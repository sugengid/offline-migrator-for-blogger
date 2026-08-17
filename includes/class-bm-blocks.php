<?php
/**
 * Converts imported HTML content into Gutenberg block markup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMIG_Blocks {

	/**
	 * Convert an HTML fragment to block markup. Each top-level element maps to
	 * its block counterpart; unknown elements are wrapped in an HTML block so
	 * no content is lost. Returns the input unchanged when parsing fails.
	 *
	 * @param string $html HTML fragment.
	 * @return string Block markup.
	 */
	public static function convert( $html ) {
		if ( '' === trim( (string) $html ) ) {
			return $html;
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		// The encoding PI keeps multibyte characters intact; the wrapper div
		// gives the fragment a single root to iterate.
		$loaded   = $dom->loadHTML(
			'<?xml encoding="UTF-8"?><div id="bmig-block-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $html;
		}

		$root = null;
		foreach ( $dom->childNodes as $node ) {
			if ( $node instanceof DOMElement && 'bmig-block-root' === $node->getAttribute( 'id' ) ) {
				$root = $node;
				break;
			}
		}
		if ( ! $root ) {
			return $html;
		}

		$blocks = '';
		foreach ( $root->childNodes as $node ) {
			$blocks .= self::node_to_block( $dom, $node );
		}

		return '' === trim( $blocks ) ? $html : $blocks;
	}

	/**
	 * Convert the content of every imported post/page into block markup after
	 * images are in the media library, so image blocks can resolve their
	 * attachment id and local URL. Already converted content is left untouched
	 * to keep repeated runs idempotent.
	 *
	 * @return int Number of posts updated.
	 */
	public static function convert_imported_posts() {
		global $wpdb;

		$ids     = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bmig_source_id'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only lookup on a plugin-internal meta key.
		$updated = 0;

		foreach ( $ids as $post_id ) {
			$post_id = (int) $post_id;
			$content = get_post_field( 'post_content', $post_id );

			if ( false !== strpos( (string) $content, '<!-- wp:' ) ) {
				continue;
			}

			$blocks = self::convert( $content );
			if ( $blocks === $content ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $blocks ),
				)
			);
			$updated++;
		}

		return $updated;
	}

	/**
	 * Map one top-level node to its block markup.
	 *
	 * @param DOMDocument $dom  Owning document, used for serialization.
	 * @param DOMNode     $node Node to convert.
	 * @return string Block markup, empty for ignorable nodes.
	 */
	private static function node_to_block( DOMDocument $dom, $node ) {
		if ( $node instanceof DOMText ) {
			if ( '' === trim( $node->textContent ) ) {
				return '';
			}
			return self::wrap( 'paragraph', '<p>' . trim( $dom->saveHTML( $node ) ) . '</p>' );
		}

		if ( ! $node instanceof DOMElement ) {
			return '';
		}

		$image = self::wrapped_image_block( $dom, $node );
		if ( '' !== $image ) {
			return $image;
		}

		$tag   = strtolower( $node->nodeName );
		$inner = self::inner_html( $dom, $node );

		if ( preg_match( '/^h[1-6]$/', $tag ) ) {
			$level = (int) substr( $tag, 1 );
			return self::wrap(
				'heading {"level":' . $level . '}',
				'<' . $tag . '>' . $inner . '</' . $tag . '>'
			);
		}

		switch ( $tag ) {
			case 'p':
				return self::wrap( 'paragraph', '<p>' . $inner . '</p>' );
			case 'ul':
				return self::wrap( 'list', self::outer_html( $dom, $node ) );
			case 'ol':
				return self::wrap( 'list {"ordered":true}', self::outer_html( $dom, $node ) );
			case 'blockquote':
				return self::wrap( 'quote', '<blockquote class="wp-block-quote">' . $inner . '</blockquote>' );
			case 'pre':
				return self::code_block( $dom, $node, $inner );
			case 'table':
				return self::wrap( 'table', '<figure class="wp-block-table">' . self::outer_html( $dom, $node ) . '</figure>' );
			case 'img':
				return self::image_block( $dom, $node, '' );
			default:
				return self::wrap( 'html', self::outer_html( $dom, $node ) );
		}
	}

	/**
	 * Detect a wrapper element holding exactly one image and optional caption
	 * text (Blogger separator divs, image links, caption tables, figures) and
	 * convert it to an image block. Returns empty when the node is not an
	 * image-only wrapper, so it falls through to normal handling.
	 *
	 * @param DOMDocument $dom  Owning document.
	 * @param DOMElement  $node Node to inspect.
	 * @return string Image block markup, empty when not an image wrapper.
	 */
	private static function wrapped_image_block( DOMDocument $dom, DOMElement $node ) {
		$tag = strtolower( $node->nodeName );
		if ( ! in_array( $tag, array( 'div', 'p', 'a', 'table', 'figure', 'span' ), true ) ) {
			return '';
		}

		$imgs = $node->getElementsByTagName( 'img' );
		if ( 1 !== $imgs->length ) {
			return '';
		}

		$img         = $imgs->item( 0 );
		$caption_node = self::caption_node( $dom, $node );

		// Only convert when the rest of the wrapper is whitespace, so inline
		// text mixed with an image stays intact.
		$text = self::node_text_excluding( $node, array( $img, $caption_node ) );
		if ( '' !== trim( $text ) ) {
			return '';
		}

		$caption = $caption_node ? trim( $caption_node->textContent ) : '';
		return self::image_block( $dom, $img, $caption );
	}

	/**
	 * Find the caption element inside an image wrapper: a figcaption, or an
	 * element carrying the tr-caption class used by Blogger caption tables.
	 *
	 * @param DOMDocument $dom  Owning document.
	 * @param DOMElement  $node Wrapper node.
	 * @return DOMElement|null
	 */
	private static function caption_node( DOMDocument $dom, DOMElement $node ) {
		$figcaps = $node->getElementsByTagName( 'figcaption' );
		if ( $figcaps->length ) {
			return $figcaps->item( 0 );
		}

		$xpath   = new DOMXPath( $dom );
		$caption = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' tr-caption ')]", $node );
		if ( $caption && $caption->length ) {
			return $caption->item( 0 );
		}

		return null;
	}

	/**
	 * Concatenate the text of a node excluding a set of child nodes, so image
	 * wrappers can be checked for non-caption text.
	 *
	 * @param DOMNode    $node    Node to walk.
	 * @param array|null $exclude Nodes whose text must be ignored.
	 * @return string
	 */
	private static function node_text_excluding( DOMNode $node, array $exclude ) {
		if ( in_array( $node, $exclude, true ) ) {
			return '';
		}
		if ( $node instanceof DOMText ) {
			return $node->textContent;
		}
		if ( ! $node instanceof DOMElement ) {
			return '';
		}

		$text = '';
		foreach ( $node->childNodes as $child ) {
			$text .= self::node_text_excluding( $child, $exclude );
		}
		return $text;
	}

	/**
	 * Wrap block content in its comment delimiters.
	 *
	 * @param string $header  Block name plus optional attributes.
	 * @param string $content Block HTML.
	 * @return string
	 */
	private static function wrap( $header, $content ) {
		return '<!-- wp:' . $header . ' -->' . $content . '<!-- /wp:' . strtok( $header, ' ' ) . ' -->' . "\n";
	}

	/**
	 * Build a code block from a <pre> element, unwrapping an inner single
	 * <code> element to avoid double markup.
	 *
	 * @param DOMDocument $dom   Owning document.
	 * @param DOMElement  $node  The pre element.
	 * @param string      $inner Pre inner HTML.
	 * @return string
	 */
	private static function code_block( DOMDocument $dom, $node, $inner ) {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMElement && 'code' === strtolower( $child->nodeName ) ) {
				$inner = self::inner_html( $dom, $child );
				break;
			}
		}
		return self::wrap( 'code', '<pre class="wp-block-code"><code>' . $inner . '</code></pre>' );
	}

	/**
	 * Build an image block for an img element. The src uses the local
	 * attachment URL when the original URL was already imported; the id,
	 * sizeSlug and wp-image class are included only in that case. The img
	 * keeps its src, alt, and title attributes; the caption text comes from a
	 * dedicated caption element, never from the img attributes.
	 *
	 * @param DOMDocument $dom     Owning document.
	 * @param DOMElement  $img     The img element.
	 * @param string      $caption Caption text, empty when none.
	 * @return string
	 */
	private static function image_block( DOMDocument $dom, DOMElement $img, $caption ) {
		$src   = $img->getAttribute( 'src' );
		$alt   = $img->getAttribute( 'alt' );
		$title = $img->getAttribute( 'title' );

		$attachment_id = '' !== $src ? self::attachment_id_for_url( $src ) : 0;
		$url           = $attachment_id ? (string) wp_get_attachment_url( $attachment_id ) : $src;

		$attrs = $attachment_id ? ' {"id":' . $attachment_id . ',"sizeSlug":"large"}' : '';
		$class = $attachment_id ? ' size-large' : '';

		$markup = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"';
		if ( $attachment_id ) {
			$markup .= ' class="wp-image-' . $attachment_id . '"';
		}
		if ( '' !== $title ) {
			$markup .= ' title="' . esc_attr( $title ) . '"';
		}
		$markup .= '/>';

		$figure = '<figure class="wp-block-image' . $class . '">' . $markup;
		if ( '' !== $caption ) {
			$figure .= '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>';
		}
		$figure .= '</figure>';

		return self::wrap( 'image' . $attrs, $figure );
	}

	/**
	 * Find an imported attachment by its original source URL.
	 *
	 * @param string $url Image URL.
	 * @return int Attachment id, 0 when not found.
	 */
	private static function attachment_id_for_url( $url ) {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared lookup on a plugin-internal meta key.
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bmig_source_url' AND meta_value = %s LIMIT 1",
				$url
			)
		);
	}

	/**
	 * Serialize the children of a node.
	 *
	 * @param DOMDocument $dom  Owning document.
	 * @param DOMNode     $node Parent node.
	 * @return string
	 */
	private static function inner_html( DOMDocument $dom, $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Serialize a node including its own tag.
	 *
	 * @param DOMDocument $dom  Owning document.
	 * @param DOMNode     $node Node to serialize.
	 * @return string
	 */
	private static function outer_html( DOMDocument $dom, $node ) {
		return $dom->saveHTML( $node );
	}
}
