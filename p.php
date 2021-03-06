<?php

$bootstrap_settings['freepbx_auth'] = false;
if (!@include_once(getenv('FREEPBX_CONF') ? getenv('FREEPBX_CONF') : '/etc/freepbx.conf')) {
    include_once('/etc/asterisk/freepbx.conf');
}
include('includes/webprov.php');

# HiPBX Stuff.
$provis_ip = '192.168.1.5';
$asterisk_ip = '192.168.1.5';
if (file_exists('/etc/hipbx.d/hipbx.conf')) {
        $hipbx = @parse_ini_file('/etc/hipbx.d/hipbx.conf', false, INI_SCANNER_RAW);
	$provis_ip=$hipbx['http_IP'];
	$asterisk_ip=$hipbx['asterisk_IP'];
}

if (file_exists('/etc/hipbx.d/provis.conf')) {
        $provis_conf = @parse_ini_file('/etc/hipbx.d/provis.conf', false, INI_SCANNER_RAW);
	$ldap_enabled=true;
	# Check all required LDAP variables are present
	$ldap_vars = array('LDAPDIRNAME', 'LDAPHOST', 'LDAPPORT', 'DSN', 'LDAPUSER', 'LDAPPASS', 'SEARCHBASE');
	# Note that 'LDAPMAP' is optional.
	foreach ($ldap_vars as $varname) {
		if (!isset($provis_conf[$varname])) {
			$ldap_enabled=false;
			break;
		}
	}
}

# Multihoming support:
# Assume(!) that the last two octets of asterisk_IP are the same on all
# networks. So when we're telling the phone what SIP Server to connect to,
# take the first two octets of the IP address the phone has connected to,
# and then add the last two from asterisk_ip

$myip = preg_split('/\./', $_SERVER['SERVER_ADDR']);
$astip = preg_split('/\./', $asterisk_ip);

$realip = $myip[0].".".$myip[1].".".$astip[2].".".$astip[3];

	
$prov = new webprov();

//Load Provisioner Library stuff
define('PROVISIONER_BASE', $prov->path.'/provisioner/');

# Workaround for SPAs that don't actually request their type of device
# Assume they're 504G's. Faulty in firmware 7.4.3a
$filename = basename($_SERVER["REQUEST_URI"]);
$web_path = 'http://'.$_SERVER["SERVER_NAME"].dirname($_SERVER["PHP_SELF"]).'/';
if ($filename == "p.php") { 
	$filename = "spa502G.cfg";
	$_SERVER['REQUEST_URI']=$_SERVER['REQUEST_URI']."/spa502G.cfg";
	$web_path = $web_path."p.php/";
}

# Upgrade 7.4 to 7.5
# This neesd to be a lot more portable.
if (preg_match('/(7.4)/', $_SERVER['HTTP_USER_AGENT'])) {
	$str = '<flat-profile><Upgrade_Enable group="Provisioning/Firmware_Upgrade">Yes</Upgrade_Enable>';
	$str .= '<Upgrade_Rule group="Provisioning/Firmware_Upgrade">http://'.$provis_ip.'/spa-firmware/7.5.1a/spa50x-30x-7-5-1a.bin</Upgrade_Rule></flat-profile>';
	echo $str;
	exit;
}


