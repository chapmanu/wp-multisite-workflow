<?php

// Allows the sending of emails
require('smc_post_notifier.php');

class wpmu_workflow {

	// The number of days before we nag the editors to review their pending posts
	private $reasonable_days_to_wait = 2;

	public function __construct() {

		// Display workflow history in publish box on posts
		add_action( 'post_submitbox_misc_actions', array($this, 'display_workflow_box') );

		// Add workflow history if post is being moved
		add_action( 'save_post', array($this, 'smc_intercept_moving_posts'), 0 );
		add_action( 'admin_notices', array($this, 'wpmu_workflow_notices')); // Shows a notice after a post is moved. 

		add_action( 'admin_enqueue_scripts', array($this, 'wpmu_workflow_styles')); // Custom styles
		add_filter( 'gettext', array($this, 'smc_modify_wp_labels'), 10, 2 ); // Modify labels in WP
		add_action( 'admin_init', array($this, 'admin_functions'));

		/***************************************************
		* Add all the action hooks! 
		* These are all of the workflow history states and notifications that take place. 
		***************************************************/

		//
		// NEW POST ENTERING WORFKLOW OR EXISTING POST MOVING
		add_action( 'new_to_pending', 	array($this, 'notify_new_to_pending'), 10, 1); // arg is post object
		add_action( 'draft_to_pending', array($this, 'notify_new_to_pending'), 10, 1); // arg is post object

		//
		// EXISTING POST MOVING ONLY
		add_action( 'smc_post_moved_blogs', array($this, 'notify_smc_post_moved_blogs'), 15, 1); // arg is a post object

		// EDITOR APPROVES
		add_action( 'pending_to_publish', array($this, 'notify_pending_to_publish'), 10, 1); // arg is post object
		add_action( 'pending_to_future', array($this, 'notify_pending_to_future'), 10, 1); // arg is post object

		// // REJECT
		// add_action( 'pending_to_draft', email_author_post_rejected, 10, 1);
		// add_action( 'pending_to_trash', email_author_post_deleted, 10, 1);

		// FUTURE POST PUBLISHED
		// NOTE: Does not work!!!
		// add_action( 'future_to_publish', array($this, 'notify_pending_to_publish', 10, 1)); // arg is post ID?



	} // end constructor


	// Fires when a post is moved
	// Fires when a post is submitted for review
	// IMPORTANT! Meta data may not be set for the post at the time of execution!!! 
	public function notify_new_to_pending($post_or_id) {

		if (is_int($post_or_id)) {
			$post = get_post($id);
		} else {
			$post = $post_or_id;
		}

		// Check if this is a draft/revision; if so, retry getting post parent
		// if ($post && $post->post_type == 'revision' && $post->post_parent) {
		// 	$post_id = $post->post_parent;
		// 	$post = get_post($post->post_parent);
		// }

		// Tell editor a new post is pending on their blog
		$notifier = new smc_post_notifier($post);
		$notifier->send_email('editor_post_pending_review');


		return;
	} // end notify_new_to_pending


	// Fires when a post is moved from A to B
	public function notify_smc_post_moved_blogs($post) {
		// Update workflow: user moved to blog

		// If the user is a contributor, and if they are submitting, go ahead and add workflow history. 
		// This catches posts which are submitted from the WP dashboard by contributors

		// $workflow_history = get_post_meta( $post_id, 'smc_workflow_history', true );

		// $is_new = ($workflow_history && count($workflow_history) <= 2);

		// if (!current_user_can('publish_posts') && $is_new) {
		// 	$notifier = new smc_post_notifier($post);
		// 	$notifier->send_email('author_submission_pending');
		// }

		return;
	}

	// Fires when a pending post is approved
	public function notify_pending_to_publish($post) {
		
		// Workflow log update
		$this->append_workflow_action($post->ID, $action = 'published' );

		// Email Notifications
		$notifier = new smc_post_notifier($post);
		$notifier->send_email('author_post_published');

		// TEMPORARY SWEATSHIRT CONTEST
		$notifier->send_sweatshirt();

		return;
	}

