<?php
/**
 * Habari AdminHandler Class
	* Backbone of the admin area, handles requests and functionality.
 *
 * @package Habari
 */

class AdminHandler extends ActionHandler
{
	private $theme= NULL;

	/**
	 * Verifies user credentials before creating the theme and displaying the request.
	 */
	public function __construct() {
		$user= User::identify();
		if ( !$user ) {
			Session::add_to_set('login', $_SERVER['REQUEST_URI'], 'original');
			Utils::redirect( URL::get( 'user', array( 'page' => 'login' ) ) );
			exit;
		}
		if ( !$user->can( 'admin' ) ) {
			die( _t( 'Permission denied.' ) );
		}
		$user->remember();
	}

	/**
	 * Dispatches the request to the defined method. (ie: post_{page})
	 */
	public function act_admin() {
		$page= ( isset( $this->handler_vars['page'] ) && !empty( $this->handler_vars['page'] ) ) ? $this->handler_vars['page'] : 'dashboard';
		$this->theme= Themes::create( 'admin', 'RawPHPEngine', Site::get_dir( 'admin_theme', TRUE ) );
		$this->set_admin_template_vars( $this->theme );
		switch( $_SERVER['REQUEST_METHOD'] ) {
			case 'POST':
				// Handle POSTs to the admin pages
				$fn= 'post_' . $page;
				if ( method_exists( $this, $fn ) ) {
					$this->$fn();
				}
				else {
					$classname= get_class( $this );
					echo sprintf( _t( "\n%s->%s() does not exist.\n" ), $classname, $fn );
					exit;
				}
				break;
			default:
				// Handle GETs of the admin pages
				$fn= 'get_' . $page;
				if ( method_exists( $this, $fn ) ) {
					$this->$fn();
					exit;
				}
				// If a get_ function doesn't exist, just load the template and display it
				$files= glob( Site::get_dir( 'admin_theme', TRUE ) . '*.php' );
				$filekeys= array_map( create_function( '$a', 'return basename( $a, \'.php\' );' ), $files );
				$map= array_combine( $filekeys, $files );
				if ( isset( $map[$page] ) ) {
					$this->display( $page );
				}
				else {
					// The requested console page doesn't exist
					header( 'HTTP/1.0 404 Not Found' );
					$this->header();
					_e( 'Whooops!' );
					$this->footer();
				}
				break;
		}
	}

	/**
	 * Handle incoming requests to /admin_ajax for admin ajax requests
	 */
	public function act_admin_ajax() {
		$context = $this->handler_vars['context'];
		if(method_exists($this, 'ajax_' . $context)) {
			call_user_func(array($this, 'ajax_' . $context), $this->handler_vars);
		}
		else {
			header('HTTP/1.1 403 Forbidden', true, 403);
			die();
		}
	}

	/**
	 * Handles post requests from the options admin page.
	 */
	public function post_options() {
		extract($this->handler_vars);
		$fields= array( 'title' => 'title', 'tagline' => 'tagline', 'about' => 'about', 'pagination' => 'pagination', 'pingback_send' => 'pingback_send', 'comments_require_id' => 'comments_require_id' );
		$checkboxes= array( 'pingback_send', 'comments_require_id' );
		foreach ( $checkboxes as $checkbox ) {
			if ( !isset( ${$checkbox} ) ) {
				${$checkbox}= 0;
			}
		}
		foreach ( $fields as $input => $field ) {
			if ( Options::get( $field ) != ${$input} ) {
				Options::set( $field, ${$input} );
			}
		}
		Session::notice(_t('Successfully updated options'));
		Utils::redirect( URL::get( 'admin', 'page=options' ) );
	}

	/**
	 * Handles post requests from the dashboard.
	 */
	public function post_dashboard() {
		_e( 'Nothing sends POST requests to the dashboard. Yet.' );
	}

