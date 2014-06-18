<?php
	
	require_once 'aws/vendor/autoload.php';
	require_once 'libs/kLogger/src/KLogger.php';
	require_once 'app/AwsService.php';
	
	use Aws\Ec2\Ec2Client;
	
	/**
	 * VPC class. Contains logic for VPC replication.
	 * During VPC replication it also creates,
	 * - Routing Tables
	 * - Internet Gateways
	 * - Security Groups
	 * - Subnets
	 *
	 *	Note: This script requires all the instance pem key be available inside,
	 *		  __DIR__/../keypairs/allkeys.pem
	 * 
	 * @author RIPE
	 *
	 * @extends AwsService
	 */
	class VPC extends AwsService {
		
		// AWS classes
		var $source_ec2_client      = '';
		var $destination_ec2_client = '';
		var $source_region = '';
		var $dest_region = '';
		var $destination_acc_id = '';
		
		// Log
		var $log = '';
		
		// -----------------------------------------------------
		
 		/**
 		 * __construct function.
 		 * 
 		 * @access public
 		 * @return void
 		 */
 		function __construct()
		{
			parent::__construct();
			
			// Sanity Check!
			if(!file_exists($_SERVER['DOCUMENT_ROOT']."/keypairs"))
			{
				mkdir($_SERVER['DOCUMENT_ROOT']."/keypairs");
			}
			
			$this->log = KLogger::instance(dirname(__FILE__)."/log", KLogger::DEBUG);
			
			$this->log->logInfo("Initialising source and destination ec2client.");
			
			$creds = json_decode(file_get_contents("credentials/cred.json"), true);
			
			try
			{
				// Source Init
				$this->source_ec2_client = Ec2Client::factory(array(
				    'key'    => $creds["source"]["access_key"],
				    'secret' => $creds["source"]["secret_key"],
				    'region' => $creds["source"]["region"]
				));
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
			
			try
			{
				// Source Destination
				$this->destination_ec2_client = Ec2Client::factory(array(
				    'key'    => $creds["destination"]["access_key"],
				    'secret' => $creds["destination"]["secret_key"],
				    'region' => $creds["destination"]["region"]
				));
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
			
			$this->log->logInfo("Source and Destination Client ");
			
			$this->source_region = $creds["source"]["region"];
			$this->dest_region = $creds["destination"]["region"];
			$this->destination_acc_id = $creds["accountId"]["id"];
			
			$this->script_version_no = $creds["scriptVersion"]["no"];
			
			$this->log->logInfo("Replication from ".$this->source_region." to ".$this->dest_region." has been initialised!");
		}
		
		// -----------------------------------------------------
		
		/**
		 * checkIfRequiredKeysAreAvailableAndCreatePublicKeysFromIt function.
		 * 
		 * @access public
		 * @return void
		 */
		function checkIfRequiredKeysAreAvailableAndCreatePublicKeysFromIt()
		{
			// -----------------------------------------------------
			
			$data = array();
			$data['keyCheck'] = true;
			$data['missingKeys'] = array();
			
			// -----------------------------------------------------
			// First get all the current keynames from source region.
			try
			{
				$result    = $this->source_ec2_client->describeKeyPairs(array());
				$dresult   = $this->destination_ec2_client->describeKeyPairs(array());
				
				$skeypairs  = $result->get('KeyPairs');
				
				$this->report($skeypairs);
				
				$dkeypairs  = $dresult->get('KeyPairs');
				
				$destinationKeyPairs = array();
				foreach($dkeypairs as $dkeypair) {
					$destinationKeyPairs[] = $dkeypair['KeyName'];
				}
				
				$sourceKeyPairs = array();
				foreach($skeypairs as $skeypair) {
					$sourceKeyPairs[] = $skeypair['KeyName'];
				}
				
				$destinationKeyPairs = array_unique($destinationKeyPairs);
				$sourceKeyPairs = array_unique($sourceKeyPairs);
				
				foreach($sourceKeyPairs as $skey)
				{
					if(!in_array($skey, $destinationKeyPairs))
					{
						$privateKey = $_SERVER['DOCUMENT_ROOT']."/keypairs/".$skey.".pem";
						$publicKey  = $_SERVER['DOCUMENT_ROOT']."/keypairs/".$skey.".pub";
						
						if(!file_exists($privateKey)){
							$data['missingKeys'][] = $skey;
							$data['keyCheck'] = false;
						} else {
							// Created Public key
							chmod($privateKey, 0600);
							exec("ssh-keygen -y -f ".$privateKey." > ".$publicKey);
							$publicKeyMaterial = file_get_contents($publicKey);
							if(strlen($publicKeyMaterial) > 0) {
								$ckey = $this->destination_ec2_client->importKeyPair(array(
									'KeyName' => $skey,
									'PublicKeyMaterial' => $publicKeyMaterial
								));
								$this->log->logInfo("Key Imported: ".$ckey->get('KeyName')." With Finger Print: ".$ckey->get('KeyFingerprint'));
							} else {
								$this->log->logInfo("Invalid Public key generated: ".$publicKey);
								$data['missingKeys'][] = $skey;
								$data['keyCheck'] = false;
							}
						}
					}
					else
					{
						$this->log->logInfo("KeyPair: ".$skey." Already exists at destination region!");
					}
				}
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
			}
			
			return $data;
		}
		
		// -----------------------------------------------------
		
		/**
		 * replicateVpcs function.
		 * 
		 * @access public
		 * @return void
		 */
		function replicateVpcs()
		{
			$this->log->logInfo("Replication of VPCs started.");
			
			$assoc = array();
			
			try
			{
				$result = $this->source_ec2_client->describeVpcs(array());
				$vpcs = $result->get("Vpcs");
				
				$this->report($vpcs);
				
				$this->log->logInfo("Number of VPCs in Source Region: ".count($vpcs));
				
				for($i = 0; $i < count($vpcs); $i++) 
				{
					$vpc = $this->destination_ec2_client->createVpc(array(
					    'CidrBlock' => $vpcs[$i]["CidrBlock"],
					    'InstanceTenancy' => $vpcs[$i]["InstanceTenancy"],
					));
					
					$cvpc = $vpc->get("Vpc");
					
					if(isset($vpcs[$i]["Tags"]))
					{
						// Make validated tag array
						$tags = $this->validTagArray($vpcs[$i]["Tags"]);
						
						// Set tag
						$this->destination_ec2_client->createTags(array(
							"Resources" => array($cvpc["VpcId"]),
							"Tags" => $tags
						));
					}
					
					// Association for late use.
					$assoc[$vpcs[$i]["VpcId"]] = $cvpc["VpcId"];
					$this->log->logInfo("VPC ".$cvpc["VpcId"]." created as a replica of source VPC ".$vpcs[$i]["VpcId"]);
				}
			 } 
			 catch(Exception $ex) 
			 {
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			 }
			 
			 $this->log->logInfo("Replication of VPCs finished.");
			 
			 return $assoc;
		}
		
		// -----------------------------------------------------
		
		/**
		 * replicateSecurityGroups function.
		 * 
		 * @access public
		 * @param mixed $vpc_id_assoc
		 * @return void
		 */
		function replicateSecurityGroups($vpc_id_assoc)
		{
			$this->log->logInfo("Replication of SecurityGroups started.");
			
			$securityGroupAssoc = array();
			$securityIngress = array();
			$securityEgress = array();
			
			try
			{
				$result = $this->source_ec2_client->describeSecurityGroups(array());
				$securityGroups = $result->get('SecurityGroups');
				
				$this->report($securityGroups);
				
				$this->log->logInfo("Number of SecurityGroups in Source Region: ".count($securityGroups));
				
				for($i=0; $i<count($securityGroups); $i++)
				{
					if($securityGroups[$i]['GroupName'] != 'default')
					{
						$newSecurityGroup = $this->destination_ec2_client->createSecurityGroup(array(
						    'GroupName' => $securityGroups[$i]['GroupName'],
						    'Description' => $securityGroups[$i]['Description'],
						    'VpcId' => $vpc_id_assoc[$securityGroups[$i]['VpcId']],
						));
						
						$newGroupId = $newSecurityGroup->get('GroupId');
						
						// Create Tags,
						$tags = $this->validTagArray($securityGroups[$i]['Tags']);
						
						$this->destination_ec2_client->createTags(array(
							'Resources' => array($newGroupId),
							'Tags' => $tags
						));
						
						// Setup Ingress and Egress Rules.
						$this->log->logInfo("Collecting Ingress and Egress for future modifications for - ".$newGroupId);
						$ingressRules = $securityGroups[$i]['IpPermissions'];
						$egressRules = $securityGroups[$i]['IpPermissionsEgress'];
						
						$this->log->logInfo("Collection finished!");
						$securityGroupAssoc[$securityGroups[$i]['GroupId']] = $newGroupId;
						$securityIngress[$newGroupId] = $ingressRules;
						$securityEgress[$newGroupId] = $egressRules;
						
						$this->log->logInfo("SecurityGroup ".$newSecurityGroup->get('GroupId')." created as a replica of source SecurityGroup ".$securityGroups[$i]['GroupId']);
					}
				}
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: CreateSecurityGroup: ".$ex->getMessage());
			}
			
			$this->log->logInfo("Will start to authorise security groups!");
			
			try
			{
				$result = $this->destination_ec2_client->describeSecurityGroups(array());
				$securityGroups = $result->get('SecurityGroups');
				
				for($j=0; $j<count($securityGroups); $j++)
				{
					if($securityGroups[$j]['GroupName'] != 'default')
					{
						$newIngressRules = $this->validIngressEgressArray($securityIngress[$securityGroups[$j]['GroupId']], 
						                                            $securityGroupAssoc, $this->destination_acc_id);
						$newEgressRule = $this->validIngressEgressArray($securityEgress[$securityGroups[$j]['GroupId']],
						                                                $securityGroupAssoc, $this->destination_acc_id);
                        if(!empty($newIngressRules))
                        { 
							$ingressResult = $this->destination_ec2_client->authorizeSecurityGroupIngress(array(
								'GroupId' => $securityGroups[$j]['GroupId'],
								'IpPermissions' => $newIngressRules
							));
							
							$this->log->logInfo("Ingress rules added for - ".$securityGroups[$j]['GroupId']);
						}
						
						if(!empty($newEgressRule))
						{
							$egressResult = $this->destination_ec2_client->authorizeSecurityGroupEgress(array(
								'GroupId' => $securityGroups[$j]['GroupId'],
								'IpPermissions' => $newEgressRule
							));
							
							$this->log->logInfo("Egress rules added for - ".$securityGroups[$j]['GroupId']);
						}
					}
				}
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
			}
				
			$this->log->logInfo("Replication of SecurityGroups finished.");
			
			return $securityGroupAssoc;
		}
		
		// -----------------------------------------------------
		
		/**
		 * replicateSubnets function.
		 * 
		 * @access public
		 * @param array $vpc_id_assoc (default: array())
		 * @return void
		 */
		function replicateSubnets($vpc_id_assoc = array())
		{
			$this->log->logInfo("Replication of Subnets started.");
			
			$assoc = array();
			
			try
			{
				$result = $this->source_ec2_client->describeSubnets(array());
				$subnets = $result->get('Subnets');
				
				$this->report($subnets);
				
				$this->log->logInfo("Number of Subnets in Source Region: ".count($subnets));
				
				// Get total number of availibility zones used.
				$zone_used = array();
				for($a = 0; $a < count($subnets); $a++) {
					$zone_used[] = $subnets[$a]['AvailabilityZone'];
				}
				$zone_used = array_values(array_unique($zone_used));
				
				$zoneResults = $this->destination_ec2_client->describeAvailabilityZones(array());
				$zones       = $zoneResults->get('AvailabilityZones');
				
				$zone_map = array();
				for($z=0; $z < count($zone_used); $z++)
				{
					if( strtolower($zones[$z]['State']) == 'available') {
						$zone_map[$zone_used[$z]] = isset($zones[$z]['ZoneName']) ? $zones[$z]['ZoneName'] : '';
					}
				}
				
				$this->log->logInfo("Availability Zone map calculated, Final out is,", $zone_map);
				
				for($i = 0; $i < count($subnets); $i++)
				{
					$subnet = $this->destination_ec2_client->createSubnet(array(
					    'VpcId' => $vpc_id_assoc[$subnets[$i]['VpcId']],
					    'CidrBlock' => $subnets[$i]['CidrBlock'],
					    'AvailabilityZone' => $zone_map[$subnets[$i]['AvailabilityZone']],
					));
					
					$csubnet = $subnet->get('Subnet');
					
					if(isset($subnets[$i]['Tags']))
					{
						// Make Validated tag array
						$tags = $this->validTagArray($subnets[$i]['Tags']);
						
						// Set tag
						$this->destination_ec2_client->createTags(array(
							"Resources" => array($csubnet["SubnetId"]),
							"Tags" => $tags
						));
					}
					
					// Association for late use.
					$assoc[$subnets[$i]["SubnetId"]] = $csubnet["SubnetId"];
					$this->log->logInfo("Subnet ".$csubnet["SubnetId"]." created as a replica of source Subnet ".$subnets[$i]["SubnetId"]);
					
				}
				
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
			
			$this->log->logInfo("Replication of Subnets finished.");
			
			return $assoc;
		}
		
		// -----------------------------------------------------------------------
		
		/**
		 * replicateInternetGateways function.
		 * 
		 * @access public
		 * @param mixed $vpc_id_assoc
		 * @return void
		 */
		function replicateInternetGateways($vpc_id_assoc)
		{
			$this->log->logInfo("Replication of InternetGateways started.");
			
			$gatewayAssoc = array();
			
			try
			{
				$result = $this->source_ec2_client->describeInternetGateways(array());
				$sourceIGs =  $result->get('InternetGateways');
				
				$this->report($sourceIGs);
				
				for($i=0; $i<count($sourceIGs); $i++)
				{
					$result = $this->destination_ec2_client->createInternetGateway(array());
					$destinationIG = $result->get('InternetGateway');
					
					
					
					$igID = $destinationIG['InternetGatewayId'];
					
					foreach($sourceIGs[$i]['Attachments'] as $attachment)
					{
						// Create Attachments  
						$this->destination_ec2_client->attachInternetGateway(array(
							'InternetGatewayId' => $igID,
							'VpcId' => $vpc_id_assoc[$attachment['VpcId']]
						));
					}
					
					$tags = array();
					foreach($sourceIGs[$i]['Tags'] as $tag)
					{
						$tags[] = array(
							'Key' => $tag['Key'],
							'Value' =>  $tag['Value']
						);
					}
					
					$tags = $this->validTagArray($tags);
					
					// Create Attachments  
					$this->destination_ec2_client->createTags(array(
						'Resources' => array($igID),
						'Tags' => $tags
					));
					
					$gatewayAssoc[$sourceIGs[$i]['InternetGatewayId']]= $igID;
					$this->log->logInfo("IG ".$igID." created as a replica of source IG ".$sourceIGs[$i]['InternetGatewayId']);
				}
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
			
			$this->log->logInfo("Replication of InternetGateways finished.");
			
			return $gatewayAssoc;
		}
		
		// -----------------------------------------------------
		
		/**
		 * replicateRoutingTables function.
		 * 
		 * @access public
		 * @param mixed $vpc_id_assoc
		 * @param mixed $subnet_id_assoc
		 * @return void
		 */
		function replicateRoutingTables($vpc_id_assoc, $subnet_id_assoc, $securityGroupAssoc, $gatewayAssoc)
		{
			$instanceAssoc = array();
			$routingTableAssoc = array();
			
			$this->log->logInfo("Replication of RoutingTable started.");
			
			try
			{
				
				$data = $this->getRouteTableMap($vpc_id_assoc, $subnet_id_assoc);
				
				$svpcs_routing_table = $data['source'];
				$dvpcs_routing_table = $data['dest'];
				
				$this->log->logInfo("Now we will create more RoutingTable as required. As architecture may have custom made tables which are not part of default routing tables.");
				
				// First check if a VPC has more then one routing tale.
				
				foreach($svpcs_routing_table as $svpc_id => $svpc_details)
				{
					foreach($dvpcs_routing_table as $dvpc_id => $dvpc_details)
					{
						if($dvpc_details['SourceVpc'] == $svpc_id)
						{
							if(count($svpc_details['RouteTable']) > 1)
							{
								try
								{
									$routeTableResult = $this->destination_ec2_client->createRouteTable(array(
								    	'VpcId' => $dvpc_id,
									));
									$routingId = $routeTableResult->getPath('RouteTable/RouteTableId');
									$this->log->logInfo("		Created routing table for ".$dvpc_id." New RouteTableID is ". $routingId);
								}
								catch(Exception $ex)
								{
									$this->log->logError("Exception::: ".$ex->getMessage());
									$this->log->logNotice("Please Rollback!");
									exit();
								}
							}
						}
					}
				}
				
				// Refresh after new route tables
				
				$this->log->logInfo("After creating more routing table. Lets create RoutTable map again for future use.");
				
				$data = $this->getRouteTableMap($vpc_id_assoc, $subnet_id_assoc);
				
				$svpcs_routing_table = $data['source'];
				$dvpcs_routing_table = $data['dest'];
				
				foreach($dvpcs_routing_table as $vpc_id => $vpc)
				{
					$source = array_keys($svpcs_routing_table[$vpc['SourceVpc']]['RouteTable']);
					$assoc  = array_combine($source, $vpc['RouteTable']);
					$routingTableAssoc = array_merge($assoc, $routingTableAssoc);
				}
				
				$this->log->logInfo("RouteTable map association based on CIDR and VPCs: ", $routingTableAssoc);
				$this->log->logInfo("Lets create the subnet association first.");
				
				for($i=0; $i<count($data['assoc_source']); $i++)
				{
					foreach($data['assoc_source'][$i]['Associations'] as $association)
					{
						if(isset($association['SubnetId']))
						{
							$arr = array(
								'SubnetId' => $subnet_id_assoc[$association['SubnetId']],
								'RouteTableId' => $routingTableAssoc[$association['RouteTableId']]
							);
							$this->destination_ec2_client->associateRouteTable($arr);
						}
					}
				}
				
				$this->log->logInfo("Now creating Routes inside the RouteTable.");

				// Lets create routes
				// First iterate thru source vpcs where we have details about the routes
				
				foreach($svpcs_routing_table as $svpc_id => $svpc_details)
				{
					foreach($dvpcs_routing_table as $dvpc_id => $dvpc_details)
					{
						if($dvpc_details['SourceVpc'] == $svpc_id)
						{
							foreach($svpc_details['RouteTable'] as $sroute_tbl_id => $sroutes)
							{
								for($g=0; $g<count($sroutes); $g++)
								{
									$this->log->logInfo("Checking if the Route inside table id is created by CreaRoute API call.");
									if($sroutes[$g]['origin'] == 'CreateRoute')
									{
										$this->log->logInfo("	Found Route which was created by using CreateRoute");
										$this->log->logInfo("	First create all the IGW routes.");
										if(isset($sroutes[$g]["GatewayId"]))
										{
											$this->log->logInfo("Found a Route for with IGW.");
											$this->destination_ec2_client->createRoute(array(
												'RouteTableId' => $routingTableAssoc[$sroute_tbl_id],
												'DestinationCidrBlock' => $sroutes[$g]["DestinationCidrBlock"],
												'GatewayId' => $gatewayAssoc[$sroutes[$g]["GatewayId"]]
											));
										}
									}	
								}
								
								for($i=0; $i<count($sroutes); $i++)
								{
									$this->log->logInfo("Checking if the Route inside Routing table is created by CreateRoute API call.");
									if($sroutes[$i]['origin'] == 'CreateRoute')
									{
										$this->log->logInfo("Found a Route which was created manually by using CreateRoute method.");
										
										// Lets create Routes. Very Important part of the script.	
										
										//1. Check if the route is for Instance or IGW
										$this->log->logInfo("		Now check if the Route is created by using Internet Gateway or by using an EC2 instance");
										if(isset($sroutes[$i]["InstanceId"]))
										{
											$this->log->logInfo("			Route was created by using Instance, possibly a NAT server.");
											try
											{
												$this->log->logInfo("			Lets create the replica of an Instance.");
												$this->log->logInfo("			Describing Instances from Source by filtering the Instance id used in Source Route. Instance ID: ". $sroutes[$i]["InstanceId"]);
												// Lets get the configuration of the source Instance.
												$result = $this->source_ec2_client->describeInstances(array(
													'InstanceIds' => array($sroutes[$i]["InstanceId"]),
												));
												
												$arr = $result->get('Reservations');
												$ec2_instance = $arr[0]['Instances'][0];
												
												$this->report($ec2_instance);
												
												$this->log->logInfo("			Found Instance successfully!");
												
												$this->log->logInfo("			Now checking describing source and destination AMIs");
												$this->log->logInfo("			Also fetching AMI details of the instance.");
												
												// List AMIs
												$amisResult = $this->source_ec2_client->describeImages(array(
													'Filters' => array(
														array(
															'Name' => 'image-id',
															'Values' => array($ec2_instance['ImageId'])
														)
													)
												));
												
												$amiImages = $amisResult->get('Images');
												
												$this->report($amiImages);
												
												$this->log->logInfo("			Looping thru Source AMIs list and fetching Name of an AMI by using AMI-ID.");
												for($t=0; $t<count($amiImages); $t++)
												{
													if($amiImages[$t]['ImageId'] == $ec2_instance['ImageId'])
													{
														$this->log->logInfo("			AMI in Source has been found ListedAmiId -".$amiImages[$t]['ImageId']." == InstanceAmiId - ".$ec2_instance['ImageId']);
														$this->log->logInfo("			Extracting the Name of the AMI.");
														
														// Extract the name
														$instanceName = ''; 
														for($z=0; $z<count($ec2_instance['Tags']); $z++){
															if($ec2_instance['Tags'][$z]['Key'] == 'Name'){
																$instanceName = $ec2_instance['Tags'][$z]['Value'];
															}
														}
														
														$dAmisResult = $this->destination_ec2_client->describeImages(array(
															'Filters' => array(
																array(
																	'Name' => 'name',
																	'Values' => array($amiImages[$t]['Name'])
																)
															)
														));
														
														$dAmiImages = $dAmisResult->get('Images');
														
														$dAmiId = '';
														
														for($y=0; $y < count($dAmiImages); $y++) {
															if($dAmiImages[$y]['Name'] == $amiImages[$t]['Name']) {
																
																$this->log->logInfo("Found matching AMI in destination region.");
																$this->log->logInfo("Will Initialize EC2Instance creation for ".$instanceName);
																
																$dAmiId = $dAmiImages[$y]['ImageId'];
																
																// prepare security groups
																$securityGrp = array();
																for($g=0; $g<count($ec2_instance['SecurityGroups']); $g++){
																	$securityGrp[] = $securityGroupAssoc[$ec2_instance['SecurityGroups'][$g]['GroupId']];
																}
																
																// -----------------------------------------------------------------------------------
																// Lets create NAT.
																	
																	$ec2Details = array(
																		'ImageId' => $dAmiId, 'MinCount' => 1, 'MaxCount' => 1,
																	    'Monitoring' => array(
																	        'Enabled' => true,
																	    ),  
																	    'KeyName' => $ec2_instance['KeyName'],
																	    'DisableApiTermination' => true, 
																	    'InstanceType' => $ec2_instance['InstanceType'],
																	    'NetworkInterfaces' => array(
																	        array(
																	            'DeviceIndex' => 0,
																	            'SubnetId' => $subnet_id_assoc[$ec2_instance['SubnetId']],
																	            'PrivateIpAddress' => $ec2_instance['PrivateIpAddress'],
																	            'Groups' => $securityGrp,
																	            'AssociatePublicIpAddress' => true
																	        )
																	    )
																	    
																	);
																	
																	$result = $this->destination_ec2_client->runInstances($ec2Details);
																	
																	$this->log->logInfo("	Spinning up an $instanceName instance for Destination region. The details of the instance are,",$ec2Details);
																	
																	$instance = $result->get('Instances');
																	$instanceId = $instance[0]['InstanceId'];
																	
																	$instanceIds = $result->getPath('Instances/*/InstanceId');
																	
																	$this->log->logInfo("	Instance created at destination ",$instanceIds);
																	
																	$this->log->logAlert("**** Halting until Ec2 becomes stable ****");
																	// Wait until the instance is launched
																	$this->destination_ec2_client->waitUntilInstanceRunning(array(
																	    'InstanceIds' => $instanceIds,
																	));
																	
																	// Modify
																	$modification = array(
																		'InstanceId' => $instanceId,
																		'SourceDestCheck' => array(
																			'Value' => false
																		)
																	);
																	$this->log->logInfo("Instance is Ok. Modifying Attributes ", $modification);
																	$this->destination_ec2_client->modifyInstanceAttribute($modification);
																	
																	$this->log->logInfo("	Tagging the instance with name: ".$instanceName);
																	
																	$this->destination_ec2_client->createTags(array(
																		'Resources' => array($instanceId),
																		'Tags' => array(
																			array(
																				'Key' => 'Name',
																				'Value' => $instanceName
																			),
																			array(
																				'Key' => 'Created', 
																				'Value' =>"Script Version: ".$this->script_version_no." used on ".date("Y-m-d H:i:s")
																			)
																		)
																	));
																	
																	$instanceAssoc[$ec2_instance['InstanceId']] = $instanceId;
																
																// -------------------------------------------------------------------------------------
																
																$this->log->logInfo(" Now script will create Route by using the $instanceName.");
																
																$this->destination_ec2_client->createRoute(array(
																	'RouteTableId' => $routingTableAssoc[$sroute_tbl_id],
																	'DestinationCidrBlock' => $sroutes[$i]["DestinationCidrBlock"],
																	'InstanceId' => $instanceId
																));
																
																break;
															}
														}
														break;
													}
												}
											}
											catch (Exception $ex)
											{
												$this->log->logError("Exception::: ".$ex->getMessage());
												$this->log->logNotice("Please Rollback!");
												exit();
											}
										}
									}
								}
							}
						}
					}
				}
				
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
			
			$this->log->logInfo("Replication of RoutingTable finished.");
			
			return array(
				'instanceAssoc' => $instanceAssoc,
				'routingTableAssoc' => $routingTableAssoc
			);
		}
		
		// -----------------------------------------------------
		
		function replicateBastion($subnet_id_assoc, $securityGroupAssoc)
		{
			$this->log->logInfo("Replication of Bastion Started.");
			
			try
			{
				$result = $this->source_ec2_client->describeInstances(array());
				$instances = $result->getPath("Reservations/*/Instances");
				
				foreach($instances as $instance)
				{
					foreach($instance['Tags'] as $tag)
					{
						if($tag['Key'] == 'Name')
						{
							if (strpos($tag['Value'],'Bastion') !== false) {
								
								// Find AMI in source
								$amisResult = $this->source_ec2_client->describeImages(array( 'Filters' => array(
										array( 'Name' => 'image-id','Values' => array($instance['ImageId'])))
								));
								$images = $amisResult->get('Images');
								$imageName = '';
								foreach($images as $image) {
									$imageName = $image['Name'];
								}
								// Match AMI in destination
								$amisResult = $this->destination_ec2_client->describeImages(array( 'Filters' => array(
										array( 'Name' => 'name','Values' => array($imageName)))
								));
								$images = $amisResult->get('Images');
								$imageId = '';
								foreach($images as $image) {
									$imageId = $image['ImageId'];
								}
								
								// prepare security groups
								$securityGrp = array();
								for($g=0; $g<count($instance['SecurityGroups']); $g++){
									$securityGrp[] = $securityGroupAssoc[$instance['SecurityGroups'][$g]['GroupId']];
								}
								
								// Extract the name
								$instanceName = ''; 
								for($z=0; $z<count($instance['Tags']); $z++){
									if($instance['Tags'][$z]['Key'] == 'Name'){
										$instanceName = $instance['Tags'][$z]['Value'];
									}
								}
																				
								// -----------------------------------------------------------------------------------
								// Lets create Bastion
								
								$ec2Details = array(
									'ImageId' => $imageId, 'MinCount' => 1, 'MaxCount' => 1,
								    'Monitoring' => array(
								        'Enabled' => true,
								    ),  
								    'KeyName' => $instance['KeyName'],
								    'DisableApiTermination' => true, 
								    'InstanceType' => $instance['InstanceType'],
								    'NetworkInterfaces' => array(
								        array(
								            'DeviceIndex' => 0,
								            'SubnetId' => $subnet_id_assoc[$instance['SubnetId']],
								            'PrivateIpAddress' => $instance['PrivateIpAddress'],
								            'Groups' => $securityGrp,
								            'AssociatePublicIpAddress' => true
								        )
								    )
								    
								);
								
								$result = $this->destination_ec2_client->runInstances($ec2Details);
																	
								$this->log->logInfo("	Spinning up an $instanceName instance for Destination region. The details of the instance are,",$ec2Details);
								$instance = $result->get('Instances');
								$instanceId = $instance[0]['InstanceId'];
								$instanceIds = $result->getPath('Instances/*/InstanceId');
								$this->log->logInfo("	Instance created at destination ",$instanceIds);
								
								// Wait until the instance is launched
								$this->destination_ec2_client->waitUntilInstanceRunning(array(
								    'InstanceIds' => $instanceIds,
								));
								
								$this->log->logInfo("	Tagging the instance with Name: ".$instanceName);
								
								$this->destination_ec2_client->createTags(array(
									'Resources' => array($instanceId),
									'Tags' => array(
										array(
											'Key' => 'Name',
											'Value' => $instanceName
										),
										array(
											'Key' => 'Created', 
											'Value' =>"Script Version: ".$this->script_version_no." used on ".date("Y-m-d H:i:s")
										)
									)
								));
							}
						}
					}
				}
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
			
			$this->log->logInfo("Replication of Bastion Finished.");
			
		}
		
		// -----------------------------------------------------
		
		/**
		 * replicateAMISandRunInstancesInDestinationRegion function.
		 * 
		 * DEPRECATED - NOT BEING USED. KEPT FOR FUTURE SCRIPT VERSIONS.
		 * @access public
		 * @return void
		 */
		function replicateAMISandRunInstancesInDestinationRegion()
		{
			$amiAssoc = array();
			
			try
			{
				$result = $this->source_ec2_client->describeImages(array(
					'Owners' => array('self')
				));
				
				$amis = $result->get('Images');
				
				$this->log->logInfo("Number of AMI found at source region: ".count($amis));
				
				for($i=0; $i<count($amis); $i++)
				{
					$this->log->logInfo("Making a copy request for AMI id: ".$amis[$i]['ImageId']);
					
					$newAmi = $this->destination_ec2_client->copyImage(array(
						'SourceRegion' => $this->source_region,
						'SourceImageId' => $amis[$i]['ImageId'],
						'Name' => $amis[$i]['Name']
					));
					
					$newImageId = $newAmi->get('ImageId');
					
					$this->log->logInfo("Image copied onto destination region with id: ".$newImageId." replication of source ami: ".$amis[$i]['ImageId']);
					
					$amiAssoc[$amis[$i]['ImageId']] = $newImageId;
					
					$this->log->logInfo("Creating Tags for the resource!");
					
					$cleanTags = $this->validTagArray($amis[$i]['Tags']);
					if(!empty($cleanTags))
					{
						$this->destination_ec2_client->createTags(array(
							'Resources' => array($newImageId),
							'Tags' => $cleanTags
						));
					}
					else
					{
						$this->log->logInfo("NO_TAG_FOUND: Please check if the AMI has tag for it. Source Region AMI_ID: ". $amis[$i]['ImageId']);
					}
				}
				
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
			
			return $amiAssoc;
		}
		
		// ---------------------------------------------------
		
		/**
		 * createKeyPairWithName function.
		 * 
		 * @access public
		 * @param mixed $name
		 * @return void
		 */
		function createKeyPairWithName($name)
		{
			$keyName = '';
			
			try
			{
				$result = $this->destination_ec2_client->createKeyPair(array(
					'KeyName' => $name."-Replica"
				));
				
				$keyName = $result->get('KeyName');
				$keyFingurePrint = $result->get('KeyFingurePrint');
				$keyMaterial = $result->get('KeyMaterial');
				$this->log->logInfo("****** Key: ".$keyName." created with fingreprint: ".$keyFingurePrint ." *******");
				file_put_contents($_SERVER['DOCUMENT_ROOT']."/keypairs/".$keyName.".pem", $keyMaterial);
				chmod($_SERVER['DOCUMENT_ROOT']."/keypairs/".$keyName.".pem", 0600);
				
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
			
			return $keyName;
		}
		
		// -----------------------------------------------------
		
		/**
		 * replicateEIPAdresses function.
		 * 
		 * @access public
		 * @return void
		 */
		function replicateEIPAdresses($subnet_id_assoc)
		{
			try
			{
				$result       = $this->source_ec2_client->describeAddresses(array());
				$eIPAddresses = $result->get('Addresses');
				
				for($i=0; $i<count($eIPAddresses); $i++)
				{
					if(isset($eIPAddresses[$i]['InstanceId']))
					{
						$instances = $this->source_ec2_client->describeInstances(array(
							'InstanceIds' => array($eIPAddresses[$i]['InstanceId']),
						));
						
						$reservations = $instances->get('Reservations');
						$instances    = $reservations[0]['Instances'];
						 
						$image_id     = isset($instances[0]['ImageId']) ? $instances[0]['ImageId'] : '';

						if($image_id == '') 
						{	
							$this->log->logError("Exception::: Image id does not exists! ".__FUNCTION__." line no. ".__LINE__);
							$this->log->logNotice("Please Rollback!");
							exit();
						}
						
						$this->log->logInfo("Copying AMI ".$image_id." of an instance and then attaching the eIP to it!");
						
						// Get Amis
						$amisResult = $this->source_ec2_client->describeImages(array(
							'ImageIds' => array($image_id)
						));
						
						$amis = $amisResult->get('Images');

						
						$r = $this->destination_ec2_client->describeImages(array( 'ExecutableUsers' => array('self')));
						$images = $r->get('Images');
						
					}
					
					$result = $this->destination_ec2_client->allocateAddress(array(
						'Domain' => 'vpc'
					));
				}
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
		}
		
		// -----------------------------------------------------
		
		/**
		 * getRouteTableMap function.
		 * 
		 * @access public
		 * @param mixed $vpc_id_assoc
		 * @param mixed $subnet_id_assoc
		 * @param bool $onlyTableList (default: false)
		 * @return void
		 */
		function getRouteTableMap($vpc_id_assoc, $subnet_id_assoc, $onlyTableList = false)
		{
			$data = array();
			
			// -----------------------------
			// 1. Check howmany routers are
			// there in source region.
			
			$result            = $this->source_ec2_client->describeRouteTables(array());
			$sourceRouteTables = $result->get('RouteTables');
			
			$this->report($sourceRouteTables);
			
			$result            = $this->destination_ec2_client->describeRouteTables(array());
			$destRouteTables   = $result->get('RouteTables');
			
			if($onlyTableList)
			{
				$data['source'] = $sourceRouteTables;
				$data['dest']   = $destRouteTables;
				
				return $data;
			}
			
			$svpcs = array_keys($vpc_id_assoc);
			$dvpcs = array_values($vpc_id_assoc);
			$flip_vpcs_assoc = array_flip($vpc_id_assoc);
			
			// List routing tables assoc.
			$svpcs_routing_table = array();
			$dvpcs_routing_table = array();
			
			// Source Routing map
			for($i=0; $i<count($svpcs); $i++)
			{
				$svpcs_routing_table[$svpcs[$i]]['RouteTable'] = array();
				for($j=0; $j<count($sourceRouteTables); $j++)
				{
					if($svpcs[$i] == $sourceRouteTables[$j]['VpcId'])
					{
						$svpcs_routing_table[$svpcs[$i]]['RouteTable'][$sourceRouteTables[$j]['RouteTableId']] = $sourceRouteTables[$j]['Routes'];
					}
				}
			}
			
			// Destination Route Map
			for($i=0; $i<count($dvpcs); $i++)
			{
				$dvpcs_routing_table[$dvpcs[$i]]['RouteTable'] = array();
				for($j=0; $j<count($destRouteTables); $j++)
				{
					if($dvpcs[$i] == $destRouteTables[$j]['VpcId'])
					{
						$dvpcs_routing_table[$dvpcs[$i]]['SourceVpc'] = $flip_vpcs_assoc[$dvpcs[$i]];
						$dvpcs_routing_table[$dvpcs[$i]]['RouteTable'][] = $destRouteTables[$j]['RouteTableId'];
					}
				}
			}
			
			$data['source'] = $svpcs_routing_table;
			$data['dest'] = $dvpcs_routing_table;
			$data['assoc_source'] = $sourceRouteTables;
			$data['assoc_dest'] = $destRouteTables;
			
			return $data;
		}
		
	}

?>