	// Fires when a pending post is scheduled
	public function notify_pending_to_future($post) {
			
		// Workflow log update
		$this->append_workflow_action($post->ID, $action = 'scheduled' );

		// Email Notifications
		$notifier = new smc_post_notifier($post);
		$notifier->send_email('author_post_scheduled');

		// TEMPORARY SWEATSHIRT CONTEST
		$notifier->send_sweatshirt();

		return; 
	}





	public function admin_functions() {
		// add_submenu_page('edit.php', 'TEST TITLE', 'Review Pending', 'edit_others_posts', 'edit.php?post_status=pending&orderby=date&order=desc');

		global $wpdb;
		$query = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'pending'";
		$post_count = $wpdb->get_var($query);

		if ($post_count > 0) {

			$label = ($post_count > 1) ? 'submissions' : 'submission';

			$icon = get_option('siteurl') . '/wp-content/plugins/' . basename(dirname(__FILE__)) . '/img/icon-arrow-14.png';

			add_menu_page("Posts pending review", "$post_count $label", 'edit_others_posts', 'edit.php?post_status=pending&orderby=date&order=desc', null, $icon, 4);

		}

		// IF WE ARE ADMIN display some email logs!
		if (current_user_can('manage_options')) {
			global $post;
			add_meta_box( 'email_notification_history', "Email notification history", array('smc_post_notifier', 'display_email_log'), 'post', 'normal',
			        'low' );
		}

	}

	public function wpmu_workflow_styles() {
	    wp_enqueue_style('wpmu_workflow_css', plugins_url('css/wpmu_workflow.css', __FILE__));
	}

	/***************************************************
	* Move a post if the destination blog was picked. 
	* VERY IMPORTANT: Nonce verification prevents recursive infinite loops which create hundreds of duplicate posts. 
	***************************************************/
	public function smc_intercept_moving_posts( $post_ID ) {
		global $post;

		// If a destination blog was set
		if ($_REQUEST['dest_blog'] > 0) {

			// verify nonce (IMPORTANT - prevents infinite recursion)
			$is_valid_move = wp_verify_nonce($_REQUEST['move_post_nonce'], 'move_post_'.$post->ID.'_to_blog_'.get_current_blog_id());

			if (!$is_valid_move) {
				return false;
			}

			// let's move the post. 
			$args = array(
				'source_post_id' => $post_ID,
				'source_blog_id' => get_current_blog_id(),
				'dest_blog_id'   => $_REQUEST['dest_blog']
			);

			// *** Prevent recursion
			remove_action( 'save_post', array($this, 'smc_intercept_moving_posts'), 0 );

			// Do the move
			$result = $this->smc_move_post($args);

			// *** Prevent recursion
			add_action( 'save_post', array($this, 'smc_intercept_moving_posts'), 0 );

			// Redirect
			$redirect_uri = add_query_arg(array(
												'post_type' 	=> $post->post_type,
												'smc_moved' 	=> 1,
												'post_title' 	=> urlencode($post->post_title),
												'dest_blog' 	=> $args['dest_blog_id']
											), get_admin_url( '', 'edit.php'));
			wp_redirect($redirect_uri);
			exit;
		}
	} // end smc_intercept_moving_posts