	/**
	 * Handles post requests from the publish page.
	 */
	public function post_publish() {
		extract( $this->handler_vars );
		if ( isset( $slug ) ) {
			$post= Post::get( array( 'slug' => $slug, 'status' => Post::status( 'any' ) ) );
			$post->title= $title;
			$post->slug= $newslug;
			$post->tags= $tags;
			$post->content= $content;
			$post->content_type= $content_type;
			if ( ( $post->status != Post::status( 'published' ) ) && ( $status == Post::status('published') ) ) {
				$post->pubdate= date( 'Y-m-d H:i:s' );
			}
			else {
				$post->pubdate= $pubdate;
			}
			$post->status= $status;
			if ( isset($comments_disabled) && $comments_disabled == TRUE ) {
				$post->info->comments_disabled= TRUE;
			}
			elseif ( $post->info->comments_disabled == TRUE ) {
				unset( $post->info->comments_disabled );
			}
			$post->update();
		}
		else {
			$postdata= array(
				'slug' => $newslug,
				'title' => $title,
				'tags' => $tags,
				'content' => $content,
				'user_id' => User::identify()->id,
				'pubdate' => ($pubdate == '') ? date( 'Y-m-d H:i:s' ) : $pubdate,
				'status' => $status,
				'content_type' => $content_type,
			);
			$post= Post::create( $postdata );
			if ( $comments_disabled == TRUE ) {
				$post->info->comments_disabled= TRUE;
				$post->update();
			}
		}
		Session::notice(sprintf(_t('The post \'%s\' has been saved as %s.'), $title, Post::status_name($status)));
		Utils::redirect( URL::get( 'admin', 'page=publish&slug=' . $post->slug ) );
	}

	function get_publish()
	{
		$this->theme->silos = Media::dir();
		$this->display( 'publish' );
	}

	/**
	 * Deletes a post from the database.
	 */
	function post_delete_post() {
		extract( $this->handler_vars );
		$okay= TRUE;
		if ( empty( $slug ) || empty( $nonce ) || empty( $timestamp ) || empty( $PasswordDigest ) ) {
			$okey= FALSE;
		}
		// Ensure the request was submitted less than five minutes ago
		if ( ( time() - strtotime( $timestamp ) ) > 300 ) {
			$okay= FALSE;
		}
		$wsse= Utils::WSSE( $nonce, $timestamp );
		if ( $digest != $wsse['digest'] ) {
			$okay= FALSE;
		}
		if ( !$okay )	{
			Utils::redirect( URL::get( 'admin', 'page=content' ) );
		}
		$post= Post::get( array( 'slug' => $slug ) );
		$post->delete();
		Utils::redirect( URL::get( 'admin', 'page=content' ) );
	}

	/**
	 * Handles post requests from the user profile page.
	 */
	function post_user() {
		// Keep track of whether we actually need to update any fields
		$update= FALSE;
		$results= array( 'page' => 'user' );
		$currentuser= User::identify();
		$user= $currentuser;
		extract( $this->handler_vars );
		$fields= array( 'user_id' => 'id', 'delete' => NULL, 'username' => 'username', 'email' => 'email', 'imageurl' => 'imageurl', 'pass1' => NULL );
		$fields= Plugins::filter( 'adminhandler_post_user_fields', $fields );

		foreach ($fields as $input => $field) {
			switch ( $input ) {
				case 'user_id': // Editing someone else's profile? If so, load that user's profile
					if ( $currentuser->id != $user_id ) {
						$user= User::get_by_id( $user_id );
						$results['user']= $user->username;
					}
					break;
				case 'delete': // Deleting a user
					if ( isset( $delete ) && ( 'user' == $delete ) ) {
						// Extra safety check here
						if ( isset( $user_id ) && ( $currentuser->id != $user_id ) ) {
							$username= $user->username;
							$user->delete();
							Session::notice( sprintf(_t('%s has been deleted'), $username) );
						}
					}
					break;
				case 'username': // Changing username
					if ( isset( $username ) && ( $user->username != $username ) ) {
						$old_name= $user->username;
						$user->username= $username;
						Session::notice( sprintf( _t('%1$s has been renamed to %2$s.'), $old_name, $username ) );
						$update= TRUE;
					}
					break;
				case 'email': // Changing e-mail address
					if ( isset( $email ) && ( $user->email != $email ) ) {
						$user->email= $email;
						Session::notice( $user->username ._t(' email has been changed to ') . $email . '.');
						$update= TRUE;
					}
					break;
				case 'pass1': // Changing password
					if ( isset( $pass1 ) && ( !empty( $pass1 ) ) ) {
						if ( isset( $pass2 ) && ( $pass1 == $pass2 ) ) {
							$user->password= Utils::crypt( $pass1 );
							if ( $user == $currentuser ) {
								$user->remember();
							}
							Session::notice(_t('Password changed successfully.'));
							$update= TRUE;
						}
						else {
							Session::error(_t('The passwords did not match, and were not changed.'));
						}
					}
					break;
				default:
					if ( isset( ${$input} ) && ( $user->info->$field != ${$input} ) ) {
						$user->info->$field= ${$input};
						Session::notice(_t('Userinfo updated!'));
						$update= TRUE;
					}
					break;
			}
		}

		if ( $update == TRUE ) {
			$user->update();
		}

		Utils::redirect( URL::get( 'admin', $results ) );
	}

