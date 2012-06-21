<?php
/*
Plugin Name: Shopp + Salesforce
Description: Customers who opt-in to email marketing from your WordPress e-commerce site during checkout are automatically added to Salesforce.
Version: 1.0.3
Plugin URI: http://optimizemyshopp.com
Author: Sander van de Graaf
Author URI: http://svdgraaf.nl
License: GPLv2
*/
/* (CC BY 3.0) 2012  Sander van de Graaf  (twitter: @svdgraaf)

	This plugin is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This plugin is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this plugin.  If not, see <http://www.gnu.org/licenses/>. 
*/

class Shopp_SalesForce {
	public static $api_key;
	public static $listid;

	public function __construct() {
		add_action('shopp_init', array(&$this, 'init'));
		add_action('shopp_order_success', array(&$this, 'add_to_salesforce'));
		
		$this->api_username = get_option("shopp_salesforce_api_username");
		$this->api_password = get_option("shopp_salesforce_api_password");
	}

	public function init() {
		// Actions and filters
		add_action('admin_menu', array(&$this, 'admin_menu'));
	}

	public function admin_menu() {
		global $Shopp;
		$ShoppMenu = $Shopp->Flow->Admin->MainMenu;
		$page = add_submenu_page($ShoppMenu,__('Shopp + SalesForce', 'page title'), __('+ SalesForce','menu title'), defined('SHOPP_USERLEVEL') ? SHOPP_USERLEVEL : 'manage_options', 'shopp-salesforce', array(&$this, 'render_display_settings'));
	}
 	
	public function add_to_salesforce($Purchase) {
		global $wpdb;
		
		$customer = $wpdb->get_var("SELECT * FROM ".$wpdb->prefix."shopp_customer WHERE id = '".$Purchase->customer."'");
		// do the shizzle
	}

	public function render_display_settings() {
		wp_nonce_field('shopp-salesforce');	

		if(count($_POST) > 0){
			$this->api_username = stripslashes($_POST['api_username']);
			$this->api_password = stripslashes($_POST['api_password']);
			
			update_option("shopp_salesforce_api_username", $this->api_username);
			update_option("shopp_salesforce_api_password", $this->api_password);
		}
?>
<div class="wrap">
	<h2>Shopp + SalesForce</h2>
	<div class="postbox-container" style="width:65%;">
		<div class="metabox-holder">	
			<div id="shopp-mailchimp-settings" class="postbox">
				<h3 class="hndle"><span>SalesForce Settings</span></h3>
				<div class="inside"><p>
					<form action="" method="post">
						<table>
							<th>API Username:</th>
							<td><input type="text" name="api_username" size="35" value="<?php echo $this->api_username; ?>" /></td>
						</tr>
						<tr>
							<th>API Password:</th>
							<td><input type="password" name="api_password"  size="35" value="<?php echo $this->api_password; ?>" /></td>
						</tr>
						</table>
						<input type="submit" class="button-primary" value="Save Settings" name="submit" />
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
<?php	
	}
}

new Shopp_SalesForce();