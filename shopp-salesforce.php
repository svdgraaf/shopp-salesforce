<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
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
 	
	public function add_to_salesforce($purchase) {
		global $wpdb;
		
		$customer = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."shopp_customer WHERE id = '".$purchase->customer."'");
		$address = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."shopp_address WHERE customer = '".$purchase->customer."'");
		$purchased = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."shopp_purchased WHERE purchase = '".$purchase->id."'");

		// only update customer if we can save the data
		if($customer->marketing != 'yes')
		{
			return '';
		}

		// client setup
		define("SALESFORCE_LIBRARY_PATH", dirname(__FILE__) . '/lib/salesforce/soapclient/');
		require_once (SALESFORCE_LIBRARY_PATH.'SforceEnterpriseClient.php');
		require_once (SALESFORCE_LIBRARY_PATH.'SforceHeaderOptions.php');

		// connect to salesforce
		$sfClient = new SforceEnterpriseClient();
		$sfClient->createConnection(SALESFORCE_LIBRARY_PATH.'/enterprise.wsdl.xml');
		$login = $sfClient->login($this->api_username, $this->api_password);


		// create a campaign for the product, and add the lead to it
		$sObject = new stdClass();
		$sObject->Name = $purchased->name;
		$sObject->Description = $purchased->description;
		$sObject->IsActive = True;
		$sObject->Type = 'Download';
		$sObject->Status = 'Active';
		$campaignResponse = $sfClient->upsert('Name', array($sObject), 'Campaign');

		// setup Lead object
		$sObject = new stdClass();

		// add Personal data
		$sObject->FirstName = $customer->firstname;
		$sObject->LastName = $customer->lastname;
		$sObject->Email = $customer->email;
		$sObject->Phone = $purchase->phone;

		// add Address data
		$sObject->City = $address->city;
		$sObject->Country = $address->country;
		$sObject->PostalCode = $address->postcode;
		$sObject->State = $address->state;
		$sObject->Street = $address->address;

		if((string)$purchase->company == '')
		{
			$purchase->company = 'Onbekend';	
		}
		$sObject->Company = (string)$purchase->company;

		// upsert the contact (find-and-update/create)
		$leadResponse = $sfClient->upsert('Email', array($sObject), 'Lead');

		// connect them together
		$mObject = new stdClass();
		$mObject->CampaignId = $campaignResponse[0]->id;
		$mObject->LeadId = $leadResponse[0]->id;
		$campaignMemberResponse = $sfClient->create(array($mObject), 'CampaignMember');		

		// return the response
		return array($leadResponse, $campaignResponse);
	}

	public function render_display_settings() {
		wp_nonce_field('shopp-salesforce');	

		if(count($_POST) > 0){
			$this->api_username = stripslashes($_POST['api_username']);
			$this->api_password = stripslashes($_POST['api_password']);
			
			update_option("shopp_salesforce_api_username", $this->api_username);
			update_option("shopp_salesforce_api_password", $this->api_password);

			// test button pressed
			if($_POST['submit'] == 'Save & Test') {
				global $wpdb;
				// get the latest purchase, and push it
				$purchase = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."shopp_purchase ORDER BY id DESC LIMIT 1");
				$response = $this->add_to_salesforce($purchase);

			}
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
						<input type="submit" class="button" value="Save & Test" name="submit" />
					</form>
				</div>
				<? if($response){ ?>
					<h3>Test Response</h3>
					<pre>
						<? var_dump($response); ?>
					</pre>
				<? }?>
				</div>
		</div>
	</div>
</div>
<?php	
	}
}

new Shopp_SalesForce();