$strip = str_replace('spa', '', $filename);
if(preg_match('/[0-9A-Fa-f]{12}/i', $strip, $matches) && !(preg_match('/[0]{10}[0-9]{2}/i',$strip))) {
        require_once(PROVISIONER_BASE.'autoload.php');
	$mac = $matches[0];
        //Now search for this mac in the table :-)
        
        $sql = "SELECT * FROM simple_endpointman_mac_list WHERE mac = '". $mac."'";
        $res = $db->query($sql);
        if($res->numRows() > 0) {
            

            $phone_info = $prov->get_device_info($mac);
            //print_r($phone_info);

            $sql = "SELECT simple_endpointman_line_list.*, sip.data as secret, devices.*, simple_endpointman_line_list.description AS epm_description FROM simple_endpointman_line_list, sip, devices WHERE simple_endpointman_line_list.ext = devices.id AND simple_endpointman_line_list.ext = sip.id AND sip.keyword = 'secret' AND simple_endpointman_line_list.mac_id = ".$phone_info['id']." ORDER BY simple_endpointman_line_list.line ASC";
            $lines_info = $db->getAll($sql, array(), DB_FETCHMODE_ASSOC);
            foreach($lines_info as $line) {
                $phone_info['line'][$line['line']] = $line;
                $phone_info['line'][$line['line']]['description'] = $line['epm_description'];
            }
            
            $brand = $phone_info['brand'];
            $family = $phone_info['product'];
            $model = $phone_info['model'];
            
            //Load Provisioner
            $class = "endpoint_" . $brand . "_" . $family . '_phone';
            $base_class = "endpoint_" . $brand. '_base';
            $master_class = "endpoint_base";

            if(!class_exists($master_class)) {
                ProvisionerConfig::endpointsAutoload($master_class);
            }
            if(!class_exists($base_class)) {
                ProvisionerConfig::endpointsAutoload($base_class);
            }
            if(!class_exists($class)) {
                ProvisionerConfig::endpointsAutoload($class);
            }

            if(!class_exists($class)) { die('Unable to load class: '. $class); }
            
            $endpoint = new $class();

            $endpoint->server_type = 'dynamic';		//Can be file or dynamic
            $endpoint->provisioning_type = 'http';

            //have to because of versions less than php5.3
            $endpoint->brand_name = $brand;
            $endpoint->family_line = $family;

            $endpoint->processor_info = "Web Provisioner 2.0";

            //Mac Address
            $endpoint->mac = $mac;

            //Phone Model (Please reference family_data.xml in the family directory for a list of recognized models)
            $endpoint->model = $model;
            
            	//Timezone
            if (!class_exists("DateTimeZone")) { require(PROVISIONER_BASE.'samples/tz.php'); }
            $endpoint->DateTimeZone = new DateTimeZone('Australia/Brisbane');

            //Server IP Address & Port
            $endpoint->server[1]['ip'] = $realip;
            $endpoint->server[1]['port'] = 5060;

            //$endpoint->proxy[1]['ip'] = $data['data']['statics']['proxyserver'];
            //$endpoint->proxy[1]['port'] = 5060;

            $endpoint->provisioning_path = $provis_ip.dirname($_SERVER['REQUEST_URI']);

	    # Get any specific params for the device
	    $devp = $prov->get_data($mac, 'settings', 'mac'); // defaults to 'custom', and 'mac'


            //Loop through Lines!
            foreach($phone_info['line'] as $line) {
                $endpoint->lines[$line['line']] = array(
			'ext' => $line['ext'], 
			'secret' => $line['secret'],
		);
		# Don't duplicate the phone's name on the extension line.
		if ($line['description'] === $devp['displayname']) {
			$endpoint->lines[$line['line']]['displayname'] = $line['ext'];
		} else {
			$endpoint->lines[$line['line']]['displayname'] = checkname($line['description']);
		}
            }
            $static_options =  array(
		'dial_plan'=>'(#2|*4xxx|**xxx|*[75]2x.|*90x.|*80xxx|*8[234]xxx|*xx|*5|[1-9]xx|0000|0112|0[23457]xxxxxxx|00[23478]xxxxxxxx|011xx|012[238]x|012x.|01300xxxxxx|013[1-9]xxx|01800xxxxxx|018xxxx|00011x.|0190)',
		'background_type' => 'BMP Picture',
		'logo_type' => 'BMP Picture',
		'picture_url' => "http://$provis_ip/logo.bmp",
		'enable_webserver' => 'Yes',
		'enable_webserver_admin' => 'Yes',
		'station_name' => checkname($devp['displayname']),
		'date_format' => 'day/month',
		'ring1' => 'n=External;w=3;c=9',
		'ring2' => 'n=Internal;w=3;c=1',
		'ring3' => 'n=24;w=4;c=1',
		'ring4' => 'n=Alt-1;w=1;c=1',
		'ring5' => 'n=Alt-2;w=2;c=1',
		'ring6' => '',
		'ring7' => '',
		'ring8' => '',
		'ring9' => '',
		'ring10' => '',
		'dial_tone' => '400@-16,425@-16,450@-16;10(*/0/1+2+3)',
		'mwi_tone' => '400@-16,425@-16,450@-16;2(.1/.1/1+2+3);10(*/0/1+2+3)',
            );

	    if($ldap_enabled) {
		# LDAP settings have been supplied. Add them to the phone.
		$ldap_options = array(
			'ldap_enabled' => 'Yes',
			'ldap_name' => $provis_conf['LDAPDIRNAME'],
			'ldap_server' => $provis_conf['LDAPHOST'],
			'ldap_account' => $provis_conf['LDAPUSER'],
			'ldap_password' => $provis_conf['LDAPPASS'],
			'ldap_base' => $provis_conf['SEARCHBASE'],
		);
		if (isset($provis_conf['LDAPMAP'])) {
			$ldap_options['ldap_mapping']=$provis_conf['LDAPMAP'];
		}
		if (isset($provis_conf['LDAPATTRS'])) {
			$ldap_options['ldap_attrs']=urldecode($provis_conf['LDAPATTRS']);
		}
		$static_options = array_merge($static_options, $ldap_options);
	    }
            
            if(!empty($phone_info['global_custom_cfg_data']['data'])) {
                $options = array_merge($static_options,$phone_info['global_custom_cfg_data']['data']);
            } else {
                $options = $static_options;
            }
            
            if(!empty($phone_info['global_user_cfg_data']['data'])) {
                $options_final = array_merge($options,$phone_info['global_user_cfg_data']['data']);
            } else {
                $options_final = $options;
            }
                        
            $endpoint->options = $options_final;
            
            $returned_data = $endpoint->generate_config();
                        
            ksort($returned_data);

            if(array_key_exists($filename, $returned_data)) {
                echo $returned_data[$filename];
            } else {
                header("HTTP/1.0 404 Not Found");
            }
        } else {
            header("HTTP/1.0 404 Not Found");
        }

} else {
    require_once (PROVISIONER_BASE.'endpoint/base.php');
    $data = Provisioner_Globals::dynamic_global_files($filename,dirname($prov->path).'/fake_tftpboot/',$web_path);
    if($data !== FALSE) {
        echo $data;
    } else {
        header("HTTP/1.0 404 Not Found");
    }
}

function checkname($name) {
	# Figure out if a name is too long.
	if (strlen($name) <= 12) {
		# Well, that was easy.
		return $name;
	} 
	# It's too long. Damn. Lets try to preserve as much info as possible.
	list($fn, $ln) = explode(' ', $name, 2);
	$lfn = strlen($fn);
	$lln = strlen($ln);
	# Can we get away with just shortening the first name?
	if ($lln + 2 <= 12) {
		# We can. Good. Lets do that.
		return substr($fn, 0, 1)." $ln";
	}
	# Hmm. Ok. How about just the second name?
	if ($lfn + 2 <= 12) {
		return "$fn ".substr($ln, 0, 1);
	}
	# Both the first name AND the Last name are longer than 10 chars.
	# Can we have JUST the last name or JUST the first name?
	if ($lln <= 12) {
		return $ln;
	} elseif ($lfn <= 12) {
		return $fn;
	}
	# You're just being difficult.  First initial, plus the first 8 chars of
	# the last name, plus '..' 
	return substr($fn, 0, 1)." ".substr($ln, 0, 8)."..";
}