	/**
	 * OVERVIEW: 
	 * Copies a post from a source blog to a destination blog. Moves all attachments along with the post. Maintains featured image association. 
	 * 
	 * DETAILS: 
	 * Only allows posts to be moved if they are saved as a draft or pending status. Retrieves all attachments that were uploaded to this post specifically; also retrieves the featured image EVEN IF it is not attached to this post specifically (in that case, the attachment is copied instead of moved). Replaces the any URL references to the attachments that are contained within the post body. 
	 *
	 * Updates the post status to Pending on the destination blog.  Inserts new attachments and moves files to destination blog. Associates featured image as necessary. Deletes original post AND deletes associated attachments (except featured image IF not associated with the post directly)
	 * 
	 * 
	 *
	 * @param  $args (array) Example: array('source_post_id' => 1, 'source_blog_id' => 1, 'dest_blog_id' => 1)
	 * @return true on success, WP Error on failure. (pending)
	 */ 
	public static function smc_move_post($args) {

		set_time_limit ( 300 );

		// Data validation
		if ($args['source_post_id'] <= 0) 	return false;
		if ($args['source_blog_id'] <= 0) 	return false;
		if ($args['dest_blog_id'] <= 0) 	return false;

		// Check if user has permission to move post
		if (! current_user_can('edit_post', $args['source_post_id'])) {
			die("An error occurred: You don't have permission to edit this post. ");
		}

		// Don't move a post to the same blog
		if ($args['source_blog_id'] == $args['dest_blog_id']) {
			$post = get_post($args['source_post_id']);
			$post->post_status = 'pending';

			$result = wp_update_post($post);

			self::append_workflow_action($post->ID, null, $args['source_blog_id'], $args['dest_blog_id']); // must happen BEFORE retrieving post meta, and BEFORE the move. 

			do_action('smc_post_moved_blogs', $post);
			return $result;
		} 

		/*
		* Get current post and attachment data
		***************************************************/
		switch_to_blog($args['source_blog_id']);
		$post = get_post($args['source_post_id']);

		// Check if this is a draft/revision; if so, retry getting post parent
		if ($post && $post->post_type == 'revision' && $post->post_parent) {
			$args['source_post_id'] = $post->post_parent;
			$post = get_post($args['source_post_id']);
		}

		if (!$post) {
			die("An error occurred: could not retrieve the post data from the source blog. Debug info: ".print_r($args, true));
		}

		// Only allow move of pending or draft posts
		if (!in_array($post->post_status, array('pending', 'draft'))) { 
			die("This post is not in a state where it can be moved.");
		}

		// Get featured image
		$source_post_thumbnail_id = get_post_thumbnail_id( $post->ID );

		// Get attachments
		$attachments = get_posts(
			array(
				'post_parent'		=> $post->ID,
				'post_type'			=> 'attachment',
				'post_mime_type'	=> 'image',
				'orderby'			=> 'post_date',
				'order'				=> 'DESC',
				'posts_per_page' 	=> -1,
				'exclude'			=> $source_post_thumbnail_id
			)
		);

		// Append featured image attachment
		if ($source_post_thumbnail_id > 0) {
			$attachments[] = get_post($source_post_thumbnail_id);
		}

		foreach($attachments as $attachment) {
			$attachment_ids_map[$attachment->ID] = null; // array('source_id'=>'dest_id')
		}

		// Check for gallery shortcode
		$has_galleries = has_shortcode( $post->post_content, 'gallery' );
		if ($has_galleries) {

			// Output used from get_shortcode_regex()
			$gallery_regex = '\[(\[?)(gallery)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)';

			// Search the content for gallery shortcodes
			preg_match_all("/$gallery_regex/s", $post->post_content, $matches, PREG_SET_ORDER);

			// For each [gallery] shortcode we found...
			foreach ($matches as $match) {

				// Parse the attribute string
				$atts = shortcode_parse_atts($match[3]);
				$gallery_image_ids = explode(',',$atts['ids']);

				// Strip that whitespace!!
				$gallery_image_ids = array_filter(array_map('trim', $gallery_image_ids));

				// Append each gallery to an array for later use. 
				$galleries[] = array(
					'old_shortcode' => $match[0],
					'old_shortcode_ids_string' => $atts['ids'],
					'old_ids' => $gallery_image_ids
				);

				// Append each image to the attachments array;
				foreach ($gallery_image_ids as $gallery_image_id) {
					if (!array_key_exists($gallery_image_id, $attachment_ids_map)) {

						// Append attachment
						$attachments[] = get_post($gallery_image_id);

						// Append ID to the IDs map
						$attachment_ids_map[$gallery_image_id] = null;
					}
				}
			}
		}

		// Replace URLs in post body and build up data for copying files later
		if ($attachments) {

			// Get upload dirs - Look up path to attachments on server
			// $source_blog_upload_path = get_blog_option($args['source_blog_id'], 'upload_path');
			// $dest_blog_upload_path   = get_blog_option($args['dest_blog_id'],   'upload_path');
			$source_blog_upload_path = self::get_pretty_upload_path_for_blog($args['source_blog_id']);
			$dest_blog_upload_path   = self::get_pretty_upload_path_for_blog($args['dest_blog_id']);

			foreach($attachments as $attachment) {

				// Set up for later use
				$attachment_files[$attachment->ID] = get_attached_file( $attachment->ID );

				// Replace URLs in post body
				$attachment_meta = wp_get_attachment_metadata($attachment->ID);

				$basename = substr($attachment_meta['file'], 0, strrpos($attachment_meta['file'], '.')); // this captures different file names for different image sizes

				$old_urls[] = $source_blog_upload_path.'/'.$basename;
				$new_urls[] = $dest_blog_upload_path.'/'.$basename;

			}

			$post->post_content = str_replace($old_urls, $new_urls, $post->post_content);
			$post->post_excerpt = str_replace($old_urls, $new_urls, $post->post_excerpt);
		}

		// WORFKLOW UPDATE
		self::append_workflow_action($post->ID, null, $args['source_blog_id'], $args['dest_blog_id']); // must happen BEFORE retrieving post meta, and BEFORE the move. 

		// Get postmeta
		$postmeta = get_post_meta($post->ID);


		/*
		* Create new post and attachments on destination blog
		***************************************************/
		switch_to_blog($args['dest_blog_id']);

		$post->ID = ''; // Create new post
		$post->post_status = 'draft'; // We will update this later

		$new_post_id = wp_insert_post($post, true); // NOTE - META IS NOT YET INSERTED, BUT ACTIONS FIRE! 

		$output['new_post_id'] = $new_post_id;

		if (is_wp_error($new_post_id)) {
			die("An error occured: Could not create new post on destination blog.");
		}

		/*
		* Store the post meta. 
		* Okay, so the crazy nested loop/conditional structure is because each post meta key can possibly have multiple values. Most will be an array of one. 
		***************************************************/
		if ($postmeta) {
			// Loop over each key
			foreach($postmeta as $key => $vals) {

				$i = 0;
				// Loop over each individual value per key
				foreach ($vals as $val) {

					if ($i == 0) {
						// This prevents duplicate entries which already exist. 
						update_post_meta($new_post_id, $key, maybe_unserialize($val));
					} else {

						// Now we are adding duplicate values. 
						add_post_meta($new_post_id, $key, maybe_unserialize($val));
					}

					$i++;
				}

			}
		}

		/*
		* Copy all the attachments over
		***************************************************/
		if ($attachments) {
			foreach($attachments as $attachment) {

				$path = $attachment_files[$attachment->ID];

				if (!file_exists($path)) continue;

				$file['name'] 		= basename($path);
				$file['type'] 		= wp_check_filetype($path);
				$file['tmp_name'] 	= $path;

				$new_attachment_id = media_handle_sideload($file, $new_post_id, null, (array) $attachment);

				if (is_wp_error($new_attachment_id)) {
					echo $new_attachment_id->get_error_message();
					die("An error occured sideloading images.");
				}

				// Update IDs array
				$attachment_ids_map[$attachment->ID] = $new_attachment_id;

				// Set featured image
				if ($source_post_thumbnail_id == $attachment->ID) {
					update_post_meta($new_post_id, '_thumbnail_id', $new_attachment_id);
				}

			}
		}

		// Update the post body gallery shortcode with new attachment IDs
		if ($has_galleries) {

			foreach ($galleries as $gallery) {

				$new_ids = array(); // Initialize

				foreach ($gallery['old_ids'] as $old_id) {
					// Get the corresponding new IDs
					$new_ids[] = $attachment_ids_map[$old_id];
				}

				$old_shortcodes[] = $gallery['old_shortcode'];
				$new_shortcodes[] = str_replace($gallery['old_shortcode_ids_string'], implode(',',$new_ids), $gallery['old_shortcode']);
			}

			// Replace the old shortcodes with the new
			$post->post_content = str_replace($old_shortcodes, $new_shortcodes, $post->post_content);
		}

		// now update the post status to pending AND update the content with new shortcodes
		$result = wp_update_post(array('ID'=>$new_post_id, 'post_status' => 'pending', 'post_content'=>$post->post_content));

		do_action('smc_post_moved_blogs', $post);

		/*
		* Remove original post and attachments from source blog
		***************************************************/
		restore_current_blog();

		wp_delete_post($args['source_post_id'], true); // force delete


		if ($attachments) {
			foreach($attachments as $attachment) {

				// Only delete if the attachment belonged to the source post. 
				if ($attachment->post_parent == $args['source_post_id']) {
					$result = wp_delete_attachment( $attachment->ID, true );
					if ($result === false) die("An error occured: Could not remove attachments from the old post.");
				}
			}
		}

		/*////////////////////////////////////////////////////
		* Trigger email notification here that something is pending. Add it to the mods queue. Or somthing. 
		////////////////////////////////////////////////////*/


		// All is good, let's return some data
		$output = array('success'=>true);

		return $output;
	} // end smc_move_post()


