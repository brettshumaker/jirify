<?php

class Jirify_Clockify extends Jirify {
	private $token;
	private $workspace;
	private $user_id;
	private $api_base;
	private $timezone;
	private $jira;

	public function __construct( $config ) {
		$this->token       = $config->token;
		$this->workspace   = $config->workspace;
		$this->user_id     = $config->user_id;
		$this->timezone    = $config->timezone;
		$this->api_base    = sprintf(
			'https://api.clockify.me/api/v1/workspaces/%s',
			rawurlencode( $this->workspace )
		);

		// Set up the Jira connection.
		$this->jira = new Jirify_Jira( $config->jira_token, $config->jira_email, $config->jira_endpoint );
	}

	/**
	 * Sends time entries to Jira. This is reason we're here, folks. This handles getting all entries from
	 * Clockify after a given start date. The start date can be supplied in any form that can construct a
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
			$project_id = $time_entry->projectId;
			$project    = $projects->$project_id;
			$client_id  = $project->clientId;
			$description = ! empty( $time_entry->description ) ? '"' . $time_entry->description . '"' : '';

			// If we don't have a client ID for the project, we can't track it - skip.
			if ( ! $client_id ) {
				$skip_out = implode( ' ', array_filter( [$description, $project->name, "(no client assigned)."] ) );
				$this->line( "❕ Skipping log for " . $skip_out );
				continue;
			}

			// timeInterval->duration will be NULL if timer is running, skip.
			if ( is_null( $time_entry->timeInterval->duration ) ) {
				continue;
			}

			$client       = $clients->$client_id;
			$start        = $time_entry->timeInterval->start;
			$duration     = $this->round_up( $this->clockify_duration_to_seconds( $time_entry->timeInterval->duration ) );

			if ( 0 === $duration ) {
				// There was a problem with the duration string, skip
				$this->line( "❌ Invalid duration string for " . $client->name . ": " . $time_entry->timeInterval->duration );
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

	public function test_log_date() {
		$projects          = $this->get_projects();
		$clients           = $this->get_clients();
		$entries           = $this->get_entries();
		$last_logged_start = false;

		// There was an error getting one of the necessary data types. Let's stop.
		if ( false === $entries || false === $projects || false === $clients ) {
			$this->line( 'Encountered an error.' );
			$this->line( var_export( [ $entries, $projects, $clients ], true ) );
			return;
		}

		if ( empty( $entries ) ) {
			$this->line( "⚪ No entries found." );
		}

		$test_projects = [];
		$this->line( gettype( $projects ) );
		foreach ( $projects as $pid => $p ) {
			$this->line( $pid . ' - ' . gettype( $pid ) );
			$test_projects[] = $pid;
		}
		$this->line();

		// Loop through each time entry
		foreach( $entries as $time_entry ) {
			$project_id = $time_entry->projectId;
			$project    = $projects->$project_id;
			$client_id  = $project->clientId;

			// If we don't have a client ID for the project, we can't track it - skip.
			if ( ! $client_id ) {
				$this->line( "❕ Skipping log for " . $project->name . " (no client assigned)." );
				continue;
			}

			// timeInterval->duration will be NULL if timer is running, skip.
			if ( is_null( $time_entry->timeInterval->duration ) ) {
				continue;
			}

			$client       = $clients->$client_id;
			$start        = $time_entry->timeInterval->start;
			$duration     = $this->round_up( $this->clockify_duration_to_seconds( $time_entry->timeInterval->duration ) );

			if ( $start > $last_logged_start ) {
				$last_logged_start = $start;
			}
			$this->line( "✅ $last_logged_start ||| $start ||| Would have logged " . $this->get_friendly_duration_output( $duration ) . " for " . $client->name );
		}

		$this->line( "Would have set last logged to: $last_logged_start" );
	}

	/**
	 * Gets a array of Clockify project objects.
	 * Returns an object if the request failed, otherwise an array of "project" objects.
	 *
	 * @return mixed
	 */
	public function get_projects() {
		return $this-> get_data_store( 'projects' );
	}