	/**
	 * Handles post requests from the Users listing (ie: creating a new user)
	 */
	public function post_users() {
		extract( $this->handler_vars );
		$error= '';
		if ( isset( $action ) && ( 'newuser' == $action ) ) {
			if ( !isset( $pass1 ) || !isset( $pass2 ) || empty( $pass1 ) || empty( $pass2 ) ) {
				Session::error(_t('Password mis-match.'), 'adduser');
			}
			if ( !isset( $email ) || empty( $email ) || ( !strstr( $email, '@' ) ) ) {
				Session::error(_t('Please supply a valid email address.'), 'adduser');
			}
			if ( !isset( $username ) || empty( $username ) ) {
				Session::error(_t('Please supply a user name.'), 'adduser');
			}
			// safety check to make sure no such username exists
			$user= User::get_by_name( $username );
			if ( isset($user->id) ) {
				Session::error(_t('That username is already assigned.'), 'adduser');
			}
			if ( !Session::has_errors('adduser') ) {
				$user= new User( array( 'username' => $username, 'email' => $email, 'password' => Utils::crypt( $pass1 ) ) );
				if ( $user->insert() ) {
					Session::notice(sprintf(_t("Added user '%s'"), $username));
				}
				else {
					$dberror= DB::get_last_error();
					Session::error($dberror[2], 'adduser');
				}
			}
			Utils::redirect( );
		}
	}

	/**
	 * Handles plugin activation or deactivation.
	 */
	function post_plugin_toggle() {
		extract( $this->handler_vars );
		if ( 'activate' == strtolower( $action ) ) {
			Plugins::activate_plugin( $plugin );
			$plugins = Plugins::get_active();
			Session::notice( sprintf(_t("Activated plugin '%s'"), $plugins[Plugins::id_from_file($plugin)]->info->name ) );
		}
		else {
			$plugins = Plugins::get_active();
			Session::notice( sprintf(_t("Deactivated plugin '%s'"), $plugins[Plugins::id_from_file($plugin)]->info->name ) );
			Plugins::deactivate_plugin( $plugin );
		}
		Utils::redirect( URL::get( 'admin', 'page=plugins' ) );
	}

	/**
	 * Activates a theme.
	 */
	function post_activate_theme() {
		extract( $this->handler_vars );
		if ( 'activate' == strtolower( $submit ) ) {
			Themes::activate_theme( $theme_name,  $theme_dir );
		}
		Session::notice(sprintf(_t("Activated theme '%s'"), $theme_name));
		Utils::redirect( URL::get( 'admin', 'page=themes' ) );
	}

	/**
	 * Handles the submission of the import form, importing data from a WordPress database.
	 * This function should probably be broken into an importer class, since it is WordPress-specific.
	 */
	function post_import() {
		if ( !isset( $_REQUEST['importer'] ) ) {
			Utils::redirect( URL::get( 'admin', 'page=import' ) );
			exit;
		}

		$this->display( 'import' );
	}

	function get_moderate() {
		$this->post_moderate();
	}

