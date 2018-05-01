<?php
/*
Plugin Name: Blocked Content Template
Plugin URI:
Description:
Version: 0.0.1
Author: Jonathan Stegall
Author URI: https://code.minnpost.com
License: GPL2+
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: blocked-content-template
*/

class Blocked_Content_Template {

	private $option_prefix;
	private $version;
	private $slug;

	public $single_level_meta_key;
	public $level_prefix;
	public $member_levels;
	public $minimum_branded_level;

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
		$this->slug    = 'blocked-content-template';

		// admin settings
		//$this->admin = $this->load_admin();

		$this->single_level_meta_key = '_access_level';
		$this->level_prefix          = 'member_';
		$this->member_levels         = array(
			'registered' => 'registered users',
			'members'    => 'all members',
			1            => 'bronze',
			2            => 'silver',
			3            => 'gold',
			4            => 'platinum',
		);

		$this->minimum_branded_level = $this->get_minimum_branded_level();

		$this->blocked_template_suffix = '-paywalled';

		$can_see_blocked_content       = array( 'administrator', 'editor', 'business' );
		$this->can_see_blocked_content = apply_filters( 'blocked_content_template', $can_see_blocked_content );

		$this->add_actions();
	}

	/**
	* Things to run when plugin loads
	*
	*/
	private function add_actions() {
		// this could be used for any other template as well, but we are sticking with single.
		add_filter( 'single_template', array( $this, 'template_show_or_block' ), 10, 3 );
	}

	/**
	* Set and return the mininum branded level for content. This is when the content should look different because of its access level.
	*
	* @return int $minimum_branded_level
	*/
	public function get_minimum_branded_level() {
		$minimum_branded_level = 2;
		$minimum_branded_level = apply_filters( 'blocked_content_minimum_branded_level', $minimum_branded_level );
		return $minimum_branded_level;
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
		$user_id    = get_current_user_id();
		$can_access = $this->user_can_access( $post->ID, $user_id );
		if ( true === $can_access ) {
			return $template;
		} else {
			$blocked_templates = array();
			foreach ( $templates as $default_template ) {
				$blocked_templates[] = substr_replace( $default_template, $type . $this->blocked_template_suffix, 0, strlen( $type ) );
			}
			$blocked_templates = array_merge( $blocked_templates, $templates );
			$template          = locate_template( $blocked_templates );
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

		$content_access_level = get_post_meta( $post_id, $this->single_level_meta_key, true );
		if ( '' === $content_access_level ) {
			return true;
		}

		// at this point, the default access answer should be false because the single item has a level meta value. user roles override it.
		$can_access = false;

		// if the user id is not a user, they can't access any restricted content
		if ( 0 === $user_id ) {
			return false;
		}

		// if the content access level is only registered, let the user in because they are signed in
		if ( 'registered' === $content_access_level ) {
			return true;
		}

		if ( true === filter_var( $content_access_level, FILTER_VALIDATE_INT ) ) {
			$content_access_level = absint( $content_access_level );
		}

		$content_member_level = $this->member_levels[ $content_access_level ];

		$user_info      = get_userdata( $user_id );
		$all_user_roles = $user_info->roles;
		$user_roles     = array_filter( $user_info->roles, function ( $key ) {
			return strpos( $key, $this->level_prefix ) === 0;
		} );

		if ( is_array( $user_roles ) && ! empty( $user_roles ) ) {
			$highest_user_role     = $user_roles[ max( array_keys( $user_roles ) ) ];
			$highest_user_role_num = absint( array_search(
				substr(
					$highest_user_role,
					strlen( $this->level_prefix )
				),
				$this->member_levels
			) );
			// the user is a member and this content is for all members. let them in.
			if ( 'members' === $content_access_level && in_array( $highest_user_role_num, $this->member_levels ) ) {
				$can_access = true;
			}
			// the user's member level matches the content member level
			if ( $highest_user_role_num >= $content_access_level ) {
				$can_access = true;
			}
		}

		// if user has a role that allows them to see everything, let them see everything
		$can_user_see_everything = array_intersect( $this->can_see_blocked_content, $all_user_roles );
		if ( is_array( $can_user_see_everything ) && ! empty( $can_user_see_everything ) ) {
			$can_access = true;
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
			$settings = '<a href="' . get_admin_url() . 'options-general.php?page=' . $this->slug . '">' . __( 'Settings', 'blocked-content-template' ) . '</a>';
			array_unshift( $links, $settings );
		}
		return $links;
	}

}

// Instantiate our class
$blocked_content_template = Blocked_Content_Template::get_instance();