	/**
	 * Gets a array of Clockify client objects.
	 * Returns an object if the request failed, otherwise an array of "client" objects.
	 *
	 * @return mixed
	 */
	public function get_clients() {
		return $this-> get_data_store( 'clients' );
	}

	/**
	 * Retrieves a given data store either from a cached file, or the Clockify API. If getting
	 * fresh data from the API, it stores the data in a cache file.
	 *
	 * @param string $store The slug of the data store. Should also be a Clockify API endpoint.
	 * @return mixed Returns an object if the request failed, otherwise an array of objects.
	 */
	private function get_data_store( $store ) {
		$data = $this->get_cache_data( $store );

		if ( ! $data ) {
			$this->line( "🔄 Refreshing " . substr( $store, 0, -1 ) . " data..." );
			$url  = $this->api_base . "/$store/";
			$data = $this->remote_get( $url, $this->clockify_api_request_args() )['response'];

			// If `message` is set, then we've had an error - pass it back.
			if ( isset( $data->message ) ) {
				$this->line( "❌ There was an error retrieving $store data: " . $data->message );
				return false;
			}

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
	 * Gets time entries from Clockify using a start date and end date.
	 * 
	 *
	 * @param boolean $start_date
	 * @param boolean $end_date
	 * @return mixed An array of time entry objects or false if invalid start/end date or error from Clockify API.
	 */
	private function get_entries( $start_date = false, $end_date = false ) {
		if ( ! $start_date ) {
			$last_logged_start = $this->get_last_logged();
			if ( $last_logged_start ) {
				// This will need to be adjusted for GMT offset.
				$start_date = $last_logged_start;
			} else {
				// Fallback to midnight of the current day.
				$start_date = gmdate('Y-m-d') . 'T00:00:00Z';
			}
		} else {
			// Use what we were given
			try {
				$start_datetime = new DateTime( $start_date );
				$start_date     = $start_datetime->format('Y-m-d\TH:i:s\Z');
			} catch ( Exception $e ) {
				$this->line( "❌ Invalid start date supplied: $start_date" );
				return false;
			}
		}

		/**
		 * Clockify is a weirdo and expects $start_date to be in your local timezone
		 * but will only return data in UTC. So we have to convert our $start_date to
		 * the local timezone before we send it to Clockify.
		 */
		$start_date = $this->convert_utc_to_offset( $start_date );
		$start_date_param = '&start=' . $start_date;

		$this->line( "🕒 Using start date $start_date" );

		// Set up $end_date if we have it.
		$end_date_param = '';
		if ( $end_date ) {
			try {
				$end_datetime = new DateTime( $end_date );
			} catch ( Exception $e ) {
				$this->line( "❌ Invalid end date supplied: $end_date" );
				return false;
			}
			/**
			 * Clockify is a weirdo and expects $end_date to be in your local timezone
			 * but will only return data in UTC. So we have to convert our $end_date to
			 * the local timezone before we send it to Clockify.
			 */
			$end_date       = $this->convert_utc_to_offset( $end_datetime->format('Y-m-d\TH:i:s\Z') );
			$end_date_param = '&end=' . $end_date;

			$this->line( "🕒 Using end date $end_date" );
		}

		// Noting that we need to store the last logged as at least one second later than the start time of the last logged entry.
		$url  = $this->api_base . '/user/' . $this->user_id . '/time-entries/?in-progress=false&page-size=200' . $start_date_param . $end_date_param;
		$response = $this->remote_get( $url, $this->clockify_api_request_args() )['response'];

		// If `message` is set, then we've had an error - pass it back.
		if ( isset( $response->message ) ) {
			$this->line( "❌ There was an error retrieving entries from Clockify: " . $response->message );
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
		$last_logged_raw = json_decode( file_get_contents( dirname( __FILE__ ) . '/.data/data.json' ) );
		$this->line( "📆 Getting last logged date..." );
		return '' !== $last_logged_raw->last_logged ? $last_logged_raw->last_logged : false;
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
		$date = $datetime->format( 'Y-m-d\TH:i:s\Z' );

		// Here, were loading the whole data.json file and just updating the last_logged value in case we store anything there in the future.
		$last_logged_raw = json_decode( file_get_contents( dirname( __FILE__ ) . '/.data/data.json' ) );
		$last_logged_raw->last_logged = $date;

		file_put_contents( dirname( __FILE__ ) . '/.data/data.json', json_encode( $last_logged_raw, JSON_PRETTY_PRINT ) );
		$this->line( "📆 Setting last logged date to $date" );
	}

	/**
	 * Converts a given date to a different timezone.
	 * Clockify expects the start/end dates to be in your local time (idk why) so we first
	 * try to use the timezone set in the config file. If that doesn't work, we try to fall
	 * back to reading the local time on the system. If THAT doesn't work, we fall back to
	 * Eastern.
	 *
	 * @param string $date
	 * @return string
	 */
	private function convert_utc_to_offset( string $date ) {
		// Try and set the create the DateTimeZone.
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

		$datetime = new DateTime( $date );
		$datetime->setTimezone( $tz );
		return $datetime->format('Y-m-d\TH:i:s\Z');
	}

	/**
	 * Takes a DateInterval period string and returns the duration in seconds.
	 *
	 * @param string $duration_string
	 * @return int Number of seconds or 0 if invalid duration string.
	 */
	private function clockify_duration_to_seconds( $duration_string ) {
		// Create a dateInterval with our period string.
		try{
			$dateInterval = new DateInterval( $duration_string );
		} catch( Exception $e ) {
			return 0;
		}
		$total_duration = 0;

		// Add Hours in seconds
		$total_duration += (int) $dateInterval->format('%h') * 60 * 60;
		
		// Add Minutes in seconds
		$total_duration += (int) $dateInterval->format('%i') * 60;
		
		// Add Seconds
		$total_duration += (int) $dateInterval->format('%s');

		return $total_duration;
	}

	/**
	 * Retrieves cache data from a data store.
	 *
	 * @param string $store
	 * @return mixed An array of objects or false when expired, not found, or a problem was encountered loading the file
	 */
	private function get_cache_data( $store ) {
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
	private function set_cache_data( $store, $data = array(), $expiry = 60 * 60 * 12 ) {
		$file_path        = dirname( __FILE__ ) . "/.cache/$store.json";
		$expire_timestamp = time() + $expiry;
		$data_to_store    = json_encode( (object) array(
			'expires' => $expire_timestamp,
			$store    => $data,
		) );

		file_put_contents( $file_path, $data_to_store );
	}

	/**
	 * Sets the x-api-key header needed for Clockify from the value in the .config.json file.
	 *
	 * @return array
	 */
	private function clockify_api_request_args() {
		$args = array(
			'headers' => array(
			   'x-api-key: ' . $this->token,
			),
	   );
	   return $args;
	}

	/**
	 * Gets an output string for a "friendly" representation of a number of seconds.
	 * Converts a duration in seconds to Xh Xm Xs as needed for display.
	 *
	 * @param int $duration_in_seconds
	 * @return string
	 */
	private function get_friendly_duration_output( $duration_in_seconds ) {
		$output_duration = new DateIntervalEnhanced( "PT" . $duration_in_seconds ."S" );
		$hours   = (int) $output_duration->recalculate()->format('%h');
		$minutes = (int) $output_duration->recalculate()->format('%i');
		$seconds = (int) $output_duration->recalculate()->format('%s');

		$output = '';

		if ( $hours > 0 ) {
			$output .= $hours . 'h ';
		}

		$output .= $minutes . 'm' . ( $seconds > 0 ? ' ' . $seconds . 's' : '' );

		return $output;
	}
}

/**
 * Utility class to overflow lots of seconds into hours minutes, and seconds.
 * Used by Jirify_Clockify->get_friendly_duration_output()
 */
class DateIntervalEnhanced extends DateInterval {

    public function recalculate()
    {
        $from = new DateTime;
        $to = clone $from;
        $to = $to->add($this);
        $diff = $from->diff($to);
        foreach ($diff as $k => $v) $this->$k = $v;
        return $this;
    }

}