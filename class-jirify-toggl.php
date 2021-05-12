<?php

class Jirify_Toggl extends Jirify {
	private $token;
	private $workspace;
	private $api_base;
	private $timezone;
	private $jira;

	public function __construct( $config ) {
		$this->token       = $config->token;
		$this->workspace   = $config->workspace;
		$this->timezone    = $config->timezone;
		$this->api_base    = 'https://api.track.toggl.com/api/v8';

		// Set up the Jira connection.
		$this->jira = new Jirify_Jira( $config->jira_token, $config->jira_email, $config->jira_endpoint, $config->jira_project_key );
	}

	/**
	 * Sends time entries to Jira. This is reason we're here, folks. This handles getting all entries from
	 * Toggl after a given start date. The start date can be supplied in any form that can construct a
	 * PHP DateTime object. If a start date is not supplied, it will use the last_logged value from the
	 * data.json file. If that doesn't exist, it will fallback to midnight of the current day.
	 *
	 * @param boolean $start_date
	 * @return void
	 */
	public function log_time( $start_date = false, $end_date = false, $dry_run = false ) {
		$projects          = $this->get_projects();
		$clients           = $this->get_clients();
		$entries           = $this->get_entries( $start_date, $end_date );
		$last_logged_start = $dry_last_logged_start = false;

		// There was an error getting one of the necessary data types. Let's stop.
		if ( false === $entries || false === $projects || false === $clients ) {
			return;
		}

		if ( empty( $entries ) ) {
			$this->line( "⚪ No entries found." );
		}

		// Loop through each time entry
		foreach( $entries as $time_entry ) {
			$project_id = $time_entry->pid;
			$project    = $projects->$project_id;
			$client_id  = $project->cid;
			$description = ! empty( $time_entry->description ) ? '"' . $time_entry->description . '"' : '';

			// If we don't have a client ID for the project, we can't track it - skip.
			if ( ! $client_id ) {
				$skip_out = implode( ' ', array_filter( [$description, $project->name, "(no client assigned)."] ) );
				$this->line( "❕ Skipping log for " . $skip_out );
				continue;
			}

			// $time_entry->duration will be a negative integer if timer is running, skip.
			if ( 0 > $time_entry->duration ) {
				continue;
			}

			$client       = $clients->$client_id;
			$start        = $time_entry->start;
			$duration     = $this->round_up( $time_entry->duration );

			if ( 0 === $duration ) {
				// There was a problem with the duration string, skip
				$this->line( "❌ Invalid duration string for " . $client->name . ": " . $time_entry->duration );
			}

			$description = ! empty( $description ) ? " - $description" : '';

			// This sends the time entry to Jira. $duration needs to be in seconds.
			if ( ! $dry_run && $this->jira->send_worklog( $client->name, $duration, '', $start ) ) {
				$this->line( "✅ Logged " . $this->get_friendly_duration_output( $duration ) . " for " . $client->name . $description );
				if ( $start > $last_logged_start ) {
					$last_logged_start = $start;
				}
			} else if( $dry_run ) {
				if ( $start > $dry_last_logged_start ) {
					$dry_last_logged_start = $start;
				}
				$this->line( "✅ Would have logged " . $this->get_friendly_duration_output( $duration ) . " for " . $client->name . $description );
			} else {
				$this->line( "❌ Error logging " . $this->get_friendly_duration_output( $duration ) . " for " . $client->name . $description );
			}
		}

		// If we've logged something, store the start date from the last time entry we processed.
		if ( $last_logged_start ) {
			$this->set_last_logged( $last_logged_start );
		} else if ( ! $dry_run ) {
			$this->line( "\n🤷‍♂️ No new entries with clients found - nothing sent to Jira." );
		}

		if ( $dry_run && $dry_last_logged_start ) {
			$this->line( "Would have set last logged date to $dry_last_logged_start" );
		}

		// Buh bye!
		$this->line( "\n👋 All done! Bye!" );
	}

	/**
	 * Gets a array of Toggl project objects.
	 * Returns an object if the request failed, otherwise an array of "project" objects.
	 *
	 * @return mixed
	 */
	public function get_projects() {
		return $this->get_data_store( 'projects' );
	}

	/**
	 * Gets a array of Toggl client objects.
	 * Returns an object if the request failed, otherwise an array of "client" objects.
	 *
	 * @return mixed
	 */
	public function get_clients() {
		return $this->get_data_store( 'clients' );
	}

	/**
	 * Retrieves a given data store either from a cached file, or the Toggl API. If getting
	 * fresh data from the API, it stores the data in a cache file.
	 *
	 * @param string $store The slug of the data store. Should also be a Toggl API endpoint.
	 * @return mixed Returns an object if the request failed, otherwise an array of objects.
	 */
	private function get_data_store( $store ) {
		$data = $this->get_cache_data( $store );

		if ( ! $data ) {
			$this->line( "🔄 Refreshing " . substr( $store, 0, -1 ) . " data..." );
			$url  = $this->api_base . "/workspaces/" . $this->workspace . "/$store";
			$response = $this->remote_get( $url, $this->toggl_api_request_args() );

			// If `message` is set, then we've had an error - pass it back.
			if ( 200 !== $response['status'] ) {
				$this->line( "❌ There was an error retrieving $store data: status " . $response['status'] );
				return false;
			}

            $data = $response['response'];

			// Transform the return data into an array indexed by the project ID to make it easier to select from.
			foreach( $data as $index => $item ) {
				if ( $item->id ) {
					$data[ $item->id ] = $item;
					unset( $data[ $index ] );
				}
			}

			// We received good data back - store it in cache
			$this->set_cache_data( $store, $data );
		}

		return (object) $data;
	}

