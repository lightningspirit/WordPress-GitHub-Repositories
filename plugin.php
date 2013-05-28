<?php

/*
Plugin Name: GitHub Repositories
Plugin URI: http://github.com/lightningspirit/WordPress-GitHub-Repositories
Version: 0.1
Description: Enables search, installation and update of WordPress plugins hosted in GitHub.
Author: lightningspirit
Author URI: http://profiles.wordpress.org/lightningspirit
Text Domain: github-repos
Domain Path: /languages/
Network: true
Tags: plugin, github, github plugins, git plugins
License: GPLv2 - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/


/*
 * @package GitHub Repositories
 * @author lightningspirit
 * @copyright lightningspirit 2013
 * This code is released under the GPL licence version 2 or later
 * http://www.gnu.org/licenses/gpl.txt
 */



// Checks if it is accessed from Wordpress' index.php
if ( ! function_exists( 'add_action' ) ) {
	die( 'I\'m just a plugin. I must not do anything when called directly!' );

}




if ( ! class_exists ( 'WP_GitHub_Repositories' ) ) :
/**
 * WP_GitHub_Repositories
 *
 * @package WordPress
 * @subpackage GitHub Repositories
 * @since 0.1
 */
class WP_GitHub_Repositories {

	/**
	 * GitHub API URL get repository info
	 *
	 * @since 0.1
	 */
	private $api_url = 'https://api.github.com/repos/%owner%/%repository%';
	//var $test_repurl = 'git@github.com:lightningspirit/WordPress-Functions-Ahead.git';
	private $user = 'lightningspirit';
	
	
	/** 
	 * {@internal Missing Short Description}}
	 * 
	 * @since 0.1
	 * 
	 * @return void
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( __CLASS__, 'init' ) );

	}
	
	/** 
	 * {@internal Missing Short Description}}
	 * 
	 * @since 0.1
	 * 
	 * @return void
	 */
	public static function init() {
		// Load the text domain to support translations
		load_plugin_textdomain( 'github-repos', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		

		// Just to be parsed by gettext
		$plugin_headers = array(
			__( 'GitHub Repositories', 'github-repos' ).
			__( 'Enables search, installation and update of WordPress plugins hosted in GitHub.', 'github-repos' )
		);


		// if new upgrade
		if ( version_compare( (int) get_option( 'github_repositories_plugin_version' ), '0.1', '<' ) )
			add_action( 'admin_init', array( __CLASS__, 'do_upgrade' ) );
		
		add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_action_links' ), 10, 2 );
		
		/** Register actions for admin pages */
		if ( is_admin() ) {

			// Hook inside the update API
			add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'api_check' ) );
		
			// Hook into the plugin details screen
			add_filter( 'plugins_api', array( __CLASS__, 'api_information' ), 10, 3 );
			
			
		}

	}
	
	/** 
	 * {@internal Missing Short Description}}
	 * 
	 * @since 0.1
	 * 
	 * @return void
	 */
	public static function do_upgrade() {
		update_option( 'github_repositories_plugin_version', '0.1' );
		
	}
	
	/** 
	 * {@internal Missing Short Description}}
	 * 
	 * @since 0.1
	 * 
	 * @param string $links
	 * @param string $file
	 * @return void
	 */
	public static function plugin_action_links( $links, $file ) {
		if ( $file != plugin_basename( __FILE__ ) )
			return $links;

		$settings_link = '';
		$set_i18n = __( 'Set Repositories', 'github-repos' );

		if ( is_multisite() && is_super_admin() )
			$settings_link = '<a href="' . network_admin_url( 'settings.php' ) . '?page=manage-repositories">' . $set_i18n . '</a>';

		elseif ( current_user_can( 'manage_options' ) )
			$settings_link = '<a href="options-general.php?page=manage-repositories">' . $set_i18n . '</a>';

		array_unshift( $links, $settings_link );

		return $links;

	}

	/**
	 * Parse Repository name
	 *
	 * @since 0.1
	 *
	 * @param string $name
	 * @return bool
	 */
	public function parse_repository_name( $name ) {
		if ( false !== strpos( $name, 'git@github.com:' ) )
			$name = str_replace( 'git@github.com:', '', $name );

		if ( false !== strpos( $name, 'https://github.com/' ) )
			$name = str_replace( 'https://github.com/', '', $name );

		if ( false !== strpos( $name, '.git' ) )
			$name = rtrim( $name, '.git' );

		return $name;

	}

	/**
	 * Send request to our API
	 *
	 * @since 0.1
	 * 
	 * @param array $args 
	 * @return array
	 */
	public function api_request( $args ) {

		// Send request
		$return = self::get_api_request( $args );

		if ( is_wp_error( $return ) ) {
			// Let's talk to the user...
			add_action( 'admin_notices', 'create_function( $return, "echo \'<p>\'.$return->get_error_message().\'</p>\'")' );
			return;

		}

		// Parse request
		$info = self::parse_api_request( $return, $args );
		
		return $info;

	}

	/**
	 * Send request to our API
	 *
	 * @since 0.1
	 * 
	 * @param array $args
	 * @return array
	 */
	public function get_api_request( $args ) {

		// Build the URL
		$url = '';
		if ( isset( $args['action'] ) )
			$url = traillingslashit( self::$api_url ) . $args['action'];

		if ( empty( $url ) )
			return new WP_Error( 'no_url', __( 'No URL or action was defined.', 'github-repo' ) );

		// Send request
		$request = wp_remote_post( $url );

		// Make sure the request was successful
		if( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			// Request failed
			return new WP_Error( 'request_failed', __( 'Request to GitHub API failed.', 'github-repo' ) );		
		}

		// Read server response, which should be an object
		return json_decode( wp_remote_retrieve_body( $request ) );
		
	}

	/**
	 * Parse API return to get uniform with what WordPress expects
	 *
	 * @since 0.1
	 * @todo Some tests to the API...
	 * 
	 * @param array $args 
	 * @return array
	 */
	public function parse_api_request( $args ) {
		// TODO: Some tests to the API...
		return (object) $args;
		
	}

	/**
	 * Get repository plugin metadata
	 *
	 * @since 0.1
	 *
	 * @param string $repository
	 * @return array|WP_Error 
	 */
	public function get_repository_metadata( $repository, $context = '' ) {
		global $wp_respositories;

		if ( isset( $wp_respositories[ $repository ] ) ) {
			if ( $wp_respositories[ $repository ] )
				return $wp_respositories[ $repository ];

		}


		// Set default headers
		$default_headers = array(
			'plugin_name' => 'Plugin Name',
			'plugin_uri' => 'Plugin URI',
			'description' => 'Description',
			'version' => 'Version',
			'tags' => 'Tags',
			'author' => 'Author',
			'author_uri' => 'Author URI',
			'contributors' => 'Contributors',
			'version' => 'Version',
			'requires' => 'Requires at least',
			'provides' => 'Provides',
			'depends' => 'Depends',
			'tested' => 'Tested up to',
			'license' => 'License',
			'license_uri' => 'License URI', 
		);

		// Make the request
		$args = array(
			'action' 	 => 'get-metadata',
			'request' 	 => 'contents/.wordpress',
			'repository' => $respository,
		);
		$return = self::api_request( $args );

		if ( ! isset( $return->name ) )
			return false;

		$repo_data = base64_decode( $return->content );

		// Make sure we catch CR-only line endings.
		$repo_data = str_replace( "\r", "\n", $repo_data );

		if ( $context && $extra_headers = apply_filters( "extra_{$context}_headers", array() ) ) {
			$extra_headers = array_combine( $extra_headers, $extra_headers ); // keys equal values
			$all_headers = array_merge( $extra_headers, (array) $default_headers );

		} else {
			$all_headers = $default_headers;

		}

		foreach ( $all_headers as $field => $regex ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $repo_data, $match ) && $match[1] )
				$all_headers[ $field ] = _cleanup_header_comment( $match[1] );
			else
				$all_headers[ $field ] = '';

		}

		$wp_respositories[ $repository ] = $all_headers;
		return $all_headers;

	}

	/**
	 * Check if the given repository is actually a valid WordPress Plugin repository
	 *
	 * @since 0.1
	 *
	 * @param string $repository
	 * @return bool
	 */
	function is_valid_plugin_repository( $repository ) {
		return (bool) self::get_repository_metadata( $repository );

	}

	/**
	 * Check Selected GitHub repositories agains our API.
	 *
	 * @since 1.0
	 *
	 * @param object $transient
	 * @return object $transient
	 */
	public function api_check( $transient ) {
		 
		// Check the update transient.
		if( empty( $transient->checked ) )
			return $transient;
		
		// The transient contains the 'checked' information
		// Now append to it information form your own API
		$plugins = self::get_registered_plugins();
		
		// Check if there is no plugin from installed
		// if so, return the transient and do nothing
		if ( empty( $plugins ) )
			return $transient;
		
		// For each installed plugin...
		foreach ( $plugins as $plugin ) { 
    
			// POST data to send to your API
			$args = array(
				'action' => 'update-check',
				'plugin_name' => $plugin->slug,
				'version' => $transient->checked[$plugin->slug],
			
			);

			// Send request checking for an update
			$response = $this->api_request( $args );
			

			// If response is false, don't alter the transient
			if( false !== $response ) {
				$transient->response[$plugin->slug] = $response;
			
			}
			
		}

		return $transient;
		
	}
	
	
	
	
	
	/**
	 * View Version detail information
	 * @since 1.0
	 */
	public function api_information( $false, $action, $args ) {
		global $locale;
		
		// The transient contains the 'checked' information
		// Now append to it information form your own API
		$plugins = $this->get_registered_plugins();
		
		if ( !isset( $args->slug ) )
			return false;

		// Check if this plugins API is about this plugin
		if( array_key_exists( $args->slug, $plugins ) ) :
			$plugin_slug = $args->slug;
		
		else :
			return false;
			
		endif;

		// POST data to send to your API
		$args = array(
			'action' => 'plugin-information',
			'plugin_name' => $plugin_slug,
			'locale' => $locale,
			
		);

		// Send request for detailed information
		$response = $this->api_request( $args );
		
		return $response;
		
	}

	
	
	/**
	 * Retrieve plugins registered in the API
	 * and merge them with the actual plugins installed in this system.
	 * @since 1.0
	 */
	public function get_git_repositories() {
		
		// Get available repositories

		
		// Send request for detailed information
		$response = $this->api_request( $args );
		
		
		foreach ( $response as $plugin ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin->slug ) )
				$this->plugins[ $plugin->slug ] = $plugin;
		
		}
		
		return $this->plugins;
		
	}
	
	

}

//new WP_GitHub_Repositories;

endif;


/**
 * github_repositories_activation_hook
 *
 * Register activation hook for plugin
 *
 * @since 0.1
 */
function github_repositories_activation_hook() {
	// Wordpress version control. No compatibility with older versions. ( wp_die )
	if ( version_compare( get_bloginfo( 'version' ), '3.5', '<' ) ) {
		wp_die( 'GitHub Repositories is not compatible with versions prior to 3.5' );

	}

}
register_activation_hook( __FILE__, 'github_repositories_activation_hook' );
