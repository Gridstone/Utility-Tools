<?php

	require_once("app/vpc.php");
	require_once("app/elb.php");
	require_once("app/rds.php");
	require_once('libs/kLogger/src/KLogger.php');
	
	$vpc = new VPC();
	
	set_time_limit(0);
	
	// -------------------------------------------------------------------------------------
	// Logger
	$log = KLogger::instance(dirname(__FILE__)."/log", KLogger::DEBUG);
	
	// -------------------------------------------------------------------------------------
	// Resource Created
	$resourcesCreated = array();
	
	// -------------------------------------------------------------------------------------
	// Services
	
	$vpc = new VPC();
	$elb = new ELB();
	$rds = new RDS();
	
	// --------------------------------------------------------------------------------------
	// Very first check is make sure we have keys for the instances we will be creating soon.
	
	$keysCheck = $vpc->checkIfRequiredKeysAreAvailableAndCreatePublicKeysFromIt();
	
	if($keysCheck['keyCheck'])
	{
		$vpc_id_assoc       = $vpc->replicateVpcs();
		$gatewayAssoc       = $vpc->replicateInternetGateways($vpc_id_assoc);
		$subnet_id_assoc    = $vpc->replicateSubnets($vpc_id_assoc);

		$securityGroupAssoc = $vpc->replicateSecurityGroups($vpc_id_assoc);
		
		$multiAssoc         = $vpc->replicateRoutingTables($vpc_id_assoc, $subnet_id_assoc, $securityGroupAssoc, $gatewayAssoc);
		$instanceAssoc      = $multiAssoc['instanceAssoc'];
		$routingTableAssoc  = $multiAssoc['routingTableAssoc'];
		
		$bastion = $vpc->replicateBastion($subnet_id_assoc, $securityGroupAssoc);
		
		// ELB
		$elb->replicateElbs($subnet_id_assoc, $securityGroupAssoc);
		
		$amisAssoc = $vpc->replicateAMISandRunInstancesInDestinationRegion();
		
		$rds->replicateRdss($subnet_id_assoc, $securityGroupAssoc);
		
		$resourcesCreated = array(
			'vpcs'            => array_values($vpc_id_assoc),
			'gateways'        => array_values($gatewayAssoc),
			'subnets'         => array_values($subnet_id_assoc),
			'securityGroups'  => array_values($securityGroupAssoc),
			'instances'       => array_values($instanceAssoc),
			'routingTables'   => array_values($routingTableAssoc),
			'amis'            => array_values($amisAssoc)
		);
		
		file_put_contents($_SESSION['DOCUMENT_ROOT']."rollbackdata/rollback-".date("d-m-Y\TH:m:s\Z").".json", json_encode($resourcesCreated));
		
		$log->logInfo("Done Replicating!");
	}
	else
	{
		echo "Key are Missing!";
		echo "<pre>";
		echo print_r($keysCheck['missingKeys'],true);
	}
	
?> 