<?php
/**
 * Plugin Name: BP Limit Members Per group
 * Plugin URI: http://buddydev.com/plugins/bp-limit-members-per-group/
 * Version: 1.0
 * Author: Brajesh
 * Description: Limit the no. of members a group can have. Group admins can enable/disable the limit and set their own limit if admin wants to allow them
 */

class BP_Limit_Members_Group_Helper{
    
    private static $instance;
    
    private function __construct(){
       
        
      
        //remove default BuddyPress Hooks
        add_action( 'init', array( $this, 'remove_hooks' ) );
        
        //Admin Options under BuddyPress->Settings 
        add_action( 'bp_admin_init',array( $this, 'register_settings' ), 20 );
        
         //load text domain
        add_action ( 'bp_loaded', array( $this, 'load_textdomain' ), 2 );
        
        //handle group join action
         add_action( 'bp_actions', array( $this, 'action_join_group' ) );
         
         //handle ajax group join/leave action
         add_action( 'wp_ajax_joinleave_group', array($this, 'ajax_joinleave_group' ));
         
         
         //for individual group preferences if override is allowed
         if( self::get_override() ){
            //show form
            add_action( 'bp_before_group_settings_admin', array( $this, 'group_pref_form' ) );
            add_action( 'bp_before_group_settings_creation_step', array( $this, 'group_pref_form' ) );
            //update settings
            add_action( 'groups_group_settings_edited', array( $this, 'save_group_prefs' ) );
            add_action( 'groups_create_group', array( $this, 'save_group_prefs' ) );
            add_action( 'groups_update_group', array( $this, 'save_group_prefs' ) );

         }
    }
    /**
     *
     * @return BP_Limit_Members_Group_Helper
     */
    public static function get_instance(){
        
        if( !isset( self::$instance ) )
                self::$instance = new self();
        
        return self::$instance;
    }
    /**
     * Load plugin textdomain for translation
     */
    function load_textdomain(){
        
        $locale = apply_filters( 'bp-limit-group-membership_get_locale', get_locale() );
        
        // if load .mo file
        if ( !empty( $locale ) ) {
            $mofile_default = sprintf( '%slanguages/%s.mo', plugin_dir_path(__FILE__), $locale );

            $mofile = apply_filters( 'bp-limit-group-membership_load_textdomain_mofile', $mofile_default );

                    if (is_readable( $mofile ) ) {
                        // make sure file exists, and load it
                        load_textdomain( 'bp-limit-group-membership', $mofile );
            }
        }
       
    }
    
    
    /**
     * Removes various BuddyPress actions and attches our own for custom functionality
     */
    function remove_hooks(){
        //remove the action to handle group join
        if( has_action( 'bp_actions', 'groups_action_join_group' ) )
            remove_action( 'bp_actions', 'groups_action_join_group' );
        
        //remove ajax handler for join/leave group in the themes including bp-default js
        if( has_action( 'wp_ajax_joinleave_group', 'bp_dtheme_ajax_joinleave_group' ) )
            remove_action( 'wp_ajax_joinleave_group', 'bp_dtheme_ajax_joinleave_group' );
        
        //remove ajax handler for themes supporting legacy template(bp legacy)
        if( has_action( 'wp_ajax_joinleave_group', 'bp_legacy_theme_ajax_joinleave_group' ) )
            remove_action( 'wp_ajax_joinleave_group', 'bp_legacy_theme_ajax_joinleave_group' );
        
        //for private group request membership, we will have to disallow the buddypress core function to handle it
        
        self::change_screen_callback();
    }
     /**
     * Changes the callback used for request membership/group invite accept
     * 
     */
    function change_screen_callback(){
        
      
        if( has_action( 'bp_screens', 'groups_screen_group_request_membership' ) ){
            
           remove_action( 'bp_screens', 'groups_screen_group_request_membership', 3 );
           //add our own callback to show the compose screen          
           add_action( 'bp_screens', array( $this, 'screen_request_membership' ), 3 );
            
           
        }
        if( has_action( 'bp_screens', 'groups_screen_group_invites' ) ){
            
           remove_action( 'bp_screens', 'groups_screen_group_invites', 3 );
           //add our own callback to show the compose screen          
           add_action( 'bp_screens', array( $this, 'screen_invites' ), 3 );
            
           
        }
        
    }
    /**
     * Handle Group Join Action
     * 
     */
    function action_join_group(){
        
        $bp = buddypress();

        if ( !bp_is_single_item() || !bp_is_groups_component() || !bp_is_current_action( 'join' ) )
            return false;

        // Nonce check
        if ( !check_admin_referer( 'groups_join_group' ) )
            return false;

    
        // Skip if banned or already a member
        if ( !groups_is_user_member( bp_loggedin_user_id(), $bp->groups->current_group->id ) && !groups_is_user_banned( bp_loggedin_user_id(), $bp->groups->current_group->id ) ) {

        //check for the current group settings
        
      //can the membership be requested
       if( !self::can_request( $bp->groups->current_group->id ) ){
           
           bp_core_add_message( self::get_message(), 'error' );
		   bp_core_redirect( bp_get_group_permalink( $bp->groups->current_group ) );
       }
           
       
		// User wants to join a group that is not public
		if ( $bp->groups->current_group->status != 'public' ) {
			if ( !groups_check_user_has_invite( bp_loggedin_user_id(), $bp->groups->current_group->id ) ) {
				bp_core_add_message( __( 'There was an error joining the group.', 'bp-limit-group-membership' ), 'error' );
				bp_core_redirect( bp_get_group_permalink( $bp->groups->current_group ) );
			}
		}

		// User wants to join any group
		if ( !groups_join_group( $bp->groups->current_group->id ) )
			bp_core_add_message( __( 'There was an error joining the group.', 'bp-limit-group-membership' ), 'error' );
		else
			bp_core_add_message( __( 'You joined the group!', 'bp-limit-group-membership' ) );

		bp_core_redirect( bp_get_group_permalink( $bp->groups->current_group ) );
	}

	bp_core_load_template( apply_filters( 'groups_template_group_home', 'groups/single/home' ) );
    }
    
