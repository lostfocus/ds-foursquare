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
		'limit'	=>	'100',
		'v'	=>	'20130105',
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
	/* foreach($checkins->response->checkins->items as $checkin){
		echo "<pre>";
		var_dump($checkin);
		echo "</pre>";
		die();
	}*/
}
