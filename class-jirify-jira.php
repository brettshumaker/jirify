<?php

class Jirify_Jira extends Jirify {
	private $token;
	private $email;
	private $endpoint;

	public function __construct( $token, $email, $endpoint ) {
		$this->token          = $token;
		$this->email          = $email;
		$this->endpoint       = $endpoint;
	}

	public function send_worklog( $client, $seconds, $desc = '', $date ) {
		// Normalize strings.
		$lc_client = strtolower( trim( $client ) );
		$client_mapping = $this->get_client_mapping();

		$worklogs  = array_change_key_case( $client_mapping );

		if ( array_key_exists( $lc_client, $worklogs ) ) {
			$lc_client = $worklogs[ $lc_client ];
		}

		// No match found!
		if ( strtolower( trim( $client ) ) === $lc_client ) {
			$this->line( sprintf( '❌ Could not find a Worklog match for client "%s"', $client ) );
			return false;
		}

		$url = sprintf(
			'%s/rest/api/3/issue/%s/worklog',
			$this->endpoint,
			rawurlencode( $lc_client ),
		);

		$args = array(
			'headers' => array(
				'Authorization: Basic ' . base64_encode( $this->email . ':' . $this->token ),
				'Accept: application/json',
			),
			'body'    => json_encode(
				array(
					// 'comment'          => $desc, // Not sure I want to send descriptions.
					'started'          => gmdate( 'Y-m-d\TG:i:s.vO', strtotime( $date ) ),
					'timeSpentSeconds' => (int) $seconds,
				)
			),
		);

		$result = $this->remote_post( $url, $args );
		if ( 201 === $result['status'] ) {
			return true;
		}
		return false;
	}
}