    /**
     * Ajaxified join/leave group
     * 
     */
    function ajax_joinleave_group() {
        // Bail if not a POST action
        if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) )
            return;

        // Cast gid as integer
        $group_id = (int) $_POST['gid'];

        if ( groups_is_user_banned( bp_loggedin_user_id(), $group_id ) )
            return;

        if ( ! $group = groups_get_group( array( 'group_id' => $group_id ) ) )
            return;

        if ( ! groups_is_user_member( bp_loggedin_user_id(), $group->id ) ) {

             
          

          if( !self::can_request($group->id)) {

              echo self::get_message();
                    exit(0);
           }

            if ( 'public' == $group->status ) {
                check_ajax_referer( 'groups_join_group' );

                if ( ! groups_join_group( $group->id ) ) {
                    _e( 'Error joining group', 'bp-limit-group-membership' );
                } else {
                    echo '<a id="group-' . esc_attr( $group->id ) . '" class="leave-group" rel="leave" title="' . __( 'Leave Group', 'bp-limit-group-membership' ) . '" href="' . wp_nonce_url( bp_get_group_permalink( $group ) . 'leave-group', 'groups_leave_group' ) . '">' . __( 'Leave Group', 'bp-limit-group-membership' ) . '</a>';
                }

            } elseif ( 'private' == $group->status ) {
                check_ajax_referer( 'groups_request_membership' );

                if ( ! groups_send_membership_request( bp_loggedin_user_id(), $group->id ) ) {
                    _e( 'Error requesting membership', 'bp-limit-group-membership' );
                } else {
                    echo '<a id="group-' . esc_attr( $group->id ) . '" class="membership-requested" rel="membership-requested" title="' . __( 'Membership Requested', 'bp-limit-group-membership' ) . '" href="' . bp_get_group_permalink( $group ) . '">' . __( 'Membership Requested', 'bp-limit-group-membership' ) . '</a>';
                }
            }

        } else {
            check_ajax_referer( 'groups_leave_group' );

            if ( ! groups_leave_group( $group->id ) ) {
                _e( 'Error leaving group', 'bp-limit-group-membership' );
            } elseif ( 'public' == $group->status ) {
                echo '<a id="group-' . esc_attr( $group->id ) . '" class="join-group" rel="join" title="' . __( 'Join Group', 'bp-limit-group-membership' ) . '" href="' . wp_nonce_url( bp_get_group_permalink( $group ) . 'join', 'groups_join_group' ) . '">' . __( 'Join Group', 'bp-limit-group-membership' ) . '</a>';
            } elseif ( 'private' == $group->status ) {
                echo '<a id="group-' . esc_attr( $group->id ) . '" class="request-membership" rel="join" title="' . __( 'Request Membership', 'bp-limit-group-membership' ) . '" href="' . wp_nonce_url( bp_get_group_permalink( $group ) . 'request-membership', 'groups_send_membership_request' ) . '">' . __( 'Request Membership', 'bp-limit-group-membership' ) . '</a>';
            }
        }

        exit;
    }
    /**
     *  Handle limiting for private group membership request
     * @global type $bp
     * @return boolean
     */
    function screen_request_membership() {
        global $bp;

        if ( !is_user_logged_in() )
            return false;

        $bp = buddypress();

        if ( 'private' != $bp->groups->current_group->status )
            return false;
        // If the user has submitted a request, send it.
        if ( isset( $_POST['group-request-send']) ) {

            // Check the nonce
            if ( !check_admin_referer( 'groups_request_membership' ) )
                return false;

            //if the group has already enough members, do not allow
            if( !self::can_request( $bp->groups->current_group->id ) ){
                bp_core_add_message( self::get_message(), 'error' );
                bp_core_redirect( bp_get_group_permalink( $bp->groups->current_group ) );
            }

           groups_screen_group_request_membership();

        }
    }
    
    /**
     * 
     * Handle group invitation accept request
     * 
     */
    function screen_invites() {
        $group_id = (int)bp_action_variable( 1 );

        if ( bp_is_action_variable( 'accept' ) && is_numeric( $group_id ) ) {
            // Check the nonce
            if ( !check_admin_referer( 'groups_accept_invite' ) )
                return false;
             //if the group has already enough members, do not allow
            if( !self::can_request( $group_id ) ){
                bp_core_add_message( self::get_message(), 'error' );
                bp_core_redirect( trailingslashit( bp_loggedin_user_domain() . bp_get_groups_slug() . '/' . bp_current_action() ) );

            }
        
            
            
        }
        groups_screen_group_invites();
    }

    /**
     * Get the limit set by admin
     * 
     * @return type 
     */
    public static function get_limit(){
        $allowed_count=absint( get_option( 'bp_limit_group_membership_count', 20 ) );//20 default request count   
        return apply_filters( 'bp_limit_group_membership_count', $allowed_count );
    }
  
    
    public static function get_message(){
        return bp_get_option( 'bp_limit_group_membership_message', __( 'This group has limited membership. Please contact admin to join this group.', 'bp-limit-friendship-request' ) );
    }
   
    public static function get_override(){
        return bp_get_option( 'bp_limit_group_membership_allow_override', 1 );
    }
   
    
   /**
    * Check if the membership for current group can be requested
    * @param type $group_id
    * @return boolean
    */
    public static function can_request( $group_id = false ){
        
        $limit = 0;
        if( is_super_admin() )
            return true;//do not stop super admin
        
        //check if group override is allowed
        
        $override_allowed = self::get_override();
        
        if( $override_allowed ){
            
            //check if the group has disabled restriction
            if( groups_get_groupmeta( $group_id, 'group-disable-membership-limit' ) )
                    return true;//anyone can join, we don't have any issue
            //otherwise let us check the limit
            $limit = groups_get_groupmeta( $group_id, 'limit_membership_count');
            
        }
        
        if( !$limit )
            $limit = self::get_limit ();
        
        //check for the allowed 
         $member_count = groups_get_groupmeta( $group_id, 'total_member_count');
         
           if( $limit <= $member_count )
               return false;
           
           return true;
        
    }
    
   /*** Single Group options if enabled*/

    //Group Preference form
    function group_pref_form(){
        if( !self::get_override() )//if override is not allowed
            return;//do not show if options are not enabled for individual group component
        $group = groups_get_current_group();

        $count = groups_get_groupmeta( $group->id, 'limit_membership_count' );
        if( !$count )
            $count = self::get_limit();
        ?>
        <div class="checkbox">
            <label><input type="checkbox" name="group-disable-membership-limit" id="group-disable-membership-limit" value="1" <?php echo checked( 1, groups_get_groupmeta( $group->id, 'group-disable-membership-limit' ) ); ?>/> <?php _e( 'Disable Membership Limit', 'bp-limit-group-membership' ) ?></label>
        </div>
        <div class="limit-membership-count">
            <label> <?php _e( 'No. of Allowed members', 'bp-limit-group-membership' ) ?><input type="text" name="limit_membership_count" id="limit_membership_count" value="<?php echo $count;?>" size="5" maxlength="10"  style="width:20px;"/></label>
        </div>
    <?php

    }

    

    /**
     * Update group preference
     * @param type $group_id
     */
    function save_group_prefs($group_id){
          $disable = $_POST['group-disable-membership-limit'];
          groups_update_groupmeta($group_id, 'group-disable-membership-limit', $disable );
          groups_update_groupmeta($group_id, 'limit_membership_count', intval($_POST['limit_membership_count']) );

    }
     
    
    /* admin helper*/
    
    
    /** register settings for admin*/
    function register_settings(){
            // Add the ajax Registration settings section
            add_settings_section( 'bp_limit_group_membership_request',__( 'BP Limit Members Per Group',  'bp-limit-group-membership' ), array( $this, 'reg_section' ), 'bp-limit-group-membership' );
            // Allow loading form via jax or nt?
            add_settings_field( 'bp_limit_group_membership_count', __( 'How many Users Can join?',   'bp-limit-group-membership' ), array( $this, 'settings_field_count' ),   'buddypress', 'bp_limit_group_membership_request' );
            add_settings_field( 'bp_limit_group_membership_message', __( 'What Message you want to display if the group reaches the limit?',   'bp-limit-group-membership' ), array( $this, 'settings_field_message' ),   'buddypress', 'bp_limit_group_membership_request' );
            add_settings_field( 'bp_limit_group_membership_allow_override', __( 'Do you want to allow group admins to override settings?',   'bp-limit-group-membership' ), array( $this, 'settings_field_override' ),   'buddypress', 'bp_limit_group_membership_request' );
           
            register_setting  ( 'buddypress', 'bp_limit_group_membership_count','intval' );
            register_setting  ( 'buddypress', 'bp_limit_group_membership_message');
            register_setting  ( 'buddypress', 'bp_limit_group_membership_allow_override');
    }  
    
    function reg_section(){
        
    }
    
    function settings_field_count(){
            $val=self::get_limit();?>
            <input id="bp_limit_group_membership_count" name="bp_limit_group_membership_count" type="text" value="<?php echo $val;?>"  />
    <?php
    }
   
  
   function settings_field_message(){
        $val=self::get_message() ?>

         <label>
             <textarea id='bp_limit_group_membership_message' name='bp_limit_group_membership_message' rows="5" cols="80" ><?php echo esc_textarea( $val);?></textarea></label>     
   <?php    
       
   }
    function settings_field_override(){
        $val=self::get_override(); ?>

            <label><input id='bp_limit_group_membership_allow_override' name='bp_limit_group_membership_allow_override' type='checkbox' value='1' <?php echo checked(1,$val);?> /> <?php _e( 'Yes','bp-limit-group-membership');?></label>     
   <?php    
       
   }
   
}

BP_Limit_Members_Group_Helper::get_instance();