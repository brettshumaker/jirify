<?php

/**
 * If I'm going to extend this to allow for additional time tracking APIs, that selection
 * should be stored in config. Read that value and load the appropriate files.
 */
require 'class-jirify.php';
require 'class-jirify-jira.php';

// Get our config file.
$config = json_decode( file_get_contents( dirname( __FILE__ ) . '/.config/config.json' ) );

// Load up base Jirify
$jirify = new Jirify();

// Set up our service - default to Clockify.
if ( $config->service ) {
    $this_service      = 'Jirify_' . ucwords( $config->service );
    $this_service_file = dirname( __FILE__ ) . '/class-jirify-' . strtolower( $config->service ) . '.php';
} else {
    $this_service = 'Jirify_Clockify';
    $this_service_file = dirname( __FILE__ ) . '/class-jirify-clockify.php';
}

// Make sure the service file exists.
if ( ! file_exists( $this_service_file ) ) {
    $jirify->line( "Could not load file $this_service_file." );
    die();
}

require $this_service_file;

// Make sure the service class exists.
if ( ! class_exists( $this_service ) ) {
    $jirify->line( "Class $this_service does not exist." );
    die();
}

// Load up specific Jirify service
$jirify_service = new $this_service( $config );

if ( isset( $argv[1] ) ) {
    if ( method_exists( $this_service, $argv[1] ) ) {
        $method = $argv[1];
        $args =  parse_cli_args( array_slice( $argv, 2 ) );
    
        // Handle our log_time method
        if ( 'log_time' === $method ) {
            $jirify_service->log_time(
                ( isset( $args['start_date'] ) ? $args['start_date'] : false ),
                ( isset( $args['end_date'] ) ? $args['end_date'] : false ),
                ( isset( $args['dry_run'] ) ? $args['dry_run'] : false )
            );
        } else {
            $jirify_service->$method();
        }
    } else {
        $jirify->line( "Method $argv[1] does not exist in $this_service." );
        die();
    }
} else {
    $jirify->line( "You need to specify a function to run. i.e. log_time" );
    die();
}

/**
 * Crude parsing of CLI args. Just strips off `--`, explodes and returns key value pair.
 * Any argument not starting with `--` will be ignored.
 *
 * @param array $args
 * @return array
 */
function parse_cli_args( $args ) {
    $parsed_args = array();
    foreach ( $args as $raw_arg ) {
        if ( stripos( $raw_arg, '--' ) !== 0 ) {
            continue;
        }
        $raw_arg = str_replace( '--' , '', $raw_arg );
        $raw_arg_arr = explode( '=', $raw_arg );
        $parsed_args[ $raw_arg_arr[0] ] = $raw_arg_arr[1];
    }
    return $parsed_args;
}
