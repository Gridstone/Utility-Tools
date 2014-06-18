<?php
	
	// ----------------------------------------------
	/**
	 * AwsService class. for common methods
	 * 
	 * @author RIPE
	 *
	 */
	 
	class AwsService
	{
		
		// -----------------------------------------------------
		// iVars
		
		var $script_version_no = '';
		var $attempt = 4;
		
		// -----------------------------------------------------
		
		/**
		 * __construct function.
		 * 
		 * @access public
		 * @return void
		 */
		function __construct()
		{
			// Create report attempt disrectory.
			if(!file_exists($_SERVER['DOCUMENT_ROOT']."/report/".$this->attempt))
			{
				mkdir($_SERVER['DOCUMENT_ROOT']."/report/".$this->attempt);
			}
		}
		
		// -----------------------------------------------------

		/**
		 * validTagArray function.
		 * 
		 * @access public
		 * @param array $tags (default: array())
		 * @return void
		 */
		function validTagArray($tags = array())
		{
			// Make validated tag array
			$validtags = array();
			
			for($i=0; $i < count($tags); $i++)
			{
				$temp_tag = array();
				
				$temp_tag["Key"]   = $tags[$i]["Key"];
				$temp_tag["Value"] = is_array($tags[$i]["Value"]) ? '' : $tags[$i]["Value"];
				
				$validtags[] = $temp_tag;
			}
			
			$temp_tag = array();
				
			$temp_tag["Key"]   = "Created";
			$temp_tag["Value"] = "Script Version: ".$this->script_version_no." used on ".date("Y-m-d H:i:s");
			
			$validtags[] = $temp_tag;
			
			return $validtags;
		}
		
		// ------------------------------------------------------
		
		function validIngressEgressArray($ingressRules, $securityGroupAssoc, $accId)
		{
			$arr = array();
			
			for($i=0; $i < count($ingressRules); $i++){
				$keys = array_keys($ingressRules[$i]);
				$values = array_values($ingressRules[$i]);
				$temp_rule = array();
				for($j=0; $j<count($keys); $j++){
					$key = $keys[$j];
					$value = $values[$j];
					if(is_array($value)){
						if(!empty($value)){
							if($key == 'UserIdGroupPairs') {
								$newValue = array();
								for($m=0; $m<count($value); $m++) {
									$newValue[] = array( 'UserId' => $accId, 
									'GroupId' => $securityGroupAssoc[$value[$m]['GroupId']] );
								}
								$temp_rule[$key] = $newValue;
							} else if($key == 'IpRanges') {
								$newValue = array();
								foreach($value as $iprange)
								{
									if($iprange['CidrIp'] != '0.0.0.0/0')
									{
										$newValue[] = $iprange;
									}
								}
								$temp_rule[$key] = $newValue;
							} else {
								$temp_rule[$key] = $value;
							}
						}
					}else{
						$temp_rule[$key] = $value;
					}
				}
				$arr[] = $temp_rule;				
			}
			
			return $arr;
			
		}
		
		// ------------------------------------------------------
		
		function generateRandomString($length = 10) 
		{
		    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		    $randomString = '';
		    for ($i = 0; $i < $length; $i++) {
		        $randomString .= $characters[rand(0, strlen($characters) - 1)];
		    }
		    return $randomString;
		}
		
		// -------------------------------------------------------
		
		function report($data)
		{
			file_put_contents($_SESSION['DOCUMENT_ROOT']."report/".$this->attempt."/source-".date("d-m-Y\TH:m:s\Z").".log", "Will be copied from source: ".print_r($data, true)."\n \n \n", FILE_APPEND);
		}

	}

?>