	/**
	 * Handles the submission of the comment moderation form.
	 * @todo Separate delete from "delete until purge"
	 */
	function post_moderate() {
		// Make certain handler_vars local with defaults, and add them to the theme output
		$locals = array(
			'do_update' => false,
			'comment_ids' => null,
			'nonce' => '',
			'timestamp' => '',
			'PasswordDigest' => '',
			'mass_spam_delete' => null,
			'mass_delete' => null,

			'type' => Comment::type('comment'),
			'status' => Comment::status('approved'),
			'limit' => 30,
			'orderby' => 'date DESC',
			'default_radio' => array( 'approve'=>'', 'delete'=>'', 'spam'=>'', 'unapprove'=>'', 'edit' =>'' ),
			'show' => '0',
			'search' => '',
			'search_fields' => array('content'),
			'search_status' => null,
			'search_type' => null,
			'do_search' => false,
			'index' => 1,
		);
		foreach($locals as $varname => $default) {
			$$varname= isset($this->handler_vars[$varname]) ? $this->handler_vars[$varname] : $default;
			$this->theme->{$varname}= $$varname;
		}

		// Setting these mass_delete options prevents any other processing.  Desired?
		if ( isset( $mass_spam_delete ) && $search_status == Comment::STATUS_SPAM ) {
			// Delete all comments that have the spam status.
			Comments::delete_by_status( Comment::STATUS_SPAM );
			Session::notice(_t('Deleted all spam comments'));
			Utils::redirect();
			die();
		}
		elseif ( isset( $mass_delete ) && $search_status == Comment::STATUS_UNAPPROVED ) {
			// Delete all comments that are unapproved.
			Comments::delete_by_status( Comment::STATUS_UNAPPROVED );
			Session::notice(_t('Deleted all unapproved comments'));
			Utils::redirect();
			die();
		}
		// if we're updating posts, let's do so:
		elseif ( $do_update && isset( $comment_ids ) ) {
			$okay= true;
			if ( empty( $nonce ) || empty( $timestamp ) ||  empty( $PasswordDigest ) ) {
				$okay= false;
			}
			// Ensure the request was submitted less than five minutes ago
			if ( ( time() - strtotime( $timestamp ) ) > 300 ) {
				$okay= false;
			}
			$wsse= Utils::WSSE( $nonce, $timestamp );
			if ( $PasswordDigest != $wsse['digest'] ) {
				$okay= false;
			}
			if ( $okay ) {
				foreach ( $comment_ids as $id => $id_change ) {
					if ( $id_change != $show ) { // Skip unmoderated submitted comment_ids
						$ids[]= $id;
						$ids_change[$id]= $id_change;
					}
				}
				$to_update= Comments::get( array( 'id' => $ids ) );
				$modstatus = array('Deleted %d comments' => 0, 'Marked %d comments as spam' => 0, 'Approved %d comments' => 0, 'Unapproved %d comments' => 0, 'Edited %d comments' => 0);
				foreach ( $to_update as $comment ) {
					switch ( $ids_change[$comment->id] ) {
					case 'delete':
						// This comment was marked for deletion
						$comment->delete();
						$modstatus['Deleted %d comments']++;
						break;
					case 'spam':
						// This comment was marked as spam
						$comment= Comment::get( $comment->id );
						$modstatus['Marked %d comments as spam'] += $comment->status != Comment::STATUS_SPAM;
						$comment->status= Comment::STATUS_SPAM;
						$comment->update();
						break;
					case 'approve':
						// This comment was marked for approval
						$comment= Comment::get( $comment->id );
						$modstatus['Approved %d comments'] += $comment->status != Comment::STATUS_APPROVED;
						$comment->status= Comment::STATUS_APPROVED;
						$comment->update();
						break;
					case 'unapprove':
						// This comment was marked for unapproval
						$comment= Comment::get( $comment->id );
						$modstatus['Unapproved %d comments'] += $comment->status != Comment::STATUS_UNAPPROVED;
						$comment->status= Comment::STATUS_UNAPPROVED;
						$comment->update();
						break;
					case 'edit':
						// This comment was edited
						$comment= Comment::get( $comment->id );
						if( $_POST['name_' . $comment->id] != NULL ) {
							$comment->name= $_POST['name_' . $comment->id];
						}
						if( $_POST['email_' . $comment->id] != NULL ) {
							$comment->email= $_POST['email_' . $comment->id];
						}
						if( $_POST['url_' . $comment->id] != NULL ) {
							$comment->url= $_POST['url_' . $comment->id];
						}
						if( $_POST['content_' . $comment->id] != NULL ) {
							$comment->content= $_POST['content_' . $comment->id];
						}
						$comment->update();
						$modstatus['Edited %d comments']++;
						break;
					}
				}
				foreach($modstatus as $key => $value) {
					if($value) {
						Session::notice(sprintf(_t($key), $value));
					}
				}
				unset( $this->handler_vars['change'] );
			}
			Utils::redirect();
			die();
		}

		// Set up the limits select box
		$limits= array( 5, 10, 20, 50, 100 );
		$limits= array_combine($limits, $limits);
		$this->theme->limits= $limits;

		// Set up the type select box
		$types_tmp= Comment::list_comment_types();
		$types['All']= 'All';
		foreach ( $types_tmp as $type_key => $type_val  ) {
			$types[$type_key]= $type_val;
		}
		$this->theme->types= $types;

		// Set up the status select box
		$statuses_tmp= Comment::list_comment_statuses();
		$statuses['All']= 'All';
		foreach ( $statuses_tmp as $status_key => $status_val  ) {
			$statuses[$status_key]= $status_val;
		}
		$this->theme->statuses= $statuses;

		// we load the WSSE tokens
		// for use in the delete button
		$this->theme->wsse= Utils::WSSE();

		$arguments= array(
			'limit' => $limit,
			'offset' => ($index - 1) * $limit,
		);

		// Decide what to display
		$arguments['status']= intval($search_status);
		switch($search_status) {
			case Comment::STATUS_SPAM:
				$this->theme->mass_delete = 'mass_spam_delete';
				$default_radio['spam']= ' checked';
				break;
			case Comment::STATUS_APPROVED:
				$this->theme->mass_delete = '';
				$default_radio['approve']= ' checked';
				break;
			case Comment::STATUS_UNAPPROVED:
			default:
				$this->theme->mass_delete = 'mass_delete';
				$default_radio['unapprove']= ' checked';
				break;
		}
		$this->theme->default_radio= $default_radio;

		if ( '' != $search ) {
			$arguments['criteria']= $search;
			$arguments['criteria_fields']= $search_fields;
			if ( $search_status == 'All' ) {
				unset( $arguments['status'] );
			}
			if ( $search_type == 'All' ) {
				unset( $arguments['type'] );
			}
		}
		$this->theme->comments= Comments::get( $arguments );

		// Get the page count
		$arguments['count']= 'id';
		unset($arguments['limit']);
		unset($arguments['offset']);
		$totalpages= Comments::get( $arguments );
		$pagecount= ceil( $totalpages / $limit );

		// Put page numbers into an array for the page controls to output.
		$pages= array();
		for($z = 1; $z <= $pagecount; $z++) {
			$pages[$z] = $z;
		}
		$this->theme->pagecount= $pagecount;
		$this->theme->pages= $pages;

		$this->display( 'moderate' );
	}

