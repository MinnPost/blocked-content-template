<?php
/*
Plugin Name: Blocked Content Template
Plugin URI:
Description:
Version: 0.0.1
Author: Jonathan Stegall
Author URI: http://code.minnpost.com
License: GPL2+
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: blocked-content-template
*/

class Blocked_Content_Template {

	public $option_prefix;
	public $version;
	public $slug;

	/**
	 * @var object
	 * Static property to hold an instance of the class; this seems to make it reusable
	 *
	 */
	static $instance = null;

	/**
	* Load the static $instance property that holds the instance of the class.
	* This instance makes the class reusable by other plugins
	*
	* @return object
	*
	*/
	static public function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new Blocked_Content_Template();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->version = '0.0.1';
		$this->slug = 'blocked-content-template';

		// admin settings
		//$this->admin = $this->load_admin();

		$this->single_level_meta_key = '_access_level';
		$this->level_prefix = 'member_';
		$this->member_levels = array(
			1 => 'bronze',
			2 => 'silver',
			3 => 'gold',
			4 => 'platinum',
		);
		$this->blocked_template_suffix = '-paywalled';

		$this->add_actions();
	}

	private function add_actions() {
		// this could be used for any other template as well, but we are sticking with single.
		add_filter( 'single_template', array( $this, 'template_show_or_block' ), 10, 3 );
	}

	/**
	* Choose template depending on whether a post has an access level, and if so, whether a user can access it.
	* The important thing to know is that this adds type-blocked-postname, type-blocked-post, type-blocked
	* to the beginning of the template hierarchy, and then attempts to locate them in the theme.
	* If no blocked templates exist, it will contine to check the default hierarchy for a matching file.
	*
	* @param string $template
	* @param string $type
	* @param array $templates
	*
	* @return string template
	*/
	public function template_show_or_block( $template, $type, $templates ) {
		global $post;
		$user_id = get_current_user_id();
		$can_access = $this->user_can_access( $post->ID, $user_id );
		if ( true === $can_access ) {
			return $template;
		} else {
			$blocked_templates = array();
			foreach ( $templates as $default_template ) {
				$blocked_templates[] = substr_replace( $default_template, $type . $this->blocked_template_suffix, 0, strlen( $type ) );
			}
			$blocked_templates = array_merge( $blocked_templates, $templates );
			$template = locate_template( $blocked_templates );
			return $template;
		}
	}

	/**
	* Determine whether a user can access a post, based on the post ID and the user ID.
	* This checks the field defined as $this->single_level_meta_key on the post, and the user's roles for a matching level.
	*
	* @param int $post_id
	* @param int $user_id
	*
	* @return bool $can_access
	*/
	private function user_can_access( $post_id = '', $user_id = '' ) {

		if ( '' === $post_id ) {
			$post_id = get_the_ID();
		}

		if ( '' === $user_id ) {
			$user_id = get_current_user_id();
		}

		$access_level = absint( get_post_meta( $post_id, $this->single_level_meta_key, true ) );
		if ( '' === $access_level ) {
			return true;
		}

		// at this point, the default access answer should be false because the single item has a level meta value. user roles override it.
		$can_access = false;
		$content_member_level = $this->member_levels[ $access_level ];

		$user_info = get_userdata( $user_id );

		$user_roles = array_filter( $user_info->roles, function ( $key ) {
			return strpos( $key, $this->level_prefix ) === 0;
		} );

		if ( is_array( $user_roles ) && ! empty( $user_roles ) ) {
			$highest_user_role = $user_roles[ max( array_keys( $user_roles ) ) ];
			$highest_user_role_num = absint( array_search(
				substr(
					$highest_user_role,
					strlen( $this->level_prefix )
				),
				$this->member_levels
			) );
			if ( $highest_user_role_num >= $access_level ) {
				$can_access = true;
			}
		}
		return $can_access;
	}


	/**
	* load the admin stuff
	* creates admin menu to save the config options
	*
	* @throws \Exception
	*/
	public function load_admin() {
		$admin = '';
		add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 );
		return $admin;
	}

	/**
	* Display a Settings link on the main Plugins page
	*
	* @param array $links
	* @param string $file
	* @return array $links
	* These are the links that go with this plugin's entry
	*/
	public function plugin_action_links( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file ) {
			$settings = '<a href="' . get_admin_url() . 'options-general.php?page=' . $this->slug . '">' . __( 'Settings', $this->slug ) . '</a>';
			array_unshift( $links, $settings );
		}
		return $links;
	}

}

// Instantiate our class
$blocked_content_template = Blocked_Content_Template::get_instance();
