<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derives the best search keyword for a post.
 *
 * The keyword mode setting controls what happens when no per-post custom
 * keyword is set:
 *  - 'title'    (default): title → category → tag → global default
 *  - 'keyword'  : global default keyword only
 *  - 'combined' : global default + title terms merged into one query
 *
 * Per-post custom keyword always overrides the mode.
 */
class Keyword_Generator {

	const META_CUSTOM_KEYWORD = '_unsplash_custom_keyword';
	const KEYWORD_MODE_OPTION = 'unsplash_keyword_mode';

	/** Common English stop words to filter out of titles. */
	private $stop_words = array(
		'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
		'of', 'with', 'by', 'from', 'up', 'about', 'into', 'through', 'during',
		'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had',
		'do', 'does', 'did', 'will', 'would', 'shall', 'should', 'may', 'might',
		'must', 'can', 'could', 'not', 'no', 'nor', 'so', 'yet', 'both', 'either',
		'each', 'few', 'more', 'most', 'other', 'some', 'such', 'than', 'too',
		'very', 's', 't', 'just', 'how', 'what', 'when', 'where', 'who', 'why',
		'which', 'this', 'that', 'these', 'those', 'i', 'me', 'my', 'we', 'our',
		'you', 'your', 'he', 'she', 'it', 'his', 'her', 'its', 'they', 'them',
		'their', 'all', 'any', 'here', 'there', 'then', 'now', 'only', 'also',
	);

	public function __construct() {}

	/**
	 * Return the best keyword for a post, following the fallback chain.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public function get_keyword_for_post( $post_id ) {
		$post_id = absint( $post_id );

		// Per-post custom keyword always wins, regardless of mode.
		if ( $this->has_custom_keyword( $post_id ) ) {
			return $this->get_custom_keyword( $post_id );
		}

		$mode = $this->get_keyword_mode();

		if ( 'keyword' === $mode ) {
			return $this->get_default_keyword();
		}

		if ( 'combined' === $mode ) {
			return $this->get_combined_keyword( $post_id );
		}

		// 'title' mode — original fallback chain.
		$post = get_post( $post_id );
		if ( $post && ! empty( $post->post_title ) ) {
			$from_title = $this->extract_from_title( $post->post_title );
			if ( ! empty( $from_title ) ) {
				return $from_title;
			}
		}

		$from_category = $this->get_category_keyword( $post_id );
		if ( ! empty( $from_category ) ) {
			return $from_category;
		}

		$from_tag = $this->get_tag_keyword( $post_id );
		if ( ! empty( $from_tag ) ) {
			return $from_tag;
		}

		return $this->get_default_keyword();
	}

	/**
	 * Check whether the post has a custom keyword set.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public function has_custom_keyword( $post_id ) {
		$keyword = get_post_meta( absint( $post_id ), self::META_CUSTOM_KEYWORD, true );
		return ! empty( $keyword );
	}

	/**
	 * Get the custom keyword for a post.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public function get_custom_keyword( $post_id ) {
		$keyword = get_post_meta( absint( $post_id ), self::META_CUSTOM_KEYWORD, true );
		return $this->clean_keyword( (string) $keyword );
	}

	/**
	 * Set a custom keyword for a post.
	 *
	 * @param int    $post_id
	 * @param string $keyword
	 */
	public function set_custom_keyword( $post_id, $keyword ) {
		$clean = $this->clean_keyword( $keyword );
		if ( empty( $clean ) ) {
			delete_post_meta( absint( $post_id ), self::META_CUSTOM_KEYWORD );
		} else {
			update_post_meta( absint( $post_id ), self::META_CUSTOM_KEYWORD, $clean );
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract a usable keyword from a post title.
	 *
	 * @param string $title
	 * @return string
	 */
	private function extract_from_title( $title ) {
		$title = wp_strip_all_tags( $title );
		$title = strtolower( $title );
		// Remove special chars, keep letters, numbers, spaces.
		$title = preg_replace( '/[^a-z0-9\s]/', ' ', $title );
		$words = preg_split( '/\s+/', trim( $title ), -1, PREG_SPLIT_NO_EMPTY );
		$words = $this->remove_stop_words( $words );

		if ( empty( $words ) ) {
			return '';
		}

		// Return up to the 3 most meaningful words as the query.
		return $this->clean_keyword( implode( ' ', array_slice( $words, 0, 3 ) ) );
	}

	/**
	 * Return the first category name for a post.
	 *
	 * @param int $post_id
	 * @return string
	 */
	private function get_category_keyword( $post_id ) {
		$categories = get_the_category( $post_id );
		if ( ! empty( $categories ) ) {
			return $this->clean_keyword( $categories[0]->name );
		}
		return '';
	}

	/**
	 * Return the first tag name for a post.
	 *
	 * @param int $post_id
	 * @return string
	 */
	private function get_tag_keyword( $post_id ) {
		$tags = get_the_tags( $post_id );
		if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
			return $this->clean_keyword( $tags[0]->name );
		}
		return '';
	}

	/**
	 * Return the plugin-level default keyword from settings.
	 *
	 * @return string
	 */
	private function get_default_keyword() {
		$keyword = get_option( 'unsplash_default_keyword', 'nature' );
		return $this->clean_keyword( (string) $keyword );
	}

	/**
	 * Sanitize and normalize a keyword string.
	 *
	 * @param string $keyword
	 * @return string
	 */
	private function clean_keyword( $keyword ) {
		$keyword = sanitize_text_field( $keyword );
		$keyword = strtolower( trim( $keyword ) );
		$keyword = preg_replace( '/\s+/', ' ', $keyword );
		return $keyword;
	}

	/**
	 * Return the active keyword mode, validated against the allowed list.
	 *
	 * @return string  'title' | 'keyword' | 'combined'
	 */
	private function get_keyword_mode() {
		$mode = get_option( self::KEYWORD_MODE_OPTION, 'title' );
		return in_array( $mode, array( 'title', 'keyword', 'combined' ), true ) ? $mode : 'title';
	}

	/**
	 * Combine the global default keyword with terms extracted from the post title.
	 * Falls back to just the default keyword if the title yields nothing.
	 *
	 * @param int $post_id
	 * @return string
	 */
	private function get_combined_keyword( $post_id ) {
		$default = $this->get_default_keyword();

		$post = get_post( $post_id );
		if ( ! $post || empty( $post->post_title ) ) {
			return $default;
		}

		$title_terms = $this->extract_from_title( $post->post_title );
		if ( empty( $title_terms ) ) {
			return $default;
		}

		return $this->clean_keyword( $default . ' ' . $title_terms );
	}

	/**
	 * Remove stop words from a word array.
	 *
	 * @param string[] $words
	 * @return string[]
	 */
	private function remove_stop_words( $words ) {
		return array_values(
			array_filter( $words, function( $word ) {
				return ! in_array( $word, $this->stop_words, true ) && strlen( $word ) > 2;
			} )
		);
	}
}
