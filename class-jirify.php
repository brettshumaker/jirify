<?php

class Jirify {
	public $client_mapping;

	public function __construct() {
	}

	public function get_client_mapping() {

		if ( is_null( $this->client_mapping ) ) {
			$this->client_mapping = (array) json_decode( file_get_contents( dirname( __FILE__ ) . '/.data/mapping.json' ) );
		}

		if ( ! $this->client_mapping ) {
			$this->line( "No client issue mapping found! Please add this data to ./data/mapping.json" );
			die();
		}

		return $this->client_mapping;
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
