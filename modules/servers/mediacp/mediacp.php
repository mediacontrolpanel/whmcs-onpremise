<?php
    use Illuminate\Database\Capsule\Manager as Capsule;


	/** Ensure XMLRPC class is not already included **/
	if ( !class_exists("IXR_Value") ){
		include_once('IXR_Library.php');
	}


	/**
	 * Define module related meta data.
	 *
	 * Values returned here are used to determine module related abilities and
	 * settings.
	 *
	 * @see http://docs.whmcs.com/Provisioning_Module_Meta_Data_Parameters
	 *
	 * @return array
	 */
	function mediacp_MetaData()
	{
		return array(
			'DisplayName' => 'Media Control Panel',
			'APIVersion' => '1.1', // Use API Version 1.1
			'RequiresServer' => true, // Set true if module requires a server to work
			'DefaultNonSSLPort' => '2020', // Default Non-SSL Connection Port
			'DefaultSSLPort' => '2020', // Default SSL Connection Port
            // The display name of the unique identifier to be displayed on the table output
            'ListAccountsUniqueIdentifierDisplayName' => 'Domain',
            // The field in the return that matches the unique identifier
            'ListAccountsUniqueIdentifierField' => 'domain',
            // The config option indexed field from the _ConfigOptions function that identifies the product on the remote system
            'ListAccountsProductField' => 'configoption1',
		);
	}

	function mediacp_ConfigOptions(){

		mediacp_DetectUpgradeDatabase();

        $configarray = array(
            "serviceplugin" => array(
                "FriendlyName" => "Media Service",
                "Type" => "dropdown",
                "Size" => "28",
                "Options" => "Shoutcast 2,Shoutcast 198,Icecast 2,Icecast 2 KH,Wowza Streaming Engine,Flussonic,NginxRtmp,Audio Transcoder",
                "Description" => "",
                "Default" => "Shoutcast 2",
                'SimpleMode' => true,

            ),

            "sourceplugin" => array(
                "FriendlyName" => "Audio AutoDJ Type",
                "Type" => "dropdown",
                "Size" => "28",
                "Options" => "No Source,Liquidsoap,Shoutcast Transcoder V1,Shoutcast Transcoder V2,Ices 0.4 (MP3),Ices 2.0 (OGG),Stream Transcoder V3",
                "Description" => "(Audio Services Only - AutoDJ)",
                "Default" => "No Source",
                'SimpleMode' => true,

            ),

            "connections" => array(
                "FriendlyName" => "Limit Connections",
                "Type" => "text",
                "Size" => "28",
                "Description" => "",
                "Default" => "100",
                'SimpleMode' => true,

            ),
            "bitrate" => array(
                "FriendlyName" => "Limit Bitrate",
                "Type" => "dropdown",
                "Size" => "28",
                "Description" => "",
                "Options" => "24,32,40,48,56,64,80,96,112,128,160,192,224,256,320,400,480,560,640,720,800,920,1024,1280,1536,1792,2048,2560,3072,3584,4096,4068,5120,5632,6144,6656,7168,7680,8192,9216,10240,11264,12228,13312,14336,99999",
                "Default" => "100",
                'SimpleMode' => true,

            ),
            "transfer" => array(
                "FriendlyName" => "Limit Data Transfer",
                "Type" => "text",
                "Size" => "28",
                "Description" => "",
                "Default" => "2TB",
                'SimpleMode' => true,

            ),
            "diskusage" => array(
                "FriendlyName" => "Limit Disk Space",
                "Type" => "text",
                "Size" => "28",
                "Description" => "",
                "Default" => "500MB",
                'SimpleMode' => true,
            ),
            "servicetype" => array(
                "FriendlyName" => "Video Service Type",
                "Type" => "dropdown",
                "Size" => "28",
                "Description" => "<br />Only applies to Video Streaming services.",
                "Options" => ",Live Streaming,Live Streaming Low Latency,Ondemand Streaming,TV Station,Shoutcast,Live Camera Restream",
                "Default" => "",
                'SimpleMode' => true,
            ),
            "usernametype" => array(
                "FriendlyName" => "Username Setting",
                "Type" => "dropdown",
                "Size" => "28",
                "Description" => "",
                "Options" => "Shared Client Email,Individual Accounts",
                "Default" => ""
            ),
            "resellerplan" => array(
                "FriendlyName" => "Reseller Plan ID",
                "Type" => "text",
                "Size" => "28",
                "Description" => "<br />Enter Reseller Plan ID or leave blank for normal account.",
                "Default" => ""
            ),
        );
		return $configarray;
	}

	function mediacp_GetConfiguration($params){

		mediacp_DetectUpgradeServiceID($params);

		$int = 1;
		$DefaultArray = mediacp_ConfigOptions();
		$ConfigArray = array();
		foreach( $DefaultArray as $option => $arr){
			$ConfigArray[$option] = $params['configoption'. $int];
			$int++;
		}
		return $ConfigArray;
	}

	function mediacp_CreateAccount($params){

		$Config = mediacp_GetConfiguration($params);
		$ServiceData = mediacp_AdminServicesTabFieldsGet($params);

		# Check if account already created
        if ( $params['status'] == 'Active' ){
            return "WHMCS service status is Active, please terminate or set status to Terminated and then try again.";
        }

		if ( !empty($ServiceData['ServiceID']) ){
            mediacp_TerminateAccount($params);
		}

		# Check User Account
        $username = empty($Config['usernametype']) || $Config['usernametype'] == 'Shared Client Email' ?
            trim($params['clientsdetails']['email']) :
            (
            empty($params['username']) ? // If username is empty, then it's because WHMCS "Enable random usernames" is not enabled; we should use part of the email as username instead
                substr(preg_replace("/[^a-zA-Z0-9]+/", "", $params['clientsdetails']['email']),0,8) . substr(uniqid(),8,12) :
                trim($params['username'])
            );

        $password = empty($Config['usernametype']) || $Config['usernametype'] == 'Shared Client Email' ? mediacp_getClientPassword($params['clientsdetails']['userid']) : trim($params['password']);
		$hash = SHA1($username . $password);

			$api = array(
				"rpc"		=> "admin.user_create",
				"args"		=> array(
								"auth"			=> $params['serveraccesshash'],
								"username"		=> $username,
								"hash"			=> $hash,
								"user_email"	=> trim($params['clientsdetails']['email']),
								"name"			=> trim($params['clientsdetails']['firstname']) . " " . trim($params['clientsdetails']['lastname']),
								"contact_number"=> trim($params['clientsdetails']['phonenumber'])
				)
			);

			# Reseller Account?
			if ( !empty($Config['resellerplan']) && is_numeric($Config['resellerplan']) ){
				$api['args']['reseller_plan'] = $Config['resellerplan'];
			}

			# Execute API
			$return = mediacp_api( $api, $params );

			# If user account exists and reseller plan specified, then update user account with reseller plan
            if ( $return['status'] != 'success' && $return['error'] == 'User account already exists' && !empty($Config['resellerplan']) && is_numeric($Config['resellerplan']) ){
                $return = mediacp_api( $api, [
                    "rpc"		=> "admin.user_update",
                    "args"		=> [
                        "auth"			=> $params['serveraccesshash'],
                        "username"		=> $username,
                        "hash"			=> $hash,
                        "user_email"	=> trim($params['clientsdetails']['email']),
                        "name"			=> trim($params['clientsdetails']['firstname']) . " " . trim($params['clientsdetails']['lastname']),
                        "contact_number"=> trim($params['clientsdetails']['phonenumber']),
                        "reseller_plan" => $Config['resellerplan']
                    ]
                ]);
            }

            # if no reseller plan specified, return that user already exists error
			if ( $return['status'] != 'success' && $return['error'] != 'User account already exists' ){
				return $return['error'];
			}

			# Update WHMCS Username & Password Fields
			full_query("UPDATE tblhosting 	SET	username='".  $username  ."',
												password='".  encrypt($password)  ."'
											WHERE id='".$params["accountid"]."'");



			mediacp_AdminServicesTabFieldsUpdate($params, array("CustomerID"=>$return['id']));
			$ServiceData['CustomerID'] = $return['id'];


		# Reseller Account?
		if ( !empty($Config['resellerplan']) && is_numeric($Config['resellerplan']) ){
			return 'success';
		}


		# Service Creation
		$api = array(
			"path"		=> $params['serverhostname'],
			"rpc"		=> "admin.service_create",
			"args"		=> array(
							"auth"			=> $params['serveraccesshash'],
							"plan"			=> false,
							"rpc_extra"		=> 1,
							"userid"		=> $ServiceData['CustomerID'],

							"password"		=> $password,
							"adminpassword"	=> $password,
							"plugin"		=> $Config['serviceplugin'],
							"sourceplugin"	=> $Config['sourceplugin'],
							"maxuser"		=> $Config['connections'],
							"bitrate"		=> $Config['bitrate'],
							"bandwidth"		=> $Config['transfer'],
							"quota"			=> $Config['diskusage']
			)
		);

		# Icecast source password
        if ( $api['args']['plugin'] == 'icecast' || $api['args']['plugin'] == 'icecast_kh'){
            unset($api['args']['password']);
            $api['args']['customfields']['source_password'] = $password;
        }

		# Wowza Service Type
		if ( $Config['serviceplugin'] == 'Wowza Streaming Engine' || $Config['serviceplugin'] == 'Flussonic' ){
			$api['args']['customfields']['servicetype'] = $Config['servicetype'];
		}

		# Configurable Options
		$api['args'] = mediacp_ProcessServiceOptions($api['args'], $params);

		# Custom Field: Publish Name
		if ( isset($params['customfields']['Service Name']) )	$api['args']['unique_id'] = $params['customfields']['Service Name'];
		if ( isset($params['customfields']['Publish Name']) )	$api['args']['unique_id'] = $params['customfields']['Publish Name'];

		# If Publish name already specified then try to use that
        if ( !empty($ServiceData['PublishName']) && strlen($ServiceData['PublishName']) > 3 ){
            $api['args']['unique_id'] = $ServiceData['PublishName'];
        }

        # Random combination for Video Services if no unique_id specified
        if ( empty($api['args']['unique_id']) && in_array($api['args']['plugin'], ['WowzaMedia','Flussonic','NginxRtmp']) ){
            $api['args']['unique_id'] = mediacp_generateStrongPassword(10,false,'l');
        }



		# Custom Field: Wowza: Shoutcast Address


		###########################

		$return = mediacp_api($api,$params);

		if ( $return['status'] != 'success' ){
			return "ERROR: " . $return['error'];
		}

		# Update CustomField ServiceID for tracking
		mediacp_AdminServicesTabFieldsUpdate($params, array("ServiceID"=>$return['id']));
		mediacp_AdminServicesTabFieldsUpdate($params, array("PublishName"=>$return['serverData']['unique_id']));

		$domain = in_array($api['args']['plugin'], ['WowzaMedia','Flussonic','NginxRtmp']) ? $return['serverData']['unique_id'] : ($params['serverhostname'] .':'. $return['portbase']);

		full_query("UPDATE tblhosting 	SET domain='".  $domain  ."' WHERE id='".$params["accountid"]."'");
		full_query("UPDATE tblhosting 	SET dedicatedip='".  ($params['serverip'])  ."' WHERE id='".$params["accountid"]."'");
		return 'success';

	}

	function mediacp_TerminateAccount($params) {

		$Config = mediacp_GetConfiguration($params);
		$ServiceData = mediacp_AdminServicesTabFieldsGet($params);

		# Terminate Reseller Account

		# Delete Media Service
		if ( !empty($ServiceData['ServiceID']) ){
			$api = array(
				"path"		=> $params['serverhostname'],
				"rpc"		=> "admin.service_remove",
				"args"		=> array(
								"auth"			=> $params['serveraccesshash'],
								"serverid"		=> $ServiceData['ServiceID'],
				)
			);

			$return = mediacp_api( $api, $params );

			if ( $return['status'] != 'success' && $return['error'] != 'Could not locate service' ){
				return $return['error'];
			}
		}

		mediacp_AdminServicesTabFieldsUpdate($params, array("CustomerID"=>0));
		mediacp_AdminServicesTabFieldsUpdate($params, array("ServiceID"=>0));


		return 'success';

	}

	function mediacp_SuspendAccount($params) {

		$Config = mediacp_GetConfiguration($params);
		$ServiceData = mediacp_AdminServicesTabFieldsGet($params);

		# Suspend Reseller Account
		if ( !empty($Config['resellerplan']) && is_numeric($Config['resellerplan']) ){

			# Attempt to suspend all services
			$api = array(
				"path"		=> $params['serverhostname'],
				"rpc"		=> "admin.user_suspend_services",
				"args"		=> array(
								"auth"			=> $params['serveraccesshash'],
								"userid"		=> $ServiceData['CustomerID']

				)
			);
			$return = mediacp_api( $api, $params );

			$api = array(
				"path"		=> $params['serverhostname'],
				"rpc"		=> "admin.user_update",
				"args"		=> array(
								"auth"			=> $params['serveraccesshash'],
								"userid"		=> $ServiceData['CustomerID'],
								"activated"		=> 0

				)
			);
			$return = mediacp_api( $api, $params );

			if ( $return['status'] != 'success' ){
				return $return['error'];
			}
			return 'success';
		}

		# Suspend Media Service
		$api = array(
			"path"		=> $params['serverhostname'],
			"rpc"		=> "admin.service_suspend",
			"args"		=> array(
							"auth"			=> $params['serveraccesshash'],
							"ServerID"		=> $ServiceData['ServiceID'],
							"Reason"		=> $params['suspendreason'],
							"Days"			=> 9999999999999

			)
		);
		$return = mediacp_api( $api, $params );

		if ( $return['status'] != 'success' ){
			return $return['error'];
		}

		return 'success';
	}

	function mediacp_UnsuspendAccount($params) {

		$Config = mediacp_GetConfiguration($params);
		$ServiceData = mediacp_AdminServicesTabFieldsGet($params);

		# Suspend Reseller Account
		if ( !empty($Config['resellerplan']) && is_numeric($Config['resellerplan']) ){


			# Attempt to unsuspend all services
			$api = array(
				"path"		=> $params['serverhostname'],
				"rpc"		=> "admin.user_unsuspend_services",
				"args"		=> array(
					"auth"			=> $params['serveraccesshash'],
					"userid"		=> $ServiceData['CustomerID']

				)
			);
			$return = mediacp_api( $api, $params );

			$api = array(
				"path"		=> $params['serverhostname'],
				"rpc"		=> "admin.user_update",
				"args"		=> array(
								"auth"			=> $params['serveraccesshash'],
								"userid"		=> $ServiceData['CustomerID'],
								"activated"		=> 1

				)
			);
			$return = mediacp_api( $api, $params );

			if ( $return['status'] != 'success' ){
				return $return['error'];
			}
			return 'success';
		}

		# Suspend Media Service
		$api = array(
			"path"		=> $params['serverhostname'],
			"rpc"		=> "admin.service_unsuspend",
			"args"		=> array(
							"auth"			=> $params['serveraccesshash'],
							"ServerID"		=> $ServiceData['ServiceID'],
							"start"			=> $params['suspendreason']

			)
		);
		$return = mediacp_api( $api, $params );

		if ( $return['status'] != 'success' ){
			return $return['error'];
		}

		return 'success';
	}

	function mediacp_ChangePassword($params) {

		$Config = mediacp_GetConfiguration($params);
		$ServiceData = mediacp_AdminServicesTabFieldsGet($params);

		$username = empty($Config['usernametype']) || $Config['usernametype'] == 'Shared Client Email' ? trim($params['clientsdetails']['email']) : trim($params['username']);
		$password = trim($params['password']);

		$hash = SHA1($username . $password);

		$api = array(
			"path"		=> $params['serverhostname'],
			"rpc"		=> "admin.user_update",
			"args"		=> array(
							"auth"			=> $params['serveraccesshash'],
							"userid"		=> $ServiceData['CustomerID'],
							"hash"			=> $hash

			)
		);
		$return = mediacp_api( $api, $params );

		if ( $return['status'] != 'success' ){
			return $return['error'];
		}

		# Update Shared Password
		if ( empty($Config['usernametype']) || $Config['usernametype'] == 'Shared Client Email' ){
			mediacp_updateClientPassword($ServiceData['CustomerID'], $password);
		}

		return 'success';
	}

	function mediacp_ChangePackage($params) {

		$Config = mediacp_GetConfiguration($params);
		$ServiceData = mediacp_AdminServicesTabFieldsGet($params);

		$api = array(
			"path"		=> $params['serverhostname'],
			"rpc"		=> "admin.service_update",
			"args"		=> array(
							"auth"			=> $params['serveraccesshash'],
							"ServerID"		=> $ServiceData['ServiceID'],
							"maxuser"		=> $Config['connections'],
							"bitrate"		=> $Config['bitrate'],
							"bandwidth"		=> $Config['transfer'],
							"quota"			=> $Config['diskusage']
			)
		);
		$api['args'] = mediacp_ProcessServiceOptions($api['args'], $params);

		# Unsupported Fields (will cause issues if changed after creation)
		unset($api['args']['plugin']);
		unset($api['args']['sourceplugin']);
		unset($api['args']['customfields']['servicetype']);

		$return = mediacp_api( $api, $params );

		if ( $return['status'] != 'success' ){
			return $return['error'];
		}

		return 'success';

	}

	function mediacp_ClientArea($params) {

		$panelUrl = mediacp_get_panel_url($params);
		# Output can be returned like this, or defined via a clientarea.tpl template file (see docs for more info)

		$code = '<form action="'.$panelUrl['url'].'" method="post" target="_blank">
	<input type="hidden" name="username" value="'.$params["username"].'" />
	<input type="hidden" name="user_password" value="'.$params["password"].'" />
	<input type="submit" class="btn btn-primary" value="Login to Control Panel" />
	</form>';
		return $code;

	}

	function mediacp_AdminLink($params) {

		$panelUrl = mediacp_get_panel_url($params);
		$code = '<form action="'.$panelUrl['url'].'" method="post" target="_blank">
	<input type="hidden" name="username" value="'.$params["serverusername"].'" />
	<input type="hidden" name="user_password" value="'.$params["serverpassword"].'" />
	<input type="submit" value="Login to Control Panel" />
	</form>';
		return $code;

	}

	function mediacp_LoginLink($params) {
		$panelUrl = mediacp_get_panel_url($params);

		$Config = mediacp_GetConfiguration($params);
		$ServiceData = mediacp_AdminServicesTabFieldsGet($params);

		echo "<a href=\"".$panelUrl["url"]."/?page=admin&m=servers&action=view&id=".$ServiceData["ServiceID"]."\" target=\"_blank\" style=\"color:#cc0000\">view media service</a> - ";
		echo "<a href=\"".$panelUrl["url"]."/?page=admin&m=users&action=login&id=".$ServiceData["CustomerID"]."\" target=\"_blank\" style=\"color:#cc0000\">login to customer account</a>";

	}

	function mediacp_restart($params) {

		$Config = mediacp_GetConfiguration($params);
		$ServiceData = mediacp_AdminServicesTabFieldsGet($params);

		# Restart Media Service
		$api = array(
			"path"		=> $params['serverhostname'],
			"rpc"		=> "service.restart",
			"args"		=> array(
							"auth"			=> $params['serveraccesshash'],
							"ServerID"		=> $ServiceData['ServiceID']

			)
		);
		$return = mediacp_api( $api, $params );

		if ( $return['status'] != 'success' && $return['status'] != 'queued' ){
			return 'ERROR: '.print_r($return,true);
		}

		return 'success';
	}

	function mediacp_stop($params) {

		$Config = mediacp_GetConfiguration($params);
		$ServiceData = mediacp_AdminServicesTabFieldsGet($params);

		# Restart Media Service
		$api = array(
			"path"		=> $params['serverhostname'],
			"rpc"		=> "service.stop",
			"args"		=> array(
							"auth"			=> $params['serveraccesshash'],
							"ServerID"		=> $ServiceData['ServiceID']

			)
		);
		$return = mediacp_api( $api, $params );

		if ( $return['status'] != 'success' && $return['status'] != 'queued' ){
			return 'ERROR: '.print_r($return,true);
		}

		return 'success';
	}

	function mediacp_restartsource($params) {

		$Config = mediacp_GetConfiguration($params);
		$ServiceData = mediacp_AdminServicesTabFieldsGet($params);

		# Restart Media Service
		$api = array(
			"path"		=> $params['serverhostname'],
			"rpc"		=> "source.restart",
			"args"		=> array(
							"auth"			=> $params['serveraccesshash'],
							"ServerID"		=> $ServiceData['ServiceID']

			)
		);
		$return = mediacp_api( $api, $params );

		if ( $return['status'] != 'success' && $return['status'] != 'queued' ){
			return 'ERROR: '.print_r($return,true);
		}

		return 'success';
	}
	function mediacp_stopsource($params) {

		$Config = mediacp_GetConfiguration($params);
		$ServiceData = mediacp_AdminServicesTabFieldsGet($params);

		# Restart Media Service
		$api = array(
			"path"		=> $params['serverhostname'],
			"rpc"		=> "source.stop",
			"args"		=> array(
							"auth"			=> $params['serveraccesshash'],
							"ServerID"		=> $ServiceData['ServiceID']

			)
		);
		$return = mediacp_api( $api, $params );

		if ( $return['status'] != 'success' && $return['status'] != 'queued' ){
			return 'ERROR: '.print_r($return,true);
		}

		return 'success';
	}

	function mediacp_ClientAreaCustomButtonArray() {
		$buttonarray = array(
		 "Restart Service" => "restart",
		 "Stop Service" => "stop",
		 "Restart Source" => "restartsource",
		 "Stop Source" => "stopsource",
		);
		return $buttonarray;
	}

	function mediacp_AdminCustomButtonArray() {
		$buttonarray = array(
		 "Restart" => "restart",
		 "Stop" => "stop",
		 "Restart Source" => "restartsource",
		 "Stop Source" => "stopsource",
		 "Update Usage" => "UsageUpdate",
		);
		return $buttonarray;
	}
	/*
	function mediacp_extrapage($params) {
		$pagearray = array(
		 'templatefile' => 'example',
		 'breadcrumb' => ' > <a href="#">Example Page</a>',
		 'vars' => array(
			'var1' => 'demo1',
			'var2' => 'demo2',
		 ),
		);
		return $pagearray;
	}*/

	function mediacp_UsageUpdate($params) {

		#$Config = mediacp_GetConfiguration($params);

		$serverid = $params['serverid'];
		$serverhostname = $params['serverhostname'];
		$serverip = $params['serverip'];
		$serverusername = $params['serverusername'];
		$serverpassword = $params['serverpassword'];
		$serveraccesshash = $params['serveraccesshash'];
		$serversecure = $params['serversecure'];

		# Run connection to retrieve usage for all domains/accounts on $serverid
		$api = array(
			"path"		=> $params['serverhostname'],
			"rpc"		=> "admin.service_usage",
			"args"		=> array(
							"auth"			=> $params['serveraccesshash']

			)
		);
		$return = mediacp_api( $api, $params );

		if ( !$return['status'] ){
			return 'ERROR: '.print_r($return,true);
		}



        # Now loop through results and update DB
        foreach ($return['usage'] AS $serviceid=>$values) {
            $service = Capsule::table('mod_mediacp_fields')->select('service_id')->where('ServiceID',$serviceid)->first();
            if ( !$service ) continue;
            try {
                Capsule::table('tblhosting')
                    ->where('id',$service->service_id)
                    ->update([
                        'diskusage' 	=> $values['DiskUsed'],
                        'disklimit' 	=> $values['DiskLimit'],
                        'bwusage' 		=> $values['TransferUsed'],
                        'bwlimit' 		=> $values['TransferLimit'],
                        'lastupdate'	=> Capsule::raw('now()'),

                    ]);
            } catch (\Exception $e) {
                // Handle any error which may occur
            }
        }

	}

	function mediacp_AdminServicesTabFieldsGet($params){
		mediacp_checkTableCreation();
        $data = (array) Capsule::table('mod_mediacp_fields')->where('service_id',$params['serviceid'])->get()->toArray()[0];

		return array(
					"CustomerID" => $data['CustomerID'],
					"ServiceID" => $data['ServiceID'],
					"PublishName" => $data['PublishName']
					//"Service Link" => $data['ServiceLink'],
					//"RTMP Link"	=> $data['RTMP'],
					//"RTSP Link" => $data['RTSP']

		);
	}
	function mediacp_AdminServicesTabFieldsUpdate($params, $dataarray){
	    $adminFields = Capsule::table('mod_mediacp_fields')->where('service_id',$params['serviceid']);
		if ( $adminFields->count() == 0 ){
		    Capsule::table("mod_mediacp_fields")->insert(['service_id'=>$params['serviceid']]);
		}
		$adminFields->update($dataarray);

	}

	function mediacp_AdminServicesTabFields($params) {
		$ServiceConfig = mediacp_AdminServicesTabFieldsGet($params);
		foreach($ServiceConfig as $Option=>$Val){
			$ServiceConfig[$Option] = "<input type=\"text\" name=\"modulefields[{$Option}]\" value=\"{$Val}\" />";
		}
		return $ServiceConfig;
	}

	function mediacp_AdminServicesTabFieldsSave($params) {
		mediacp_AdminServicesTabFieldsUpdate($params, $_POST['modulefields']);
	}















	/****** MEDIACP FUNCTIONALITY ******/

	function mediacp_ProcessServiceOptions($args, $params){

		$Config = mediacp_GetConfiguration($params);
		$ServiceData = mediacp_AdminServicesTabFieldsGet($params);

		/*
			plugin
			sourceplugin
			maxuser
			bitrate
			bandwidth
			quota
		*/

		/* CONFIGURABLE OPTIOSN & VALIDATION */
			$configoptions = $params['configoptions'];

			# Plugin / Media Service
			if ( isset($configoptions['Media Service']) )			$args['plugin'] = $configoptions['Media Service'];

			switch($args['plugin']){
				case 'Shoutcast':
				case 'Shoutcast 2':
					$args['plugin'] = 'shoutcast2';
					$args['adminpassword'] = mediacp_generatePassword();
				break;

				case 'Shoutcast 198':
					$args['plugin'] = 'shoutcast198';
				break;

				case 'Icecast':
				case 'Icecast 2':
					$args['plugin'] = 'icecast';
                    $args['customfields']['source_password'] = $args['password'];
                    unset($args['password']);
                    unset($args['adminpassword']);
				break;

				case 'Icecast KH':
				case 'Icecast 2 KH':
					$args['plugin'] = 'icecast_kh';
                    $args['customfields']['source_password'] = $args['password'];
                    unset($args['password']);
                    unset($args['adminpassword']);
				break;

				case 'Audio Transcoder':
				case 'Audio Transcoding':
				case 'Transcoder':
				case 'Shoutcast Transcoder':
				case 'Icecast Transcoder':
					$args['plugin'] = 'AudioTranscoder';
				break;

				case 'Wowza Streaming Engine':
				case 'Wowza':
				case 'Wowza Media Services':
				case 'Flash Media Service':
				case 'Flash Media':
					$args['plugin'] = 'WowzaMedia';
				break;

				case 'Flussonic':
					$args['plugin'] = 'Flussonic';
					break;
				case 'NginxRtmp':
					$args['plugin'] = 'NginxRtmp';
					break;

				case 'Windows Media Services':
				case 'Windows Media':
					$args['plugin'] = 'windowsMediaServices';
				break;
			}

			# Source
			if ( isset($configoptions['Mountpoints']) )				$args['customfields']['mountpoints'] = (int) $configoptions['Mountpoints'];
			if ( isset($configoptions['AutoDJ Sources']) )			$args['customfields']['maxsources'] = (int) $configoptions['AutoDJ Sources'];
			if ( isset($configoptions['Source']) )					$args['sourceplugin'] = $configoptions['Source'];
			if ( isset($configoptions['AutoDJ']) )					$args['sourceplugin'] = $configoptions['AutoDJ'];

			if ( $args['sourceplugin'] == 'No Source' ){
				unset($args['sourceplugin']);
			}

			if ( isset($args['sourceplugin']) ){

				if ( !isset($args['customfields']['maxsources']) ) $args['customfields']['maxsources'] = 1;

				switch($args['sourceplugin']){

					/** AUTO SELECT **/
					case 'Yes':
						unset($args['sourceplugin']);
						if ( $args['plugin'] == 'shoutcast198' ) $args['sourceplugin'] = 'sctransv2';
						if ( $args['plugin'] == 'shoutcast2' ) $args['sourceplugin'] = 'sctransv2';
						if ( $args['plugin'] == 'icescast2' ) $args['sourceplugin'] = 'sctransv2';
						if ( $args['plugin'] == 'icecast_kh' ) $args['sourceplugin'] = 'sctransv2';
					break;

					/** END BEST MATCHING **/

					case 'sctransv1':
					case 'Shoutcast Transcoder V1':
						$args['sourceplugin'] = 'sctransv1';
					break;
					case 'sctransv2':
					case 'Shoutcast Transcoder V2':
						$args['sourceplugin'] = 'sctransv2';
					break;
					case 'Ices 0.4 (MP3)':
					case 'Ices 0.4':
					case 'Ices':
					case 'ices04':
							$args['sourceplugin'] = 'ices04';
					break;
					case 'Ices 2.0 (OGG)':
					case 'Ices 2.0':
					case 'Ices 2':
					case 'ices20':
							$args['sourceplugin'] = 'ices20';
					break;
					case 'Stream Transcoder V3':
							$args['sourceplugin'] = 'streamtranscoderv3';
					break;
					case 'Liquidsoap':
							$args['sourceplugin'] = 'liquidsoap';
					break;
				}



				# Check Supported AutoDJ
				switch($args['plugin']){
					case 'shoutcast2':
						if ( $args['sourceplugin'] != 'sctransv2' && $args['sourceplugin'] != 'liquidsoap'){
							$args['sourceplugin'] = 'sctransv2';
						}
					break;
					case 'shoutcast198':
						if ( $args['sourceplugin'] != 'sctransv1' &&
							$args['sourceplugin'] != 'sctransv2' &&
							$args['sourceplugin'] != 'liquidsoap' &&
							$args['sourceplugin'] != 'ices04' &&
							$args['sourceplugin'] != 'ices20')
						{
							$args['sourceplugin'] = 'sctransv2';
						}
					break;
					case 'icecast':
						if (
							$args['sourceplugin'] != 'sctransv2' &&
							$args['sourceplugin'] != 'liquidsoap' &&
							$args['sourceplugin'] != 'ices04' &&
							$args['sourceplugin'] != 'ices20')
						{
							$args['sourceplugin'] = 'sctransv2';
						}
					break;
					case 'icecast_kh':
						if (
							$args['sourceplugin'] != 'sctransv2' &&
							$args['sourceplugin'] != 'liquidsoap' &&
							$args['sourceplugin'] != 'ices04' &&
							$args['sourceplugin'] != 'ices20')
						{
							$args['sourceplugin'] = 'sctransv2';
						}

					break;
					case 'AudioTranscoder':
					case 'WowzaMedia':
					case 'Flussonic':
					case 'NginxRtmp':
						unset($args['sourceplugin']);
					break;
					case 'windowsMediaServices':
						unset($args['sourceplugin']);
					break;
				}
			}

			# Connections (expects number ONLY)
			if ( isset($configoptions['Connections']) )				$args['maxuser'] = $configoptions['Connections'];
			if ( isset($configoptions['Listeners']) )				$args['maxuser'] = $configoptions['Listeners'];
			if ( isset($configoptions['Viewers']) )					$args['maxuser'] = $configoptions['Viewers'];
			if ( isset($configoptions['Maximum Users']) )			$args['maxuser'] = $configoptions['Maximum Users'];
			if ( isset($configoptions['Maximum Listeners']) )		$args['maxuser'] = $configoptions['Maximum Listeners'];
			if ( isset($configoptions['Maximum Viewers']) )			$args['maxuser'] = $configoptions['Maximum Viewers'];

			$args['maxuser']	= preg_replace("/[^0-9]/i",'', $args['maxuser']); # VALIDATION
			$args['maxuser']	= (empty($args['maxuser'])?9999:$args['maxuser']); # VALIDATION

			# Bitrate (expects 0-99999Kbps)
			if ( isset($configoptions['Bitrate']) )					$args['bitrate'] = $configoptions['Bitrate'];
			if ( isset($configoptions['Maximum Bitrate']) )			$args['bitrate'] = $configoptions['Maximum Bitrate'];

			$args['bitrate']	= preg_replace("/[^0-9]/i",'', $args['bitrate']);	# VALIDATION

			# Disk Usage (expects 0-9999MB/GB/TB)
			if ( isset($configoptions['Disk']) )					$args['quota'] = $configoptions['Disk'];
			if ( isset($configoptions['Quota']) )					$args['quota'] = $configoptions['Quota'];
			if ( isset($configoptions['Disk Usage']) )				$args['quota'] = $configoptions['Disk Usage'];
			if ( isset($configoptions['Disk Quota']) )				$args['quota'] = $configoptions['Disk Quota'];

			$args['quota']		= mediacp_ConvertUnitsToMegabyte($args['quota']); # VALIDATION

			# Bandwidth
			if ( isset($configoptions['Bandwidth']) )				$args['bandwidth'] = $configoptions['Bandwidth'];
			if ( isset($configoptions['Transfer']) )				$args['bandwidth'] = $configoptions['Transfer'];
			if ( isset($configoptions['Data Transfer']) )			$args['bandwidth'] = $configoptions['Data Transfer'];

			$args['bandwidth']	= mediacp_ConvertUnitsToMegabyte($args['bandwidth']); # VALIDATION

            # Stream Auth
            if ( isset($configoptions['Stream Auth']) )	             $args['streamauth'] = (strtolower($configoptions['Stream Auth'])=='yes'||$configoptions['Stream Auth']==1?'enabled':'disabled');
            if ( isset($configoptions['Stream Authentication']) )	 $args['streamauth'] = (strtolower($configoptions['Stream Authentication'])=='yes'||$configoptions['Stream Authentication']==1?'enabled':'disabled');
            if ( isset($configoptions['Listener Authentication']) )	 $args['streamauth'] = (strtolower($configoptions['Listener Authentication'])=='yes'||$configoptions['Listener Authentication']==1?'enabled':'disabled');


			if ( isset($configoptions['Geo Locking']) )				$args['customfields']['geolock'] = (strtolower($configoptions['Geo Locking'])=='yes'||$configoptions['Geo Locking']==1?1:0);
			if ( isset($configoptions['Country Locking']) )			$args['customfields']['geolock'] = (strtolower($configoptions['Country Locking'])=='yes'||$configoptions['Country Locking']==1?1:0);
			if ( isset($configoptions['Geolock']) )			    $args['customfields']['geolock'] = (strtolower($configoptions['Geolock'])=='yes'||$configoptions['Geolock']==1?1:0);

			# Historical Reporting
			if ( isset($configoptions['Reporting']) )				$args['customfields']['reporting'] = (strtolower($configoptions['Reporting'])=='yes'?'Enabled':'Disabled');
			if ( isset($configoptions['Historical Reporting']) )	$args['customfields']['reporting'] = (strtolower($configoptions['Historical Reporting'])=='yes'?'Enabled':'Disabled');
			if ( isset($configoptions['Advanced Reporting']) )		$args['customfields']['reporting'] = (strtolower($configoptions[' AdvancedReporting'])=='yes'?'Enabled':'Disabled');

			# ICES 0.4 SOURCE REENCODE
			if ( isset($configoptions['Source Reencode']) )			$args['customfields']['ices_reencode'] = $configoptions['Source Reencode'];
			if ( isset($configoptions['AutoDJ Reencode']) )			$args['customfields']['ices_reencode'] = $configoptions['AutoDJ Reencode'];

			# Wowza Service Type
			if ( isset($configoptions['Service Type']) )			$args['customfields']['servicetype'] = $configoptions['Service Type'];
			if ( isset($configoptions['Wowza Media Type']) )		$args['customfields']['servicetype'] = $configoptions['Wowza Media Type'];
			if ( isset($configoptions['Flash Media Service']) )		$args['customfields']['servicetype'] = $configoptions['Flash Media Service'];

			# Flussonic / Wowza Service Type -> Shoutcast Extension
			if ( $args['customfields']['servicetype'] == 'Shoutcast/Icecast Relay' ) $args['customfields']['servicetype'] = 'Shoutcast';

			if ( 	$args['plugin'] == 'WowzaMedia' &&
					isset($args['customfields']['servicetype']) &&
					$args['customfields']['servicetype'] != 'Live Streaming' &&
					$args['customfields']['servicetype'] != 'Live Streaming Low Latency' &&
					$args['customfields']['servicetype'] != 'TV Station' &&
					$args['customfields']['servicetype'] != 'Ondemand Streaming' &&
					$args['customfields']['servicetype'] != 'Shoutcast' &&
					$args['customfields']['servicetype'] != 'Live Camera Restream'
				){
				$args['customfields']['servicetype'] = 'Live Streaming';
			}
			if ( 	$args['plugin'] == 'Flussonic' &&
					isset($args['customfields']['servicetype']) &&
					$args['customfields']['servicetype'] != 'Live Streaming' &&
					$args['customfields']['servicetype'] != 'TV Station' &&
					$args['customfields']['servicetype'] != 'Ondemand Streaming'
				){
				$args['customfields']['servicetype'] = 'Live Streaming';
			}
			if ( 	$args['plugin'] == 'NginxRtmp' &&
					isset($args['customfields']['servicetype']) &&
					$args['customfields']['servicetype'] != 'Live Streaming' &&
			    		$args['customfields']['servicetype'] != 'TV Station' &&
					$args['customfields']['servicetype'] != 'Relay'
				){
				$args['customfields']['servicetype'] = 'Live Streaming';
			}

			# Wowza VHOST Configuration
			if ( isset($params['customfields']['Wowza VHost']) ){
				$args['customfields']['vhost'] = trim($params['customfields']['Wowza VHost']);
			}


			# Wowza Live Authentication - live_authentication
			if ( isset($configoptions['Live Authentication']) )		$args['customfields']['live_authentication'] = (strtolower($configoptions['Live Authentication'])=='yes'||$configoptions['Live Authentication']==1?'Enabled':'Disabled');

			# Wowza Transcoder Support
			if ( $args['plugin'] == 'WowzaMedia' && isset($configoptions['Transcoder']) )			$args['customfields']['wowza_transcoder'] = (strtolower($configoptions['Transcoder'])=='yes'||$configoptions['Transcoder']==1?'enabled':'disabled');
			if ( $args['plugin'] == 'WowzaMedia' && isset($configoptions['Transcoder Service']) )	$args['customfields']['wowza_transcoder'] = (strtolower($configoptions['Transcoder Service'])=='yes'||$configoptions['Transcoder Service']==1?'enabled':'disabled');
			if ( $args['plugin'] == 'WowzaMedia' && isset($configoptions['Transcoder Profiles']) )	$args['customfields']['transcoder_profiles'] = $configoptions['Transcoder Profiles'];

			# Wowza nDVR
			if ( $args['plugin'] == 'Flussonic' && isset($configoptions['nDVR']) )			$args['customfields']['ndvr'] = (strtolower($configoptions['nDVR'])=='yes'||$configoptions['nDVR']==1?'enabled':'disabled');
			if ( $args['plugin'] == 'WowzaMedia' && isset($configoptions['nDVR']) )			$args['customfields']['wowza_ndvr'] = (strtolower($configoptions['nDVR'])=='yes'||$configoptions['nDVR']==1?'enabled':'disabled');
			if ( $args['plugin'] == 'WowzaMedia' && isset($configoptions['nDVR AddOn']) )		$args['customfields']['wowza_ndvr'] = (strtolower($configoptions['nDVR AddOn'])=='yes'||$configoptions['nDVR AddOn']==1?'enabled':'disabled');
			if ( $args['plugin'] == 'WowzaMedia' && isset($configoptions['nDVR Playback']) )	$args['customfields']['wowza_ndvr'] = (strtolower($configoptions['nDVR Playback'])=='yes'||$configoptions['nDVR Playback']==1?'enabled':'disabled');

			# Wowza Live Stream Recording
			if ( $args['plugin'] == 'WowzaMedia' && isset($configoptions['Stream Recording']) )			$args['customfields']['wowza_record'] = (strtolower($configoptions['Stream Recording'])=='yes'||$configoptions['Stream Recording']==1?'enabled':'disabled');
			if ( $args['plugin'] == 'WowzaMedia' && isset($configoptions['Live Stream Recording']) )		$args['customfields']['wowza_record'] = (strtolower($configoptions['Live Stream Recording'])=='yes'||$configoptions['Live Stream Recording']==1?'enabled':'disabled');


			# RTMP / RTSP Enabled
			if ( isset($configoptions['RTMP']) )					$args['customfields']['rtmpenabled'] = (strtolower($configoptions['RTMP'])=='yes'||$configoptions['RTMP']==1?'yes':'no');
			if ( isset($configoptions['RTMP Support']) )			$args['customfields']['rtmpenabled'] = (strtolower($configoptions['RTMP Support'])=='yes'||$configoptions['RTMP Support']==1?'yes':'no');
			if ( isset($configoptions['RTMP Service']) )			$args['customfields']['rtmpenabled'] = (strtolower($configoptions['RTMP Service'])=='yes'||$configoptions['RTMP Service']==1?'yes':'no');

			/*
			 * Stream Targets
			 */
			$streamTargets = [];
			if ( isset($configoptions['Stream Publishing']) && strtolower($configoptions['Stream Publishing'])=='yes'||$configoptions['Stream Publishing']==1 )		$streamTargets = ['Facebook','Youtube','Twitch','Periscope','Icecast','Shoutcast','RTMP'];
			if ( isset($configoptions['Facebook Publishing']) && strtolower($configoptions['Facebook Publishing'])=='yes'||$configoptions['Facebook Publishing']==1 )		$streamTargets[] = 'Facebook';
			if ( isset($configoptions['Icecast Publishing']) && strtolower($configoptions['Icecast Publishing'])=='yes'||$configoptions['Icecast Publishing']==1 )		$streamTargets[] = 'Icecast';
			if ( isset($configoptions['Shoutcast Publishing']) && strtolower($configoptions['Shoutcast Publishing'])=='yes'||$configoptions['Shoutcast Publishing']==1 )		$streamTargets[] = 'Shoutcast';
			if ( isset($configoptions['Periscope Publishing']) && strtolower($configoptions['Periscope Publishing'])=='yes'||$configoptions['Periscope Publishing']==1 )		$streamTargets[] = 'Periscope';
			if ( isset($configoptions['Twitch Publishing']) && strtolower($configoptions['Twitch Publishing'])=='yes'||$configoptions['Twitch Publishing']==1 )		$streamTargets[] = 'Twitch';
			if ( isset($configoptions['Youtube Publishing']) && strtolower($configoptions['Youtube Publishing'])=='yes'||$configoptions['Youtube Publishing']==1 )		$streamTargets[] = 'Youtube';
			if ( isset($configoptions['RTMP Publishing']) && strtolower($configoptions['RTMP Publishing'])=='yes'||$configoptions['RTMP Publishing']==1 )		$streamTargets[] = 'RTMP';
			if ( count($streamTargets) > 0 ) $args['customfields']['streamtargets'] = $streamTargets;

			/*
			 * Stream Proxy
			 * server_proxy
			 */
            if ( isset($configoptions['Stream Proxy']) )					$args['proxy'] = (strtolower($configoptions['Stream Proxy'])=='yes'||$configoptions['Stream Proxy']==1?1:0);

		/** CUSTOM FIELDS **/

			# Publish Name
			if ( isset($params['customfields']['Publish Name']) )	$args['unique_id'] = $params['customfields']['Publish Name'];

			# Wowza -> Shoutcast Stream Name
			if ( isset($params['customfields']['Shoutcast Stream Name']) )		$args['customfields']['shoutcast_streamname'] = $params['customfields']['Shoutcast Stream Name'];
			if ( isset($params['customfields']['Stream Name']) )				$args['customfields']['shoutcast_streamname'] = $params['customfields']['Stream name'];

			# Wowza -> Restream URL / IPCAM / Shoutcast RESTREAM
			if ( isset($params['customfields']['Shoutcast URL']) )		$args['customfields']['shoutcast_address'] = $params['customfields']['Shoutcast URL'];
			if ( isset($params['customfields']['Icecast URL']) )		$args['customfields']['shoutcast_address'] = $params['customfields']['Icecast URL'];
			if ( isset($params['customfields']['Restream Address']) )	$args['customfields']['shoutcast_address'] = $params['customfields']['Restream Address'];
			if ( isset($params['customfields']['IPCAM URL']) )			$args['customfields']['shoutcast_address'] = $params['customfields']['IPCAM URL'];
			if ( isset($params['customfields']['IPCAM Address']) )		$args['customfields']['shoutcast_address'] = $params['customfields']['IPCAM Address'];


		return $args;
	}

	function mediacp_ConvertUnitsToMegabyte( $value ){
		if ( empty($value) ) return 0;
		if ( strtolower(trim($value)) == 'unlimited' ) return 999999;

		$numeric = preg_replace("/[^0-9]/i",'', $value);
		$unit = strtolower( trim( preg_replace("/[^a-z]/i",'', $value) ) );

		switch($unit){
			case 'kb':			return $numeric / 1024;			break;
			default: case 'mb':	return $numeric;				break;
			case 'gb':			return $numeric * 1024;			break;
			case 'tb':			return $numeric * 1024 * 1024;	break;
		}

		return $numeric;

	}

	function mediacp_generatePassword($length = 12)	{
		return mediacp_generateStrongPassword($length,false,'lud');
	}

	function mediacp_generateStrongPassword($length = 12, $add_dashes = false, $available_sets = 'luds')
	{
		$sets = array();
		if(strpos($available_sets, 'l') !== false)
			$sets[] = 'abcdefghjkmnpqrstuvwxyz';
		if(strpos($available_sets, 'u') !== false)
			$sets[] = 'ABCDEFGHJKMNPQRSTUVWXYZ';
		if(strpos($available_sets, 'd') !== false)
			$sets[] = '23456789';
		if(strpos($available_sets, 's') !== false)
			$sets[] = '!@$%*?';

		$all = '';
		$password = '';
		foreach($sets as $set)
		{
			$password .= $set[array_rand(str_split($set))];
			$all .= $set;
		}

		$all = str_split($all);
		for($i = 0; $i < $length - count($sets); $i++)
			$password .= $all[array_rand($all)];

		$password = str_shuffle($password);

		if(!$add_dashes)
			return $password;

		$dash_len = floor(sqrt($length));
		$dash_str = '';
		while(strlen($password) > $dash_len)
		{
			$dash_str .= substr($password, 0, $dash_len) . '-';
			$password = substr($password, $dash_len);
		}
		$dash_str .= $password;
		return $dash_str;
	}

	function mediacp_DetectUpgradeServiceID($params){

		# If domain is empty, service has not been provisioned
		if ( empty($params['domain']) ) return false;

		$ServiceData = mediacp_AdminServicesTabFieldsGet($params);


		if ( $ServiceData['CustomerID'] == '' || $ServiceData['CustomerID'] == 0 ){

			# Attempt to Locate & Update CustomerID
			$api = array(
				"path"		=> $params['serverhostname'],
				"rpc"		=> "admin.user_update",
				"args"		=> array(
								"auth"			=> $params['serveraccesshash'],
								"username"		=> $params['clientsdetails']['email']
				)
			);
			$return = mediacp_api( $api, $params );
			if ( isset($return['id']) && is_numeric($return['id']) ){
				mediacp_AdminServicesTabFieldsUpdate($params, array("CustomerID"=>$return['id']));
			}else{
				mediacp_AdminServicesTabFieldsUpdate($params, array("CustomerID"=>'COULD NOT DETERMINE'));
			}
		}

		if ( $ServiceData['ServiceID'] == '' || $ServiceData['ServiceID'] == 0 ){

			$api = array(
				"path"		=> $params['serverhostname'],
				"rpc"		=> "service.overview",
				"args"		=> array(
								"auth"			=> $params['serveraccesshash'],
								"unique_id"		=> $ServiceData['PublishName']
				)
			);

			if ( empty($ServiceData['PublishName']) ){
			    unset($api['args']['unique_id']);
                # Attempt to Locate & Update ServiceID based on domain param
			    $api['args']['portbase'] = @explode(':', $params['domain'])[1];
            }

			$return = mediacp_api( $api, $params );
			if ( isset($return['serverData']['id']) && is_numeric($return['serverData']['id']) ){
				mediacp_AdminServicesTabFieldsUpdate($params, array("ServiceID"=>$return['serverData']['id']));
				mediacp_AdminServicesTabFieldsUpdate($params, array("PublishName"=>$return['serverData']['unique_id']));
			}else{
				mediacp_AdminServicesTabFieldsUpdate($params, array("ServiceID"=>'0'));
			}

		}

	}

	function mediacp_DetectUpgradeDatabase(){

		$selectTBLPRODUCTS = full_query("SELECT * FROM tblproducts WHERE servertype='mediacp'");
		if ( mysql_num_rows($selectTBLPRODUCTS) > 1 ){
			$params = mysql_fetch_assoc($selectTBLPRODUCTS);

			# Detect if old module currently installed, migrate fields to new format
			if (
					strpos($params['configoption2'], 'http') !== FALSE &&
					($params['configoption18']=='Email' || $params['configoption18']=='WHMCS') &&
					($params['configoption19']=='disabled' || $params['configoption19']=='enabled') &&
					($params['configoption14']=='disabled' || $params['configoption14']=='enabled')
			){
				full_query("UPDATE tblproducts SET configoption8='Shoutcast Transcoder V1' WHERE configoption8='sctransv1' AND servertype='mediacp';");echo mysql_error();
				full_query("UPDATE tblproducts SET configoption8='Shoutcast Transcoder V2' WHERE configoption8='sctransv2' AND servertype='mediacp';");echo mysql_error();
				full_query("UPDATE tblproducts SET configoption8='Ices 0.4 (MP3)' WHERE configoption8='ices04' AND servertype='mediacp';");echo mysql_error();
				full_query("UPDATE tblproducts SET configoption8='Ices 2.0 (OGG)' WHERE configoption8='ices20' AND servertype='mediacp';");echo mysql_error();
				full_query("UPDATE tblproducts SET configoption8='Stream Transcoder V3' WHERE configoption8='streamtranscoderv3' AND servertype='mediacp';");echo mysql_error();
				full_query("UPDATE tblproducts SET
								configoption10=configoption3,
								configoption11=configoption8,
								configoption12=configoption5,
								configoption13=configoption4,
								configoption14=configoption6,
								configoption15=configoption7,
								configoption16=configoption9,
								configoption17=configoption15
							WHERE
								servertype='mediacp'");
				full_query("UPDATE tblproducts SET
								configoption1=configoption10,
								configoption2=configoption11,
								configoption3=configoption12,
								configoption7=configoption13,
								configoption4=configoption14,
								configoption5=configoption15,
								configoption6=configoption16,
								configoption8='Email',
								configoption9=configoption17,
								configoption10='',	configoption11='',configoption12='',configoption13='',configoption14='',configoption15='',configoption16='',configoption17='',configoption18='',configoption19='',
								configoption20='',configoption21='',configoption22='',configoption23='',configoption24=''
							WHERE
								servertype='mediacp';");
								echo mysql_error();

				mediacp_checkTableCreation();

				/** Migrate Passwords from existing accounts **/
				full_query("INSERT INTO `whmcs_mediacp` (customer_id, sharedpassword)
								SELECT customer_id, reference FROM whmcs_castcontrol
								WHERE NOT EXISTS
									(SELECT 1 FROM whmcs_mediacp as T2 WHERE whmcs_castcontrol.customer_id = T2.customer_id)");

			}
		}

	}

	function mediacp_checkTableCreation() {

		$result = full_query("show tables like 'whmcs_mediacp'");
		if ( mysql_num_rows($result)==0 ){

			$sql = ("CREATE TABLE IF NOT EXISTS `whmcs_mediacp` (".
						"  `customer_id` int(11) NOT NULL,".
						"  `sharedpassword` varchar(50) NOT NULL,".
						"  PRIMARY KEY  (`customer_id`)".
					") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
			full_query($sql);if ( $error = mysql_error() )	echo $sql.'::'.mysql_error();

		}

		$result = full_query("show tables like 'mod_mediacp_fields'");
		if ( mysql_num_rows($result)==0 ){

			$sql = ("CREATE TABLE IF NOT EXISTS `mod_mediacp_fields` (".
						"  `service_id` int(11) NOT NULL,".
						"  `CustomerID` int(6) NOT NULL,".
						"  `ServiceID` int(6) NOT NULL,".
						"  `PublishName` varchar(100) NOT NULL,".
						"  `ServiceLink` TEXT NOT NULL,".
						"  `RTMP` TEXT NOT NULL,".
						"  `RTSP` TEXT NOT NULL,".
						"  PRIMARY KEY  (`service_id`)".
					") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
			full_query($sql);if ( $error = mysql_error() )	echo $sql.'::'.mysql_error();

		}

		# Check and upgrade collation
        $result = full_query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_NAME = 'mod_mediacp_fields' AND TABLE_COLLATION = 'utf8mb4_unicode_ci'");
        if ( mysql_num_rows($result)==0 ) {
            full_query("ALTER TABLE mod_mediacp_fields CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        }
	}

	function mediacp_getClientPassword($customer_id){

		mediacp_checkTableCreation();

		if (!is_numeric($customer_id)) return false;

		$selectPassword = full_query("SELECT sharedpassword FROM whmcs_mediacp WHERE customer_id=".$customer_id);
		if ( mysql_num_rows($selectPassword) == 0 ){
			$sql = ("INSERT INTO whmcs_mediacp (customer_id, sharedpassword) VALUES({$customer_id}, '".mysql_real_escape_string(encrypt(mediacp_generatePassword()))."')");
			full_query($sql);if ( $error = mysql_error() )	echo $sql.'::'.mysql_error();

			$selectPassword = full_query("SELECT sharedpassword FROM whmcs_mediacp WHERE customer_id=".$customer_id);
		}
		$results = mysql_fetch_array($selectPassword);
		return decrypt($results['sharedpassword']);
	}

	function mediacp_updateClientPassword($customer_id, $password){
		$sql = ("UPDATE whmcs_mediacp SET sharedpassword='".mysql_real_escape_string(encrypt($password))."'");
		full_query($sql);if ( $error = mysql_error() )	echo $sql.'::'.mysql_error();
	}

	function mediacp_get_panel_url($params){

		$hostname = $params['serverhostname'];
		$port = $params['serverport'];
		$ssl = $params['serversecure'];

		return [
			'hostname' => $hostname,
			'port' => $port,
			'ssl' => $ssl,
			'url' => ($ssl?'https://':'http://') . $hostname . ':' . $port,
		];
	}

    function mediacp_ListAccounts(array $params)
    {
        $accounts = [];
        try {

            $serverid = $params['serverid'];
            $serverhostname = $params['serverhostname'];
            $serverip = $params['serverip'];
            $serverusername = $params['serverusername'];
            $serverpassword = $params['serverpassword'];
            $serveraccesshash = $params['serveraccesshash'];
            $serversecure = $params['serversecure'];

            # Run connection to retrieve usage for all domains/accounts on $serverid
            $api = array(
                "path"		=> $params['serverhostname'],
                "rpc"		=> "admin.service_list",
                "args"		=> array("auth"			=> $params['serveraccesshash'])
            );
            $return = mediacp_api( $api, $params );

            if ( @$return['status'] == 'failed' && strpos(@$return['error'],'requested method admin.service_list does not exist') !== -1 ){
                return [
                    'success'  => false, // Boolean value
                    'error' => "Not supported with your version of MediaCP. Please upgrade MediaCP to version 2.9.11, 2.10.7 or newer.",
                ];
            }

            if ( @$return['status'] !== 'success' ){
                return 'ERROR: '.print_r($return,true);
            }

            // Call the remote api to obtain the list of accounts. Use the values provided by
            // WHMCS in `$params`. (https://developers.whmcs.com/provisioning-modules/module-parameters/)
            //$data = mymodule_call($params, 'listaccounts');

            foreach ($return['data'] as $account) {
                #var_dump($account);exit;
                $accounts[] = [
                    // The remote accounts email address
                    'email' => $account['user']['user_email'],
                    // The remote accounts username
                    'username' => $account['user']['username'],
                    // The remote accounts primary domain name
                    'domain' => "{$params['serverhostname']}:{$account['portbase']}",
                    // This can be one of the above fields or something different.
                    // In this example, the unique identifier is the domain name
                    //'uniqueIdentifier' => "{$account['slug']}",
                    'uniqueIdentifier' => "{$params['serverhostname']}:{$account['portbase']}",
                    // The accounts package on the remote server
                    'product' => "default",
                    // The remote accounts primary IP Address
                    'primaryip' => '1.2.3.4',
                    // The remote accounts creation date (Format: Y-m-d H:i:s)
                    'created' => date("Y-m-d H:i:s"),
                    // The remote accounts status (Status::ACTIVE or Status::SUSPENDED)
                    'status' => 'Active',
                ];
            }
            #var_dump($accounts);exit;
            // When returning the accounts, ensure that you return a success as a boolean
            // and accounts as an array.
            return [
                'success'  => true, // Boolean value
                'accounts' => $accounts,
            ];
        } catch (Exception $e) {
            return [
                'success'  => false, // Boolean value
                'error' => $e->getMessage(),
            ];
        }
    }

	function mediacp_api( $api, $params )	{

		$panelUrl = mediacp_get_panel_url($params);


		# RECORD PREREQUEST
		logModuleCall('mediacp_'.date("Ymd", filemtime(__FILE__)),$api['rpc'].'_prerequest',$api,'','');

		# CHECK API KEY IS ENTERED CORRECTLY
		if ( strlen($api['args']['auth']) < 24 ) {
			logModuleCall('mediacp_'.date ("Ymd.", filemtime(__FILE__)),$api['rpc'] . '_apicheck',$api, '', "The provided API key for the server is not valid",'');
			return array('status'=>'failed', 'error'=>"The provided API key for the server is not valid. Refer to https://www.mediacp.net/documentation/whmcs-integration-guide/");
		}

		# SSL Support
		$method = $panelUrl['ssl'] ? "IXR_ClientSSL" : "IXR_Client";

		$client = new $method($panelUrl['hostname'],'/system/rpc.php',$panelUrl['port'],10);
		$SubmitRequest = $client->query($api['rpc'], $api['args']);

		# Debugging
		if ( !$SubmitRequest ) {
			if ( $client->getErrorMessage() == 'transport error - HTTP status was redirect' ){
				logModuleCall('mediacp_'.date ("Ymd.", filemtime(__FILE__)),$api['rpc'],$api, var_export($SubmitRequest, true), "There is a license issue with your MediaCP license at this address of {$api['path']}",'');
				return array('status'=>'failed', 'error'=>"There is a license issue with your MediaCP license at this address of {$api['path']}");
			}
			$errorcode = $client->getErrorCode();
			$errormessage = $client->getErrorMessage();
			logModuleCall('mediacp_'.date ("Ymd.", filemtime(__FILE__)),$api['rpc'],$api, var_export($SubmitRequest, true), $errorcode.':'.$errormessage);
			return array('status'=>'failed', 'error'=>$errormessage);
		}

		$response = $client->getResponse();
		logModuleCall('mediacp_'.date ("Ymd.", filemtime(__FILE__)),$api['rpc'],$api, var_export($SubmitRequest, true), $response, $client->debugContents);

		return $response;
	}
