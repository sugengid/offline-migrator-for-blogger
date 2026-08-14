<?php
/**
 * Imports parsed Blogger items into WordPress content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BM_Importer {

	/**
	 * Comments whose parent post has not been imported yet, retried on the
	 * next import_chunk() call of the same importer instance.
	 *
	 * @var array
	 */
	private $pending_comments = array();

	/**
	 * Import one chunk of parsed items.
	 *
	 * Posts and pages are inserted first so comments can resolve their parent
	 * post. Comments whose parent post is not imported yet are deferred and
	 * retried on later chunks, so feed order does not matter within one
	 * importer instance. Comments are inserted without threading in pass 1,
	 * then a sweep links comment_parent for every reply whose parent comment
	 * already exists.
	 *
	 * @param array         $items       Item arrays from BM_Parser::parse().
	 * @param callable|null $on_progress Optional callback receiving the stats array after each item.
	 * @return array Stats: total, imported, skipped, errors, deferred (comments still waiting for their parent post).
	 */
	public function import_chunk( array $items, $on_progress = null ) {
		$stats = array(
			'total'    => count( $items ),
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => 0,
			'deferred' => 0,
		);

		foreach ( $items as $item ) {
			if ( 'comment' === $item['type'] ) {
				continue;
			}
			$this->import_post( $item, $stats );
			$this->report_progress( $on_progress, $stats );
		}

		foreach ( $this->pending_comments as $key => $pending ) {
			if ( ! $this->find_post_by_source_id( $pending['parent'] ) ) {
				continue;
			}
			unset( $this->pending_comments[ $key ] );
			$this->import_comment( $pending, $stats );
		}

		foreach ( $items as $item ) {
			if ( 'comment' !== $item['type'] ) {
				continue;
			}
			$this->import_comment( $item, $stats );
			$this->report_progress( $on_progress, $stats );
		}

		$this->resolve_comment_parents();

		$stats['deferred'] = count( $this->pending_comments );

		return $stats;
	}

	/**
	 * Insert one post or page. Skipped when its source id was imported before.
	 *
	 * @param array $item  Parsed item.
	 * @param array $stats Running stats, modified by reference.
	 */
	private function import_post( array $item, array &$stats ) {
		if ( $this->find_post_by_source_id( $item['id'] ) ) {
			$stats['skipped']++;
			return;
		}

		list( $post_date, $post_date_gmt ) = $this->item_dates( $item );

		$postarr = array(
			'post_type'     => 'page' === $item['type'] ? 'page' : 'post',
			'post_status'   => 'DRAFT' === $item['status'] ? 'draft' : 'publish',
			'post_title'    => wp_slash( $item['title'] ),
			'post_content'  => wp_slash( $item['content'] ),
			'post_date'     => $post_date,
			'post_date_gmt' => $post_date_gmt,
			'meta_input'    => array(
				'_bm_source_id' => $item['id'],
				'_bm_filename'  => $item['filename'],
			),
		);

		if ( '' !== $item['slug'] ) {
			$postarr['post_name'] = $item['slug'];
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			$stats['errors']++;
			return;
		}

		if ( ! empty( $item['categories'] ) ) {
			$this->set_post_categories( $post_id, $item['categories'] );
		}

		$stats['imported']++;
	}

	/**
	 * Assign label names as categories, creating terms that do not exist yet.
	 * The category taxonomy is hierarchical, so wp_set_post_terms() only
	 * accepts term IDs, not names.
	 *
	 * @param int      $post_id Post or page id.
	 * @param string[] $names   Category names.
	 */
	private function set_post_categories( $post_id, array $names ) {
		$ids = array();
		foreach ( $names as $name ) {
			$existing = term_exists( $name, 'category' );
			if ( $existing ) {
				$ids[] = (int) $existing['term_id'];
				continue;
			}
			$created = wp_insert_term( $name, 'category' );
			if ( is_wp_error( $created ) ) {
				$existing_id = (int) $created->get_error_data( 'term_exists' );
				if ( $existing_id ) {
					$ids[] = $existing_id;
				}
				continue;
			}
			$ids[] = (int) $created['term_id'];
		}
		if ( ! empty( $ids ) ) {
			wp_set_post_terms( $post_id, $ids, 'category' );
		}
	}

	/**
	 * Insert one comment without threading. The reply target is kept in the
	 * _bm_in_reply_to meta so resolve_comment_parents() can link it once the
	 * parent comment exists.
	 *
	 * @param array $item  Parsed item.
	 * @param array $stats Running stats, modified by reference.
	 */
	private function import_comment( array $item, array &$stats ) {
		if ( $this->find_comment_by_source_id( $item['id'] ) ) {
			$stats['skipped']++;
			return;
		}

		$post_id = $this->find_post_by_source_id( $item['parent'] );
		if ( ! $post_id ) {
			$this->pending_comments[] = $item;
			return;
		}

		list( $comment_date, $comment_date_gmt ) = $this->item_dates( $item );

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_content'      => wp_slash( $item['content'] ),
				'comment_author'       => wp_slash( $item['author_name'] ),
				'comment_author_url'   => $item['author_url'],
				'comment_author_email' => '',
				'comment_approved'     => 1,
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
				'comment_date'         => $comment_date,
				'comment_date_gmt'     => $comment_date_gmt,
			)
		);

		if ( ! $comment_id ) {
			$stats['errors']++;
			return;
		}

		add_comment_meta( $comment_id, '_bm_source_id', $item['id'] );
		if ( '' !== $item['in_reply_to'] ) {
			add_comment_meta( $comment_id, '_bm_in_reply_to', $item['in_reply_to'] );
		}

		$stats['imported']++;
	}

	/**
	 * Link comment_parent for replies whose parent comment is already imported.
	 * Runs over all unresolved replies, not just the current chunk, so replies
	 * chunked before their parent still get linked. The _bm_in_reply_to meta is
	 * deleted once resolved to keep later sweeps cheap.
	 */
	private function resolve_comment_parents() {
		global $wpdb;

		$pending = $wpdb->get_results(
			"SELECT cm.comment_id, cm.meta_value AS reply_to
			FROM {$wpdb->commentmeta} cm
			INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
			WHERE cm.meta_key = '_bm_in_reply_to' AND c.comment_parent = 0"
		);

		foreach ( $pending as $row ) {
			$parent_id = $this->find_comment_by_source_id( $row->reply_to );
			if ( ! $parent_id ) {
				continue;
			}
			$wpdb->update(
				$wpdb->comments,
				array( 'comment_parent' => $parent_id ),
				array( 'comment_ID' => $row->comment_id ),
				array( '%d' ),
				array( '%d' )
			);
			clean_comment_cache( $row->comment_id );
			delete_comment_meta( $row->comment_id, '_bm_in_reply_to' );
		}
	}

	/**
	 * Find a post or page id by its Blogger source id.
	 *
	 * @param string $source_id Blogger entry id.
	 * @return int Post id, 0 when not found.
	 */
	private function find_post_by_source_id( $source_id ) {
		global $wpdb;
		if ( '' === $source_id ) {
			return 0;
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bm_source_id' AND meta_value = %s LIMIT 1",
				$source_id
			)
		);
	}

	/**
	 * Find a comment id by its Blogger source id.
	 *
	 * @param string $source_id Blogger entry id.
	 * @return int Comment id, 0 when not found.
	 */
	private function find_comment_by_source_id( $source_id ) {
		global $wpdb;
		if ( '' === $source_id ) {
			return 0;
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = '_bm_source_id' AND meta_value = %s LIMIT 1",
				$source_id
			)
		);
	}

	/**
	 * Build local and GMT dates from an item, preferring published over created.
	 *
	 * @param array $item Parsed item.
	 * @return string[] Array of post_date (local) and post_date_gmt.
	 */
	private function item_dates( array $item ) {
		$raw = '' !== $item['published'] ? $item['published'] : $item['created'];

		$timestamp = strtotime( $raw );
		if ( false === $timestamp ) {
			$timestamp = time();
		}

		$gmt = gmdate( 'Y-m-d H:i:s', $timestamp );
		return array( get_date_from_gmt( $gmt ), $gmt );
	}

	/**
	 * Invoke the progress callback with a copy of the running stats.
	 *
	 * @param callable|null $on_progress Progress callback.
	 * @param array         $stats       Running stats.
	 */
	private function report_progress( $on_progress, array $stats ) {
		if ( is_callable( $on_progress ) ) {
			call_user_func( $on_progress, $stats );
		}
	}
}
