<?php

// check if cookie exists
if(!isset($_COOKIE['__zts']) || !isset($_GET['c']))
{
    return false;
}

$t = $_COOKIE['__zts'];
$t = explode($t,'-');

if(count($t[0]) != 2)
{
    return false;
}

$hash = md5($t[0] + '-z24-salesforce-contact');
if($hash != $t[1])
{
    return false;
}

// we got a valid account, mark the page
define("SALESFORCE_LIBRARY_PATH", dirname(__FILE__) . '/lib/salesforce/soapclient/');
require_once (SALESFORCE_LIBRARY_PATH.'SforceEnterpriseClient.php');
require_once (SALESFORCE_LIBRARY_PATH.'SforceHeaderOptions.php');