	/**
	 * A POST handler for the admin plugins page that simply passes those options through.
	 */
	public function post_plugins()
	{
		$this->get_plugins();
	}

	public function get_plugins()
	{
		$all_plugins= Plugins::list_all();
		$active_plugins= Plugins::get_active();

		$plugins = array();
		foreach ( $all_plugins as $file ) {
			$plugin = array();
			$plugin_id = Plugins::id_from_file( $file );
			$plugin['plugin_id'] = $plugin_id;
			$plugin['file'] = $file;

			$error = '';
			if ( Utils::php_check_file_syntax( $file, $error ) ) {
				$plugin['debug'] = false;
				if ( array_key_exists( $plugin_id, $active_plugins ) ) {
					$plugin['verb'] = _t( 'Deactivate' );
					$pluginobj = $active_plugins[$plugin_id];
					$plugin['active'] = true;
					$plugin_actions = array();
					$plugin['actions'] = Plugins::filter( 'plugin_config', $plugin_actions, $plugin_id );
				}
				else {
					// instantiate this plugin
					// in order to get its info()
					include_once( $file );
					$pluginobj = Plugins::load( $file );
					$plugin['active'] = false;
					$plugin['verb'] = _t( 'Activate' );
					$plugin['actions'] = array();
				}
				$plugin['info'] = $pluginobj->info;
			}
			else {
				$plugin['debug'] = true;
				$plugin['error'] = $error;
			}
			$plugins[$plugin_id] = $plugin;
		}

		$this->theme->plugins = $plugins;

		$this->display( 'plugins' );
	}

