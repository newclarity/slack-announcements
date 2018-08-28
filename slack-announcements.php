<?php
/*
Plugin Name: Slack Announcements
Plugin URI: https://github.com/newclarity/slack-announcements
Description: Announce posts of selected types on Slack.
Version: 0.1.0
Author: The NewClarity Team
Author URI: http://newclarity.net
Text Domain: slack-announcements
*/

add_action( 'init', [ 'Slack_Announcements', 'on_load' ], 11 );

class Slack_Announcements {

	const ENDPOINT        = 'https://slack.com/api/chat.postMessage';
	const SETTINGS_OPTION = 'sa-slack-announcements';
	const CAPABILITY      = 'slack_announcements_push';

	/**
	 *
	 */
	static function on_load() {

		/**
		 * Add an activation hook (though we may not need it...)
		 */
		register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );

		/**
		 * enqueue jQuery UI spinner when adding a new revision
		 */
		add_action( 'admin_enqueue_scripts', [ __CLASS__, '_admin_enqueue_scripts' ]);

		/*
		 * Add Settings page in Admin menu
		 * Change 'Post' to 'Story' in admin menu.
		 */
		add_action( 'admin_menu', [ __CLASS__, '_admin_menu' ] );

		/**
		 * Render a metabox with a list of available channels and an optional message
		 */
		add_filter( 'add_meta_boxes', [ __CLASS__, '_add_meta_boxes_11' ], 11, 2 );