	/***************************************************
	* A custom function to find the permalink path for our uploaded files. 
	* Checks for 'wp-content/blogs.dir/00/files' and replaces it with SLUG/files
	***************************************************/
	public static function get_pretty_upload_path_for_blog($blog_id) {
		$upload_path = get_blog_option($blog_id, 'upload_path');

		$is_match = preg_match("/wp-content\/blogs\.dir\/(\d+)\/files/", $upload_path);

		// If this matches the format 'wp-content/blogs.dir/00/files'
		if ($is_match) {

			$slug = substr(get_blog_details($blog_id)->path, 1, -1);
			return $slug.'/files';

		}

		return $upload_path;
	}


	/***************************************************
	* Append workflow information to a post. 
	***************************************************/
	public static function append_workflow_action($post_id, $action = null, $source_blog_id = null, $dest_blog_id = null, $workflow_history = null ) {

		// Make sure we have the most parent post
		$post = get_post($post_id);

		// Check if this is a draft/revision; if so, retry getting post parent
		if ($post && $post->post_type == 'revision' && $post->post_parent) {
			$post_id = $post->post_parent;
			$post = get_post($post->post_parent);
		}

		// Validation
		if (!$source_blog_id) 	$source_blog_id = get_current_blog_id();
		if (!$dest_blog_id) 	$dest_blog_id 	= get_current_blog_id();
		if (!$workflow_history) $workflow_history = get_post_meta( $post_id, 'smc_workflow_history', true );

		if (!$action) {
			$action = (!$workflow_history) ? 'created' : 'forwarded';
		}

		if ($action == 'created') {

			$workflow_history[] = array(
				'timestamp' 	=> strtotime($post->post_date),
				'user_id'		=> $post->post_author,
				'blog_id'		=> $source_blog_id,
				'action' 		=> 'created',
			);


			// If the user responsible for this new post insertion CANNOT publish, send a confirmation email!
			if (!current_user_can('publish_posts') || (get_current_blog_id() == 1)) {
				$notifier = new smc_post_notifier($post, $dest_blog_id);
				$notifier->send_email('author_submission_pending');
			}

			$action = 'submitted';
		}

		$workflow_history[] = array(
			'timestamp' 	=> time(),
			'user_id'		=> get_current_user_id(),
			'blog_id'		=> $dest_blog_id,
			'action' 		=> $action,
		);


		return update_post_meta($post_id, 'smc_workflow_history', $workflow_history);

	} // end append_workflow_action()