	public function get_content()
	{
		$this->post_content();
	}

	/**
	 * handles POST values from /manage/content
	 * used to control what content to show / manage
	**/
	public function post_content()
	{
		// Make certain handler_vars local with defaults, and add them to the theme output
		$locals = array(
			'do_update' => false,
			'post_ids' => null,
			'nonce' => '',
			'timestamp' => '',
			'PasswordDigest' => '',
			'change' => '',

			'author' => 0,
			'type' => Post::type('entry'),
			'status' => Post::status('published'),
			'limit' => 20,
			'year_month' => 'Any',
			'search' => '',
			'do_search' => false,
			'index' => 1,
		);
		foreach($locals as $varname => $default) {
			$$varname= isset($this->handler_vars[$varname]) ? $this->handler_vars[$varname] : $default;
			$this->theme->{$varname}= $$varname;
		}

		// if we're updating posts, let's do so:
		if ( $do_update && isset( $post_ids ) ) {
			$okay= true;
			if ( empty( $nonce ) || empty( $timestamp ) ||  empty( $PasswordDigest ) ) {
				$okay= false;
			}
			// Ensure the request was submitted less than five minutes ago
			if ( ( time() - strtotime( $timestamp ) ) > 300 ) {
				$okay= false;
			}
			$wsse= Utils::WSSE( $nonce, $timestamp );
			if ( $PasswordDigest != $wsse['digest'] ) {
				$okay= false;
			}
			if ( $okay ) {
				foreach ( $post_ids as $id ) {
					$ids[]= array( 'id' => $id );
				}
				$to_update= Posts::get( array( 'where' => $ids, 'nolimit' => 1 ) );
				foreach ( $to_update as $post ) {
					switch( $change ) {
					case 'delete':
						$post->delete();
						break;
					case 'publish':
						$post->publish();
						break;
					case 'unpublish':
						$post->status= Post::status('draft');
						$post->update();
						break;
					}
				}
				unset( $this->handler_vars['change'] );
			}
		}

		// Set up Authors select box
		$authors_temp= DB::get_results( 'SELECT username, user_id FROM ' . DB::table('users') . ' JOIN ' . DB::table('posts') . ' ON ' . DB::table('users') . '.id=' . DB::table('posts') . '.user_id GROUP BY user_id ORDER BY username ASC');
		array_unshift($authors_temp, new QueryRecord(array('username' => 'All', 'user_id' => 0)));
		$authors= array();
		foreach($authors_temp as $author) {
			$authors[$author->user_id]= $author->username;
		}
		$this->theme->authors = $authors;

		// Set up the dates select box
		$dates= DB::get_column("SELECT pubdate FROM " . DB::table('posts') . ' ORDER BY pubdate DESC');
		$dates= array_map( create_function( '$date', 'return strftime( "%Y-%m", strtotime( $date ) );' ), $dates );
		array_unshift($dates, 'Any');
		$dates= array_combine($dates, $dates);
		$this->theme->dates = $dates;

		// Set up the limits select box
		$limits= array( 5, 10, 20, 50, 100 );
		$limits= array_combine($limits, $limits);
		$this->theme->limits= $limits;

		// we load the WSSE tokens
		// for use in the delete button
		$this->theme->wsse= Utils::WSSE();

		$arguments= array(
			'content_type' => $type,
			'status' => $status,
			'limit' => $limit,
			'offset' => ($index - 1) * $limit,
		);
		if ( 'any' != strtolower($year_month) ) {
			list($arguments['year'], $arguments['month']) = explode('-', $year_month);
		}
		if ( '' != $search ) {
			$arguments['criteria']= $search;
		}
		$this->theme->posts= Posts::get( $arguments );

		// Get the page count
		$arguments['count']= 'id';
		unset($arguments['limit']);
		unset($arguments['offset']);
		$totalpages= Posts::get( $arguments );
		$pagecount= ceil( $totalpages / $limit );

		// Put page numbers into an array for the page controls to output.
		$pages= array();
		for($z = 1; $z <= $pagecount; $z++) {
			$pages[$z] = $z;
		}
		$this->theme->pagecount= $pagecount;
		$this->theme->pages= $pages;

		$this->display( 'content' );
	}

