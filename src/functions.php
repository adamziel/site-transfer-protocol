<?php

function wp_list_urls_in_block_markup( $options ) {
	$block_markup = $options['block_markup'];
	$base_url     = $options['base_url'] ?? 'https://playground.internal';
	$p            = new WP_Block_Markup_Url_Processor( $block_markup, $base_url );
	while ( $p->next_url() ) {
		// Skip empty relative URLs.
		if ( ! trim( $p->get_raw_url() ) ) {
			continue;
		}
		echo '* ';
		switch ( $p->get_token_type() ) {
			case '#tag':
				echo 'In <' . $p->get_tag() . '> tag attribute "' . $p->get_inspected_attribute_name() . '": ';
				break;
			case '#block-comment':
				echo 'In a ' . $p->get_block_name() . ' block attribute "' . $p->get_block_attribute_key() . '": ';
				break;
			case '#text':
				echo 'In #text: ';
				break;
		}
		echo $p->get_raw_url() . "\n";
	}
}

function wp_migrate_post_content_urls($options) {
	$result = wp_frontload_assets($options);
	if($result !== true) {
		return $result;
	}
	return wp_rewrite_urls($options);
}

function wp_rewrite_urls($options) {
	$string_new_site_url           = $options['new-site-url'];
	$parsed_new_site_url           = WP_URL::parse( $string_new_site_url );
	$parsed_current_site_url       = WP_URL::parse( $options['current-site-url'] );
	
	$p = new WP_Block_Markup_Url_Processor( $options['block_markup'], $options['base_url'] );
	$generator = iterate_urls($p, $options['current-site-url']);
	foreach($generator as $p) {
		$parsed_matched_url = $p->get_parsed_url();
		// Let's rewrite the URL
		$parsed_matched_url->hostname = $parsed_new_site_url->hostname;
		$decoded_matched_pathname = urldecode( $parsed_matched_url->pathname );

		// Short-circuit for empty pathnames
		if ('/' !== $parsed_current_site_url->pathname) {
			$parsed_matched_url->pathname =
				$parsed_new_site_url->pathname .
				substr(
					$decoded_matched_pathname,
					// @TODO: Why is + 1 needed to avoid a double slash in the pathname?
					strlen(urldecode($parsed_current_site_url->pathname)) + 1
				);
		}

		/*
		 * Stylistic choice – if the matched URL has no trailing slash,
		 * do not add it to the new URL. The WHATWG URL parser will
		 * add one automatically if the path is empty, so we have to
		 * explicitly remove it.
		 */
		$new_raw_url = $parsed_matched_url->toString();
		$raw_matched_url = $p->get_raw_url();
		if (
			$raw_matched_url[strlen($raw_matched_url) - 1] !== '/' &&
			$parsed_matched_url->pathname === '/' &&
			$parsed_matched_url->search === '' &&
			$parsed_matched_url->hash === ''
		) {
			$new_raw_url = rtrim($new_raw_url, '/');
		}
		if ($new_raw_url) {
			$p->set_raw_url($new_raw_url);
		}
	}
	return $p->get_updated_html();
}

function wp_frontload_assets( $options ) {
	$local_assets_path = $options['local-assets-path'];

	$p = new WP_Block_Markup_Url_Processor( $options['block_markup'], $options['base_url'] );

	$assets_urls = [];
	foreach(iterate_urls($p, $options['current-site-url']) as $p) {
		$parsed_matched_url = $p->get_parsed_url();
		// @TODO use an actual decoder, not PHP's urldecode
		$pathname = urldecode($parsed_matched_url->pathname);
		$pathname = normalize_path($pathname);
		$pathname = ltrim($pathname, '/');
		if(!str_contains($pathname, '.')) {
			continue;
		}

		$local_path = join_paths($local_assets_path, $pathname);
		
		$ext = pathinfo($pathname, PATHINFO_EXTENSION);
		if(!is_dir(dirname($local_path))) {
			mkdir(dirname($local_path), 0777, true);
		}
		
		if($ext === 'php') {
			continue;
		}
		$assets_urls[$parsed_matched_url->href] = $local_path;
	}

	$download_results = wp_download_files([
		'concurrency' => 10,
		'assets' => $assets_urls,
	]);

	$failures = array_filter($download_results, function($result) {
		return !$result['success'];
	});
	if(!empty($failures)) {
		return $failures;
	}
	return true;
}

/**
 * 
 * @param mixed $options
 * @return Generator
 */
function iterate_urls($p, $current_site_url) {
	$parsed_current_site_url       = WP_URL::parse( $current_site_url );
	$decoded_current_site_pathname = urldecode( $parsed_current_site_url->pathname );

	while ( $p->next_url() ) {
		$parsed_matched_url = $p->get_parsed_url();
		if ( $parsed_matched_url->hostname === $parsed_current_site_url->hostname ) {
			$decoded_matched_pathname = urldecode( $parsed_matched_url->pathname );
			$pathname_matches         = str_starts_with( $decoded_matched_pathname, $decoded_current_site_pathname );
			if ( ! $pathname_matches ) {
				continue;
			}

			// It's a match!
			yield $p;
		}
	}
	return $p->get_updated_html();
}


