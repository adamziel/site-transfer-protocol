<?php

namespace Wordpress\TransferProtocol\exploratory;

use WP_Migration_URL_In_Text_Processor;

/**
 * @wip
 *
 * An exploration to match URLs without using regular expressions.
 * Need to benchmark and rigorously test the current next_url()
 * implementation.
 *
 * There's a lot to implement here – it's not clear if it's worth it.
 *
 * We may either:
 *
 * * Be fine with preg_match in next_url()
 * * Need a custom implementation like this one
 * * Be forced to ditch this approach entirely and find a way to plug
 *   in a proper WHATWG-compliant URL parser into the task of finding
 *   URLs in text. This may or may not be possible/viable.
 */
class WP_Regexless_URL_In_Text_Processor extends WP_Migration_URL_In_Text_Processor {

	private $text;
	private $bytes_already_parsed = 0;


	/**
	 * Characters that are forbidden in the host part of a URL.
	 * See https://url.spec.whatwg.org/#host-miscellaneous.
	 */
	private const FORBIDDEN_HOST_BYTES = "\x00\x09\x0a\x0d\x20\x23\x2f\x3a\x3c\x3e\x3f\x40\x5b\x5c\x5d\x5e\x7c";
	private const FORBIDDEN_DOMAIN_BYTES = "\x00\x09\x0a\x0d\x20\x23\x25\x2f\x3a\x3c\x3e\x3f\x40\x5b\x5c\x5d\x5e\x7c\x7f";

	/**
	 * Unlike RFC 3986, the WHATWG URL specification does not the domain part of
	 * a URL to any length. That being said, we apply an arbitrary limit here as
	 * an optimization to avoid scanning the entire text for a domain name.
	 *
	 * Rationale: Domains larger than 1KB are extremely rare. The WHATWG URL
	 */
	private const CONSIDER_DOMAINS_UP_TO_BYTES = 1024;

	public function next_url() {
		$at = $this->bytes_already_parsed;

		// Find the next dot in the text
		$dot_at = strpos( $this->text, '.', $at );

		// If there's no dot, assume there's no URL
		if ( false === $dot_at ) {
			return false;
		}

		// The shortest tld is 2 characters long
		if ( $dot_at + 2 >= strlen( $this->text ) ) {
			return false;
		}

		$host_bytes_after_dot = strcspn(
			$this->text,
			self::FORBIDDEN_DOMAIN_BYTES,
			$dot_at + 1,
			self::CONSIDER_DOMAINS_UP_TO_BYTES
		);

		if ( 0 === $host_bytes_after_dot ) {
			return false;
		}

		// Lookbehind to capture the rest of the domain name up to a forbidden character.
		$host_bytes_before_dot = strcspn(
			$this->text_rev,
			self::FORBIDDEN_DOMAIN_BYTES,
			strlen( $this->text ) - $dot_at - 1,
			self::CONSIDER_DOMAINS_UP_TO_BYTES
		);

		$host_starts_at = $dot_at - $host_bytes_before_dot;

		// Capture the protocol, if any
		$has_double_slash = false;
		if ( $host_starts_at > 2 ) {
			if ( '/' === $this->text[ $host_starts_at - 1 ] && '/' === $this->text[ $host_starts_at - 2 ] ) {
				$has_double_slash = true;
			}
		}

		/**
		 * Look for http or https at the beginning of the URL.
		 * @TODO: Ensure the character before http or https is a word boundary.
		 */
		$has_protocol = false;
		if ( $has_double_slash && (
				(
					$host_starts_at >= 6 &&
					'h' === $this->text[ $host_starts_at - 6 ] &&
					't' === $this->text[ $host_starts_at - 5 ] &&
					't' === $this->text[ $host_starts_at - 4 ] &&
					'p' === $this->text[ $host_starts_at - 3 ]
				) ||
				(
					$host_starts_at >= 7 &&
					'h' === $this->text[ $host_starts_at - 7 ] &&
					't' === $this->text[ $host_starts_at - 6 ] &&
					't' === $this->text[ $host_starts_at - 5 ] &&
					'p' === $this->text[ $host_starts_at - 4 ] &&
					's' === $this->text[ $host_starts_at - 3 ]
				)
			) ) {
			$has_protocol = true;
		}

		// Move the pointer to the end of the host
		$at = $dot_at + $host_bytes_after_dot;
	}


}

