<?php

class Jirify_Jira extends Jirify {
	private $token;
	private $email;
	private $endpoint;
	private $project_key;

	public function __construct( $jira_options ) {
		$this->token          = $jira_options->token;
		$this->email          = $jira_options->email;
		$this->endpoint       = $jira_options->endpoint;
		$this->project_key    = $jira_options->project_key;
		$this->flush          = $jira_options->flush;
		$this->client_mapping = $this->get_client_mapping();
	}

	public function send_worklog( $client, $seconds, $desc = '', $date ) {
		// Get the issue ID for this client
		$issue_id = $this->get_issue_id_from_client( $client );

		// No match found!
		if ( ! $issue_id ) {
			$this->line( sprintf( '❌ Could not find a Worklog match for client "%s"', $client ) );
			return false;
		}

		$url = sprintf(
			'%s/rest/api/3/issue/%s/worklog',
			$this->endpoint,
			rawurlencode( $issue_id ),
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

	/**
	 * Gets an issue ID from the client mapping.
	 *
	 * @param string $client The client name to check.
	 * @return string|bool The Jira issue ID, or false if no match found.
	 */
	public function get_issue_id_from_client( $client ) {
		// Normalize strings.
		$lc_client = strtolower( trim( $client ) );
		$client_mapping = $this->client_mapping;

		$worklogs  = array_change_key_case( $client_mapping );

		if ( array_key_exists( $lc_client, $worklogs ) ) {
			$issue_id = $worklogs[ $lc_client ];
		}

		if ( ! isset( $issue_id ) ) {
			return false;
		}

		return $issue_id;
	}

	/**
	 * Gets the Client Name to Jira issue key mapping
	 *
	 * @return array An array of "client name" => "jira issue key" pairs.
	 */
	public function get_client_mapping() {
		// If we aren't supposed to flush the cache, try and get cached data.
		if ( ! $this->flush ) {
			// Try and get client mapping from cache.
			$cached_mapping = $this->get_cache_data( 'mapping' );
			if ( $cached_mapping ) {
				return (array) $cached_mapping;
			}
		}

		$this->line( "♻️  Refreshing Jira client mapping." );

		$url = sprintf(
			'%s/rest/api/3/search',
			$this->endpoint
		);
	
		$args = array(
			'headers' => array(
				'Authorization: Basic ' . base64_encode( $this->email . ':' . $this->token ),
				'Accept: application/json',
			),
			'body'    => json_encode(
				array(
					// This search is specific to my Jira setup. https://support.atlassian.com/jira-software-cloud/docs/advanced-search-reference-jql-fields/
					'jql' => 'project = ' . $this->project_key . ' AND issuetype = Client AND resolution = unresolved order by summary ASC',
					// I'm pretty sure that 100 is the absolute max here.
					'maxResults' => 100,
				)
			),
		);
	
		$result = $this->remote_post( $url, $args );

		$issue_mapping = [];

		if ( isset( $result['response'] ) ) {
			foreach ( $result['response']->issues as $issue ) {
				$issue_mapping[ $issue->fields->summary ] = $issue->key;
			}
		}

		// Now get any nicknames we have.
		if ( file_exists( dirname( __FILE__ ) . '/.config/nicknames.json' ) ) {
			$nicknames = (array) json_decode( file_get_contents( dirname( __FILE__ ) . '/.config/nicknames.json' ) );

			// Merge them in.
			$issue_mapping = array_merge( $issue_mapping, $nicknames );
		}

		// Sets the mapping data in the cache for 12 hours.
		$this->set_cache_data( 'mapping', $issue_mapping );
		return $issue_mapping;
	}
}
