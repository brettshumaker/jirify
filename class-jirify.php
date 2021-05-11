<?php

class Jirify {
	public $client_mapping;

	public function __construct() {
	}

	/**
	 * Outputs a line to the screen and appends a \n character to it.
	 * We're ignoring standards here because there's nothing nefarious
	 * that we could output to the terminal. 🙂
	 * @param $string $message
	 */
	public function line( $message = '' ) {
		echo $message . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function remote_request( $url, $args ) {
		$curl = curl_init();

		$curl_args = array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => $args['method'],
			CURLOPT_HTTPHEADER         => array_merge(
				array(
					'Content-Type: application/json',
				),
				$args['headers']
			),
		);

		if ( 'POST' === $args['method'] && isset( $args['body'] ) ) {
			$curl_args[CURLOPT_POSTFIELDS] = $args['body'];
		}

		if ( isset( $args['auth'] ) ) {
			$curl_args[CURLOPT_USERPWD] = $args['auth']['username'] . ":" . $args['auth']['password'];
		}

		curl_setopt_array(
			$curl,
			$curl_args
		);

		$response = json_decode( curl_exec( $curl ) );
		$status   = curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		curl_close( $curl );

		return array(
			'status'   => $status,
			'response' => $response,
		);
	}

	public function remote_get( $url, $args ) {
		$args['method'] = 'GET';
		return $this->remote_request( $url, $args );
	}

	public function remote_post( $url, $args ) {
		$args['method'] = 'POST';
		return $this->remote_request( $url, $args );
	}

	/**
	 * Retrieves cache data from a data store.
	 *
	 * @param string $store
	 * @return mixed An array of objects or false when expired, not found, or a problem was encountered loading the file
	 */
	public function get_cache_data( $store ) {
		$file_path = dirname( __FILE__ ) . "/.cache/$store.json";

		// If the file doesn't exist, return false.
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		$data = json_decode( file_get_contents( $file_path ) );

		// If there was a problem retrieving the data, return false.
		if ( is_null( $data ) ) {
			$this->line( "Problem retrieving $store" );
			return false;
		}

		$expired = time() > $data->expires ? true : false;

		// If the data has expired, return false.
		if ( $expired ) {
			return false;
		}

		return $data->$store;
	}

	/**
	 * Sets the cache data from a data store.
	 * 
	 * @var string $store  The data store to set.
	 * @var array  $data   The array of data objects to save to cache.
	 * @var int    $expiry The expiration time for the data - defaults to 12 hours.
	 */
	public function set_cache_data( $store, $data = array(), $expiry = 60 * 60 * 12 ) {
		$file_path        = dirname( __FILE__ ) . "/.cache/$store.json";
		$expire_timestamp = time() + $expiry;
		$data_to_store    = json_encode( (object) array(
			'expires' => $expire_timestamp,
			$store    => $data,
		) );

		file_put_contents( $file_path, $data_to_store );
	}

	/**
	 * Rounds up duration to the nearest 15 minutes.
	 *
	 * @param int $value Number of seconds
	 * @param int $round_to The number of seconds to round up to. Defaults to 900 (15 minutes).
	 * @return int
	 */
	public function round_up( $value = 0, $round_to = 900 ) {
		if ( $value > 0 ) {
			$mod = $value % $round_to;
			$value = ( $value - $mod ) + $round_to;
		}
		return $value;
	}
}