	/***************************************************
	* Show a notification message after a post has been moved. 
	* Based on a GET variable which is set. 
	***************************************************/
	public function wpmu_workflow_notices() {

		// Check what page we are currently on

	    // Check if the variable is set
	    if ($_REQUEST['smc_moved']) {
	    	$title = $_REQUEST['post_title'] ?: '1 post ';
	    	$dest_blog_details = get_blog_details($_REQUEST['dest_blog']);
	    	$dest_blog_name = $dest_blog_details->blogname ?: 'another blog';

	    	echo "<div id=\"message\" class=\"updated fade\">";
	    	echo "<p><strong>$title</strong> was moved to <strong>$dest_blog_name</strong> and is now pending review.  ";

	    	// Display a convenient link for the user to switch blogs, IF they have the capability. 
	    	if (current_user_can_for_blog($_REQUEST['dest_blog'], 'edit_others_posts')) {
	    		$url = network_site_url( $dest_blog_details->path .'wp-admin/edit.php?post_status=pending&orderby=date&order=desc' );
	    		echo "<a href=\"$url\">Review pending posts on $dest_blog_name &#0187;</a>";
	    	}

	    	echo "</p></div>";
	    }

	    // Check if old pending posts and nag the editor
	    if (current_user_can('edit_others_posts')) {
		    global $wpdb;
		    $query = "SELECT COUNT(*) 
		    			FROM {$wpdb->posts} 
		    			WHERE post_status = 'pending' 
		    			AND post_date < '" . date('Y-m-d', strtotime('-'.$this->reasonable_days_to_wait.' days')) . "'";

		    $old_pending_count = $wpdb->get_var($query);

		    if ($old_pending_count > 0) {
		    	echo "<div id=\"message\" class=\"updated fade\">";
		    	echo "<p><strong>$old_pending_count ";

		    	echo ($old_pending_count == 1) ? "submission " : "submissions ";
		    	echo "</strong> ";

		    	echo ($old_pending_count == 1) ? "has " : "have ";
		    	echo "been pending review for more than $this->reasonable_days_to_wait days.  <a href=\"edit.php?post_status=pending&orderby=date&order=desc\">Review ";

		    	echo ($old_pending_count == 1) ? "it  " : "them ";
		    	echo "now &#0187;</a></p>";
		    	echo "</div>";
		    }
		}

	} // end wpmu_workflow_notices()




