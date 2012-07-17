<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
/*
Plugin Name: Shopp + Salesforce
Description: Customers are automatically added to Salesforce.
Version: 1.0.3
Plugin URI: http://svdgraaf.nl/
Author: Sander van de Graaf
Author URI: http://svdgraaf.nl
License: GPLv2
*/
/* (CC BY 3.0) 2012  Sander van de Graaf  (twitter: @svdgraaf)

	This plugin is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This plugin is distributed in the hope that it will be useful","	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this plugin.  If not, see <http://www.gnu.org/licenses/>. 
*/

class Shopp_SalesForce {
	public static $api_key;
	public $_salesforce_fields = array(
		"AnnualRevenue" => 'double',
		"City" => 'string', 
		"Company" => 'string', 
		"Country" => 'string',
		"Email" => 'string',
		"Fax" => 'string',
		"FirstName" => 'string',
		"Industry" => 'string',
		"LastName" => 'string',
		"LeadSource" => 'string',
		"MobilePhone" => 'string',
		"Name" => 'string',
		"NumberOfEmployees" => 'int',
		"NumberofLocations" => 'double',
		"Phone" => 'string',
		"PostalCode" => 'string',
		"Rating" => 'string',
		"SICCode" => 'string',
		"Salutation" => 'string',
		"State" => 'string',
		"Status" => 'string',
		"Street" => 'string',
		"Title" => 'string',
		"Website" => 'string'
	);

	public function __construct() {
		add_action('shopp_init', array(&$this, 'init'));
		add_action('shopp_order_success', array(&$this, 'add_to_salesforce'));
		
		$this->api_username = get_option("shopp_salesforce_api_username");
		$this->api_password = get_option("shopp_salesforce_api_password");
		$this->api_mapping = json_decode(get_option("shopp_salesforce_api_mapping"));
	}

	public function init() {
		// Actions and filters
		add_action('admin_menu', array(&$this, 'admin_menu'));
		add_action('wp_footer', array(&$this, 'tracker'));
	}

	public function tracker(){
		return '<img src="' . $_SERVER['HTTP_HOST'] . '/wp-content/plugins/shopp-salesforce/track.php?c=foo" />';
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
		if(!$customer){
			return 'User not found?';
		}

		// define the userid
		$userid = $customer->wpuser;

		// client setup
		define("SALESFORCE_LIBRARY_PATH", dirname(__FILE__) . '/lib/salesforce/soapclient/');
		require_once (SALESFORCE_LIBRARY_PATH.'SforceEnterpriseClient.php');
		require_once (SALESFORCE_LIBRARY_PATH.'SforceHeaderOptions.php');

		// connect to salesforce
		$sfClient = new SforceEnterpriseClient();
		$sfClient->createConnection(SALESFORCE_LIBRARY_PATH.'/enterprise.wsdl.xml');
		$login = $sfClient->login($this->api_username, $this->api_password);

		// create a campaign for the product, and add the lead to it
		$cObject = new stdClass();
		$cObject->Name = $purchased->name;
		$cObject->Description = $purchased->description;
		$cObject->IsActive = True;
		$cObject->Type = 'Download';
		$cObject->Status = 'Active';
		try {
			$campaignResponse = $sfClient->upsert('Name', array($cObject), 'Campaign');
		}
		catch(Exception $e) {
			return 'Failed creating the campaign :(';
		}

		// setup Lead object
		$lObject = new stdClass();

		// add Personal data
		$lObject->FirstName = $customer->firstname;
		$lObject->LastName = $customer->lastname;
		$lObject->Email = $customer->email;
		$lObject->Phone = $purchase->phone;

		// add Address data
		$lObject->MailingCity = $address->city;
		$lObject->MailingCountry = $address->country;
		$lObject->MailingPostalCode = $address->postcode;
		$lObject->MailingState = $address->state;
		$lObject->MailingStreet = $address->address;

		// get the meta data from the mapping
		foreach($this->api_mapping AS $metafield => $field)
		{
			$value = get_user_meta($userid, $metafield, true);
			if($this->_salesforce_fields[$field] == 'int')
			{
				$value = intval($value);
			}
			if($this->_salesforce_fields[$field] == 'double')
			{
				$value = doubleval($value);
			}
			$lObject->$field = $value;
		}

		if((string)$purchase->company == '')
		{
			$purchase->company = 'Onbekend';	
		}
		// $lObject->Name = (string)$purchase->company;
		$lObject->LeadSource = 'Shopp';

		// upsert the contact (find-and-update/create)
		try {
			$leadResponse = $sfClient->upsert('Email', array($lObject), 'Contact');
		}
		catch(Exception $e) {
			// return 'Failed creating the lead :(';
			return array('Failed creating the lead :(', $e);
		}

		// connect them together
		$mObject = new stdClass();
		$mObject->CampaignId = $campaignResponse[0]->id;
		$mObject->ContactId = $leadResponse[0]->id;
		try {
			$campaignMemberResponse = $sfClient->create(array($mObject), 'CampaignMember');		
		}
		catch(Exception $e) {
			return array('Failed adding the lead to the campaign :(', $e);
		}

		// set a cookie, so we can track the user, set it on the main hostname
		$host = '.z24.nl';
		// $host = '192.168.10.146';
		$cookieValue = $leadResponse[0]->id . '-' . md5($leadResponse[0]->id + '-z24-salesforce-contact');
		$cookie = setcookie('__zts', $cookieValue); //, time()+60*60*24*30, '/', $host);

		// return the response
		return array($cookieValue, $cookie, $lObject, $leadResponse, $cObject, $campaignResponse, $mObject, $campaignMemberResponse);
	}