	/**
	 * Gets time entries from Toggl using a start date and end date.
	 *
	 * @param boolean $start_date
	 * @param boolean $end_date
	 * @return mixed An array of time entry objects or false if invalid start/end date or error from Toggl API.
	 */
	private function get_entries( $start_date = false, $end_date = false ) {
		if ( ! $start_date ) {
			$last_logged_start = $this->get_last_logged();
			if ( $last_logged_start ) {
				// This will need to be adjusted for GMT offset.
				$start_date = $last_logged_start;
			} else {
				// Fallback to midnight of the current day.
				$start_date = $this->validate_date_string( gmdate( 'Y-m-d H:i:s', strtotime('today') ) );
			}
		} else {
			$start_date = $this->validate_date_string( $start_date );
            if ( ! $start_date ) {
                return false;
            }
		}
		$start_date_param = '&start_date=' . $start_date;

		$this->line( "🕒 Using start date $start_date" );

		// Set up $end_date if we have it.
		$end_date_param = '';
		if ( $end_date ) {
			$end_date = $this->validate_date_string( $end_date );
            if ( ! $end_date ) {
                return false;
            }
			$end_date_param = '&end_date=' . $end_date;

			$this->line( "🕒 Using end date $end_date" );
		}

		// Noting that we need to store the last logged as at least one second later than the start time of the last logged entry.
		$url  = $this->api_base . '/time_entries?' . $start_date_param . $end_date_param;
		$response = $this->remote_get( $url, $this->toggl_api_request_args() )['response'];

		// If `message` is set, then we've had an error - pass it back.
		if ( isset( $response->message ) ) {
			$this->line( "❌ There was an error retrieving entries from Toggl: " . $response->message );
			return false;
		}

		return $response;
	}

	/**
	 * Gets the last logged date from the data.json file.
	 *
	 * @return mixed Returns string if found, otherwise false.
	 */
	private function get_last_logged() {
        if ( file_exists( dirname( __FILE__ ) . '/.data/data.json' ) ) {
			$last_logged_raw = json_decode( file_get_contents( dirname( __FILE__ ) . '/.data/data.json' ) );
			$this->line( "📆 Getting last logged date..." );
			return '' !== $last_logged_raw->last_logged ? $last_logged_raw->last_logged : false;
		}

		return false;
	}

	/**
	 * Saves the last logged date to the data.json file.
	 *
	 * @param string $date
	 */
	private function set_last_logged( $date ) {
		// Need to increase $date by 1 second so we don't get the last logged entry on the next run.
		$time = (int) strtotime( $date );
		$time++;
		$datetime = new DateTime();
		$datetime->setTimestamp( $time );
		$date = $datetime->format( 'c' );

		// Here, were loading the whole data.json file and just updating the last_logged value in case we store anything there in the future.
		$last_logged_raw = json_decode( file_get_contents( dirname( __FILE__ ) . '/.data/data.json' ) );
		$last_logged_raw->last_logged = $date;

		file_put_contents( dirname( __FILE__ ) . '/.data/data.json', json_encode( $last_logged_raw, JSON_PRETTY_PRINT ) );
		$this->line( "📆 Setting last logged date to $date" );
	}

    /**
     * Validates a date string to work with how Toggle wants its date strings.
     *
     * @param string $date_string
     * @return string | bool The date string with TZ offset or false if invalid.
     */
    private function validate_date_string( $date_string ) {
        $date_string_with_offset =  $date_string . $this->get_datetimezone_offset();

        try {
            $datetime    = new DateTime( $date_string_with_offset );
            $date_string_with_offset = $datetime->format('c');
        } catch ( Exception $e ) {
            $this->line( "❌ Invalid date supplied: $date_string" );
            return false;
        }

        return $date_string_with_offset;
    }

    /**
     * Gets the timezone offset in the "P" format (±00:00)
     *
     * @return string
     */
    private function get_datetimezone_offset() {
        try{
			// Try the config file first.
			$tz = new DateTimeZone( $this->timezone );
		} catch( Exception $e ) {
			// Let the user know that their config is missing or has an invalid timezone set.
			$this->line( "🕒 Missing or invalid timezone set in config...trying system timezone." );

			try{
				// That didn't work. Attempt to read the system timezone.
				$timezone = substr( readlink('/etc/localtime'), strlen ('/var/db/timezone/zoneinfo/') );
				$tz = new DateTimeZone( $timezone );
			} catch ( Exception $e ){
				// Fallback to Eastern.
				$this->line( "🕒 Couldn't read the system timezone...falling back to Eastern." );
				$tz = new DateTimeZone( 'America/New_York' );
			}	
		}

        $dt = new DateTime();
        $dt->setTimezone( $tz );
        return $dt->format('P');
    }

	/**
	 * Sets the necessary request args for Toggl.
	 *
	 * @return array
	 */
	private function toggl_api_request_args() {
		$args = array(
            'headers' => array(),
			'auth' => array(
			   'username' => $this->token,
               'password' => 'api_token'
			),
	   );
	   return $args;
	}
}