	/***************************************************
	* Generate a list of blogs participating in the workflow for multisite
	* Excludes current blog
	***************************************************/
	public static function get_destination_blog_list($include_current = false, $only_if_accepting_community_submissions = false) {

		// Build a list of all blogs
		$items = wp_get_sites(array('deleted'=>false, 'spam'=>false));
		foreach ($items as $item) {

			// Exclude current blog?
			if (!$include_current && $item['blog_id'] == get_current_blog_id()) continue;

			// Get options for current blog
			$smc_wpmu_workflow_options = get_blog_option($item['blog_id'], 'smc_wpmu_workflow_options');

			// Check if enabled in workflow
			if ($only_if_accepting_community_submissions) {
				if (! $smc_wpmu_workflow_options['smc_accept_community_submissions']) continue;
			} else {
				if (! $smc_wpmu_workflow_options['smc_multisite_workflow']) continue;
			}


			// Get data we are going to return
			$data = get_blog_details($item['blog_id']);

			//
			// Enhance data and add new fields

			// Add slug
			$data->slug = substr($data->path, 1, -1);

			// add workflow options
			$data->smc_wpmu_workflow_options = $smc_wpmu_workflow_options;

			// Add to results
			$blogs[] = $data;
		}

		// Sort all blogs alphabetically
		usort($blogs, arrSortObjsByKey('blogname', 'ASC'));

		return $blogs;

	}

	// Return a user ID of the designated editor for a blog
	public static function get_reviewer_for_blog($blog_id = null) {
		if ($blog_id <= 0) {
			$blog_id = get_current_blog_id();
		}

		$smc_wpmu_workflow_options = get_blog_option($blog_id, 'smc_wpmu_workflow_options');

		return $smc_wpmu_workflow_options['smc_workflow_editor'];
	}


	public function display_workflow_history($post_id) {
		if(false != $workflow_history = get_post_meta( $post_id, 'smc_workflow_history', true )) : ?>
		<ul style="margin:0; padding:0;">
			<?php foreach ($workflow_history as $event) { 

				// Retrieve and cache data for use in this loop. 
				if (!$tmp_users[$event['user_id']]) $tmp_users[$event['user_id']] 	= get_userdata($event['user_id']);
				if (!$tmp_blogs[$event['blog_id']]) $tmp_blogs[$event['blog_id']] = get_blog_details($event['blog_id']);

				$usr 	= $tmp_users[$event['user_id']];
				$blog 	= $tmp_blogs[$event['blog_id']];

				// What happened to the post?
				switch ($event['action']) {
					case 'created':
						$action_label = 'created this on';
						break;
					case 'submitted':
						$action_label = 'submitted this to';
						break;
					case 'forwarded':
						$action_label = 'forwarded this to';
						break;
					case 'published':
						$action_label = 'published this on';
						break;
					case 'scheduled':
						$action_label = 'scheduled this on';
						break;
					default:
						$action_label = 'unknown';
						break;
				}

				$blog_label = ($blog->path != '/') ? $blog->path : 'Blogs.Chapman';

				?>
			<li style="margin:0 0 5px 0; padding:0; list-style-type:none;"><a href="mailto:<?php echo $usr->user_email; ?>" title="<?php echo $usr->user_firstname . ' ' . $usr->user_lastname; ?>"><?php echo $usr->user_firstname . ' ' . substr($usr->user_lastname, 0, 1); ?></a> <?php echo $action_label; ?> <?php echo $blog_label; ?></li>
			<?php } ?>
		</ul>
		<?php endif;
	}