/**
 * Downloads the assets from the given URLs to the local paths.
 * Only downloads the files that are not already present locally.
 * 
 * For now it uses curl. Before merging to WordPress core we should switch to
 * https://github.com/WordPress/blueprints-library/blob/trunk/src/WordPress/AsyncHttp/Client.php.
 * To use in Playground, we'll need to delegate to `fetch()` until we support
 * curl in the browser.
 * 
 * @param array $options {
 *     @type int|null concurrency How many concurrent downloads to run.
 *     @type string   concurrency An array of { remote URL => local path }.
 * }
 * @return array An array of download errors in format { remote URL => error }.
 */
function wp_download_files($options) {
	$concurrency = $options['concurrency'] ?? 10;
	$assets = $options['assets'];

    $mh = curl_multi_init();
	$errors = [];
    $handles = [];
    $active_handles = 0;
    
    foreach ($assets as $url => $local_path) {
        // Initialize curl handle
        $ch = curl_init($url);
        $fp = fopen($local_path, 'w');
    
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
    
        // Add handle to multi-handle
        curl_multi_add_handle($mh, $ch);
        $handles[(int) $ch] = ['handle' => $ch, 'fp' => $fp, 'url' => $url];
        $active_handles++;
    
        // When window_size is reached, execute handles
        if ($active_handles == $concurrency) {
            do {
                $execrun = curl_multi_exec($mh, $running);
            } while ($execrun == CURLM_CALL_MULTI_PERFORM);
    
            while ($running && $execrun == CURLM_OK) {
                if (curl_multi_select($mh) == -1) {
                    usleep(100);
                }
    
                do {
                    $execrun = curl_multi_exec($mh, $running);
                } while ($execrun == CURLM_CALL_MULTI_PERFORM);
            }
    
            while ($done = curl_multi_info_read($mh)) {
                $handle = $done['handle'];
                $fp = $handles[(int) $handle]['fp'];

				if (curl_errno($handle)) {
					$errors[$handles[(int) $handle]['url']] = curl_error($handle);
				}
    
                curl_multi_remove_handle($mh, $handle);
                curl_close($handle);
                fclose($fp);
    
                unset($handles[(int) $handle]);
                $active_handles--;
            }
        }
    }
    
    // Process any remaining handles
    do {
        $execrun = curl_multi_exec($mh, $running);
    } while ($execrun == CURLM_CALL_MULTI_PERFORM);
    
    while ($running && $execrun == CURLM_OK) {
        if (curl_multi_select($mh) == -1) {
            usleep(100);
        }
    
        do {
            $execrun = curl_multi_exec($mh, $running);
        } while ($execrun == CURLM_CALL_MULTI_PERFORM);
    }
    
    while ($done = curl_multi_info_read($mh)) {
        $handle = $done['handle'];
		if (curl_errno($handle)) {
			$errors[$handles[(int) $handle]['url']] = curl_error($handle);
		}

        $fp = $handles[(int) $handle]['fp'];
        curl_multi_remove_handle($mh, $handle);
        curl_close($handle);
        fclose($fp);
    
        unset($handles[(int) $handle]);
    }
    
    curl_multi_close($mh);

	$results = [];
	foreach($assets as $url => $local_path) {
		$results[$url] = [
			'success' => !isset($errors[$url]),
			'error' => $errors[$url] ?? null,
		];
	}
	return $results;
}

function normalize_path($path) {
    // Check if it's an absolute path (Unix-based systems)
    $isAbsolute = $path[0] === '/';
    
    // Replace backslashes with forward slashes
    $path = str_replace('\\', '/', $path);

    // Split the path into segments
    $parts = array_filter(explode('/', $path), 'strlen');

    $result = [];
    foreach ($parts as $part) {
        if ($part === '..') {
            // Pop the last element if possible (go one directory up)
            if (count($result) > 0) {
                array_pop($result);
            } elseif (!$isAbsolute) {
                // If the path is relative and we're at the root, add '..'
                $result[] = $part;
            }
        } elseif ($part !== '.') {
            // Add the current directory to the stack if it's not '.'
            $result[] = $part;
        }
    }

    // Combine the parts back into a normalized path
    return ($isAbsolute ? '/' : '') . implode('/', $result);
}
function join_paths(...$paths) {
    // Normalize and filter out any empty paths (excluding the root directory case)
    $paths = array_map(function($path) {
        // Convert backslashes to forward slashes
        return str_replace('\\', '/', $path);
    }, $paths);

    // Trim slashes from the end of each part and the start of each part (except the first one)
    $trimmed_paths = [];
    foreach ($paths as $key => $path) {
        if ($key == 0) {
            // For the first path, trim only trailing slashes
            $trimmed_paths[] = rtrim($path, '/');
        } else {
            // For subsequent paths, trim both leading and trailing slashes
            $trimmed_paths[] = trim($path, '/');
        }
    }

    // Join the components with a slash
    $result = implode('/', $trimmed_paths);

    // Handle the case of joining to an absolute Unix path
    if (strpos($paths[0], '/') === 0) {
        $result = '/' . ltrim($result, '/');
    }

    return $result;
}