		/**
		 * Push announcement to Slack when a post of supported post type is saved
		 */
		add_action('save_post', [__CLASS__, '_save_post'], 10, 3 );
	}

	/**
	 *
	 */
	static function activate() {

	}

	/**
	 * @return string[]
	 */
	static function supported_post_types(){
		$settings = self::settings();
		return apply_filters( 'slack_announcements_post_types', $settings['post_types'] );
	}

	/**
	 * @param string $post_type
	 *
	 * @return bool
	 */
	static function is_supported_post_type( $post_type ){
		return in_array( $post_type, self::supported_post_types());
	}

	/**
	 * @return string[]
	 */
	static function allowed_roles(){
		$settings = self::settings();
		return apply_filters( 'slack_announcements_roles', $settings['roles'] );
	}

	/**
	 * @return string[]
	 */
	static function available_channels(){
		$settings = self::settings();
		return apply_filters( 'slack_announcements_channels', $settings['channels'] );
	}

	/**
	 * @return string[]
	 */
	static function slack_api_token(){
		$settings = self::settings();
		return apply_filters( 'slack_announcements_api_token', $settings['api_token'] );
	}

	/**
	 * @return string
	 */
	static function slack_username(){
		$settings = self::settings();

		if( !$username = apply_filters( 'slack_announcements_username', $settings['username'] )) {
			if( $user = wp_get_current_user() ) {
				$username = $user->display_name;
			}
		}
		return $username;
	}

	/**
	 * @param $hook
	 */
	static function _admin_enqueue_scripts( $hook ){
		$screen = get_current_screen();
		if( 'post' == $screen->base && self::is_supported_post_type( $screen->post_type ) ){
			wp_enqueue_script( 'slack-announcements', plugin_dir_url( __FILE__ ) .'assets/slack-announcements.js', [ 'jquery' ] );
		}

	}

	/**
	 * Fire admin_menu hook
	 */
	static function _admin_menu() {

		/*
		 * Add Settings page in Admin menu
		 */
		$label              = __( 'Slack Announcements', 'slack-announcements' );
		$settings_page_hook = add_options_page(
			$label,
			$label,
			'manage_options',
			'slack-announcements',
			array( __CLASS__, 'the_settings_page' )
		);

	}

	/**
	 * @return array
	 */
	static function settings(){

		static $settings;

		if( !isset( $settings )) {

			$defaults = array(
				'api_token'  => '',
				'username'   => '',
				'channels'   => [ 'general' ],
				'post_types' => [ 'post' ],
				'roles'      => [ 'administrator', 'editor' ],
			);

			if ( ! $settings = get_option( self::SETTINGS_OPTION ) ) {
				$settings = $defaults;
			} else {
				$settings = wp_parse_args( $settings, $defaults );
			}

			$settings = apply_filters('slack_announcements_settings', $settings );
		}

		return $settings;
	}

	/**
	 * Render the admin settings edit page.
	 */
	static function the_settings_page(){

		self::_maybe_update_values();

		echo '<div class="wrap">';
		echo '<h1>'.__('Slack Announcements', 'slack-announcements'). '</h1>';
		echo '</div>';

		//delete_option( self::SETTINGS_OPTION );

		$stored = self::settings();
?>
<form method="post">

	<?php wp_nonce_field('slack_announcements_settings', '_wpnonce', $referrer = true, $echo = true ); ?>

	<table class="form-table">
		<tbody>
		<tr>
			<th scope="row"><label for="api_token"><?php _e('Slack API Token', 'slack-announcements'); ?></label></th>
			<td><input name="slack[api_token]" type="text" id="api_token" aria-describedby="api-token-desc" value="<?php esc_attr_e( $stored['api_token'] ); ?>" class="regular-text">
				<p class="description" id="api-token-desc"><?php _e('In order to get the API Token visit: https://api.slack.com/custom-integrations/legacy-tokens. The token will look something like this `xoxo-2100000415-0000000000-0000000000-ab1ab1', 'slack-announcements'); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="default_username"><?php _e('Default Username', 'slack-announcements'); ?></label></th>
			<td><input name="slack[username]" type="text" id="username" aria-describedby="username-desc" value="<?php esc_attr_e( $stored['username'] ); ?>" class="regular-text">
				<p class="description" id="username-desc"><?php _e('Leave empty to have announcements signed by current user.', 'slack-announcements'); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php _e('Slack Channels','site-announcements'); ?></th>
			<td>
				<textarea name="slack[channels]" aria-describedby="channels-desc" ><?php echo esc_textarea( implode("\n", $stored['channels'] )); ?></textarea><br>
				<p class="description" id="channels-desc"><?php _e('Known Slack Channels (one per row or a comma-separated list).', 'slack-announcements'); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="post_types"><?php _e('Post Types', 'slack-announcements'); ?></label></th>
			<td>
				<fieldset>

				<?php
				$post_types = get_post_types();

				unset(
					$post_types['attachment'],
					$post_types['revision'],
					$post_types['revision'],
					$post_types['nav_menu_item'],
					$post_types['custom_css'],
					$post_types['customize_changeset']
				);

				$post_types = apply_filters( 'sn_enabled_post_types', $post_types );

				foreach ($post_types as $post_type ): ?>
					<label for="post_types">
						<input name="slack[post_types][]" id="post_types" aria-describedby="post-types-desc" type="checkbox" value="<?php echo $post_type; ?>" <?php checked( in_array( $post_type, $stored['post_types']), true ); ?>>
						<?php echo $post_type; ?>
					</label>
					<br>
				<?php endforeach; ?>
					<p class="description" id="post-types-desc"><?php _e('Types of posts that can be pushed as Slack announcements.', 'slack-announcements'); ?></p>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php _e('Roles', 'slack-announcements'); ?></th>
			<td> <fieldset>
					<?php
					$roles = apply_filters( 'sn_enabled_roles', get_editable_roles() );

					foreach ($roles as $role => $details ): ?>
						<label for="users_can_register">
							<input name="slack[roles][]" type="checkbox" id="roles" aria-describedby="roles-desc" value="<?php echo $role; ?>" <?php checked( in_array( $role, $stored['roles']), true ); ?>>
							<?php echo $details['name']; ?>
						</label>
						<br>
					<?php endforeach; ?>
					<p class="description" id="roles-desc"><?php _e('Roles that get <code>slack_announcements_push</code> capability.', 'slack-announcements'); ?></p>
				</fieldset>
			</td>
		</tr>
		</tbody>
	</table>

	<p class="submit">
		<input type="submit" name="submit_settings" id="submit" class="button button-primary" value="<?php _e('Save Changes', 'slack-announcements'); ?>">
	</p>

</form>
		
<?php

	}

	/**
	 * Maybe collect, sanitize and store posted values.
	 */
	static function _maybe_update_values() {

		do {
			if ( !isset( $_POST[ 'submit_settings' ] ) || !check_admin_referer( 'slack_announcements_settings' ) ) {
				break;
			}

			$posted = apply_filters( 'slack_announcements_posted', isset( $_POST['slack'] ) ? $_POST['slack'] : null );

			if( !empty( $posted['channels'] ) ){
				$posted['channels'] = array_map('trim', explode("\n", str_replace(',', "\n", str_replace( '#', '', $posted['channels'] ))));
			}

			if ( !$sanitized = self::_sanitize_settings( $posted ) ) {
				break;
			}

			/**
			 * update role capabilities
			 */
			$roles = get_editable_roles();
			foreach ( $roles as $role_name => $details ){
				$role = get_role( $role_name );
				if( $role && in_array( $role_name, $sanitized['roles'] )){
					$role->add_cap( self::CAPABILITY );
				} else {
					$role->remove_cap( self::CAPABILITY );
				}
			}

			//echo '<pre>' . print_r( compact('posted', 'sanitized' ), true ) . '</pre>';

			update_option( self::SETTINGS_OPTION, $sanitized );

		} while ( false );

	}

	/**
	 * Sanitizes and validates settings.
	 *
	 * @param $values
	 *
	 * @return array|null Will return null if validation fails.
	 */
	static function _sanitize_settings( $values ) {

		$values = wp_parse_args( $values, array(
			'api_token'  => '',
			'username'   => '',
			'post_types' => ['post'],
			'channels'   => ['general'],
			'roles'      => ['administrator', 'editor'],
		));
		
		foreach ( $values['roles'] as $idx => $role ){
			if( !get_role( $role ) ){
				/**
				 * drop invalid roles
				 */
				unset( $values['roles'][ $idx ] );
			}
		}

		if( !in_array( 'administrator', $values['roles']) ){
			/**
			 * make sure administrator is always enabled
			 */
			$values['roles'][] = 'administrator';
		}

		foreach ( $values['post_types'] as $idx => $post_type ){
			if( !post_type_exists( $post_type ) ){
				/**
				 * drop invalid post types
				 */
				unset( $values['post_types'][ $idx ] );
			}
		}

		$values['channels'] = array_filter( array_map( 'trim', $values['channels'] ) );

		return apply_filters( 'slack_announcements_sanitize', $values );

	}

	/**
	 * Add a metabox for Site Release edit page to allow selecting which Slack channels to push to upon publishing.
	 */
	static function _add_meta_boxes_11() {

		add_meta_box(
			'push-slack-announcements',
			__('Slack Announcements', 'slack-releases'),
			[__CLASS__, 'the_metabox'],
			self::supported_post_types(),
			'side',
			'default'
		);

	}

	/**
	 * Render the metabox.
	 */
	static function the_metabox(){

		$settings = self::settings();

		if( !trim( $settings['api_token'] )){

			_e('Slack Announcements plugin is not properly set up.', 'slack-announcements');

			printf( ' '. __('You can %s.'), '<a href="' . admin_url('options-general.php?page=slack-announcements') .'">' . __('change its settings here', 'slack-announcements') .'</a>');
		} else {

            echo '<h4>' . __('Channels:', 'slack-announcements') . '</h4>';

            foreach ($settings['channels'] as $channel ): ?>
            <label for="channels">
                <input name="slack[channels][]" type="checkbox" class="jq-announce-channels" value="<?php esc_attr_e( $channel ); ?>">
                <?php echo '#' . esc_html( $channel ); ?>
            </label>
            <br><?php
            endforeach;

            echo '<p class="howto jq-announce-channels-desc" id="slack-channels-desc">'.__('Channels to push this announcement to.', 'slack-announcements').'</p>';
            echo '<p class="howto" style="color:red; display:none;">'.__('Please pick some channels to<br> push this announcement to!', 'slack-announcements').'</p>';
            echo '<h4>' . __('Message:', 'slack-announcements') . '</h4>';
            echo '<textarea style="width: 100%;     margin: -12px 0 5px 0;" name="slack[message]" rows="4"></textarea>';

            echo '<input style="float: right;" type="submit" class="jq-announce button button-primary button-large" name="slack[submit]" value="' . __('Update &amp; Notify', 'slack-announcements') . '"><div class="clear"></div>';

            echo wp_nonce_field( '_slack_announcement_nonce', 'slack_announcement_nonce' );

		}

	}

	/**
     * Maybe push announcement about the current post to Slack.
     *
	 * @param int $post_id
	 * @param WP_Post $post
	 * @param bool $update
	 */
	static function _save_post( $post_id, $post, $update ) {

		do {

			if ( ! self::is_supported_post_type( get_post_type( $post_id ) )  ) {
				break;
			}

			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				break;
			}

			$postdata = WABE::http_POST();

			if ( ! isset( $postdata[ 'slack' ] ) || !isset( $postdata[ 'slack' ]['submit'] )) {
				break;
			}

			$channels = isset( $postdata['slack']['channels'] )
				? $postdata['slack']['channels']
				: array();

			if( empty( $channels )){
				break;
			}

			if ( ! wp_verify_nonce( $postdata[ 'slack_announcement_nonce' ], '_slack_announcement_nonce' ) ) {
				break;
			}

			if ( ! current_user_can( self::CAPABILITY, $post_id ) ) {
				break;
			}

			$message  = isset( $postdata['slack']['message'] )
                ? $postdata['slack']['message']
                : '';

			$message .= "\n" . get_permalink( $post_id );

			self::slack( $message, $channels );

		} while( false );
	}

	/**
	 * Send a Message to a Slack Channel.
	 *
	 * @credit: https://gist.github.com/nadar/68a347d2d1de586e4393
	 *
	 * In order to get the API Token visit: https://api.slack.com/custom-integrations/legacy-tokens
	 * The token will look something like this `xoxo-2100000415-0000000000-0000000000-ab1ab1`.
	 *
	 * @param string $message The message to post into a channel.
	 * @param string|string[] $channels The name of the channel prefixed with #, example #foobar
	 *
	 * @return boolean
	 */
	static function slack( $message, $channels ){

		if( ! is_array( $channels ) ){
			$channels = array( $channels );
        }

		foreach ( $channels as $channel ) {
			$ch = curl_init( self::ENDPOINT );


			$data = http_build_query( [
				"channel"  => '#' . $channel, //"#mychannel",
				"text"     => $message,
				"token"    => self::slack_api_token(),
				"username" => self::slack_username(),
			] );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
			$result = curl_exec( $ch );
			curl_close( $ch );
		}

		return $result;
	}

}