	public function get_logs()
	{
		$this->post_logs();
	}

	public function post_logs()
	{
		$locals= array(
			'do_update' => false,
			'log_ids' => null,
			'nonce' => '',
			'timestamp' => '',
			'PasswordDigest' => '',
			'change' => '',
			'limit' => 20,
			'user' => 0,
			'date' => 'any',
			'module' => '0',
			'type' => '0',
			'severity' => 'any',
			'address' => '0',
			'search' => '',
			'do_search' => false,
			'index' => 1,
		);
		foreach ( $locals as $varname => $default ) {
			$$varname= isset($this->handler_vars[$varname]) ? $this->handler_vars[$varname] : $default;
			$this->theme->{$varname}= $$varname;
		}
		$this->theme->severities= LogEntry::list_severities();
		$any= array( '0' => 'Any' );

		$modulelist= LogEntry::list_logentry_types();
		$modules= array();
		$types= array();
		$addresses= $any;
		$ips= DB::get_column('SELECT DISTINCT(ip) FROM ' . DB::table('log') );
		foreach ($ips as $ip) {
			$addresses[$ip]= long2ip($ip);
		}
		$this->theme->addresses= $addresses;
		foreach($modulelist as $modulename => $typearray) {
			$modules['0,'.implode(',', $typearray)] = $modulename;
			foreach($typearray as $typename => $typevalue) {
				if(!isset($types[$typename])) {
					$types[$typename]= '0';
				}
				$types[$typename].= ',' . $typevalue;
			}
		}
		$types = array_flip($types);
		$this->theme->types= array_merge( $any, $types );
		$this->theme->modules= array_merge( $any, $modules );

		// set up the users
		$users_temp= DB::get_results( 'SELECT username, user_id FROM ' . DB::table('users') . ' JOIN ' . DB::table('log') . ' ON ' . DB::table('users') . '.id=' . DB::table('log') . '.user_id GROUP BY user_id ORDER BY username ASC');
		array_unshift( $users_temp, new QueryRecord(array('username' => 'All', 'user_id' => 0)));
		foreach ($users_temp as $user) {
			$users[$user->user_id]= $user->username;
		}
		$this->theme->users= $users;

		// set up dates.
		$dates= DB::get_column("SELECT timestamp FROM " . DB::table('log') . ' ORDER BY timestamp DESC');
		$dates= array_map( create_function( '$date', 'return strftime( "%Y-%m", strtotime( $date ) );' ), $dates );
		array_unshift( $dates, 'Any');
		$dates= array_combine( $dates, $dates );
		$this->theme->dates= $dates;

		// set up the limit select box
		$limits= array( 5, 10, 20, 50, 100 );
		$limits= array_combine( $limits, $limits );
		$this->theme->limits= $limits;

		// prepare the WSSE tokens
		$this->theme->wsse= Utils::WSSE();

		$arguments= array(
			'severity' => LogEntry::severity($severity),
			'limit' => $limit,
			'offset' => ( $index - 1) * $limit,
		);

		// deduce type_id from module and type
		$r_type = explode(',', substr($type, 2));
		$r_module = explode(',', substr($module, 2));
		if( $type != '0' && $module != '0' ) {
			$arguments['type_id'] = array_intersect($r_type, $r_module);
		}
		elseif( $type == '0' ) {
			$arguments['type_id'] = $r_module;
		}
		elseif( $module == '0' ) {
			$arguments['type_id'] = $r_type;
		}

		if ( '0' != $address ) {
			$arguments['ip']= $address;
		}

		if ( 'any' != strtolower($date) ) {
			list($arguments['year'], $arguments['month'])= explode( '-', $date );
		}
		if ( '' != $search ) {
			$arguments['criteria']= $search;
		}
		$this->theme->logs= EventLog::get( $arguments );

		// get the page count
		$arguments['count']= 'id';
		unset($arguments['limit']);
		unset($arguments['offset']);
		$totalpages= EventLog::get( $arguments );
		$pagecount= ceil( $totalpages / $limit );

		// put the page numbers into an array
		$pages= array();
		for ( $z= 1; $z <= $pagecount; $z++ ) {
			$pages[$z]= $z;
		}
		$this->theme->pagecount= $pagecount;
		$this->theme->pages= $pages;

		$this->display( 'logs' );
	}

