<?php
/*
Plugin Name: Foursquare WP
Plugin URI: http://lostfocus.de/
Version: 0.0.1
Description: Imports Foursquare Checkins as blogposts.
Author: Dominik Schwind
Author URI: http://lostfocus.de/
*/

function ds_foursquare_options(){
	$redirecturl = (isset($_SERVER["HTTPS"]) ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	$options = get_option('ds_foursquare');
	// $options = unserialize($options);
	if(isset($_POST['submit'])){
		$oldoptions = $options;
		$options = array();
		if(isset($_POST['client_id'])) $options['client_id'] = $_POST['client_id'];
		if(isset($_POST['client_secret'])) $options['client_secret'] = $_POST['client_secret'];
		update_option('ds_foursquare',$options);
	}
	if(isset($_GET['code'])){
		$code = $_GET['code'];
		$tokenurl = sprintf("https://foursquare.com/oauth2/access_token?client_id=%s&client_secret=%s&grant_type=authorization_code&redirect_uri=%s&code=%s",
			$options['client_id'],
			$options['client_secret'],
			urlencode($redirecturl),
			$code
		);
		$tokendata = json_decode(wp_remote_fopen($tokenurl));
		if(!isset($tokendata->error) && isset($tokendata->access_token)){
			$options['access_token'] = (string)$tokendata->access_token;
			update_option('ds_foursquare',$options);
		}
	}
	?>
<div class="wrap">
	<div id="icon-options-general" class="icon32"><br></div>
	<h2>Foursquare Import Options</h2>
	<?php if(isset($tokendata) && (isset($tokendata->error))) {
		?>
<div id="message" class="error fade">
	Foursquare error: <?php echo $tokendata->error; ?>
</div>
	<?php
	}
	if(!$options){
?>
<p>
	To get this to work, you need to register a new Application over at 
	<a href="https://foursquare.com/developers/register">foursquare.com/developers/register</a>.
</p>
<p>
	Use
	<code><?php echo $redirecturl; ?></code>
	as Callback Url.
</p>
<p>When you're done with that, copy and paste the Client id and Client secret here:</p>
<?php
	}
	?>
<form method="post">
	<h3>Client ID and Secret</h3>
	<table class='form-table'>
		<tr>
			<th scope='row'>Client id:</th>
			<td><input type="text" name="client_id" required value="<?php echo $options['client_id'] ?>"></td>
		</tr>
		<tr>
			<th scope='row'>Client secret:</th>
			<td><input type="text" name="client_secret" required value="<?php echo $options['client_secret'] ?>"></td>
		</tr>
	</table>	
	<?php submit_button('Update'); ?>
</form>
</div>
	<?php
	if((trim($options['client_id']) != "") && (trim($options['client_secret']) != "")){
		$fsqurl = sprintf(
		"https://foursquare.com/oauth2/authenticate?client_id=%s&response_type=code&redirect_uri=%s",
		$options['client_id'],
		urlencode($redirecturl)
		);
		?>
<h3>Connect with Foursquare</h3>
<p>
	<a href="<?php echo $fsqurl ?>">Connect this with Foursquare.</a>
</p>
		<?php
	}

	if(trim($options['access_token']) != ""){
		if ( false === ( $foursquareself = get_transient( 'ds_foursquare_self' ) ) ) {
			$selfurl = "https://api.foursquare.com/v2/users/self?v=20130105&oauth_token=".$options['access_token'];
			$foursquareself = json_decode(wp_remote_fopen($selfurl));
			set_transient( 'ds_foursquare_self', $foursquareself, DAY_IN_SECONDS );
		}
		?>
<h3>And we're set!</h3>
<p>
	According to Foursquare, your name is <em><?php echo $foursquareself->response->user->firstName; ?></em>.
	(We show this to check if the Foursquare integration works for us.)
</p>
<p>
	Now you're all done. The import is a slow process, just because.
</p>
		<?php
	}

	ds_foursquare_load();
}

function ds_foursquare_menu() {
	add_options_page(
		'Foursquare Import',
		'Foursquare Import',
		'manage_options',
		'ds_foursquare',
		'ds_foursquare_options'
	);  
}

function ds_foursquare_activation() {
	if ( !wp_next_scheduled( 'ds_foursquare_event' ) ) {
		wp_schedule_event( time(), 'hourly', 'ds_foursquare_event');
	}
}


function ds_foursquare_deactivation() {
	wp_clear_scheduled_hook('ds_foursquare_event');
}

add_action( 'admin_menu', 'ds_foursquare_menu' );
add_action('ds_foursquare_event', 'ds_foursquare_load');

register_activation_hook(__FILE__, 'ds_foursquare_activation');
register_deactivation_hook(__FILE__, 'ds_foursquare_deactivation');

function ds_foursquare_load() {
	$options = get_option('ds_foursquare');
	if(!isset($options['access_token'])) return;
	$queryoptions = array(
		'sort'	=>	'oldestfirst',
		'v'	=>	'20130105',
		'limit'	=>	250,
		'oauth_token'	=>	$options['access_token']
	);
	if(isset($options['latestcheckin'])){
		$queryoptions['afterTimestamp'] = $options['latestcheckin'] - 300;
	}
	$q = array();
	foreach($queryoptions as $key => $value){
		$q[] = $key ."=". $value;
	}
	$queryoptions = implode("&",$q);
	$q = md5($queryoptions);
	if ( false === ( $checkins = get_transient( 'ds_foursquare_checkins_'.$q ) ) ) {
		$checkinsurl = "https://api.foursquare.com/v2/users/self/checkins?".$queryoptions;
		$checkins = json_decode(wp_remote_fopen($checkinsurl));
		set_transient( 'ds_foursquare_checkins_'.$q, $checkins, HOUR_IN_SECONDS );
	}
	foreach($checkins->response->checkins->items as $checkin){
		$content = ds_foursquare_content_from_checkin($checkin);
		$query = new WP_Query( 'meta_key=_ds4sqid&meta_value='.$checkin->id );
		$hp = $query->have_posts();
		if(!$hp && (trim($content) != "")){
			$postdata = array(
				'post_author' => 1,
				'post_date' => gmdate('Y-m-d H:i:s',$checkin->createdAt),
				'post_date_gmt' => get_gmt_from_date(gmdate('Y-m-d H:i:s',$checkin->createdAt)),
				'post_content' => $content,
				'post_excerpt' => (isset($checkin->venue->name)) ? $checkin->venue->name : $checkin->shout,
				'post_title' => (isset($checkin->venue->name)) ? $checkin->venue->name : $checkin->shout,
				'post_status' => 'publish',
				'post_type' => 'post',
			);
			$post_id = wp_insert_post( $postdata, true );
			if(is_int($post_id)){
				if(isset($checkin->photos) && ($checkin->photos->count) > 0){
					$images = array();
					foreach($checkin->photos->items as $photo){
						$filename = sprintf("%s%sx%s%s",$photo->prefix,$photo->width,$photo->height,$photo->suffix);
						$tmp = explode("/",$photo->suffix);
						$c = count($tmp);
						$tmpname = $tmp[$c - 1];
						$tmp = dirname(__FILE__)."/cache/".$tmpname;
						copy($filename,$tmp);
						$finfo = new finfo(FILEINFO_MIME);
						$mime = $finfo->file($tmp);
						$mime = explode(";",$mime);
						$mime = $mime[0];
						$size = filesize($tmp);
						$file = array(
							'name'	=>	$tmpname,
							'type'	=>	$mime,
							'tmp_name'	=>	$tmp,
							'error'	=>	0,
							'size'	=>	$size,
						);
						$overrides = array(
							'test_form' => false
						);
						
						$file = wp_handle_sideload($file,$overrides,gmdate('Y-m-d H:i:s',$checkin->createdAt));
						$url = $file['url'];
						$type = $file['type'];
						$file = $file['file'];
						$title = (isset($checkin->venue->name)) ? $checkin->venue->name : $checkin->shout;
						$content = (isset($checkin->venue->name)) ? $checkin->venue->name : $checkin->shout;

						// use image exif/iptc data for title and caption defaults if possible
						if ( $image_meta = wp_read_image_metadata($file) ) {
							if ( trim( $image_meta['title'] ) && ! is_numeric( sanitize_title( $image_meta['title'] ) ) )
								$title = $image_meta['title'];
							if ( trim( $image_meta['caption'] ) )
								$content = $image_meta['caption'];
						}


						// Construct the attachment array
						$attachment = array(
							'post_mime_type' => $type,
							'guid' => $url,
							'post_parent' => $post_id,
							'post_title' => $title,
							'post_content' => $content,
						);
						$id = wp_insert_attachment($attachment, $file, $post_id);
						$images[] = $id;
					}
					if(isset($images[0])){
						set_post_thumbnail( $post_id, $images[0] );
					}
				}


				add_post_meta($post_id, '_ds4sqsource', serialize($checkin), true);
				add_post_meta($post_id, '_ds4sqid', $checkin->id, true);
				add_post_meta($post_id, 'checkin_id', $checkin->id, true);
				if(isset($checkin->venue)) {
					add_post_meta($post_id, 'checkin_venue_id', $checkin->venue->id, true);
					if(isset($checkin->venue->name)) add_post_meta($post_id, 'checkin_venue_name', $checkin->venue->name, true);
					if(isset($checkin->venue->location)){
						add_post_meta($post_id, 'geo_latitude', $checkin->venue->location->lat, true);
						add_post_meta($post_id, 'geo_longitude', $checkin->venue->location->lng, true);
						add_post_meta($post_id, 'geo_public', "1", true);
						if(isset($checkin->venue->location->address)) add_post_meta($post_id, 'checkin_venue_location_address', $checkin->venue->location->address, true);
						if(isset($checkin->venue->location->postalCode)) add_post_meta($post_id, 'checkin_venue_location_postalcode', $checkin->venue->location->postalCode, true);
						if(isset($checkin->venue->location->city)) add_post_meta($post_id, 'checkin_venue_location_city', $checkin->venue->location->city, true);
						if(isset($checkin->venue->location->state)) add_post_meta($post_id, 'checkin_venue_location_state', $checkin->venue->location->state, true);
						if(isset($checkin->venue->location->country)) add_post_meta($post_id, 'checkin_venue_location_country', $checkin->venue->location->country, true);
						if(isset($checkin->venue->location->cc)) add_post_meta($post_id, 'checkin_venue_location_cc', $checkin->venue->location->cc, true);
					}
				}
				if(isset($checkin->shout)) add_post_meta($post_id, 'shout', $checkin->shout, true);
				$tags = array();
				if(isset($checkin->venue->location->country)) $tags[] = trim($checkin->venue->location->country);
				if(isset($checkin->venue->location->city)) $tags[] = trim($checkin->venue->location->city);
				if(isset($checkin->venue->name)) $tags[] = trim($checkin->venue->name);
				wp_set_post_tags($post_id, $tags);
				$cat = get_category_by_slug('foursquare-checkin');
				if(!$cat){
					$cat = wp_create_category('foursquare Checkin');
				}
				if($cat && isset($cat->term_id)){
					wp_set_post_categories($post_id,array($cat->term_id));
				} elseif($cat && is_int($cat)){
					wp_set_post_categories($post_id,array($cat));
				}
			}
			echo "</pre>";
		}
		echo "</pre>";
		$options = get_option('ds_foursquare');
		if(isset($options['latestcheckin']) && ($options['latestcheckin'] < $checkin->createdAt)){
			$options['latestcheckin'] = $checkin->createdAt;
			update_option('ds_foursquare',$options);
		} elseif(!isset($options['latestcheckin'])){
			$options['latestcheckin'] = $checkin->createdAt;
			update_option('ds_foursquare',$options);
		}
	}
}

function ds_foursquare_content_from_checkin($checkin){
	$content = "";
	if(isset($checkin->shout)){
		$content .= sprintf("<blockquote>%s</blockquote>",$checkin->shout);
	}
	if(isset($checkin->event)){
		$content .= sprintf("<b>%s</b><br />\n",$checkin->event->name);
	}
	if(isset($checkin->venue)){
		$hcard = "<div class='vcard'>\n";
		$hcard .= sprintf("<b class='fn org'><a href='%s' rel='nofollow'>%s</a></b><br />\n",$checkin->venue->canonicalUrl,$checkin->venue->name);
		if(isset($checkin->venue->location)){
			$hcard .= "<div class='adr'>\n";
			if(isset($checkin->venue->location->address)) $hcard .= sprintf("<span class='street-address'>%s</span><br />\n",$checkin->venue->location->address);
			if(isset($checkin->venue->location->city)) $hcard .= sprintf("<span class='locality'>%s</span>, ",$checkin->venue->location->city);
			if(isset($checkin->venue->location->state)) $hcard .= sprintf("<span class='region'>%s</span>, ",$checkin->venue->location->state);
			if(isset($checkin->venue->location->postalCode)) $hcard .= sprintf("<span class='postal-code'>%s</span><br />\n",$checkin->venue->location->postalCode);
			if(isset($checkin->venue->location->country)) $hcard .= sprintf("<span class='country-name'>%s</span><br />\n",$checkin->venue->location->country);
			$hcard .= "</div>\n";
		}
		$hcard .= "</div>\n";
		$content .= $hcard;
	}
	if((trim($content) == "")&&($checkin->type != "venueless")){
		var_dump($checkin);
		die();
	}
	return trim($content);
}