	public function render_display_settings() {
		wp_nonce_field('shopp-salesforce');	

		if(isset($_POST['api_username'])){
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

		// did we get an updated mapping?
		if(isset($_POST['fields']))
		{
			$mapping = array();
			foreach($_POST['fields'] AS $i => $metafield)
			{
				if(trim($metafield) != '' && trim($_POST['mapping'][$i]) != '')
				{
					$mapping[$metafield] = $_POST['mapping'][$i];
				}
			}

			$this->api_mapping = $mapping;
			update_option("shopp_salesforce_api_mapping", json_encode($this->api_mapping));
		}
		var_dump($_COOKIE);

?>
<div class="wrap">
	<h2>Shopp + SalesForce</h2>
	<div class="postbox-container" style="width:65%;">
		<div class="metabox-holder">	
			<div id="shopp-salesforce-settings" class="postbox">
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
				<h3 class="hndle"><span>Salesforce meta-mapping</span></h3>
				<div class="inside">
					<form action="" method="post">
						<table>
							<thead>
								<tr>
									<td>Meta key</td>
									<td>Salesforce field</td>
								</tr>
							</thead>
							<tbody>
								<? $i=0;$max=count($this->api_mapping) + 5;foreach($this->api_mapping AS $metafield => $mapping) {
								$i++; ?>
								<tr>
									<td><input type="text" name="fields[<? echo $i; ?>]" value="<?= $metafield; ?>"></td>
									<td>
										<select name="mapping[<? echo $i; ?>]">
											<option value="">None</option>
											<? foreach($this->_salesforce_fields AS $field => $type) { ?>
											<option value="<? echo $field; ?>" <? if($mapping == $field){?>selected="selected"<?}?>><? echo $field; ?></option>
											<? } ?>
										</select>
									</td>
								</tr>
								<? } ?>
								<? for($i=$i;$i<$max;$i++){ ?>
								<tr>
									<td><input type="text" name="fields[<? echo $i; ?>]"></td>
									<td>
										<select name="mapping[<? echo $i; ?>]">
											<option value="">None</option>
											<? foreach($this->_salesforce_fields AS $field => $type) { ?>
											<option value="<? echo $field; ?>"><? echo $field; ?></option>
											<? } ?>
										</select>
									</td>
								</tr>
								<? } ?>
							</tbody>
						</table>
						<input type="submit" class="button-primary" value="Save mapping" />
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