	/***************************************************
	* Display the workflow history in the post publish box
	***************************************************/
	public function display_workflow_box() {
		global $post; ?>
		<div class="misc-pub-section my-options">
			
			<?php $this->display_workflow_history($post->ID); ?>

			<?php if (!in_array($post->post_status, array('publish', 'scheduled', 'private'))) : ?>
			<a href="javascript:void(0);" id="showHideMoveBlogs">Forward for publishing on another blog</a>
			<?php endif; ?>

			<div id="move_blogs" style="display:none; padding-top:10px;">

				<input type="hidden" name="move_post_nonce"  value="<?php echo wp_create_nonce('move_post_'.$post->ID.'_to_blog_'.get_current_blog_id()); ?>" />

				<select name="dest_blog" id="dest_blog_id" style="max-width:100%;">
					<option value=""></option>
					<?php $blogs = $this->get_destination_blog_list(); ?>
					<?php foreach ($blogs as $blog) { ?>
					<option value="<?php echo $blog->blog_id; ?>"><?php echo $blog->blogname; ?></option>
					<?php } ?>
				</select>

				<p align="right" style="margin-bottom:0">
					<span class="spinner" id="smc_move_post_spinner"></span>
					<input id="smc_move_post" class="button button-large" type="submit" value="Submit for Review" name="save">
				</p>

			</div>

		</div>

		<script type="text/javascript">

		/***************************************************
		* Show/Hide the post move box on link click
		***************************************************/
		var showHideMoveBlogs_label = jQuery("#showHideMoveBlogs").html();
		jQuery("#showHideMoveBlogs").click(function(e) {

			var move_blogs = document.getElementById('move_blogs');
			if (move_blogs.style.display !== 'none') {
			   	jQuery(move_blogs).slideUp();
			   	jQuery("#major-publishing-actions").slideDown();
			   	jQuery("#minor-publishing-actions").slideDown();
			   	jQuery("#showHideMoveBlogs").html(showHideMoveBlogs_label);

			   	// Reset the selector
			   	jQuery("#dest_blog_id").find('option:first').attr('selected', 'selected');
			} else {
			    jQuery(move_blogs).slideDown();
			    jQuery("#major-publishing-actions").slideUp();
			    jQuery("#minor-publishing-actions").slideUp();
			    jQuery("#showHideMoveBlogs").html("Nevermind, keep the post on this blog.");

			}

			e.preventDefault();
			return false;
		});

		/***************************************************
		* Change button class when item is selected
		***************************************************/
		jQuery("#dest_blog_id").change(function(e) {
			if (jQuery(this).val() > 0) {
				jQuery("#smc_move_post").addClass("button-primary");
			} else {
				jQuery("#smc_move_post").removeClass("button-primary");
			}
		})

		/***************************************************
		* Update UI on save click
		***************************************************/
		jQuery("#smc_move_post").click(function(e) {

			var dest_blog_id = jQuery("#dest_blog_id").val();
			if (dest_blog_id <= 0) {
				alert("Please select a blog to submit this post to.");
				e.preventDefault();
				e.stopPropagation();
				return false;
			}

			jQuery("#smc_move_post_spinner").css({'display': 'inline-block', 'float':'none','margin-top':'0'});

		});

		</script>
	<?php
	} // end display_workflow_box()


	/***************************************************
	* Modify label text in WP, systme wide.
	***************************************************/
	public function smc_modify_wp_labels( $translated_text, $text ) {

		switch ($translated_text) {
			case 'Publish':
				if (is_admin() && get_post_status() == 'pending') {
					return 'Approve &amp; Publish';
					break;
				}

			// case 'pending':
			// 	global $pagenow;
			// 	if ( $pagenow == 'edit.php') {
			// 		return 'Pending REVIEW';
			// 	}
			// 	break;
			
			default:
				return $translated_text;
				break;
		}

		return $translated_text;
	}

} // END CLASS

// Run it only in admin panel! 
if( is_admin() ) {
	$wpmu_workflow = new wpmu_workflow();
	require('wpmu_workflow_settings.php');
}

?>