	/**
	 * Assembles the main menu for the admin area.
		*/
	protected function get_main_menu() {
		$mainmenus = array(
			'admin' => array(
				'caption' => _t( 'Admin' ),
				'url' => URL::get( 'admin', 'page=' ),
				'title' => _t( 'Display the dashboard' ),
				'submenu' => array(
					'options' => array( 'caption' => _t( 'Options' ), 'url' => URL::get( 'admin', 'page=options' ) ),
					'plugins' => array( 'caption' => _t( 'Plugins' ), 'url' => URL::get( 'admin', 'page=plugins' ) ),
					'themes' => array( 'caption' => _t( 'Themes' ), 'url' => URL::get( 'admin', 'page=themes' ) ),
					'users' => array( 'caption' => _t( 'Users' ), 'url' => URL::get( 'admin', 'page=users' ) ),
					'logs' => array( 'caption' => _t( 'Logs' ), 'url' => URL::get( 'admin', 'page=logs' ) ),
					'import' => array( 'caption' => _t( 'Import' ), 'url' => URL::get( 'admin', 'page=import' ) ),
				)
			),
			'publish' => array(
				'caption' => _t( 'Create' ),
				'url' => URL::get( 'admin', 'page=publish' ),
				'title' => _t( 'Create content for your site' ),
				'submenu' => array()
			),
			'manage' => array(
				'caption' => _t( 'Manage' ),
				'url' => URL::get( 'admin', 'page=content' ),
				'title' => _t( 'Manage your site content' ),
				'submenu' => array(
					'content' => array( 'caption' => _t( 'Content' ), 'url' => URL::get( 'admin', 'page=content' ) ),
					'unapproved' => array( 'caption' => _t( 'Unapproved Comments' ), 'url' => URL::get( 'admin', 'page=moderate' ) ),
					'approved' => array( 'caption' => _t( 'Approved Comments' ), 'url' => URL::get( 'admin', 'page=moderate&search_status=1' ) ),
					'spam' => array( 'caption' => _t( 'Spam' ), 'url' => URL::get( 'admin', 'page=moderate&search_status=2' ) ),
				)
			),
		);

		foreach( Post::list_active_post_types() as $type => $typeint ) {
			if ( $typeint == 0 ) {
				continue;
			}
			$mainmenus['publish']['submenu'][$type]= array( 'caption' => _t( ucwords( $type ) ), 'url' => URL::get( 'admin', 'page=publish&content_type=' . $type ) );
		}

		$mainmenus= Plugins::filter( 'adminhandler_post_loadplugins_main_menu', $mainmenus );

		$out= '';
		foreach( $mainmenus as $mainmenukey => $mainmenu ) {
			$out.= '<li class="menu-item"><a href="' . $mainmenu['url'] . '" title="' . $mainmenu['title'] . '">' . $mainmenu['caption'] . '</a>';
			$out.= '<ul class="menu-list">';
			foreach( $mainmenu['submenu'] as $menukey => $menuitem ) {
				$out.= '<li><a href="' . $menuitem['url'] . '">' . $menuitem['caption'] . '</a></li>';
			}
			$out.= '</ul>';
			$out.= '</li>';
		}
		return $out;
	}

	/**
	 * Assigns the main menu to $mainmenu into the theme.
		*/
	protected function set_admin_template_vars( $theme ) {
		$theme->assign( 'mainmenu', $this->get_main_menu() );
	}

	/**
	 * Helper function to assign all handler_vars into the theme and displays a theme template.
	 * @param template_name Name of template to display (note: not the filename)
	 */
	protected function display( $template_name ) {
		// Assign internal variables into the theme. See Theme::assign()
		foreach ( $this->handler_vars as $key => $value ) {
			$this->theme->assign( $key, $value );
		}
		$this->theme->display( $template_name );
	}

}

?>
