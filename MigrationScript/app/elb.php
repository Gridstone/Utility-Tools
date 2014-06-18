<?php

	require_once 'aws/vendor/autoload.php';
	require_once 'libs/kLogger/src/KLogger.php';
	
	use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;
	
	/**
	 * ELB class.
	 * 
	 * @author RIPE
	 *
	 * @extends AwsService
	 */
	class ELB extends AwsService
	{
		
		// AWS classes
		var $source_elb_client      = '';
		var $destination_elb_client = '';
		var $source_region = '';
		var $dest_region = '';
		
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
			
			$this->log = KLogger::instance(dirname(__FILE__)."/log", KLogger::DEBUG);
			
			$this->log->logInfo("Initialising source and destination ec2client.");
			
			$creds = json_decode(file_get_contents("credentials/cred.json"), true);
			
			try
			{
				// Source Init
				$this->source_elb_client = ElasticLoadBalancingClient::factory(array(
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
				$this->destination_elb_client = ElasticLoadBalancingClient::factory(array(
				    'key'    => $creds["destination"]["access_key"],
				    'secret' => $creds["destination"]["secret_key"],
				    'region' => $creds["destination"]["region"]
				));
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception Create Client::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
			
			$this->log->logInfo("Source and Destination Client ");
			
			$this->source_region = $creds["source"]["region"];
			$this->dest_region = $creds["destination"]["region"];
		}
		
		// --------------------------------------------------------------
		
		/**
		 * replicateElbs function.
		 * 
		 * @access public
		 * @param mixed $subnetAssoc
		 * @param mixed $securityGroupAssoc
		 * @return void
		 */
		function replicateElbs($subnetAssoc, $securityGroupAssoc)
		{
			try
			{
				$result = $this->source_elb_client->describeLoadBalancers(array());
				$loadBalanacers = $result->get('LoadBalancerDescriptions');
				
				$this->report($loadBalanacers);
				
				$this->log->logInfo("Number of load balancers found in source region: ".count($loadBalanacers));
				
				for($i=0; $i<count($loadBalanacers); $i++)
				{
					$sourceElb = $loadBalanacers[$i];
					
					$this->log->logInfo("Replicating load balancer with id: ".$sourceElb['LoadBalancerName']);
					
					$listners = array();
					
					// Create Listner array
					for($j=0; $j<count($sourceElb['ListenerDescriptions']); $j++)
					{
						$data = $sourceElb['ListenerDescriptions'][$j]['Listener'];
						$listner = array(
				            'Protocol' => $data['Protocol'],
				            'LoadBalancerPort' => $data['LoadBalancerPort'],
				            'InstanceProtocol' => $data['InstanceProtocol'],
				            'InstancePort' => $data['InstancePort'],
				        );
						$listners[] = $listner;
					}
					
					// Get subnets
					$subnets = array();
					for($j=0; $j<count($sourceElb['Subnets']); $j++){
						$subnets[] = $subnetAssoc[$sourceElb['Subnets'][$j]];
					}
					// Get security groups
					$securityGroups = array();
					for($j=0; $j<count($sourceElb['SecurityGroups']); $j++){
						$securityGroups[] = $securityGroupAssoc[$sourceElb['SecurityGroups'][$j]];
					}

					$result = $this->destination_elb_client->createLoadBalancer(array(
									'LoadBalancerName' => $sourceElb['LoadBalancerName'],
									'Listeners' => $listners,
									'Subnets' => $subnets,
									'SecurityGroups' => $securityGroups
							  ));
							  
					$loadBalancerDNSName = $result->get('DNSName');
					
					$this->log->logInfo("Load balancer with DNS name: ".$loadBalancerDNSName." created at destination region.");
					
				}
			}
			catch(Exception $ex)
			{
				$this->log->logError("Exception::: ".$ex->getMessage());
				$this->log->logNotice("Please Rollback!");
				exit();
			}
		}
		
	}

?>